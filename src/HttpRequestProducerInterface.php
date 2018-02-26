<?php

namespace Webgriffe\Esb;

interface HttpRequestProducerInterface extends ProducerInterface
{
    public function getAttachedRequestMethod(): string;

    public function getAttachedRequestUri(): string;
}
