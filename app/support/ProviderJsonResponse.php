<?php

declare(strict_types=1);

namespace app\support;

final class ProviderJsonResponse
{
    public static function decode(string $raw): ?array
    {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $length = strlen($raw);
        $start = strpos($raw, '{');
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;

        for ($index = $start; $index < $length; $index++) {
            $char = $raw[$index];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }
                if ($char === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"') {
                $inString = true;
                continue;
            }
            if ($char === '{') {
                $depth++;
                continue;
            }
            if ($char !== '}') {
                continue;
            }

            $depth--;
            if ($depth !== 0) {
                continue;
            }

            $candidate = substr($raw, $start, $index - $start + 1);
            $decoded = json_decode($candidate, true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }
}
