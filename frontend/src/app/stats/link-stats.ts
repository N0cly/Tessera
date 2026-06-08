import { DatePipe, DecimalPipe } from '@angular/common';
import {
  Component,
  Input,
  OnChanges,
  OnDestroy,
  OnInit,
  SimpleChanges,
  computed,
  inject,
  signal,
} from '@angular/core';
import { TranslocoDirective, TranslocoService } from '@jsverse/transloco';
import { ChartConfiguration } from 'chart.js';

import { AppConfigService } from '../core/app-config.service';
import { LocaleService } from '../core/locale.service';
import { LinkStats, LinksService } from '../core/links.service';
import { token } from '../core/tessera-tokens';
import { ChartCanvasComponent } from './chart-canvas';

type Period = 7 | 30 | 90;

@Component({
  selector: 'app-link-stats',
  standalone: true,
  imports: [ChartCanvasComponent, TranslocoDirective, DatePipe, DecimalPipe],
  templateUrl: './link-stats.html',
  styleUrl: './link-stats.scss',
})
export class LinkStatsComponent implements OnInit, OnChanges, OnDestroy {
  private readonly api = inject(LinksService);
  private readonly transloco = inject(TranslocoService);
  private readonly config = inject(AppConfigService);
  readonly locale = inject(LocaleService);

  @Input({ required: true }) linkIri!: string;

  readonly periods: Period[] = [7, 30, 90];
  readonly period = signal<Period>(30);

  readonly stats = signal<LinkStats | null>(null);
  readonly loading = signal(false);
  readonly error = signal<string | null>(null);

  /** Total shown in the header — eased toward stats().total so a fresh scan
   *  visibly counts up (the demo "watch the scan appear" moment). */
  readonly displayTotal = signal(0);
  private rafId = 0;
  private followupTimer?: ReturnType<typeof setTimeout>;

  readonly isEmpty = computed(() => {
    const s = this.stats();
    return s !== null && s.total === 0;
  });

  // Pre-built Chart.js configs derived from the latest stats. Colours are
  // pulled from tessera tokens at render time — never hardcoded.
  /** Locale-formatted short day label for chart x-axes (e.g. "Jun 8" / "8 juin"). */
  private dayLabel(iso: string): string {
    const d = new Date(iso);
    return Number.isNaN(d.getTime())
      ? iso
      : d.toLocaleDateString(this.locale.lang(), { month: 'short', day: 'numeric' });
  }

  readonly timeSeriesConfig = computed<ChartConfiguration | null>(() => {
    this.locale.lang();
    const s = this.stats();
    if (!s || s.total === 0) return null;
    const accent = token('color-accent');
    return {
      type: 'line',
      data: {
        labels: s.perDay.map((d) => this.dayLabel(d.date)),
        datasets: [
          {
            label: this.transloco.translate('stats.scansLabel'),
            data: s.perDay.map((d) => d.count),
            borderColor: accent,
            backgroundColor: token('color-accent-soft'),
            fill: true,
            tension: 0.25,
            pointRadius: s.period <= 30 ? 2 : 0,
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
              maxTicksLimit: s.period <= 30 ? 8 : 10,
              maxRotation: 0,
            },
          },
        },
      },
    };
  });

  readonly countryConfig = computed<ChartConfiguration | null>(() => {
    this.locale.lang();
    const unknown = this.transloco.translate('stats.unknown');
    return this.breakdownConfig(
      this.stats()?.byCountry.map((r) => ({ label: r.country ?? unknown, value: r.count })),
    );
  });

  readonly deviceConfig = computed<ChartConfiguration | null>(() => {
    this.locale.lang();
    const unknown = this.transloco.translate('stats.unknown');
    return this.breakdownConfig(
      this.stats()?.byDevice.map((r) => ({ label: r.device ?? unknown, value: r.count })),
    );
  });

  ngOnInit(): void {
    this.refresh();
    // In demo mode the visitor opens their code in a NEW tab (the safe
    // interstitial logs a simulated scan); when they switch back, re-pull the
    // stats so the new scan shows up and the total counts up — no manual reload.
    if (this.config.demoMode() && typeof document !== 'undefined') {
      document.addEventListener('visibilitychange', this.onVisibility);
    }
  }

  ngOnChanges(changes: SimpleChanges): void {
    if (changes['linkIri'] && !changes['linkIri'].firstChange) {
      this.refresh();
    }
  }

  ngOnDestroy(): void {
    if (typeof document !== 'undefined') {
      document.removeEventListener('visibilitychange', this.onVisibility);
    }
    clearTimeout(this.followupTimer);
    if (this.rafId) cancelAnimationFrame(this.rafId);
  }

  selectPeriod(p: Period): void {
    if (p === this.period()) return;
    this.period.set(p);
    this.refresh();
  }

  refresh(): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.stats(this.linkIri, this.period()).subscribe({
      next: (stats) => {
        this.stats.set(stats);
        this.animateTotal(stats.total);
        this.loading.set(false);
      },
      error: () => {
        this.stats.set(null);
        this.loading.set(false);
        this.error.set(this.transloco.translate('stats.loadError'));
      },
    });
  }

  /** Tab regained focus (back from the interstitial): re-pull now, then once
   *  more shortly after to absorb the async scan-worker latency. */
  private readonly onVisibility = (): void => {
    if (document.visibilityState !== 'visible') return;
    this.refresh();
    clearTimeout(this.followupTimer);
    this.followupTimer = setTimeout(() => this.refresh(), 1500);
  };

  /** Ease displayTotal toward `to`. Counts UP only; drops (period switch) snap. */
  private animateTotal(to: number): void {
    if (this.rafId) cancelAnimationFrame(this.rafId);
    const from = this.displayTotal();
    const reduce =
      typeof matchMedia !== 'undefined' && matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduce || to <= from || typeof requestAnimationFrame === 'undefined') {
      this.displayTotal.set(to);
      return;
    }
    const start = performance.now();
    const duration = 600;
    const step = (now: number): void => {
      const p = Math.min(1, (now - start) / duration);
      const eased = 1 - Math.pow(1 - p, 3); // easeOutCubic
      this.displayTotal.set(Math.round(from + (to - from) * eased));
      if (p < 1) this.rafId = requestAnimationFrame(step);
      else this.rafId = 0;
    };
    this.rafId = requestAnimationFrame(step);
  }

  private breakdownConfig(
    rows: { label: string; value: number }[] | undefined,
  ): ChartConfiguration | null {
    if (!rows || rows.length === 0) return null;
    const top = rows.slice(0, 8);
    return {
      type: 'bar',
      data: {
        labels: top.map((r) => r.label),
        datasets: [
          {
            data: top.map((r) => r.value),
            backgroundColor: token('color-accent'),
            borderRadius: 3,
          },
        ],
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: { intersect: false },
        },
        scales: {
          x: { beginAtZero: true, ticks: { precision: 0 } },
          y: { ticks: { autoSkip: false } },
        },
      },
    };
  }
}
