# Architecture

This document is the high-level map of Tessera (repo: `qr-code-redirect`) and the
**invariants** that must hold. The exhaustive, rule-by-rule source of truth is
[`CLAUDE.md`](../CLAUDE.md) at the repo root — when this doc and `CLAUDE.md`
disagree, `CLAUDE.md` wins. Read this before changing anything load-bearing.

## What it is

A **dynamic QR** platform. A QR encodes a short *permanent* URL — `/r/{slug}` —
that 302-redirects to a destination the owner can change at any time without
reprinting the code. Plus privacy-respecting scan analytics. It is open-core
(MIT), self-hostable, and EU-hostable.

The engine is **generic**: the same machinery serves a restaurant menu, an event
poster, a link-in-bio, etc. Any vertical framing lives in the marketing/landing
layer, never in the core data model.

## Components

```
                         ┌─────────────────────────────────────────┐
  scanner ──GET /r/{slug}┤  backend (Symfony 7 + API Platform 4)   │
                         │                                          │
  dashboard ──/api ──────┤  • RedirectController  (/r/{slug})       │── Postgres (links, scans,
  (Angular 21)           │      ↑ plain controller, NOT API Platform│      subscriptions, …)
                         │  • API Platform resources (/api/links …) │
                         │  • lightweight controllers               │── Redis  (slug cache,
                         │      (/api/config, /api/pricing,          │      Messenger transport,
                         │       /api/dashboard/overview, /admin …)  │      rate-limiter)
                         └───────────────┬──────────────────────────┘
                                         │ dispatch ScanRecorded (Messenger)
                                         ▼
                         ┌──────────────────────────┐    ┌──────────────────┐
                         │ worker (drains async)    │    │ cron (janitor)   │
                         │  UA → device/os          │    │  demo purges,    │
                         │  IP → country, then drop │    │  TTL cleanups    │
                         │  INSERT scan (no IP col) │    └──────────────────┘
                         └──────────────────────────┘
```

`docker compose up` brings up Postgres 16, Redis 7, the backend, the Angular
frontend, the async **worker**, and the **cron** janitor — and serves a working
app from a clean clone with no undocumented manual steps (JWT keys generated and
migrations run by the backend entrypoint).

- **Backend** — PHP 8.4, Symfony 7, API Platform 4, Doctrine ORM. Management API
  (CRUD on links, analytics reads, billing, admin) via API Platform + lightweight
  controllers; the redirect hot path is a plain controller.
- **Frontend** — Angular 21 (standalone components, signals), Transloco i18n,
  Chart.js. Consumes the REST API; the back-office is a layout shell with child
  routes.
- **Postgres** — durable data. **Redis** — slug→destination cache, the Messenger
  transport for async scans, and the token-bucket rate-limiter backend.

## The redirect hot path — `GET /r/{slug}`

This is the latency-critical path and the source of most invariants.

1. Resolve `slug → {id, destinationUrl, fallbackUrl, graceEndsAt, lapsed}` from
   **Redis first**; on a miss, fall back to Postgres and warm the cache.
2. Dispatch a `ScanRecorded` message (Symfony Messenger, Redis transport) and
   return — **the redirect never blocks on a DB write**.
3. Choose the target with a pure `now >= graceEndsAt` compare (no per-scan
   subscription join) and return **302**.

A warm hit is one Redis `GET` + one Redis enqueue, then 302 — **zero Postgres
round-trips**.

## Invariants (non-negotiable)

These are not style preferences; breaking one breaks the product or its privacy
guarantees. CI, CodeQL, and the Claude PR review all watch for them.

1. **302, never 301.** Destinations are editable; a 301 gets cached for life by
   browsers and would freeze old QR targets forever.
2. **The redirect is a plain controller, outside API Platform.** `/r/{slug}` must
   not become an API Platform resource — that overhead is unacceptable on the hot
   path.
3. **Scan logging is async.** The redirect dispatches `ScanRecorded` and returns;
   the `INSERT` happens in the `worker`. The hot path never waits on the DB.
