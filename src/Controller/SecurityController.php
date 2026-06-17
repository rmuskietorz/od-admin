<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    #[Route(path: '/admin/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $auth): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('security/login.html.twig', [
            'last_username' => $auth->getLastUsername(),
            'error'         => $auth->getLastAuthenticationError(),
        ]);
    }

    #[Route(path: '/admin/logout', name: 'app_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('Wird von Symfony Security abgefangen.');
    }

    /**
     * Nginx auth_request endpoint. Liefert 200 wenn eingeloggt, 401 sonst.
     * Wird vor jedem Request gegen / und /admin/ttyd/ aufgerufen.
     *
     * Prueft IS_AUTHENTICATED_REMEMBERED (eingeloggt inkl. remember-me),
     * NICHT _FULLY: die 'admin'-Firewall schuetzt /admin nur mit ROLE_USER,
     * und nach Container-Neustarts (Session liegt im tmpfs) bleibt der Nutzer
     * via remember-me angemeldet = REMEMBERED, aber nicht FULLY. Mit _FULLY
     * sperrt das Gate solche Nutzer faelschlich aus, obwohl das Admin-Panel
     * sie akzeptiert -> OD-Proxy und ttyd zeigen das Login/Dashboard statt
     * des Inhalts.
     */
    #[Route(path: '/_auth_check', name: 'app_auth_check', methods: ['GET'])]
    public function authCheck(): Response
    {
        return $this->isGranted('IS_AUTHENTICATED_REMEMBERED')
            ? new Response('', Response::HTTP_OK)
            : new Response('', Response::HTTP_UNAUTHORIZED);
    }
}
