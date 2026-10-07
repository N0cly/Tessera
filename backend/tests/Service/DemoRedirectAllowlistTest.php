<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\DemoRedirectAllowlist;
use PHPUnit\Framework\TestCase;

final class DemoRedirectAllowlistTest extends TestCase
{
    private function list(?string $raw = 'nocly.fr, bongiorno.nocly.fr  DBSKY.nocly.fr'): DemoRedirectAllowlist
    {
        return new DemoRedirectAllowlist($raw);
    }

    public function testParsesCommaAndWhitespaceSeparatedHostsLowercased(): void
    {
        self::assertSame(['nocly.fr', 'bongiorno.nocly.fr', 'dbsky.nocly.fr'], $this->list()->hosts());
    }

    public function testAllowsExactHostsCaseInsensitiveAnyPathOrPort(): void
    {
        $l = $this->list();
        self::assertTrue($l->allows('https://nocly.fr/'));
        self::assertTrue($l->allows('https://NOCLY.fr/projects?x=1'));
        self::assertTrue($l->allows('http://bongiorno.nocly.fr:8080/menu'));
        self::assertTrue($l->allows('https://dbsky.nocly.fr'));
    }

    public function testRejectsEverythingElse(): void
    {
        $l = $this->list();
        self::assertFalse($l->allows('https://evil.nocly.fr/'), 'no implicit subdomain match');
        self::assertFalse($l->allows('https://nocly.fr.evil.com/'));
        self::assertFalse($l->allows('https://notnocly.fr/'));
        self::assertFalse($l->allows('https://nocly.fr@evil.com/'), 'userinfo trick resolves to evil.com');
        self::assertFalse($l->allows('javascript://nocly.fr/%0aalert(1)'), 'non-http(s) scheme');
        self::assertFalse($l->allows('not a url'));
    }

    public function testEmptyListAllowsNothing(): void
    {
        self::assertSame([], $this->list('')->hosts());
        self::assertFalse($this->list(null)->allows('https://nocly.fr/'));
    }
}
