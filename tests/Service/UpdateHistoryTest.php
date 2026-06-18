<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\UpdateHistory;
use PHPUnit\Framework\TestCase;

final class UpdateHistoryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/odtest_'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir.'/update-history.json');
        @rmdir($this->dir);
    }

    public function testRecordsOnlyOnDigestChange(): void
    {
        $h = new UpdateHistory($this->dir);
        self::assertSame([], $h->all());

        $h->record('sha256:aaa', 'img:1', '2026-06-18T10:00:00Z');
        self::assertCount(1, $h->all());

        // Gleicher Digest (z.B. reiner Restart) -> kein neuer Eintrag.
        $h->record('sha256:aaa', 'img:1', '2026-06-18T11:00:00Z');
        self::assertCount(1, $h->all());

        // Geaenderter Digest (echtes Update) -> neuer Eintrag, vorne.
        $h->record('sha256:bbb', 'img:2', '2026-06-18T12:00:00Z');
        $all = $h->all();
        self::assertCount(2, $all);
        self::assertSame('sha256:bbb', $all[0]['digest']);
        self::assertSame('2026-06-18T12:00:00Z', $all[0]['at']);
    }

    public function testEmptyDigestIgnored(): void
    {
        $h = new UpdateHistory($this->dir);
        $h->record('', 'img', null);
        self::assertSame([], $h->all());
    }
}
