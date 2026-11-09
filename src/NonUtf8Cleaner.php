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
        array_walk_recursive($data, static function (mixed &$value): void {
            if (\is_string($value)) {
                $value = self::cleanString($value);
            }
        });
        return $data;
    }

    /**
     * Ported from Monolog 1's public Utils::detectAndCleanUtf8(), which became private in Monolog 2.
     */
    private static function cleanString(string $data): string
    {
        if (preg_match('//u', $data) === 1) {
            return $data;
        }

        $data = preg_replace_callback(
            '/[\x80-\xFF]+/',
            static fn (array $m): string => mb_convert_encoding($m[0], 'UTF-8', 'ISO-8859-1'),
            $data
        );
        if (!\is_string($data)) {
            throw new \RuntimeException(
                'Failed to preg_replace_callback: ' . preg_last_error() . ' / ' . preg_last_error_msg()
            );
        }

        return str_replace(
            ['¤', '¦', '¨', '´', '¸', '¼', '½', '¾'],
            ['€', 'Š', 'š', 'Ž', 'ž', 'Œ', 'œ', 'Ÿ'],
            $data
        );
    }
}
