import { Component, OnDestroy, OnInit, computed, inject, signal } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { TranslocoDirective, TranslocoService } from '@jsverse/transloco';

import { AppConfigService } from '../core/app-config.service';
import { AuthService } from '../core/auth.service';
import { BillingService } from '../core/billing.service';
import { LanguageSwitcherComponent } from '../core/language-switcher';
import { LocaleService } from '../core/locale.service';
import { withLocalePrefix } from '../core/locale';
import { PricingPlan, PricingService } from '../core/pricing.service';
import { SeoService } from '../core/seo.service';

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
  imports: [RouterLink, TranslocoDirective, LanguageSwitcherComponent],
  templateUrl: './pricing.html',
  styleUrl: './pricing.scss',
})
export class PricingComponent implements OnInit, OnDestroy {
  private readonly pricing = inject(PricingService);
  private readonly billing = inject(BillingService);
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly transloco = inject(TranslocoService);
  private readonly seo = inject(SeoService);
  readonly locale = inject(LocaleService);
  /** Flag-driven entry: in demo mode the dashboard CTAs become "See the demo". */
  readonly config = inject(AppConfigService);

  private readonly seoSub = this.seo.apply('pricing');

  /** Locale-prefixed internal path for marketing links (keeps /fr, /es, … space). */
  localeLink(path: string): string {
    return withLocalePrefix(this.locale.lang(), path);
  }

  readonly githubUrl = 'https://github.com/N0cly/Tessera';

  readonly plans = signal<PricingPlan[] | null>(null);
  readonly loading = signal(true);
  readonly loadError = signal(false);
  /** The plan key currently being sent to checkout, or null. */
  readonly checkoutBusy = signal<string | null>(null);
  readonly checkoutError = signal<string | null>(null);

  // Feature lists are app copy (NOT prices). Unbuilt Pro features are tagged
  // `soon` so the UI marks them "coming soon" instead of selling vapor
  // (tessera-pricing-page.md, out-of-scope section). Built as getters so the
  // text resolves against the active language at read time.
  private get starterFeatures(): PlanFeature[] {
    return [
      { text: this.transloco.translate('pricing.features.starter.core') },
      { text: this.transloco.translate('pricing.features.starter.analytics') },
      { text: this.transloco.translate('pricing.features.starter.euHosting') },
    ];
  }
  private get proFeatures(): PlanFeature[] {
    return [
      { text: this.transloco.translate('pricing.features.pro.everythingStarter') },
      { text: this.transloco.translate('pricing.features.pro.customDomain'), soon: true },
      { text: this.transloco.translate('pricing.features.pro.branding'), soon: true },
      { text: this.transloco.translate('pricing.features.pro.team'), soon: true },
      { text: this.transloco.translate('pricing.features.pro.prioritySupport') },
    ];
  }

  // Self-host is genuinely free & open-source — not a Paddle price — so its
  // card content is static (the "no hardcoded prices" rule is about the paid
  // plans, whose amounts must come from Paddle).
  get selfHostFeatures(): PlanFeature[] {
    return [
      { text: this.transloco.translate('pricing.features.selfHost.unlimited') },
      { text: this.transloco.translate('pricing.features.selfHost.forever') },
      { text: this.transloco.translate('pricing.features.selfHost.openSource') },
      { text: this.transloco.translate('pricing.features.selfHost.yourData') },
    ];
  }

  readonly paidCards = computed<PaidCard[]>(() => {
    this.locale.lang(); // re-resolve feature text when the language changes
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

  ngOnDestroy(): void {
    this.seoSub.unsubscribe();
  }

  /** True when we have a real, Paddle-sourced price to show. */
  isPriced(p: PricingPlan): boolean {
    return p.available && p.amount !== null && p.currency !== null;
  }

  /** Format a minor-unit amount in its currency, using the active language. */
  formatPrice(minor: number, currency: string): string {
    const fmt = new Intl.NumberFormat(this.locale.lang(), { style: 'currency', currency });
    const decimals = fmt.resolvedOptions().maximumFractionDigits ?? 2;
    return fmt.format(minor / 10 ** decimals);
  }

  intervalLabel(interval: string | null): string {
    switch (interval) {
      case 'month':
        return this.transloco.translate('pricing.interval.month');
      case 'year':
        return this.transloco.translate('pricing.interval.year');
      case 'week':
        return this.transloco.translate('pricing.interval.week');
      case 'day':
        return this.transloco.translate('pricing.interval.day');
      default:
        return '';
    }
  }

  /** Short badge text for an active promo, e.g. "−20%" or "−1.00 €". */
  promoBadge(p: PricingPlan): string | null {
    if (!p.promo) return null;
    if (p.promo.type === 'percentage') {
      const n = p.promo.amount;
      const formatted = new Intl.NumberFormat(this.locale.lang(), {
        maximumFractionDigits: 1,
      }).format(n);
      return `−${formatted}%`;
    }
    if (p.currency) return `−${this.formatPrice(p.promo.amount, p.currency)}`;
    return this.transloco.translate('pricing.promo.badge');
  }

  promoEnds(p: PricingPlan): string | null {
    if (!p.promo?.endsAt) return null;
    const d = new Date(p.promo.endsAt);
    return Number.isNaN(d.getTime()) ? null : d.toLocaleDateString(this.locale.lang());
  }

  codeLimitLabel(limit: number | null): string {
    return limit === null
      ? this.transloco.translate('pricing.codeLimit.unlimited')
      : this.transloco.translate('pricing.codeLimit.upTo', {
          count: limit.toLocaleString(this.locale.lang()),
        });
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
        this.checkoutError.set(this.transloco.translate('pricing.checkoutError'));
      },
    });
  }
}
