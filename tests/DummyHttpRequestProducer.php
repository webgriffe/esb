<?php

namespace Webgriffe\Esb;

use Amp\Iterator;
use Amp\Producer;
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
     * @return Iterator
     * @throws \InvalidArgumentException
     * @throws \Error
     */
    public function produce($data = null): Iterator
    {
        if (!$data instanceof ServerRequestInterface) {
            throw new \InvalidArgumentException(
                sprintf('Expected "%s" as data for "%s"', ServerRequestInterface::class, __CLASS__)
            );
        }
        $body = json_decode($data->getBody(), true);
        $jobsData = $body['jobs'];
        return new Producer(function (callable $emit) use ($jobsData) {
            foreach ($jobsData as $jobData) {
                yield $emit(new Job([$jobData]));
            }
        });
    }
}
