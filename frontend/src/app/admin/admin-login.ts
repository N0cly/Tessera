import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { TranslocoDirective, TranslocoService } from '@jsverse/transloco';

import { LanguageSwitcherComponent } from '../core/language-switcher';

import { AdminAuthService } from '../core/admin-auth.service';

@Component({
  selector: 'app-admin-login',
  standalone: true,
  imports: [FormsModule, TranslocoDirective, LanguageSwitcherComponent],
  templateUrl: './admin-login.html',
  styleUrl: './admin-login.scss',
})
export class AdminLoginComponent {
  private readonly auth = inject(AdminAuthService);
  private readonly router = inject(Router);
  private readonly transloco = inject(TranslocoService);

  email = '';
  password = '';
  code = '';
  readonly busy = signal(false);
  readonly error = signal<string | null>(null);

  submit(): void {
    if (this.busy()) return;
    this.error.set(null);
    this.busy.set(true);

    this.auth.login(this.email, this.password, this.code).subscribe({
      next: () => {
        this.busy.set(false);
        void this.router.navigate(['/admin']);
      },
      error: (err) => {
        this.busy.set(false);
        this.error.set(
          err?.status === 429
            ? this.transloco.translate('adminAuth.errors.tooManyAttempts')
            : this.transloco.translate('adminAuth.errors.invalidCredentials'),
        );
      },
    });
  }
}
