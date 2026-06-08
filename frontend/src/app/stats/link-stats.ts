import { DatePipe, DecimalPipe } from '@angular/common';
import {
  Component,
  Input,
  OnChanges,
  OnInit,
  SimpleChanges,
  computed,
  inject,
  signal,
} from '@angular/core';
import { TranslocoDirective, TranslocoService } from '@jsverse/transloco';
import { ChartConfiguration } from 'chart.js';

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
export class LinkStatsComponent implements OnInit, OnChanges {
  private readonly api = inject(LinksService);
  private readonly transloco = inject(TranslocoService);
  readonly locale = inject(LocaleService);

  @Input({ required: true }) linkIri!: string;

  readonly periods: Period[] = [7, 30, 90];
  readonly period = signal<Period>(30);

  readonly stats = signal<LinkStats | null>(null);
  readonly loading = signal(false);
  readonly error = signal<string | null>(null);

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
  }

  ngOnChanges(changes: SimpleChanges): void {
    if (changes['linkIri'] && !changes['linkIri'].firstChange) {
      this.refresh();
    }
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
        this.loading.set(false);
      },
      error: () => {
        this.stats.set(null);
        this.loading.set(false);
        this.error.set(this.transloco.translate('stats.loadError'));
      },
    });
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
