<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Pages d'erreur personnalisées. Elles ne remplacent la page de débogage de Symfony
 * que lorsque le débogage est désactivé, comme en prod : le noyau est donc démarré avec debug = false.
 */
class PagesErreurTest extends WebTestCase
{
    use DonneesDeTestTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient(['debug' => false]);
    }

    public function testPage404(): void
    {
        $this->client->request('GET', '/page-qui-n-existe-pas');

        $this->assertResponseStatusCodeSame(404);
        $this->assertSelectorTextContains('h1', 'Page introuvable');
        $this->assertSelectorExists('main a[href="/"]');
        // La page étend base.html.twig : la navigation est présente
        $this->assertSelectorExists('nav a.nav-connexion');
    }

    public function testPage404EnAnglais(): void
    {
        $this->client->request('GET', '/langue/en');
        $this->client->request('GET', '/page-qui-n-existe-pas');

        $this->assertResponseStatusCodeSame(404);
        $this->assertSelectorTextContains('h1', 'Page not found');
    }

    public function testPage403(): void
    {
        $this->client->loginUser($this->creerUtilisateur('client@jersey-store.test'));

        $this->client->request('GET', '/super-admin');

        $this->assertResponseStatusCodeSame(403);
        $this->assertSelectorTextContains('h1', 'Accès refusé');
        $this->assertSelectorExists('main a[href="/"]');
    }

    public function testPageGeneriquePourLesAutresCodes(): void
    {
        // 405 : la route d'achat n'accepte que POST
        $this->client->loginUser($this->creerUtilisateur('client@jersey-store.test'));

        $this->client->request('GET', '/panier/acheter');

        $this->assertResponseStatusCodeSame(405);
        $this->assertSelectorTextContains('h1', 'Une erreur est survenue');
        $this->assertSelectorTextContains('.message-erreur', '405');
    }
}
