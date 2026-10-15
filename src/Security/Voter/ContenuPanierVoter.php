<?php

namespace App\Security\Voter;

use App\Entity\ContenuPanier;
use App\Entity\Utilisateur;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Un utilisateur ne peut modifier une ligne de panier que si elle appartient
 * à SON panier en cours (pas à celui d'un autre, ni à un panier déjà payé).
 *
 * @extends Voter<string, ContenuPanier>
 */
final class ContenuPanierVoter extends Voter
{
    public const MODIFIER = 'LIGNE_PANIER_MODIFIER';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::MODIFIER === $attribute && $subject instanceof ContenuPanier;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $utilisateur = $token->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return false;
        }

        $panier = $subject->getPanier();

        // Comparaison par identifiant : le propriétaire du panier doit être l'utilisateur connecté
        return null !== $panier
            && !$panier->isEtat()
            && $panier->getUtilisateur()?->getId() === $utilisateur->getId();
    }
}
