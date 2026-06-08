<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The neutral "this link is currently inactive" page, shown for a lapsed code
 * that has no fallback URL set.
 *
 * Deliberately self-contained and calm: no redirect, no tracking, and — by
 * design — NO link to Tessera's marketing site. A scanned code that has lapsed
 * must not become an ad. Returns 200 with a real page (it's terminal content,
 * not a redirect, so the 301-vs-302 rule doesn't apply here).
 *
 * Localized in the scanner's language (CLAUDE.md i18n): the copy comes from the
 * `inactive` translation domain, defaulting to English.
 */
final class InactivePageRenderer
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function render(string $locale = 'en'): Response
    {
        $lang = htmlspecialchars($locale, ENT_QUOTES);
        $title = $this->t('inactive.title', $locale);
        $heading = $this->t('inactive.heading', $locale);
        $body = $this->t('inactive.body', $locale);

        $html = <<<HTML
            <!doctype html>
            <html lang="{$lang}">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex, nofollow">
            <title>{$title}</title>
            <style>
              :root { color-scheme: light dark; }
              html, body { height: 100%; margin: 0; }
              body {
                display: grid; place-items: center;
                font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
                background: #f7f4ec; color: #14211e;
                padding: 24px;
              }
              .card {
                max-width: 28rem; text-align: center; line-height: 1.6;
              }
              h1 { font-size: 1.375rem; font-weight: 600; margin: 0 0 0.5rem; }
              p { margin: 0; color: #43534c; }
              .dot {
                width: 10px; height: 10px; border-radius: 999px;
                background: #6e8b7b; display: inline-block; margin-bottom: 1rem;
              }
              @media (prefers-color-scheme: dark) {
                body { background: #101d19; color: #ece8dc; }
                p { color: #b6c2ba; }
                .dot { background: #7e948a; }
              }
            </style>
            </head>
            <body>
              <main class="card">
                <span class="dot" aria-hidden="true"></span>
                <h1>{$heading}</h1>
                <p>{$body}</p>
              </main>
            </body>
            </html>
            HTML;

        return new Response($html, Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
            'Content-Language' => $locale,
        ]);
    }

    private function t(string $key, string $locale): string
    {
        return htmlspecialchars(
            $this->translator->trans($key, [], 'messages', $locale),
            ENT_QUOTES,
        );
    }
}
