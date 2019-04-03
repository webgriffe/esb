<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

use Monolog\Formatter\NormalizerFormatter;

class NonUtf8Cleaner
{
    public static function clean(array $data): array
    {
        $formatter = new NormalizerFormatter();
        array_walk_recursive($data, [$formatter, 'detectAndCleanUtf8']);
        return $data;
    }

    public static function cleanString(string $data): string
    {
        $formatter = new NormalizerFormatter();
        $formatter->detectAndCleanUtf8($data);
        return $data;
    }
}
