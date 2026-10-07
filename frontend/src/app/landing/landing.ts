import {
  AfterViewInit,
  Component,
  ElementRef,
  OnDestroy,
  ViewChild,
  computed,
  effect,
  inject,
  signal,
} from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslocoDirective, TranslocoService } from '@jsverse/transloco';
import QRCode from 'qrcode';

import { AppConfigService } from '../core/app-config.service';
import { LanguageSwitcherComponent } from '../core/language-switcher';
import { LocaleService } from '../core/locale.service';
import { withLocalePrefix } from '../core/locale';
import { SeoService } from '../core/seo.service';
import { token } from '../core/tessera-tokens';

interface DemoCase {
  key: 'nocly' | 'bongiorno' | 'dbsky';
  label: string;
  emoji: string;
  destination: string;
  blurb: string;
  fakeShortUrl?: string;
}

@Component({
  selector: 'app-landing',
  standalone: true,
  imports: [RouterLink, TranslocoDirective, LanguageSwitcherComponent],
  templateUrl: './landing.html',
  styleUrl: './landing.scss',
})
export class LandingComponent implements AfterViewInit, OnDestroy {
  private readonly transloco = inject(TranslocoService);
  private readonly locale = inject(LocaleService);
  private readonly seo = inject(SeoService);
  /** Flag-driven entry: in demo mode the dashboard CTAs become "See the demo". */
  readonly config = inject(AppConfigService);
  private readonly seoSub = this.seo.apply('landing');

  /** Locale-prefixed internal path for marketing links (keeps /fr, /es, … space). */
  localeLink(path: string): string {
    return withLocalePrefix(this.locale.lang(), path);
  }

  @ViewChild('qrCanvas', { static: false }) qrCanvasRef?: ElementRef<HTMLCanvasElement>;

  // Fallback when the instance exposes no showcase link (self-host, or a host
  // missing from DEMO_REDIRECT_ALLOWLIST): fake but believable, never scanned.
  private readonly fakeShortUrl = 'https://qr.example.com/r/mZ4kPx7';

  // Decorative emoji + (fake) destination are data, not copy — they stay as-is.
  // Labels and blurbs are translated; they re-resolve when the language changes.
  private readonly caseData: Pick<DemoCase, 'key' | 'emoji' | 'destination' | 'fakeShortUrl'>[] = [
    {
      key: 'nocly',
      emoji: '💻',
      destination: 'https://nocly.fr',
      fakeShortUrl: 'https://tessera.nocly.fr/r/eVi58se',
    },
    {
      key: 'bongiorno',
      emoji: '📷',
      destination: 'https://bongiorno.nocly.fr',
      fakeShortUrl: 'https://tessera.nocly.fr/r/9mfRs98',
    },
    {
      key: 'dbsky',
      emoji: '🎬',
      destination: 'https://dbsky.nocly.fr',
      fakeShortUrl: 'https://tessera.nocly.fr/r/sDr29o4',
    },
  ];

  private readonly activeLang = signal(this.transloco.getActiveLang());

  readonly cases = computed<DemoCase[]>(() => {
    // depend on the active language so labels/blurbs re-resolve on switch
    this.activeLang();
    return this.caseData.map((c) => ({
      ...c,
      label: this.transloco.translate(`landing.demo.cases.${c.key}.label`),
      blurb: this.transloco.translate(`landing.demo.cases.${c.key}.blurb`),
    }));
  });

  readonly activeKey = signal<DemoCase['key']>('nocly');
  readonly active = computed(
    () => this.cases().find((c) => c.key === this.activeKey()) ?? this.cases()[0],
  );

  /**
   * On the demo instance each case has a REAL permanent code (/api/config
   * `showcaseLinks`, keyed by destination host) that 302s when scanned.
   */
  private readonly liveShortUrl = computed<string | null>(() => {
    const host = new URL(this.active().destination).hostname;
    return this.config.showcaseLinks()[host] ?? null;
  });
  readonly isLive = computed(() => this.liveShortUrl() !== null);
  readonly shortUrl = computed(() => this.liveShortUrl() ?? this.fakeShortUrl);
  readonly shortUrlLabel = computed(() => this.shortUrl().replace(/^https?:\/\//, ''));

  private readonly langSub = this.transloco.langChanges$.subscribe((lang) =>
    this.activeLang.set(lang),
  );

  constructor() {
    // Re-render the QR whenever the active case changes. The QR ENCODES
    // the permanent short URL — never the destination — exactly mirroring
    // what the real backend does. On the demo it's the case's real showcase
    // code; otherwise one fake short URL shared by every case.
    effect(() => {
      // depend on the signal so it re-runs
      this.activeKey();
      this.renderQr();
    });
  }

  ngAfterViewInit(): void {
    this.renderQr();
  }

  ngOnDestroy(): void {
    // No resources to release; canvas is GC'd with the host.
    this.langSub.unsubscribe();
    this.seoSub.unsubscribe();
  }

  selectCase(key: DemoCase['key']): void {
    this.activeKey.set(key);
  }

  private renderQr(): void {
    const canvas = this.qrCanvasRef?.nativeElement;
    if (!canvas) return;
    // Encode the short URL, never the destination: the QR is permanent,
    // the destination behind it isn't.
    // QR modules use tessera "ink" on "surface" — both tokens stay
    // legal QR contrast in light AND dark mode (the dark-mode --color-ink
    // is the warm paper and --color-surface is deep pin, still > 4.5:1).
    void QRCode.toCanvas(canvas, this.active().fakeShortUrl!, {
      errorCorrectionLevel: 'Q',
      width: 320,
      margin: 2,
      color: {
        dark: token('color-ink'),
        light: token('color-surface'),
      },
    });
  }
}
