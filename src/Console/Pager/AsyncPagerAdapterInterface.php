<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Console\Pager;

use Amp\Promise;

interface AsyncPagerAdapterInterface
{
    /**
     * Returns the number of results.
     *
     * @return Promise which resolve integer The number of results.
     */
    public function getNbResults(): Promise;

    /**
     * Returns an slice of the results.
     *
     * @param integer $offset The offset.
     * @param integer $length The length.
     *
     * @return Promise which resolve array|\Traversable The slice.
     */
    public function getSlice(int $offset, int $length): Promise;
}
