<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\LocaleResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class LocaleResolverTest extends TestCase
{
    private LocaleResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new LocaleResolver();
    }

    public function testNormalizeSupportedAndRegionTags(): void
    {
        self::assertSame('fr', $this->resolver->normalize('fr'));
        self::assertSame('fr', $this->resolver->normalize('fr-CA'));
        self::assertSame('de', $this->resolver->normalize('DE'));
        self::assertSame('es', $this->resolver->normalize('es-419'));
    }

    public function testNormalizeUnsupportedOrEmptyFallsBackToEnglish(): void
    {
        self::assertSame('en', $this->resolver->normalize('pt'));
        self::assertSame('en', $this->resolver->normalize('zh-CN'));
        self::assertSame('en', $this->resolver->normalize(''));
        self::assertSame('en', $this->resolver->normalize(null));
    }

    public function testIsSupported(): void
    {
        self::assertTrue($this->resolver->isSupported('it'));
        self::assertFalse($this->resolver->isSupported('it-IT'), 'only base codes are stored');
        self::assertFalse($this->resolver->isSupported('pt'));
    }

    public function testFromAcceptLanguagePicksBestSupported(): void
    {
        self::assertSame('fr', $this->resolver->fromAcceptLanguage(
            $this->requestWithAcceptLanguage('fr-FR,fr;q=0.9,en;q=0.8'),
        ));
        self::assertSame('de', $this->resolver->fromAcceptLanguage(
            $this->requestWithAcceptLanguage('de-AT,de;q=0.9'),
        ));
    }

    public function testFromAcceptLanguageFallsBackToEnglish(): void
    {
        // No supported language present.
        self::assertSame('en', $this->resolver->fromAcceptLanguage(
            $this->requestWithAcceptLanguage('pt-BR,pt;q=0.9'),
        ));
        // No header at all.
        self::assertSame('en', $this->resolver->fromAcceptLanguage(Request::create('/')));
    }

    private function requestWithAcceptLanguage(string $value): Request
    {
        return Request::create('/', 'GET', [], [], [], ['HTTP_ACCEPT_LANGUAGE' => $value]);
    }
}
