<?php

namespace Webgriffe\Esb;

use Amp\Http\Server\Request;
use Amp\Iterator;
use Amp\Producer;
use Amp\Promise;
use Amp\Success;
use Webgriffe\Esb\Model\Job;
use Webmozart\Assert\Assert;

final class DummyHttpRequestProducer implements HttpRequestProducerInterface
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
     * @return Success<null>
     */
    public function init(): Promise
    {
        return new Success(null);
    }

    /**
     * @param Request $data
     */
    public function produce(mixed $data = null): Iterator
    {
        return new Producer(function (callable $emit) use ($data) {
            if (!$data instanceof Request) {
                throw new \InvalidArgumentException(
                    sprintf('Expected "%s" as data for "%s"', Request::class, __CLASS__)
                );
            }
            $requestBody = yield $data->getBody()->buffer();
            Assert::string($requestBody);
            $body = json_decode($requestBody, true);
            Assert::isArray($body);
            Assert::keyExists($body, 'jobs');
            Assert::isIterable($body['jobs']);
            $jobsData = $body['jobs'];
            foreach ($jobsData as $jobData) {
                yield $emit(new Job([$jobData]));   // @phpstan-ignore-line
            }
        });
    }
}
