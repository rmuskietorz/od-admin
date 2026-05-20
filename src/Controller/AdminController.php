<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\LoginAttemptRepository;
use App\Service\DockerClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class AdminController extends AbstractController
{
    public function __construct(
        private readonly DockerClient $docker,
        private readonly LoginAttemptRepository $loginAttempts,
    ) {
    }

    #[Route(path: '/admin/audit', name: 'app_audit', methods: ['GET'])]
    public function audit(): Response
    {
        return $this->render('admin/audit.html.twig', [
            'attempts' => $this->loginAttempts->findRecent(100),
        ]);
    }

    #[Route(path: '/admin', name: 'app_dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        return $this->render('admin/dashboard.html.twig', [
            'status' => $this->docker->status(),
        ]);
    }

    #[Route(path: '/admin/status', name: 'app_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        return new JsonResponse($this->docker->status());
    }

    #[Route(path: '/admin/restart', name: 'app_restart', methods: ['POST'])]
    public function restart(): JsonResponse
    {
        $proc = $this->docker->restart();
        $proc->run();

        return new JsonResponse([
            'ok'     => $proc->isSuccessful(),
            'stdout' => $proc->getOutput(),
            'stderr' => $proc->getErrorOutput(),
        ], $proc->isSuccessful() ? 200 : 500);
    }

    #[Route(path: '/admin/update', name: 'app_update', methods: ['POST'])]
    public function update(): JsonResponse
    {
        $pull = $this->docker->updateImage();
        $pull->run();
        if (!$pull->isSuccessful()) {
            return new JsonResponse([
                'ok'    => false,
                'phase' => 'pull',
                'error' => $pull->getErrorOutput(),
            ], 500);
        }

        $up = $this->docker->up();
        $up->run();

        return new JsonResponse([
            'ok'         => $up->isSuccessful(),
            'pull_out'   => $pull->getOutput(),
            'up_out'     => $up->getOutput(),
            'error'      => $up->isSuccessful() ? null : $up->getErrorOutput(),
        ], $up->isSuccessful() ? 200 : 500);
    }

    #[Route(path: '/admin/logs', name: 'app_logs', methods: ['GET'])]
    public function logs(): StreamedResponse
    {
        $proc = $this->docker->logsFollow(200);

        $response = new StreamedResponse(function () use ($proc): void {
            $proc->start();
            foreach ($proc as $type => $data) {
                if (!is_string($data) || '' === $data) {
                    continue;
                }
                foreach (preg_split('/\R/', rtrim($data)) ?: [] as $line) {
                    echo 'data: '.str_replace("\n", '\\n', $line)."\n\n";
                }
                if (function_exists('ob_flush')) {
                    @ob_flush();
                }
                flush();
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }
}
