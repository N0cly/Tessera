<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin client for the Paddle (Merchant of Record) Billing API + webhook
 * signature verification. Paddle hosts the checkout and the customer portal,
 * handles PCI/card data and tax/VAT, and is the SOURCE OF TRUTH for prices and
 * discounts. We only:
 *  - read the catalogue (prices + active discounts) to render the pricing page,
 *  - kick off a hosted checkout for a given price,
 *  - deep-link the portal,
 *  - verify incoming webhooks.
 *
 * Which price id maps to which plan lives in PlanCatalog (the single source),
 * NOT here — this client is given a price id and stays plan-agnostic.
 *
 * All secrets come from env (PADDLE_API_KEY / PADDLE_WEBHOOK_SECRET) and are
 * never committed — see CLAUDE.md rules 14 and 16 and .env.example.
 */
final class PaddleClient
{
    /** Reject webhooks whose signature timestamp is older than this (replay guard). */
    private const MAX_SIGNATURE_AGE_SECONDS = 300;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(default::PADDLE_API_BASE_URL)%')]
        private readonly ?string $apiBaseUrl,
        #[Autowire('%env(default::PADDLE_API_KEY)%')]
        private readonly ?string $apiKey,
        #[Autowire('%env(default::PADDLE_WEBHOOK_SECRET)%')]
        private readonly ?string $webhookSecret,
    ) {
    }

    /** Whether we can talk to the Paddle API at all (server-side key present). */
    public function isConfigured(): bool
    {
        return '' !== (string) $this->apiKey && '' !== (string) $this->base();
    }

    public function isWebhookConfigured(): bool
    {
        return '' !== (string) $this->webhookSecret;
    }

    /**
     * Create a hosted-checkout transaction for the current user against a
     * specific Paddle price and return the Paddle-hosted checkout URL. The user
     * id rides along in custom_data so the webhook can map the result back to
     * the account.
     *
     * @throws \RuntimeException if billing is not configured or Paddle errors
     */
    public function createCheckoutUrl(User $user, string $priceId): string
    {
        if (!$this->isConfigured() || '' === $priceId) {
            throw new \RuntimeException('Billing is not configured on this instance.');
        }

        $response = $this->httpClient->request('POST', $this->base().'/transactions', [
            'auth_bearer' => (string) $this->apiKey,
            'headers' => ['Paddle-Version' => '1'],
            'json' => [
                'items' => [['price_id' => $priceId, 'quantity' => 1]],
                'custom_data' => ['user_id' => (string) $user->getId()],
            ],
        ]);

        $data = $response->toArray(false);
        $url = $data['data']['checkout']['url'] ?? null;
        if (!is_string($url) || '' === $url) {
            throw new \RuntimeException('Paddle did not return a checkout URL.');
        }

        return $url;
    }

    /**
     * Create a customer-portal session and return its overview URL, or null if
     * we can't (no customer id yet, or Paddle declined).
     */
    public function createPortalUrl(string $customerId): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }

        try {
            $response = $this->httpClient->request(
                'POST',
                sprintf('%s/customers/%s/portal-sessions', $this->base(), rawurlencode($customerId)),
                [
                    'auth_bearer' => (string) $this->apiKey,
                    'headers' => ['Paddle-Version' => '1'],
                    'json' => (object) [],
                ],
            );
            $data = $response->toArray(false);
        } catch (\Throwable) {
            return null;
        }

        $url = $data['data']['urls']['general']['overview'] ?? null;

        return is_string($url) && '' !== $url ? $url : null;
    }

    /**
     * Read a single price from Paddle. Returns the amount in MINOR units (so it
     * always equals what Paddle charges — no float drift), the currency, the
     * billing interval, and the owning product id (needed to match discounts
     * that are restricted to a product rather than a specific price). Returns
     * null on ANY problem (not configured, network error, unexpected shape) so
     * callers can fail safe and never render a wrong number (CLAUDE.md rule 16).
     *
     * @return array{amount: int, currency: string, interval: ?string, productId: ?string}|null
     */
    public function fetchPrice(string $priceId): ?array
    {
        if (!$this->isConfigured() || '' === $priceId) {
            return null;
        }

        try {
            $response = $this->httpClient->request(
                'GET',
                sprintf('%s/prices/%s', $this->base(), rawurlencode($priceId)),
                [
                    'auth_bearer' => (string) $this->apiKey,
                    'headers' => ['Paddle-Version' => '1'],
                ],
            );
            if (200 !== $response->getStatusCode()) {
                return null;
            }
            $data = $response->toArray(false)['data'] ?? null;
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($data)) {
            return null;
        }

        $amount = $data['unit_price']['amount'] ?? null;
        $currency = $data['unit_price']['currency_code'] ?? null;
        if (!is_string($amount) || !ctype_digit($amount) || !is_string($currency) || '' === $currency) {
            return null;
        }

        $interval = null;
        $cycle = $data['billing_cycle'] ?? null;
        if (is_array($cycle) && is_string($cycle['interval'] ?? null)) {
            $interval = $cycle['interval'];
        }

        return [
            'amount' => (int) $amount,
            'currency' => $currency,
            'interval' => $interval,
            'productId' => is_string($data['product_id'] ?? null) ? $data['product_id'] : null,
        ];
    }

    /**
     * Active standard discounts (used to surface time-limited promotions on the
     * pricing page). Returns the raw Paddle discount rows filtered to
     * status=active; matching them to a price is the caller's job.
     *
     * Returns an empty array when the call succeeded but there are no active
     * discounts, and **null when the call FAILED** (not configured, network
     * error, non-200, junk) so the caller can tell "no promos" apart from "we
     * couldn't check" — a missing promo is safe, a wrong price is not.
     *
     * @return list<array<string, mixed>>|null
     */
    public function fetchActiveDiscounts(): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', $this->base().'/discounts', [
                'auth_bearer' => (string) $this->apiKey,
                'headers' => ['Paddle-Version' => '1'],
                'query' => ['status' => 'active', 'per_page' => 200],
            ]);
            if (200 !== $response->getStatusCode()) {
                return null;
            }
            $rows = $response->toArray(false)['data'] ?? null;
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($rows)) {
            return null;
        }

        return array_values(array_filter($rows, static fn ($row): bool => is_array($row)));
    }

    /**
     * List subscriptions from Paddle, following pagination up to $maxPages. Used
     * by the admin panel to derive business KPIs (MRR, counts) — Paddle is the
     * source of truth for revenue/subscription metrics; we never recompute
     * revenue from raw payments (CLAUDE.md rules 16/17).
     *
     * Returns the raw subscription rows, or null on ANY failure (not configured,
     * network error, non-200, junk) so the caller can fail safe.
     *
     * @return list<array<string, mixed>>|null
     */
    public function listSubscriptions(int $maxPages = 20): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $all = [];
        $url = $this->base().'/subscriptions';
        // Only the statuses the KPIs need, so churned rows don't eat the page
        // budget. (Churn is computed from our DB mirror, not from this list.)
        $query = ['per_page' => 200, 'status' => 'active,trialing,past_due,paused'];

        try {
            for ($page = 0; $page < $maxPages; ++$page) {
                $response = $this->httpClient->request('GET', $url, [
                    'auth_bearer' => (string) $this->apiKey,
                    'headers' => ['Paddle-Version' => '1'],
                    'query' => $query,
                ]);
                if (200 !== $response->getStatusCode()) {
                    return null;
                }

                $body = $response->toArray(false);
                $data = $body['data'] ?? null;
                if (!is_array($data)) {
                    return null;
                }
                foreach ($data as $row) {
                    if (is_array($row)) {
                        $all[] = $row;
                    }
                }

                $pagination = $body['meta']['pagination'] ?? [];
                $next = is_array($pagination) ? ($pagination['next'] ?? null) : null;
                $hasMore = is_array($pagination) && true === ($pagination['has_more'] ?? false);
                if (!$hasMore || !is_string($next) || '' === $next) {
                    return $all; // fully fetched
                }
                // The `next` URL already carries the pagination cursor.
                $url = $next;
                $query = [];
            }
        } catch (\Throwable) {
            return null;
        }

        // Reached the page cap with more still pending: returning the partial
        // list would silently under-count MRR, so fail safe (caller → degraded).
        return null;
    }

    /**
     * Verify a Paddle webhook signature against the raw request body.
     *
     * Paddle sends `Paddle-Signature: ts=<unix>;h1=<hex>` where the HMAC is
     * SHA-256 of "<ts>:<rawBody>" keyed with the webhook secret. We reject on
     * any malformed input, a stale timestamp, or a mismatch — fail closed.
     */
    public function verifyWebhookSignature(string $rawBody, ?string $signatureHeader): bool
    {
        $secret = (string) $this->webhookSecret;
        if ('' === $secret || null === $signatureHeader || '' === $signatureHeader) {
            return false;
        }

        $ts = null;
        $h1 = null;
        foreach (explode(';', $signatureHeader) as $part) {
            $pair = explode('=', trim($part), 2);
            if (2 !== count($pair)) {
                continue;
            }
            if ('ts' === $pair[0]) {
                $ts = $pair[1];
            } elseif ('h1' === $pair[0]) {
                $h1 = $pair[1];
            }
        }

        if (null === $ts || null === $h1 || !ctype_digit($ts) || '' === $h1) {
            return false;
        }

        if (abs(time() - (int) $ts) > self::MAX_SIGNATURE_AGE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $ts.':'.$rawBody, $secret);

        return hash_equals($expected, $h1);
    }

    private function base(): string
    {
        return rtrim((string) $this->apiBaseUrl, '/');
    }
}
