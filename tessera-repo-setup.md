# Tessera — repo & community setup

Blueprint for turning the repo into a healthy, contributable open-source project.

## Branching (recommended)

- **`main`** — default, protected, always releasable. **All PRs (yours and external) target it.**
- **Staging / "val"** — deployed automatically from `main` (an *environment*, not a long-lived branch).
- **Production** — deployed from **version tags / GitHub Releases** (e.g. `v1.0.0`), not a branch people commit to.
- If you insist on env branches, make `staging` / `production` **fast-forward-only deploy pointers**
  advanced by CI from `main` — never PR targets.

## Branch protection on `main`

- Require a PR before merging; no direct pushes.
- Require **≥1 approving review**; dismiss stale approvals on new commits.
- Require status checks green: **lint, format, type-check, tests, build, CodeQL**.
- Require branches up to date before merge.
- Block force-pushes and branch deletion.
- Prefer **squash-merge** (clean linear history).
- Optional: require signed commits.

## `.github/` files

- `CODEOWNERS` — `* @N0cly` so you're auto-requested on every PR.
- `PULL_REQUEST_TEMPLATE.md` — what/why, screenshots, checklist (tests, docs, i18n keys present in all 5 langs, no 301, prettier/cs-fixer run).
- `ISSUE_TEMPLATE/` — `bug_report.yml`, `feature_request.yml`, `config.yml` (link to Discussions).
- `FUNDING.yml` — optional (GitHub Sponsors for hosting costs).
- `dependabot.yml` — npm (frontend) + composer (backend) + github-actions, weekly.
- `workflows/ci.yml` — lint / format / type-check / tests / build on PR + push to `main`. **Secret-free** so it runs on fork PRs.
- `workflows/codeql.yml` — code scanning.
- `workflows/claude-review.yml` — `anthropics/claude-code-action@v1` auto PR review on `pull_request` [opened, synchronize].

## Community / docs files

- `CONTRIBUTING.md` — dev setup (`docker compose`), how to run tests/lint/format, coding standards,
  **DCO sign-off (`git commit -s`)**, commit/PR conventions, and the architecture invariants.
- `CODE_OF_CONDUCT.md` — Contributor Covenant v2.1.
- `SECURITY.md` — private vulnerability reporting, supported versions, response expectations.
- `docs/ARCHITECTURE.md` — overview + **invariants**: 302-only (never 301), redirect outside API
  Platform, async scan logging (Messenger), Redis slug cache + invalidation, no raw IP, feature
  flags (`DEMO_MODE`, `BILLING_ENABLED`), demo mode (per-session sandbox + interstitial).

## GitHub settings to enable

- Dependabot alerts + security updates
- CodeQL / code scanning
- Secret scanning + **push protection**
- **Private vulnerability reporting**
- Discussions
- Labels: `good first issue`, `help wanted`, `bug`, `enhancement`, `docs`, `security` — then seed 2–3 good-first-issues.

## License / contributions

- `LICENSE` = **MIT** (lock it now — relicensing after external merges needs every contributor's consent).
- **DCO** sign-off required (lightweight). Optionally enforce with the DCO GitHub App.
- CLA only if you later want relicensing / commercial rights — not the current path.

## PR automation specifics

- **Blocking checks** = CI + CodeQL via branch protection.
- **Claude review**: `claude /install-github-app`, add `ANTHROPIC_API_KEY` secret, workflow on
  `pull_request` [opened, synchronize]. Defaults to Sonnet; set `model: claude-opus-4-8` if wanted.
- ⚠️ **Fork safety**: secrets aren't exposed to PRs from forks, so the Claude review and any
  secret-dependent job won't auto-run on external contributors' PRs — they need maintainer
  "Approve and run". Keep core CI (lint/tests/build) secret-free so it always runs.