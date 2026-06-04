import { DecimalPipe } from '@angular/common';
import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { ChartConfiguration } from 'chart.js';

import { AuthService } from '../core/auth.service';
import { BillingService, SubscriptionStatus, SubscriptionSummary } from '../core/billing.service';
import {
  DashboardOverview,
  DashboardPeriod,
  DashboardService,
} from '../core/dashboard.service';
import { token } from '../core/tessera-tokens';
import { ChartCanvasComponent } from '../stats/chart-canvas';

interface PeriodOption {
  value: DashboardPeriod;
  label: string;
}

@Component({
  selector: 'app-dashboard-overview',
  standalone: true,
  imports: [RouterLink, DecimalPipe, ChartCanvasComponent],
  templateUrl: './dashboard-overview.html',
  styleUrl: './dashboard-overview.scss',
})
export class DashboardOverviewComponent implements OnInit {
  private readonly api = inject(DashboardService);
  private readonly billing = inject(BillingService);
  private readonly auth = inject(AuthService);
  private readonly route = inject(ActivatedRoute);

  readonly periods: PeriodOption[] = [
    { value: '7d', label: '7 j' },
    { value: '30d', label: '30 j' },
    { value: '90d', label: '90 j' },
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
    const labels: Record<SubscriptionStatus, string> = {
      trialing: 'Trialing',
      active: 'Active',
      past_due: 'Past due',
      canceled: 'Canceled',
      expired: 'Expired',
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
  readonly timeSeriesConfig = computed<ChartConfiguration | null>(() => {
    const d = this.data();
    if (!d || !this.hasPeriodScans()) return null;
    return {
      type: 'line',
      data: {
        labels: d.timeSeries.map((p) => p.date),
        datasets: [
          {
            label: 'Scans',
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
    this.loadSubscription();
    // Coming back from the hosted checkout: show a pending hint and re-read the
    // subscription. We never grant access here — the webhook does that.
    if (this.route.snapshot.queryParamMap.get('checkout') === 'success') {
      this.checkoutReturned.set(true);
    }
  }

  loadSubscription(): void {
    this.billing.subscription().subscribe({
      next: (s) => this.subscription.set(s),
      error: () => this.billingError.set('Could not load billing details.'),
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
        this.billingError.set('Checkout is unavailable right now. Please try again later.');
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
        this.billingError.set('Could not open the customer portal. Please try again later.');
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
        this.error.set('Could not load the overview.');
      },
    });
  }

  topLinkWidth(scans: number): string {
    return `${Math.round((scans / this.topLinkMax()) * 100)}%`;
  }

  deviceLabel(device: string): string {
    const map: Record<string, string> = {
      smartphone: 'Mobile',
      mobile: 'Mobile',
      phablet: 'Mobile',
      desktop: 'Desktop',
      tablet: 'Tablet',
      tv: 'TV',
      console: 'Console',
      unknown: 'Unknown',
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

  logout(): void {
    this.auth.logout();
  }
}
