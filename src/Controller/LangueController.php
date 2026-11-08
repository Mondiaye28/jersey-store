<?php

namespace App\Controller;

use App\EventListener\LangueSessionListener;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Changement de langue du site : la langue choisie est gardée en session
 * et appliquée à chaque requête par LangueSessionListener.
 */
final class LangueController extends AbstractController
{
    // Seules les langues proposées sont acceptées par la route : /langue/de renvoie une 404
    #[Route('/langue/{locale}', name: 'app_langue', requirements: ['locale' => 'fr|en'], methods: ['GET'])]
    public function changer(Request $request, string $locale): RedirectResponse
    {
        $request->getSession()->set(LangueSessionListener::CLE_SESSION, $locale);

        return $this->redirect($this->pagePrecedente($request));
    }

    /**
     * Page d'où vient l'utilisateur (en-tête Referer), ou l'accueil.
     * Seul le chemin est conservé et le Referer doit venir du même site :
     * on ne redirige jamais vers un site externe (pas de redirection ouverte).
     */
    private function pagePrecedente(Request $request): string
    {
        $referer = $request->headers->get('referer');
        $accueil = $this->generateUrl('app_home');
        if (null === $referer) {
            return $accueil;
        }

        $parties = parse_url($referer);
        if (false === $parties || ($parties['host'] ?? null) !== $request->getHost() || !isset($parties['path'])) {
            return $accueil;
        }

        // Seul un chemin relatif simple (« /... ») est accepté : « //site » ou « /\site »
        // serait compris par le navigateur comme une adresse vers un autre site
        $chemin = $parties['path'];
        if (!str_starts_with($chemin, '/') || str_starts_with($chemin, '//') || str_contains($chemin, '\\')) {
            return $accueil;
        }

        return $chemin.(isset($parties['query']) ? '?'.$parties['query'] : '');
    }
}
