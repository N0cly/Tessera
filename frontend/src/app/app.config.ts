import { registerLocaleData } from '@angular/common';
import localeDe from '@angular/common/locales/de';
import localeEs from '@angular/common/locales/es';
import localeFr from '@angular/common/locales/fr';
import localeIt from '@angular/common/locales/it';
import { provideHttpClient, withInterceptors } from '@angular/common/http';
import {
  ApplicationConfig,
  inject,
  isDevMode,
  provideAppInitializer,
  provideBrowserGlobalErrorListeners,
} from '@angular/core';
import { provideRouter } from '@angular/router';
import { provideTransloco } from '@jsverse/transloco';

import { routes } from './app.routes';
import { AppConfigService } from './core/app-config.service';
import { authInterceptor } from './core/auth.interceptor';
import { SUPPORTED_LANGS } from './core/locale';
import { LocaleService } from './core/locale.service';
import { TranslocoHttpLoader } from './core/transloco-loader';

// Angular locale data for the non-default languages (en is built in), so date /
// number / currency pipes can format per locale at runtime.
registerLocaleData(localeFr);
registerLocaleData(localeEs);
registerLocaleData(localeIt);
registerLocaleData(localeDe);

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    provideRouter(routes),
    provideHttpClient(withInterceptors([authInterceptor])),
    provideTransloco({
      config: {
        availableLangs: [...SUPPORTED_LANGS],
        defaultLang: 'en',
        fallbackLang: 'en',
        // English fills any key missing from another locale.
        missingHandler: { useFallbackTranslation: true },
        reRenderOnLangChange: true,
        prodMode: !isDevMode(),
      },
      loader: TranslocoHttpLoader,
    }),
    // Bootstrap, in order, before first render: instance flags (demo mode,
    // billing) THEN the startup language. Config must resolve first so demoMode
    // is known — otherwise a stale token 401 from LocaleService's /api/me probe
    // would hit the interceptor before it can choose the demo (re-seed) recovery.
    provideAppInitializer(() => {
      const config = inject(AppConfigService);
      const locale = inject(LocaleService);
      return (async () => {
        await config.load();
        await locale.init();
      })();
    }),
  ],
};
