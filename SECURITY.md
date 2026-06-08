# Security Policy

Tessera is a self-hostable redirect service that handles other people's links and
scan data, so we take security seriously. Thank you for helping keep it and its
users safe.

## Supported versions

This is a pre-1.0, single-track project: **security fixes land on `main`** and in
the next tagged release. Please run a recent `main` or the latest release.

| Version            | Supported          |
| ------------------ | ------------------ |
| latest release / `main` | ✅ |
| older tags         | ❌ (please upgrade) |

## Reporting a vulnerability

**Please do not open a public issue, PR, or Discussion for a vulnerability.**

Report it privately through GitHub's **private vulnerability reporting**:

➡️ **[Open a draft security advisory](https://github.com/N0cly/Tessera/security/advisories/new)**
(repo → **Security** tab → **Report a vulnerability**).

If you cannot use that, contact the maintainer **@N0cly** privately and we'll
arrange a secure channel.

Please include, where possible:

- A description of the issue and its impact.
- Steps to reproduce (a minimal PoC, affected endpoint/method, request/response).
- The version / commit you tested.
- Any suggested remediation.

### What to expect

- **Acknowledgement** within **72 hours**.
- An initial assessment and severity within about a week.
- We'll keep you updated on progress and coordinate a disclosure timeline
  (target: a fix and advisory within **90 days**, sooner for high severity).
- Credit in the advisory if you'd like it (let us know how to attribute you).

We ask that you give us reasonable time to fix the issue before any public
disclosure, and that you avoid privacy violations, data destruction, or service
disruption while researching.

## Scope notes

Some properties are intentional design and **not** vulnerabilities:

- `GET /r/{slug}` returns **302** (never 301) by design.
- In **demo mode** (`DEMO_MODE=true`), `/r/{slug}` shows an interstitial instead
  of performing a real redirect — this is deliberate (open-redirect mitigation
  for the public sandbox), not a bug.
- The `scans` table has **no IP column** on purpose; raw scanner IPs are never
  persisted.

Things we *do* want to hear about: authentication/authorization bypass, IDOR /
cross-session data access, the admin panel (2FA, audit log, server-side authz),
the billing webhook signature verification, an open-redirect that escapes the
demo interstitial, secret leakage, SSRF, injection, and anything touching
scan-data privacy.

## Hardening reminders for self-hosters

- Set strong `APP_SECRET` and `JWT_PASSPHRASE` (`openssl rand -hex 32`); never
  commit `.env` or `.env.local`.
- Lock `CORS_ALLOW_ORIGIN` to your real origin in production.
- Keep `APP_ENV=prod` on internet-facing instances (never expose the dev profiler).
- Keep dependencies patched (Dependabot is enabled) and run behind TLS.
