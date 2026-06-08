import { HttpClient } from '@angular/common/http';
import { Injectable, effect, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { TranslocoService } from '@jsverse/transloco';
import { firstValueFrom, map } from 'rxjs';

import { environment } from '../../environments/environment';
import { AuthService } from './auth.service';
import { DEFAULT_LANG, Lang, isLang, matchLang } from './locale';

const STORAGE_KEY = 'tessera.lang';

/**
 * Centralizes the active UI language and its persistence.
 *
 * The single source of truth is TranslocoService — every change goes through
 * `setActiveLang`, and `lang()` is just a reactive mirror of its `langChanges$`.
 * At startup (APP_INITIALIZER) the language is restored (logged-in profile →
 * localStorage → browser → English) and its file is PRELOADED before the first
 * render, so no raw keys ever flash and the language survives a hard refresh.
 * Every change is persisted to localStorage and, when logged in, to the profile.
 */
@Injectable({ providedIn: 'root' })
export class LocaleService {
  private readonly transloco = inject(TranslocoService);
  private readonly http = inject(HttpClient);
  private readonly auth = inject(AuthService);

  /** Reactive mirror of TranslocoService's active lang (the source of truth). */
  readonly lang = toSignal(
    this.transloco.langChanges$.pipe(map((l): Lang => (isLang(l) ? l : DEFAULT_LANG))),
    { initialValue: this.currentLang() },
  );

  /** True once the user explicitly picked a language this session (switcher). */
  private explicitChoice = false;
  /** Tracks auth transitions so we react only to login/logout. */
  private wasAuthenticated = this.auth.isAuthenticated();

  constructor() {
    // On login adopt the profile locale; on logout drop the previous user's so a
    // shared browser doesn't carry it over. A transition clears the in-session
    // explicit choice so the profile is authoritative on login.
    effect(() => {
      const authed = this.auth.isAuthenticated();
      if (authed === this.wasAuthenticated) return;
      this.wasAuthenticated = authed;
      this.explicitChoice = false;
      if (authed) {
        this.refreshFromServer();
      } else {
        this.setActive(this.detectFromBrowser() ?? DEFAULT_LANG, false);
      }
    });
  }

  /**
   * APP_INITIALIZER: restore the active language and preload its file BEFORE the
   * first render. Returns a promise Angular awaits, so the app never paints with
   * unresolved keys. Order: logged-in profile → localStorage → browser → English.
   */
  async init(): Promise<void> {
    let lang: Lang | null = this.auth.isAuthenticated() ? await this.fetchProfileLocale() : null;
    lang ??= this.readStored() ?? this.detectFromBrowser() ?? DEFAULT_LANG;

    this.setActive(lang, false);
    // Block startup until the active translations are loaded (no raw-key flash).
    await firstValueFrom(this.transloco.load(lang));
  }

  /** A user picked a language from the switcher (persists locally + to profile). */
  setLang(lang: Lang): void {
    this.explicitChoice = true;
    this.setActive(lang, true);
  }

  /** Reflect a locale coming from a localized public route (e.g. /fr/pricing). */
  setFromRoute(lang: Lang): void {
    if (lang !== this.currentLang()) {
      this.setActive(lang, false);
    }
  }

  /** Pull the logged-in user's profile locale and apply it (if any). */
  refreshFromServer(): void {
    if (!this.auth.isAuthenticated()) return;
    this.http.get<{ locale: string | null }>(`${environment.apiBaseUrl}/api/me`).subscribe({
      next: (me) => {
        // Don't clobber a language the user actively picked while in flight.
        if (this.explicitChoice) return;
        const lang = matchLang(me.locale);
        if (lang) this.setActive(lang, false);
      },
      error: () => {
        /* not fatal — keep the locally-resolved language */
      },
    });
  }

  /** Write the active language through the single source of truth + persist it. */
  private setActive(lang: Lang, pushToServer: boolean): void {
    this.transloco.setActiveLang(lang);
    if (typeof document !== 'undefined') {
      document.documentElement.lang = lang;
    }
    this.writeStored(lang);

    if (pushToServer && this.auth.isAuthenticated()) {
      this.http
        .patch(`${environment.apiBaseUrl}/api/me`, { locale: lang })
        .subscribe({ error: () => {} });
    }
  }

  private currentLang(): Lang {
    const active = this.transloco.getActiveLang();
    return isLang(active) ? active : DEFAULT_LANG;
  }

  private async fetchProfileLocale(): Promise<Lang | null> {
    try {
      const me = await firstValueFrom(
        this.http.get<{ locale: string | null }>(`${environment.apiBaseUrl}/api/me`),
      );
      return matchLang(me.locale);
    } catch {
      return null;
    }
  }

  private detectFromBrowser(): Lang | null {
    if (typeof navigator === 'undefined') return null;
    const tags = navigator.languages?.length ? navigator.languages : [navigator.language];
    for (const tag of tags) {
      const lang = matchLang(tag);
      if (lang) return lang;
    }
    return null;
  }

  private readStored(): Lang | null {
    if (typeof localStorage === 'undefined') return null;
    return matchLang(localStorage.getItem(STORAGE_KEY));
  }

  private writeStored(lang: Lang): void {
    if (typeof localStorage !== 'undefined') localStorage.setItem(STORAGE_KEY, lang);
  }
}
