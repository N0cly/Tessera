# Tessera — milestone: i18n (multilingual)

Full site in 5 languages: **EN (default), FR, ES, IT, DE**, with **runtime dynamic switching**
(no reload). Follows `CLAUDE.md` and the design system.

## Library — important

- **Frontend: use a RUNTIME library — Transloco** (recommended) or ngx-translate.
  **Do NOT use Angular's built-in `@angular/localize`** — it is compile-time (one build per
  locale) and cannot switch language at runtime.
- **Backend: Symfony Translation component** for server-side content.

## Frontend (Angular + Transloco)

- Translation files per locale in `assets/i18n/{en,fr,es,it,de}.json`, organized by feature
  namespace. **English = default + fallback** for any missing key.
- Extract **all** hardcoded UI strings into keys: dashboard, landing, pricing, admin, auth,
  forms, error/empty states.
- **Language switcher** in the header (and footer on the public site).
- **Persistence**: logged-in user → a `locale` field on the profile; anonymous → localStorage.
  First visit with no preference → detect from browser `Accept-Language`, fallback to English.
- Register Angular locale data for the 5 locales; format dates / numbers / currency (€) per locale.

## Public pages SEO (landing + pricing only)

- Runtime-only switching = one URL → weak for multilingual SEO. For the **marketing pages**, add
  **locale-prefixed routes** (`/fr/`, `/es/`, …) + `hreflang` tags + localized `<title>`/`<meta>`.
- App pages (dashboard, admin) do **not** need this — runtime switching is enough there.

## Backend (Symfony)

- Translate: transactional **emails**, **validation / API error messages**, the neutral
  **"inactive" fallback page**.
- Store the user's `locale`; send emails + system content in that locale.
- Pass the locale to the **Paddle checkout** so it displays in the user's language.

## Scope notes

- Cross-cutting refactor: it touches **every** component (string → key). Do it now, before more
  strings accumulate.
- **No RTL** needed (all 5 languages are left-to-right).

## Out of scope

- Translating **user-generated content** (a customer's own link names, etc.) — that's their data, not UI.
- Any machine-translation pipeline; translations are maintained JSON/Symfony files.