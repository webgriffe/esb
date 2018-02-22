<?php

namespace Webgriffe\Esb;

use Amp\Iterator;
use Amp\Promise;

interface ProducerInterface
{
    /**
     * @return string
     */
    public function getTube(): string;

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
