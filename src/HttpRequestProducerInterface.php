<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

interface HttpRequestProducerInterface extends ProducerInterface
{
    /**
     * @return string
     */
    public function getAttachedRequestMethod(): string;

    /**
     * @return string
     */
    public function getAttachedRequestUri(): string;
}
