import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';

import { AdminAuthService } from './admin-auth.service';

/**
 * UI gate for the admin panel. This only hides the screen — the server
 * authorizes every admin request independently (never trust the client).
 */
export const adminGuard: CanActivateFn = () => {
  const admin = inject(AdminAuthService);
  const router = inject(Router);
  if (admin.isAuthenticated()) return true;
  void router.navigate(['/admin/login']);
  return false;
};
