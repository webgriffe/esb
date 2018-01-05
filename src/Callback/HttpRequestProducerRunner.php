<?php

namespace Webgriffe\Esb\Callback;

use Amp\Beanstalk\BeanstalkClient;
use function Amp\call;
use Amp\CallableMaker;
use Amp\ReactAdapter\ReactAdapter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use React\Http\Response;
use Webgriffe\Esb\HttpRequestProducerInterface;
use Webgriffe\Esb\Model\Job;

class HttpRequestProducerRunner
{
    use CallableMaker;

    /**
     * @var HttpRequestProducerInterface
     */
    private $producer;
    /**
     * @var BeanstalkClient
     */
    private $beanstalkClient;
    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        HttpRequestProducerInterface $producer,
        BeanstalkClient $beanstalkClient,
        LoggerInterface $logger
    ) {
        $this->producer = $producer;
        $this->beanstalkClient = $beanstalkClient;
        $this->logger = $logger;
    }

    public function __invoke()
    {
        yield call([$this->producer, 'init']);
        $this->logger->info(
            'A Producer has been successfully initialized',
            ['producer' => \get_class($this->producer)]
        );
        yield $this->beanstalkClient->use($this->producer->getTube());
        $server = new \React\Http\Server($this->callableFromInstanceMethod('requestHandler'));
        $server->listen(new \React\Socket\Server($this->producer->getPort(), ReactAdapter::get()));
    }

    /** @noinspection PhpUnusedPrivateMethodInspection
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    private function requestHandler(ServerRequestInterface $request): ResponseInterface
    {
        $producer = $this->producer;
        $beanstalkClient = $this->beanstalkClient;
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
    }
}
