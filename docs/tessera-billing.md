# Tessera — milestone: billing (Merchant of Record)

Add subscriptions + free trial through a **Merchant of Record** (recommended: Paddle;
Lemon Squeezy is equivalent). The MoR handles hosted checkout, card data (PCI), and all
tax/VAT. Tessera only stores subscription **status** and reacts to **webhooks**.

## What we do NOT build (the MoR does it)

- No card handling, no PCI scope, no tax/VAT logic, no payment form. All payment UI is the
  MoR's hosted checkout. We never see card data.

## Scope — build this

### Data model
- `Subscription` (one per user — or fields on `User`):
    - `plan` (e.g. `free_trial`, `pro`)
    - `status` enum: `trialing | active | past_due | canceled | expired`
    - `trialEndsAt`, `currentPeriodEndsAt` (nullable datetimes)
    - `providerCustomerId`, `providerSubscriptionId` (strings)
    - `updatedAt`
- New user → `status = trialing`, `trialEndsAt = now + 14 days`.

### Checkout
- "Start trial / subscribe" action → redirect to the MoR **hosted checkout**, passing a
  reference to the current user so the webhook can map the result back.
- On return from checkout, show a pending/active state. **Do not** grant access based on the
  redirect — the webhook is the source of truth.

### Webhooks (source of truth)
- `POST /api/webhooks/billing`:
    - **Verify the provider signature** before processing (reject if invalid). This is security-critical.
    - Map provider events (subscription created/updated/canceled, payment succeeded/failed) to
      `Subscription.status` + the date fields.
    - **Idempotent** (same event delivered twice must not double-apply).

### Wiring
- Plan & usage widget (built presentational in the dashboard milestone) now reads the real
  `Subscription`: plan, trial days left (`trialEndsAt - now`), codes used / plan limit.
- Enforce plan limits: block creating links beyond the plan's code limit with a clear error;
  the UI shows an upgrade prompt.
- Billing section in the dashboard: current plan + status + a "manage subscription" button
  (link to the MoR's customer portal if available).

## Decision to confirm at implementation
- **Trial with or without card.** Without card = lower friction, more trials, but the user
  must actively subscribe after. With card = smoother auto-conversion. Default suggestion:
  without card (favor adoption), since lapse is handled gracefully by the fallback milestone.

## Out of scope — separate milestone

- The **option B fallback redirect** logic (destination → fallback on expiry). It depends on the
  `status` produced here; it's the next milestone.
- Dunning emails, proration UI, invoices, currency UI — the MoR handles these.

## Security / config notes

- API keys + webhook signing secret via env vars (`.env.local` / GitHub Secrets), never committed.
- The webhook signature check is mandatory — an unverified billing webhook is an open door.
- Store `providerCustomerId` / `providerSubscriptionId` to reconcile and to deep-link the portal.