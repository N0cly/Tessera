import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { environment } from '../../environments/environment';

export type DashboardPeriod = '7d' | '30d' | '90d';

export interface DashboardOverview {
  kpis: {
    activeCodes: number;
    totalScans: number;
    periodScans: number;
    periodScansChangePct: number;
    avgScansPerCode: number;
  };
  timeSeries: { date: string; scans: number }[];
  topLinks: { slug: string; name: string | null; scans: number }[];
  byDevice: { device: string; pct: number }[];
}

@Injectable({ providedIn: 'root' })
export class DashboardService {
  private readonly http = inject(HttpClient);

  overview(period: DashboardPeriod): Observable<DashboardOverview> {
    return this.http.get<DashboardOverview>(`${environment.apiBaseUrl}/api/dashboard/overview`, {
      params: { period },
    });
  }
}
