<?php

namespace Webgriffe\Esb;

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
