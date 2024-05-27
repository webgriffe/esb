<?php

declare(strict_types=1);

namespace Webgriffe\Esb;

/**
 * @internal
 */
class NonUtf8Cleaner
{
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function clean(array $data): array
    {
        array_walk_recursive($data, [__CLASS__, 'cleanString']);
        return $data;
    }

    public static function cleanString(string $data): string
    {
        // Implementation borrowed from Monolog\Utils::detectAndCleanUtf8() which is no longer a public method as of version 2

        if (preg_match('//u', $data)) {
            return $data;
        }

        $data = preg_replace_callback(
            '/[\x80-\xFF]+/',
            function ($m) {
                return function_exists('mb_convert_encoding') ? mb_convert_encoding($m[0], 'UTF-8', 'ISO-8859-1') : utf8_encode($m[0]);
            },
            $data
        );

        if (!is_string($data)) {
            throw new \RuntimeException('Failed to preg_replace_callback: ' . preg_last_error());
        }

        return str_replace(
            ['¤', '¦', '¨', '´', '¸', '¼', '½', '¾'],
            ['€', 'Š', 'š', 'Ž', 'ž', 'Œ', 'œ', 'Ÿ'],
            $data
        );
    }
}
