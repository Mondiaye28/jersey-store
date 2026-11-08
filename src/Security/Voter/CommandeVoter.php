<?php

namespace App\Security\Voter;

use App\Entity\Panier;
use App\Entity\Utilisateur;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Un utilisateur ne peut voir une commande que si c'est SON panier et qu'il a été acheté
 * (un panier en cours n'est pas une commande).
 *
 * @extends Voter<string, Panier>
 */
final class CommandeVoter extends Voter
{
    public const VOIR = 'COMMANDE_VOIR';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::VOIR === $attribute && $subject instanceof Panier;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $utilisateur = $token->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return false;
        }

        // Comparaison par identifiant : le propriétaire de la commande doit être l'utilisateur connecté
        return $subject->isEtat()
            && $subject->getUtilisateur()?->getId() === $utilisateur->getId();
    }
}
