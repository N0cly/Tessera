import { DecimalPipe } from '@angular/common';
import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { TranslocoDirective, TranslocoService } from '@jsverse/transloco';
import { ChartConfiguration } from 'chart.js';

import { AppConfigService } from '../core/app-config.service';
import { AuthService } from '../core/auth.service';
import { BillingService, SubscriptionStatus, SubscriptionSummary } from '../core/billing.service';
import { DashboardOverview, DashboardPeriod, DashboardService } from '../core/dashboard.service';
import { LanguageSwitcherComponent } from '../core/language-switcher';
import { LocaleService } from '../core/locale.service';
import { token } from '../core/tessera-tokens';
import { TourService } from '../core/tour.service';
import { ChartCanvasComponent } from '../stats/chart-canvas';

interface PeriodOption {
  value: DashboardPeriod;
  /** Translation key — resolved reactively in the template, not once in TS. */
  labelKey: string;
}

@Component({
  selector: 'app-dashboard-overview',
  standalone: true,
  imports: [
    RouterLink,
    DecimalPipe,
    ChartCanvasComponent,
    TranslocoDirective,
    LanguageSwitcherComponent,
  ],
  templateUrl: './dashboard-overview.html',
  styleUrl: './dashboard-overview.scss',
})
export class DashboardOverviewComponent implements OnInit {
  private readonly api = inject(DashboardService);
  private readonly billing = inject(BillingService);
  private readonly auth = inject(AuthService);
  private readonly route = inject(ActivatedRoute);
  private readonly transloco = inject(TranslocoService);
  readonly locale = inject(LocaleService);
  readonly config = inject(AppConfigService);
  private readonly tour = inject(TourService);

  readonly periods: PeriodOption[] = [
    { value: '7d', labelKey: 'dashboard.period.7d' },
    { value: '30d', labelKey: 'dashboard.period.30d' },
    { value: '90d', labelKey: 'dashboard.period.90d' },
  ];
  readonly period = signal<DashboardPeriod>('30d');

  readonly data = signal<DashboardOverview | null>(null);
  readonly loading = signal(false);
  readonly error = signal<string | null>(null);

  // Real subscription (billing milestone). Source of truth is the webhook —
  // the checkout return only shows a pending hint, never grants access.
  readonly subscription = signal<SubscriptionSummary | null>(null);
  readonly billingBusy = signal(false);
  readonly billingError = signal<string | null>(null);
  readonly checkoutReturned = signal(false);

  readonly planUsagePct = computed(() => {
    const s = this.subscription();
    if (!s || s.codeLimit === null || s.codeLimit <= 0) return 0;
    return Math.min(100, Math.round((s.codesUsed / s.codeLimit) * 100));
  });

  /** Human-readable subscription status for the billing section. */
  readonly statusLabel = computed(() => {
    this.locale.lang(); // re-resolve when the language changes
    const labels: Record<SubscriptionStatus, string> = {
      trialing: this.transloco.translate('dashboard.status.trialing'),
      active: this.transloco.translate('dashboard.status.active'),
      past_due: this.transloco.translate('dashboard.status.pastDue'),
      canceled: this.transloco.translate('dashboard.status.canceled'),
      expired: this.transloco.translate('dashboard.status.expired'),
    };
    const s = this.subscription();
    return s ? labels[s.status] : '';
  });

  /** Whether to offer "subscribe" (vs "manage subscription"). */
  readonly canSubscribe = computed(() => {
    const s = this.subscription();
    return !!s && s.checkoutAvailable && !s.portalAvailable && s.status !== 'active';
  });

  readonly isEmpty = computed(() => {
    const d = this.data();
    return d !== null && d.kpis.totalScans === 0;
  });

  readonly hasPeriodScans = computed(() => (this.data()?.kpis.periodScans ?? 0) > 0);

  /** Widest top-link scan count, for relative bar widths. */
  readonly topLinkMax = computed(() =>
    Math.max(1, ...(this.data()?.topLinks ?? []).map((l) => l.scans)),
  );

  // Chart.js config for the scans line. Colours resolve from tessera tokens
  // at render time — never hardcoded (tessera-design.md).
  /** Locale-formatted short day label for the chart x-axis. */
  private dayLabel(iso: string): string {
    const d = new Date(iso);
    return Number.isNaN(d.getTime())
      ? iso
      : d.toLocaleDateString(this.locale.lang(), { month: 'short', day: 'numeric' });
  }

