<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

use Amp\Iterator;
use Amp\Promise;
use Webgriffe\Esb\Model\JobInterface;

interface ProducerInterface
{
    /**
     * @return Promise<null>
     */
    public function init(): Promise;

    /**
     * @param mixed $data
     * @return Iterator<JobInterface> An Amp Iterator that must emit Jobs.
     */
    public function produce($data = null): Iterator;
}
