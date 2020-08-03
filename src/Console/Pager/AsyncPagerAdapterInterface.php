<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Console\Pager;

use Amp\Promise;

interface AsyncPagerAdapterInterface
{
    /**
     * Returns the number of results.
     *
     * @return Promise<int> which resolve integer The number of results.
     */
    public function getNbResults(): Promise;

    /**
     * Returns an slice of the results.
     *
     * @param int $offset The offset.
     * @param int $length The length.
     *
     * @return Promise<iterable<mixed>>
     */
    public function getSlice(int $offset, int $length): Promise;
}
