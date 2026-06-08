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
  key: 'menu' | 'event' | 'bio';
  label: string;
  emoji: string;
  destination: string;
  blurb: string;
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

  // Fake but believable. The slug is decorative — landing is FE-only,
  // there's no backend lookup, no persistence, nothing on the wire.
  readonly fakeSlug = 'mZ4kPx7';
  readonly fakeShortUrl = `qr.example.com/r/${this.fakeSlug}`;

  // Decorative emoji + (fake) destination are data, not copy — they stay as-is.
  // Labels and blurbs are translated; they re-resolve when the language changes.
  private readonly caseData: Pick<DemoCase, 'key' | 'emoji' | 'destination'>[] = [
    {
      key: 'menu',
      emoji: '🍽️',
      destination: 'https://chez-mathilde.example.com/menu/spring-2026',
    },
    {
      key: 'event',
      emoji: '🎟️',
      destination: 'https://meetup.example.com/devops-paris/feb-26',
    },
    {
      key: 'bio',
      emoji: '🔗',
      destination: 'https://linktr.example.com/elara-music',
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

  readonly activeKey = signal<DemoCase['key']>('menu');
  readonly active = computed(
    () => this.cases().find((c) => c.key === this.activeKey()) ?? this.cases()[0],
  );

  private readonly langSub = this.transloco.langChanges$.subscribe((lang) =>
    this.activeLang.set(lang),
  );

  constructor() {
    // Re-render the QR whenever the active case changes. The QR ENCODES
    // the (fake) permanent short URL — never the destination — exactly
    // mirroring what the real backend does. Changing the active case
    // changes only what the short URL would point at, not the QR itself.
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
    // Always encode the SAME short URL — that's the point of the demo:
    // the QR is permanent, the destination behind it isn't.
    // QR modules use tessera "ink" on "surface" — both tokens stay
    // legal QR contrast in light AND dark mode (the dark-mode --color-ink
    // is the warm paper and --color-surface is deep pin, still > 4.5:1).
    void QRCode.toCanvas(canvas, `https://${this.fakeShortUrl}`, {
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
