# Tessera — milestone: demo mode (ephemeral per-session sandbox)

The hosted instance runs as a **public demo**: each visitor gets an isolated, pre-seeded
workspace; changes are scoped to their session and never affect others; the session resets after
**1h of inactivity**. Self-host default = demo OFF. Follows `CLAUDE.md` and the design system.

## Feature flags

- `DEMO_MODE` — off by default; ON only on the hosted demo instance.
- Billing / subscriptions stay flagged **OFF** everywhere for now (separate flag). No revenue.

## Session model

- Anonymous demo session created on entering the demo (cookie / opaque token). **No signup, no
  email/password, no PII.**
- Each session = its own ephemeral workspace, **seeded from a fixed template** (a few example
  links with sample scan history) so the dashboard and analytics are populated immediately
  ("compte défini").
- All demo-created entities carry a `demoSessionId`; **every query is scoped to it.** A session
  can never read or modify another session's data.

## Reset / lifecycle

- Track `lastActivityAt` per session. After **1h of inactivity**, purge the session's workspace
  (links, scans, session row). Returning later → a fresh seeded workspace.
- Cleanup runs as a **scheduled job** (Symfony scheduler / cron command) AND lazily on access (defensive).

## Redirect safety — CRITICAL (session isolation does NOT cover this)

- `/r/{slug}` is **global and public** — anyone with a slug can hit it, outside any session.
  Session isolation protects dashboard data, **not** the redirect. A real 302 to a user-set
  destination = open redirect → phishing/malware with your domain.
- In demo mode, demo links **do NOT perform a real external redirect**. `/r/{slug}` for a demo
  link resolves to a **safe interstitial** ("Tessera demo — this code would redirect to
  `<destination>`") and **logs a simulated scan** so analytics still demonstrate.
- The full mechanic (editable destination, QR, scan analytics) is shown; the open-redirect
  liability is removed.

## Abuse guardrails

- Per-session quota (e.g. max N demo links).
- **Rate-limit demo-session creation per IP** + cap concurrent sessions (prevent mass session spam).
- Keep existing validation (http/https-only, domain checks) on stored destinations even though
  they are never actually followed.

## UX

- Persistent **demo banner**: "Demo — your data is isolated and resets after 1h of inactivity.
  Self-host for the real thing → [link]."
- Hide features that don't make sense in demo (real account settings; billing UI already off).

## Out of scope

- Real accounts / persistence in demo.
- Live external redirects in demo (intentionally replaced by the interstitial).