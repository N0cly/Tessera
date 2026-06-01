import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';

import { AuthService } from '../core/auth.service';

@Component({
  selector: 'app-login',
  imports: [FormsModule],
  templateUrl: './login.html',
  styleUrl: './login.css',
})
export class LoginComponent {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  email = '';
  password = '';
  readonly mode = signal<'login' | 'register'>('login');
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

    const done = () => {
      this.busy.set(false);
    };

    if (this.mode() === 'login') {
      this.auth.login(this.email, this.password).subscribe({
        next: () => {
          done();
          void this.router.navigate(['/app']);
        },
        error: (err) => {
          done();
          this.error.set(err?.error?.message ?? 'Invalid email or password.');
        },
      });
    } else {
      this.auth.register(this.email, this.password).subscribe({
        next: () => {
          this.auth.login(this.email, this.password).subscribe({
            next: () => {
              done();
              void this.router.navigate(['/app']);
            },
            error: () => {
              done();
              this.error.set('Account created, but login failed. Try again.');
            },
          });
        },
        error: (err) => {
          done();
          this.error.set(err?.error?.error ?? 'Registration failed.');
        },
      });
    }
  }
}
