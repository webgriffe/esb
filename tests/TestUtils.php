<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

trait TestUtils
{
    /**
     * @return string[]
     */
    private function getFileLines(string $file): array
    {
        $fileContents = file_get_contents($file);
        if (!is_string($fileContents)) {
            throw new \RuntimeException('Could not read file contents');
        }

        return array_filter(explode(PHP_EOL, $fileContents));
    }
}
