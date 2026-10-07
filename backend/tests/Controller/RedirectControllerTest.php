<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Cache\LinkCache;
use App\Controller\RedirectController;
use App\Http\DemoInterstitialRenderer;
use App\Repository\LinkRepository;
use App\Service\DemoRedirectAllowlist;
use App\Service\FeatureFlags;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The demo real-redirect gate (tessera-demo-real-redirects.md): in DEMO_MODE a
 * real 302 only for a SEEDED link whose destination host is allowlisted;
 * everything else → interstitial. Self-host (demo off) always 302s.
 */
final class RedirectControllerTest extends TestCase
{
    private function controller(bool $demo, string $destination, bool $seeded, int &$scans = 0): RedirectController
    {
        $pool = new ArrayAdapter();
        $pool->get('link.slug.abc', static fn () => [
            'id' => '0190a0a0-0000-7000-8000-000000000000',
            'destinationUrl' => $destination,
            'demoSeeded' => $seeded,
        ]);
        $cache = new LinkCache($pool, $this->createStub(LinkRepository::class));

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $m) use (&$scans): Envelope {
            ++$scans;

            return new Envelope($m);
        });

        $flags = new FeatureFlags($demo, false, 1, 5, null);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new RedirectController(
            $cache,
            $bus,
            $flags,
            new DemoInterstitialRenderer($translator, $flags),
            new DemoRedirectAllowlist('nocly.fr,bongiorno.nocly.fr,dbsky.nocly.fr'),
        );
    }

    public function testDemoSeededAllowlistedLinkReallyRedirects(): void
    {
        $scans = 0;
        $res = ($this->controller(true, 'https://nocly.fr/', true, $scans))('abc', Request::create('/r/abc'));

        self::assertInstanceOf(RedirectResponse::class, $res);
        self::assertSame(302, $res->getStatusCode());
        self::assertSame('https://nocly.fr/', $res->headers->get('Location'));
        self::assertSame(1, $scans);
    }

    public function testDemoSeededLinkRepointedOffListShowsInterstitial(): void
    {
        $scans = 0;
        $res = ($this->controller(true, 'https://example.org/phish', true, $scans))('abc', Request::create('/r/abc'));

        self::assertNotInstanceOf(RedirectResponse::class, $res);
        self::assertSame(200, $res->getStatusCode());
        self::assertFalse($res->headers->has('Location'));
        self::assertStringContainsString('https://example.org/phish', (string) $res->getContent());
        self::assertSame(1, $scans, 'the simulated scan is still recorded');
    }

    public function testDemoVisitorLinkShowsInterstitialEvenToAnAllowlistedHost(): void
    {
        $res = ($this->controller(true, 'https://nocly.fr/', false))('abc', Request::create('/r/abc'));

        self::assertNotInstanceOf(RedirectResponse::class, $res);
        self::assertFalse($res->headers->has('Location'));
    }

    public function testSelfHostAlwaysRedirects(): void
    {
        $res = ($this->controller(false, 'https://example.org/menu', false))('abc', Request::create('/r/abc'));

        self::assertInstanceOf(RedirectResponse::class, $res);
        self::assertSame(302, $res->getStatusCode());
        self::assertSame('https://example.org/menu', $res->headers->get('Location'));
    }
}
