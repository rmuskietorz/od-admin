<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Setzt Security-Header auf die HTML-Antworten der Admin-App. Greift nur auf
 * od-admins eigene Seiten (Dashboard/Login/2FA/Audit) — der OD-Reverse-Proxy
 * laeuft in nginx, nicht durch Symfony, und bleibt unberuehrt.
 *
 * style-/script-src erlauben 'unsafe-inline', weil die Control-Plane-Seiten
 * bewusst Inline-CSS/-JS nutzen; der Gewinn liegt in object-src 'none',
 * base-uri/form-action/frame-ancestors 'self' und der Quell-Beschraenkung.
 */
final class SecurityHeadersListener implements EventSubscriberInterface
{
    private const CSP = "default-src 'self'; "
        ."object-src 'none'; base-uri 'self'; frame-ancestors 'self'; "
        ."img-src 'self' data:; style-src 'self' 'unsafe-inline'; "
        ."script-src 'self' 'unsafe-inline'; connect-src 'self'; form-action 'self'";

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onResponse'];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        $contentType = (string) $response->headers->get('Content-Type', '');
        if (!str_contains($contentType, 'text/html')) {
            return; // SSE, JSON, Auth-Check etc. unberuehrt
        }

        $headers = $response->headers;
        $headers->set('Content-Security-Policy', self::CSP);
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'same-origin');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
    }
}
