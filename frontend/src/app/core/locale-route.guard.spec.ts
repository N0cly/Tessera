import { TestBed } from '@angular/core/testing';
import { ActivatedRouteSnapshot, RouterStateSnapshot } from '@angular/router';
import { vi } from 'vitest';

import { localeRouteGuard } from './locale-route.guard';
import { LocaleService } from './locale.service';

describe('localeRouteGuard', () => {
  let setFromRoute: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    setFromRoute = vi.fn();
    TestBed.configureTestingModule({
      providers: [{ provide: LocaleService, useValue: { setFromRoute } }],
    });
  });

  function run(data: Record<string, unknown>): boolean {
    const route = { data } as unknown as ActivatedRouteSnapshot;
    return TestBed.runInInjectionContext(
      () => localeRouteGuard(route, {} as RouterStateSnapshot) as boolean,
    );
  }

  it('applies a supported language from route data', () => {
    expect(run({ lang: 'fr' })).toBe(true);
    expect(setFromRoute).toHaveBeenCalledWith('fr');
  });

  it('is a no-op on a canonical route with no lang', () => {
    expect(run({ page: 'landing' })).toBe(true);
    expect(setFromRoute).not.toHaveBeenCalled();
  });

  it('ignores an unsupported lang value', () => {
    expect(run({ lang: 'pt' })).toBe(true);
    expect(setFromRoute).not.toHaveBeenCalled();
  });
});
