import { HttpClient } from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { TranslocoService } from '@jsverse/transloco';
import { Subject, of } from 'rxjs';
import { vi } from 'vitest';

import { AuthService } from './auth.service';
import { LocaleService } from './locale.service';

function setup(opts: { authed?: boolean; profile?: string | null; stored?: string | null } = {}) {
  localStorage.clear();
  if (opts.stored) localStorage.setItem('tessera.lang', opts.stored);

  let active = 'en';
  const langChanges$ = new Subject<string>();
  const transloco = {
    getActiveLang: () => active,
    setActiveLang: vi.fn((l: string) => {
      active = l;
      langChanges$.next(l);
    }),
    load: vi.fn(() => of({})),
    langChanges$,
  };
  const auth = { isAuthenticated: () => !!opts.authed };
  const http = {
    get: vi.fn(() => of({ locale: opts.profile ?? null })),
    patch: vi.fn(() => of({})),
  };

  TestBed.configureTestingModule({
    providers: [
      LocaleService,
      { provide: TranslocoService, useValue: transloco },
      { provide: AuthService, useValue: auth },
      { provide: HttpClient, useValue: http },
    ],
  });

  return { svc: TestBed.inject(LocaleService), transloco, http };
}

describe('LocaleService', () => {
  it('restores the logged-in profile locale first and preloads it before render', async () => {
    const { svc, transloco, http } = setup({ authed: true, profile: 'fr' });

    await svc.init();

    expect(http.get).toHaveBeenCalled(); // fetched the profile
    expect(transloco.setActiveLang).toHaveBeenCalledWith('fr');
    expect(transloco.load).toHaveBeenCalledWith('fr'); // preloaded before resolving
    expect(localStorage.getItem('tessera.lang')).toBe('fr');
  });

  it('falls back to localStorage for an anonymous visitor (no profile fetch)', async () => {
    const { svc, transloco, http } = setup({ authed: false, stored: 'de' });

    await svc.init();

    expect(http.get).not.toHaveBeenCalled();
    expect(transloco.setActiveLang).toHaveBeenCalledWith('de');
    expect(transloco.load).toHaveBeenCalledWith('de');
  });

  it('setLang routes through TranslocoService and persists locally + to the profile', () => {
    const { svc, transloco, http } = setup({ authed: true, profile: 'en' });

    svc.setLang('es');

    expect(transloco.setActiveLang).toHaveBeenCalledWith('es');
    expect(localStorage.getItem('tessera.lang')).toBe('es');
    expect(http.patch).toHaveBeenCalledWith(expect.stringContaining('/api/me'), { locale: 'es' });
  });

  it('lang() mirrors the active language from langChanges$', () => {
    const { svc } = setup({ authed: false });

    svc.setLang('it');

    expect(svc.lang()).toBe('it');
  });
});
