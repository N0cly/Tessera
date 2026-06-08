<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\SubscriptionRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Business KPIs for the admin panel. Revenue / subscription metrics come from
 * Paddle (the source of truth) — MRR is normalized from the active subscriptions
 * Paddle reports, NEVER recomputed from raw payments (CLAUDE.md rule 17 /
 * tessera-admin-panel.md). Trial→paid conversion and churn need history the live
 * Paddle snapshot doesn't give, so they're derived from our webhook-synced
 * Subscription mirror.
 *
 * Redis-cached so the panel never calls Paddle per view; fails safe (Paddle
 * unreachable → available:false, no wrong numbers), exactly like PricingCatalog.
 */
final class AdminBillingMetrics
{
    private const CACHE_KEY = 'admin.business';
    private const DEGRADED_TTL = 30;

    public function __construct(
        #[Autowire(service: 'app.cache.admin')]
        private readonly CacheInterface $cache,
        private readonly PaddleClient $paddle,
        private readonly PlanCatalog $plans,
        private readonly SubscriptionRepository $subscriptions,
        #[Autowire('%env(int:ADMIN_METRICS_CACHE_TTL)%')]
        private readonly int $ttl,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function business(): array
    {
        $cached = $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
            $result = $this->build();
            $ttl = max(1, $this->ttl);
            $item->expiresAfter($result['degraded'] ? min($ttl, self::DEGRADED_TTL) : $ttl);

            return $result;
        });

        unset($cached['degraded']);

        return $cached;
    }

    public function invalidate(): void
    {
        $this->cache->delete(self::CACHE_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    private function build(): array
    {
        $subs = $this->paddle->listSubscriptions();
        $available = null !== $subs;
        // Configured-but-unreadable → degraded (short cache). Not configured →
        // a deliberate "no billing" state, cached for the full TTL.
        $degraded = $this->paddle->isConfigured() && !$available;

        $paddlePart = null !== $subs ? $this->computeFromSubscriptions($subs) : [
            'currency' => null,
            'mrr' => null,
            'mixedCurrency' => false,
            'activeSubscriptions' => 0,
            'trialing' => 0,
            'pastDue' => 0,
            'canceled' => 0,
            'byPlan' => ['starter' => 0, 'pro' => 0, 'other' => 0],
        ];

        return [
            'available' => $available,
            'degraded' => $degraded,
            ...$paddlePart,
            // From our synced mirror (history Paddle's snapshot can't give).
            'trialConversionRate' => round($this->subscriptions->trialConversionRate(), 4),
            'churnRateLast30d' => round($this->subscriptions->churnRateLast30d(), 4),
        ];
    }

    /**
     * Derive the business KPIs from a list of Paddle subscription rows. Pure +
     * public so it can be unit-tested without Paddle. MRR is in MINOR units,
     * normalized to a monthly figure from each active subscription's recurring
     * price — not from raw payments.
     *
     * @param list<array<string, mixed>> $subs
     *
     * @return array<string, mixed>
     */
    public function computeFromSubscriptions(array $subs): array
    {
        $active = $trialing = $pastDue = $canceled = 0;
        $byPlan = ['starter' => 0, 'pro' => 0, 'other' => 0];
        // MRR is accumulated per currency. Summing minor units across different
        // currencies is meaningless, so a mix is reported as "unavailable"
        // rather than a wrong total (fail safe — CLAUDE.md rules 16/17).
        $mrrByCurrency = [];

        foreach ($subs as $sub) {
            $status = is_string($sub['status'] ?? null) ? $sub['status'] : '';
            switch ($status) {
                case 'trialing':
                    ++$trialing;
                    break;
                case 'past_due':
                case 'paused':
                    ++$pastDue;
                    break;
                case 'canceled':
                    ++$canceled;
                    break;
                case 'active':
                    ++$active;
                    $byPlan[$this->planBucket($sub)] += 1;
                    $key = $this->currencyOf($sub) ?? '?';
                    $mrrByCurrency[$key] = ($mrrByCurrency[$key] ?? 0) + $this->monthlyMinor($sub);
                    break;
            }
        }

        $mixedCurrency = count($mrrByCurrency) > 1;
        if ($mixedCurrency) {
            $mrr = null;
            $currency = null;
        } elseif ([] === $mrrByCurrency) {
            $mrr = 0;
            $currency = null;
        } else {
            $currency = array_key_first($mrrByCurrency);
            $mrr = $mrrByCurrency[$currency];
            if ('?' === $currency) {
                $currency = null;
            }
        }

        return [
            'currency' => $currency,
            'mrr' => $mrr,
            'mixedCurrency' => $mixedCurrency,
            'activeSubscriptions' => $active,
            'trialing' => $trialing,
            'pastDue' => $pastDue,
            'canceled' => $canceled,
            'byPlan' => $byPlan,
        ];
    }

    /**
     * Monthly recurring amount (minor units) for one subscription, summed over
     * its items and normalized by billing interval.
     *
     * @param array<string, mixed> $sub
     */
    private function monthlyMinor(array $sub): int
    {
        $total = 0;
        foreach ($this->items($sub) as $item) {
            $price = is_array($item['price'] ?? null) ? $item['price'] : [];
            $amount = $price['unit_price']['amount'] ?? null;
            if (!is_string($amount) || !ctype_digit($amount)) {
                continue;
            }
            $qty = max(1, (int) ($item['quantity'] ?? 1));
            $cycle = is_array($price['billing_cycle'] ?? null) ? $price['billing_cycle'] : [];
            $interval = is_string($cycle['interval'] ?? null) ? $cycle['interval'] : 'month';
            $freq = max(1, (int) ($cycle['frequency'] ?? 1));
            $total += $this->normalizeMonthly((int) $amount * $qty, $interval, $freq);
        }

        return $total;
    }

    private function normalizeMonthly(int $amount, string $interval, int $freq): int
    {
        $monthly = match ($interval) {
            'year' => $amount / (12 * $freq),
            'week' => $amount * 52 / 12 / $freq,
            'day' => $amount * 365 / 12 / $freq,
            default => $amount / $freq, // month (and anything else) billed every $freq months
        };

        return (int) round($monthly);
    }

    /**
     * @param array<string, mixed> $sub
     */
    private function planBucket(array $sub): string
    {
        foreach ($this->items($sub) as $item) {
            $priceId = is_array($item['price'] ?? null) ? ($item['price']['id'] ?? null) : null;
            if (is_string($priceId)) {
                $plan = $this->plans->planForPriceId($priceId);
                if (PlanCatalog::PLAN_STARTER === $plan) {
                    return 'starter';
                }
                if (PlanCatalog::PLAN_PRO === $plan) {
                    return 'pro';
                }
            }
        }

        return 'other';
    }

    /**
     * @param array<string, mixed> $sub
     */
    private function currencyOf(array $sub): ?string
    {
        foreach ($this->items($sub) as $item) {
            $currency = is_array($item['price'] ?? null) ? ($item['price']['unit_price']['currency_code'] ?? null) : null;
            if (is_string($currency) && '' !== $currency) {
                return $currency;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $sub
     *
     * @return list<array<string, mixed>>
     */
    private function items(array $sub): array
    {
        $items = $sub['items'] ?? null;
        if (!is_array($items)) {
            return [];
        }

        return array_values(array_filter($items, static fn ($i): bool => is_array($i)));
    }
}
