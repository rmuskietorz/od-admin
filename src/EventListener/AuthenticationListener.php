<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\LoginAttempt;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Schreibt jeden Login-Versuch (erfolgreich oder nicht) in die login_attempt
 * Tabelle. Auswertbar im Admin-Panel + dient als Datenquelle fuer fail2ban
 * (via Log oder direkt aus der DB).
 */
final class AuthenticationListener
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsEventListener(event: LoginSuccessEvent::class)]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $request = $event->getRequest();
        $username = $event->getAuthenticatedToken()->getUserIdentifier();

        $this->persist(new LoginAttempt(
            username: $username,
            ip: $request->getClientIp() ?? '0.0.0.0',
            userAgent: $request->headers->get('User-Agent'),
            success: true,
        ));

        $this->logger->info('login.success', [
            'username' => $username,
            'ip'       => $request->getClientIp(),
        ]);
    }

    #[AsEventListener(event: LoginFailureEvent::class)]
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $request = $this->requestStack->getMainRequest();
        $username = $event->getPassport()?->getUser()?->getUserIdentifier()
            ?? (string) ($request?->request->get('_username') ?? 'unknown');
        $reason = $event->getException()->getMessageKey();

        $this->persist(new LoginAttempt(
            username: $username,
            ip: $request?->getClientIp() ?? '0.0.0.0',
            userAgent: $request?->headers->get('User-Agent'),
            success: false,
            reason: $reason,
        ));

        $this->logger->warning('login.failure', [
            'username' => $username,
            'ip'       => $request?->getClientIp(),
            'reason'   => $reason,
        ]);
    }

    private function persist(LoginAttempt $attempt): void
    {
        try {
            $this->em->persist($attempt);
            $this->em->flush();
        } catch (\Throwable $e) {
            // Audit-Log darf den Login-Flow niemals brechen.
            $this->logger->error('login.audit.persist_failed', [
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
