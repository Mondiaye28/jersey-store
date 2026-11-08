<?php

namespace App\Repository;

use App\Entity\Panier;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Panier>
 */
class PanierRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Panier::class);
    }

    /**
     * Panier en cours (pas encore payé) de l'utilisateur, ou null s'il n'en a pas.
     */
    public function findEnCours(Utilisateur $utilisateur): ?Panier
    {
        return $this->findOneBy(['utilisateur' => $utilisateur, 'etat' => false]);
    }

    /**
     * Paniers en cours (etat = false) de tous les utilisateurs, avec leur propriétaire,
     * leurs lignes et leurs produits. La jointure interne sur les lignes exclut les paniers vides.
     *
     * @return list<Panier>
     */
    public function findEnCoursNonVides(): array
    {
        return $this->createQueryBuilder('p')
            ->addSelect('u', 'c', 'pr')
            ->innerJoin('p.utilisateur', 'u')
            ->innerJoin('p.contenuPaniers', 'c')
            ->innerJoin('c.produit', 'pr')
            ->andWhere('p.etat = false')
            ->orderBy('p.id', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Paniers achetés (etat = true) de l'utilisateur, les plus récents d'abord.
     * Les lignes et leurs produits sont chargés en une seule requête pour calculer les totaux.
     *
     * @return list<Panier>
     */
    public function findCommandes(Utilisateur $utilisateur): array
    {
        return $this->createQueryBuilder('p')
            ->addSelect('c', 'pr')
            ->leftJoin('p.contenuPaniers', 'c')
            ->leftJoin('c.produit', 'pr')
            ->andWhere('p.utilisateur = :utilisateur')
            ->andWhere('p.etat = true')
            ->setParameter('utilisateur', $utilisateur)
            ->orderBy('p.dateAchat', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

//    /**
//     * @return Panier[] Returns an array of Panier objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('p')
//            ->andWhere('p.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('p.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?Panier
//    {
//        return $this->createQueryBuilder('p')
//            ->andWhere('p.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}
