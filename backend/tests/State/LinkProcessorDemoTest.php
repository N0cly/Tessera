<?php

declare(strict_types=1);

namespace App\Tests\State;

use App\Entity\Link;
use App\Entity\User;
use App\Repository\LinkRepository;
use App\Service\DemoWorkspaceSeeder;
use App\Service\FeatureFlags;
use App\Service\PlanCatalog;
use App\Service\SlugGenerator;
use App\Service\SubscriptionManager;
use App\State\LinkProcessor;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The demo per-session quota is applied ON TOP of the seeded template links
 * (CLAUDE.md rule 19), so a fresh workspace's pre-seeded codes never eat into
 * the visitor's creation allowance.
 */
final class LinkProcessorDemoTest extends TestCase
{
    private const QUOTA = 5;

    /** seeded template (5) + quota (5) = 10 total before a 402. */
    public function testDemoQuotaIsAddedOnTopOfTheSeededLinks(): void
    {
        $links = $this->createStub(LinkRepository::class);
        // One below the effective cap: still allowed.
        $links->method('countForOwner')->willReturn(DemoWorkspaceSeeder::templateSize() + self::QUOTA - 1);

        $link = (new Link())->setSlug('preset12')->setDestinationUrl('https://example.test/x');
        $processor = $this->processor($links, $link);

        $result = $processor->process($link, new Post());

        self::assertSame($link, $result, 'a visitor under the effective cap can still create a link');
    }

    public function testDemoQuotaExceededReportsTheTotalCap(): void
    {
        $links = $this->createStub(LinkRepository::class);
        // Exactly at the effective cap (seed + quota): refused.
        $links->method('countForOwner')->willReturn(DemoWorkspaceSeeder::templateSize() + self::QUOTA);

        $link = (new Link())->setSlug('preset12')->setDestinationUrl('https://example.test/x');
        $processor = $this->processor($links, $link);

        try {
            $processor->process($link, new Post());
            self::fail('expected a 402 once the demo workspace is full');
        } catch (HttpException $e) {
            self::assertSame(402, $e->getStatusCode());
            // %count% must be the TOTAL cap (10), proving the quota is additive.
            self::assertStringContainsString(
                (string) (DemoWorkspaceSeeder::templateSize() + self::QUOTA),
                $e->getMessage(),
            );
        }
    }

    private function processor(LinkRepository $links, Link $persisted): LinkProcessor
    {
        $user = new User();

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $persist = $this->createStub(ProcessorInterface::class);
        $persist->method('process')->willReturn($persisted);

        // Only reached on the happy path; always accept.
        $limiter = $this->createStub(LimiterInterface::class);
        $limiter->method('consume')->willReturn(new RateLimit(10, new \DateTimeImmutable(), true, 10));
        $limiterFactory = $this->createStub(RateLimiterFactoryInterface::class);
        $limiterFactory->method('create')->willReturn($limiter);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $params = []): string => $id.':'.($params['%count%'] ?? ''),
        );

        // Both are final and never reached on the demo path — instantiate them
        // without invoking their (env-wired) constructors.
        $subscriptions = (new \ReflectionClass(SubscriptionManager::class))->newInstanceWithoutConstructor();
        $plans = (new \ReflectionClass(PlanCatalog::class))->newInstanceWithoutConstructor();

        return new LinkProcessor(
            $persist,
            new SlugGenerator($links),
            $security,
            $limiterFactory,
            $subscriptions,
            $plans,
            $links,
            $translator,
            new FeatureFlags(true, false, 1, self::QUOTA, null),
        );
    }
}
