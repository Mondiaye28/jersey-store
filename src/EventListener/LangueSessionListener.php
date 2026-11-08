<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Applique à la requête la langue choisie par l'utilisateur et gardée en session.
 *
 * Priorité 33 : avant le RouterListener (32). Ainsi même une page 404 levée pendant
 * le routage s'affiche dans la bonne langue. Le LocaleListener de Symfony (16) ne change
 * ensuite la langue que si la route définit un _locale, ce que nos routes ne font pas.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 33)]
final class LangueSessionListener
{
    public const CLE_SESSION = '_locale';

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        // Pas de session existante : on ne la démarre pas (visiteur sans choix de langue,
        // la langue par défaut s'applique)
        if (!$request->hasPreviousSession()) {
            return;
        }

        $locale = $request->getSession()->get(self::CLE_SESSION);
        if (\is_string($locale)) {
            $request->setLocale($locale);
        }
    }
}
