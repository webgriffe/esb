<?php

namespace Webgriffe\Esb;

use Psr\Http\Message\ServerRequestInterface;
use React\Http\Response;
use Webgriffe\Esb\Model\Job;

class DummyHttpRequestProducer implements HttpRequestProducerInterface
{
    /**
     * @var ServerRequestInterface
     */
    private $request;

    /**
     * @var Response
     */
    private $response;

    public function getPort(): int
    {
        return 8080;
    }

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
     * @return void
     */
    public function init()
    {
        // TODO: Implement init() method.
    }

    /**
     * @param ServerRequestInterface $request
     * @return \Generator|Job[]
     */
    public function produce(ServerRequestInterface $request): \Generator
    {
        $body = json_decode($request->getBody(), true);
        foreach ($body['jobs'] as $job) {
            yield new Job([$job]);
        }
    }

    /**
     * @param Job $job
     * @return void
     */
    public function onProduceSuccess(Job $job)
    {
        // TODO: Implement onProduceSuccess() method.
    }

    /**
     * @param Job $job
     * @param \Exception $exception
     * @return void
     */
    public function onProduceFail(Job $job, \Throwable $exception)
    {
        // TODO: Implement onProduceFail() method.
    }
}
