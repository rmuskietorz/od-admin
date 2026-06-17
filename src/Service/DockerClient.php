<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;

/**
 * Schmaler Wrapper um den Docker-Daemon.
 * Nutzt ausschliesslich Symfony\Process mit Array-Args (kein Shell, kein
 * String-Interpolation), damit keine Command-Injection moeglich ist.
 *
 * Spricht je nach DOCKER_HOST:
 *   - unix:///var/run/docker.sock  -> direkt ueber gemounteten Socket
 *   - tcp://docker-socket-proxy    -> ueber Socket-Proxy (Stufe 2)
 */
final class DockerClient
{
    public function __construct(
        private readonly string $containerName,
        private readonly string $composeFile,
        private readonly string $dockerHost,
    ) {
    }

    /**
     * Container-Inspect via Docker Engine API.
     *
     * @return array<string,mixed>|null
     */
    public function inspectContainer(): ?array
    {
        $endpoint = sprintf('/containers/%s/json', rawurlencode($this->containerName));
        $response = $this->httpRequest('GET', $endpoint);

        if (null === $response || 200 !== $response['status']) {
            return null;
        }

        return $response['body'];
    }

    /**
     * Health-Status des Containers. Fertiges Dashboard-Dict.
     *
     * @return array{name:string,running:bool,health:string,image:string,started:string|null}
     */
    public function status(): array
    {
        $data = $this->inspectContainer();
        if (null === $data) {
            return [
                'name'    => $this->containerName,
                'running' => false,
                'health'  => 'not_found',
                'image'   => '',
                'started' => null,
            ];
        }

        $state = is_array($data['State'] ?? null) ? $data['State'] : [];
        $config = is_array($data['Config'] ?? null) ? $data['Config'] : [];

        $health = 'unknown';
        if (isset($state['Health']['Status']) && is_string($state['Health']['Status'])) {
            $health = $state['Health']['Status'];
        } elseif (isset($state['Status']) && is_string($state['Status'])) {
            $health = $state['Status'];
        }

        return [
            'name'    => is_string($data['Name'] ?? null) ? (string) $data['Name'] : $this->containerName,
            'running' => (bool) ($state['Running'] ?? false),
            'health'  => $health,
            'image'   => is_string($config['Image'] ?? null) ? (string) $config['Image'] : '',
            'started' => isset($state['StartedAt']) && is_string($state['StartedAt']) ? $state['StartedAt'] : null,
        ];
    }

    /**
     * Compose-Restart des OD-Containers.
     */
    public function restart(): Process
    {
        return $this->runCompose(['restart', 'open-design']);
    }

    /**
     * Pull-and-up: holt neueste Image-Version, ersetzt OD-Container.
     */
    public function updateImage(): Process
    {
        // Sequenzielles Pull + Up als zwei Process-Calls vom Caller.
        // Hier liefern wir den Pull-Process; AdminController kettet danach up.
        return $this->runCompose(['pull', 'open-design']);
    }

    public function up(): Process
    {
        return $this->runCompose(['up', '-d', 'open-design']);
    }

    /**
     * Direktes Kommando im OD-Container (z.B. `claude -p 'OK'`).
     *
     * @param list<string> $cmd
     */
    public function runInContainer(array $cmd, ?int $timeoutSec = 30): Process
    {
        $full = ['docker', 'exec', '-u', 'open-design', $this->containerName, ...$cmd];

        return $this->runProcess($full, $timeoutSec);
    }

    // ── Token-Login-Bridge (Button-Flow) ────────────────────────────────────
    // `claude setup-token` braucht ein TTY. od-admin oeffnet via `script` ein
    // Pseudo-Terminal, faehrt darin `docker exec -it ... claude setup-token`,
    // loggt die Ausgabe (inkl. OAuth-URL) in TOKEN_OUT und nimmt den Code ueber
    // die FIFO TOKEN_IN entgegen. Der Prozess laeuft detached (setsid) und
    // ueberlebt damit den HTTP-Request, der ihn startet.
    private const TOKEN_OUT = '/tmp/od_token_login.out';
    private const TOKEN_IN  = '/tmp/od_token_login.in';

