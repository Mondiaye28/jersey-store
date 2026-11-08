<?php

namespace App\Controller;

use App\Dto\ProfilUtilisateur;
use App\Entity\Panier;
use App\Entity\Utilisateur;
use App\Form\ProfilFormType;
use App\Security\Voter\CommandeVoter;
use App\Service\CompteService;
use App\Service\PanierService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Page « Mon compte » : modification du profil, historique et détail des commandes.
 * La logique métier est dans CompteService et PanierService.
 */
#[IsGranted('ROLE_USER')]
final class CompteController extends AbstractController
{
    public function __construct(
        private PanierService $panierService,
        private CompteService $compteService,
    ) {
    }

    #[Route('/compte', name: 'app_compte', methods: ['GET', 'POST'])]
    public function index(Request $request, #[CurrentUser] Utilisateur $utilisateur): Response
    {
        $form = $this->createForm(ProfilFormType::class, ProfilUtilisateur::depuisUtilisateur($utilisateur));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->compteService->modifierProfil($utilisateur, $form->getData());
            $this->addFlash('success', 'flash.compte.modifie');

            return $this->redirectToRoute('app_compte');
        }

        if ($form->isSubmitted()) {
            $this->addFlash('error', 'flash.compte.formulaire_invalide');
        }

        // Historique : uniquement les paniers achetés (etat = true)
        $commandes = $this->panierService->getCommandes($utilisateur);

        return $this->render('compte/index.html.twig', [
            'profilForm' => $form,
            'commandes' => $commandes,
            'totaux' => $this->panierService->calculerTotaux($commandes),
        ]);
    }

    #[Route('/compte/commande/{id<\d+>}', name: 'app_commande_show', methods: ['GET'])]
    // 403 si la commande n'appartient pas à l'utilisateur connecté (ou n'est pas encore achetée)
    #[IsGranted(CommandeVoter::VOIR, subject: 'commande')]
    public function commande(#[MapEntity(id: 'id')] Panier $commande): Response
    {
        return $this->render('compte/commande.html.twig', [
            'commande' => $commande,
            'montants' => $this->panierService->calculerMontantsLignes($commande),
            'total' => $this->panierService->calculerTotal($commande),
        ]);
    }
}
