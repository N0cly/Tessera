# Tessera — milestone: pricing page

Public pricing page with three offers: **Self-host** (free), **Starter**, **Pro**. Prices and
active promotions are read from **Paddle** (single source of truth) — never hardcoded. Includes
a trust FAQ. Follows `tessera-design.md` and uses `tessera-tokens.css`.

## Source of truth: Paddle (do not rebuild this)

- Plans, prices and discounts live in **Paddle**. Create in Paddle to start: **Starter 3€/mo**,
  **Pro 15€/mo** (these are starting values — change them in Paddle anytime, no deploy).
- **Temporary promotions** = Paddle discounts (time-limited). The page reflects active promos
  automatically.
- **Never hardcode prices** (front or back). **Do not build a custom price/promo editor** —
  Paddle's dashboard is that tool. The displayed price MUST always equal what Paddle charges.

## Backend

- `GET /api/pricing` (public): returns the catalog read from Paddle —
  `[{ plan: "starter"|"pro", priceId, amount, currency, interval, promo?: { type, amount, label, endsAt } }]`.
- Read products/prices + active standard discounts from Paddle. **Cache in Redis** (short TTL,
  e.g. 10 min, and/or refresh on a relevant webhook) — never call Paddle on every page view.
- If Paddle can't be read, **fail safe** (don't render a wrong number).

## Frontend (Angular, public route on the landing)

- Fetch `/api/pricing` and render 3 cards: Self-host, Starter, Pro. **No hardcoded prices.**
- If a plan has an active promo: show the discounted price + a small promo badge; else the normal price.
- Monthly/annual toggle only if annual prices exist in Paddle.
- CTAs: Self-host → GitHub / self-host guide; Starter & Pro → start the 14-day trial (existing
  billing checkout from the billing milestone).
- Trust FAQ (at minimum):
    - "What happens if I stop paying?" → your codes fall back to a URL you choose (option B), and
      you can always self-host (open source).
    - "Does the trial charge me?" → no, 14 days, no card.
- Style strictly via `tessera-tokens.css` + `tessera-design.md`: Fraunces headings, teal accent,
  light **and** dark.

## Card content

- **Self-host (0€)**: unlimited codes, full core, open source (MIT), your data / your server. CTA → GitHub.
- **Starter**: full core, complete analytics, fallback URL, EU hosting, [Starter code limit]. CTA → trial.
- **Pro**: everything in Starter + custom domain, QR branding, team members, priority support,
  [Pro code limit]. CTA → trial.

## Feature limits ≠ prices

- Prices come from **Paddle**. **Feature/usage limits** (e.g. 10 vs 100 codes) are app business
  rules → keep them in **one app-side config**, reused by BOTH the pricing copy and the billing
  limit enforcement. No duplicated hardcoded limits.

## Out of scope — important

- The **Pro features themselves** (custom domain, QR branding, teams) are NOT built yet — separate
  milestones. On the page, mark unbuilt Pro features as **"coming soon"**; do not sell features
  that don't work.
- A custom price/promo admin (Paddle handles it).
- The general site admin panel (separate future milestone, scope TBD).