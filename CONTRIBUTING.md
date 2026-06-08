# Contributing to Tessera

Thanks for considering a contribution! This is a small, opinionated
project — a quick read of this file plus [`CLAUDE.md`](CLAUDE.md) and
[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) will save us both time.

By participating you agree to abide by our
[Code of Conduct](CODE_OF_CONDUCT.md). To report a security issue, use
private reporting — see [`SECURITY.md`](SECURITY.md), never a public issue.

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

## Tests, lint & format

Run the same checks CI runs (`.github/workflows/ci.yml`) before you push — all
green is the merge bar. Everything runs inside the containers:

**Backend (Symfony):**

```bash
docker compose exec backend bin/phpunit                 # tests
docker compose exec backend vendor/bin/phpstan analyse  # static analysis (type-check)
```

PHP follows **PSR-12** (typed properties, constructor injection); keep PHPStan green.

**Frontend (Angular):**

```bash
docker compose exec frontend npm run lint               # prettier --check
docker compose exec frontend npm run format             # prettier --write (auto-fix)
docker compose exec frontend npx ng test --watch=false  # unit tests
docker compose exec frontend npx ng build               # type-check + production build
```

If you touch UI strings, rebuild the i18n locale files and keep all five locales
at key parity:

```bash
docker compose exec frontend node scripts/merge-i18n.mjs
```

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

## Sign your commits (DCO)

This project uses the [Developer Certificate of Origin](https://developercertificate.org/)
— a lightweight, one-line affirmation that you wrote the patch or have the right
to submit it under the project's MIT license. (No CLA, no copyright assignment.)

Sign off **every** commit with `-s`, which appends a `Signed-off-by` trailer
using your real name and the email on your commits:

```bash
git commit -s -m "fix: warm the slug cache after a destination edit"
```

This adds:

```
Signed-off-by: Your Name <you@example.com>
```

Forgot to sign off? Amend the last commit with `git commit -s --amend`, or sign
off a whole branch with `git rebase --signoff main`. PRs whose commits aren't
signed off can't be merged.

## Submitting a PR

1. Fork, branch from `main`, name the branch `<type>/<short-slug>`
   (e.g. `feat/wifi-qr-types`, `fix/redirect-302-cache`).
2. Run the tests / lint / build above, and make sure `docker compose up` boots a
   working app at the end of your change — that's the smoke-test bar.
3. **Sign off** your commits (`git commit -s`).
4. Push, open a PR linking the relevant issue, fill in the template, describe the *why*.
5. Be patient — review may take a few days. The Claude review bot and CI will
   weigh in automatically (on PRs from forks, a maintainer triggers them).

Thanks again. 🙏
