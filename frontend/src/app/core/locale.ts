/** Supported UI languages. English is the default and the fallback. */
export const SUPPORTED_LANGS = ['en', 'fr', 'es', 'it', 'de'] as const;
export type Lang = (typeof SUPPORTED_LANGS)[number];

export const DEFAULT_LANG: Lang = 'en';

/** Human labels for the language switcher (each shown in its own language). */
export const LANG_LABELS: Record<Lang, string> = {
  en: 'English',
  fr: 'Français',
  es: 'Español',
  it: 'Italiano',
  de: 'Deutsch',
};

export function isLang(value: unknown): value is Lang {
  return typeof value === 'string' && (SUPPORTED_LANGS as readonly string[]).includes(value);
}

/**
 * Best-effort match of a BCP-47 tag (e.g. "fr-CA", "de") to a supported lang,
 * or null. Used for browser-language detection.
 */
export function matchLang(tag: string | null | undefined): Lang | null {
  if (!tag) return null;
  const base = tag.toLowerCase().split('-')[0];
  return isLang(base) ? base : null;
}

/**
 * Prefix an internal path with the locale (e.g. "/pricing" → "/fr/pricing"),
 * leaving English (the canonical, unprefixed locale) untouched. Used for the
 * marketing pages' internal links so navigation stays within the locale-prefixed
 * URL space for SEO.
 */
export function withLocalePrefix(lang: Lang, path: string): string {
  if (lang === DEFAULT_LANG) return path;
  return '/' === path ? `/${lang}` : `/${lang}${path}`;
}
