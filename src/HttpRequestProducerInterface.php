<?php

namespace Webgriffe\Esb;

use Psr\Http\Message\ServerRequestInterface;
use Webgriffe\Esb\Model\Job;

interface HttpRequestProducerInterface extends ProducerInterface
{
    public function getAttachedRequestMethod(): string;

    public function getAttachedRequestUri(): string;

    /**
     * @param ServerRequestInterface $request
     * @return \Generator|Job[]
     */
    public function produce(ServerRequestInterface $request): \Generator;
}
