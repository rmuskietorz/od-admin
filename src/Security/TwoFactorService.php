<?php

declare(strict_types=1);

namespace App\Security;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use OTPHP\TOTP;

/**
 * TOTP-2FA ohne Bundle (reine otphp + endroid/qr-code). Kapselt Secret-
 * Erzeugung, Code-Pruefung, Provisioning-URI/QR und Recovery-Codes.
 */
final class TwoFactorService
{
    public function __construct(private readonly string $issuer = 'Open Design Admin')
    {
    }

    /** Neues Base32-Secret (noch nicht gespeichert). */
    public function generateSecret(): string
    {
        return TOTP::generate()->getSecret();
    }

    public function provisioningUri(string $secret, string $username): string
    {
        \assert('' !== $secret && '' !== $username && '' !== $this->issuer);

        $totp = TOTP::createFromSecret($secret);
        $totp->setLabel($username);
        $totp->setIssuer($this->issuer);

        return $totp->getProvisioningUri();
    }

    /** QR als data:-URI (PNG, base64) zum direkten <img src>. */
    public function qrDataUri(string $secret, string $username): string
    {
        $builder = new Builder(
            writer: new PngWriter(),
            data: $this->provisioningUri($secret, $username),
            size: 220,
            margin: 10,
        );

        return $builder->build()->getDataUri();
    }

    /** Prueft einen 6-stelligen TOTP-Code (±1 Zeitfenster Toleranz). */
    public function verify(string $secret, string $code): bool
    {
        $code = trim($code);
        if ('' === $code || '' === $secret) {
            return false;
        }

        return TOTP::createFromSecret($secret)->verify($code, null, 1);
    }

    /**
     * Klartext-Recovery-Codes (einmal anzeigen).
     *
     * @return list<string>
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; ++$i) {
            $codes[] = bin2hex(random_bytes(2)).'-'.bin2hex(random_bytes(2));
        }

        return $codes;
    }

    /**
     * @param list<string> $plain
     *
     * @return list<string> sha256-Hashes zur Speicherung
     */
    public function hashRecoveryCodes(array $plain): array
    {
        return array_map(static fn (string $c): string => hash('sha256', $c), $plain);
    }

    /**
     * Verbraucht einen Recovery-Code: bei Treffer die RESTLICHEN Hashes
     * zurueck, sonst null.
     *
     * @param list<string> $hashes
     *
     * @return list<string>|null
     */
    public function consumeRecoveryCode(array $hashes, string $input): ?array
    {
        $h = hash('sha256', trim($input));
        $idx = array_search($h, $hashes, true);
        if (false === $idx) {
            return null;
        }
        unset($hashes[$idx]);

        return array_values($hashes);
    }
}