    public function startTokenLogin(): void
    {
        $this->stopTokenLogin();

        $boot = sprintf(
            'rm -f %1$s %2$s; mkfifo %2$s; ( sleep 1800 > %2$s & ); '
            .'setsid script -qfc '
            // Sehr breites PTY, damit die lange OAuth-URL NICHT umbricht.
            .'"stty cols 1000 rows 50 2>/dev/null; '
            .'docker exec -it -u open-design %3$s claude setup-token" '
            .'%1$s < %2$s > /dev/null 2>&1 &',
            self::TOKEN_OUT,
            self::TOKEN_IN,
            $this->containerName,
        );

        $proc = new Process(['sh', '-c', $boot], env: $this->processEnv());
        $proc->setTimeout(15.0);
        $proc->run();
    }

    public function readTokenLoginOutput(): string
    {
        $proc = new Process(
            ['sh', '-c', sprintf('cat %s 2>/dev/null || true', self::TOKEN_OUT)],
            env: $this->processEnv(),
        );
        $proc->setTimeout(5.0);
        $proc->run();

        return $proc->getOutput();
    }

    public function submitTokenCode(string $code): void
    {
        // Code via stdin der FIFO uebergeben – keine Shell-Interpolation.
        $proc = new Process(
            ['sh', '-c', sprintf('cat >> %s', self::TOKEN_IN)],
            env: $this->processEnv(),
        );
        $proc->setTimeout(5.0);
        $proc->setInput(trim($code)."\n");
        $proc->run();
    }

    public function stopTokenLogin(): void
    {
        $proc = new Process(
            ['sh', '-c', sprintf('pkill -f "claude setup-token" 2>/dev/null; rm -f %s %s; true', self::TOKEN_OUT, self::TOKEN_IN)],
            env: $this->processEnv(),
        );
        $proc->setTimeout(5.0);
        $proc->run();

        // Eine evtl. im OD-Container haengende Session ebenfalls beenden.
        $this->runInContainer(['pkill', '-f', 'setup-token'], timeoutSec: 5)->run();
    }

    /**
     * Logs-Tail als Process (callable mit Output-Callback fuer SSE).
     */
    public function logsFollow(int $tail = 200): Process
    {
        return $this->runProcess([
            'docker', 'logs',
            '--tail', (string) $tail,
            '--follow',
            '--timestamps',
            $this->containerName,
        ], null);
    }

    /**
     * @param list<string> $args
     */
    private function runCompose(array $args): Process
    {
        $cmd = ['docker', 'compose', '--file', $this->composeFile, ...$args];

        return $this->runProcess($cmd, 300);
    }

    /**
     * @param list<string> $cmd
     */
    private function runProcess(array $cmd, ?int $timeoutSec): Process
    {
        $proc = new Process($cmd, env: $this->processEnv());
        $proc->setTimeout(null === $timeoutSec ? null : (float) $timeoutSec);

        return $proc;
    }

    /**
     * @return array<string,string>
     */
    private function processEnv(): array
    {
        return ['DOCKER_HOST' => $this->dockerHost];
    }

    /**
     * HTTP-Request gegen die Docker-Engine.
     *
     * @return array{status:int,body:array<string,mixed>}|null
     */
    private function httpRequest(string $method, string $path): ?array
    {
        if (str_starts_with($this->dockerHost, 'unix://')) {
            return $this->httpRequestUnix($method, $path);
        }

        $client = HttpClient::create();
        $url = rtrim(str_replace('tcp://', 'http://', $this->dockerHost), '/').$path;

        try {
            $resp = $client->request($method, $url, ['timeout' => 10]);
            $status = $resp->getStatusCode();
            $body = $resp->toArray(false);
        } catch (\Throwable) {
            return null;
        }

        return ['status' => $status, 'body' => $body];
    }

    /**
     * @return array{status:int,body:array<string,mixed>}|null
     */
    private function httpRequestUnix(string $method, string $path): ?array
    {
        $socketPath = substr($this->dockerHost, 7);
        $proc = new Process([
            'curl',
            '--silent',
            '--unix-socket', $socketPath,
            '--write-out', "\n%{http_code}",
            '-X', $method,
            'http://localhost'.$path,
        ]);
        $proc->setTimeout(10.0);
        $proc->run();

        if (!$proc->isSuccessful()) {
            return null;
        }

        $out = trim($proc->getOutput());
        $nl = strrpos($out, "\n");
        if (false === $nl) {
            return null;
        }

        $body = substr($out, 0, $nl);
        $status = (int) substr($out, $nl + 1);
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            return null;
        }

        /** @var array<string,mixed> $decoded */
        return ['status' => $status, 'body' => $decoded];
    }
}
