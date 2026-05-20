<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\DockerClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route(path: '/admin/claude', name: 'app_claude_')]
final class ClaudeController extends AbstractController
{
    public function __construct(private readonly DockerClient $docker)
    {
    }

    #[Route(path: '/login', name: 'login', methods: ['GET'])]
    public function login(): Response
    {
        // Iframe-Embed gegen den ttyd-Sidecar. ttyd startet automatisch
        // 'claude setup-token || claude /login' im OD-Container.
        return $this->render('admin/claude_login.html.twig');
    }

    #[Route(path: '/status', name: 'status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        $proc = $this->docker->runInContainer([
            'sh', '-c',
            'test -f /home/open-design/.claude/.credentials.json && echo present || echo missing',
        ], timeoutSec: 5);
        $proc->run();
        $hasCreds = 'present' === trim($proc->getOutput());

        $verProc = $this->docker->runInContainer(['claude', '--version'], timeoutSec: 5);
        $verProc->run();

        return new JsonResponse([
            'has_credentials' => $hasCreds,
            'cli_version'     => trim($verProc->getOutput()),
        ]);
    }

    #[Route(path: '/test', name: 'test', methods: ['POST'])]
    public function test(): JsonResponse
    {
        $proc = $this->docker->runInContainer([
            'claude', '-p', 'antworte nur mit OK', '--output-format', 'text',
        ], timeoutSec: 30);
        $proc->run();

        return new JsonResponse([
            'ok'     => $proc->isSuccessful(),
            'output' => trim($proc->getOutput()),
            'error'  => $proc->isSuccessful() ? null : $proc->getErrorOutput(),
        ], $proc->isSuccessful() ? 200 : 500);
    }

    #[Route(path: '/logout', name: 'logout', methods: ['POST'])]
    public function logout(): JsonResponse
    {
        $proc = $this->docker->runInContainer([
            'rm', '-f', '/home/open-design/.claude/.credentials.json',
        ], timeoutSec: 5);
        $proc->run();

        return new JsonResponse(['ok' => $proc->isSuccessful()]);
    }
}
