<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Link;
use App\Entity\Scan;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Seeds a fresh demo workspace from a fixed template (tessera-demo-mode.md /
 * CLAUDE.md rule 19): five example links, each with its OWN believable 90-day
 * scan story (a launch spike, steady traffic, a slow ramp, weekend clusters, a
 * sparse new code), all owned by the session's synthetic user. This makes the
 * dashboard + analytics look like a real account the instant a visitor enters
 * the demo.
 *
 * Link NAMES are localized ONCE here, at seed time, and stored as plain strings
 * (CLAUDE.md rule 18 — we never runtime-translate stored user content); the rest
 * of the app treats them as opaque user data.
 *
 * Destinations are real http(s) URLs (and pass the normal validation) but are
 * never actually followed — the demo redirect shows an interstitial instead.
 */
final class DemoWorkspaceSeeder
{
    /**
     * Five template links. Each carries an i18n `name` key (resolved per session
     * locale), a realistic destination, and a `story` that drives how its scans
     * are distributed over the 90-day window. Totals are deliberately NON-ROUND
     * so the "top links" / time series read as organic, not fabricated.
     */
    private const TEMPLATE = [
        ['name' => 'demo.seed.launch', 'destination' => 'https://novapress.example.com/launch/aurora-v2', 'story' => 'launch', 'scans' => 287],
        ['name' => 'demo.seed.steady', 'destination' => 'https://chez-mathilde.example.com/menu/spring-2026', 'story' => 'steady', 'scans' => 146],
        ['name' => 'demo.seed.growth', 'destination' => 'https://ateliers-lumiere.example.com/newsletter', 'story' => 'growth', 'scans' => 119],
        ['name' => 'demo.seed.weekend', 'destination' => 'https://marche-bastille.example.com/pop-up', 'story' => 'weekend', 'scans' => 73],
        ['name' => 'demo.seed.low', 'destination' => 'https://linktr.example.com/elara-music', 'story' => 'low', 'scans' => 24],
    ];

    private const SPREAD_DAYS = 90;

    /**
     * Country weights (cumulative buckets). A realistic long tail: a few markets
     * dominate, the rest trail off. Sums to 100.
     *
     * @var array<string, int>
     */
    private const COUNTRY_WEIGHTS = [
        'FR' => 30, 'DE' => 16, 'ES' => 11, 'IT' => 9, 'GB' => 8,
        'US' => 7, 'NL' => 6, 'BE' => 5, 'CH' => 4, 'PT' => 4,
    ];

    /**
     * Device weights (sums to 100): mobile-first, as a real QR audience is.
     *
     * @var array<string, int>
     */
    private const DEVICE_WEIGHTS = ['smartphone' => 62, 'desktop' => 28, 'tablet' => 10];

    /**
     * OS choices per device — correlated, so a "smartphone" never reports
     * "Windows". Each inner list is sampled with array_rand (roughly even, with
     * iOS/Android leaning realistic via duplication).
     *
     * @var array<string, list<string>>
     */
    private const OS_BY_DEVICE = [
        'smartphone' => ['iOS', 'iOS', 'iOS', 'Android', 'Android'],
        'tablet' => ['iPadOS', 'iPadOS', 'Android'],
        'desktop' => ['Windows', 'Windows', 'macOS', 'macOS', 'Linux'],
    ];

