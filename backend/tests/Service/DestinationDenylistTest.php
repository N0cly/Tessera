<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\DestinationDenylist;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DestinationDenylistTest extends TestCase
{
    /**
     * @return iterable<string, array{string|null, string, bool}>
     */
    public static function provider(): iterable
    {
        $list = 'blocked.test, .spam.test, EVIL.example';

        yield 'empty denylist allows everything' => [null, 'https://blocked.test/x', false];
        yield 'empty string denylist allows everything' => ['', 'https://blocked.test/x', false];
        yield 'exact match denies' => [$list, 'http://blocked.test/x', true];
        yield 'exact rule does not match subdomain' => [$list, 'https://sub.blocked.test/x', false];
        yield 'leading-dot matches bare host' => [$list, 'https://spam.test', true];
        yield 'leading-dot matches subdomain' => [$list, 'https://foo.spam.test/x', true];
        yield 'leading-dot does not match suffix collision' => [$list, 'https://nospam.test', false];
        yield 'case-insensitive both sides' => [$list, 'https://Evil.EXAMPLE/x', true];
        yield 'port is irrelevant' => [$list, 'https://blocked.test:8443/x', true];
        yield 'unrelated host passes' => [$list, 'https://example.com', false];
        yield 'unparsable url passes silently' => [$list, 'not a url', false];
    }

    #[DataProvider('provider')]
    public function testIsDenied(?string $rules, string $url, bool $expected): void
    {
        $denylist = new DestinationDenylist($rules);
        self::assertSame($expected, $denylist->isDenied($url));
    }
}
