import { HttpClient } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { Observable, tap } from 'rxjs';

import { environment } from '../../environments/environment';

/**
 * Auth for the operator admin panel. Deliberately separate from the user
 * AuthService: a distinct token (its own storage key) minted only by the 2FA
 * admin login. The token is purely a UI convenience — the backend enforces real
 * authorization (admin role + 2FA scope + IP) on every endpoint.
 */
const ADMIN_TOKEN_KEY = 'tessera.admin.jwt';

@Injectable({ providedIn: 'root' })
export class AdminAuthService {
  private readonly http = inject(HttpClient);
  private readonly router = inject(Router);

  private readonly _token = signal<string | null>(
    typeof localStorage !== 'undefined' ? localStorage.getItem(ADMIN_TOKEN_KEY) : null,
  );
  readonly token = this._token.asReadonly();
  readonly isAuthenticated = computed(() => this._token() !== null);

  /** Admin login: email + password + a current TOTP code. */
  login(email: string, password: string, code: string): Observable<{ token: string }> {
    return this.http
      .post<{ token: string }>(`${environment.apiBaseUrl}/admin/login`, { email, password, code })
      .pipe(tap((res) => this.setToken(res.token)));
  }

  logout(): void {
    this.setToken(null);
    void this.router.navigate(['/admin/login']);
  }

  private setToken(token: string | null): void {
    this._token.set(token);
    if (typeof localStorage === 'undefined') return;
    if (token) localStorage.setItem(ADMIN_TOKEN_KEY, token);
    else localStorage.removeItem(ADMIN_TOKEN_KEY);
  }
}
