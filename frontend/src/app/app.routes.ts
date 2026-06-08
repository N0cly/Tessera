import { Routes } from '@angular/router';

import { adminGuard } from './core/admin.guard';
import { authGuard } from './core/auth.guard';
import { localeRouteGuard } from './core/locale-route.guard';

const landing = () => import('./landing/landing').then((m) => m.LandingComponent);
const pricing = () => import('./pricing/pricing').then((m) => m.PricingComponent);

// SEO: the marketing pages are also reachable at locale-prefixed URLs (/fr,
// /es/pricing, …). The guard applies the route's language; hreflang tags
// (SeoService) link the variants. English is the canonical (unprefixed) one.
// App/admin pages stay unprefixed — runtime switching is enough (CLAUDE.md i18n).
const localizedPublic: Routes = (['fr', 'es', 'it', 'de'] as const).flatMap((lang) => [
  {
    path: `${lang}/pricing`,
    canActivate: [localeRouteGuard],
    data: { lang, page: 'pricing' },
    loadComponent: pricing,
  },
  {
    path: lang,
    canActivate: [localeRouteGuard],
    data: { lang, page: 'landing' },
    loadComponent: landing,
  },
]);

export const routes: Routes = [
  {
    path: '',
    pathMatch: 'full',
    canActivate: [localeRouteGuard],
    data: { page: 'landing' },
    loadComponent: landing,
  },
  {
    path: 'pricing',
    canActivate: [localeRouteGuard],
    data: { page: 'pricing' },
    loadComponent: pricing,
  },
  ...localizedPublic,
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
