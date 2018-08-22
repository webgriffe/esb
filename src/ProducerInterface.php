<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

use Amp\Iterator;
use Amp\Promise;

interface ProducerInterface
{
    /**
     * @return Promise
     */
    public function init(): Promise;

    /**
     * @param mixed $data
     * @return Iterator An Amp Iterator that must emit Jobs.
     */
    public function produce($data = null): Iterator;
}
