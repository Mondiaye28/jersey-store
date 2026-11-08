<?php

namespace App\Tests\Controller;

use App\Entity\Utilisateur;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tests fonctionnels de la page « Mon compte » : profil, historique et détail des commandes.
 */
class CompteControllerTest extends WebTestCase
{
    use DonneesDeTestTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testPageCompteRefuseePourUnVisiteur(): void
    {
        $this->client->request('GET', '/compte');

        $this->assertResponseRedirects('/connexion');
    }

    public function testHistoriqueNAfficheQueLesCommandesAchetees(): void
    {
        $utilisateur = $this->creerUtilisateur('client@jersey-store.test');
        $autre = $this->creerUtilisateur('autre@jersey-store.test');
        $maillotA = $this->creerProduit('Maillot A test', 10.5, 10);
        $maillotB = $this->creerProduit('Maillot B test', 5.0, 10);
        $ancienne = $this->creerPanier($utilisateur, [[$maillotA, 2]], new \DateTimeImmutable('2026-01-15 10:30'));
        $recente = $this->creerPanier($utilisateur, [[$maillotA, 3], [$maillotB, 2]], new \DateTimeImmutable('2026-03-20 14:00'));
        $enCours = $this->creerPanier($utilisateur, [[$maillotB, 1]]);
        $commandeDeLAutre = $this->creerPanier($autre, [[$maillotB, 4]], new \DateTimeImmutable('2026-02-01 09:00'));
        $this->client->loginUser($utilisateur);

        $crawler = $this->client->request('GET', '/compte');

        $this->assertResponseIsSuccessful();
        $lignes = $crawler->filter('tr.commande');
        $this->assertCount(2, $lignes, 'Ni le panier en cours, ni la commande d\'un autre utilisateur');
        $this->assertSelectorTextNotContains('table.commandes', 'n° '.$enCours->getId());
        $this->assertSelectorTextNotContains('table.commandes', 'n° '.$commandeDeLAutre->getId());

        // La plus récente d'abord, avec son identifiant, sa date et son total (10,50 x 3 + 5,00 x 2)
        $premiere = $lignes->eq(0);
        $this->assertStringContainsString('Commande n° '.$recente->getId(), $premiere->filter('.numero')->text());
        $this->assertStringContainsString('20/03/2026 15:00', $premiere->filter('.date')->text(), '14:00 UTC affiché à l\'heure de Paris');
        $this->assertStringContainsString('41,50', $premiere->filter('.montant')->text());
        $this->assertSame('/compte/commande/'.$recente->getId(), $premiere->filter('.numero a')->attr('href'));

        $seconde = $lignes->eq(1);
        $this->assertStringContainsString('Commande n° '.$ancienne->getId(), $seconde->filter('.numero')->text());
        $this->assertStringContainsString('15/01/2026', $seconde->filter('.date')->text());
        $this->assertStringContainsString('21,00', $seconde->filter('.montant')->text());
    }

    public function testDetailDUneCommande(): void
    {
        $utilisateur = $this->creerUtilisateur('client@jersey-store.test');
        $commande = $this->creerPanier($utilisateur, [
            [$this->creerProduit('Maillot A test', 10.5, 10), 3],
            [$this->creerProduit('Maillot B test', 5.0, 10), 2],
        ], new \DateTimeImmutable('2026-03-20 14:00'));
        $this->client->loginUser($utilisateur);

        $crawler = $this->client->request('GET', '/compte/commande/'.$commande->getId());

        $this->assertResponseIsSuccessful();
        $this->assertCount(2, $crawler->filter('tr.ligne-panier'));
        $ligneA = $crawler->filter('tr.ligne-panier')->reduce(fn ($tr) => str_contains($tr->text(), 'Maillot A test'));
        $this->assertSame('3', $ligneA->filter('.quantite')->text());
        $this->assertStringContainsString('10,50', $ligneA->text(), 'Prix unitaire');
        $this->assertStringContainsString('31,50', $ligneA->filter('.montant')->text());
        $this->assertStringContainsString('41,50', $crawler->filter('.total')->text());
        // 14:00 UTC le 20 mars = 15:00 à Paris (heure d'hiver)
        $this->assertSelectorTextContains('.date-commande', '20 mars 2026');
        $this->assertSelectorTextContains('.date-commande', '15:00');
        // Lecture seule : ni bouton d'achat, ni bouton de suppression, ni formulaire
        $this->assertSelectorNotExists('button.acheter');
        $this->assertSelectorNotExists('main form');
        $this->assertSelectorTextNotContains('main', 'Retirer');
    }

    public function testDetailDeLaCommandeDUnAutreUtilisateurInterdit(): void
    {
        $alice = $this->creerUtilisateur('alice@jersey-store.test');
        $bob = $this->creerUtilisateur('bob@jersey-store.test');
        $commandeAlice = $this->creerPanier($alice, [[$this->creerProduit('Maillot d\'Alice test', 10.0, 10), 1]], new \DateTimeImmutable());
        $this->client->loginUser($bob);

        $this->client->request('GET', '/compte/commande/'.$commandeAlice->getId());

        $this->assertResponseStatusCodeSame(403);
    }

