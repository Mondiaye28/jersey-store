<?php

namespace App\Service;

use App\Entity\ContenuPanier;
use App\Entity\Panier;
use App\Entity\Produit;
use App\Entity\Utilisateur;
use App\Exception\PanierVideException;
use App\Exception\StockInsuffisantException;
use App\Repository\PanierRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Logique métier du panier.
 * Règle : un utilisateur a au plus un panier en cours (etat = false),
 * créé au moment de son premier ajout.
 */
class PanierService
{
    public function __construct(
        private PanierRepository $panierRepository,
        private EntityManagerInterface $entityManager,
        private LockFactory $lockFactory,
    ) {
    }

    /**
     * Panier en cours de l'utilisateur, sans en créer : null s'il n'a encore rien ajouté.
     */
    public function getPanierEnCours(Utilisateur $utilisateur): ?Panier
    {
        return $this->panierRepository->findEnCours($utilisateur);
    }

    /**
     * Ajoute un produit au panier en cours (créé si besoin).
     * Si le produit y est déjà, sa quantité est augmentée au lieu de créer une deuxième ligne.
     *
     * @throws StockInsuffisantException si la quantité totale dépasse le stock du produit
     */
    public function ajouter(Utilisateur $utilisateur, Produit $produit, int $quantite = 1): ContenuPanier
    {
        if ($quantite < 1) {
            throw new \InvalidArgumentException('La quantité ajoutée doit être au moins 1.');
        }

        // Verrou par utilisateur : deux ajouts simultanés (double clic, deux onglets)
        // ne doivent ni créer deux paniers en cours ni perdre une quantité
        $verrou = $this->lockFactory->createLock('panier_utilisateur_'.$utilisateur->getId());
        $verrou->acquire(true);

        try {
            $panier = $this->getPanierEnCours($utilisateur);
            $ligne = null !== $panier ? $this->trouverLigne($panier, $produit) : null;

            // Le stock est vérifié avant toute création, pour ne pas laisser de panier vide
            $nouvelleQuantite = ($ligne?->getQuantite() ?? 0) + $quantite;
            if ($nouvelleQuantite > $produit->getStock()) {
                throw new StockInsuffisantException(\sprintf('Stock insuffisant pour le produit %d.', $produit->getId()));
            }

            if (null === $panier) {
                $panier = new Panier();
                $utilisateur->addPanier($panier);
                $this->entityManager->persist($panier);
            }

            if (null === $ligne) {
                $ligne = (new ContenuPanier())
                    ->setProduit($produit)
                    ->setQuantite($nouvelleQuantite);
                $panier->addContenuPanier($ligne);
                $this->entityManager->persist($ligne);
            } else {
                $ligne->setQuantite($nouvelleQuantite);
            }

            $this->entityManager->flush();

            return $ligne;
        } finally {
            $verrou->release();
        }
    }

    /**
     * Achète (paiement fictif) le panier en cours de l'utilisateur, en tout ou rien :
     * le panier passe à etat = true avec la date d'achat actuelle, et le stock de
     * chaque produit est décrémenté de la quantité commandée.
     * Le panier acheté n'est plus « en cours » : le prochain ajout en créera un nouveau.
     *
     * @throws PanierVideException       s'il n'y a rien à acheter
     * @throws StockInsuffisantException si un produit n'a plus assez de stock (rien n'est modifié)
     */
    public function acheter(Utilisateur $utilisateur): Panier
    {
        // Même verrou que l'ajout : le panier ne peut pas changer pendant son achat
        $verrou = $this->lockFactory->createLock('panier_utilisateur_'.$utilisateur->getId());
        $verrou->acquire(true);

        try {
            $panier = $this->getPanierEnCours($utilisateur);
            if (null === $panier || $panier->getContenuPaniers()->isEmpty()) {
                throw new PanierVideException('Aucun produit à acheter.');
            }

            $connexion = $this->entityManager->getConnection();
            // Transaction gérée à la main plutôt qu'avec wrapInTransaction(), qui fermerait
            // l'EntityManager en cas d'échec et le rendrait inutilisable pour la suite de la requête
            $connexion->beginTransaction();

            try {
                // 1. Vérification de tous les stocks AVANT toute modification.
                // Chaque produit est relu en base avec un verrou d'écriture (SELECT ... FOR UPDATE) :
                // un achat concurrent du même produit attend la fin de cette transaction,
                // donc le stock lu ne peut pas changer entre la vérification et la décrémentation
                foreach ($panier->getContenuPaniers() as $ligne) {
                    $produit = $ligne->getProduit();
                    $this->entityManager->refresh($produit, LockMode::PESSIMISTIC_WRITE);

                    if ($ligne->getQuantite() > $produit->getStock()) {
                        throw new StockInsuffisantException(\sprintf('Stock insuffisant pour le produit %d.', $produit->getId()));
                    }
                }

                // 2. Tous les stocks suffisent : on décrémente et on valide le panier
                foreach ($panier->getContenuPaniers() as $ligne) {
                    $produit = $ligne->getProduit();
                    $produit->setStock($produit->getStock() - $ligne->getQuantite());
                }

                // La date d'achat est fixée ici, jamais saisie par l'utilisateur
                $panier
                    ->setEtat(true)
                    ->setDateAchat(new \DateTimeImmutable());

                $this->entityManager->flush();
                $connexion->commit();
            } catch (\Throwable $e) {
                // Annulation complète : aucune écriture n'est conservée en base
                $connexion->rollBack();

                throw $e;
            }

            return $panier;
        } finally {
            $verrou->release();
        }
    }

