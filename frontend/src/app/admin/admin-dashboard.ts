import { DecimalPipe, KeyValuePipe } from '@angular/common';
import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { TranslocoDirective, TranslocoService } from '@jsverse/transloco';
import { ChartConfiguration } from 'chart.js';

import { AdminAuthService } from '../core/admin-auth.service';
import {
  AdminAuditPage,
  AdminCustomersPage,
  AdminOverview,
  AdminPeriod,
  AdminService,
} from '../core/admin.service';
import { LanguageSwitcherComponent } from '../core/language-switcher';
import { LocaleService } from '../core/locale.service';
import { token } from '../core/tessera-tokens';
import { ChartCanvasComponent } from '../stats/chart-canvas';

type Tab = 'overview' | 'customers' | 'audit';

@Component({
  selector: 'app-admin-dashboard',
  standalone: true,
  imports: [
    DecimalPipe,
    KeyValuePipe,
    ChartCanvasComponent,
    TranslocoDirective,
    LanguageSwitcherComponent,
  ],
  templateUrl: './admin-dashboard.html',
  styleUrl: './admin-dashboard.scss',
})
export class AdminDashboardComponent implements OnInit {
  private readonly api = inject(AdminService);
  private readonly auth = inject(AdminAuthService);
  private readonly transloco = inject(TranslocoService);
  readonly locale = inject(LocaleService);

  readonly tab = signal<Tab>('overview');
  readonly periods: { value: AdminPeriod; labelKey: string }[] = [
    { value: '7d', labelKey: 'admin.periods.7d' },
    { value: '30d', labelKey: 'admin.periods.30d' },
    { value: '90d', labelKey: 'admin.periods.90d' },
  ];
  readonly period = signal<AdminPeriod>('30d');

  readonly overview = signal<AdminOverview | null>(null);
  readonly loading = signal(false);
  readonly error = signal<string | null>(null);

  readonly customers = signal<AdminCustomersPage | null>(null);
  readonly customersLoading = signal(false);
  readonly customersError = signal<string | null>(null);
  readonly customerPage = signal(1);
  private readonly perPage = 25;

  readonly audit = signal<AdminAuditPage | null>(null);
  readonly auditLoading = signal(false);
  readonly auditError = signal<string | null>(null);
  readonly auditPage = signal(1);
  private readonly auditPerPage = 50;

  /** Transloco key for the active period's label (e.g. "admin.periods.30d"). */
  readonly periodLabelKey = computed(() => `admin.periods.${this.period()}`);

  readonly customerPages = computed(() => {
    const c = this.customers();
    return c ? Math.max(1, Math.ceil(c.total / c.perPage)) : 1;
  });

  readonly auditPages = computed(() => {
    const a = this.audit();
    return a ? Math.max(1, Math.ceil(a.total / a.perPage)) : 1;
  });

  ngOnInit(): void {
    this.loadOverview();
  }

  selectTab(t: Tab): void {
    this.tab.set(t);
    if (t === 'customers' && !this.customers()) this.loadCustomers();
    if (t === 'audit' && !this.audit()) this.loadAudit();
  }

  selectPeriod(p: AdminPeriod): void {
    if (p === this.period()) return;
    this.period.set(p);
    this.loadOverview();
    if (this.customers()) {
      this.customerPage.set(1);
      this.loadCustomers();
    }
  }

  loadOverview(): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.overview(this.period()).subscribe({
      next: (d) => {
        this.overview.set(d);
        this.loading.set(false);
      },
      error: () => {
        this.loading.set(false);
        this.error.set(this.transloco.translate('admin.errors.overview'));
      },
    });
  }

  loadCustomers(): void {
    this.customersLoading.set(true);
    this.customersError.set(null);
    this.api.customers(this.customerPage(), this.perPage, this.period()).subscribe({
      next: (d) => {
        this.customers.set(d);
        this.customersLoading.set(false);
      },
      error: () => {
        this.customersLoading.set(false);
        this.customersError.set(this.transloco.translate('admin.errors.customers'));
      },
    });
  }

  changeCustomerPage(delta: number): void {
    const next = this.customerPage() + delta;
    if (next < 1 || next > this.customerPages()) return;
    this.customerPage.set(next);
    this.loadCustomers();
  }

  loadAudit(): void {
    this.auditLoading.set(true);
    this.auditError.set(null);
    this.api.audit(this.auditPage(), this.auditPerPage).subscribe({
      next: (d) => {
        this.audit.set(d);
        this.auditLoading.set(false);
      },
      error: () => {
        this.auditLoading.set(false);
        this.auditError.set(this.transloco.translate('admin.errors.audit'));
      },
    });
  }

  changeAuditPage(delta: number): void {
    const next = this.auditPage() + delta;
    if (next < 1 || next > this.auditPages()) return;
    this.auditPage.set(next);
    this.loadAudit();
  }

  logout(): void {
    this.auth.logout();
  }

  /** Format minor-unit money in its currency, or "—" when unavailable. */
  money(minor: number | null, currency: string | null): string {
    if (minor === null || !currency) return '—';
    const fmt = new Intl.NumberFormat(this.locale.lang(), { style: 'currency', currency });
    const decimals = fmt.resolvedOptions().maximumFractionDigits ?? 2;
    return fmt.format(minor / 10 ** decimals);
  }

  pct(ratio: number): string {
    return `${Math.round((ratio ?? 0) * 100)}%`;
  }

  formatDate(iso: string): string {
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? iso : d.toLocaleString(this.locale.lang());
  }

  detailText(detail: Record<string, unknown> | null): string {
    if (!detail) return '';
    return Object.entries(detail)
      .map(([k, v]) => `${k}=${v}`)
      .join(' ');
  }

  readonly scansChart = computed<ChartConfiguration | null>(() => {
    this.locale.lang();
    const d = this.overview();
    if (!d) return null;
    const s = d.usage.scansOverTime;
    if (!s.some((p) => p.scans > 0)) return null;
    return this.lineChart(
      s.map((p) => this.dayLabel(p.date)),
      s.map((p) => p.scans),
      this.transloco.translate('admin.chart.scans'),
    );
  });

  readonly signupsChart = computed<ChartConfiguration | null>(() => {
    this.locale.lang();
    const d = this.overview();
    if (!d) return null;
    const s = d.customers.signupsOverTime;
    if (!s.some((p) => p.count > 0)) return null;
    return this.lineChart(
      s.map((p) => this.dayLabel(p.date)),
      s.map((p) => p.count),
      this.transloco.translate('admin.chart.signups'),
    );
  });

  /** Locale-formatted short day label for chart x-axes. */
  private dayLabel(iso: string): string {
    const d = new Date(iso);
    return Number.isNaN(d.getTime())
      ? iso
      : d.toLocaleDateString(this.locale.lang(), { month: 'short', day: 'numeric' });
  }

  // Colours resolve from tessera tokens at render time — never hardcoded.
  private lineChart(labels: string[], data: number[], label: string): ChartConfiguration {
    return {
      type: 'line',
      data: {
        labels,
        datasets: [
          {
            label,
            data,
            borderColor: token('color-accent'),
            backgroundColor: token('color-accent-soft'),
            fill: true,
            tension: 0.25,
            pointRadius: data.length <= 31 ? 2 : 0,
            borderWidth: 2,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { intersect: false, mode: 'index' } },
        scales: {
          y: { beginAtZero: true, ticks: { precision: 0 } },
          x: { ticks: { autoSkip: true, maxTicksLimit: 8, maxRotation: 0 } },
        },
      },
    };
  }
}
