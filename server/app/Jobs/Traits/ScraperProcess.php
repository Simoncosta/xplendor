<?php

declare(strict_types=1);

namespace App\Jobs\Traits;

trait ScraperProcess
{
    protected function extractJson(string $output): ?string
    {
        $start = strpos($output, '{');
        $end   = strrpos($output, '}');

        if ($start === false || $end === false) {
            return null;
        }

        return substr($output, $start, $end - $start + 1);
    }

    protected function isBlocked(string $stdout, string $stderr): bool
    {
        $combined = strtolower($stdout . $stderr);

        if (str_contains($combined, '403') || str_contains($combined, '429')) {
            return true;
        }

        if (str_contains($combined, 'captcha') || str_contains($combined, 'cloudflare')) {
            return true;
        }

        return false;
    }
}
