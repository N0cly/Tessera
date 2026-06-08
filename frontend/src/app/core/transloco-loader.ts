import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Translation, TranslocoLoader } from '@jsverse/transloco';
import { Observable } from 'rxjs';

/**
 * Loads one JSON file per locale from /assets/i18n/{lang}.json. Each file holds
 * all feature namespaces (common, landing, pricing, auth, dashboard, links,
 * stats, admin, adminAuth, seo) as top-level keys; error/empty strings live
 * nested under their feature (e.g. auth.errors, links.errors). English is the
 * fallback (configured in provideTransloco), so any key missing from a locale
 * resolves to en.
 */
@Injectable({ providedIn: 'root' })
export class TranslocoHttpLoader implements TranslocoLoader {
  private readonly http = inject(HttpClient);

  getTranslation(lang: string): Observable<Translation> {
    return this.http.get<Translation>(`/assets/i18n/${lang}.json`);
  }
}
