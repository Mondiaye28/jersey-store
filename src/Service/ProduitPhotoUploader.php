<?php

namespace App\Service;

use App\Entity\Produit;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Enregistre et supprime les photos des produits dans public/uploads/produits/.
 * Seul le nom du fichier est stocké dans Produit::$photo.
 */
class ProduitPhotoUploader
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/public/uploads/produits')]
        private string $dossier,
        private SluggerInterface $slugger,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * Remplace la photo du produit par le fichier envoyé, puis supprime l'ancienne.
     * Sans fichier, la photo actuelle est conservée.
     *
     * @throws FileException si le fichier ne peut pas être enregistré
     */
    public function remplacer(Produit $produit, ?UploadedFile $fichier): void
    {
        if (null === $fichier) {
            return;
        }

        $anciennePhoto = $produit->getPhoto();
        $produit->setPhoto($this->enregistrer($fichier));
        $this->supprimer($anciennePhoto);
    }

    /**
     * Supprime le fichier d'une photo, s'il existe.
     */
    public function supprimer(?string $nomFichier): void
    {
        if (null === $nomFichier || '' === $nomFichier) {
            return;
        }

        // basename() empêche de sortir du dossier des uploads
        $this->filesystem->remove($this->dossier.'/'.basename($nomFichier));
    }

    /**
     * Déplace le fichier dans le dossier des uploads sous un nom unique et retourne ce nom.
     */
    private function enregistrer(UploadedFile $fichier): string
    {
        // Nom lisible tiré du nom d'origine + suffixe aléatoire pour éviter les collisions
        $nomOriginal = pathinfo($fichier->getClientOriginalName(), \PATHINFO_FILENAME);
        $nomFichier = \sprintf(
            '%s-%s.%s',
            $this->slugger->slug($nomOriginal)->lower(),
            bin2hex(random_bytes(8)),
            $fichier->guessExtension() ?? 'bin',
        );

        $fichier->move($this->dossier, $nomFichier);

        return $nomFichier;
    }
}
