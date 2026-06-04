import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { environment } from '../../environments/environment';

export type SubscriptionStatus =
  | 'trialing'
  | 'active'
  | 'past_due'
  | 'canceled'
  | 'expired';

export interface SubscriptionSummary {
  plan: string;
  planName: string;
  status: SubscriptionStatus;
  trialEndsAt: string | null;
  trialDaysLeft: number;
  currentPeriodEndsAt: string | null;
  codesUsed: number;
  /** null means unlimited. */
  codeLimit: number | null;
  /** Grace window (days) after a lapse before codes switch to fallback. */
  graceDays: number;
  checkoutAvailable: boolean;
  portalAvailable: boolean;
}

@Injectable({ providedIn: 'root' })
export class BillingService {
  private readonly http = inject(HttpClient);
  private readonly base = `${environment.apiBaseUrl}/api/billing`;

  subscription(): Observable<SubscriptionSummary> {
    return this.http.get<SubscriptionSummary>(`${this.base}/subscription`);
  }

  /** Start a hosted checkout; returns the MoR checkout URL to redirect to. */
  checkout(): Observable<{ checkoutUrl: string }> {
    return this.http.post<{ checkoutUrl: string }>(`${this.base}/checkout`, {});
  }

  /** Open the MoR customer portal; returns its URL. */
  portal(): Observable<{ portalUrl: string }> {
    return this.http.post<{ portalUrl: string }>(`${this.base}/portal`, {});
  }
}
