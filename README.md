# Tessera

> [![CI](https://github.com/N0cly/Tessera/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/N0cly/Tessera/actions/workflows/ci.yml)
> [![CodeQL](https://github.com/N0cly/Tessera/actions/workflows/codeql.yml/badge.svg?branch=main)](https://github.com/N0cly/Tessera/actions/workflows/codeql.yml)
> [![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
> Status: **v1** — works end-to-end, ready to self-host.
> 
**Privacy-first, self-hostable dynamic QR codes** — change where a code points *after* it's
printed, and see how it's scanned, without handing your data (or your audience) to anyone.

## Why Tessera

A printed QR code is forever — unless it's *dynamic*. Tessera codes redirect through a short link
you control, so you can change the destination anytime without reprinting. And the redirect is
privacy-first by design:

- **Your codes always resolve.** No hostage model: if a hosted plan lapses, codes fall back to a
  URL *you* choose — never to our marketing page.
- **No tracking creep.** A country is derived from the IP for analytics, then the IP is discarded.
  No raw identifiers stored.
- **Your data, your server.** Self-host the whole thing under MIT. The hosted version is only a demo.

## Features

- Dynamic redirects (HTTP 302) with an editable destination
- QR generation + export (PNG / SVG)
- Privacy-preserving scan analytics: scans over time, country, device, referrer
- Account dashboard + per-code analytics
- Owner-controlled fallback URL
- 5 UI languages (EN / FR / ES / IT / DE), switchable at runtime
- One-command self-host via Docker

## Live demo

A sandboxed, guided demo [Tessera](https://www.tessera.nocly.fr). Data is isolated per session and resets after 1h of inactivity.

## Tech stack

- **Backend:** Symfony 7, API Platform 4, PHP 8.x
- **Frontend:** Angular + Transloco (runtime i18n)
- **Data:** PostgreSQL, Redis (hot-path slug cache + async scan logging via Symfony Messenger)
- **Geo:** MaxMind GeoLite2 (country only, local lookup)
- **Runtime:** Docker / docker compose

## Quickstart (self-host)

Prerequisites: Docker + Docker Compose.

```bash
git clone https://github.com/N0cly/Tessera.git
cd Tessera
cp .env.example .env          # then set your secrets in .env (or .env.local)
docker compose up -d          # migrations + JWT keys run automatically on first boot
```

App: `http://localhost:<port>` · API: `…/api` · Redirects: `…/r/{slug}`

> **GeoLite2 is not bundled** (MaxMind license). Country lookups are optional: download the free
> `GeoLite2-Country.mmdb` from MaxMind and drop it at `backend/var/geoip/GeoLite2-Country.mmdb`
> (the stack runs fine without it — `country` just stays `NULL`). See Configuration.

## Configuration (key env vars)

| Var | Purpose |
|-----|---------|
| `APP_BASE_URL` | Public base URL encoded into QR codes |
| `DATABASE_URL` | PostgreSQL connection |
| `REDIS_URL` | Redis connection |
| `GEOIP_DATABASE_PATH` | Path to a manually-provided GeoLite2-Country.mmdb (optional country lookup) |
| `DEMO_MODE` | `false` for normal self-host; `true` only for a public demo |
| `BILLING_ENABLED` | `false` — subscriptions are disabled in the OSS build |

Commit non-secret defaults in `.env`; keep secrets in `.env.local` (gitignored).

## Architecture

See `docs/ARCHITECTURE.md` for the full picture. A few **invariants** contributors must preserve:

- Redirects are **always 302**, never 301 (destinations are mutable; a cached permanent redirect breaks the product).
- The redirect endpoint is a plain controller, **not** API Platform (hot path / latency).
- Scan logging is **async** (Symfony Messenger) — never block the redirect.
- **Never store raw IPs** — derive a country, then discard.

## Contributing

PRs welcome! Read [`CONTRIBUTING.md`](CONTRIBUTING.md) and [`CODE_OF_CONDUCT.md`](CODE_OF_CONDUCT.md)
first. New here? Look for the
[`good first issue`](https://github.com/N0cly/Tessera/labels/good%20first%20issue) label.

## Security

Found a vulnerability? See `SECURITY.md` for **private** reporting — please don't open a public issue.

## License

MIT — see `LICENSE`. GeoLite2 data is subject to MaxMind's license; required attribution:
"This product includes GeoLite2 data created by MaxMind, available from https://www.maxmind.com".

## Roadmap

- **V1 (done):** dynamic codes, analytics, QR, runtime i18n, self-host, guided demo.
- **Designed but disabled:** a hosted billing layer (custom domain, QR branding, teams) — built
  behind a flag, currently off. Self-host is the free offer.