    public function testUnPanierEnCoursNEstPasUneCommande(): void
    {
        $utilisateur = $this->creerUtilisateur('client@jersey-store.test');
        $enCours = $this->creerPanier($utilisateur, [[$this->creerProduit('Maillot en cours test', 10.0, 10), 1]]);
        $this->client->loginUser($utilisateur);

        $this->client->request('GET', '/compte/commande/'.$enCours->getId());

        $this->assertResponseStatusCodeSame(403);
    }

    public function testLeFormulaireNeContientPasDeDate(): void
    {
        $this->client->loginUser($this->creerUtilisateur('client@jersey-store.test'));

        $crawler = $this->client->request('GET', '/compte');

        $champs = $crawler->filter('form[name="profil_form"] input, form[name="profil_form"] select')
            ->each(fn ($champ) => (string) $champ->attr('name'));
        $this->assertSame(
            ['profil_form[nom]', 'profil_form[prenom]', 'profil_form[email]', 'profil_form[plainPassword]', 'profil_form[_token]'],
            $champs,
        );
        $this->assertSelectorNotExists('input[type="date"], input[type="datetime-local"]');
    }

    public function testModificationDuProfilSansChangerLeMotDePasse(): void
    {
        $utilisateur = $this->creerUtilisateur('client@jersey-store.test');
        $this->client->loginUser($utilisateur);

        $this->client->request('GET', '/compte');
        $this->client->submitForm('Enregistrer', [
            'profil_form[nom]' => 'Diallo',
            'profil_form[prenom]' => 'Awa',
            'profil_form[email]' => 'awa@jersey-store.test',
            'profil_form[plainPassword]' => '',
        ]);

        $this->assertResponseRedirects('/compte');
        $this->em()->clear();
        $modifie = $this->em()->find(Utilisateur::class, $utilisateur->getId());
        $this->assertSame('Diallo', $modifie->getNom());
        $this->assertSame('Awa', $modifie->getPrenom());
        $this->assertSame('awa@jersey-store.test', $modifie->getEmail());
        $this->assertSame('hash', $modifie->getPassword(), 'Mot de passe vide : inchangé');

        // Toujours connecté après le changement d'email
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.flash-success', 'Votre profil a été mis à jour.');
        $this->assertSelectorTextContains('nav', 'Awa Diallo');
    }

    public function testChangementDuMotDePasseLeHache(): void
    {
        $utilisateur = $this->creerUtilisateur('client@jersey-store.test');
        $this->client->loginUser($utilisateur);

        $this->client->request('GET', '/compte');
        $this->client->submitForm('Enregistrer', ['profil_form[plainPassword]' => 'nouveau-secret-123']);

        $this->assertResponseRedirects('/compte');
        $this->em()->clear();
        $modifie = $this->em()->find(Utilisateur::class, $utilisateur->getId());
        $this->assertNotSame('nouveau-secret-123', $modifie->getPassword(), 'Jamais en clair');
        $this->assertTrue(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($modifie, 'nouveau-secret-123'));
    }

    public function testMotDePasseTropCourtRefuse(): void
    {
        $utilisateur = $this->creerUtilisateur('client@jersey-store.test');
        $this->client->loginUser($utilisateur);

        $this->client->request('GET', '/compte');
        $this->client->submitForm('Enregistrer', ['profil_form[plainPassword]' => 'court']);

        $this->assertResponseStatusCodeSame(422);
        $this->em()->clear();
        $this->assertSame('hash', $this->em()->find(Utilisateur::class, $utilisateur->getId())->getPassword());
    }

    public function testEmailDejaUtiliseRefuse(): void
    {
        $this->creerUtilisateur('pris@jersey-store.test');
        $utilisateur = $this->creerUtilisateur('client@jersey-store.test');
        $this->client->loginUser($utilisateur);

        $this->client->request('GET', '/compte');
        $this->client->submitForm('Enregistrer', [
            'profil_form[nom]' => 'Nouveau nom',
            'profil_form[email]' => 'pris@jersey-store.test',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('.flash-error', 'Votre profil n\'a pas été enregistré.');
        $this->em()->clear();
        $intact = $this->em()->find(Utilisateur::class, $utilisateur->getId());
        $this->assertSame('client@jersey-store.test', $intact->getEmail());
        $this->assertSame('Test', $intact->getNom(), 'Rien n\'est enregistré si le formulaire est invalide');
        // La barre de navigation affiche toujours les données enregistrées, pas la saisie refusée
        $this->assertSelectorTextNotContains('nav', 'Nouveau nom');

        // L'utilisateur est toujours connecté à la requête suivante
        $this->client->request('GET', '/compte');
        $this->assertResponseIsSuccessful();
    }

    public function testGarderSonPropreEmailEstAccepte(): void
    {
        $utilisateur = $this->creerUtilisateur('client@jersey-store.test');
        $this->client->loginUser($utilisateur);

        $this->client->request('GET', '/compte');
        $this->client->submitForm('Enregistrer', ['profil_form[nom]' => 'Ndiaye']);

        $this->assertResponseRedirects('/compte');
    }

    public function testChampsObligatoiresDuProfil(): void
    {
        $utilisateur = $this->creerUtilisateur('client@jersey-store.test');
        $this->client->loginUser($utilisateur);

        $this->client->request('GET', '/compte');
        $this->client->submitForm('Enregistrer', [
            'profil_form[nom]' => '',
            'profil_form[email]' => 'pas-un-email',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->em()->clear();
        $this->assertSame('client@jersey-store.test', $this->em()->find(Utilisateur::class, $utilisateur->getId())->getEmail());
    }
}
