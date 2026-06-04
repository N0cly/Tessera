import { Routes } from '@angular/router';

import { authGuard } from './core/auth.guard';

export const routes: Routes = [
  {
    path: '',
    pathMatch: 'full',
    loadComponent: () => import('./landing/landing').then((m) => m.LandingComponent),
  },
  {
    path: 'login',
    loadComponent: () => import('./login/login').then((m) => m.LoginComponent),
  },
  {
    path: 'app',
    canActivate: [authGuard],
    loadComponent: () =>
      import('./dashboard/dashboard-overview').then((m) => m.DashboardOverviewComponent),
  },
  {
    path: 'app/links',
    canActivate: [authGuard],
    loadComponent: () => import('./links/links').then((m) => m.LinksComponent),
  },
  { path: '**', redirectTo: '' },
];
