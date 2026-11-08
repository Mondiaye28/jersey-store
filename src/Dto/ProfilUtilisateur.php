<?php

namespace App\Dto;

use App\Entity\Utilisateur;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Données du formulaire « Mon compte ».
 * Le formulaire travaille sur cet objet et non sur l'entité : si la saisie est invalide,
 * l'utilisateur connecté (gardé en session) n'est jamais modifié.
 * L'email doit rester unique, en ignorant le compte lui-même (comparaison par id).
 */
#[UniqueEntity(fields: ['email'], entityClass: Utilisateur::class, identifierFieldNames: ['id'])]
class ProfilUtilisateur
{
    public ?int $id = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public ?string $nom = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public ?string $prenom = null;

    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    public ?string $email = null;

    // Facultatif : laissé vide, le mot de passe actuel est conservé
    #[Assert\Length(min: 8, max: 4096)]
    public ?string $plainPassword = null;

    public static function depuisUtilisateur(Utilisateur $utilisateur): self
    {
        $profil = new self();
        $profil->id = $utilisateur->getId();
        $profil->nom = $utilisateur->getNom();
        $profil->prenom = $utilisateur->getPrenom();
        $profil->email = $utilisateur->getEmail();

        return $profil;
    }
}
