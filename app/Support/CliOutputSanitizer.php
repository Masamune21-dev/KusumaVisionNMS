<?php

namespace App\Support;

class CliOutputSanitizer
{
    public static function clean(string $output): string
    {
        $output = self::stripTelnetControlSequences($output);
        $output = self::normalizeUtf8($output);

        $output = preg_replace('/\x1B\[[0-?]*[ -\/]*[@-~]/', '', $output) ?? $output;
        $output = str_replace("\x08", '', $output);

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', '', $output) ?? $output;
    }

    private static function normalizeUtf8(string $output): string
    {
        if (preg_match('//u', $output) === 1) {
            return $output;
        }

        $cleaned = @iconv('UTF-8', 'UTF-8//IGNORE', $output);

        if ($cleaned !== false && preg_match('//u', $cleaned) === 1) {
            return $cleaned;
        }

        return preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $output) ?? '';
    }

    private static function stripTelnetControlSequences(string $output): string
    {
        $cleaned = '';
        $length = strlen($output);

        for ($i = 0; $i < $length; $i++) {
            if (ord($output[$i]) !== 0xFF) {
                $cleaned .= $output[$i];

                continue;
            }

            if ($i + 1 >= $length) {
                break;
            }

            $command = ord($output[++$i]);

            if ($command === 0xFA) {
                while ($i + 1 < $length) {
                    $i++;

                    if (ord($output[$i]) === 0xFF && $i + 1 < $length && ord($output[$i + 1]) === 0xF0) {
                        $i++;
                        break;
                    }
                }

                continue;
            }

            if ($command >= 0xFB && $command <= 0xFE && $i + 1 < $length) {
                $i++;
            }
        }

        return $cleaned;
    }
}
