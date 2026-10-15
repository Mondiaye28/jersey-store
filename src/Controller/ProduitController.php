<?php

namespace App\Controller;

use App\Entity\Produit;
use App\Form\ProduitType;
use App\Service\ProduitPhotoUploader;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Fiche produit et gestion du catalogue par les administrateurs.
 * La liste des produits est affichée par HomeController.
 */
final class ProduitController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ProduitPhotoUploader $photoUploader,
    ) {
    }

    #[Route('/produit/{id<\d+>}', name: 'app_produit_show', methods: ['GET'])]
    public function show(#[MapEntity(id: 'id')] Produit $produit): Response
    {
        return $this->render('produit/show.html.twig', [
            'produit' => $produit,
        ]);
    }

    #[Route('/admin/produit/nouveau', name: 'app_produit_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function new(Request $request): Response
    {
        return $this->enregistrer($request, new Produit(), 'produit/new.html.twig', 'flash.produit.cree');
    }

    #[Route('/admin/produit/{id<\d+>}/modifier', name: 'app_produit_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(Request $request, #[MapEntity(id: 'id')] Produit $produit): Response
    {
        return $this->enregistrer($request, $produit, 'produit/edit.html.twig', 'flash.produit.modifie');
    }

    #[Route('/admin/produit/{id<\d+>}/supprimer', name: 'app_produit_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, #[MapEntity(id: 'id')] Produit $produit): Response
    {
        // Le jeton CSRF est propre à chaque produit
        if (!$this->isCsrfTokenValid('supprimer_produit_'.$produit->getId(), $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'flash.produit.csrf_invalide');

            return $this->redirectToRoute('app_produit_show', ['id' => $produit->getId()]);
        }

        $id = $produit->getId();
        $photo = $produit->getPhoto();

        try {
            $this->entityManager->remove($produit);
            $this->entityManager->flush();
        } catch (ForeignKeyConstraintViolationException) {
            // Le produit est encore référencé par un panier : il ne peut pas être supprimé
            $this->addFlash('error', 'flash.produit.suppression_impossible');

            return $this->redirectToRoute('app_produit_show', ['id' => $id]);
        }

        // La photo n'est supprimée qu'une fois le produit effacé de la base
        $this->photoUploader->supprimer($photo);
        $this->addFlash('success', 'flash.produit.supprime');

        return $this->redirectToRoute('app_home');
    }

    /**
     * Traitement commun à la création et à la modification : formulaire, photo, enregistrement.
     */
    private function enregistrer(Request $request, Produit $produit, string $template, string $messageSucces): Response
    {
        $form = $this->createForm(ProduitType::class, $produit);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->photoUploader->remplacer($produit, $form->get('photo')->getData());
            } catch (FileException) {
                $this->addFlash('error', 'flash.produit.upload_erreur');

                return $this->render($template, ['form' => $form, 'produit' => $produit]);
            }

            $this->entityManager->persist($produit);
            $this->entityManager->flush();
            $this->addFlash('success', $messageSucces);

            return $this->redirectToRoute('app_produit_show', ['id' => $produit->getId()]);
        }

        if ($form->isSubmitted()) {
            $this->addFlash('error', 'flash.produit.formulaire_invalide');
        }

        return $this->render($template, ['form' => $form, 'produit' => $produit]);
    }
}
