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
- Billing via a **Merchant of Record (Paddle)** — hosted checkout + customer
  portal + webhooks. Outbound calls use `symfony/http-client`. The MoR owns
  card data (PCI) and tax/VAT; we store only subscription status.

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
12. **All styling follows `tessera-design.md` and uses ONLY the variables in
    `frontend/src/styles/tessera-tokens.css` — never a hardcoded hex, rgb, named
    colour, or font literal in any component, template, or JS chart/QR config.**
    `tessera-tokens.css` is imported first from `frontend/src/styles.css`. New
    visuals must resolve every colour through `var(--color-…)`, every font
    through `var(--font-…)`, every radius through `var(--radius-…)`. Both light
    and dark mode are driven by the tokens via `prefers-color-scheme`; verify
    every new screen in both. The user-facing brand is **Tessera**; the repo
    name (`qr-code-redirect`) stays in code/docs only.
13. **Dashboard overview** — `GET /api/dashboard/overview?period=7d|30d|90d` (default `30d`),
    authenticated, owner-scoped. A plain lightweight controller (not an API Platform resource);
    aggregates across all the user's links on the fly via SQL `GROUP BY` over `Scan` rows — no
    precomputed roll-up table. `periodScansChangePct` compares vs the previous equal-length period.
    Frontend route layout (all behind the auth guard): `/app` = `DashboardOverviewComponent` (home),
    `/app/links` = `LinksComponent`. The scans chart reuses the existing `chart.js` +
    `ChartCanvasComponent` wrapper — no new charting dependency.
14. **Billing via Merchant of Record (Paddle).** One `Subscription` per user
    (status: `trialing|active|past_due|canceled|expired`). New users start
    `trialing` for `BILLING_TRIAL_DAYS` (14). The MoR is the **source of truth**:
    - `POST /api/webhooks/billing` is **public** (no JWT — own firewall +
      access_control). It **verifies the Paddle HMAC signature before any
      processing** (rejects 401 if invalid/stale) and is **idempotent** via the
      `billing_events` ledger (provider event id is unique). NEVER grant access
      from the checkout return redirect — only from a verified webhook.
    - `POST /api/billing/checkout` returns the MoR hosted-checkout URL (user id
      planted in `custom_data` so the webhook maps the result back).
      `POST /api/billing/portal` deep-links the MoR customer portal.
      `GET /api/billing/subscription` feeds the dashboard's Plan & usage widget.
    - Link creation is capped at the plan's code limit (`PlanCatalog`); over the
      limit → **HTTP 402** with a clear message and a UI upgrade prompt.
    - Secrets (`PADDLE_API_KEY`, `PADDLE_WEBHOOK_SECRET`) come from env /
      `.env.local` and are **never committed**. Empty `PADDLE_*` = billing
      disabled gracefully (everyone stays on the trial code limit). The
      destination → fallback-on-expiry logic lives in rule 15.
15. **Fallback on lapse (honest degradation).** `Link.fallbackUrl` (nullable,
    same `http(s)` + denylist validation as `destinationUrl`). The redirect hot
    path picks the target from the subscription state, but **never joins the
    subscription table per scan** (this would wreck M1/M3 latency):
    - `LinkCache` stores, per slug, `{ id, destinationUrl, fallbackUrl,
      graceEndsAt, lapsed }`. `graceEndsAt` (= `currentPeriodEndsAt` +
      `BILLING_GRACE_DAYS`, default 30) is computed once at cache-build time by
      `GraceCalculator`. `/r/{slug}` decides with a pure `now >= graceEndsAt`
      compare — the time boundary is self-resolving, no query.
    - `graceEndsAt` null = active/trial → always `destinationUrl`. Past the
      boundary → `fallbackUrl` if set, else the neutral hosted **inactive page**
      (`InactivePageRenderer`: calm, on-brand, NO redirect to Tessera marketing).
      Always **302** for redirects.
    - Cache is busted when: the link's `destinationUrl`/`fallbackUrl` is edited
      (Doctrine listener), OR the owner's subscription status changes — the
      **billing webhook calls `LinkCache::invalidateForOwner()`** for all the
      owner's slugs. A direct DB status change needs a manual
      `cache:pool:clear app.cache.links`.
