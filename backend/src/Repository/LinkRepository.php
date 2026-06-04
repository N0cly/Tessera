<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Link;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Link>
 */
class LinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Link::class);
    }

    /**
     * Number of links owned by the user — the dashboard's "active codes" KPI.
     * Codes never expire (see CLAUDE.md), so every owned link counts.
     */
    public function countForOwner(User $owner): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.owner = :owner')
            ->setParameter('owner', $owner)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findOneBySlug(string $slug): ?Link
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * Slugs of every link owned by the user — used to bust the redirect cache
     * for all of an owner's codes when their subscription status changes.
     *
     * @return list<string>
     */
    public function findSlugsByOwner(User $owner): array
    {
        /** @var list<array{slug: string}> $rows */
        $rows = $this->createQueryBuilder('l')
            ->select('l.slug')
            ->where('l.owner = :owner')
            ->setParameter('owner', $owner)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $r) => $r['slug'], $rows);
    }

    public function slugExists(string $slug): bool
    {
        return null !== $this->findOneBy(['slug' => $slug]);
    }
}
