import { inject } from '@angular/core';
import { CanActivateFn } from '@angular/router';

import { isLang } from './locale';
import { LocaleService } from './locale.service';

/**
 * Applies the language from a locale-prefixed public route (e.g. /fr, /es/pricing)
 * via route data `{ lang }`. No-op when the route carries no lang (canonical
 * routes keep the runtime-resolved language). UX only — translations resolve at
 * runtime regardless.
 */
export const localeRouteGuard: CanActivateFn = (route) => {
  const lang = route.data?.['lang'];
  if (isLang(lang)) {
    inject(LocaleService).setFromRoute(lang);
  }
  return true;
};
