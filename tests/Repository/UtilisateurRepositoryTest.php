<?php

namespace App\Tests\Repository;

use App\Entity\Utilisateur;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Bornes de « aujourd'hui » (journée de Paris) dans UtilisateurRepository::findInscritsAujourdhui().
 * Les dates sont en 2030 : aucune vraie inscription de la base ne peut tomber ces jours-là.
 */
class UtilisateurRepositoryTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    public function testBornesDeLaJourneeDeParis(): void
    {
        static::bootKernel();
        $paris = new \DateTimeZone('Europe/Paris');
        // Juste après minuit à Paris : en UTC, on est encore la veille (23:05)
        static::mockTime(new \DateTimeImmutable('2030-06-12 00:05', $paris));

        $this->creerInscrit('veille-fin@jersey-store.test', '2030-06-11 23:59:59', $paris);
        $this->creerInscrit('minuit@jersey-store.test', '2030-06-12 00:00:00', $paris);
        $this->creerInscrit('soir@jersey-store.test', '2030-06-12 23:59:59', $paris);
        $this->creerInscrit('lendemain@jersey-store.test', '2030-06-13 00:00:00', $paris);

        $emails = array_map(
            fn (Utilisateur $u) => $u->getEmail(),
            static::getContainer()->get(UtilisateurRepository::class)->findInscritsAujourdhui(),
        );

        // 00:00 inclus, minuit du lendemain exclu ; tri du plus récent au plus ancien
        $this->assertSame(['soir@jersey-store.test', 'minuit@jersey-store.test'], $emails);
    }

    public function testJourDuPassageALHeureDHiver(): void
    {
        static::bootKernel();
        $paris = new \DateTimeZone('Europe/Paris');
        // Le 27/10/2030 dure 25 h à Paris : 23:30 ce jour-là fait encore partie de la journée
        static::mockTime(new \DateTimeImmutable('2030-10-27 12:00', $paris));

        $this->creerInscrit('tard@jersey-store.test', '2030-10-27 23:30:00', $paris);
        $this->creerInscrit('lendemain@jersey-store.test', '2030-10-28 00:30:00', $paris);

        $emails = array_map(
            fn (Utilisateur $u) => $u->getEmail(),
            static::getContainer()->get(UtilisateurRepository::class)->findInscritsAujourdhui(),
        );

        $this->assertSame(['tard@jersey-store.test'], $emails);
    }

    private function creerInscrit(string $email, string $date, \DateTimeZone $fuseau): void
    {
        $utilisateur = (new Utilisateur())
            ->setEmail($email)
            ->setPassword('hash')
            ->setNom('Test')
            ->setPrenom('Compte');
        // Pas de setter sur l'entité : la date est forcée par réflexion, dans le fuseau de PHP
        (new \ReflectionProperty(Utilisateur::class, 'dateInscription'))->setValue(
            $utilisateur,
            (new \DateTimeImmutable($date, $fuseau))->setTimezone(new \DateTimeZone(date_default_timezone_get())),
        );

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($utilisateur);
        $em->flush();
    }
}
