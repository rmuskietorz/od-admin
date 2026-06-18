<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Erzwingt den 2FA-Zwischenschritt: ein per Passwort eingeloggter Nutzer mit
 * aktivem TOTP kommt erst weiter, wenn die Session als '2fa_complete' markiert
 * ist. Bis dahin sind nur /2fa (Verify) und /logout erlaubt; das Auth-Gate
 * (/_auth_check) bleibt 401, damit auch Open Design gesperrt bleibt.
 */
final class TwoFactorListener implements EventSubscriberInterface
{
    public const SESSION_KEY = '2fa_complete';

    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Nach dem Firewall-Authenticator (Prioritaet 8) laufen.
        return [KernelEvents::REQUEST => ['onRequest', 7]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $event->hasResponse()) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User || !$user->isTwoFactorEnabled()) {
            return; // kein 2FA noetig
        }

        $request = $event->getRequest();
        if (!$request->hasSession()) {
            return;
        }
        if (true === $request->getSession()->get(self::SESSION_KEY)) {
            return; // 2FA bereits erledigt
        }

        $path = $request->getPathInfo();
        // Im Pending-Zustand NUR Verify + Logout — NICHT setup/disable (sonst
        // koennte man 2FA umgehen, ohne den Code einzugeben).
        if ('/2fa' === $path || '/logout' === $path) {
            return;
        }

        if ('/_auth_check' === $path) {
            $event->setResponse(new Response('', Response::HTTP_UNAUTHORIZED));

            return;
        }

        $event->setResponse(new RedirectResponse($this->urls->generate('app_2fa')));
    }
}
