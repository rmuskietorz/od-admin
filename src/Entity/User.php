<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private string $username;

    #[ORM\Column]
    private string $password;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $roles = ['ROLE_USER'];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Base32-TOTP-Secret; null = 2FA aus. */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $totpSecret = null;

    /** @var list<string> Recovery-Codes, jeweils sha256-gehasht. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $recoveryCodes = null;

    public function __construct(string $username, string $password)
    {
        $this->username = $username;
        $this->password = $password;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getUserIdentifier(): string
    {
        // UserInterface::getUserIdentifier() ist als non-empty-string deklariert.
        // Ein Username ist per DB-Constraint und Anlage-Command nie leer.
        \assert('' !== $this->username);

        return $this->username;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $hashed): void
    {
        $this->password = $hashed;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): void
    {
        $this->roles = $roles;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isTwoFactorEnabled(): bool
    {
        return null !== $this->totpSecret && '' !== $this->totpSecret;
    }

    public function getTotpSecret(): ?string
    {
        return $this->totpSecret;
    }

    public function setTotpSecret(?string $secret): void
    {
        $this->totpSecret = $secret;
    }

    /** @return list<string> */
    public function getRecoveryCodes(): array
    {
        return $this->recoveryCodes ?? [];
    }

    /** @param list<string> $codes sha256-Hashes */
    public function setRecoveryCodes(array $codes): void
    {
        $this->recoveryCodes = array_values($codes);
    }

    public function eraseCredentials(): void
    {
    }
}
