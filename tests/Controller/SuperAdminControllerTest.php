<?php

namespace App\Tests\Controller;

use App\Entity\Utilisateur;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Tests fonctionnels de la section super-admin et de l'héritage ROLE_SUPER_ADMIN → ROLE_ADMIN.
 */
class SuperAdminControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use DonneesDeTestTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testVisiteurRedirigeVersLaConnexion(): void
    {
        $this->client->request('GET', '/super-admin');

        $this->assertResponseRedirects('/connexion');
    }

    public function testUtilisateurSimpleRecoit403(): void
    {
        $this->client->loginUser($this->creerUtilisateurAvecRoles('client@jersey-store.test', []));

        $this->client->request('GET', '/super-admin');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminRecoit403(): void
    {
        $this->client->loginUser($this->creerUtilisateurAvecRoles('admin@jersey-store.test', ['ROLE_ADMIN']));

        $this->client->request('GET', '/super-admin');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testSuperAdminAccedeALaSection(): void
    {
        $this->client->loginUser($this->creerUtilisateurAvecRoles('super@jersey-store.test', ['ROLE_SUPER_ADMIN']));

        $this->client->request('GET', '/super-admin');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Super-admin');
    }

    public function testSuperAdminAccedeAuxPagesAdmin(): void
    {
        // Seul ROLE_SUPER_ADMIN est stocké : l'accès vient uniquement de la hiérarchie des rôles
        $superAdmin = $this->creerUtilisateurAvecRoles('super@jersey-store.test', ['ROLE_SUPER_ADMIN']);
        $produit = $this->creerProduit('Maillot admin test', 10.0, 10);
        $this->client->loginUser($superAdmin);

        $this->client->request('GET', '/admin/produit/nouveau');
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/admin/produit/'.$produit->getId().'/modifier');
        $this->assertResponseIsSuccessful();
    }

    public function testLienSuperAdminVisibleSeulementPourLeSuperAdmin(): void
    {
        $this->client->loginUser($this->creerUtilisateurAvecRoles('admin@jersey-store.test', ['ROLE_ADMIN']));
        $this->client->request('GET', '/');
        $this->assertSelectorNotExists('nav a.nav-super-admin');

        $this->client->loginUser($this->creerUtilisateurAvecRoles('super@jersey-store.test', ['ROLE_SUPER_ADMIN']));
        $this->client->request('GET', '/');
        $this->assertSelectorTextContains('nav a.nav-super-admin', 'Super-admin');
        $this->assertSelectorExists('nav a.nav-super-admin[href="/super-admin"]');
    }

    public function testListeDesPaniersNonAchetesSansLesPaniersVides(): void
    {
        $alice = $this->creerUtilisateurAvecRoles('alice@jersey-store.test', []);
        $alice->setNom('Martin')->setPrenom('Alice');
        $bob = $this->creerUtilisateurAvecRoles('bob@jersey-store.test', []);
        $maillotA = $this->creerProduit('Maillot A test', 10.5, 10);
        $maillotB = $this->creerProduit('Maillot B test', 5.0, 10);
        $panierAlice = $this->creerPanier($alice, [[$maillotA, 3], [$maillotB, 2]]);
        $panierVide = $this->creerPanier($bob, []);
        $commande = $this->creerPanier($bob, [[$maillotA, 1]], new \DateTimeImmutable());
        $this->client->loginUser($this->creerUtilisateurAvecRoles('super@jersey-store.test', ['ROLE_SUPER_ADMIN']));

        $crawler = $this->client->request('GET', '/super-admin');

        $this->assertResponseIsSuccessful();
        $paniers = $crawler->filter('article.panier-en-cours');
        $this->assertCount(1, $paniers, 'Ni le panier vide, ni le panier acheté');
        $this->assertSelectorNotExists('#panier-'.$panierVide->getId());
        $this->assertSelectorNotExists('#panier-'.$commande->getId());

        $article = $crawler->filter('#panier-'.$panierAlice->getId());
        $this->assertStringContainsString('Panier n° '.$panierAlice->getId(), $article->filter('h3')->text());
        $this->assertStringContainsString('Martin Alice (alice@jersey-store.test)', $article->filter('.proprietaire')->text());
        $ligneA = $article->filter('tr.ligne-panier')->reduce(fn ($tr) => str_contains($tr->text(), 'Maillot A test'));
        $this->assertSame('3', $ligneA->filter('.quantite')->text());
        $this->assertStringContainsString('10,50', $ligneA->text(), 'Prix unitaire');
        $this->assertStringContainsString('31,50', $ligneA->filter('.montant')->text(), 'Montant de ligne');
        $this->assertStringContainsString('41,50', $article->filter('.total')->text());
        // Lecture seule : pas de bouton de suppression
        $this->assertCount(0, $article->filter('form'));
    }

    public function testInscritsDuJourDuPlusRecentAuPlusAncien(): void
    {
        // Heure figée en milieu de journée : les inscriptions de la matinée sont bien « aujourd'hui ».
        // Date lointaine : aucune vraie inscription de la base ne tombe ce jour-là
        static::mockTime(new \DateTimeImmutable('2030-06-12 15:00', new \DateTimeZone('Europe/Paris')));
        $paris = new \DateTimeZone('Europe/Paris');
        $this->creerInscrit('matin@jersey-store.test', new \DateTimeImmutable('2030-06-12 08:00', $paris));
        $this->creerInscrit('midi@jersey-store.test', new \DateTimeImmutable('2030-06-12 12:00', $paris));
        $this->creerInscrit('aube@jersey-store.test', new \DateTimeImmutable('2030-06-12 00:10', $paris));
        $this->creerInscrit('hier@jersey-store.test', new \DateTimeImmutable('2030-06-11 23:50', $paris));
        $superAdmin = $this->creerInscrit('super@jersey-store.test', new \DateTimeImmutable('2030-06-01 10:00', $paris));
        $superAdmin->setRoles(['ROLE_SUPER_ADMIN']);
        $this->em()->flush();
        $this->client->loginUser($superAdmin);

        $crawler = $this->client->request('GET', '/super-admin');

        $emails = $crawler->filter('tr.inscrit .email')->each(fn ($td) => $td->text());
        $this->assertSame(['midi@jersey-store.test', 'matin@jersey-store.test', 'aube@jersey-store.test'], $emails);
        // Heure affichée à l'heure de Paris
        $this->assertStringContainsString('12:00:00', $crawler->filter('tr.inscrit')->first()->text());
    }

    /**
     * @param list<string> $roles
     */
    private function creerUtilisateurAvecRoles(string $email, array $roles): Utilisateur
    {
        $utilisateur = $this->creerUtilisateur($email)->setRoles($roles);
        $this->em()->flush();

        return $utilisateur;
    }

    /**
     * Crée un utilisateur avec une date d'inscription choisie. L'entité n'a volontairement
     * pas de setter (la date est fixée par le constructeur) : on passe par la réflexion.
     */
    private function creerInscrit(string $email, \DateTimeImmutable $dateInscription): Utilisateur
    {
        $utilisateur = $this->creerUtilisateur($email);
        // Enregistrée dans le fuseau de PHP, comme le ferait le constructeur
        (new \ReflectionProperty(Utilisateur::class, 'dateInscription'))
            ->setValue($utilisateur, $dateInscription->setTimezone(new \DateTimeZone(date_default_timezone_get())));
        $this->em()->flush();

        return $utilisateur;
    }
}
