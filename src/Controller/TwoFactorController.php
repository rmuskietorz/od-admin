<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Security\TwoFactorListener;
use App\Security\TwoFactorService;
use App\Service\AdminAuditLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class TwoFactorController extends AbstractController
{
    public function __construct(
        private readonly TwoFactorService $tfa,
        private readonly EntityManagerInterface $em,
        private readonly AdminAuditLog $audit,
    ) {
    }

    /** Login-Zwischenschritt: TOTP- oder Recovery-Code eingeben. */
    #[Route(path: '/2fa', name: 'app_2fa', methods: ['GET', 'POST'])]
    public function verify(Request $request): Response
    {
        $user = $this->user();
        if (!$user->isTwoFactorEnabled()) {
            return $this->redirectToRoute('app_dashboard');
        }
        if (true === $request->getSession()->get(TwoFactorListener::SESSION_KEY)) {
            return $this->redirectToRoute('app_dashboard');
        }

        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('2fa', (string) $request->request->get('_csrf_token'))) {
                $error = 'Ungueltiges Formular. Bitte erneut.';
            } else {
                $code = (string) $request->request->get('code', '');
                if ($this->tfa->verify((string) $user->getTotpSecret(), $code)) {
                    $request->getSession()->set(TwoFactorListener::SESSION_KEY, true);

                    return $this->redirectToRoute('app_dashboard');
                }
                // Fallback: Recovery-Code (einmalig).
                $rest = $this->tfa->consumeRecoveryCode($user->getRecoveryCodes(), $code);
                if (null !== $rest) {
                    $user->setRecoveryCodes($rest);
                    $this->em->flush();
                    $request->getSession()->set(TwoFactorListener::SESSION_KEY, true);

                    return $this->redirectToRoute('app_dashboard');
                }
                $error = 'Code ungueltig.';
            }
        }

        return $this->render('security/2fa.html.twig', ['error' => $error]);
    }

    /** Enrollment: Secret erzeugen, QR zeigen, Code bestaetigen. */
    #[Route(path: '/2fa/setup', name: 'app_2fa_setup', methods: ['GET', 'POST'])]
    public function setup(Request $request): Response
    {
        $user = $this->user();
        if ($user->isTwoFactorEnabled()) {
            $this->addFlash('info', '2FA ist bereits aktiv.');

            return $this->redirectToRoute('app_dashboard');
        }

        $session = $request->getSession();
        $secret = (string) $session->get('2fa_setup_secret', '');
        if ('' === $secret) {
            $secret = $this->tfa->generateSecret();
            $session->set('2fa_setup_secret', $secret);
        }

        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('2fa_setup', (string) $request->request->get('_csrf_token'))) {
                $error = 'Ungueltiges Formular. Bitte erneut.';
            } elseif ($this->tfa->verify($secret, (string) $request->request->get('code', ''))) {
                $plainCodes = $this->tfa->generateRecoveryCodes();
                $user->setTotpSecret($secret);
                $user->setRecoveryCodes($this->tfa->hashRecoveryCodes($plainCodes));
                $this->em->flush();
                $session->remove('2fa_setup_secret');
                $session->set(TwoFactorListener::SESSION_KEY, true);
                $this->audit->log($user->getUserIdentifier(), '2FA aktiviert');

                return $this->render('admin/2fa_codes.html.twig', ['codes' => $plainCodes]);
            } else {
                $error = 'Code stimmt nicht — App-Zeit korrekt? Nochmal versuchen.';
            }
        }

        return $this->render('admin/2fa_setup.html.twig', [
            'secret'  => $secret,
            'qr'      => $this->tfa->qrDataUri($secret, $user->getUserIdentifier()),
            'error'   => $error,
        ]);
    }

    /** 2FA deaktivieren — verlangt einen gueltigen aktuellen Code. */
    #[Route(path: '/2fa/disable', name: 'app_2fa_disable', methods: ['POST'])]
    public function disable(Request $request): Response
    {
        $user = $this->user();
        if (!$this->isCsrfTokenValid('2fa_disable', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Ungueltiges Formular.');

            return $this->redirectToRoute('app_dashboard');
        }
        if (!$user->isTwoFactorEnabled()
            || !$this->tfa->verify((string) $user->getTotpSecret(), (string) $request->request->get('code', ''))) {
            $this->addFlash('error', '2FA NICHT deaktiviert: Code fehlt/ungueltig.');

            return $this->redirectToRoute('app_dashboard');
        }

        $user->setTotpSecret(null);
        $user->setRecoveryCodes([]);
        $this->em->flush();
        $this->audit->log($user->getUserIdentifier(), '2FA deaktiviert');
        $this->addFlash('info', '2FA wurde deaktiviert.');

        return $this->redirectToRoute('app_dashboard');
    }

    private function user(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
