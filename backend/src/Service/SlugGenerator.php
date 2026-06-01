<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\LinkRepository;

final class SlugGenerator
{
    private const ALPHABET = '23456789abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
    private const DEFAULT_LENGTH = 7;
    private const MAX_ATTEMPTS = 10;

    public function __construct(private readonly LinkRepository $links)
    {
    }

    public function generateUnique(int $length = self::DEFAULT_LENGTH): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $candidate = $this->randomSlug($length);
            if (!$this->links->slugExists($candidate)) {
                return $candidate;
            }
        }

        // After repeated collisions at this length, widen the keyspace.
        return $this->generateUnique($length + 1);
    }

    private function randomSlug(int $length): string
    {
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }
}
