# Tessera — milestone: admin panel (operator)

A private, **operator-only** panel for you to see service-wide health: revenue, customers,
product usage. **Read-only** for v1. Follows `tessera-design.md` / `tessera-tokens.css`.

## Scope split — don't rebuild what Paddle gives you

- **Revenue / subscription metrics** → Paddle is the source. Pull key KPIs via the Paddle API
  (cached) for a unified glance; do NOT recompute revenue from raw payments. Paddle's own
  reporting stays the deep-dive tool.
- **Product usage** (links, scans, per-customer) → your DB. This is what Paddle can't give you,
  so it's the real value of a custom panel.

## Content (read-only v1)

- **Business** (from Paddle, cached): MRR, active subscriptions by plan, trials in progress,
  trial→paid conversion, churn, failed payments.
- **Customers** (your DB): total users, signups over time, by plan/status, customer list
  (minimal fields), churned customers.
- **Usage** (your DB): total links, total scans, scans over time (platform-wide), top customers
  by usage, active codes.

## Security — CRITICAL (this panel is the highest-value target in the app)

- Dedicated `admin` role. **Not assignable** via signup or any user-facing flow — granted
  out-of-band (CLI / DB / env allowlist).
- **Server-side authorization on every admin endpoint.** Never rely on hiding the UI.
- **2FA required** for admin accounts.
- **Audit log**: record admin logins and any access to customer data.
- Admin routes isolated under `/admin`; consider an IP allowlist. Rate-limit; no user enumeration.

## Privacy / GDPR — privacy-first applies internally too

- **Data minimization**: prefer aggregates; expose individual customer PII only where
  operationally necessary, and log that access.
- Scans store no raw IP (M3) — keep it. The panel cannot and must not show scanner identities.

## Out of scope (v1)

- Management actions (suspend a user, issue a refund, change a plan) → do these in Paddle for now.
  Refunds especially belong in Paddle.
- Content / feature-flag management, support tooling → separate future milestones.

## Design

- Reuse `tessera-tokens.css` + `tessera-design.md` and the aggregation patterns from the user
  dashboard, but **platform-wide** and on locked-down `/admin` routes. Light + dark.