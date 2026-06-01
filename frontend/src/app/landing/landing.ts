import {
  AfterViewInit,
  Component,
  ElementRef,
  OnDestroy,
  ViewChild,
  computed,
  effect,
  signal,
} from '@angular/core';
import { RouterLink } from '@angular/router';
import QRCode from 'qrcode';

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
  imports: [RouterLink],
  templateUrl: './landing.html',
  styleUrl: './landing.css',
})
export class LandingComponent implements AfterViewInit, OnDestroy {
  @ViewChild('qrCanvas', { static: false }) qrCanvasRef?: ElementRef<HTMLCanvasElement>;

  // Fake but believable. The slug is decorative — landing is FE-only,
  // there's no backend lookup, no persistence, nothing on the wire.
  readonly fakeSlug = 'mZ4kPx7';
  readonly fakeShortUrl = `qr.example.com/r/${this.fakeSlug}`;

  readonly cases: DemoCase[] = [
    {
      key: 'menu',
      label: 'Restaurant menu',
      emoji: '🍽️',
      destination: 'https://chez-mathilde.example.com/menu/spring-2026',
      blurb: 'On the table tents. Reprint never — swap the menu link every season.',
    },
    {
      key: 'event',
      label: 'Event signup',
      emoji: '🎟️',
      destination: 'https://meetup.example.com/devops-paris/feb-26',
      blurb:
        'On the conference poster. Same QR for every edition; just redirect to the new signup page.',
    },
    {
      key: 'bio',
      label: 'Social bio link',
      emoji: '🔗',
      destination: 'https://linktr.example.com/elara-music',
      blurb:
        'On a business card or sticker. Update the destination as your link-in-bio service changes.',
    },
  ];

  readonly activeKey = signal<DemoCase['key']>('menu');
  readonly active = computed(
    () => this.cases.find((c) => c.key === this.activeKey()) ?? this.cases[0],
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
  }

  selectCase(key: DemoCase['key']): void {
    this.activeKey.set(key);
  }

  private renderQr(): void {
    const canvas = this.qrCanvasRef?.nativeElement;
    if (!canvas) return;
    // Always encode the SAME short URL — that's the point of the demo:
    // the QR is permanent, the destination behind it isn't.
    void QRCode.toCanvas(canvas, `https://${this.fakeShortUrl}`, {
      errorCorrectionLevel: 'Q',
      width: 320,
      margin: 2,
      color: { dark: '#0f172a', light: '#ffffff' },
    });
  }
}
