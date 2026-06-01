import { Component, OnDestroy, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';

import { AuthService } from '../core/auth.service';
import { Link, LinksService } from '../core/links.service';
import { LinkStatsComponent } from '../stats/link-stats';

interface EditState {
  iri: string;
  destinationUrl: string;
}

@Component({
  selector: 'app-links',
  imports: [FormsModule, LinkStatsComponent],
  templateUrl: './links.html',
  styleUrl: './links.css',
})
export class LinksComponent implements OnInit, OnDestroy {
  private readonly api = inject(LinksService);
  private readonly auth = inject(AuthService);

  readonly links = signal<Link[]>([]);
  readonly loading = signal(true);
  readonly error = signal<string | null>(null);

  newDestination = '';
  newName = '';
  readonly creating = signal(false);

  readonly editing = signal<EditState | null>(null);
  readonly savingEdit = signal(false);

  // Map of link id → object URL for the inline PNG preview.
  readonly qrPreviews = signal<Record<string, string>>({});

  // Set of link ids whose stats panel is expanded.
  readonly expanded = signal<Record<string, boolean>>({});

  ngOnInit(): void {
    this.refresh();
  }

  ngOnDestroy(): void {
    this.revokeAllPreviews();
  }

  refresh(): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.list().subscribe({
      next: (links) => {
        this.revokeAllPreviews();
        this.links.set(links);
        this.loading.set(false);
        links.forEach((l) => this.loadQrPreview(l));
      },
      error: () => {
        this.error.set('Could not load links.');
        this.loading.set(false);
      },
    });
  }

  redirectUrl(slug: string): string {
    return this.api.redirectUrl(slug);
  }

  qrPreviewUrl(linkId: string): string | null {
    return this.qrPreviews()[linkId] ?? null;
  }

  create(): void {
    if (this.creating() || !this.newDestination) return;
    this.creating.set(true);
    this.error.set(null);
    this.api.create({ destinationUrl: this.newDestination, name: this.newName || null }).subscribe({
      next: (link) => {
        this.links.update((curr) => [link, ...curr]);
        this.newDestination = '';
        this.newName = '';
        this.creating.set(false);
        this.loadQrPreview(link);
      },
      error: (err) => {
        this.creating.set(false);
        this.error.set(this.extractError(err) ?? 'Could not create link.');
      },
    });
  }

  startEdit(link: Link): void {
    this.editing.set({ iri: link['@id'], destinationUrl: link.destinationUrl });
  }

  cancelEdit(): void {
    this.editing.set(null);
  }

  saveEdit(): void {
    const state = this.editing();
    if (!state || this.savingEdit()) return;
    this.savingEdit.set(true);
    this.api.update(state.iri, { destinationUrl: state.destinationUrl }).subscribe({
      next: (updated) => {
        this.links.update((curr) => curr.map((l) => (l['@id'] === updated['@id'] ? updated : l)));
        this.editing.set(null);
        this.savingEdit.set(false);
      },
      error: (err) => {
        this.savingEdit.set(false);
        this.error.set(this.extractError(err) ?? 'Could not save changes.');
      },
    });
  }

  remove(link: Link): void {
    if (!confirm(`Delete /r/${link.slug}? This cannot be undone.`)) return;
    this.api.remove(link['@id']).subscribe({
      next: () => {
        this.revokePreview(link.id);
        this.expanded.update((curr) => {
          const { [link.id]: _drop, ...rest } = curr;
          return rest;
        });
        this.links.update((curr) => curr.filter((l) => l['@id'] !== link['@id']));
      },
      error: () => this.error.set('Could not delete link.'),
    });
  }

  isExpanded(linkId: string): boolean {
    return !!this.expanded()[linkId];
  }

  toggleStats(link: Link): void {
    this.expanded.update((curr) => ({
      ...curr,
      [link.id]: !curr[link.id],
    }));
  }

  download(link: Link, format: 'png' | 'svg'): void {
    this.api.qr(link['@id'], format).subscribe({
      next: (blob) => {
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `qr-${link.slug}.${format}`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
      },
      error: () => this.error.set(`Could not download ${format.toUpperCase()}.`),
    });
  }

  logout(): void {
    this.auth.logout();
  }

  copySlug(slug: string): void {
    void navigator.clipboard?.writeText(this.redirectUrl(slug));
  }

  private loadQrPreview(link: Link): void {
    this.api.qr(link['@id'], 'png').subscribe({
      next: (blob) => {
        const url = URL.createObjectURL(blob);
        this.qrPreviews.update((curr) => {
          // If a stale URL is being replaced, revoke it.
          const prev = curr[link.id];
          if (prev) URL.revokeObjectURL(prev);
          return { ...curr, [link.id]: url };
        });
      },
      // Silently swallow: the preview is best-effort, downloads still work.
      error: () => {},
    });
  }

  private revokePreview(linkId: string): void {
    const url = this.qrPreviews()[linkId];
    if (!url) return;
    URL.revokeObjectURL(url);
    this.qrPreviews.update((curr) => {
      const { [linkId]: _drop, ...rest } = curr;
      return rest;
    });
  }

  private revokeAllPreviews(): void {
    const all = this.qrPreviews();
    Object.values(all).forEach((url) => URL.revokeObjectURL(url));
    this.qrPreviews.set({});
  }

  private extractError(err: {
    error?: { detail?: string; violations?: { propertyPath: string; message: string }[] };
  }): string | null {
    if (!err?.error) return null;
    if (err.error.violations?.length) {
      return err.error.violations.map((v) => `${v.propertyPath}: ${v.message}`).join('; ');
    }
    return err.error.detail ?? null;
  }
}
