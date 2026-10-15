<?php

namespace App\Service;

use App\Entity\ContenuPanier;
use App\Entity\Panier;
use App\Entity\Produit;
use App\Entity\Utilisateur;
use App\Exception\StockInsuffisantException;
use App\Repository\PanierRepository;
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
