<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Assembles the public pricing catalogue for /api/pricing by reading live
 * prices + active discounts from Paddle (the single source of truth) and
 * pairing them with the per-plan code limits from PlanCatalog.
 *
 * Caching: the assembled catalogue is cached in Redis for PRICING_CACHE_TTL so
 * we never call Paddle on every page view. If Paddle can't be read for a
 * configured plan we mark that plan unavailable (amount stays null) and cache
 * for only a short window so the page recovers quickly once Paddle is back —
 * we never serve a wrong price (CLAUDE.md rule 16, tessera-pricing-page.md).
 */
final class PricingCatalog
{
    private const CACHE_KEY = 'app.pricing.catalog';
    /** Upper bound on how long a degraded (Paddle-unreachable) result is cached. */
    private const DEGRADED_TTL = 30;

    public function __construct(
        #[Autowire(service: 'app.cache.pricing')]
        private readonly CacheInterface $cache,
        private readonly PaddleClient $paddle,
        private readonly PlanCatalog $plans,
        #[Autowire('%env(int:PRICING_CACHE_TTL)%')]
        private readonly int $ttl,
    ) {
    }

    /**
     * The paid plans with their live Paddle pricing, ready to serialize.
     *
     * @return list<array<string, mixed>>
     */
    public function plans(): array
    {
        $cached = $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
            $result = $this->build();
            $ttl = max(1, $this->ttl);
            // Healthy → full TTL. Degraded → brief, so Paddle's recovery shows
            // up fast without letting the public endpoint hammer Paddle.
            $item->expiresAfter($result['degraded'] ? min($ttl, self::DEGRADED_TTL) : $ttl);

            return $result;
        });

        return $cached['plans'];
    }

    /**
     * Drop the cached catalogue. Called by the billing webhook when Paddle
     * reports a price/discount/product change so the next view reflects it.
     */
    public function invalidate(): void
    {
        $this->cache->delete(self::CACHE_KEY);
    }

    /**
     * @return array{plans: list<array<string, mixed>>, degraded: bool}
     */
    private function build(): array
    {
        // One discounts call shared across all plans (cache-miss only). null
        // signals the call FAILED (vs [] = succeeded with no active discounts):
        // a failure means a real promo might be missing, so we mark the result
        // degraded (short TTL) to recover quickly — without ever showing a wrong
        // price. A genuine empty list is healthy and cached for the full TTL.
        $fetchedDiscounts = $this->paddle->fetchActiveDiscounts();
        $discounts = $fetchedDiscounts ?? [];
        $plans = [];
        // A discounts-fetch failure only counts as degraded when Paddle is
        // actually configured. On a pure self-host instance (no Paddle) the null
        // is expected, not a transient blip, so the (price-less) catalogue is a
        // stable state cached for the full TTL — no pointless 30s churn.
        $degraded = null === $fetchedDiscounts && $this->paddle->isConfigured();

        foreach ($this->plans->paidPlans() as $plan) {
            $priceId = $this->plans->priceIdForPlan($plan);
            $entry = [
                'plan' => $plan,
                'name' => $this->plans->displayName($plan),
                'priceId' => $priceId,
                'amount' => null,
                'currency' => null,
                'interval' => null,
                // From PlanCatalog (app config) — known even when Paddle is down.
                'codeLimit' => $this->plans->codeLimitForPlan($plan),
                'available' => false,
                'promo' => null,
            ];

            if (null !== $priceId) {
                $price = $this->paddle->fetchPrice($priceId);
                if (null !== $price) {
                    $entry['amount'] = $price['amount'];
                    $entry['currency'] = $price['currency'];
                    $entry['interval'] = $price['interval'];
                    $entry['available'] = true;
                    $entry['promo'] = $this->resolvePromo($priceId, $price, $discounts);
                } else {
                    // Configured but Paddle wouldn't price it → degraded.
                    $degraded = true;
                }
            }
            // priceId === null is NOT degraded: it's a deliberate "billing not
            // configured for this plan" state (e.g. a pure self-host instance).

            $plans[] = $entry;
        }

        return ['plans' => $plans, 'degraded' => $degraded];
    }

    /**
     * Pick the best active discount that applies to this price and return the
     * promo descriptor, including the exact discounted amount (minor units).
     *
     * @param array{amount: int, currency: string, interval: ?string, productId: ?string} $price
     * @param list<array<string, mixed>>                                                   $discounts
     *
     * @return array{type: string, amount: int|float, label: ?string, endsAt: ?string, finalAmount: int}|null
     */
    private function resolvePromo(string $priceId, array $price, array $discounts): ?array
    {
        $bestDiscount = null;
        $bestFinal = $price['amount'];

        foreach ($discounts as $discount) {
            if (!$this->discountApplies($discount, $priceId, $price)) {
                continue;
            }
            $final = $this->applyDiscount($discount, $price);
            if (null === $final || $final >= $bestFinal) {
                continue;
            }
            $bestFinal = $final;
            $bestDiscount = $discount;
        }

        if (null === $bestDiscount) {
            return null;
        }

        $type = 'percentage' === ($bestDiscount['type'] ?? null) ? 'percentage' : 'flat';
        $rawAmount = $bestDiscount['amount'] ?? 0;
        $label = $bestDiscount['description'] ?? null;

        return [
            'type' => $type,
            'amount' => 'percentage' === $type ? (float) $rawAmount : (int) $rawAmount,
            'label' => is_string($label) && '' !== $label ? $label : null,
            'endsAt' => is_string($bestDiscount['expires_at'] ?? null) ? $bestDiscount['expires_at'] : null,
            'finalAmount' => $bestFinal,
        ];
    }

    /**
     * @param array<string, mixed>                                                        $discount
     * @param array{amount: int, currency: string, interval: ?string, productId: ?string} $price
     */
    private function discountApplies(array $discount, string $priceId, array $price): bool
    {
        if (($discount['status'] ?? null) !== 'active') {
            return false;
        }

        // Belt-and-braces expiry check (status=active should already exclude
        // expired ones, but a clock skew window is cheap to guard).
        $expiresAt = $discount['expires_at'] ?? null;
        if (is_string($expiresAt)) {
            try {
                if (new \DateTimeImmutable($expiresAt) <= new \DateTimeImmutable()) {
                    return false;
                }
            } catch (\Exception) {
                return false;
            }
        }

        // restrict_to null/empty = applies to everything; otherwise it must list
        // either this price id OR its product id. Paddle allows a discount to be
        // scoped to a whole product ("all prices for that product"), so matching
        // only the price id would silently drop a genuinely-applied promo and
        // show a price higher than Paddle actually charges.
        $restrict = $discount['restrict_to'] ?? null;
        if (is_array($restrict) && [] !== $restrict) {
            $productId = $price['productId'] ?? null;
            $matches = in_array($priceId, $restrict, true)
                || (is_string($productId) && in_array($productId, $restrict, true));
            if (!$matches) {
                return false;
            }
        }

        // A flat discount only makes sense in the price's own currency.
        $type = $discount['type'] ?? null;
        if ('flat' === $type || 'flat_per_seat' === $type) {
            $discountCurrency = $discount['currency_code'] ?? null;
            if (!is_string($discountCurrency) || $discountCurrency !== $price['currency']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Compute the discounted amount (minor units), mirroring how Paddle applies
     * the discount, or null if the discount is unusable.
     *
     * @param array<string, mixed>                                                        $discount
     * @param array{amount: int, currency: string, interval: ?string, productId: ?string} $price
     */
    private function applyDiscount(array $discount, array $price): ?int
    {
        $type = $discount['type'] ?? null;
        $raw = $discount['amount'] ?? null;
        if (!is_string($raw) && !is_int($raw)) {
            return null;
        }
        $raw = (string) $raw;

        if ('percentage' === $type) {
            if (!is_numeric($raw)) {
                return null;
            }
            $pct = (float) $raw;
            if ($pct <= 0.0 || $pct > 100.0) {
                return null;
            }

            return max(0, (int) round($price['amount'] * (100.0 - $pct) / 100.0));
        }

        if ('flat' === $type || 'flat_per_seat' === $type) {
            if (!ctype_digit($raw)) {
                return null;
            }

            return max(0, $price['amount'] - (int) $raw);
        }

        return null;
    }
}