  readonly timeSeriesConfig = computed<ChartConfiguration | null>(() => {
    this.locale.lang(); // re-render labels/tooltip label on language switch
    const d = this.data();
    if (!d || !this.hasPeriodScans()) return null;
    return {
      type: 'line',
      data: {
        labels: d.timeSeries.map((p) => this.dayLabel(p.date)),
        datasets: [
          {
            label: this.transloco.translate('dashboard.chart.scansLabel'),
            data: d.timeSeries.map((p) => p.scans),
            borderColor: token('color-accent'),
            backgroundColor: token('color-accent-soft'),
            fill: true,
            tension: 0.25,
            pointRadius: d.timeSeries.length <= 31 ? 2 : 0,
            borderWidth: 2,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: { intersect: false, mode: 'index' },
        },
        scales: {
          y: { beginAtZero: true, ticks: { precision: 0 } },
          x: {
            ticks: {
              autoSkip: true,
              maxTicksLimit: d.timeSeries.length <= 31 ? 8 : 10,
              maxRotation: 0,
            },
          },
        },
      },
    };
  });

  ngOnInit(): void {
    this.refresh();
    // First demo landing → auto-run the guided tour once per session. It polls
    // for the rendered KPIs itself, so calling before data loads is fine.
    this.tour.maybeAutoStart();
    // Only touch billing when it's enabled (off in demo / by default) — this
    // also avoids provisioning a subscription for an ephemeral demo user.
    if (this.config.billingEnabled()) {
      this.loadSubscription();
      // Coming back from the hosted checkout: show a pending hint and re-read
      // the subscription. We never grant access here — the webhook does that.
      if (this.route.snapshot.queryParamMap.get('checkout') === 'success') {
        this.checkoutReturned.set(true);
      }
    }
  }

  loadSubscription(): void {
    this.billing.subscription().subscribe({
      next: (s) => this.subscription.set(s),
      error: () => this.billingError.set(this.transloco.translate('dashboard.billing.loadError')),
    });
  }

  subscribe(): void {
    if (this.billingBusy()) return;
    this.billingBusy.set(true);
    this.billingError.set(null);
    this.billing.checkout().subscribe({
      next: ({ checkoutUrl }) => {
        window.location.href = checkoutUrl;
      },
      error: () => {
        this.billingBusy.set(false);
        this.billingError.set(this.transloco.translate('dashboard.billing.checkoutError'));
      },
    });
  }

  manageSubscription(): void {
    if (this.billingBusy()) return;
    this.billingBusy.set(true);
    this.billingError.set(null);
    this.billing.portal().subscribe({
      next: ({ portalUrl }) => {
        window.location.href = portalUrl;
      },
      error: () => {
        this.billingBusy.set(false);
        this.billingError.set(this.transloco.translate('dashboard.billing.portalError'));
      },
    });
  }

  selectPeriod(p: DashboardPeriod): void {
    if (p === this.period()) return;
    this.period.set(p);
    this.refresh();
  }

  refresh(): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.overview(this.period()).subscribe({
      next: (data) => {
        this.data.set(data);
        this.loading.set(false);
      },
      error: () => {
        this.data.set(null);
        this.loading.set(false);
        this.error.set(this.transloco.translate('dashboard.loadError'));
      },
    });
  }

  topLinkWidth(scans: number): string {
    return `${Math.round((scans / this.topLinkMax()) * 100)}%`;
  }

  deviceLabel(device: string): string {
    const map: Record<string, string> = {
      smartphone: this.transloco.translate('dashboard.device.mobile'),
      mobile: this.transloco.translate('dashboard.device.mobile'),
      phablet: this.transloco.translate('dashboard.device.mobile'),
      desktop: this.transloco.translate('dashboard.device.desktop'),
      tablet: this.transloco.translate('dashboard.device.tablet'),
      tv: this.transloco.translate('dashboard.device.tv'),
      console: this.transloco.translate('dashboard.device.console'),
      unknown: this.transloco.translate('dashboard.device.unknown'),
    };
    return map[device] ?? device.charAt(0).toUpperCase() + device.slice(1);
  }

  /** Maps a backend device value to an icon glyph key used in the template. */
  deviceIcon(device: string): 'mobile' | 'desktop' | 'tablet' | 'other' {
    if (device === 'smartphone' || device === 'mobile' || device === 'phablet') return 'mobile';
    if (device === 'desktop') return 'desktop';
    if (device === 'tablet') return 'tablet';
    return 'other';
  }

  /** Replay the guided tour from the header button (demo only). */
  startTour(): void {
    this.tour.start();
  }

  logout(): void {
    this.auth.logout();
  }
}
