import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { TranslocoDirective, TranslocoService } from '@jsverse/transloco';

import { LanguageSwitcherComponent } from '../core/language-switcher';
import { LocaleService } from '../core/locale.service';

import { AuthService } from '../core/auth.service';
import { BillingService } from '../core/billing.service';

@Component({
  selector: 'app-login',
  imports: [FormsModule, TranslocoDirective, LanguageSwitcherComponent],
  templateUrl: './login.html',
  styleUrl: './login.scss',
})
export class LoginComponent {
  private readonly auth = inject(AuthService);
  private readonly billing = inject(BillingService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly transloco = inject(TranslocoService);
  private readonly locale = inject(LocaleService);

  // A paid plan carried over from the pricing page's "Start trial" CTA, if any.
  // When present, the visitor is signing up to subscribe: we start in register
  // mode and, after auth, continue straight to the hosted checkout for it.
  private readonly plan = this.readPlan();

  email = '';
  password = '';
  readonly mode = signal<'login' | 'register'>(this.plan ? 'register' : 'login');
  readonly busy = signal(false);
  readonly error = signal<string | null>(null);

  toggle(): void {
    this.error.set(null);
    this.mode.set(this.mode() === 'login' ? 'register' : 'login');
  }

  submit(): void {
    if (this.busy()) return;
    this.error.set(null);
    this.busy.set(true);

    if (this.mode() === 'login') {
      this.auth.login(this.email, this.password).subscribe({
        next: () => this.afterAuth(),
        error: (err) => {
          this.busy.set(false);
          this.error.set(
            err?.error?.message ?? this.transloco.translate('auth.errors.invalidCredentials'),
          );
        },
      });
    } else {
      this.auth.register(this.email, this.password, this.locale.lang()).subscribe({
        next: () => {
          this.auth.login(this.email, this.password).subscribe({
            next: () => this.afterAuth(),
            error: () => {
              this.busy.set(false);
              this.error.set(this.transloco.translate('auth.errors.loginAfterRegister'));
            },
          });
        },
        error: (err) => {
          this.busy.set(false);
          this.error.set(
            err?.error?.error ?? this.transloco.translate('auth.errors.registrationFailed'),
          );
        },
      });
    }
  }

  /**
   * After a successful login/registration: if the visitor came from the pricing
   * page with a chosen plan, send them to the hosted checkout for it; otherwise
   * land them in the dashboard. If checkout can't be opened (e.g. billing not
   * configured), fall back to the dashboard on the freshly-started trial rather
   * than stranding them here.
   */
  private afterAuth(): void {
    if (this.plan) {
      this.billing.checkout(this.plan).subscribe({
        next: ({ checkoutUrl }) => {
          window.location.href = checkoutUrl;
        },
        error: () => {
          this.busy.set(false);
          void this.router.navigate(['/app']);
        },
      });
      return;
    }

    this.busy.set(false);
    void this.router.navigate(['/app']);
  }

  private readPlan(): 'starter' | 'pro' | null {
    const p = this.route.snapshot.queryParamMap.get('plan');
    return p === 'starter' || p === 'pro' ? p : null;
  }
}
