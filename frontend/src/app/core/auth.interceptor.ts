import { HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, switchMap, throwError } from 'rxjs';

import { AdminAuthService } from './admin-auth.service';
import { AppConfigService } from './app-config.service';
import { AuthService } from './auth.service';
import { DemoService } from './demo.service';

// Public endpoints that must NEVER carry a user token: the JWT firewall rejects
// an invalid/stale token with 401 even on a public route, which would otherwise
// break the demo bootstrap (config flags + session creation) for any visitor who
// still has a token from a session that has since reset/been purged.
const PUBLIC_API = [
  '/api/config',
  '/api/demo/session',
  '/api/pricing',
  '/api/login_check',
  '/api/register',
  '/api/webhooks',
];

export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const auth = inject(AuthService);
  const adminAuth = inject(AdminAuthService);
  const config = inject(AppConfigService);
  const demo = inject(DemoService);
  const router = inject(Router);

  // Admin API calls carry the admin token; everything else the user token. The
  // admin login itself and the public API endpoints are unauthenticated.
  const isAdminApi = req.url.includes('/admin/');
  const isAdminLogin = req.url.includes('/admin/login');
  const isPublicApi = !isAdminApi && PUBLIC_API.some((p) => req.url.includes(p));
  const token = isAdminApi ? adminAuth.token() : auth.token();

  const authed =
    token && !isAdminLogin && !isPublicApi
      ? req.clone({ setHeaders: { Authorization: `Bearer ${token}` } })
      : req;

  return next(authed).pipe(
    catchError((err) => {
      if (err.status === 401) {
        if (isAdminApi && !isAdminLogin && adminAuth.isAuthenticated()) {
          // Admin token expired/invalid → back to the admin login.
          adminAuth.logout();
        } else if (!isAdminApi && !isPublicApi && auth.isAuthenticated()) {
          if (config.demoMode()) {
            // Stale demo token (the session was reset after 1h of inactivity, or
            // the backend cycled): drop it, seed a fresh session, and RETRY this
            // request with the new token so the page recovers in place — a
            // same-URL navigate() would be a no-op and leave the visitor stuck.
            auth.clear();
            return demo.createSession().pipe(
              switchMap((ok) => {
                if (!ok) {
                  void router.navigate(['/']);
                  return throwError(() => err);
                }
                return next(req.clone({ setHeaders: { Authorization: `Bearer ${auth.token()}` } }));
              }),
            );
          }
          auth.logout(); // clears + navigates to /login
        }
      }
      return throwError(() => err);
    }),
  );
};
