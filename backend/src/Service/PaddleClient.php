<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin client for the Paddle (Merchant of Record) Billing API + webhook
 * signature verification. Paddle hosts the checkout and the customer portal,
 * handles PCI/card data and tax/VAT — we only kick off a hosted checkout,
 * deep-link the portal, and verify incoming webhooks.
 *
 * All secrets come from env (PADDLE_API_KEY / PADDLE_WEBHOOK_SECRET) and are
 * never committed — see CLAUDE.md rule 14 and .env.example.
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
        #[Autowire('%env(default::PADDLE_PRICE_ID)%')]
        private readonly ?string $priceId,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== (string) $this->apiKey && '' !== (string) $this->priceId;
    }

    public function isWebhookConfigured(): bool
    {
        return '' !== (string) $this->webhookSecret;
    }

    /**
     * Create a hosted-checkout transaction for the current user and return the
     * Paddle-hosted checkout URL. The user id rides along in custom_data so the
     * webhook can map the result back to the account.
     *
     * @throws \RuntimeException if billing is not configured or Paddle errors
     */
    public function createCheckoutUrl(User $user): string
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Billing is not configured on this instance.');
        }

        $response = $this->httpClient->request('POST', $this->base().'/transactions', [
            'auth_bearer' => (string) $this->apiKey,
            'headers' => ['Paddle-Version' => '1'],
            'json' => [
                'items' => [['price_id' => $this->priceId, 'quantity' => 1]],
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
