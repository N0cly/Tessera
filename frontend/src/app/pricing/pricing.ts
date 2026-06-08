import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { Router, RouterLink } from '@angular/router';

import { AuthService } from '../core/auth.service';
import { BillingService } from '../core/billing.service';
import { PricingPlan, PricingService } from '../core/pricing.service';

interface PlanFeature {
  text: string;
  /** Tagged "coming soon" — a feature not built yet; we mark it, never sell it. */
  soon?: boolean;
}

interface PaidCard {
  data: PricingPlan;
  features: PlanFeature[];
}

@Component({
  selector: 'app-pricing',
  standalone: true,
  imports: [RouterLink],
  templateUrl: './pricing.html',
  styleUrl: './pricing.scss',
})
export class PricingComponent implements OnInit {
  private readonly pricing = inject(PricingService);
  private readonly billing = inject(BillingService);
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  readonly githubUrl = 'https://github.com/N0cly/Tessera';

  readonly plans = signal<PricingPlan[] | null>(null);
  readonly loading = signal(true);
  readonly loadError = signal(false);
  /** The plan key currently being sent to checkout, or null. */
  readonly checkoutBusy = signal<string | null>(null);
  readonly checkoutError = signal<string | null>(null);

  // Feature lists are app copy (NOT prices). Unbuilt Pro features are tagged
  // `soon` so the UI marks them "coming soon" instead of selling vapor
  // (tessera-pricing-page.md, out-of-scope section).
  private readonly starterFeatures: PlanFeature[] = [
    { text: 'Full open-source core' },
    { text: 'Complete scan analytics' },
    { text: 'Fallback URL when a subscription lapses' },
    { text: 'EU hosting' },
  ];
  private readonly proFeatures: PlanFeature[] = [
    { text: 'Everything in Starter' },
    { text: 'Custom domain', soon: true },
    { text: 'QR branding (logo & colours)', soon: true },
    { text: 'Team members', soon: true },
    { text: 'Priority support' },
  ];

  // Self-host is genuinely free & open-source — not a Paddle price — so its
  // card content is static (the "no hardcoded prices" rule is about the paid
  // plans, whose amounts must come from Paddle).
  readonly selfHostFeatures: PlanFeature[] = [
    { text: 'Unlimited codes' },
    { text: 'The full core, forever' },
    { text: 'Open source (MIT)' },
    { text: 'Your data, your server' },
  ];

  readonly paidCards = computed<PaidCard[]>(() => {
    const list = this.plans();
    if (!list) return [];
    const features: Record<string, PlanFeature[]> = {
      starter: this.starterFeatures,
      pro: this.proFeatures,
    };
    return (['starter', 'pro'] as const)
      .map((key) => list.find((p) => p.plan === key))
      .filter((p): p is PricingPlan => !!p)
      .map((p) => ({ data: p, features: features[p.plan] ?? [] }));
  });

  ngOnInit(): void {
    this.pricing.pricing().subscribe({
      next: (res) => {
        this.plans.set(res.plans ?? []);
        this.loading.set(false);
      },
      error: () => {
        this.loadError.set(true);
        this.loading.set(false);
      },
    });
  }

  /** True when we have a real, Paddle-sourced price to show. */
  isPriced(p: PricingPlan): boolean {
    return p.available && p.amount !== null && p.currency !== null;
  }

  /** Format a minor-unit amount in its currency, using the visitor's locale. */
  formatPrice(minor: number, currency: string): string {
    const fmt = new Intl.NumberFormat(undefined, { style: 'currency', currency });
    const decimals = fmt.resolvedOptions().maximumFractionDigits ?? 2;
    return fmt.format(minor / 10 ** decimals);
  }

  intervalLabel(interval: string | null): string {
    switch (interval) {
      case 'month':
        return '/mo';
      case 'year':
        return '/yr';
      case 'week':
        return '/wk';
      case 'day':
        return '/day';
      default:
        return '';
    }
  }

  /** Short badge text for an active promo, e.g. "−20%" or "−1.00 €". */
  promoBadge(p: PricingPlan): string | null {
    if (!p.promo) return null;
    if (p.promo.type === 'percentage') {
      const n = p.promo.amount;
      return `−${Number.isInteger(n) ? n : n.toFixed(1)}%`;
    }
    if (p.currency) return `−${this.formatPrice(p.promo.amount, p.currency)}`;
    return 'Promo';
  }

  promoEnds(p: PricingPlan): string | null {
    if (!p.promo?.endsAt) return null;
    const d = new Date(p.promo.endsAt);
    return Number.isNaN(d.getTime()) ? null : d.toLocaleDateString();
  }

  codeLimitLabel(limit: number | null): string {
    return limit === null ? 'Unlimited codes' : `Up to ${limit.toLocaleString()} codes`;
  }

  startTrial(plan: 'starter' | 'pro'): void {
    // The 14-day trial needs an account but no card: a logged-out visitor signs
    // up first (the trial auto-starts at registration); a logged-in user goes
    // straight to the hosted checkout for the chosen plan.
    if (!this.auth.isAuthenticated()) {
      void this.router.navigate(['/login'], { queryParams: { plan } });
      return;
    }
    if (this.checkoutBusy()) return;
    this.checkoutBusy.set(plan);
    this.checkoutError.set(null);
    this.billing.checkout(plan).subscribe({
      next: ({ checkoutUrl }) => {
        window.location.href = checkoutUrl;
      },
      error: () => {
        this.checkoutBusy.set(null);
        this.checkoutError.set('Checkout is unavailable right now. Please try again later.');
      },
    });
  }
}
