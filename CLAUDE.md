# CLAUDE.md — qr-code-redirect

> Persistent project context for Claude Code. Loaded at the start of every session.
> Keep it concise and high-signal. If a rule here conflicts with a chat prompt, this file wins.

## Project overview

qr-code-redirect is an open-source, self-hostable **dynamic QR code** platform.
A "dynamic" QR encodes a short permanent URL (`/r/{slug}`) that redirects to a destination
the owner can change at any time — without reprinting the code — plus scan analytics.

- Positioning: privacy-first, EU-hostable, **codes never expire** (no hostage model).
- Business model: open-core. The core is free and self-hostable; a hosted version and pro
  features come later. Do NOT build paid/pro features in v1.
- The engine is **generic** (any use case). Vertical framing (restaurants, events, social
  links, etc.) lives in the marketing/landing layer only — never in the core data model.

## Tech stack

Backend
- PHP 8.2+, Symfony 7, API Platform 4, Doctrine ORM
- PostgreSQL (data) + Redis (slug→URL cache and async scan queue)
- QR image generation: endroid/qr-code
- Geo from IP: MaxMind GeoLite2 (local DB, no third-party call)

Frontend
- Angular (latest stable, pinned at scaffold) + TypeScript
- Consumes the API Platform REST API

Infra
- Docker + docker compose. `docker compose up` must bring up the whole stack and serve both apps.

## Repository layout

```
/                  repo root
  CLAUDE.md        this file
  README.md
  .env.example     self-host knobs — copy to .env to override defaults
  docker-compose.yml
  backend/         Symfony app (management API + redirect endpoint)
  frontend/        Angular app (management dashboard)
```

## Architecture — critical rules

1. Redirect hot path — `GET /r/{slug}`:
    - MUST return HTTP **302** (temporary), NEVER 301. Destinations are editable; a 301 gets cached
      for life by browsers and would freeze old targets. Non-negotiable.
    - Resolve `slug → destination_url` from **Redis** first; fall back to Postgres and warm the cache.
    - Log the scan **asynchronously** (Symfony Messenger) so the redirect never blocks on a DB write.
    - This endpoint is a plain lightweight controller, **not** an API Platform resource (latency).
2. The management API (CRUD on links, analytics reads) is exposed via **API Platform**.
3. Auth: simple email + password for v1. No SSO, no teams in v1.
4. `APP_BASE_URL` is the **public origin under which `/r/{slug}` is reachable** (e.g. `http://localhost:8000`
   in dev, `https://qr.example.com` in prod). It is what gets encoded into every QR. The QR endpoint
   builds `{APP_BASE_URL}/r/{slug}` — **never** encode the destination URL directly, that would defeat
   the whole "dynamic" point. No trailing slash. Set it in `.env` or override via docker-compose.
5. Scan tracking: the redirect dispatches a `ScanRecorded` message via Symfony Messenger (Redis transport),
   the `worker` service drains it. **The raw IP must never be persisted.** It travels in the in-flight
   message, gets resolved to a country in the handler (`GeoLocator`), then is discarded. The `scans`
   table has no IP column on purpose.
6. **GeoLite2 database is NOT in git.** MaxMind's licence forbids it. Drop the file at
   `backend/var/geoip/GeoLite2-Country.mmdb` (see that directory's README). The stack runs without it;
   `country` is just NULL on scans until you provide it.
7. **`docker compose up` from a clean clone must boot a working app — no undocumented manual steps.**
   The backend container's entrypoint generates the JWT keypair if missing and runs Doctrine migrations
   (`RUN_MIGRATIONS=1`). The worker is gated on `backend: condition: service_healthy` so it never
   races. Every overridable config knob lives in `.env.example` with a sane default.
8. `POST /api/links` is rate-limited per authenticated user (token-bucket, ~10/min default, Redis-backed).
   Tune via `LINK_CREATION_RATE_PER_MINUTE` / `LINK_CREATION_BURST` in `.env`. Exceeded → `429` with
   `Retry-After`. This is anti-abuse, not a product-tier limit.
9. **Destination URLs MUST be `http(s)` only.** Enforced on POST + PATCH by `#[Assert\Url(protocols: ['http','https'])]`
   on `Link::$destinationUrl`. Don't relax this — `javascript:`, `data:`, `file:` payloads would let a
   compromised account turn the redirector into a phishing/XSS vector across any browser that opens the QR.
10. Operators can refuse specific destination hosts via `LINK_DESTINATION_DENYLIST`
    (comma-/whitespace-separated, leading-dot for subdomain match). Empty default — mechanism only,
    no built-in policy. Hits return `422` with a clear validation message.
11. Public-demo hygiene: if `DEMO_USER_EMAIL` is set, the `cron` service purges links owned by that
    account older than `DEMO_LINK_TTL_HOURS` (default 24). Disabled when the env var is empty.

## Dev commands

All commands assume the stack is up (`docker compose up`). Backend runs at
http://localhost:8000, frontend at http://localhost:4200.

- First-time setup: `cp .env.example .env`, edit secrets, then `docker compose up -d`.
- Start everything: `docker compose up` (add `--build` after Dockerfile/dep changes)
- Stop everything: `docker compose down`
- Wipe & restart: `docker compose down -v && docker compose up -d` (drops the postgres + JWT volumes,
  the backend entrypoint regenerates everything on next boot)
- Backend shell: `docker compose exec backend sh`
- Backend health check: `curl http://localhost:8000/health` → `{"status":"ok"}`
- Backend migrations: auto-applied on backend startup. Re-run manually:
  `docker compose exec backend bin/console doctrine:migrations:migrate -n`
- Generate migration: `docker compose exec backend bin/console make:migration`
- Backend tests: `docker compose exec backend bin/phpunit` (after `composer require --dev symfony/test-pack`)
- Frontend dev server: served by the `frontend` container on :4200 (hot reload via volume mount)
- Frontend tests: `docker compose exec frontend npx ng test --watch=false`
- Frontend build: `docker compose exec frontend npx ng build`
- Worker logs: `docker compose logs -f worker` (drains the `async` Messenger transport)
- Cron logs: `docker compose logs -f cron` (periodic demo-link purge; no-op when `DEMO_USER_EMAIL` is empty)
- Inspect queue: `docker compose exec redis redis-cli XLEN messages`
- Manual demo purge: `docker compose exec backend bin/console app:demo:purge`

## Conventions

- PHP: PSR-12, typed properties, constructor injection.
- Angular: official style guide, standalone components, everything typed.
- Commits: conventional commits (`feat:`, `fix:`, `chore:`, …).
- Leave `docker compose up` working at the end of every change.

## Build plan — work ONE milestone at a time

- M0 — Skeleton: docker compose (Postgres, Redis, backend, frontend) + minimal auth. It boots.
- M1 — Core (no QR yet): create a link; `GET /r/{slug}` 302-redirects to an editable URL; basic dashboard.
- M2 — QR: generate + display the QR for a slug, export PNG/SVG. (Shippable mini-product.)
- M3 — Tracking: async scan logging (date, device/OS from UA, country from GeoLite2) + Redis on the hot path.
- M4 — Analytics: dashboard with totals, time series, device/country breakdown.
- M5 — Polish & self-host: clean README, tidy docker, live demo, multi-example landing page.

## OUT of v1 scope — do NOT build these

Bulk generation · teams/roles · custom domains · QR design (logo/colors) · non-URL QR types
(vCard/Wi-Fi/text) · time- or geo-based A/B redirects · white-label · any paid/pro tier.
These are the post-v1 roadmap. Adding them now is scope creep — don't.