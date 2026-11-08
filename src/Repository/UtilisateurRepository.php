<?php

namespace App\Repository;

use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<Utilisateur>
 */
class UtilisateurRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    // Fuseau dans lequel « aujourd'hui » est défini pour la boutique
    private const FUSEAU_BOUTIQUE = 'Europe/Paris';

    public function __construct(
        ManagerRegistry $registry,
        private ClockInterface $clock,
    ) {
        parent::__construct($registry, Utilisateur::class);
    }

    /**
     * Utilisateurs inscrits aujourd'hui (journée de Paris, de 00:00 inclus à 00:00 le lendemain exclu),
     * du plus récent au plus ancien.
     *
     * @return list<Utilisateur>
     */
    public function findInscritsAujourdhui(): array
    {
        $debut = $this->clock->now()
            ->setTimezone(new \DateTimeZone(self::FUSEAU_BOUTIQUE))
            ->setTime(0, 0);
        // modify() plutôt que +24 h : les jours de changement d'heure durent 23 h ou 25 h
        $fin = $debut->modify('+1 day');

        // Les dates sont enregistrées sans fuseau, dans celui de PHP (date.timezone) :
        // les bornes sont converties dans ce même fuseau avant la comparaison
        $fuseauStockage = new \DateTimeZone(date_default_timezone_get());

        return $this->createQueryBuilder('u')
            ->andWhere('u.dateInscription >= :debut')
            ->andWhere('u.dateInscription < :fin')
            ->setParameter('debut', $debut->setTimezone($fuseauStockage), Types::DATETIME_IMMUTABLE)
            ->setParameter('fin', $fin->setTimezone($fuseauStockage), Types::DATETIME_IMMUTABLE)
            ->orderBy('u.dateInscription', 'DESC')
            ->addOrderBy('u.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof Utilisateur) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

//    /**
//     * @return Utilisateur[] Returns an array of Utilisateur objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('u')
//            ->andWhere('u.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('u.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?Utilisateur
//    {
//        return $this->createQueryBuilder('u')
//            ->andWhere('u.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}
