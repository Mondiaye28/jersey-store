<?php

namespace App\Tests\Controller;

use App\Entity\Utilisateur;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tests fonctionnels de l'inscription et de la connexion.
 * Chaque test est annulé à la fin (dama/doctrine-test-bundle) : rien ne reste en base.
 */
class AuthenticationTest extends WebTestCase
{
    private const EMAIL = 'inscription@jersey-store.test';
    private const MOT_DE_PASSE = 'motdepasse123';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testPageInscriptionRepond200(): void
    {
        $this->client->request('GET', '/inscription');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[name="registration_form"]');
    }

    public function testInscriptionValideCreeUnUtilisateurAvecMotDePasseHache(): void
    {
        $this->soumettreInscription(self::EMAIL);

        // Après l'inscription, l'utilisateur est envoyé vers la page de connexion
        $this->assertResponseRedirects('/connexion');

        $utilisateur = static::getContainer()->get(UtilisateurRepository::class)->findOneBy(['email' => self::EMAIL]);
        $this->assertNotNull($utilisateur);
        $this->assertSame('Ndiaye', $utilisateur->getNom());
        $this->assertSame('Awa', $utilisateur->getPrenom());

        // Le mot de passe enregistré est un hash, pas le mot de passe saisi
        $this->assertNotSame(self::MOT_DE_PASSE, $utilisateur->getPassword());
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->assertTrue($hasher->isPasswordValid($utilisateur, self::MOT_DE_PASSE));

        // Le message flash de succès s'affiche sur la page de connexion
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-success', 'Votre compte a été créé');
    }

    public function testEmailDejaUtiliseEstRefuse(): void
    {
        $existant = (new Utilisateur())
            ->setEmail(self::EMAIL)
            ->setPassword('hash')
            ->setNom('Existant')
            ->setPrenom('Compte');
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($existant);
        $entityManager->flush();

        $this->soumettreInscription(self::EMAIL);

        // Formulaire invalide : la page est réaffichée avec un message d'échec
        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('.flash-error', 'Le formulaire contient des erreurs');
        // Le refus vient bien de la contrainte UniqueEntity sur l'email
        $this->assertAnySelectorTextContains('li', 'Cette valeur est déjà utilisée.');
        $this->assertSame(1, static::getContainer()->get(UtilisateurRepository::class)->count(['email' => self::EMAIL]));
    }

    public function testPageConnexionRepond200(): void
    {
        $this->client->request('GET', '/connexion');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="_username"]');
        $this->assertSelectorExists('input[name="_csrf_token"]');
    }

    /**
     * Remplit et envoie le formulaire d'inscription avec l'email donné.
     */
    private function soumettreInscription(string $email): void
    {
        $this->client->request('GET', '/inscription');
        $this->client->submitForm('Créer mon compte', [
            'registration_form[nom]' => 'Ndiaye',
            'registration_form[prenom]' => 'Awa',
            'registration_form[email]' => $email,
            'registration_form[plainPassword]' => self::MOT_DE_PASSE,
        ]);
    }
}
