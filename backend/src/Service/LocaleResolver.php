<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;

/**
 * Single source for the supported locales and how to resolve one. English is
 * the default + fallback (CLAUDE.md i18n).
 */
final class LocaleResolver
{
    /** @var list<string> */
    public const SUPPORTED = ['en', 'fr', 'es', 'it', 'de'];
    public const DEFAULT = 'en';

    /** Coerce any tag (e.g. "fr-CA", "DE") to a supported locale, else default. */
    public function normalize(?string $locale): string
    {
        if (null === $locale || '' === $locale) {
            return self::DEFAULT;
        }
        $base = strtolower(substr($locale, 0, 2));

        return in_array($base, self::SUPPORTED, true) ? $base : self::DEFAULT;
    }

    public function isSupported(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED, true);
    }

    /** Best supported locale from the request's Accept-Language, else default. */
    public function fromAcceptLanguage(Request $request): string
    {
        return $this->normalize($request->getPreferredLanguage(self::SUPPORTED));
    }
}
