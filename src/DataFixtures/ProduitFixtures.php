<?php

namespace App\DataFixtures;

use App\Entity\Produit;
use App\Repository\ProduitRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Catalogue de démonstration : maillots de clubs fictifs, chacun avec une illustration SVG
 * (couleurs du maillot et numéro floqué) enregistrée dans public/uploads/produits.
 *
 * À charger sans vider la base : php bin/console doctrine:fixtures:load --append
 * Un produit déjà présent (même nom) n'est pas recréé : la commande peut être relancée sans doublon.
 */
class ProduitFixtures extends Fixture
{
    /**
     * nom, description, prix, stock, puis le maillot : couleur du corps, couleur des manches,
     * couleur du col et des bords, rayures verticales (couleur ou null), couleur et valeur du numéro.
     */
    private const MAILLOTS = [
        ['Lions FC Dakar, domicile 2026', "Maillot domicile de la saison 2026.\nCoupe ajustée, tissu respirant, écusson brodé.", 89.99, 25, '#f4f2ec', '#f4f2ec', '#1f8a4c', null, '#1f8a4c', '10'],
        ['Lions FC Dakar, extérieur 2026', "Maillot extérieur de la saison 2026.\nVert profond, col en V contrasté.", 89.99, 18, '#1f8a4c', '#1f8a4c', '#e9b949', null, '#f4f2ec', '9'],
        ['Lions FC Dakar, third 2026', "Troisième maillot, édition nuit.\nNoir mat, finitions dorées.", 94.99, 10, '#1a1a1a', '#1a1a1a', '#e9b949', null, '#e9b949', '7'],
        ['Rouges du Port, rétro 1998', "Réédition du maillot domicile de 1998.\nRayures larges, col polo boutonné.", 119.00, 4, '#c8202f', '#c8202f', '#f4f2ec', '#f4f2ec', '#141414', '5'],
        ['Azur Olympique, rétro 1986', "Réédition du maillot de 1986.\nBleu azur, col rond blanc, coton épais.", 109.00, 2, '#2f6fd6', '#2f6fd6', '#f4f2ec', null, '#f4f2ec', '14'],
        ['Gardien 2026, manches longues', "Maillot de gardien, manches longues.\nCoudes renforcés, tissu déperlant.", 79.50, 8, '#f08a24', '#b85f12', '#141414', null, '#141414', '1'],
        ['Haut d\'entraînement 2026', "Haut d'entraînement léger.\nIdéal pour l'échauffement et les séances.", 49.00, 40, '#55585c', '#2b2d30', '#e9b949', null, '#f4f2ec', '23'],
        ['Lions FC Dakar, domicile enfant 2026', "Version enfant du maillot domicile 2026.\nTailles 6 à 14 ans.", 64.90, 0, '#f4f2ec', '#f4f2ec', '#1f8a4c', null, '#1f8a4c', '10'],
    ];

    public function __construct(
        private ProduitRepository $produitRepository,
        private Filesystem $filesystem,
        #[Autowire('%kernel.project_dir%/public/uploads/produits')]
        private string $dossierPhotos,
    ) {
    }

    /**
     * Crée les maillots manquants et leurs illustrations.
     */
    public function load(ObjectManager $manager): void
    {
        foreach (self::MAILLOTS as [$nom, $description, $prix, $stock, $corps, $manches, $bords, $rayures, $couleurNumero, $numero]) {
            // Déjà présent : on ne le recrée pas
            if (null !== $this->produitRepository->findOneBy(['nom' => $nom])) {
                continue;
            }

            $fichier = 'demo-'.substr(md5($nom), 0, 10).'.svg';
            $this->filesystem->dumpFile(
                $this->dossierPhotos.'/'.$fichier,
                $this->dessinerMaillot($corps, $manches, $bords, $rayures, $couleurNumero, $numero),
            );

            $manager->persist(
                (new Produit())
                    ->setNom($nom)
                    ->setDescription($description)
                    ->setPrix($prix)
                    ->setStock($stock)
                    ->setPhoto($fichier)
            );
        }

        $manager->flush();
    }

    /**
     * Illustration du dos d'un maillot (format 3:4, fond transparent), avec son numéro.
     */
    private function dessinerMaillot(string $corps, string $manches, string $bords, ?string $rayures, string $couleurNumero, string $numero): string
    {
        // Silhouette commune : le corps, puis les deux manches par-dessus
        $corpsChemin = 'M95 52 L122 40 Q150 58 178 40 L205 52 L215 120 L215 352 Q150 366 85 352 L85 120 Z';
        $mancheGauche = 'M95 52 L30 88 L52 156 L87 140 Z';
        $mancheDroite = 'M205 52 L270 88 L248 156 L213 140 Z';

        $bandes = '';
        if (null !== $rayures) {
            // Rayures verticales découpées à la forme du corps
            foreach ([100, 140, 180] as $x) {
                $bandes .= \sprintf('<rect x="%d" y="30" width="20" height="340" fill="%s"/>', $x, $rayures);
            }
        }

        return <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 400" width="600" height="800">
              <defs><clipPath id="corps"><path d="{$corpsChemin}"/></clipPath></defs>
              <path d="{$mancheGauche}" fill="{$manches}" stroke="{$bords}" stroke-width="4" stroke-linejoin="round"/>
              <path d="{$mancheDroite}" fill="{$manches}" stroke="{$bords}" stroke-width="4" stroke-linejoin="round"/>
              <path d="{$corpsChemin}" fill="{$corps}"/>
              <g clip-path="url(#corps)">{$bandes}</g>
              <path d="{$corpsChemin}" fill="none" stroke="{$bords}" stroke-width="4" stroke-linejoin="round"/>
              <path d="M122 40 Q150 58 178 40" fill="none" stroke="{$bords}" stroke-width="8" stroke-linecap="round"/>
              <text x="150" y="262" text-anchor="middle" font-family="Impact, 'Arial Narrow', 'Helvetica Neue', sans-serif" font-weight="900" font-size="128" fill="{$couleurNumero}">{$numero}</text>
            </svg>
            SVG;
    }
}
