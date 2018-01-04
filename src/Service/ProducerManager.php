<?php

namespace Webgriffe\Esb\Service;

use Amp\Beanstalk\BeanstalkClient;
use function Amp\call;
use Amp\Loop;
use function Amp\Promise\all;
use function Amp\Promise\wait;
use Amp\ReactAdapter\ReactAdapter;
use Monolog\Logger;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Response;
use React\Promise\Promise;
use Webgriffe\Esb\HttpServerProducerInterface;
use Webgriffe\Esb\Model\Job;
use Webgriffe\Esb\ProducerInterface;
use Webgriffe\Esb\RepeatProducerInterface;

class ProducerManager
{
    /**
     * @var BeanstalkClientFactory
     */
    private $beanstalkClientFactory;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var ProducerInterface[]
     */
    private $producers;

    /**
     * ProducerManager constructor.
     * @param BeanstalkClientFactory $beanstalkClientFactory
     * @param Logger $logger
     */
    public function __construct(BeanstalkClientFactory $beanstalkClientFactory, Logger $logger)
    {
        $this->beanstalkClientFactory = $beanstalkClientFactory;
        $this->logger = $logger;
    }

    public function bootProducers()
    {
        if (!count($this->producers)) {
            $this->logger->notice('No producer to start.');
            return;
        }

        foreach ($this->producers as $producer) {
            if ($producer instanceof RepeatProducerInterface) {
                $this->bootRepeatProducer($producer);
            } else if ($producer instanceof  HttpServerProducerInterface) {
                $this->bootHttpServerProducer($producer);
            } else {
                throw new \RuntimeException(sprintf('Unknown producer type "%s".', get_class($producer)));
            }
        }
    }

    public function bootRepeatProducer(RepeatProducerInterface $producer)
    {
        Loop::defer(function () use ($producer) {
            $beanstalkClient = $this->beanstalkClientFactory->create();
            yield call([$producer, 'init']);
            $this->logger->info(
                'A Producer has been successfully initialized',
                ['producer' => \get_class($producer)]
            );
            yield $beanstalkClient->use($producer->getTube());
            Loop::repeat($producer->getInterval(), function ($watcherId) use ($producer, $beanstalkClient) {
                Loop::disable($watcherId);
                $jobs = $producer->produce();
                /** @var Job $job */
                foreach($jobs as $job) {
                    try {
                        $payload = serialize($job->getPayloadData());
                        $jobId = yield $beanstalkClient->put($payload);
                        $this->logger->info(
                            'Successfully produced a new Job',
                            [
                                'producer' => \get_class($producer),
                                'job_id' => $jobId,
                                'payload_data' => $job->getPayloadData()
                            ]
                        );
                        $producer->onProduceSuccess($job);
                    } catch (\Exception $e) {
                        $this->logger->error(
                            'An error occurred producing a job.',
                            [
                                'producer' => \get_class($producer),
                                'payload_data' => $job->getPayloadData(),
                                'error' => $e->getMessage(),
                            ]
                        );
                        $producer->onProduceFail($job, $e);
                    }
                }
                Loop::enable($watcherId);
            });
        });
    }

    private function bootHttpServerProducer(HttpServerProducerInterface $producer)
    {
        Loop::defer(function () use ($producer) {
            $beanstalkClient = $this->beanstalkClientFactory->create();
            yield call([$producer, 'init']);
            $this->logger->info(
                'A Producer has been successfully initialized',
                ['producer' => \get_class($producer)]
            );
            yield $beanstalkClient->use($producer->getTube());
            $server = new \React\Http\Server(function (ServerRequestInterface $request) use ($producer, $beanstalkClient) {
                $jobsCount = 0;
                $jobs = $producer->produce($request);
                /** @var Job $job */
                foreach($jobs as $job) {
                    $payload = serialize($job->getPayloadData());
                    $this->logger->info($payload);
                    $beanstalkClient->put($payload)->onResolve(
                        function (\Throwable $error = null, int $jobId) use ($producer, $job) {
                            if ($error) {
                                $this->logger->error(
                                    'An error occurred producing a job.',
                                    [
                                        'producer' => \get_class($producer),
                                        'payload_data' => $job->getPayloadData(),
                                        'error' => $error->getMessage(),
                                    ]
                                );
                                $producer->onProduceFail($job, $error);
                            } else {
                                $this->logger->info(
                                    'Successfully produced a new Job',
                                    [
                                        'producer' => \get_class($producer),
                                        'job_id' => $jobId,
                                        'payload_data' => $job->getPayloadData()
                                    ]
                                );
                                $producer->onProduceSuccess($job);
                            }
                        }
                    );
                    $jobsCount++;
                }

                $responseMessage = sprintf('Successfully scheduled %s job(s) to be queued.', $jobsCount);
                $statusCode = 200;
                return new Response($statusCode, [], sprintf('"%s"', $responseMessage));
            });
            $server->listen(new \React\Socket\Server($producer->getPort(), ReactAdapter::get()));
        });
    }

    public function addProducer(ProducerInterface $producer)
    {
        $this->producers[] = $producer;
    }
}
