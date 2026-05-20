<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LoginAttemptRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LoginAttemptRepository::class)]
#[ORM\Table(name: 'login_attempt')]
#[ORM\Index(columns: ['created_at'], name: 'idx_attempt_created_at')]
#[ORM\Index(columns: ['username'], name: 'idx_attempt_username')]
class LoginAttempt
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $username;

    #[ORM\Column(length: 45)]
    private string $ip;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgent;

    #[ORM\Column]
    private bool $success;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $reason;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $username,
        string $ip,
        ?string $userAgent,
        bool $success,
        ?string $reason = null,
    ) {
        $this->username = substr($username, 0, 180);
        $this->ip = substr($ip, 0, 45);
        $this->userAgent = null !== $userAgent ? substr($userAgent, 0, 255) : null;
        $this->success = $success;
        $this->reason = null !== $reason ? substr($reason, 0, 255) : null;
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

    public function getIp(): string
    {
        return $this->ip;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