4. **Raw IP is never persisted.** The scanner IP travels only in the in-flight
   Messenger message, is resolved to a country code in the handler (`GeoLocator`),
   then discarded. The `scans` table has **no IP column** on purpose. (In demo
   mode the IP isn't even put on the message.)
5. **Redis slug cache + explicit invalidation.** `LinkCache` stores the minimal
   redirect payload per slug. It is busted when a link's destination/fallback is
   edited (Doctrine listener) or the owner's subscription status changes (billing
   webhook), with a 1-hour safety TTL as a backstop.
6. **QRs encode `{APP_BASE_URL}/r/{slug}`**, never the destination URL — that is
   the entire point of a *dynamic* code. `APP_BASE_URL` is the public origin under
   which `/r/{slug}` is reachable; no trailing slash.
7. **Destination URLs are `http(s)`-only**, enforced on POST and PATCH; an
   operator host denylist (`LINK_DESTINATION_DENYLIST`) can refuse specific hosts.
8. **`docker compose up` from a clean clone boots a working app** — no
   undocumented steps; every overridable knob has a sane default.
9. **i18n parity.** Every user-facing string is a translation key present in all
   five locales (`en, fr, es, it, de`); English is the fallback. Frontend strings
   are assembled from `_parts/*.json`; backend messages live in `translations/`.

## Fallback on lapse (honest degradation)

A link may carry a nullable `fallbackUrl`. The hot path never joins the
subscription table per scan: `LinkCache` precomputes `graceEndsAt`
(`currentPeriodEndsAt + BILLING_GRACE_DAYS`) once at cache-build time, and
`/r/{slug}` decides with a pure `now >= graceEndsAt` compare. Past the grace
boundary the code serves `fallbackUrl` (if set) or a neutral hosted **inactive
page** — always 302 for real redirects.

## Feature flags

Two independent flags, both **off by default**, surfaced by the public
`GET /api/config` and resolved by `App\Service\FeatureFlags`:

- **`DEMO_MODE`** — turns the instance into an ephemeral public sandbox (below).
- **`BILLING_ENABLED`** — paid subscriptions (Paddle, Merchant of Record). A
  *separate* flag; with it off, billing UI/endpoints are hidden/refused and
  everyone stays on the trial code limit. No secret values are ever committed
  (`PADDLE_*` come from env / `.env.local`).

## Demo mode (per-session sandbox + interstitial)

When `DEMO_MODE` is on, the hosted instance is a public demo:

- **Per-session synthetic user.** Each visitor gets an anonymous, seeded,
  ephemeral workspace backed by a synthetic `User` (no PII) that *owns* the
  session's links/scans — so the platform's existing owner-scoping is the
  isolation mechanism. The client holds a JWT for that user.
- **Redirect safety (critical).** `/r/{slug}` is global/public and **not** covered
  by session isolation, so in demo mode it **never performs a real 302**: it
  records a simulated scan and renders a safe **interstitial** ("this code would
  redirect to `<destination>`", shown as inert escaped text). This removes the
  open-redirect liability while still demonstrating the full mechanic.
- **Lifecycle.** Sessions reset after `DEMO_SESSION_TTL_HOURS` (default 1h) of
  inactivity — purged on a schedule and lazily on access; deleting the synthetic
  user cascades the whole workspace. Abuse guardrails: per-session link quota,
  per-IP session-creation rate limit, and a concurrent-session cap.
- **Guided experience.** A first-entry, replayable driver.js tour walks the demo
  hands-on. Seeded showcase link names are localized once, at seed time.

## Admin panel (operator-only, read-only)

Isolated under `/admin` (own firewalls), `ROLE_ADMIN` granted out-of-band only,
**mandatory 2FA** (TOTP) minting an admin-scoped JWT, server-side authorization on
every endpoint, and an audit log of every admin login and customer-data access.
Aggregates carry no PII; customer PII lives in a separate, audit-logged view.
Read-only by design — management actions (suspend/refund/change plan) happen in
Paddle.

## Where to go next

- [`CLAUDE.md`](../CLAUDE.md) — the full, numbered architectural rules.
- [`CONTRIBUTING.md`](../CONTRIBUTING.md) — dev setup, how to run tests/lint, DCO.
- [`README.md`](../README.md) — self-host quickstart and configuration.
