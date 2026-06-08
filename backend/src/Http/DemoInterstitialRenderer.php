<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\FeatureFlags;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The safe interstitial shown for /r/{slug} when DEMO_MODE is on
 * (tessera-demo-mode.md — CRITICAL). Instead of a real 302 to a user-set
 * destination (an open-redirect → phishing/malware liability), it shows
 * "Tessera demo — this code would redirect to <destination>" and performs NO
 * navigation. The full mechanic is demonstrated; the redirect liability is not.
 *
 * The destination is shown as inert, escaped text — never a clickable link and
 * never a redirect — so the demo can't be weaponized into an open redirector.
 */
final class DemoInterstitialRenderer
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly FeatureFlags $flags,
    ) {
    }

    public function render(string $destination, string $locale = 'en'): Response
    {
        $lang = htmlspecialchars($locale, ENT_QUOTES);
        $title = $this->t('demo.interstitial.title', $locale);
        $heading = $this->t('demo.interstitial.heading', $locale);
        $body = $this->t('demo.interstitial.body', $locale);
        $note = $this->t('demo.interstitial.note', $locale);
        $return = $this->t('demo.interstitial.return', $locale);
        $cta = $this->t('demo.interstitial.selfHost', $locale);
        $dest = htmlspecialchars($destination, ENT_QUOTES);
        $github = htmlspecialchars($this->flags->githubUrl(), ENT_QUOTES);

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
                background: #f7f4ec; color: #14211e; padding: 24px;
              }
              .card { max-width: 32rem; text-align: center; line-height: 1.6; }
              .badge {
                display: inline-block; font-size: 0.75rem; font-weight: 600;
                letter-spacing: 0.04em; text-transform: uppercase;
                color: #137a5b; background: #e1f0ea;
                padding: 0.25rem 0.625rem; border-radius: 999px; margin-bottom: 1rem;
              }
              h1 { font-size: 1.375rem; font-weight: 600; margin: 0 0 0.75rem; }
              p { margin: 0 0 0.75rem; color: #43534c; }
              .dest {
                display: block; font-family: "JetBrains Mono", ui-monospace, monospace;
                font-size: 0.9375rem; word-break: break-all;
                background: #ffffff; border: 1px solid #e4decf; border-radius: 10px;
                padding: 0.75rem 1rem; margin: 0 0 1rem; color: #14211e;
              }
              .note { font-size: 0.8125rem; color: #6e8b7b; }
              .return { margin-top: 1.25rem; margin-bottom: 0; }
              a { color: #137a5b; }
              @media (prefers-color-scheme: dark) {
                body { background: #101d19; color: #ece8dc; }
                p { color: #b6c2ba; } .note { color: #7e948a; }
                .badge { color: #4fc9a2; background: #16302a; }
                .dest { background: #172620; border-color: #283a32; color: #ece8dc; }
                a { color: #4fc9a2; }
              }
            </style>
            </head>
            <body>
              <main class="card">
                <span class="badge">{$title}</span>
                <h1>{$heading}</h1>
                <p>{$body}</p>
                <code class="dest">{$dest}</code>
                <p class="note">{$note}</p>
                <p class="note"><a href="{$github}" rel="noopener">{$cta}</a></p>
                <p class="note return">{$return}</p>
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
