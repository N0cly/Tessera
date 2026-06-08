import { Injectable, inject } from '@angular/core';
import { Router } from '@angular/router';
import { TranslocoService } from '@jsverse/transloco';
import { type Driver, type DriveStep, driver } from 'driver.js';

import { AppConfigService } from './app-config.service';

/** One ordered tour step: where it lives, what it points at, and how to reveal it. */
interface TourStep {
  /** Navigate here before showing (skipped if already on it). */
  route?: string;
  /** Element to highlight. Omit for a centered popover (the closing CTA). */
  selector?: string;
  /** Transloco keys under the `tour.` namespace. */
  titleKey: string;
  textKey: string;
  /** Extra HTML appended to the description (e.g. the self-host link). */
  html?: () => string;
  /** Reveal/act before the highlight — clicks real UI (expand stats, open edit). */
  prepare?: () => Promise<void> | void;
  side?: 'top' | 'bottom' | 'left' | 'right' | 'over';
  align?: 'start' | 'center' | 'end';
}

/** The newest link is prepended to the list, so the first card is "your" code. */
const FIRST_CARD = '.list ul li.card:first-child';

/**
 * Guided, data-driven product tour for the demo (tessera-demo-experience.md).
 *
 * Uses driver.js (MIT). Each step is a selector + i18n key; the arc walks the
 * overview, a code's analytics, editing a destination and the QR, then finishes
 * HANDS-ON: the visitor creates their own code, opens it (the demo interstitial
 * logs a simulated scan), watches the scan appear, repoints the destination and
 * opens it again — before a self-host CTA. Skippable (the X) and replayable (the
 * "Take the tour" button); auto-starts once per demo session.
 */
@Injectable({ providedIn: 'root' })
export class TourService {
  private readonly router = inject(Router);
  private readonly transloco = inject(TranslocoService);
  private readonly config = inject(AppConfigService);

  /** Per-session "already seen" marker so the auto-start fires only once. */
  private static readonly DONE_KEY = 'tessera.tour.done';

  private driverObj: Driver | null = null;
  private steps: TourStep[] = [];
  private running = false;

  /** Auto-start on first demo dashboard landing (once per session). */
  maybeAutoStart(): void {
    if (this.seen()) return;
    this.start();
  }

  /** Start (or replay) the tour. No-op outside demo mode or while one runs. */
  start(): void {
    if (!this.config.demoMode() || this.running) return;
    void this.run();
  }

  // ── orchestration ────────────────────────────────────────────────────────

  private async run(): Promise<void> {
    this.running = true;
    try {
      this.steps = this.buildSteps();
      const reduce =
        typeof matchMedia !== 'undefined' && matchMedia('(prefers-reduced-motion: reduce)').matches;

      const driveSteps: DriveStep[] = this.steps.map((s) => ({
        element: s.selector,
        popover: {
          title: this.t(s.titleKey),
          description: this.describe(s),
          side: s.side ?? 'bottom',
          align: s.align ?? 'start',
        },
      }));

      this.driverObj = driver({
        showProgress: true,
        // The X closes the tour; overlay-click / ESC do not, so a stray click
        // mid-interaction never kills it.
        allowClose: false,
        animate: !reduce,
        smoothScroll: !reduce,
        overlayOpacity: 0.55,
        stagePadding: 6,
        stageRadius: 10,
        popoverClass: 'tessera-tour',
        showButtons: ['next', 'close'],
        nextBtnText: this.t('next'),
        doneBtnText: this.t('done'),
        steps: driveSteps,
        onNextClick: () => void this.advance(),
        onCloseClick: () => this.driverObj?.destroy(),
        onDestroyed: () => this.finish(),
      });

      await this.prepareStep(0);
      this.driverObj.drive();
    } catch {
      this.driverObj?.destroy();
      this.finish();
    }
  }

  /** Advance one step, doing any async prep (navigate / reveal) first. */
  private async advance(): Promise<void> {
    const d = this.driverObj;
    if (!d) return;
    if (d.isLastStep()) {
      d.destroy(); // → onDestroyed → finish()
      return;
    }
    const next = (d.getActiveIndex() ?? 0) + 1;
    await this.prepareStep(next);
    d.moveNext();
  }

  private async prepareStep(i: number): Promise<void> {
    const s = this.steps[i];
    if (!s) return;
    if (s.route) await this.go(s.route);
    if (s.prepare) await s.prepare();
    if (s.selector) await this.waitFor(s.selector);
  }

  private finish(): void {
    this.markSeen();
    this.running = false;
    this.driverObj = null;
  }

  // ── step list ────────────────────────────────────────────────────────────

