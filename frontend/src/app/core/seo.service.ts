import { DOCUMENT } from '@angular/common';
import { Injectable, inject } from '@angular/core';
import { Meta, Title } from '@angular/platform-browser';
import { TranslocoService } from '@jsverse/transloco';
import { Subscription } from 'rxjs';

import { SUPPORTED_LANGS } from './locale';
import { LocaleService } from './locale.service';

type PublicPage = 'landing' | 'pricing';

/** Canonical (English / default) path for each localizable public page. */
const PAGE_PATH: Record<PublicPage, string> = {
  landing: '',
  pricing: 'pricing',
};

/**
 * Localized SEO for the public marketing pages (landing + pricing). Sets a
 * translated <title> and <meta name="description">, keeps <html lang> in sync,
 * and emits hreflang alternates (one per locale + x-default) so search engines
 * can index each language at its prefixed URL (/fr, /es, …). App pages don't
 * need this — runtime switching is enough there (CLAUDE.md i18n).
 */
@Injectable({ providedIn: 'root' })
export class SeoService {
  private readonly title = inject(Title);
  private readonly meta = inject(Meta);
  private readonly transloco = inject(TranslocoService);
  private readonly locale = inject(LocaleService);
  private readonly doc = inject(DOCUMENT);

  /**
   * Apply SEO tags for a page and keep them in sync with the active language.
   * Returns a Subscription the component must release on destroy.
   */
  apply(page: PublicPage): Subscription {
    return this.transloco
      .selectTranslate([`seo.${page}.title`, `seo.${page}.description`])
      .subscribe(([title, description]) => {
        this.title.setTitle(title);
        this.meta.updateTag({ name: 'description', content: description });
        this.doc.documentElement.lang = this.locale.lang();
        this.setAlternates(page);
      });
  }

  /** Rebuild hreflang alternate links for the given page. */
  private setAlternates(page: PublicPage): void {
    const head = this.doc.head;
    head
      .querySelectorAll(
        'link[rel="alternate"][data-i18n="1"], link[rel="canonical"][data-i18n="1"]',
      )
      .forEach((el) => el.remove());

    const origin = this.doc.location?.origin ?? '';
    const path = PAGE_PATH[page];
    const urlFor = (lang: string) => {
      const prefix = lang === 'en' ? '' : `/${lang}`;
      const tail = path ? `/${path}` : '';
      return `${origin}${prefix}${tail}` || `${origin}/`;
    };

    const add = (rel: string, href: string, hreflang?: string) => {
      const link = this.doc.createElement('link');
      link.setAttribute('rel', rel);
      link.setAttribute('href', href);
      if (hreflang) link.setAttribute('hreflang', hreflang);
      link.setAttribute('data-i18n', '1');
      head.appendChild(link);
    };

    for (const lang of SUPPORTED_LANGS) {
      add('alternate', urlFor(lang), lang);
    }
    add('alternate', urlFor('en'), 'x-default');
    // Self-referential canonical: the URL actually being served (derived from
    // the path), NOT the runtime UI language — otherwise /pricing viewed in
    // French would wrongly canonicalize to /fr/pricing.
    const served = this.doc.location?.pathname ?? '/';
    add('canonical', `${origin}${served}`);
  }
}
