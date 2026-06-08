import { DEFAULT_LANG, SUPPORTED_LANGS, isLang, matchLang } from './locale';

describe('locale helpers', () => {
  it('has English as the default language', () => {
    expect(DEFAULT_LANG).toBe('en');
    expect(SUPPORTED_LANGS).toContain('en');
  });

  it('isLang recognizes only supported base codes', () => {
    expect(isLang('fr')).toBe(true);
    expect(isLang('de')).toBe(true);
    expect(isLang('pt')).toBe(false);
    expect(isLang('fr-CA')).toBe(false);
    expect(isLang(null)).toBe(false);
    expect(isLang(42)).toBe(false);
  });

  it('matchLang maps BCP-47 tags to a supported language', () => {
    expect(matchLang('fr')).toBe('fr');
    expect(matchLang('fr-CA')).toBe('fr');
    expect(matchLang('DE')).toBe('de');
    expect(matchLang('es-419')).toBe('es');
    expect(matchLang('it-IT')).toBe('it');
  });

  it('matchLang returns null for unsupported or empty input', () => {
    expect(matchLang('pt')).toBeNull();
    expect(matchLang('zh-CN')).toBeNull();
    expect(matchLang('')).toBeNull();
    expect(matchLang(null)).toBeNull();
    expect(matchLang(undefined)).toBeNull();
  });
});
