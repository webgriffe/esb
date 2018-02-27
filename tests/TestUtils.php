<?php


namespace Webgriffe\Esb;


trait TestUtils
{
    /**
     * @param string $file
     * @return array
     */
    private function getFileLines(string $file): array
    {
        return array_filter(explode(PHP_EOL, file_get_contents($file)));
    }
}
