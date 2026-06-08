import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { environment } from '../../environments/environment';

export type AdminPeriod = '7d' | '30d' | '90d';

export interface AdminBusiness {
  available: boolean;
  currency: string | null;
  /** Monthly recurring revenue in minor units (e.g. 30000 = 300.00). Null when unavailable or mixed-currency. */
  mrr: number | null;
  /** True when active subscriptions span multiple currencies (MRR not summable). */
  mixedCurrency: boolean;
  activeSubscriptions: number;
  trialing: number;
  pastDue: number;
  canceled: number;
  byPlan: Record<string, number>;
  /** Ratios in [0, 1]. */
  trialConversionRate: number;
  churnRateLast30d: number;
}

export interface AdminTimePoint {
  date: string;
  scans?: number;
  count?: number;
}

export interface AdminOverview {
  period: AdminPeriod;
  business: AdminBusiness;
  usage: {
    totalLinks: number;
    totalScans: number;
    periodScans: number;
    activeCodes: number;
    scansOverTime: { date: string; scans: number }[];
  };
  customers: {
    total: number;
    churned: number;
    byPlan: Record<string, number>;
    byStatus: Record<string, number>;
    signupsOverTime: { date: string; count: number }[];
  };
}

export interface AdminCustomer {
  email: string;
  createdAt: string;
  plan: string | null;
  status: string | null;
  codeCount: number;
}

export interface AdminCustomersPage {
  customers: AdminCustomer[];
  total: number;
  page: number;
  perPage: number;
  topByUsage: { email: string; links: number; scans: number }[];
}

export interface AdminAuditEntry {
  at: string;
  actorEmail: string;
  action: string;
  success: boolean;
  ip: string | null;
  detail: Record<string, unknown> | null;
}

export interface AdminAuditPage {
  entries: AdminAuditEntry[];
  total: number;
  page: number;
  perPage: number;
}

@Injectable({ providedIn: 'root' })
export class AdminService {
  private readonly http = inject(HttpClient);
  private readonly base = `${environment.apiBaseUrl}/admin`;

  overview(period: AdminPeriod): Observable<AdminOverview> {
    return this.http.get<AdminOverview>(`${this.base}/overview`, { params: { period } });
  }

  /** Customer PII — backend audit-logs every call. Loaded only on demand. */
  customers(page = 1, perPage = 25, period: AdminPeriod = '30d'): Observable<AdminCustomersPage> {
    return this.http.get<AdminCustomersPage>(`${this.base}/customers`, {
      params: { page, perPage, period },
    });
  }

  audit(page = 1, perPage = 50): Observable<AdminAuditPage> {
    return this.http.get<AdminAuditPage>(`${this.base}/audit`, { params: { page, perPage } });
  }
}
