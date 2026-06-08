<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Instance-wide feature flags, read from env. Two independent switches
 * (CLAUDE.md rule 19 / tessera-demo-mode.md):
 *
 *  - DEMO_MODE: the whole instance runs as an ephemeral public demo. OFF by
 *    default — a self-hoster never gets demo behavior unless they opt in.
 *  - BILLING_ENABLED: paid subscriptions. OFF by default and a SEPARATE flag, so
 *    demo and self-host both run with no revenue surface until explicitly turned
 *    on (and Paddle configured).
 */
final class FeatureFlags
{
    public function __construct(
        #[Autowire('%env(bool:DEMO_MODE)%')]
        private readonly bool $demoMode,
        #[Autowire('%env(bool:BILLING_ENABLED)%')]
        private readonly bool $billingEnabled,
        #[Autowire('%env(int:DEMO_SESSION_TTL_HOURS)%')]
        private readonly int $demoResetHours,
        #[Autowire('%env(int:DEMO_LINK_QUOTA)%')]
        private readonly int $demoLinkQuota,
        #[Autowire('%env(default::DEMO_GITHUB_URL)%')]
        private readonly ?string $githubUrl,
    ) {
    }

    public function isDemoMode(): bool
    {
        return $this->demoMode;
    }

    public function isBillingEnabled(): bool
    {
        return $this->billingEnabled;
    }

    public function demoResetHours(): int
    {
        return $this->demoResetHours > 0 ? $this->demoResetHours : 1;
    }

    public function demoLinkQuota(): int
    {
        return $this->demoLinkQuota > 0 ? $this->demoLinkQuota : 5;
    }

    public function githubUrl(): string
    {
        $url = (string) $this->githubUrl;

        return '' !== $url ? $url : 'https://github.com/N0cly/Tessera';
    }

    /**
     * The public client config consumed by the frontend at startup.
     *
     * @return array<string, mixed>
     */
    public function clientConfig(): array
    {
        return [
            'demoMode' => $this->demoMode,
            'billingEnabled' => $this->billingEnabled,
            'demoResetHours' => $this->demoResetHours(),
            'githubUrl' => $this->githubUrl(),
        ];
    }
}
