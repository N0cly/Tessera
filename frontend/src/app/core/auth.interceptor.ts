import { HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';

import { AdminAuthService } from './admin-auth.service';
import { AppConfigService } from './app-config.service';
import { AuthService } from './auth.service';
import { DemoService } from './demo.service';

export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const auth = inject(AuthService);
  const adminAuth = inject(AdminAuthService);
  const config = inject(AppConfigService);
  const demo = inject(DemoService);
  const router = inject(Router);

  // Admin API calls carry the admin token; everything else the user token. The
  // admin login itself is unauthenticated (it issues the token).
  const isAdminApi = req.url.includes('/admin/');
  const isAdminLogin = req.url.includes('/admin/login');
  const token = isAdminApi ? adminAuth.token() : auth.token();

  const authed =
    token && !isAdminLogin ? req.clone({ setHeaders: { Authorization: `Bearer ${token}` } }) : req;

  return next(authed).pipe(
    catchError((err) => {
      if (err.status === 401) {
        if (isAdminApi && !isAdminLogin && adminAuth.isAuthenticated()) {
          // Admin token expired/invalid → back to the admin login.
          adminAuth.logout();
        } else if (!isAdminApi && auth.isAuthenticated()) {
          if (config.demoMode()) {
            // Demo session expired/purged → drop it and seed a fresh isolated
            // one directly. We don't rely on navigating to re-trigger the guard:
            // the visitor is usually already on /app, so a navigate(['/app'])
            // is a same-URL no-op and would leave them stuck with no session.
            auth.clear();
            demo.createSession().subscribe((ok) => {
              if (ok) void router.navigate(['/app']);
            });
          } else {
            auth.logout(); // clears + navigates to /login
          }
        }
      }
      return throwError(() => err);
    }),
  );
};
