<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Page de connexion.
 * La vérification du mot de passe est faite par le firewall (form_login) :
 * ce contrôleur se contente d'afficher le formulaire et ses erreurs.
 * La déconnexion est gérée par le firewall (route app_logout).
 */
final class SecurityController extends AbstractController
{
    #[Route('/connexion', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        // Erreur de la dernière tentative de connexion, ou null
        $error = $authenticationUtils->getLastAuthenticationError();

        // Dernier email saisi, pour le pré-remplir après une erreur
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
        ]);
    }

    /**
     * Route de déconnexion utilisée par le firewall (logout.path: app_logout).
     * Le firewall intercepte la requête avant d'arriver ici : cette méthode n'est jamais exécutée.
     */
    #[Route('/deconnexion', name: 'app_logout')]
    public function logout(): never
    {
        throw new \LogicException('Cette méthode est interceptée par la clé logout du firewall.');
    }
}
