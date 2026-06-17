<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Beim Logout das REMEMBERME-Cookie an MEHREREN Pfaden loeschen.
 *
 * Symfony loescht das Cookie nur am aktuell konfigurierten Pfad. Durch einen
 * frueheren Pfadwechsel (/admin -> /) koennen zwei REMEMBERME-Cookies parallel
 * existieren; Symfony raeumt dann nur eins weg und das andere meldet den Nutzer
 * sofort wieder an ("Logout funktioniert nicht"). Hier loeschen wir beide.
 */
final class ClearRememberMeSubscriber implements EventSubscriberInterface
{
    /** @var list<string> */
    private const PATHS = ['/', '/admin'];

    public static function getSubscribedEvents(): array
    {
        return [LogoutEvent::class => 'onLogout'];
    }

    public function onLogout(LogoutEvent $event): void
    {
        $response = $event->getResponse();
        if (null === $response) {
            return;
        }

        foreach (self::PATHS as $path) {
            $response->headers->clearCookie('REMEMBERME', $path);
        }
    }
}
