<?php

namespace Webgriffe\Esb\Service\Worker;

interface WorkerInterface
{
    /**
     * @return void
     */
    public function work();

    /**
     * @return string
     */
    public function getCode();
}
