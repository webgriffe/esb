<?php

namespace Webgriffe\Esb;

use Amp\Promise;
use Amp\Success;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Response;
use Webgriffe\Esb\Model\Job;

class DummyHttpRequestProducer implements HttpRequestProducerInterface
{
    public function getAttachedRequestMethod(): string
    {
        return 'POST';
    }

    public function getAttachedRequestUri(): string
    {
        return '/dummy';
    }

    /**
     * @return string
     */
    public function getTube(): string
    {
        return DummyFilesystemWorker::TUBE;
    }

    /**
     * @return Promise
     * @throws \Error
     */
    public function init(): Promise
    {
        return new Success(null);
    }

    /**
     * @param ServerRequestInterface $data
     * @return \Generator|Job[]
     * @throws \InvalidArgumentException
     */
    public function produce($data = null): \Generator
    {
        if (!$data instanceof ServerRequestInterface) {
            throw new \InvalidArgumentException(
                sprintf('Expected "%s" as data for "%s"', ServerRequestInterface::class, __CLASS__)
            );
        }
        $body = json_decode($data->getBody(), true);
        foreach ($body['jobs'] as $job) {
            yield new Job([$job]);
        }
    }
}
