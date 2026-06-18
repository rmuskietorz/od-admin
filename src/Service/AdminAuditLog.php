<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Sichtbares Audit fuer Admin-Aktionen (Restart/Update/Token/2FA). Datei in
 * var/data (eigenes Volume, ueberlebt Rebuilds) — kein Monolog noetig. Wird auf
 * der Audit-Seite neben den Login-Versuchen angezeigt.
 *
 * @phpstan-type Entry array{at:string,user:string,action:string}
 */
final class AdminAuditLog
{
    private const MAX = 200;

    private readonly string $file;

    public function __construct(#[Autowire('%kernel.project_dir%/var/data')] string $dataDir)
    {
        $this->file = $dataDir.'/admin-audit.json';
    }

    public function log(string $user, string $action): void
    {
        $entries = $this->all();
        array_unshift($entries, [
            'at'     => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'user'   => '' !== $user ? $user : 'unbekannt',
            'action' => $action,
        ]);
        $entries = \array_slice($entries, 0, self::MAX);

        @file_put_contents($this->file, json_encode($entries, \JSON_PRETTY_PRINT), \LOCK_EX);
    }

    /**
     * @return list<Entry>
     */
    public function all(): array
    {
        if (!is_file($this->file)) {
            return [];
        }
        $raw = @file_get_contents($this->file);
        if (false === $raw || '' === $raw) {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }

        $out = [];
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'at'     => isset($row['at']) && is_string($row['at']) ? $row['at'] : '',
                'user'   => isset($row['user']) && is_string($row['user']) ? $row['user'] : '',
                'action' => isset($row['action']) && is_string($row['action']) ? $row['action'] : '',
            ];
        }

        return $out;
    }

    /**
     * @return list<Entry>
     */
    public function recent(int $count = 50): array
    {
        return \array_slice($this->all(), 0, $count);
    }
}
