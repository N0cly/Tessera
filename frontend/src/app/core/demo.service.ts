import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { TranslocoService } from '@jsverse/transloco';
import { Observable, catchError, map, of, tap } from 'rxjs';

import { environment } from '../../environments/environment';
import { AuthService } from './auth.service';

/**
 * Demo session bootstrap (tessera-demo-mode.md / CLAUDE.md rule 19). On entering
 * the demo (the app), an anonymous, seeded, ephemeral workspace is created and
 * its token (a JWT for the session's synthetic user) is adopted as the auth
 * token — so the rest of the app runs as that isolated session with no signup.
 */
@Injectable({ providedIn: 'root' })
export class DemoService {
  private readonly http = inject(HttpClient);
  private readonly auth = inject(AuthService);
  private readonly transloco = inject(TranslocoService);

  /**
   * Create + adopt a fresh demo session. The active UI language is sent so the
   * backend seeds the showcase link names in the visitor's language (the names
   * are localized once, at seed time — CLAUDE.md rule 18).
   */
  createSession(): Observable<boolean> {
    return this.http
      .post<{
        token: string;
      }>(
        `${environment.apiBaseUrl}/api/demo/session`,
        {},
        { params: { locale: this.transloco.getActiveLang() } },
      )
      .pipe(
        tap(({ token }) => this.auth.useToken(token)),
        map(() => true),
        catchError(() => of(false)),
      );
  }

  /** Ensure a session exists; if already authenticated, no-op. */
  ensureSession(): Observable<boolean> {
    return this.auth.isAuthenticated() ? of(true) : this.createSession();
  }
}
