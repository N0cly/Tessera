// Assembles per-namespace translation parts into one JSON file per locale.
//
// Source:  public/assets/i18n/_parts/*.json
//          each: { "namespace": "<ns>", "translations": { "en": {...}, "fr": {...}, ... } }
// Output:  public/assets/i18n/{en,fr,es,it,de}.json
//          each: { "<ns>": { ...keys }, ... }
//
// English is the fallback (configured in provideTransloco), so a key missing
// from a non-English part simply resolves to en at runtime.
import { readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const LANGS = ['en', 'fr', 'es', 'it', 'de'];
const i18nDir = join(dirname(fileURLToPath(import.meta.url)), '..', 'public', 'assets', 'i18n');
const partsDir = join(i18nDir, '_parts');

function deepMerge(target, source) {
  for (const [k, v] of Object.entries(source ?? {})) {
    if (v && typeof v === 'object' && !Array.isArray(v)) {
      target[k] = deepMerge(target[k] && typeof target[k] === 'object' ? target[k] : {}, v);
    } else {
      target[k] = v;
    }
  }
  return target;
}

const out = Object.fromEntries(LANGS.map((l) => [l, {}]));

const parts = readdirSync(partsDir)
  .filter((f) => f.endsWith('.json'))
  .sort();

for (const file of parts) {
  const part = JSON.parse(readFileSync(join(partsDir, file), 'utf8'));
  const ns = part.namespace;
  if (!ns) throw new Error(`${file}: missing "namespace"`);
  for (const lang of LANGS) {
    const t = part.translations?.[lang];
    if (t) out[lang][ns] = deepMerge(out[lang][ns] ?? {}, t);
  }
}

for (const lang of LANGS) {
  writeFileSync(join(i18nDir, `${lang}.json`), JSON.stringify(out[lang], null, 2) + '\n');
}

console.log(`Merged ${parts.length} part(s) → ${LANGS.length} locale files: ${parts.join(', ')}`);
