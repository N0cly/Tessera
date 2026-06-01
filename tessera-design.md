# Tessera — design system

Rules for implementing the UI (dashboard + landing). This file is the **rules**;
`tessera-tokens.css` holds the **values**. For any visual decision, this file wins over a chat prompt.

## Principles (the why)

Tessera is a privacy-first, self-hostable tool — the design must *earn trust*:
- Clarity over decoration. Generous whitespace, no clutter.
- Calm and honest. No hype, no dark patterns, no fake urgency.
- Competence signals trust: alignment, consistency and polish are features, not nice-to-haves.
- Reassure on permanence: make "codes never expire" and "your data stays yours" visible.

## Tokens — never hardcode

- Every color comes from `tessera-tokens.css`. Use `var(--color-…)` everywhere. NEVER write a raw hex in a component.
- Every font via `--font-…`, every radius via `--radius-…`.
- Light and dark mode must both work (tokens handle it via `prefers-color-scheme`). Check every screen in both.

Color roles:
- `--color-bg` — page background
- `--color-surface` — cards, panels, inputs
- `--color-ink` — primary text
- `--color-ink-soft` — secondary text
- `--color-muted` — tertiary text / hints
- `--color-border` — hairlines, separators
- `--color-accent` — primary actions & key highlights
- `--color-accent-strong` — hover / active state of accent
- `--color-accent-soft` — tinted backgrounds (badges, the slug field)
- `--color-danger` / `--color-warning` — semantic states only

Accent is for action and emphasis, not large fills. Most of the UI is `bg` + `surface` + `ink`.
A little teal goes a long way.

## Typography

- `--font-display` (Fraunces): headings and the wordmark ONLY. Never body text.
- `--font-sans` (Hanken Grotesk): all body, UI, labels, buttons.
- `--font-mono` (JetBrains Mono): slugs, short URLs, codes, technical values only.
- Weights: 400 regular, 500/600 emphasis. Never heavier than 600.
- Sentence case everywhere. Never ALL CAPS, never Title Case.
- Scale: display 40 / 32 · h1 28 · h2 22 · h3 18 · body 16 · small 14 · tiny 13 (px).
  Line-height ~1.6 for body, ~1.2 for headings.

## Spacing & layout

- Spacing scale (px): 4, 8, 12, 16, 24, 32, 48, 64. Stick to it.
- Generous whitespace beats density. Let things breathe.
- Max content width ~1100px; text columns ~680px for readability.
- Radius: `--radius-sm` small controls · `--radius-md` buttons/fields · `--radius-lg` cards.

## Components

- Buttons
    - Primary: background `--color-accent`, light text; hover `--color-accent-strong`. One primary per view (the main action).
    - Secondary: transparent background, `--color-border` outline, `--color-ink` text; hover = subtle surface tint.
    - Radius `--radius-md`, comfortable padding (e.g. 10px 16px). Disabled = reduced opacity, no pointer.
- Inputs / fields: `--color-surface` bg, 1px `--color-border`, radius-md, ink text.
  Visible focus ring: `box-shadow: 0 0 0 3px var(--color-accent-soft)`. Always show focus (a11y).
- Cards / panels: `--color-surface` bg, hairline `--color-border`, `--radius-lg`, generous padding.
- Links: `--color-accent`; underline on hover.
- Slug / short URL: `--font-mono`, on `--color-accent-soft` background, with a copy button next to it.
- QR preview: render on `--color-surface` (or white) with its quiet zone (padding) intact.
  Never crop the quiet zone, never place a QR on a busy/low-contrast background — it must stay scannable.
- Prefer thin borders over shadows. No gradients, no glow, no heavy drop shadows.

## Accessibility

- Body text meets WCAG AA contrast against its background, in both modes.
- Visible keyboard focus on every interactive element (`:focus-visible`).
- Minimum font-size 13px. Don't disable zoom. Use semantic HTML (`button`, `nav`, `main`, `label`).
- Respect `prefers-reduced-motion`: keep motion subtle and skippable.

## Don't

- Hardcode hex or font values in components.
- Use Fraunces for body text or paragraphs.
- Use weights above 600, ALL CAPS, or Title Case.
- Loud gradients, neon, heavy shadows, or busy backgrounds.
- Dark patterns (fake countdowns, pre-checked opt-ins, hidden costs, buried unsubscribe).
  This is a privacy tool — the UI must behave like one.