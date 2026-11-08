<?php

namespace App\Tests\Controller;

use App\Entity\Utilisateur;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Barre de navigation adaptée au rôle, et sélecteur de langue.
 */
class NavigationTest extends WebTestCase
{
    use DonneesDeTestTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testNavigationDuVisiteur(): void
    {
        $this->client->request('GET', '/');

        $this->assertSame(['/', '/connexion', '/inscription'], $this->liensDeNavigation());
        $this->assertSelectorNotExists('nav a.nav-panier');
    }

    public function testNavigationDUnUtilisateur(): void
    {
        $this->client->loginUser($this->utilisateurAvecRoles('client@jersey-store.test', []));
        $this->client->request('GET', '/');

        $this->assertSame(['/', '/panier', '/compte', '/deconnexion'], $this->liensDeNavigation());
        $this->assertSelectorNotExists('nav a.nav-super-admin');
        $this->assertSelectorNotExists('nav a.nav-ajouter-produit');
    }

    public function testNavigationDUnAdmin(): void
    {
        $this->client->loginUser($this->utilisateurAvecRoles('admin@jersey-store.test', ['ROLE_ADMIN']));
        $this->client->request('GET', '/');

        $this->assertSame(['/', '/panier', '/compte', '/admin/produit/nouveau', '/deconnexion'], $this->liensDeNavigation());
        $this->assertSelectorTextContains('nav a.nav-ajouter-produit', 'Ajouter un produit');
    }

    public function testNavigationDUnSuperAdmin(): void
    {
        $this->client->loginUser($this->utilisateurAvecRoles('super@jersey-store.test', ['ROLE_SUPER_ADMIN']));
        $this->client->request('GET', '/');

        $this->assertSame(
            ['/', '/panier', '/compte', '/admin/produit/nouveau', '/super-admin', '/deconnexion'],
            $this->liensDeNavigation(),
        );
    }

    /**
     * Chaque lien de navigation doit être accessible au rôle qui le voit (aucune 403).
     *
     * @param list<string>|null $roles null = visiteur
     */
    #[DataProvider('roles')]
    public function testAucunLienVersUnePageInterdite(?array $roles): void
    {
        if (null !== $roles) {
            $this->client->loginUser($this->utilisateurAvecRoles('role@jersey-store.test', $roles));
        }
        $this->client->request('GET', '/');

        foreach ($this->liensDeNavigation() as $lien) {
            if ('/deconnexion' === $lien) {
                continue; // déconnecterait l'utilisateur pour les liens suivants
            }
            $this->client->request('GET', $lien);
            $this->assertResponseIsSuccessful(\sprintf('Le lien %s doit être accessible', $lien));
        }
    }

    /**
     * @return iterable<string, array{list<string>|null}>
     */
    public static function roles(): iterable
    {
        yield 'visiteur' => [null];
        yield 'utilisateur' => [[]];
        yield 'admin' => [['ROLE_ADMIN']];
        yield 'super-admin' => [['ROLE_SUPER_ADMIN']];
    }

    public function testLeChoixDeLaLangueAnglaiseEstAppliqueEtConserve(): void
    {
        $crawler = $this->client->request('GET', '/connexion');
        $this->assertSelectorTextContains('h1', 'Connexion');

        // On revient sur la page d'où l'on vient, désormais en anglais
        $this->client->request('GET', '/langue/en', [], [], ['HTTP_REFERER' => 'http://localhost/connexion']);
        $this->assertResponseRedirects('/connexion');

        $crawler = $this->client->followRedirect();
        $this->assertSame('en', $crawler->filter('html')->attr('lang'));
        $this->assertSelectorTextContains('h1', 'Log in');

        // La langue reste en session pour les pages suivantes
        $this->client->request('GET', '/');
        $this->assertSelectorTextContains('nav', 'Home');

        // Retour au français
        $this->client->request('GET', '/langue/fr');
        $this->client->request('GET', '/');
        $this->assertSelectorTextContains('nav', 'Accueil');
    }

    public function testRedirectionVersLAccueilSansPagePrecedente(): void
    {
        $this->client->request('GET', '/langue/en');

        $this->assertResponseRedirects('/');
    }

    public function testPasDeRedirectionVersUnSiteExterne(): void
    {
        $this->client->request('GET', '/langue/en', [], [], ['HTTP_REFERER' => 'https://site-malveillant.example/piege']);

        $this->assertResponseRedirects('/');
    }

    /**
     * Même hôte, mais un chemin que le navigateur interpréterait comme un autre site.
     */
    #[DataProvider('referersDetournes')]
    public function testPasDeRedirectionVersUnSiteExterneViaLeChemin(string $referer): void
    {
        $this->client->request('GET', '/langue/en', [], [], ['HTTP_REFERER' => $referer]);

        $this->assertResponseRedirects('/');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function referersDetournes(): iterable
    {
        yield 'double barre oblique' => ['http://localhost//site-malveillant.example/piege'];
        yield 'barre oblique inversée' => ['http://localhost/\\site-malveillant.example/piege'];
    }

    public function testLangueNonProposeeRefusee(): void
    {
        $this->client->request('GET', '/langue/de');

        $this->assertResponseStatusCodeSame(404);
    }

    /**
     * @return list<string> chemins des liens de la barre de navigation, hors sélecteur de langue
     */
    private function liensDeNavigation(): array
    {
        return $this->client->getCrawler()
            ->filter('nav > a')
            ->each(fn ($a) => (string) $a->attr('href'));
    }

    /**
     * @param list<string> $roles
     */
    private function utilisateurAvecRoles(string $email, array $roles): Utilisateur
    {
        $utilisateur = $this->creerUtilisateur($email)->setRoles($roles);
        $this->em()->flush();

        return $utilisateur;
    }
}
