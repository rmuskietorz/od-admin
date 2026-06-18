<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Persistenter Update-Verlauf des OD-Containers. Schreibt einen Eintrag, sobald
 * sich die Image-ID (Digest) aendert — egal ob via Watchtower, Dashboard oder
 * helper. Liegt in var/data (eigenes Volume) und ueberlebt Rebuilds.
 *
 * @phpstan-type Entry array{at:string,image:string,digest:string}
 */
final class UpdateHistory
{
    private const MAX = 15;

    private readonly string $file;

    public function __construct(#[Autowire('%kernel.project_dir%/var/data')] string $dataDir)
    {
        $this->file = $dataDir.'/update-history.json';
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
                'image'  => isset($row['image']) && is_string($row['image']) ? $row['image'] : '',
                'digest' => isset($row['digest']) && is_string($row['digest']) ? $row['digest'] : '',
            ];
        }

        return $out;
    }

    /**
     * Neuen Eintrag voranstellen, wenn sich der Digest geaendert hat.
     */
    public function record(string $digest, string $image, ?string $startedAt): void
    {
        if ('' === $digest) {
            return;
        }

        $history = $this->all();
        if (isset($history[0]) && $history[0]['digest'] === $digest) {
            return; // unveraendert -> kein neuer Eintrag
        }

        array_unshift($history, [
            'at'     => $startedAt ?? '',
            'image'  => $image,
            'digest' => $digest,
        ]);
        $history = \array_slice($history, 0, self::MAX);

        @file_put_contents($this->file, json_encode($history, \JSON_PRETTY_PRINT), \LOCK_EX);
    }

    /**
     * @return list<Entry>
     */
    public function recent(int $count = 6): array
    {
        return \array_slice($this->all(), 0, $count);
    }
}
