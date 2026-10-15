<?php

namespace App\Controller;

use App\Entity\ContenuPanier;
use App\Entity\Produit;
use App\Entity\Utilisateur;
use App\Exception\StockInsuffisantException;
use App\Security\Voter\ContenuPanierVoter;
use App\Service\PanierService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Panier de l'utilisateur connecté. La logique métier est dans PanierService.
 * Chaque action ne travaille que sur le panier de l'utilisateur connecté.
 */
final class PanierController extends AbstractController
{
    public function __construct(
        private PanierService $panierService,
    ) {
    }

    #[Route('/panier', name: 'app_panier', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(#[CurrentUser] Utilisateur $utilisateur): Response
    {
        // Uniquement le panier en cours (etat = false) de l'utilisateur connecté
        $panier = $this->panierService->getPanierEnCours($utilisateur);

        return $this->render('panier/index.html.twig', [
            'panier' => $panier,
            'montants' => null !== $panier ? $this->panierService->calculerMontantsLignes($panier) : [],
            'total' => null !== $panier ? $this->panierService->calculerTotal($panier) : 0.0,
        ]);
    }

    #[Route('/panier/ajouter/{id<\d+>}', name: 'app_panier_ajouter', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function ajouter(
        Request $request,
        #[MapEntity(id: 'id')] Produit $produit,
        #[CurrentUser] Utilisateur $utilisateur,
    ): Response {
        $retourFiche = $this->redirectToRoute('app_produit_show', ['id' => $produit->getId()]);

        if (!$this->isCsrfTokenValid('ajouter_panier_'.$produit->getId(), $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'flash.panier.csrf_invalide');

            return $retourFiche;
        }

        $quantite = $request->getPayload()->getInt('quantite', 1);
        if ($quantite < 1) {
            $this->addFlash('error', 'flash.panier.quantite_invalide');

            return $retourFiche;
        }

        try {
            $this->panierService->ajouter($utilisateur, $produit, $quantite);
        } catch (StockInsuffisantException) {
            $this->addFlash('error', 'flash.panier.stock_insuffisant');

            return $retourFiche;
        }

        $this->addFlash('success', 'flash.panier.ajoute');

        return $retourFiche;
    }

    #[Route('/panier/ligne/{id<\d+>}/supprimer', name: 'app_panier_ligne_supprimer', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    // 403 si la ligne n'appartient pas au panier en cours de l'utilisateur connecté
    #[IsGranted(ContenuPanierVoter::MODIFIER, subject: 'ligne')]
    public function supprimerLigne(Request $request, #[MapEntity(id: 'id')] ContenuPanier $ligne): Response
    {
        if (!$this->isCsrfTokenValid('supprimer_ligne_'.$ligne->getId(), $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'flash.panier.csrf_invalide');

            return $this->redirectToRoute('app_panier');
        }

        $this->panierService->retirerLigne($ligne);
        $this->addFlash('success', 'flash.panier.ligne_supprimee');

        return $this->redirectToRoute('app_panier');
    }
}
