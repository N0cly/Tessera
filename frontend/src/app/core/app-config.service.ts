import { HttpClient } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';

import { environment } from '../../environments/environment';

export interface AppConfig {
  /** This instance runs as an ephemeral public demo. */
  demoMode: boolean;
  /** Paid subscriptions are enabled (separate flag, off in demo / by default). */
  billingEnabled: boolean;
  /** Hours of inactivity before a demo workspace resets. */
  demoResetHours: number;
  /** Self-host link shown in the demo banner / interstitial. */
  githubUrl: string;
}

const DEFAULTS: AppConfig = {
  demoMode: false,
  billingEnabled: false,
  demoResetHours: 1,
  githubUrl: 'https://github.com/N0cly/Tessera',
};

/**
 * Public instance flags fetched once at startup (GET /api/config) so the UI can
 * adapt: show the demo banner + auto-create a demo session in demo mode, hide
 * billing when it's disabled (CLAUDE.md rule 19).
 */
@Injectable({ providedIn: 'root' })
export class AppConfigService {
  private readonly http = inject(HttpClient);
  private readonly _config = signal<AppConfig>(DEFAULTS);

  readonly config = this._config.asReadonly();
  readonly demoMode = computed(() => this._config().demoMode);
  readonly billingEnabled = computed(() => this._config().billingEnabled);
  readonly demoResetHours = computed(() => this._config().demoResetHours);
  readonly githubUrl = computed(() => this._config().githubUrl);

  /** APP_INITIALIZER: load the flags before first render. Fails soft to defaults. */
  async load(): Promise<void> {
    try {
      const cfg = await firstValueFrom(
        this.http.get<AppConfig>(`${environment.apiBaseUrl}/api/config`),
      );
      this._config.set({ ...DEFAULTS, ...cfg });
    } catch {
      /* keep safe defaults (self-host, no demo, no billing) */
    }
  }
}
