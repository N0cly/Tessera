import { Component, OnInit, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import {
  ActivatedRoute,
  NavigationEnd,
  Router,
  RouterLink,
  RouterLinkActive,
  RouterOutlet,
} from '@angular/router';
import { TranslocoDirective } from '@jsverse/transloco';
import { filter, map } from 'rxjs';

import { AppConfigService } from '../core/app-config.service';
import { LanguageSwitcherComponent } from '../core/language-switcher';
import { TourService } from '../core/tour.service';

/**
 * Back-office shell: the shared navigation header + a <router-outlet>. The
 * overview and links pages are child routes that render into the outlet, so the
 * header lives in exactly ONE place (no duplication). The active tab is driven
 * by routerLinkActive and the page title is read from the active child route's
 * `data.title` (a Transloco key) — the pages carry no nav state. The demo-only
 * "take the tour" button drives the shared TourService.
 */
@Component({
  selector: 'app-backoffice-layout',
  standalone: true,
  imports: [
    RouterOutlet,
    RouterLink,
    RouterLinkActive,
    TranslocoDirective,
    LanguageSwitcherComponent,
  ],
  templateUrl: './backoffice-layout.html',
  styleUrl: './backoffice-layout.scss',
})
export class BackofficeLayoutComponent implements OnInit {
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  readonly config = inject(AppConfigService);
  private readonly tour = inject(TourService);

  /** Active page's title — the active child route's `data.title` Transloco key. */
  readonly titleKey = toSignal(
    this.router.events.pipe(
      filter((e) => e instanceof NavigationEnd),
      map(() => this.resolveTitle()),
    ),
    { initialValue: this.resolveTitle() },
  );

  ngOnInit(): void {
    // First demo landing → auto-run the guided tour once per session.
    this.tour.maybeAutoStart();
  }

  /** Replay the guided tour from the header button (demo only). */
  startTour(): void {
    this.tour.start();
  }

  private resolveTitle(): string {
    let r = this.route.firstChild;
    while (r?.firstChild) r = r.firstChild;
    // Guard `snapshot` too: when this runs eagerly during construction the child
    // ActivatedRoute can exist before its snapshot is wired — the NavigationEnd
    // subscription then fills the real title in a moment.
    return (r?.snapshot?.data?.['title'] as string | undefined) ?? '';
  }
}
