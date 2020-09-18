<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Service;

use Amp\CallableMaker;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Server;
use Amp\Http\Status;
use Amp\Promise;
use Amp\Socket;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Webgriffe\Esb\HttpRequestProducerInterface;
use Webgriffe\Esb\ProducerInstance;
use function Amp\call;

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
     * @var Server|null
     */
    private $httpServer;

    public function __construct(int $port, LoggerInterface $logger)
    {
        $this->port = $port;
        $this->logger = $logger;
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

            $this->httpServer = new \Amp\Http\Server\Server(
                $sockets,
                new CallableRequestHandler($this->callableFromInstanceMethod('requestHandler')),
                new NullLogger()
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
        return $state === Server::STARTING || $state === Server::STARTED;
    }

    public function addProducerInstance(ProducerInstance $producerInstance): void
    {
        $this->producerInstances[] = $producerInstance;
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    /**
     * @param Request $request
     * @return \Generator<Response>
     */
    private function requestHandler(Request $request)
    {
        $producerInstance = $this->matchProducerInstance($request);
        if (!$producerInstance) {
            return new Response(Status::NOT_FOUND, [], 'Producer Not Found');
        }

        $this->logger->info(
            'Matched an HTTP Producer for an incoming HTTP request.',
            [
                'producer' => \get_class($producerInstance->getProducer()),
                'request' => sprintf('%s %s', strtoupper($request->getMethod()), $request->getUri())
            ]
        );
        $jobsCount = yield $producerInstance->produceAndQueueJobs($request);
        $responseMessage = sprintf('Successfully scheduled %s job(s) to be queued.', $jobsCount);
        return new Response(Status::OK, [], sprintf('"%s"', $responseMessage));
    }

    /**
     * @param Request $request
     * @return false|ProducerInstance
     */
    private function matchProducerInstance(Request $request)
    {
        foreach ($this->producerInstances as $producerInstance) {
            $producer = $producerInstance->getProducer();
            if (!$producer instanceof HttpRequestProducerInterface) {
                // This should never happen but maybe we should add a warning here?
                continue;
            }
            if ($request->getUri()->getPath() === $producer->getAttachedRequestUri() &&
                $producer->getAttachedRequestMethod() === $request->getMethod()) {
                return $producerInstance;
            }
        }
        return false;
    }
}