    /**
     * Commandes (paniers achetés) de l'utilisateur, de la plus récente à la plus ancienne.
     *
     * @return list<Panier>
     */
    public function getCommandes(Utilisateur $utilisateur): array
    {
        return $this->panierRepository->findCommandes($utilisateur);
    }

    /**
     * Paniers en cours non vides de tous les utilisateurs (vue super-admin).
     *
     * @return list<Panier>
     */
    public function getPaniersEnCoursNonVides(): array
    {
        return $this->panierRepository->findEnCoursNonVides();
    }

    /**
     * Montant de chaque ligne de plusieurs paniers, indexé par l'identifiant de la ligne.
     *
     * @param iterable<Panier> $paniers
     *
     * @return array<int, float>
     */
    public function calculerMontantsLignesDe(iterable $paniers): array
    {
        $montants = [];
        foreach ($paniers as $panier) {
            $montants += $this->calculerMontantsLignes($panier);
        }

        return $montants;
    }

    /**
     * Montant total de chaque panier, indexé par l'identifiant du panier.
     *
     * @param iterable<Panier> $paniers
     *
     * @return array<int, float>
     */
    public function calculerTotaux(iterable $paniers): array
    {
        $totaux = [];
        foreach ($paniers as $panier) {
            $totaux[$panier->getId()] = $this->calculerTotal($panier);
        }

        return $totaux;
    }

    /**
     * Retire une ligne de son panier (orphanRemoval la supprime de la base).
     */
    public function retirerLigne(ContenuPanier $ligne): void
    {
        $ligne->getPanier()->removeContenuPanier($ligne);
        $this->entityManager->flush();
    }

    /**
     * Montant d'une ligne : prix unitaire x quantité.
     */
    public function calculerMontantLigne(ContenuPanier $ligne): float
    {
        return round($ligne->getProduit()->getPrix() * $ligne->getQuantite(), 2);
    }

    /**
     * Montant de chaque ligne du panier, indexé par l'identifiant de la ligne.
     *
     * @return array<int, float>
     */
    public function calculerMontantsLignes(Panier $panier): array
    {
        $montants = [];
        foreach ($panier->getContenuPaniers() as $ligne) {
            $montants[$ligne->getId()] = $this->calculerMontantLigne($ligne);
        }

        return $montants;
    }

    /**
     * Montant total du panier : somme des montants de ses lignes.
     */
    public function calculerTotal(Panier $panier): float
    {
        $total = 0.0;
        foreach ($panier->getContenuPaniers() as $ligne) {
            $total += $this->calculerMontantLigne($ligne);
        }

        return round($total, 2);
    }

    private function trouverLigne(Panier $panier, Produit $produit): ?ContenuPanier
    {
        foreach ($panier->getContenuPaniers() as $ligne) {
            if ($ligne->getProduit()->getId() === $produit->getId()) {
                return $ligne;
            }
        }

        return null;
    }
}
