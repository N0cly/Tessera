import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';

import { environment } from '../../environments/environment';

export interface Link {
  '@id': string;
  id: string;
  slug: string;
  destinationUrl: string;
  name: string | null;
  createdAt: string;
  updatedAt: string;
}

interface HydraCollection<T> {
  member: T[];
  totalItems: number;
}

@Injectable({ providedIn: 'root' })
export class LinksService {
  private readonly http = inject(HttpClient);
  private readonly base = `${environment.apiBaseUrl}/api/links`;

  list(): Observable<Link[]> {
    return this.http
      .get<HydraCollection<Link>>(this.base, {
        headers: { Accept: 'application/ld+json' },
      })
      .pipe(map((res) => res.member ?? []));
  }

  create(payload: { destinationUrl: string; name?: string | null }): Observable<Link> {
    return this.http.post<Link>(this.base, payload, {
      headers: {
        'Content-Type': 'application/ld+json',
        Accept: 'application/ld+json',
      },
    });
  }

  update(iri: string, payload: Partial<Pick<Link, 'destinationUrl' | 'name'>>): Observable<Link> {
    return this.http.patch<Link>(`${environment.apiBaseUrl}${iri}`, payload, {
      headers: {
        'Content-Type': 'application/merge-patch+json',
        Accept: 'application/ld+json',
      },
    });
  }

  remove(iri: string): Observable<void> {
    return this.http.delete<void>(`${environment.apiBaseUrl}${iri}`);
  }

  redirectUrl(slug: string): string {
    return `${environment.apiBaseUrl}/r/${slug}`;
  }

  qr(iri: string, format: 'png' | 'svg'): Observable<Blob> {
    return this.http.get(`${environment.apiBaseUrl}${iri}/qr`, {
      params: { format },
      responseType: 'blob',
    });
  }

  stats(iri: string, period: 7 | 30 | 90): Observable<LinkStats> {
    return this.http.get<LinkStats>(`${environment.apiBaseUrl}${iri}/stats`, {
      params: { period },
    });
  }
}

export interface LinkStats {
  linkId: string;
  period: 7 | 30 | 90;
  since: string;
  total: number;
  perDay: { date: string; count: number }[];
  byCountry: { country: string | null; count: number }[];
  byDevice: { device: string | null; count: number }[];
  byOs: { os: string | null; count: number }[];
}