16. **Pricing page — Paddle is the source of truth for prices; nothing is
    hardcoded.** Plans: **Self-host** (free/MIT, not a Paddle price), **Starter**,
    **Pro**.
    - `GET /api/pricing` is **public** (lightweight controller, NOT an API
      Platform resource) and returns the paid plans assembled by `PricingCatalog`
      from Paddle: `{ plan, name, priceId, amount, currency, interval, codeLimit,
      available, promo? }`. `amount`/`promo.finalAmount` are **minor units** read
      straight from Paddle so the displayed price always equals what Paddle
      charges. Active Paddle **discounts** become `promo` (discounted price +
      badge). It is **cached in Redis** (`app.cache.pricing`, `PRICING_CACHE_TTL`,
      default 600s) — never call Paddle per view — and **fails safe**: if a plan
      can't be priced it comes back `available:false`/`amount:null` (a card with
      no number), never a wrong number. The cache also busts on Paddle
      `price.*`/`product.*`/`discount.*` webhooks.
    - **No price/promo editor** — Paddle's dashboard owns that. Create the prices
      in Paddle (start: Starter 3 €/mo, Pro 15 €/mo) and reference them via
      `PADDLE_STARTER_PRICE_ID` / `PADDLE_PRO_PRICE_ID` (legacy `PADDLE_PRICE_ID`
      is a Pro fallback). Empty id = that plan isn't purchasable.
    - **Plan limits have ONE source: `PlanCatalog`** (env `PLAN_*_CODE_LIMIT`),
      reused by BOTH the pricing display (via `/api/pricing`) and billing
      enforcement (`LinkProcessor` 402). `PlanCatalog` also owns the
      price-id↔plan mapping used by checkout and by the webhook to set the
      purchased plan. Never duplicate a limit or a price→plan mapping elsewhere.
    - Checkout (`POST /api/billing/checkout`) takes a `plan` (`starter`/`pro`);
      the pricing page's Starter/Pro CTAs start the 14-day trial (logged-out →
      sign up first, no card; logged-in → hosted checkout). Self-host CTA →
      GitHub. Unbuilt Pro features (custom domain, QR branding, teams) are tagged
      **"coming soon"** — marked, not sold. Frontend: public route `/pricing`
      (`PricingComponent`), styled strictly via `tessera-tokens.css`.
