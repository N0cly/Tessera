import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { environment } from '../../environments/environment';

/** An active, time-limited promotion read from Paddle. */
export interface PricingPromo {
  /** "percentage" → `amount` is a percent; "flat" → `amount` is minor units. */
  type: 'percentage' | 'flat';
  amount: number;
  label: string | null;
  endsAt: string | null;
  /** The discounted price in minor units — exactly what Paddle charges. */
  finalAmount: number;
}

/**
 * A paid plan, priced live from Paddle. `amount`/`currency` are null and
 * `available` is false when Paddle couldn't be read (fail-safe: we never render
 * a wrong number) or when this instance has no Paddle price configured
 * (`priceId` is then null too — a pure self-host instance).
 */
export interface PricingPlan {
  plan: 'starter' | 'pro';
  name: string;
  priceId: string | null;
  /** Price in minor units (e.g. 300 = 3.00). */
  amount: number | null;
  currency: string | null;
  interval: string | null;
  /** Plan code limit; null means unlimited. Sourced from app config, not Paddle. */
  codeLimit: number | null;
  available: boolean;
  promo: PricingPromo | null;
}

export interface PricingResponse {
  plans: PricingPlan[];
}

@Injectable({ providedIn: 'root' })
export class PricingService {
  private readonly http = inject(HttpClient);

  /** Public catalogue — no auth required. */
  pricing(): Observable<PricingResponse> {
    return this.http.get<PricingResponse>(`${environment.apiBaseUrl}/api/pricing`);
  }
}