    /**
     * Referrers (cumulative buckets, sums to 100). ~35% null = direct / a true
     * QR scan with no referrer; the rest are realistic social/search origins.
     *
     * @var array<?string, int>
     */
    private const REFERRER_WEIGHTS = [
        'https://www.google.com/' => 22,
        'https://www.instagram.com/' => 14,
        'https://www.facebook.com/' => 11,
        'https://l.facebook.com/' => 4,
        'https://t.co/' => 6,
        'https://www.linkedin.com/' => 5,
        'https://www.reddit.com/' => 3,
        // 35 → null (direct / camera QR scan), handled by the fall-through.
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SlugGenerator $slugs,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Number of links a fresh workspace is pre-seeded with. The demo link quota
     * is applied on TOP of this (CLAUDE.md rule 19) so the visitor can always
     * create their full allowance — the seed never eats into it.
     */
    public static function templateSize(): int
    {
        return \count(self::TEMPLATE);
    }

    /**
     * Seed the workspace. Link names are translated into $locale at this point
     * and persisted as plain strings (CLAUDE.md rule 18). Everything is flushed
     * once at the end — ~650 inserts in a single transaction.
     */
    public function seed(User $user, string $locale = 'en'): void
    {
        $now = new \DateTimeImmutable();

        foreach (self::TEMPLATE as $tpl) {
            $link = (new Link())
                ->setOwner($user)
                ->setName($this->translator->trans($tpl['name'], [], 'messages', $locale))
                ->setDestinationUrl($tpl['destination'])
                ->setSlug($this->slugs->generateUnique());
            $this->em->persist($link);

            for ($i = 0; $i < $tpl['scans']; ++$i) {
                $this->em->persist($this->fakeScan($link, $tpl['story'], $now));
            }
        }

        $this->em->flush();
    }

    /** Build one believable scan: a story-driven timestamp + correlated attributes. */
    private function fakeScan(Link $link, string $story, \DateTimeImmutable $now): Scan
    {
        $when = $this->scanTimestamp($story, $now);

        $device = $this->weighted(self::DEVICE_WEIGHTS);
        $osChoices = self::OS_BY_DEVICE[$device];

        return (new Scan($link, $when))
            ->setDevice($device)
            ->setOs($osChoices[array_rand($osChoices)])
            ->setCountry($this->weighted(self::COUNTRY_WEIGHTS))
            ->setReferrer($this->pickReferrer());
    }

    /**
     * Pick a "days ago" offset (0 = today, up to SPREAD_DAYS) that follows the
     * link's story, then jitter the time-of-day so two scans never collide.
     */
    private function scanTimestamp(string $story, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $daysAgo = $this->daysAgoForStory($story);

        return $now
            ->modify(sprintf('-%d days', $daysAgo))
            ->modify(sprintf('-%d minutes', random_int(0, 1439)))
            ->modify(sprintf('-%d seconds', random_int(0, 59)));
    }

    /** Per-story date picker. Returns whole days-ago in [0, SPREAD_DAYS]. */
    private function daysAgoForStory(string $story): int
    {
        switch ($story) {
            case 'launch':
                // Dense burst ~70–80 days ago (around the launch), decaying since.
                // 70% of hits cluster in the launch window; the rest is a long tail.
                if (random_int(1, 100) <= 70) {
                    return $this->clampDay(random_int(68, 82));
                }

                return $this->clampDay(random_int(0, 67));

            case 'steady':
                // Even across the full 90 days, with a mild weekly rhythm: weekdays
                // a touch busier than weekends. Resample once if we land on a
                // weekend to thin those days out slightly.
                $day = random_int(0, self::SPREAD_DAYS);
                if ($this->isWeekend($day) && 0 === random_int(0, 1)) {
                    $day = random_int(0, self::SPREAD_DAYS);
                }

                return $day;

            case 'growth':
                // Slow ramp toward today: bias the distribution to recent days by
                // taking the larger of two "days ago" draws (skews toward small).
                return min(random_int(0, self::SPREAD_DAYS), random_int(0, self::SPREAD_DAYS));

            case 'weekend':
                // Cluster on weekends across the window. Draw a day, then nudge to
                // the nearest weekend if it isn't one.
                $day = random_int(0, self::SPREAD_DAYS);
                if (!$this->isWeekend($day)) {
                    $day = $this->nearestWeekend($day);
                }

                return $this->clampDay($day);

            case 'low':
            default:
                // Sparse and mostly recent: ~last 20 days, with a faint older tail.
                if (random_int(1, 100) <= 80) {
                    return random_int(0, 19);
                }

                return random_int(20, self::SPREAD_DAYS);
        }
    }

    private function clampDay(int $day): int
    {
        return max(0, min(self::SPREAD_DAYS, $day));
    }

    /**
     * Is `daysAgo` a Saturday or Sunday? Today is day 0; we derive the weekday
     * from the actual calendar so seasonality lines up with the real dashboard.
     */
    private function isWeekend(int $daysAgo): bool
    {
        $dow = (int) (new \DateTimeImmutable(sprintf('-%d days', $daysAgo)))->format('N'); // 1=Mon … 7=Sun

        return $dow >= 6;
    }

    /** Nearest weekend day-offset to `daysAgo`, staying within the window. */
    private function nearestWeekend(int $daysAgo): int
    {
        for ($delta = 1; $delta <= 3; ++$delta) {
            if ($this->isWeekend($this->clampDay($daysAgo + $delta))) {
                return $this->clampDay($daysAgo + $delta);
            }
            if ($this->isWeekend($this->clampDay($daysAgo - $delta))) {
                return $this->clampDay($daysAgo - $delta);
            }
        }

        return $this->clampDay($daysAgo);
    }

    /** Weighted pick from a {value => weight} map whose weights sum to 100. */
    private function weighted(array $weights): string
    {
        $roll = random_int(1, 100);
        $cursor = 0;
        foreach ($weights as $value => $weight) {
            $cursor += $weight;
            if ($roll <= $cursor) {
                return (string) $value;
            }
        }

        return (string) array_key_last($weights);
    }

    /** Weighted referrer pick; the ~35% remainder falls through to null (direct). */
    private function pickReferrer(): ?string
    {
        $roll = random_int(1, 100);
        $cursor = 0;
        foreach (self::REFERRER_WEIGHTS as $url => $weight) {
            $cursor += $weight;
            if ($roll <= $cursor) {
                return $url;
            }
        }

        return null;
    }
}
