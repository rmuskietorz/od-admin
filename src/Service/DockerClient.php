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
