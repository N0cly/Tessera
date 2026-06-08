# Tessera — milestone: fallback (option B)

When a subscription lapses, the user's QR codes redirect to an **owner-chosen fallback URL**
instead of breaking — honest degradation that keeps the brand promise. Depends on
`Subscription.status` (billing milestone) and touches the redirect hot path (M1) + Redis (M3).

## Can be built without Paddle configured

The logic depends only on `Subscription.status` + dates, not on the payment provider. To
develop and test, seed a subscription in each state (`active`, `expired`, `canceled`) by hand.

## Scope — build this

### Data model
- Add `Link.fallbackUrl` (nullable). Validate it is `http`/`https` (reuse M1/M5b validation).

### Redirect logic (the core) — `GET /r/{slug}`
Resolve the target from the owner's subscription state:
- `trialing` / `active`, or `past_due`/`canceled` **still within grace** → `destinationUrl` (normal).
- lapsed (beyond grace, or `expired`) → `fallbackUrl` if set, else the neutral "inactive" page.
- Always **302**, never 301.
- **Grace period: 30 days** after `currentPeriodEndsAt` before switching to fallback.

### Hot-path performance (the key pitfall)
Do NOT join the subscription table on every scan. Cache in Redis, per slug, everything needed
to decide **at read time without a DB hit**:
`{ destinationUrl, fallbackUrl, graceEndsAt }` (+ a flag for the owner's lapsed state).
At read time, compare `now` to `graceEndsAt` to pick destination vs fallback — a cheap timestamp
comparison, no query. This makes the time-based grace boundary self-resolving.
Invalidate / refresh this cache entry when:
- the link's `destinationUrl` or `fallbackUrl` is edited,
- the owner's subscription status changes (the **billing webhook must bust the owner's links' cache**).

### UI
- Link settings: a `fallbackUrl` field, with a one-line explanation:
  "If your subscription lapses, this code redirects here instead of breaking."
- Dashboard plan widget: surface the default fallback state (already stubbed in the dashboard milestone).

### Neutral "inactive" page
- A minimal hosted page ("this link is currently inactive") for lapsed links with no fallback set.
- On-brand, calm, **no marketing hijack** — never redirect to Tessera's own marketing site.

## Out of scope
- Dunning / renewal emails. Bulk fallback editing.

## Pitfalls to respect
- Hot path stays fast: decide from the Redis-cached data + a timestamp compare, never a per-scan join.
- The billing webhook is now also a **cache-invalidation trigger** for the owner's links.
- Test the boundary explicitly: active → within grace (still destination) → past grace (fallback) → fallback-empty (inactive page).