# qr-code-redirect

**Self-hostable, privacy-first dynamic QR codes.** A short permanent URL gets
encoded into the QR; the destination can be changed at any time without
reprinting the code. Comes with scan analytics that never store the
visitor's IP.

> [![CI](https://github.com/<you>/qr-code-redirect/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/<you>/qr-code-redirect/actions/workflows/ci.yml)
> [![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
> Status: **v1** — works end-to-end, ready to self-host.

> Replace `<you>` in the badge URL above with your GitHub org/user once the
> repo is published; the badge will then render live CI status.

<!-- screenshot / demo -->
<p align="center">
  <img src="docs/demo.gif" alt="Demo: create a link, scan the QR, change the destination, watch the chart update" width="720" />
</p>

<p align="center">
  <img src="docs/screenshot-dashboard.png" alt="Dashboard view" width="360" />
  <img src="docs/screenshot-stats.png"     alt="Per-link stats"  width="360" />
</p>

> Drop `demo.gif`, `screenshot-dashboard.png`, and `screenshot-stats.png` in
> `docs/` to populate the placeholders above.

---

## Why another QR generator?

Most "free" dynamic QR services hold your codes hostage: scans go through
their domain, your codes stop working if you don't pay, and your scan logs
live in their database next to a column called `ip_address`.

qr-code-redirect is the opposite of that:

- **Privacy-first.** Scan logging is async, and the raw IP is **never
  persisted** — it lives only in the in-flight message, gets resolved to a
  country code, then is discarded. The `scans` table has no IP column on
  purpose.
- **EU-hostable.** All data stays on your Postgres + Redis. No third-party
  beacons, no remote analytics, no geo lookups over the internet
  (GeoLite2 runs locally on disk).
- **Your codes never expire.** As long as your instance is up, the QR you
  printed three years ago still works — and still points wherever you tell
  it to today.
- **Open-core.** The whole thing is MIT; you can fork it, run it, modify
  it, and ship it commercially. No "Community Edition" with the good stuff
  hidden.

It's deliberately generic — same engine for a restaurant menu, an event
poster, a product page, a vCard landing, whatever. Vertical framing lives
on your marketing site, not in the code.

---

## Self-host in one command

Requirements: Docker + Docker Compose v2. That's it.

```bash
git clone https://github.com/<you>/qr-code-redirect.git
cd qr-code-redirect
cp .env.example .env          # tweak APP_SECRET, JWT_PASSPHRASE, etc.
docker compose up -d --build
```

Then open <http://localhost:4200>, register an account, and create your
first link. The backend lives at <http://localhost:8000>; `GET /health`
returns `{"status":"ok"}` once it's ready.

What `docker compose up` does for you, no manual steps:

- Spins up Postgres 16, Redis 7, the Symfony backend, the Angular
  frontend, the async scan worker, and the periodic cron janitor.
- Generates the JWT keypair on first boot (regenerated automatically if
  you rotate `JWT_PASSPHRASE`).
- Runs Doctrine migrations.
- Waits on healthchecks so nothing starts before its dependencies are
  ready.

To stop: `docker compose down`. To wipe and start over: `docker compose
down -v && docker compose up -d --build`.

---

## Configuration

Every knob lives in `.env`. Defaults are sane for local dev; tighten
secrets before exposing the instance.

| Variable                            | Default                          | What it does                                                                                          |
| ----------------------------------- | -------------------------------- | ----------------------------------------------------------------------------------------------------- |
| `APP_ENV`                           | `prod`                           | `prod` or `dev`. `dev` enables the Symfony profiler — do not expose to the internet.                  |
| `APP_SECRET`                        | `change-me-in-env`               | Application secret (CSRF, signed URIs). Generate: `openssl rand -hex 32`.                             |
| `APP_BASE_URL`                      | `http://localhost:8000`          | Public origin where `/r/{slug}` is reachable. **This is what gets baked into every QR — set it right.** No trailing slash. |
| `JWT_PASSPHRASE`                    | `change-me-in-env`               | Encrypts the on-disk JWT private key. Change it → on next boot, keys are regenerated and all sessions invalidate. |
| `POSTGRES_DB` / `_USER` / `_PASSWORD` | `app` / `app` / `change-me-please` | Database credentials. Used both by the Postgres container and the backend DSN.                       |
| `POSTGRES_PORT`                     | `5432`                           | Host port mapping for Postgres.                                                                       |
| `REDIS_PORT`                        | `6379`                           | Host port mapping for Redis.                                                                          |
| `BACKEND_PORT`                      | `8000`                           | Host port for the management API + `/r/{slug}` redirect endpoint.                                     |
| `FRONTEND_PORT`                     | `4200`                           | Host port for the Angular dashboard.                                                                  |
| `CORS_ALLOW_ORIGIN`                 | `^https?://(localhost\|127\.0\.0\.1)(:[0-9]+)?$` | Regex of origins allowed to call the API. Lock down in prod, e.g. `^https://qr\.example\.com$`. |
| `LINK_CREATION_RATE_PER_MINUTE`     | `10`                             | Per-user refill rate of the token-bucket limiter on `POST /api/links`.                                |
| `LINK_CREATION_BURST`               | `20`                             | Per-user burst capacity. Excess requests get `429 Retry-After`.                                       |
| `LINK_DESTINATION_DENYLIST`         | _(empty)_                        | Comma/whitespace-separated host denylist. Prefix with `.` to also match subdomains.                   |
| `DEMO_USER_EMAIL`                   | _(empty)_                        | If set, links owned by this account are auto-purged. Leave empty to disable.                          |
| `DEMO_LINK_TTL_HOURS`               | `24`                             | Max age of demo links before the cron service deletes them.                                           |
| `DEMO_PURGE_INTERVAL_SECONDS`       | `900`                            | How often the cron container wakes to run the purge.                                                  |

The full annotated template is in [`.env.example`](.env.example).

### GeoLite2-Country

The IP → country lookup uses MaxMind's free GeoLite2 database. It is
**not shipped with the repo** (MaxMind's licence forbids it). The stack
runs fine without it; the `country` column on scans just stays `NULL`.

To enable country breakdowns:

1. Create a free MaxMind account at
   <https://www.maxmind.com/en/geolite2/signup>.
2. Download `GeoLite2-Country.mmdb.tar.gz`, extract.
3. Drop the `.mmdb` file at `backend/var/geoip/GeoLite2-Country.mmdb`.
4. `docker compose restart worker`.

That directory is mounted read-only into both `backend` and `worker`.
See [`backend/var/geoip/README.md`](backend/var/geoip/README.md) for the
exact contract.

---

## How it works

A request to `GET /r/{slug}` follows this path:

```
client ──▶ backend ──▶ Redis GET (slug → {id, destinationUrl})
                  └──▶ Messenger dispatch (ScanRecorded → Redis stream)
                  └──▶ 302 Found, Location: <destination>

                       worker ──▶ pop ScanRecorded
                              ──▶ device-detector(UA)  → device, os
                              ──▶ GeoLocator(ip)       → country (then IP is dropped)
                              ──▶ INSERT INTO scans (NO ip column)
```

The architectural invariants — there are non-negotiable rules locked
into [`CLAUDE.md`](CLAUDE.md):

- **Always 302, never 301.** A 301 gets cached for life by browsers, which
  would freeze old QR targets even after the owner edits the destination.
- **Redis first.** A warm `/r/{slug}` hit is a single Redis `GET` + a
  single Redis `XADD` for the scan dispatch — zero Postgres round-trips.
  The cache is invalidated/refreshed via a Doctrine entity listener on
  every `Link` write, plus a 1-hour safety TTL.
- **Scan logging is async.** The redirect dispatches a `ScanRecorded`
  message and returns. The actual `INSERT` happens in the worker. The
  redirect path never blocks on the DB.
- **The IP is never persisted.** It travels in the in-flight Messenger
  message, the handler turns it into a country code, then it leaves
  scope. `\d scans` confirms there is no IP column.
- **QRs encode `{APP_BASE_URL}/r/{slug}`**, not the destination. That's
  the entire reason the codes can keep working after you change where
  they point.

---

## Tech stack

- **Backend:** PHP 8.4, Symfony 7.4, API Platform 4, Doctrine ORM
- **Storage:** PostgreSQL 16 (data), Redis 7 (slug cache + Messenger
  transport + rate-limiter backend)
- **QR:** `endroid/qr-code` v6, error correction level Q
- **UA parsing:** `matomo/device-detector`
- **Geo:** MaxMind GeoLite2 via `geoip2/geoip2`
- **Frontend:** Angular 21 (standalone components, signals), Chart.js
- **Infra:** Docker + Docker Compose

The full repo layout:

```
.
├── .env.example           # self-host knobs, copy → .env
├── docker-compose.yml     # postgres, redis, backend, worker, cron, frontend
├── CLAUDE.md              # architectural rules (read this before changing anything load-bearing)
├── CONTRIBUTING.md
├── LICENSE
├── README.md
├── backend/               # Symfony app (management API + /r/{slug})
└── frontend/              # Angular dashboard
```

---

## Anti-abuse defaults

Anyone exposing a server to the internet eventually attracts scripts
hammering POSTs. qr-code-redirect ships with the boring-but-necessary
guards on by default:

- **Per-user rate limit** on `POST /api/links` (token bucket, 10/min, burst
  20). Tune via `LINK_CREATION_RATE_PER_MINUTE` and `LINK_CREATION_BURST`.
- **Destination URLs must be `http(s)`** — `javascript:`, `data:`, `file:`
  payloads are rejected at validation time on both POST and PATCH.
- **Domain denylist** (`LINK_DESTINATION_DENYLIST`) — empty by default;
  drop in known-bad hosts as you encounter them.
- **Demo TTL** — for public demos: set `DEMO_USER_EMAIL` and the cron
  service will purge that account's links periodically.

---

## Roadmap

Everything below is **deliberately out of v1**. Each item is a real ask
that came up during design and was punted to keep v1 shippable. If you
need one of these, it's a reasonable contribution target — open an issue
first so we can talk shape.

| Feature                                 | Status   | Notes |
| --------------------------------------- | -------- | ----- |
| Bulk link generation                    | Planned  | Likely a CSV upload + a background job; keeps the create endpoint single-purpose. |
| Teams / role-based access               | Planned  | The `Link.owner` FK is intentionally a single `User` in v1; teams will introduce a `Workspace` middle layer. |
| Custom domains (per-instance subdomains)| Planned  | Single domain in v1. Multi-tenant routing + cert management is its own can of worms. |
| QR design (logo, colours)               | Planned  | Pure B&W in v1 — every scanner reads it. Logos require error correction tuning. |
| Non-URL QR types (vCard, Wi-Fi, text)   | Planned  | v1 is strictly link-shortener-shaped. Other payload types are easy to add but blow up the data model. |
| Time- / geo-based A/B redirects         | Planned  | The 302 path stays single-destination in v1. A/B needs a richer routing layer. |
| White-label / branding                  | Planned  | Trivial CSS work, deferred until someone actually asks. |
| Hosted version + paid pro features      | Planned  | Open-core: core stays MIT. Hosted offering will exist separately and be built on top of this codebase. |

---

## License

MIT — see [LICENSE](LICENSE). You can fork it, run it, sell it. Patches
back are appreciated but not required.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) — short version: open an issue for
non-trivial changes, follow the architectural rules in `CLAUDE.md`,
conventional commits, leave `docker compose up` working.
