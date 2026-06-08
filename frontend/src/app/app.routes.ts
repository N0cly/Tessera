import { Routes } from '@angular/router';

import { adminGuard } from './core/admin.guard';
import { authGuard } from './core/auth.guard';

export const routes: Routes = [
  {
    path: '',
    pathMatch: 'full',
    loadComponent: () => import('./landing/landing').then((m) => m.LandingComponent),
  },
  {
    path: 'pricing',
    loadComponent: () => import('./pricing/pricing').then((m) => m.PricingComponent),
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
  {
    path: 'admin/login',
    loadComponent: () => import('./admin/admin-login').then((m) => m.AdminLoginComponent),
  },
  {
    path: 'admin',
    canActivate: [adminGuard],
    loadComponent: () => import('./admin/admin-dashboard').then((m) => m.AdminDashboardComponent),
  },
  { path: '**', redirectTo: '' },
];
