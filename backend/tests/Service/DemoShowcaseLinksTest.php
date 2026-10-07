<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Cache\LinkCache;
use App\Repository\LinkRepository;
use App\Repository\UserRepository;
use App\Service\DemoRedirectAllowlist;
use App\Service\DemoShowcaseLinks;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class DemoShowcaseLinksTest extends TestCase
{
    public function testShortUrlsAreStableAndDerivedFromTheHost(): void
    {
        $showcase = new DemoShowcaseLinks(
            new DemoRedirectAllowlist('nocly.fr, bongiorno.nocly.fr dbsky.nocly.fr nocly.com my-site.io'),
            $this->createStub(LinkRepository::class),
            $this->createStub(UserRepository::class),
            $this->createStub(EntityManagerInterface::class),
            new LinkCache(new ArrayAdapter(), $this->createStub(LinkRepository::class)),
            'https://demo.tessera.test/',
        );

        self::assertSame([
            'nocly.fr' => 'https://demo.tessera.test/r/nocly',
            'bongiorno.nocly.fr' => 'https://demo.tessera.test/r/bongiorno',
            'dbsky.nocly.fr' => 'https://demo.tessera.test/r/dbsky',
            'nocly.com' => 'https://demo.tessera.test/r/nocly2',
            'my-site.io' => 'https://demo.tessera.test/r/mysite',
        ], $showcase->shortUrls());
    }

    public function testConfiguredSlugsWinOverDerivedOnes(): void
    {
        $showcase = new DemoShowcaseLinks(
            new DemoRedirectAllowlist('nocly.fr,bongiorno.nocly.fr,dbsky.nocly.fr'),
            $this->createStub(LinkRepository::class),
            $this->createStub(UserRepository::class),
            $this->createStub(EntityManagerInterface::class),
            new LinkCache(new ArrayAdapter(), $this->createStub(LinkRepository::class)),
            'https://demo.tessera.test',
            'nocly.fr=eVi58se, bongiorno.nocly.fr=9mfRs98 dbsky.nocly.fr=bad/slug',
        );

        self::assertSame([
            'nocly.fr' => 'https://demo.tessera.test/r/eVi58se',
            'bongiorno.nocly.fr' => 'https://demo.tessera.test/r/9mfRs98',
            'dbsky.nocly.fr' => 'https://demo.tessera.test/r/dbsky',
        ], $showcase->shortUrls(), 'an invalid slug falls back to the derived one');
    }
}
