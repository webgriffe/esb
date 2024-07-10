<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Service;

use function Amp\call;
use Amp\CallableMaker;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Options;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\Promise;
use Amp\Socket;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Webgriffe\Esb\HttpRequestProducerInterface;
use Webgriffe\Esb\ProducerInstance;

/**
 * @internal
 */
class HttpProducersServer
{
    use CallableMaker;

    /**
     * @var int
     */
    private $port;
    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var ProducerInstance[]
     */
    private $producerInstances = [];
    /**
     * @var HttpServer|null
     */
    private $httpServer;

    /**
     * @var int|null
     */
    private $maxBodySize;

    public function __construct(int $port, LoggerInterface $logger, ?int $maxBodySize)
    {
        $this->port = $port;
        $this->logger = $logger;
        $this->maxBodySize = $maxBodySize;
    }

    /**
     * @return Promise<null>
     */
    public function start(): Promise
    {
        return call(function () {
            $sockets = [
                Socket\listen("0.0.0.0:{$this->port}"),
                Socket\listen("[::]:{$this->port}"),
            ];

            $options = null;
            if ($this->maxBodySize !== null) {
                $options = new Options();
                $options->withBodySizeLimit($this->maxBodySize);
            }

            $this->httpServer = new HttpServer(
                $sockets,
                new CallableRequestHandler($this->callableFromInstanceMethod('requestHandler')),
                new NullLogger(),
                $options
            );

            yield $this->httpServer->start();
        });
    }

    /**
     * @return bool
     */
    public function isStarted(): bool
    {
        if (!$this->httpServer) {
            return false;
        }
        $state = $this->httpServer->getState();
        return $state === HttpServer::STARTING || $state === HttpServer::STARTED;
    }

    public function addProducerInstance(ProducerInstance $producerInstance): void
    {
        $this->producerInstances[] = $producerInstance;
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    /**
     * @param Request $request
     * @return \Generator<Promise>
     */
    private function requestHandler(Request $request)
    {
        $producerInstances = $this->matchProducerInstance($request);

        if (count($producerInstances) === 0) {
            return new Response(Status::NOT_FOUND, [], 'No Producers Found');
        }

        $jobsCount = 0;
        foreach ($producerInstances as $producerInstance) {
            $this->logger->info(
                'Matched an HTTP Producer for an incoming HTTP request.',
                [
                    'producer' => \get_class($producerInstance->getProducer()),
                    'request' => sprintf('%s %s', strtoupper($request->getMethod()), $request->getUri())
                ]
            );
            $jobsCount += yield $producerInstance->produceAndQueueJobs($request);
        }

        $responseMessage = sprintf('Successfully scheduled %s job(s) to be queued.', $jobsCount);
        return new Response(Status::OK, [], sprintf('"%s"', $responseMessage));
    }

    /**
     * @param Request $request
     * @return ProducerInstance[]
     */
    private function matchProducerInstance(Request $request)
    {
        $matchingInstances = [];
        foreach ($this->producerInstances as $producerInstance) {
            $producer = $producerInstance->getProducer();
            if (!$producer instanceof HttpRequestProducerInterface) {
                // This should never happen, but maybe we should add a warning here?
                continue;
            }
            if ($request->getUri()->getPath() === $producer->getAttachedRequestUri() &&
                $producer->getAttachedRequestMethod() === $request->getMethod()) {
                $matchingInstances[] = $producerInstance;
            }
        }
        return $matchingInstances;
    }
}
