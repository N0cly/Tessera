import { Component, inject } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';

import { AppConfigService } from './app-config.service';

/**
 * Persistent demo banner (tessera-demo-mode.md): tells the visitor their data is
 * isolated and resets after inactivity, and links to self-hosting the real
 * product. Shown app-wide only when DEMO_MODE is on. Tokens-only styling.
 */
@Component({
  selector: 'app-demo-banner',
  standalone: true,
  imports: [TranslocoPipe],
  template: `
    <div class="demo-banner" role="status">
      <span class="msg">{{
        'demo.banner.text' | transloco: { hours: config.demoResetHours() }
      }}</span>
      <a class="cta" [href]="config.githubUrl()" target="_blank" rel="noopener">
        {{ 'demo.banner.selfHost' | transloco }} →
      </a>
    </div>
  `,
  styles: [
    `
      .demo-banner {
        position: sticky;
        top: 0;
        z-index: 50;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: center;
        gap: 6px 14px;
        padding: 8px 16px;
        background: var(--color-accent-soft);
        color: var(--color-ink-soft);
        border-bottom: 1px solid var(--color-border);
        font-family: var(--font-sans);
        font-size: 13px;
        text-align: center;
      }
      .cta {
        color: var(--color-accent-strong);
        font-weight: 600;
        text-decoration: none;
      }
      .cta:hover {
        text-decoration: underline;
      }
    `,
  ],
})
export class DemoBannerComponent {
  readonly config = inject(AppConfigService);
}
