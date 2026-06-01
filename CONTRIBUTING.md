# Contributing to qr-code-redirect

Thanks for considering a contribution! This is a small, opinionated
project — a quick read of this file plus [`CLAUDE.md`](CLAUDE.md) will
save us both time.

## Before you start

- **Open an issue first** for anything non-trivial (new endpoint,
  schema change, dependency bump, UI redesign). A two-line "I'd like to
  do X, that ok?" beats a 500-line PR that has to be rewritten.
- **Read [`CLAUDE.md`](CLAUDE.md)** — it lists the load-bearing
  architectural rules (302-not-301 on redirect, Redis-first hot path,
  scan IPs are never persisted, QR encodes the permanent URL, etc.).
  Breaking one of those is not a style nit; it's the product.
- **Check the roadmap in [`README.md`](README.md#roadmap)** — items
  marked "Planned" are explicitly out of v1 scope. PRs that add them
  need a design conversation first; PRs that quietly slip them in get
  closed.

## Local dev

```bash
cp .env.example .env
docker compose up -d --build
```

That's it — no host PHP, no host Node, no host composer required.
Migrations and JWT keys are generated automatically on first boot
(see the `backend/docker/entrypoint.sh` script).

| Want to                          | Command                                                                |
| -------------------------------- | ---------------------------------------------------------------------- |
| Tail backend logs                | `docker compose logs -f backend`                                       |
| Tail worker / cron logs          | `docker compose logs -f worker`  /  `docker compose logs -f cron`      |
| Open a shell in the backend     | `docker compose exec backend sh`                                       |
| Run a console command            | `docker compose exec backend bin/console <cmd>`                        |
| Make a migration                 | `docker compose exec backend bin/console make:migration`               |
| Apply migrations manually        | `docker compose exec backend bin/console doctrine:migrations:migrate -n` |
| Manually trigger the demo purge  | `docker compose exec backend bin/console app:demo:purge`               |
| Frontend build                   | `docker compose exec frontend npx ng build`                            |

After Dockerfile or Composer/npm dependency changes:
`docker compose up -d --build` (the `--build` matters).

## Style

- **PHP:** PSR-12, typed properties, constructor injection, no
  setter-injected services.
- **Angular:** standalone components, signals where it makes sense,
  no `any`, no `@HostBinding` magic — keep templates readable.
- **Commits:** [conventional
  commits](https://www.conventionalcommits.org/) — `feat:`, `fix:`,
  `chore:`, `docs:`, `refactor:`, `test:`. One logical change per
  commit; tiny "fix typo" amendments are fine.
- **Comments:** explain *why*, not *what*. If the code needs a comment
  to be understandable, prefer renaming the variable.

## What we accept

- Bug fixes — always welcome. Include a reproduction in the PR
  description.
- Test additions — also always welcome.
- Documentation fixes — yes, including typos.
- Performance work on the hot path (`GET /r/{slug}`) — happy to look,
  bring numbers.
- New self-host knobs (env vars) that solve a real problem — go for
  it, mention it in the issue first.

## What needs a conversation first

- Anything that changes the public API shape.
- Anything that touches scan-recording or storage (privacy invariants).
- New dependencies — minimise these; PHP and Node ecosystems both have
  enough churn already.
- UI redesigns. The dashboard is intentionally plain.

## What we close on sight

- Items from the "Planned" roadmap, submitted without a prior issue.
- Adding analytics/telemetry that phones home.
- Anything that breaks one of the architectural rules in `CLAUDE.md`.

## Submitting a PR

1. Fork, branch from `main`, name the branch `<type>/<short-slug>`
   (e.g. `feat/wifi-qr-types`, `fix/redirect-302-cache`).
2. Make sure `docker compose up` boots a working app at the end of
   your change — that's the smoke-test bar.
3. Push, open a PR linking the relevant issue, describe the *why*.
4. Be patient — review may take a few days.

Thanks again. 🙏
