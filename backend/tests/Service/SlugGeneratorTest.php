<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Link;
use App\Repository\LinkRepository;
use App\Service\SlugGenerator;
use PHPUnit\Framework\TestCase;

final class SlugGeneratorTest extends TestCase
{
    public function testGeneratesRequestedLengthWhenNoCollision(): void
    {
        $slug = (new SlugGenerator($this->repo(static fn () => false)))->generateUnique();

        self::assertSame(7, strlen($slug), 'default slug length is 7');
        self::assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $slug);
    }

    public function testAvoidsAmbiguousChars(): void
    {
        $gen = new SlugGenerator($this->repo(static fn () => false));
        // Sample enough slugs to exercise the alphabet; none should contain
        // chars that look alike in print (0/O/1/l/I).
        for ($i = 0; $i < 50; $i++) {
            self::assertDoesNotMatchRegularExpression('/[0Ol1I]/', $gen->generateUnique());
        }
    }

    public function testWidensLengthAfterRepeatedCollisions(): void
    {
        $calls = 0;
        // First 10 lookups (one full attempt cycle) collide, then accept.
        // The implementation should recurse with length+1.
        $repo = $this->repo(static function () use (&$calls): bool {
            return ++$calls <= 10;
        });

        $slug = (new SlugGenerator($repo))->generateUnique();

        self::assertGreaterThan(7, strlen($slug), 'slug must widen after collision burst');
    }

    /**
     * Anonymous-class stub so we don't pull PHPUnit's mock generator over a
     * ServiceEntityRepository whose generic + LSP shape upsets it in v13.
     *
     * @param callable(string): bool $exists
     */
    private function repo(callable $exists): LinkRepository
    {
        return new class($exists) extends LinkRepository {
            /** @param callable(string): bool $exists */
            public function __construct(private $exists)
            {
                // Skip parent constructor on purpose — we don't need the
                // ManagerRegistry for slug existence checks.
            }

            public function slugExists(string $slug): bool
            {
                return ($this->exists)($slug);
            }

            public function findOneBySlug(string $slug): ?Link
            {
                return null;
            }
        };
    }
}
