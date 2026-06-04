# Tessera — milestone: dashboard overview

Account-level overview that aggregates **all** of a user's links. Builds on the existing
`Link` (M1) and `Scan` (M3/M4) data. Style follows `tessera-design.md` and uses
`tessera-tokens.css` variables only.

## Goal

A dashboard home screen showing, across all the authenticated user's links: key metrics,
scans over time, top links, and device breakdown — plus a plan/usage widget (presentational
for now).

## Scope — build this

### Backend
- `GET /api/dashboard/overview?period=7d|30d|90d` (default `30d`), **authenticated, owner-scoped**
  (aggregate only over links owned by the current user).
- Aggregations via Doctrine/SQL `GROUP BY` over the user's `Scan` rows. No precomputed tables.
- `periodScansChangePct` = variation vs the previous equal-length period.

### Frontend (Angular)
- `DashboardOverviewComponent` mounted on the dashboard home route (behind the auth guard).
- Sections, in order: KPI row → period filter + scans line chart → (top links | by device) →
  plan & usage widget.
- Chart via a lightweight lib (Chart.js through ng2-charts, or equivalent).
- Style **strictly** via `tessera-tokens.css` variables + `tessera-design.md`. No hardcoded
  colors/fonts. Must work in light **and** dark mode. Accent = `--color-accent` (teal).

## Data contract (exact JSON returned by the endpoint)

```json
{
  "kpis": {
    "activeCodes": 12,
    "totalScans": 8432,
    "periodScans": 1204,
    "periodScansChangePct": 18,
    "avgScansPerCode": 100
  },
  "timeSeries": [
    { "date": "2026-05-06", "scans": 23 }
  ],
  "topLinks": [
    { "slug": "a7Xk2pQ", "name": "menu midi", "scans": 512 }
  ],
  "byDevice": [
    { "device": "mobile", "pct": 68 },
    { "device": "desktop", "pct": 24 },
    { "device": "tablet", "pct": 8 }
  ]
}
```
- `timeSeries`: one entry per day across the selected period.
- `topLinks`: top 5 by scans over the selected period.
- `byDevice`: percentages summing to ~100.

## Components

- **KPI card**: muted 13px label + 24px/500 value; optional small teal delta (e.g. `+18%`).
  Use the secondary background, no border, `--radius-md`. Grid of 4, responsive.
- **Period filter**: segmented control `7 j / 30 j / 90 j`; changing it refetches the endpoint.
- **Scans line chart**: filled teal line, no legend, subtle grid, tooltips. Empty state if no scans.
- **Top links**: ranked bar list — mono slug (`--font-mono`) + name + scan count + a teal bar
  (width relative to the top item). Not an HTML table.
- **By device**: bar list with icon + label + percentage + bar.
- **Plan & usage widget** (presentational, fed by a placeholder object): plan name, trial days
  left, codes used / limit with a progress bar, a "manage subscription" button, and a line
  showing the default fallback URL state. Do not wire real billing here.

## Out of scope — do NOT build here

- Stripe / billing / subscription logic and trial mechanics (separate "billing & fallback" milestone).
- Option B fallback-URL editing and the expiration → fallback redirect logic (same separate milestone).
- Country/geographic map, realtime updates, CSV export.

## Design notes

- Cards: `--color-surface` bg, 0.5px `--color-border`, `--radius-lg`. KPI cards: secondary bg, no border.
- Slugs/URLs in `--font-mono`. Headings in `--font-display`. Everything else `--font-sans`.
- Sentence case. AA contrast in both modes. Visible focus on the period controls and the button.