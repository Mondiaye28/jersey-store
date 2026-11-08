<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Form\RegistrationFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Création d'un compte utilisateur.
 */
final class RegistrationController extends AbstractController
{
    #[Route('/inscription', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
    ): Response {
        // Inutile de s'inscrire si on est déjà connecté
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $utilisateur = new Utilisateur();
        $form = $this->createForm(RegistrationFormType::class, $utilisateur);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Le mot de passe est haché avant l'enregistrement : jamais de mot de passe en clair en base
            $utilisateur->setPassword(
                $passwordHasher->hashPassword($utilisateur, $form->get('plainPassword')->getData())
            );

            $entityManager->persist($utilisateur);
            $entityManager->flush();

            $this->addFlash('success', 'flash.register.success');

            return $this->redirectToRoute('app_login');
        }

        if ($form->isSubmitted()) {
            $this->addFlash('error', 'flash.register.error');
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }
}
