# Tessera — demo experience (entry, guided tour, showcase data)

Builds on `tessera-demo-mode.md`. Turns the demo into a guided, conversion-oriented showcase whose
goal is to make a visitor want to self-host. Follows `tessera-design.md`.

## Entry point

- On the hosted demo instance (`DEMO_MODE` on), the header **"Dashboard" button becomes
  "Voir la démo"**. Clicking it creates a demo session (per `tessera-demo-mode.md`) and lands
  directly on the **classic dashboard, already "logged in"** as a demo session — no login, no signup.
- On self-host (`DEMO_MODE` off), the button is the normal "Dashboard". Label + behavior are
  **flag-driven**.
- The demo instance has no real auth/signup — the only path in is "Voir la démo".

## Showcase seed data (fake but realistic)

- Seed each new demo session with a curated set of example links that exercise **every** feature:
    - several links with distinct stories (e.g. a launch spike, steady traffic, low traffic),
    - ~90 days of scans with varied countries, devices, OS, referrers and time trends,
    - so overview KPIs, the time-series chart, device/country breakdowns and top-links are all alive.
- **Realistic, not obviously fake**: no "Lorem", no round numbers everywhere. The audience is
  technical — a hollow demo undersells the product.

## Guided interactive tour

- Library: **driver.js** (lightweight, framework-agnostic, permissive/MIT license — matters for an
  open-source MIT repo; avoids the AGPL strings on Intro.js / Shepherd.js).
- The tour is **data-driven** (steps = target selector + i18n key), **skippable and replayable**
  (a "tour" button in the UI).
- Recommended arc — **end hands-on, not just tooltips**:
    1. Overview KPIs — what you're looking at.
    2. A link's analytics — scans, countries, devices over time.
    3. Editing a destination — the core promise: change where it points without reprinting.
    4. The QR code — generate / export.
    5. **Hands-on finish**: "create your own link → get its QR → click it → watch the scan appear →
       change the destination → click again." Clicks hit the demo interstitial (`tessera-demo-mode.md`)
       and log a **simulated scan**, so the visitor sees their own action move the analytics — safely,
       no real redirect.
- End with a clear **self-host CTA**: "Like it? Host your own in minutes → [guide / GitHub]."

## i18n

- All tour text + seeded link names/labels go through **Transloco** (EN/FR/ES/IT/DE). Tour steps
  reference translation keys, never hardcoded strings.

## Coherence / security (inherited from demo-mode)

- The "fake account" is a **demo session**: flagged, scoped by `demoSessionId`, **no real admin
  access, no real auth**, cannot reach privileged endpoints.
- Redirects stay **interstitial** (no real 302). Visitor-created links start at 0 scans; clicking
  the demo QR/interstitial logs a simulated scan so their own link comes alive.
- Everything **resets after 1h of inactivity** (demo-mode lifecycle).

## Out of scope

- Persisting demo progress across sessions.
- Real redirects / real accounts.