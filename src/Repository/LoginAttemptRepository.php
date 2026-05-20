<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LoginAttempt;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LoginAttempt>
 */
class LoginAttemptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LoginAttempt::class);
    }

    /**
     * @return list<LoginAttempt>
     */
    public function findRecent(int $limit = 50): array
    {
        $qb = $this->createQueryBuilder('a')
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit);

        /** @var list<LoginAttempt> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    public function purgeOlderThan(\DateTimeImmutable $threshold): int
    {
        return (int) $this->createQueryBuilder('a')
            ->delete()
            ->where('a.createdAt < :t')
            ->setParameter('t', $threshold)
            ->getQuery()
            ->execute();
    }
}
