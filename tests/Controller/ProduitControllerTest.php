<?php

namespace App\Tests\Controller;

use App\Entity\Produit;
use App\Entity\Utilisateur;
use App\Repository\ProduitRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests fonctionnels du catalogue : accueil, fiche produit et gestion par l'administrateur.
 * Chaque test est annulé à la fin (dama/doctrine-test-bundle) : rien ne reste en base.
 */
class ProduitControllerTest extends WebTestCase
{
    // Image PNG de 1x1 pixel, pour tester l'envoi d'une photo
    private const PNG_1PX = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    /** @var list<string> fichiers temporaires à effacer après le test */
    private array $fichiersAEffacer = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        // Les fichiers ne sont pas concernés par l'annulation de la transaction
        foreach ($this->fichiersAEffacer as $fichier) {
            if (is_file($fichier)) {
                unlink($fichier);
            }
        }

        parent::tearDown();
    }

    public function testAccueilListeLesProduitsAvecUnLienVersLeurFiche(): void
    {
        $produit = $this->creerProduit('Maillot domicile test');

        $crawler = $this->client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $lien = $crawler->filter(\sprintf('a[href="/produit/%d"]', $produit->getId()));
        $this->assertCount(1, $lien);
        $this->assertStringContainsString('Maillot domicile test', $lien->text());
        // Un visiteur ne voit pas le bouton d'ajout
        $this->assertSelectorNotExists('a[href="/admin/produit/nouveau"]');
    }

    public function testAccueilAfficheLeBoutonAjouterSeulementPourAdmin(): void
    {
        $this->client->loginUser($this->creerUtilisateur('client@jersey-store.test', []));
        $this->client->request('GET', '/');
        $this->assertSelectorNotExists('a[href="/admin/produit/nouveau"]');

        $this->client->loginUser($this->creerUtilisateur('admin@jersey-store.test', ['ROLE_ADMIN']));
        $this->client->request('GET', '/');
        $this->assertSelectorTextContains('a[href="/admin/produit/nouveau"]', 'Ajouter un produit');
    }

    public function testFicheProduitPourUnVisiteur(): void
    {
        $produit = $this->creerProduit('Maillot extérieur test');

        $this->client->request('GET', '/produit/'.$produit->getId());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Maillot extérieur test');
        $this->assertSelectorTextContains('body', 'Saison 2026');
        $this->assertSelectorTextContains('dd', '89,99');
        $this->assertSelectorTextContains('body', 'Pas de photo pour ce produit.');
        // Ni achat ni gestion pour un visiteur
        $this->assertSelectorNotExists('button.ajouter-panier');
        $this->assertSelectorNotExists('form[action$="/supprimer"]');
        $this->assertSelectorNotExists('a[href$="/modifier"]');
    }

    public function testFicheProduitPourUnUtilisateurConnecte(): void
    {
        $produit = $this->creerProduit('Maillot client test');
        $this->client->loginUser($this->creerUtilisateur('client@jersey-store.test', []));

        $this->client->request('GET', '/produit/'.$produit->getId());

        $this->assertSelectorTextContains('button.ajouter-panier', 'Ajouter au panier');
        $this->assertSelectorNotExists('form[action$="/supprimer"]');
        $this->assertSelectorNotExists('a[href$="/modifier"]');
    }

    public function testFicheProduitPourUnAdmin(): void
    {
        $produit = $this->creerProduit('Maillot admin test');
        $this->client->loginUser($this->creerUtilisateur('admin@jersey-store.test', ['ROLE_ADMIN']));

        $this->client->request('GET', '/produit/'.$produit->getId());

        $this->assertSelectorExists(\sprintf('a[href="/admin/produit/%d/modifier"]', $produit->getId()));
        // La suppression passe par un formulaire POST qui contient un jeton CSRF
        $this->assertSelectorExists(\sprintf('form[method="post"][action="/admin/produit/%d/supprimer"] input[name="_token"]', $produit->getId()));
    }

    public function testFicheProduitInexistanteRepond404(): void
    {
        $this->client->request('GET', '/produit/999999999');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testCreationRefuseePourUnVisiteur(): void
    {
        $this->client->request('GET', '/admin/produit/nouveau');

        // Visiteur non connecté : renvoyé vers la page de connexion
        $this->assertResponseRedirects('/connexion');
    }

    public function testCreationRefuseePourUnUtilisateurSansRoleAdmin(): void
    {
        $this->client->loginUser($this->creerUtilisateur('client@jersey-store.test', []));

        $this->client->request('GET', '/admin/produit/nouveau');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testCreationParAdminAvecPhoto(): void
    {
        $this->client->loginUser($this->creerUtilisateur('admin@jersey-store.test', ['ROLE_ADMIN']));
        $this->client->request('GET', '/admin/produit/nouveau');

        $this->client->submitForm('Enregistrer', [
            'produit[nom]' => 'Maillot créé test',
            'produit[description]' => 'Description du maillot',
            'produit[prix]' => '59.90',
            'produit[stock]' => '7',
            'produit[photo]' => $this->creerImage('png'),
        ]);

        $produit = static::getContainer()->get(ProduitRepository::class)->findOneBy(['nom' => 'Maillot créé test']);
        $this->assertNotNull($produit);
        $this->assertResponseRedirects('/produit/'.$produit->getId());
        $this->assertSame(59.9, $produit->getPrix());
        $this->assertSame(7, $produit->getStock());

        // Seul le nom du fichier est stocké ; le fichier est dans public/uploads/produits/
        $this->assertMatchesRegularExpression('/^maillot-[0-9a-f]{16}\.png$/', $produit->getPhoto());
        $chemin = $this->dossierUploads().'/'.$produit->getPhoto();
        $this->fichiersAEffacer[] = $chemin;
        $this->assertFileExists($chemin);

        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-success', 'Le produit a été créé.');
        $this->assertSelectorExists('img[src="/uploads/produits/'.$produit->getPhoto().'"]');
    }

    public function testCreationInvalideEstRefusee(): void
    {
        $this->client->loginUser($this->creerUtilisateur('admin@jersey-store.test', ['ROLE_ADMIN']));
        $this->client->request('GET', '/admin/produit/nouveau');

        // Nom et description vides, prix et stock négatifs
        $this->client->submitForm('Enregistrer', [
            'produit[nom]' => '',
            'produit[description]' => '',
            'produit[prix]' => '-1',
            'produit[stock]' => '-3',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('.flash-error', "Le produit n'a pas été enregistré.");
        $this->assertSame(0, static::getContainer()->get(ProduitRepository::class)->count(['description' => '']));
    }

    public function testPrixVideEstRefuseSansErreurServeur(): void
    {
        $this->client->loginUser($this->creerUtilisateur('admin@jersey-store.test', ['ROLE_ADMIN']));
        $this->client->request('GET', '/admin/produit/nouveau');

        $this->client->submitForm('Enregistrer', [
            'produit[nom]' => 'Maillot sans prix test',
            'produit[description]' => 'Description',
            'produit[prix]' => '',
            'produit[stock]' => '',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertNull(static::getContainer()->get(ProduitRepository::class)->findOneBy(['nom' => 'Maillot sans prix test']));
    }

    public function testPhotoDeMauvaisTypeEstRefusee(): void
    {
        $this->client->loginUser($this->creerUtilisateur('admin@jersey-store.test', ['ROLE_ADMIN']));
        $this->client->request('GET', '/admin/produit/nouveau');

        $this->client->submitForm('Enregistrer', [
            'produit[nom]' => 'Maillot photo texte test',
            'produit[description]' => 'Description',
            'produit[prix]' => '10',
            'produit[stock]' => '1',
            'produit[photo]' => $this->creerFichierTexte(),
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertNull(static::getContainer()->get(ProduitRepository::class)->findOneBy(['nom' => 'Maillot photo texte test']));
    }

    public function testModificationParAdminConserveLaPhoto(): void
    {
        $produit = $this->creerProduit('Maillot à modifier test');
        $produit->setPhoto('photo-existante.png');
        $this->entityManager->flush();
        $this->client->loginUser($this->creerUtilisateur('admin@jersey-store.test', ['ROLE_ADMIN']));

        $this->client->request('GET', \sprintf('/admin/produit/%d/modifier', $produit->getId()));
        $this->client->submitForm('Enregistrer', [
            'produit[nom]' => 'Maillot modifié test',
            'produit[stock]' => '3',
        ]);

        $this->assertResponseRedirects('/produit/'.$produit->getId());
        $this->entityManager->clear();
        $modifie = $this->entityManager->find(Produit::class, $produit->getId());
        $this->assertSame('Maillot modifié test', $modifie->getNom());
        $this->assertSame(3, $modifie->getStock());
        // Sans nouveau fichier, la photo actuelle est conservée
        $this->assertSame('photo-existante.png', $modifie->getPhoto());

        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-success', 'Le produit a été modifié.');
    }

    public function testSuppressionParAdmin(): void
    {
        $produit = $this->creerProduit('Maillot à supprimer test');
        $id = $produit->getId();
        $this->client->loginUser($this->creerUtilisateur('admin@jersey-store.test', ['ROLE_ADMIN']));

        $this->client->request('GET', '/produit/'.$id);
        $this->client->submitForm('Supprimer');

        $this->assertResponseRedirects('/');
        $this->entityManager->clear();
        $this->assertNull($this->entityManager->find(Produit::class, $id));
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-success', 'Le produit a été supprimé.');
    }

    public function testSuppressionAvecJetonInvalideEstRefusee(): void
    {
        $produit = $this->creerProduit('Maillot protégé test');
        $id = $produit->getId();
        $this->client->loginUser($this->creerUtilisateur('admin@jersey-store.test', ['ROLE_ADMIN']));

        $this->client->request('POST', \sprintf('/admin/produit/%d/supprimer', $id), ['_token' => 'jeton-invalide']);

        $this->assertResponseRedirects('/produit/'.$id);
        $this->entityManager->clear();
        $this->assertNotNull($this->entityManager->find(Produit::class, $id));
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-error', "La demande a expiré ou n'est pas valide.");
    }

    public function testSuppressionParGetEstImpossible(): void
    {
        $produit = $this->creerProduit('Maillot GET test');
        $this->client->loginUser($this->creerUtilisateur('admin@jersey-store.test', ['ROLE_ADMIN']));

        $this->client->request('GET', \sprintf('/admin/produit/%d/supprimer', $produit->getId()));

        $this->assertResponseStatusCodeSame(405);
    }

    private function creerProduit(string $nom): Produit
    {
        $produit = (new Produit())
            ->setNom($nom)
            ->setDescription('Saison 2026')
            ->setPrix(89.99)
            ->setStock(10);
        $this->entityManager->persist($produit);
        $this->entityManager->flush();

        return $produit;
    }

    /**
     * @param list<string> $roles
     */
    private function creerUtilisateur(string $email, array $roles): Utilisateur
    {
        $utilisateur = (new Utilisateur())
            ->setEmail($email)
            ->setPassword('hash')
            ->setNom('Test')
            ->setPrenom('Compte')
            ->setRoles($roles);
        $this->entityManager->persist($utilisateur);
        $this->entityManager->flush();

        return $utilisateur;
    }

    private function creerImage(string $extension): string
    {
        $chemin = sys_get_temp_dir().'/maillot.'.$extension;
        file_put_contents($chemin, base64_decode(self::PNG_1PX));
        $this->fichiersAEffacer[] = $chemin;

        return $chemin;
    }

    private function creerFichierTexte(): string
    {
        $chemin = sys_get_temp_dir().'/pas-une-image.png';
        file_put_contents($chemin, 'ceci est du texte');
        $this->fichiersAEffacer[] = $chemin;

        return $chemin;
    }

    private function dossierUploads(): string
    {
        return static::getContainer()->getParameter('kernel.project_dir').'/public/uploads/produits';
    }
}
