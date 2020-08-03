<?php

declare(strict_types=1);

namespace Webgriffe\Esb;

use Monolog\Utils;

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
        array_walk_recursive($data, [Utils::class, 'detectAndCleanUtf8']);
        return $data;
    }

    public static function cleanString(string $data): string
    {
        Utils::detectAndCleanUtf8($data);
        return $data;
    }
}
