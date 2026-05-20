<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\DockerClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DockerClient::class)]
final class DockerClientTest extends TestCase
{
    public function testStatusReturnsNotFoundWhenEngineUnreachable(): void
    {
        // Unrealistischer Socket-Pfad -> curl-Subprozess scheitert -> null inspect
        $client = new DockerClient(
            containerName: 'open-design-claude',
            composeFile: '/tmp/no-such-compose.yml',
            dockerHost: 'unix:///tmp/this-socket-does-not-exist.sock',
        );

        $status = $client->status();

        self::assertSame('open-design-claude', $status['name']);
        self::assertFalse($status['running']);
        self::assertSame('not_found', $status['health']);
        self::assertSame('', $status['image']);
        self::assertNull($status['started']);
    }

    public function testRestartProcessConstructsCorrectCommand(): void
    {
        $client = new DockerClient(
            containerName: 'open-design-claude',
            composeFile: '/etc/od/compose.yml',
            dockerHost: 'unix:///var/run/docker.sock',
        );

        $proc = $client->restart();
        $cmdline = $proc->getCommandLine();

        // Symfony\Process escaped die Args; wir verifizieren nur dass der
        // Compose-File-Pfad und die richtige Aktion drin sind.
        self::assertStringContainsString('docker', $cmdline);
        self::assertStringContainsString('compose', $cmdline);
        self::assertStringContainsString('/etc/od/compose.yml', $cmdline);
        self::assertStringContainsString('restart', $cmdline);
        self::assertStringContainsString('open-design', $cmdline);
    }

    public function testUpdateImagePullsBeforeUp(): void
    {
        $client = new DockerClient(
            containerName: 'open-design-claude',
            composeFile: '/etc/od/compose.yml',
            dockerHost: 'unix:///var/run/docker.sock',
        );

        $pull = $client->updateImage();
        self::assertStringContainsString('pull', $pull->getCommandLine());

        $up = $client->up();
        self::assertStringContainsString('up', $up->getCommandLine());
        self::assertStringContainsString('-d', $up->getCommandLine());
    }

    public function testRunInContainerWrapsDockerExec(): void
    {
        $client = new DockerClient(
            containerName: 'open-design-claude',
            composeFile: '/tmp/c.yml',
            dockerHost: 'unix:///var/run/docker.sock',
        );

        $proc = $client->runInContainer(['claude', '--version']);
        $cmd = $proc->getCommandLine();

        self::assertStringContainsString('docker', $cmd);
        self::assertStringContainsString('exec', $cmd);
        self::assertStringContainsString('open-design-claude', $cmd);
        self::assertStringContainsString('claude', $cmd);
        self::assertStringContainsString('--version', $cmd);
    }

    public function testLogsFollowConstructsLogsCommand(): void
    {
        $client = new DockerClient(
            containerName: 'open-design-claude',
            composeFile: '/tmp/c.yml',
            dockerHost: 'unix:///var/run/docker.sock',
        );

        $proc = $client->logsFollow(50);
        $cmd = $proc->getCommandLine();

        self::assertStringContainsString('docker', $cmd);
        self::assertStringContainsString('logs', $cmd);
        self::assertStringContainsString('--follow', $cmd);
        self::assertStringContainsString('50', $cmd);
    }

    public function testDockerHostPropagatesToProcessEnv(): void
    {
        $client = new DockerClient(
            containerName: 'open-design-claude',
            composeFile: '/tmp/c.yml',
            dockerHost: 'tcp://docker-socket-proxy:2375',
        );

        $proc = $client->restart();
        $env = $proc->getEnv();

        self::assertArrayHasKey('DOCKER_HOST', $env);
        self::assertSame('tcp://docker-socket-proxy:2375', $env['DOCKER_HOST']);
    }
}