17. **Admin panel (operator-only, read-only v1) — the highest-value target in
    the app; security is non-negotiable.** Routes isolated under **`/admin`**
    (own firewalls), separate from `/api`.
    - **Dedicated `ROLE_ADMIN`, granted ONLY out-of-band** — never via signup or
      any user-facing flow (`RegistrationController` sets no roles; no endpoint
      sets roles). Grant via `bin/console app:admin:grant <email>` (sets the role
      + enrols 2FA) or the `ADMIN_ALLOWLIST` env. `App\Service\AdminAccess` is the
      single authority (DB role OR allowlist).
    - **2FA is mandatory.** `POST /admin/login` requires email + password + a TOTP
      code (`App\Service\TotpService`, RFC 6238, self-contained; codes are
      **single-use** — the consumed step is persisted to `User.lastTotpStep` so a
      captured code can't be replayed). It mints an
      **admin-scoped JWT** (`scope=admin, mfa=true`) — the ONLY token accepted by
      admin endpoints. A normal `/api/login_check` token, even for a ROLE_ADMIN
      user, has neither claim and is rejected, so 2FA cannot be bypassed.
    - **Server-side authorization on EVERY admin endpoint** via
      `AdminContext::requireAdmin()` (admin role + `scope=admin`/`mfa` claims +
      optional `ADMIN_IP_ALLOWLIST`). Never rely on hiding the UI. Login is
      per-IP rate-limited; failures are generic (no user enumeration).
    - **Audit log** (`admin_audit_logs`, `AdminAuditLogger`): every admin login
      (success + failure) and every customer-data access is recorded (actor, IP,
      action); reads of the audit log itself are recorded too (and excluded from
      the feed to avoid self-noise). Viewable at `GET /admin/audit`.
    - **Privacy / minimization:** `GET /admin/overview` is aggregates-only (NO
      PII); customer PII (emails, top customers) lives ONLY in `GET
      /admin/customers`, whose every access is audit-logged and which the UI
      loads on demand. Scans still store no raw IP (rule 5) — scanner identity is
      never available. The audit log's `ip` is the OPERATOR's own (security
      audit), which is a different thing.
    - **Business KPIs come from Paddle** (`AdminBillingMetrics`, Redis-cached,
      fail-safe): MRR is normalized from active subscriptions, never recomputed
      from raw payments. Conversion/churn (history Paddle's snapshot lacks) come
      from the synced `Subscription` mirror. **Usage + customer aggregates**
      (`AdminStats`) come from our DB (platform-wide GROUP BY, no roll-up table).
    - **Out of scope (v1):** management actions (suspend / refund / change plan →
      do them in Paddle), content/feature-flag tooling. Read-only only.
    - Frontend: separate `AdminAuthService` (own token key) + `adminGuard`; routes
      `/admin/login` + `/admin` (`AdminDashboardComponent`); styled strictly via
      `tessera-tokens.css`.
18. **i18n — 5 languages (EN default+fallback, FR, ES, IT, DE), runtime switching.**
    - **Frontend uses Transloco** (`@jsverse/transloco`), NOT `@angular/localize`
      (compile-time, no runtime switch). One JSON file per locale at
      `frontend/public/assets/i18n/{en,fr,es,it,de}.json`, organized by feature
      **namespace** (common, landing, pricing, auth, dashboard, links, stats,
      admin, adminAuth, seo). **No hardcoded UI strings** — every string is a key
      (`t('ns.key')` via the `*transloco="let t"` directive, or
      `TranslocoService.translate` in TS). EN fills any missing key (fallback).
      The files are assembled from `_parts/*.json` by `frontend/scripts/merge-i18n.mjs`
      (`node scripts/merge-i18n.mjs`); keep all 5 locales at key parity.
    - **`LocaleService`** owns the active language + persistence: logged-in →
      `User.locale` via `GET/PATCH /api/me`; else `localStorage`; first visit →
      browser language; fallback EN. The **language switcher** is in every header
      and the public footers. Dates/numbers/currency (€) format per locale —
      pass `locale.lang()` to the date/number/currency pipes and to
      `Intl.NumberFormat`; locale data is registered in `app.config.ts`.
    - **Public SEO** (landing + pricing only): locale-prefixed routes (`/fr`,
      `/es/pricing`, …; EN unprefixed = canonical) via `localeRouteGuard`, plus
      localized `<title>`/`<meta>` + `hreflang` alternates (`SeoService`). App +
      admin routes stay unprefixed (runtime switch is enough).
    - **Backend (Symfony Translation):** `default_locale: en`,
      `enabled_locales` = the 5; a `RequestLocaleSubscriber` sets the request +
      translator locale from the authenticated `User.locale` else Accept-Language.
      Catalogs in `backend/translations/{messages,validators}.{locale}.yaml` cover
      the inactive page (rendered in the scanner's language), API/validation error
      messages. The user's `locale` is stored and passed to the Paddle checkout.
      No transactional emails exist yet; the translation + per-user locale make
      them locale-ready when added.
    - **Out of scope:** translating user content (a customer's link names); any
      machine-translation pipeline (catalogs are hand-maintained). No RTL.
19. **Demo mode — ephemeral public sandbox (`DEMO_MODE`, OFF by default).** Two
    independent flags via `App\Service\FeatureFlags`, surfaced by public
    `GET /api/config`: `DEMO_MODE` and `BILLING_ENABLED` (paid subscriptions —
    a **separate** flag, OFF by default; in demo it's off and billing UI/endpoints
    are hidden/refused).
    - **Session model:** `POST /api/demo/session` (public) creates an anonymous,
      seeded, ephemeral workspace and returns a token. Each session is backed by
      a **per-session synthetic `User`** (no PII, no real credentials) that OWNS
      the session's links/scans — so the platform's existing owner-scoping is the
      isolation mechanism: a session can only ever see its own data. The token is
      a JWT for that user. Seeded from a fixed template (`DemoWorkspaceSeeder`:
      example links + scan history) so analytics are populated immediately. No
      signup — `/api/register` is 403 in demo.
    - **Redirect safety — CRITICAL:** in demo mode `/r/{slug}` NEVER performs a
      real 302. It records the (simulated) scan, then renders
      `DemoInterstitialRenderer` ("Tessera demo — this code would redirect to
      `<destination>`", destination shown as inert escaped text). Session
      isolation does NOT cover `/r/{slug}` (it's global/public) — that's exactly
      why the 302 is neutralized. Destinations keep the http(s) + denylist
      validation even though they're never followed.
    - **Lifecycle:** `DemoSession.lastActivityAt` is touched per request
      (`DemoActivitySubscriber`); idle past `DEMO_SESSION_TTL_HOURS` (default 1)
      → purged. Purge deletes the synthetic user (DB cascades session + links +
      scans). Runs scheduled (`app:demo:purge-sessions` in the cron loop) AND
      lazily on access. Returning later → a fresh seeded workspace.
    - **Abuse guardrails:** per-session link quota (`DEMO_LINK_QUOTA`, 402 in
      `LinkProcessor`), per-IP `demo_session` rate limiter, and a concurrent
      `DEMO_MAX_SESSIONS` cap.
    - **Frontend:** `AppConfigService` loads `/api/config` at startup; in demo
      the auth guard auto-creates a session (no `/login`); a persistent
      `DemoBannerComponent` (isolated data, resets after N h, self-host link) is
      shown app-wide; billing UI is hidden when `!billingEnabled`.
    - **Demo experience (tessera-demo-experience.md).** On the demo instance the
      header's dashboard CTA becomes **"Voir la démo"** (flag-driven label only;
      same `routerLink="/app"` → the guard seeds the session and lands on the
      dashboard, already "logged in"). The seed is a **90-day showcase**:
      `DemoWorkspaceSeeder` plants 5 story-driven links (launch spike / steady /
      growth / weekend / sparse) with weighted, correlated scans (device↔OS,
      country, referrer) and **non-round** totals so every widget is alive.
      Seeded link **names are localized once, at seed time**, to the session
      locale — `DemoService` sends `POST /api/demo/session?locale=…` (active UI
      lang) and the backend resolves it (query param → Accept-Language → `en`);
      never runtime-translated (rule 18). A **driver.js** (MIT) guided tour
      (`TourService`) auto-runs once per session (sessionStorage flag) and is
      replayable from a header button. It is **data-driven** (each step =
      a `data-tour="…"` selector + a `tour.*` i18n key, all 5 langs), skippable
      (the X; overlay/ESC don't close), and ends **hands-on**: overview KPIs →
      a code's analytics → edit destination → QR → then *create your own code →
      open it (the interstitial logs a simulated scan, no real 302) → watch the
      total count up (`LinkStats` count-up + a demo-only `visibilitychange`
      refresh) → repoint the destination → open again* → self-host CTA. The tour
      navigates across `/app` and `/app/links` itself and drives the real UI
      (expand stats, open edit) so highlights land on live state. driver.js is
      themed via `frontend/src/styles/tour-theme.scss` (tokens only, light+dark).

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
- Pricing catalogue: `curl http://localhost:8000/api/pricing` (public; reads Paddle, Redis-cached)
- Backend migrations: auto-applied on backend startup. Re-run manually:
  `docker compose exec backend bin/console doctrine:migrations:migrate -n`
- Generate migration: `docker compose exec backend bin/console make:migration`
- Backend tests: `docker compose exec backend bin/phpunit` (after `composer require --dev symfony/test-pack`)
- Frontend dev server: served by the `frontend` container on :4200 (hot reload via volume mount)
- Frontend tests: `docker compose exec frontend npx ng test --watch=false`
- Frontend build: `docker compose exec frontend npx ng build`
- Rebuild i18n locale files from parts: `docker compose exec frontend node scripts/merge-i18n.mjs`
- Worker logs: `docker compose logs -f worker` (drains the `async` Messenger transport)
- Cron logs: `docker compose logs -f cron` (periodic demo-link purge; no-op when `DEMO_USER_EMAIL` is empty)
- Inspect queue: `docker compose exec redis redis-cli XLEN messages`
- Manual demo purge: `docker compose exec backend bin/console app:demo:purge`
- Purge stale demo workspaces: `docker compose exec backend bin/console app:demo:purge-sessions`
  (enable the demo with `DEMO_MODE=true`; `GET /api/config` exposes the flags)
- Grant operator admin + enrol 2FA: `docker compose exec backend bin/console app:admin:grant <email>`
  (also `app:admin:revoke <email>`, `app:admin:list`). Admin panel: frontend `/admin`, API under `/admin`.

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