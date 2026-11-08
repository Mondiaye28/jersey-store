<?php

namespace App\Tests\Controller;

use App\Entity\Panier;
use App\Entity\Produit;
use App\Entity\Utilisateur;
use App\Repository\PanierRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests fonctionnels de l'achat du panier (paiement fictif) : bouton « Acheter » de la page Panier.
 */
class AchatControllerTest extends WebTestCase
{
    use DonneesDeTestTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testAchatReussi(): void
    {
        $utilisateur = $this->creerUtilisateur('client@jersey-store.test');
        $maillotA = $this->creerProduit('Maillot A test', 10.0, 10);
        // Achat de tout le stock restant : autorisé, le stock tombe à 0
        $maillotB = $this->creerProduit('Maillot B test', 25.0, 5);
        $panier = $this->creerPanier($utilisateur, [[$maillotA, 3], [$maillotB, 5]]);
        $idPanier = $panier->getId();
        $this->client->loginUser($utilisateur);

        $avant = new \DateTimeImmutable('-1 second');
        $this->acheterDepuisLePanier();
        $apres = new \DateTimeImmutable('+1 second');

        $this->assertResponseRedirects('/compte');
        $this->em()->clear();
        $commande = $this->em()->find(Panier::class, $idPanier);
        $this->assertTrue($commande->isEtat(), 'Le panier est passé à etat = true');
        $this->assertNotNull($commande->getDateAchat(), 'La date d\'achat est renseignée automatiquement');
        $this->assertGreaterThanOrEqual($avant, $commande->getDateAchat());
        $this->assertLessThanOrEqual($apres, $commande->getDateAchat());
        $this->assertSame(7, $this->em()->find(Produit::class, $maillotA->getId())->getStock());
        $this->assertSame(0, $this->em()->find(Produit::class, $maillotB->getId())->getStock());

        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-success', 'Merci pour votre achat');
        // La commande apparaît dans l'historique
        $this->assertSelectorTextContains('tr.commande', 'Commande n° '.$idPanier);
    }

    public function testApresLAchatLeProchainAjoutCreeUnNouveauPanier(): void
    {
        $utilisateur = $this->creerUtilisateur('client@jersey-store.test');
        $produit = $this->creerProduit('Maillot rachat test', 10.0, 10);
        $idAchete = $this->creerPanier($utilisateur, [[$produit, 1]])->getId();
        $this->client->loginUser($utilisateur);

        $this->acheterDepuisLePanier();
        $this->assertNull($this->panierEnCours($utilisateur), 'Plus de panier en cours après l\'achat');

        $this->client->request('GET', '/produit/'.$produit->getId());
        $this->client->submitForm('Ajouter au panier', ['quantite' => '2']);

        $nouveau = $this->panierEnCours($utilisateur);
        $this->assertNotNull($nouveau);
        $this->assertNotSame($idAchete, $nouveau->getId(), 'Un nouveau panier est créé');
        $this->assertSame(2, $nouveau->getContenuPaniers()->first()->getQuantite());
        // La commande achetée garde sa quantité d'origine
        $this->assertSame(1, $this->em()->find(Panier::class, $idAchete)->getContenuPaniers()->first()->getQuantite());
    }

    public function testAchatRefuseSiLeStockEstDevenuInsuffisant(): void
    {
        $utilisateur = $this->creerUtilisateur('client@jersey-store.test');
        $disponible = $this->creerProduit('Maillot disponible test', 10.0, 10);
        $rare = $this->creerProduit('Maillot rare test', 50.0, 5);
        $panier = $this->creerPanier($utilisateur, [[$disponible, 2], [$rare, 3]]);
        $idPanier = $panier->getId();
        // Entre-temps, d'autres clients ont acheté : il ne reste que 2 maillots rares
        $rare->setStock(2);
        $this->em()->flush();
        $this->client->loginUser($utilisateur);

        $this->acheterDepuisLePanier();

        $this->assertResponseRedirects('/panier');
        $this->em()->clear();
        $panier = $this->em()->find(Panier::class, $idPanier);
        $this->assertFalse($panier->isEtat(), 'Le panier reste en cours');
        $this->assertNull($panier->getDateAchat());
        // Aucun stock n'a bougé, y compris celui du produit qui était suffisant
        $this->assertSame(10, $this->em()->find(Produit::class, $disponible->getId())->getStock());
        $this->assertSame(2, $this->em()->find(Produit::class, $rare->getId())->getStock());

        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-error', 'Achat impossible');
    }

    public function testAchatAvecJetonInvalideEstRefuse(): void
    {
        $utilisateur = $this->creerUtilisateur('client@jersey-store.test');
        $produit = $this->creerProduit('Maillot protégé test', 10.0, 10);
        $idPanier = $this->creerPanier($utilisateur, [[$produit, 1]])->getId();
        $this->client->loginUser($utilisateur);

        $this->client->request('POST', '/panier/acheter', ['_token' => 'jeton-invalide']);

        $this->assertResponseRedirects('/panier');
        $this->em()->clear();
        $this->assertFalse($this->em()->find(Panier::class, $idPanier)->isEtat());
        $this->assertSame(10, $this->em()->find(Produit::class, $produit->getId())->getStock());
    }

    public function testAchatRefusePourUnVisiteur(): void
    {
        $this->client->request('POST', '/panier/acheter', ['_token' => 'x']);

        $this->assertResponseRedirects('/connexion');
    }

    public function testAchatSansPanierEnCours(): void
    {
        $utilisateur = $this->creerUtilisateur('client@jersey-store.test');
        $this->client->loginUser($utilisateur);
        // Jeton valide récupéré sur une page qui affiche le bouton : on le génère via un panier temporaire
        $panier = $this->creerPanier($utilisateur, [[$this->creerProduit('Maillot temporaire test', 10.0, 10), 1]]);
        $crawler = $this->client->request('GET', '/panier');
        $jeton = $crawler->filter('form button.acheter')->form()->get('_token')->getValue();
        $panier->removeContenuPanier($panier->getContenuPaniers()->first());
        $this->em()->flush();

        $this->client->request('POST', '/panier/acheter', ['_token' => $jeton]);

        $this->assertResponseRedirects('/panier');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-error', 'Votre panier est vide');
    }

    /**
     * Ouvre la page Panier et envoie le formulaire « Acheter ».
     */
    private function acheterDepuisLePanier(): void
    {
        $this->client->request('GET', '/panier');
        $this->client->submitForm('Acheter');
    }

    private function panierEnCours(Utilisateur $utilisateur): ?Panier
    {
        // On relit la base pour ne pas dépendre des objets déjà chargés
        $this->em()->clear();

        return static::getContainer()->get(PanierRepository::class)->findEnCours(
            $this->em()->getReference(Utilisateur::class, $utilisateur->getId())
        );
    }
}
