<!--
Thanks for contributing to Tessera! Keep PRs focused — one logical change each.
Open an issue first for anything non-trivial (see CONTRIBUTING.md).
-->

## What & why

<!-- What does this change do, and why? Link the issue it closes. -->

Closes #

## Screenshots / recordings

<!-- For any UI change, before/after. Delete if not applicable. -->

## Checklist

- [ ] I read [CONTRIBUTING.md](../blob/main/CONTRIBUTING.md) and my change respects the architectural invariants in [`CLAUDE.md`](../blob/main/CLAUDE.md) / [`docs/ARCHITECTURE.md`](../blob/main/docs/ARCHITECTURE.md).
- [ ] Tests pass and I added/updated tests for the change (`bin/phpunit` / `ng test`).
- [ ] Static analysis & formatting pass: backend `vendor/bin/phpstan analyse` (code is PSR-12); frontend `npm run lint`, and I ran `npm run format` (prettier).
- [ ] Docs updated where relevant (README / CLAUDE.md / .env.example for a new env knob).
- [ ] **i18n:** any new user-facing string is a translation key present in **all 5** locales (`en, fr, es, it, de`) — re-ran `node frontend/scripts/merge-i18n.mjs`.
- [ ] **Redirect invariant:** I did not introduce a `301` (the hot path is **always `302`**), did not move `/r/{slug}` into API Platform, and did not persist a raw scanner IP.
- [ ] My commits are **signed off** (DCO): `git commit -s` (adds a `Signed-off-by:` line).
- [ ] `docker compose up` still boots a working app from a clean clone.
