import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { map } from 'rxjs';

import { AppConfigService } from './app-config.service';
import { AuthService } from './auth.service';
import { DemoService } from './demo.service';

export const authGuard: CanActivateFn = () => {
  const auth = inject(AuthService);
  const router = inject(Router);
  const config = inject(AppConfigService);
  const demo = inject(DemoService);

  if (auth.isAuthenticated()) return true;

  // In demo mode, entering the app seeds an isolated ephemeral session instead
  // of sending the visitor to a (non-existent) login.
  if (config.demoMode()) {
    return demo.ensureSession().pipe(map((ok) => (ok ? true : router.parseUrl('/'))));
  }

  void router.navigate(['/login']);
  return false;
};
