import { Component, inject } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';

import { LANG_LABELS, SUPPORTED_LANGS, isLang } from './locale';
import { LocaleService } from './locale.service';

/**
 * Language selector used in every header and the public footer. Native
 * <select> for accessibility/keyboard support; switching is instant (runtime,
 * no reload) and persisted by LocaleService.
 */
@Component({
  selector: 'app-language-switcher',
  standalone: true,
  imports: [TranslocoPipe],
  template: `
    <label class="lang-switch">
      <span class="visually-hidden">{{ 'common.language' | transloco }}</span>
      <select
        (change)="onChange($event)"
        [attr.aria-label]="'common.language' | transloco"
      >
        @for (l of langs; track l.code) {
          <option [value]="l.code" [selected]="l.code === locale.lang()">{{ l.label }}</option>
        }
      </select>
    </label>
  `,
  styles: [
    `
      .lang-switch {
        display: inline-flex;
        align-items: center;
      }
      select {
        font: inherit;
        font-size: 0.875rem;
        padding: 4px 8px;
        border: 1px solid var(--color-border);
        border-radius: var(--radius-sm);
        background: var(--color-surface);
        color: var(--color-ink);
        cursor: pointer;
      }
      select:focus-visible {
        outline: none;
        box-shadow: 0 0 0 3px var(--color-accent-soft);
      }
      .visually-hidden {
        position: absolute;
        width: 1px;
        height: 1px;
        overflow: hidden;
        clip: rect(0 0 0 0);
        white-space: nowrap;
      }
    `,
  ],
})
export class LanguageSwitcherComponent {
  readonly locale = inject(LocaleService);
  readonly langs = SUPPORTED_LANGS.map((code) => ({ code, label: LANG_LABELS[code] }));

  onChange(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;
    if (isLang(value)) this.locale.setLang(value);
  }
}
