<?php

namespace App\Tests\Controller;

use App\Entity\ContenuPanier;
use App\Entity\Panier;
use App\Entity\Produit;
use App\Entity\Utilisateur;
use App\Repository\PanierRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests fonctionnels du panier : ajout, affichage, suppression d'une ligne,
 * et isolement entre utilisateurs.
 * Chaque test est annulé à la fin (dama/doctrine-test-bundle) : rien ne reste en base.
 */
class PanierControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testAjoutAuPanierPourUnUtilisateurConnecte(): void
    {
        $produit = $this->creerProduit('Maillot ajout test', 89.99, 10);
        $utilisateur = $this->creerUtilisateur('client@jersey-store.test');
        $this->client->loginUser($utilisateur);

        $this->ajouterDepuisLaFiche($produit);

        $this->assertResponseRedirects('/produit/'.$produit->getId());
        $panier = $this->panierEnCours($utilisateur);
        $this->assertNotNull($panier, 'Le panier en cours est créé au premier ajout');
        $this->assertFalse($panier->isEtat());
        $this->assertCount(1, $panier->getContenuPaniers());
        $this->assertSame(1, $panier->getContenuPaniers()->first()->getQuantite());

        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-success', 'Le produit a été ajouté à votre panier.');
    }

    public function testAjoutAvecUneQuantiteChoisie(): void
    {
        $produit = $this->creerProduit('Maillot quantité test', 20.0, 10);
        $utilisateur = $this->creerUtilisateur('client@jersey-store.test');
        $this->client->loginUser($utilisateur);

        $this->ajouterDepuisLaFiche($produit, 3);

        $this->assertSame(3, $this->panierEnCours($utilisateur)->getContenuPaniers()->first()->getQuantite());
    }

    public function testAjoutRefusePourUnVisiteur(): void
    {
        $produit = $this->creerProduit('Maillot visiteur test', 50.0, 10);

        $this->client->request('POST', \sprintf('/panier/ajouter/%d', $produit->getId()), ['_token' => 'x']);

        // Visiteur : renvoyé vers la connexion, aucun panier créé
        $this->assertResponseRedirects('/connexion');
        $this->assertSame(0, $this->em()->getRepository(ContenuPanier::class)->count(['produit' => $produit]));
    }

    public function testPagePanierRefuseePourUnVisiteur(): void
    {
        $this->client->request('GET', '/panier');

        $this->assertResponseRedirects('/connexion');
    }

    public function testSecondAjoutAugmenteLaQuantiteSansNouvelleLigne(): void
    {
        $produit = $this->creerProduit('Maillot double ajout test', 30.0, 10);
        $utilisateur = $this->creerUtilisateur('client@jersey-store.test');
        $this->client->loginUser($utilisateur);

        $this->ajouterDepuisLaFiche($produit);
        $this->ajouterDepuisLaFiche($produit);

        $panier = $this->panierEnCours($utilisateur);
        $this->assertCount(1, $panier->getContenuPaniers(), 'Une seule ligne pour le même produit');
        $this->assertSame(2, $panier->getContenuPaniers()->first()->getQuantite());
        // Toujours un seul panier en cours pour cet utilisateur
        $this->assertSame(1, $this->em()->getRepository(Panier::class)->count(['utilisateur' => $utilisateur, 'etat' => false]));
    }

    public function testAjoutRefuseSiStockInsuffisant(): void
    {
        $produit = $this->creerProduit('Maillot dernier exemplaire test', 40.0, 1);
        $utilisateur = $this->creerUtilisateur('client@jersey-store.test');
        $this->client->loginUser($utilisateur);

        $this->ajouterDepuisLaFiche($produit);
        $this->ajouterDepuisLaFiche($produit);

        // Le second ajout dépasserait le stock (1) : la quantité ne bouge pas
        $this->assertSame(1, $this->panierEnCours($utilisateur)->getContenuPaniers()->first()->getQuantite());
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-error', 'Stock insuffisant');
    }

    public function testAjoutRefuseSansStockNeCreePasDePanier(): void
    {
        $produit = $this->creerProduit('Maillot épuisé test', 40.0, 0);
        $utilisateur = $this->creerUtilisateur('client@jersey-store.test');
        $this->client->loginUser($utilisateur);

        $this->ajouterDepuisLaFiche($produit);

        $this->assertNull($this->panierEnCours($utilisateur));
    }

    public function testPagePanierAfficheLignesMontantsEtTotal(): void
    {
        $utilisateur = $this->creerUtilisateur('client@jersey-store.test');
        $this->creerPanier($utilisateur, false, [
            [$this->creerProduit('Maillot A test', 10.5, 10), 3],
            [$this->creerProduit('Maillot B test', 5.0, 10), 2],
        ]);
        $this->client->loginUser($utilisateur);

        $crawler = $this->client->request('GET', '/panier');

        $this->assertResponseIsSuccessful();
        $this->assertCount(2, $crawler->filter('tr.ligne-panier'));
        $ligneA = $crawler->filter('tr.ligne-panier')->reduce(fn ($tr) => str_contains($tr->text(), 'Maillot A test'));
        $this->assertSame('3', $ligneA->filter('.quantite')->text());
        $this->assertStringContainsString('10,50', $ligneA->text(), 'Prix unitaire');
        $this->assertStringContainsString('31,50', $ligneA->filter('.montant')->text(), 'Montant = 10,50 x 3');
        $this->assertStringContainsString('41,50', $crawler->filter('.total')->text(), 'Total = 31,50 + 10,00');
        $this->assertSelectorTextContains('button.acheter', 'Acheter');
    }

    public function testPanierDUnAutreUtilisateurNonVisible(): void
    {
        $alice = $this->creerUtilisateur('alice@jersey-store.test');
        $bob = $this->creerUtilisateur('bob@jersey-store.test');
        $this->creerPanier($alice, false, [[$this->creerProduit('Maillot d\'Alice test', 10.0, 10), 1]]);
        // Un panier déjà payé de Bob ne doit pas apparaître non plus
        $this->creerPanier($bob, true, [[$this->creerProduit('Maillot déjà payé test', 10.0, 10), 1]]);
        $this->client->loginUser($bob);

        $this->client->request('GET', '/panier');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextNotContains('body', 'Maillot d\'Alice test');
        $this->assertSelectorTextNotContains('body', 'Maillot déjà payé test');
        $this->assertSelectorTextContains('body', 'Votre panier est vide.');
    }

    public function testSuppressionDUneLigneDeSonPanier(): void
    {
        $utilisateur = $this->creerUtilisateur('client@jersey-store.test');
        $ligne = $this->creerPanier($utilisateur, false, [[$this->creerProduit('Maillot à retirer test', 10.0, 10), 1]])[0];
        $idLigne = $ligne->getId();
        $this->client->loginUser($utilisateur);

        $this->client->request('GET', '/panier');
        $this->client->submitForm('Retirer');

        $this->assertResponseRedirects('/panier');
        $this->assertNull($this->em()->find(ContenuPanier::class, $idLigne));
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-success', 'Le produit a été retiré de votre panier.');
    }

    public function testSuppressionRefuseeSurLaLigneDUnAutreUtilisateur(): void
    {
        $alice = $this->creerUtilisateur('alice@jersey-store.test');
        $bob = $this->creerUtilisateur('bob@jersey-store.test');
        $ligneAlice = $this->creerPanier($alice, false, [[$this->creerProduit('Maillot d\'Alice test', 10.0, 10), 1]])[0];
        $idLigne = $ligneAlice->getId();
        $this->client->loginUser($bob);

        $this->client->request('POST', \sprintf('/panier/ligne/%d/supprimer', $idLigne), ['_token' => 'x']);

        $this->assertResponseStatusCodeSame(403);
        $this->assertNotNull($this->em()->find(ContenuPanier::class, $idLigne), 'La ligne d\'Alice est intacte');
    }

    public function testSuppressionRefuseeSurUnPanierDejaPaye(): void
    {
        $utilisateur = $this->creerUtilisateur('client@jersey-store.test');
        $ligne = $this->creerPanier($utilisateur, true, [[$this->creerProduit('Maillot payé test', 10.0, 10), 1]])[0];
        $this->client->loginUser($utilisateur);

        $this->client->request('POST', \sprintf('/panier/ligne/%d/supprimer', $ligne->getId()), ['_token' => 'x']);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testSuppressionAvecJetonInvalideEstRefusee(): void
    {
        $utilisateur = $this->creerUtilisateur('client@jersey-store.test');
        $ligne = $this->creerPanier($utilisateur, false, [[$this->creerProduit('Maillot protégé test', 10.0, 10), 1]])[0];
        $idLigne = $ligne->getId();
        $this->client->loginUser($utilisateur);

        $this->client->request('POST', \sprintf('/panier/ligne/%d/supprimer', $idLigne), ['_token' => 'jeton-invalide']);

        $this->assertResponseRedirects('/panier');
        $this->assertNotNull($this->em()->find(ContenuPanier::class, $idLigne));
    }

    /**
     * Ouvre la fiche du produit et envoie le formulaire « Ajouter au panier ».
     */
    private function ajouterDepuisLaFiche(Produit $produit, int $quantite = 1): void
    {
        $this->client->request('GET', '/produit/'.$produit->getId());
        $this->client->submitForm('Ajouter au panier', ['quantite' => (string) $quantite]);
    }

    private function panierEnCours(Utilisateur $utilisateur): ?Panier
    {
        // On relit la base pour ne pas dépendre des objets déjà chargés
        $this->em()->clear();

        return static::getContainer()->get(PanierRepository::class)->findEnCours(
            $this->em()->getReference(Utilisateur::class, $utilisateur->getId())
        );
    }

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
     * @param list<array{Produit, int}> $lignes produit et quantité de chaque ligne
     *
     * @return list<ContenuPanier>
     */
    private function creerPanier(Utilisateur $utilisateur, bool $paye, array $lignes): array
    {
        $panier = (new Panier())->setEtat($paye);
        $utilisateur->addPanier($panier);
        $this->em()->persist($panier);

        $contenus = [];
        foreach ($lignes as [$produit, $quantite]) {
            $contenu = (new ContenuPanier())->setProduit($produit)->setQuantite($quantite);
            $panier->addContenuPanier($contenu);
            $this->em()->persist($contenu);
            $contenus[] = $contenu;
        }
        $this->em()->flush();

        return $contenus;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
