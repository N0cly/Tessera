<?php

declare(strict_types=1);

namespace App\Service;

use App\Cache\LinkCache;
use App\Entity\Link;
use App\Entity\User;
use App\Repository\LinkRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Permanent, scannable showcase codes for the public demo's landing page — one
 * per DEMO_REDIRECT_ALLOWLIST host (tessera-demo-real-redirects.md).
 *
 * Each is a real link `{APP_BASE_URL}/r/{slug}` → `https://{host}/`, flagged
 * `demoSeeded` so the demo redirect really 302s. They're owned by a dedicated
 * system user with NO DemoSession, so the idle-session purge never touches them,
 * and nobody can log in as it (unusable password, no JWT is ever minted).
 *
 * Slugs come from DEMO_SHOWCASE_SLUGS (`host=slug` pairs) — else derived from
 * the host's first DNS label — so they're stable across reboots and computable
 * without a query: /api/config exposes them for free. They're created at boot,
 * before any visitor, and SlugGenerator skips existing slugs, so a generated
 * slug never steals one.
 */
final class DemoShowcaseLinks
{
    public const OWNER_EMAIL = 'showcase@demo.invalid';

    public function __construct(
        private readonly DemoRedirectAllowlist $allowlist,
        private readonly LinkRepository $links,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly LinkCache $cache,
        #[Autowire('%env(APP_BASE_URL)%')]
        private readonly string $baseUrl,
        #[Autowire('%env(default::DEMO_SHOWCASE_SLUGS)%')]
        private readonly ?string $slugMap = null,
    ) {
    }

    /**
     * host → short URL, computed from config only (no DB).
     *
     * @return array<string, string>
     */
    public function shortUrls(): array
    {
        $out = [];
        foreach ($this->plan() as $host => $slug) {
            $out[$host] = rtrim($this->baseUrl, '/').'/r/'.$slug;
        }

        return $out;
    }

    /**
     * Create or repair the showcase links. Idempotent: re-running points each
     * slug back at its host. If a host's configured slug changed, its existing
     * showcase link is renamed (old short URL stops working). A slug already
     * owned by someone else is skipped.
     *
     * @return list<array{host: string, slug: string, url: string, status: string}>
     */
    public function ensure(): array
    {
        $owner = $this->owner();
        $urls = $this->shortUrls();
        $report = [];

        foreach ($this->plan() as $host => $slug) {
            $destination = 'https://'.$host.'/';
            $link = $this->links->findOneBySlug($slug);
            $renamed = null === $link ? $this->existingFor($owner, $destination) : null;

            if (null !== $renamed) {
                $oldSlug = (string) $renamed->getSlug();
                $link = $renamed->setSlug($slug);
                $this->cache->invalidate($oldSlug);
                $status = sprintf('renamed from /r/%s', $oldSlug);
            } elseif (null === $link) {
                $link = (new Link())->setOwner($owner)->setSlug($slug);
                $this->em->persist($link);
                $status = 'created';
            } elseif ($link->getOwner()?->getEmail() !== self::OWNER_EMAIL) {
                $report[] = ['host' => $host, 'slug' => $slug, 'url' => $urls[$host], 'status' => 'skipped (slug taken)'];
                continue;
            } else {
                $status = $link->getDestinationUrl() === $destination ? 'ok' : 'repaired';
            }

            $link->setName($host)->setDestinationUrl($destination)->markDemoSeeded();
            $report[] = ['host' => $host, 'slug' => $slug, 'url' => $urls[$host], 'status' => $status];
        }

        // The Doctrine listener busts/warms the redirect cache on these writes.
        $this->em->flush();

        return $report;
    }

    /** This owner's showcase link already pointing at $destination, if any. */
    private function existingFor(User $owner, string $destination): ?Link
    {
        return $this->links->findOneBy(['owner' => $owner, 'destinationUrl' => $destination]);
    }

    /**
     * host → slug.
     *
     * @return array<string, string>
     */
    private function plan(): array
    {
        $configured = $this->configuredSlugs();
        $plan = [];
        foreach ($this->allowlist->hosts() as $host) {
            if (isset($configured[$host]) && !\in_array($configured[$host], $plan, true)) {
                $plan[$host] = $configured[$host];
                continue;
            }
            $base = substr((string) preg_replace('/[^A-Za-z0-9]/', '', explode('.', $host)[0]), 0, 28);
            if ('' === $base) {
                continue;
            }
            $slug = $base;
            for ($n = 2; \in_array($slug, $plan, true); ++$n) {
                $slug = $base.$n;
            }
            $plan[$host] = $slug;
        }

        return $plan;
    }

    /**
     * DEMO_SHOWCASE_SLUGS: "host=slug" pairs, comma/whitespace-separated. Invalid
     * entries (slug outside [A-Za-z0-9]{1,32}) are ignored → derived slug.
     *
     * @return array<string, string>
     */
    private function configuredSlugs(): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', $this->slugMap ?? '') ?: [] as $pair) {
            if (1 === preg_match('/^([^=]+)=([A-Za-z0-9]{1,32})$/', trim($pair), $m)) {
                $out[strtolower($m[1])] = $m[2];
            }
        }

        return $out;
    }

    private function owner(): User
    {
        $owner = $this->users->findOneBy(['email' => self::OWNER_EMAIL]);
        if (null === $owner) {
            $owner = (new User())->setEmail(self::OWNER_EMAIL)->setPassword('!showcase-no-login!');
            $this->em->persist($owner);
        }

        return $owner;
    }
}
