<?php

namespace App\Controller;

use App\Repository\UtilisateurRepository;
use App\Service\PanierService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Section réservée au super-administrateur : paniers non achetés et inscrits du jour.
 * Un ROLE_ADMIN simple reçoit une 403 (en plus de la règle access_control sur ^/super-admin).
 */
#[Route('/super-admin')]
#[IsGranted('ROLE_SUPER_ADMIN')]
final class SuperAdminController extends AbstractController
{
    #[Route('', name: 'app_super_admin', methods: ['GET'])]
    public function index(PanierService $panierService, UtilisateurRepository $utilisateurRepository): Response
    {
        // Paniers en cours (etat = false) contenant au moins une ligne
        $paniers = $panierService->getPaniersEnCoursNonVides();

        return $this->render('super_admin/index.html.twig', [
            'paniers' => $paniers,
            'montants' => $panierService->calculerMontantsLignesDe($paniers),
            'totaux' => $panierService->calculerTotaux($paniers),
            'inscrits' => $utilisateurRepository->findInscritsAujourdhui(),
        ]);
    }
}
