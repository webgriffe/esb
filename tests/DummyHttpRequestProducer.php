<?php

namespace Webgriffe\Esb;

use Amp\Http\Server\Request;
use Amp\Http\Status;
use Amp\Iterator;
use Amp\Producer;
use Amp\Promise;
use Amp\Success;
use Webgriffe\Esb\Exception\HttpResponseException;
use Webgriffe\Esb\Model\Job;

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
     * @return Promise
     * @throws \Error
     */
    public function init(): Promise
    {
        return new Success(null);
    }

    /**
     * @param Request $data
     * @return Iterator
     * @throws \InvalidArgumentException
     * @throws \Error
     */
    public function produce($data = null): Iterator
    {
        return new Producer(function (callable $emit) use ($data) {
            if (!$data instanceof Request) {
                throw new \InvalidArgumentException(
                    sprintf('Expected "%s" as data for "%s"', Request::class, __CLASS__)
                );
            }
            $body = json_decode(yield $data->getBody()->read(), true);
            if (!is_array($body)) {
                throw new HttpResponseException(Status::BAD_REQUEST, 'Request body contains invalid JSON');
            }
            $jobsData = $body['jobs'];
            foreach ($jobsData as $jobData) {
                switch ($jobData) {
                    case 'throw http response exception':
                        throw new HttpResponseException(Status::PRECONDITION_FAILED, 'Some other custom message');
                    case 'throw other exception':
                        throw new \Exception('This message shouldn\'t be send to the client');
                    default:
                        yield $emit(new Job([$jobData]));
                }
            }
        });
    }
}
