<?php

namespace App\Tests\Controller;

use App\Entity\ContenuPanier;
use App\Entity\Panier;
use App\Entity\Produit;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Création des données de test (produits, utilisateurs, paniers) directement en base.
 * Tout est annulé à la fin de chaque test par dama/doctrine-test-bundle.
 */
trait DonneesDeTestTrait
{
    private function creerProduit(string $nom, float $prix, int $stock): Produit
    {
        $produit = (new Produit())
            ->setNom($nom)
            ->setDescription('Saison 2026')
            ->setPrix($prix)
            ->setStock($stock);
        $this->em()->persist($produit);
        $this->em()->flush();

        return $produit;
    }

    private function creerUtilisateur(string $email): Utilisateur
    {
        $utilisateur = (new Utilisateur())
            ->setEmail($email)
            ->setPassword('hash')
            ->setNom('Test')
            ->setPrenom('Compte');
        $this->em()->persist($utilisateur);
        $this->em()->flush();

        return $utilisateur;
    }

    /**
     * @param list<array{Produit, int}> $lignes     produit et quantité de chaque ligne
     * @param ?\DateTimeImmutable       $dateAchat  null : panier en cours ; sinon panier acheté à cette date
     */
    private function creerPanier(Utilisateur $utilisateur, array $lignes, ?\DateTimeImmutable $dateAchat = null): Panier
    {
        $panier = (new Panier())
            ->setEtat(null !== $dateAchat)
            ->setDateAchat($dateAchat);
        $utilisateur->addPanier($panier);
        $this->em()->persist($panier);

        foreach ($lignes as [$produit, $quantite]) {
            $contenu = (new ContenuPanier())->setProduit($produit)->setQuantite($quantite);
            $panier->addContenuPanier($contenu);
            $this->em()->persist($contenu);
        }
        $this->em()->flush();

        return $panier;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
