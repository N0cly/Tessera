import { HttpClient } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { Observable, tap } from 'rxjs';

import { environment } from '../../environments/environment';

const TOKEN_KEY = 'qrcr.jwt';

interface LoginResponse {
  token: string;
}

interface RegisterResponse {
  id: string;
  email: string;
}

@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly http = inject(HttpClient);
  private readonly router = inject(Router);

  private readonly _token = signal<string | null>(
    typeof localStorage !== 'undefined' ? localStorage.getItem(TOKEN_KEY) : null,
  );
  readonly token = this._token.asReadonly();
  readonly isAuthenticated = computed(() => this._token() !== null);

  login(email: string, password: string): Observable<LoginResponse> {
    return this.http
      .post<LoginResponse>(`${environment.apiBaseUrl}/api/login_check`, { email, password })
      .pipe(tap((res) => this.setToken(res.token)));
  }

  register(email: string, password: string): Observable<RegisterResponse> {
    return this.http.post<RegisterResponse>(`${environment.apiBaseUrl}/api/register`, {
      email,
      password,
    });
  }

  logout(): void {
    this.setToken(null);
    void this.router.navigate(['/login']);
  }

  private setToken(token: string | null): void {
    this._token.set(token);
    if (typeof localStorage === 'undefined') return;
    if (token) localStorage.setItem(TOKEN_KEY, token);
    else localStorage.removeItem(TOKEN_KEY);
  }
}
