<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\DockerClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route(path: '/claude', name: 'app_claude_')]
final class ClaudeController extends AbstractController
{
    public function __construct(private readonly DockerClient $docker)
    {
    }

    #[Route(path: '/login', name: 'login', methods: ['GET'])]
    public function login(): Response
    {
        return $this->render('admin/claude_login.html.twig');
    }

    #[Route(path: '/status', name: 'status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        $verProc = $this->docker->runInContainer(['claude', '--version'], timeoutSec: 5);
        $verProc->run();

        return new JsonResponse([
            'has_credentials' => $this->docker->hasOauthToken(),
            'cli_version'     => trim($verProc->getOutput()),
        ]);
    }

    // ── Button-Login-Flow ────────────────────────────────────────────────────

    /**
     * Startet `claude setup-token` im OD-Container ueber ein PTY. Liefert sofort
     * zurueck; das Frontend pollt /login/poll auf die OAuth-URL.
     */
    #[Route(path: '/login/start', name: 'login_start', methods: ['POST'])]
    public function loginStart(): JsonResponse
    {
        if ($this->docker->hasOauthToken()) {
            return new JsonResponse(['status' => 'already', 'has_credentials' => true]);
        }

        $this->docker->startTokenLogin();

        return new JsonResponse(['status' => 'started']);
    }

    /**
     * Pollt: liefert die OAuth-URL (sobald sichtbar) und ob der setup-token
     * bereits einen Token erzeugt hat (nach Code-Eingabe).
     */
    #[Route(path: '/login/poll', name: 'login_poll', methods: ['GET'])]
    public function loginPoll(): JsonResponse
    {
        $out = $this->docker->readTokenLoginOutput();

        return new JsonResponse([
            'url'         => $this->extractUrl($out),
            'token_ready' => null !== $this->docker->extractOauthToken($out),
        ]);
    }

    /** Schreibt den vom Nutzer eingefuegten Code in den laufenden setup-token. */
    #[Route(path: '/login/submit', name: 'login_submit', methods: ['POST'])]
    public function loginSubmit(Request $request): JsonResponse
    {
        /** @var array<string,mixed> $payload */
        $payload = $request->toArray();
        $code = trim((string) ($payload['code'] ?? ''));

        if ('' === $code) {
            return new JsonResponse(['ok' => false, 'error' => 'Kein Code angegeben.'], 400);
        }

        $this->docker->submitTokenCode($code);

        return new JsonResponse(['ok' => true]);
    }

    /**
     * Schliesst den Login ab: Token aus der Ausgabe ziehen, in .env.claude-cli
     * schreiben und den OD-Container neu erzeugen, damit er greift.
     */
    #[Route(path: '/login/finalize', name: 'login_finalize', methods: ['POST'])]
    public function loginFinalize(): JsonResponse
    {
        $token = $this->docker->extractOauthToken($this->docker->readTokenLoginOutput());

        if (null === $token) {
            $tail = mb_substr($this->cleanAnsi($this->docker->readTokenLoginOutput()), -400);

            return new JsonResponse(['ok' => false, 'error' => 'Kein Token erkannt.', 'output' => $tail], 200);
        }

        $proc = $this->docker->persistOauthToken($token);
        $proc->run();
        $this->docker->stopTokenLogin();

        return new JsonResponse([
            'ok'              => $proc->isSuccessful(),
            'has_credentials' => $this->docker->hasOauthToken(),
            'error'           => $proc->isSuccessful() ? null : trim($proc->getErrorOutput()),
        ]);
    }

    #[Route(path: '/login/cancel', name: 'login_cancel', methods: ['POST'])]
    public function loginCancel(): JsonResponse
    {
        $this->docker->stopTokenLogin();

        return new JsonResponse(['ok' => true]);
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
        $this->docker->stopTokenLogin();
        $proc = $this->docker->clearOauthToken();
        $proc->run();

        return new JsonResponse(['ok' => $proc->isSuccessful()]);
    }

    private function cleanAnsi(string $raw): string
    {
        $clean = preg_replace('/\x1b\[[0-9;?]*[ -\/]*[@-~]/', '', $raw) ?? $raw;
        $clean = preg_replace('/\x1b[\]P].*?(?:\x07|\x1b\\\\)/s', '', $clean) ?? $clean;

        return str_replace("\r", '', $clean);
    }

    private function extractUrl(string $raw): ?string
    {
        $clean = $this->cleanAnsi($raw);
        // Hart umgebrochene Zeilen (PTY-Zeilenumbruch mitten in der URL) wieder
        // zusammenfuegen: ein \n zwischen zwei Nicht-Whitespace-Zeichen entfernen.
        $clean = preg_replace('/([^\s])\n(?=[^\s])/', '$1', $clean) ?? $clean;

        if (preg_match('#https?://[^\s"\'<>\x00-\x1f]+#', $clean, $m)) {
            return rtrim($m[0], '.,)');
        }

        return null;
    }
}
