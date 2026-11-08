<?php

namespace App\Service;

use App\Dto\ProfilUtilisateur;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Logique métier de la page « Mon compte ».
 */
class CompteService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /**
     * Recopie un profil déjà validé sur l'utilisateur et l'enregistre.
     * Le mot de passe n'est changé que s'il a été saisi, et il est toujours haché.
     */
    public function modifierProfil(Utilisateur $utilisateur, ProfilUtilisateur $profil): void
    {
        $utilisateur
            ->setNom($profil->nom)
            ->setPrenom($profil->prenom)
            ->setEmail($profil->email);

        if (null !== $profil->plainPassword && '' !== $profil->plainPassword) {
            $utilisateur->setPassword($this->passwordHasher->hashPassword($utilisateur, $profil->plainPassword));
        }

        $this->entityManager->flush();
    }
}
