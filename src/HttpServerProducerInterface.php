<?php

namespace Webgriffe\Esb;

use Psr\Http\Message\ServerRequestInterface;
use Webgriffe\Esb\Model\Job;

interface HttpServerProducerInterface extends ProducerInterface
{
    public function getPort(): int;

    public function getAttachedRequestMethod(): string;

    public function getAttachedRequestUri(): string;

    /**
     * @param ServerRequestInterface $request
     * @return \Generator|Job[]
     */
    public function produce(ServerRequestInterface $request): \Generator;
}
