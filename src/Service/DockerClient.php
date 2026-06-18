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
     * @return array{name:string,running:bool,health:string,image:string,started:string|null,digest:string}
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
                'digest'  => '',
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
            // Image-ID (sha256) — aendert sich bei jedem echten Image-Update,
            // nicht bei einem reinen Restart. Basis fuer den Update-Verlauf.
            'digest'  => is_string($data['Image'] ?? null) ? (string) $data['Image'] : '',
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

        // Wichtig: die FIFO read-write (<>) offen halten statt mit einem
        // Hintergrund-`sleep` — letzteres laesst eine Pipe offen, an der
        // Symfony\Process haengt -> run() blockiert bis Timeout -> 500.
        // Alle Deskriptoren des detached Prozesses gehen nach /dev/null, damit
        // der startende Request sofort zurueckkehrt.
        $boot = sprintf(
            'rm -f %1$s %2$s; mkfifo %2$s; '
            .'setsid script -qfc '
            // Sehr breites PTY, damit die lange OAuth-URL NICHT umbricht.
            .'"stty cols 1000 rows 50 2>/dev/null; '
            .'docker exec -it -u open-design %3$s claude setup-token" '
            .'%1$s <> %2$s > /dev/null 2>&1 &',
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
        // Code und Enter GETRENNT senden: claude (Ink-TUI, Raw-TTY) behandelt
        // ein "code\r" in einem Rutsch als reinen Paste-Text, das \r wird Teil
        // des Werts statt Enter. Erst den Code schreiben, kurz warten, dann \r
        // als eigenes Ereignis -> wird als Enter erkannt und der Code gesendet.
        $write = new Process(
            ['sh', '-c', sprintf('cat >> %s', self::TOKEN_IN)],
            env: $this->processEnv(),
        );
        $write->setTimeout(5.0);
        $write->setInput(trim($code));
        $write->run();

        usleep(400_000);

        $enter = new Process(
            ['sh', '-c', sprintf('printf "\\r" >> %s', self::TOKEN_IN)],
            env: $this->processEnv(),
        );
        $enter->setTimeout(5.0);
        $enter->run();
    }

    public function stopTokenLogin(): void
    {
        // `pkill -x script` trifft NUR den PTY-Wrapper (Prozessname "script"),
        // nicht die ausfuehrende sh. Ein `pkill -f "...setup-token"` wuerde die
        // eigene Wrapper-Shell mittreffen (Pattern steht in deren Cmdline) und
        // sie per SIGTERM killen -> ProcessSignaledException -> 500.
        $proc = new Process(
            ['sh', '-c', sprintf('pkill -x script 2>/dev/null; rm -f %s %s; true', self::TOKEN_OUT, self::TOKEN_IN)],
            env: $this->processEnv(),
        );
        $proc->setTimeout(5.0);
        $proc->run();

        // Eine evtl. im OD-Container haengende Session ebenfalls beenden (pkill
        // laeuft hier direkt, ohne sh-Wrapper -> trifft sich nicht selbst).
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

    private function deployDir(): string
    {
        return \dirname($this->composeFile);
    }

    private function envFilePath(): string
    {
        return $this->deployDir().'/.env.claude-cli';
    }

    // Token-Datei in od-admins EIGENEM, beschreibbarem Volume (var/data gehoert
    // uid 1000). Das OD-Deploy-Verzeichnis gehoert root und ist :ro gemountet.
    private const TOKEN_ENV = '/var/www/html/var/data/od_oauth.env';

    /**
     * @param list<string> $args
     */
    private function runCompose(array $args): Process
    {
        $cmd = ['docker', 'compose', '--file', $this->composeFile];
        // Mehrere --env-file: Basis-Config aus .env.claude-cli, der OAuth-Token
        // aus od_oauth.env (spaeter = hat Vorrang). Sonst blieben ${...}-Vars
        // (Image, Token) beim up/pull leer.
        foreach ([$this->envFilePath(), self::TOKEN_ENV] as $envFile) {
            if (is_file($envFile)) {
                $cmd[] = '--env-file';
                $cmd[] = $envFile;
            }
        }
        $cmd = [...$cmd, ...$args];

        return $this->runProcess($cmd, 300);
    }

    // ── Subscription-OAuth-Token (aus `claude setup-token`) ──────────────────

    /** Token aus der setup-token-Ausgabe ziehen (Format sk-ant-oat01-...). */
    public function extractOauthToken(string $raw): ?string
    {
        if (preg_match('/sk-ant-oat01-[A-Za-z0-9_-]+/', $raw, $m)) {
            return $m[0];
        }

        return null;
    }

    /** True, wenn der OD-Container CLAUDE_CODE_OAUTH_TOKEN gesetzt hat. */
    public function hasOauthToken(): bool
    {
        $proc = $this->runInContainer(['printenv', 'CLAUDE_CODE_OAUTH_TOKEN'], timeoutSec: 5);
        $proc->run();

        return '' !== trim($proc->getOutput());
    }

    /**
     * Wann der OAuth-Token zuletzt gesetzt wurde (= mtime der Token-Datei).
     * Basis fuer die Ablauf-Anzeige; null, wenn keine Token-Datei existiert.
     */
    public function tokenSetAt(): ?\DateTimeImmutable
    {
        if (!is_file(self::TOKEN_ENV)) {
            return null;
        }
        $mtime = @filemtime(self::TOKEN_ENV);
        if (false === $mtime) {
            return null;
        }

        return (new \DateTimeImmutable())->setTimestamp($mtime);
    }

    /**
     * Token in .env.claude-cli upserten und OD-Container neu erzeugen, damit
     * CLAUDE_CODE_OAUTH_TOKEN in dessen Env landet. $token ist auf
     * sk-ant-oat01-[A-Za-z0-9_-]+ validiert -> keine Shell-Sonderzeichen.
     */
    public function persistOauthToken(string $token): Process
    {
        $this->writeEnvToken($token);

        return $this->up();
    }

    /** Token aus .env.claude-cli entfernen und OD-Container neu erzeugen. */
    public function clearOauthToken(): Process
    {
        $this->writeEnvToken(null);

        return $this->up();
    }

    private function writeEnvToken(?string $token): void
    {
        if (null === $token) {
            $script = sprintf('rm -f %s', self::TOKEN_ENV);
        } else {
            // $token ist auf sk-ant-oat01-[A-Za-z0-9_-]+ validiert -> safe.
            $script = sprintf('printf "CLAUDE_CODE_OAUTH_TOKEN=%%s\n" "%s" > %s', $token, self::TOKEN_ENV);
        }

        $proc = new Process(['sh', '-c', $script], env: $this->processEnv());
        $proc->setTimeout(10.0);
        $proc->mustRun();
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