  private buildSteps(): TourStep[] {
    return [
      // Guided — on the pre-seeded showcase data.
      {
        route: '/app',
        selector: '[data-tour="kpis"]',
        titleKey: 'steps.kpis.title',
        textKey: 'steps.kpis.text',
        side: 'bottom',
      },
      {
        route: '/app',
        selector: '[data-tour="scans-chart"]',
        titleKey: 'steps.chart.title',
        textKey: 'steps.chart.text',
        side: 'top',
      },
      {
        route: '/app',
        selector: '[data-tour="breakdowns"]',
        titleKey: 'steps.breakdowns.title',
        textKey: 'steps.breakdowns.text',
        side: 'top',
      },
      {
        route: '/app/links',
        selector: `${FIRST_CARD} [data-tour="stats-panel"]`,
        titleKey: 'steps.analytics.title',
        textKey: 'steps.analytics.text',
        side: 'top',
        prepare: () => this.expandFirstStats(),
      },
      {
        route: '/app/links',
        selector: `${FIRST_CARD} [data-tour="edit-button"]`,
        titleKey: 'steps.edit.title',
        textKey: 'steps.edit.text',
        side: 'top',
      },
      {
        route: '/app/links',
        selector: `${FIRST_CARD} [data-tour="qr-preview"]`,
        titleKey: 'steps.qr.title',
        textKey: 'steps.qr.text',
        side: 'left',
      },

      // Hands-on — the visitor's own code.
      {
        route: '/app/links',
        selector: '[data-tour="create-form"]',
        titleKey: 'steps.create.title',
        textKey: 'steps.create.text',
        side: 'bottom',
        prepare: () => this.collapseFirstStats(),
      },
      {
        selector: `${FIRST_CARD} [data-tour="qr-preview"]`,
        titleKey: 'steps.yourQr.title',
        textKey: 'steps.yourQr.text',
        side: 'left',
      },
      {
        selector: `${FIRST_CARD} [data-tour="open-code"]`,
        titleKey: 'steps.openCode.title',
        textKey: 'steps.openCode.text',
        side: 'bottom',
      },
      {
        selector: `${FIRST_CARD} [data-tour="scan-total"]`,
        titleKey: 'steps.watchScan.title',
        textKey: 'steps.watchScan.text',
        side: 'top',
        prepare: () => this.expandFirstStats(),
      },
      {
        selector: FIRST_CARD,
        titleKey: 'steps.changeDest.title',
        textKey: 'steps.changeDest.text',
        side: 'top',
        prepare: () => this.prepareChangeDest(),
      },
      {
        selector: `${FIRST_CARD} [data-tour="open-code"]`,
        titleKey: 'steps.openAgain.title',
        textKey: 'steps.openAgain.text',
        side: 'bottom',
      },

      // Close — self-host CTA (centered, no element).
      {
        titleKey: 'steps.finish.title',
        textKey: 'steps.finish.text',
        html: () =>
          `<a href="${this.config.githubUrl()}" target="_blank" rel="noopener">${this.t('steps.finish.cta')}</a>`,
      },
    ];
  }

  // ── DOM helpers (drive the real UI so the highlight lands on live state) ──

  private async expandFirstStats(): Promise<void> {
    // After a cross-route jump the list is still loading — wait for a card to
    // exist before clicking its toggle, or the click is a silent no-op.
    if (!(await this.waitFor(FIRST_CARD))) return;
    if (this.q(`${FIRST_CARD} [data-tour="stats-panel"]`)) return;
    this.q(`${FIRST_CARD} [data-tour="toggle-stats"]`)?.click();
    await this.waitFor(`${FIRST_CARD} [data-tour="stats-panel"]`);
  }

  private collapseFirstStats(): void {
    if (this.q(`${FIRST_CARD} [data-tour="stats-panel"]`)) {
      this.q(`${FIRST_CARD} [data-tour="toggle-stats"]`)?.click();
    }
  }

  private async prepareChangeDest(): Promise<void> {
    if (!(await this.waitFor(FIRST_CARD))) return;
    this.collapseFirstStats();
    if (this.q(`${FIRST_CARD} [data-tour="edit-field"]`)) return;
    this.q(`${FIRST_CARD} [data-tour="edit-button"]`)?.click();
    await this.waitFor(`${FIRST_CARD} [data-tour="edit-field"]`);
  }

  private async go(route: string): Promise<void> {
    if (this.router.url.split(/[?#]/)[0] === route) return;
    await this.router.navigateByUrl(route);
    await this.delay(60); // let the new view render before we poll for it
  }

  private waitFor(selector: string, timeoutMs = 5000): Promise<HTMLElement | null> {
    const existing = this.q(selector);
    if (existing) return Promise.resolve(existing);
    return new Promise((resolve) => {
      const start = performance.now();
      const id = setInterval(() => {
        const el = this.q(selector);
        if (el || performance.now() - start > timeoutMs) {
          clearInterval(id);
          resolve(el);
        }
      }, 80);
    });
  }

  private q(selector: string): HTMLElement | null {
    return document.querySelector<HTMLElement>(selector);
  }

  private delay(ms: number): Promise<void> {
    return new Promise((r) => setTimeout(r, ms));
  }

  // ── i18n + persistence ───────────────────────────────────────────────────

  private t(key: string): string {
    return this.transloco.translate(`tour.${key}`);
  }

  private describe(s: TourStep): string {
    const text = this.t(s.textKey);
    return s.html ? `${text}<p class="tour-cta">${s.html()}</p>` : text;
  }

  private seen(): boolean {
    try {
      return sessionStorage.getItem(TourService.DONE_KEY) === '1';
    } catch {
      return false;
    }
  }

  private markSeen(): void {
    try {
      sessionStorage.setItem(TourService.DONE_KEY, '1');
    } catch {
      /* storage unavailable (private mode) — tour simply auto-starts next nav */
    }
  }
}
