<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Service;

use Amp\CallableMaker;
use Amp\Http\Server\Options;
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
     * All available options with setter methods
     */
    private const AVAILABLE_OPTIONS = [
        'debug' => ['true' => 'withDebugMode', 'false' => 'withoutDebugMode'],
        'connectionLimit' => ['setter' => 'withConnectionLimit'],
        'connectionsPerIpLimit' => ['setter' => 'withConnectionsPerIpLimit'],
        'connectionTimeout' => ['setter' => 'withConnectionTimeout'],
        'concurrentStreamLimit' => ['setter' => 'withConcurrentStreamLimit'],
        'framesPerSecondLimit' => ['setter' => 'withFramesPerSecondLimit'],
        'minimumAverageFrameSize' => ['setter' => 'withMinimumAverageFrameSize'],
        'allowedMethods' => ['setter' => 'withAllowedMethods'],
        'bodySizeLimit' => ['setter' => 'withBodySizeLimit'],
        'headerSizeLimit' => ['setter' => 'withHeaderSizeLimit'],
        'chunkSize' => ['setter' => 'withChunkSize'],
        'compression' => ['true' => 'withCompression', 'false' => 'withoutCompression'],
        'allowHttp2Upgrade' => ['true' => 'withHttp2Upgrade', 'false' => 'withoutHttp2Upgrade'],
    ];

    /**
     * @var int
     */
    private $port;
    /**
     * @var mixed[]|null
     */
    private $options;
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

    /**
     * @param int $port
     * @param mixed[]|null $options
     * @param LoggerInterface $logger
     */
    public function __construct(int $port, ?array $options, LoggerInterface $logger)
    {
        $this->port = $port;
        $this->options = $options;
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

            $this->httpServer = new Server(
                $sockets,
                new CallableRequestHandler($this->callableFromInstanceMethod('requestHandler')),
                new NullLogger(),
                $this->getServerOptions()
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
     * @return \Generator<Promise>
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

    /**
     * Parse the $this->options array into an Options instance
     * @return Options
     */
    private function getServerOptions(): Options
    {
        $options = new Options();
        if ($this->options === null) {
            return $options;
        }

        foreach (static::AVAILABLE_OPTIONS as $optionName => $optionData) {
            $optionValue = $this->options[$optionName] ?? null;
            if ($optionValue === null) {
                continue;
            }

            if (isset($optionData['setter'])) {
                // $options = $options->withChunkSize($optionValue);
                $options = $options->{$optionData['setter']}($optionValue);
            } elseif ($optionValue) {
                // $options = $options->withDebugMode();
                $options = $options->{$optionData['true']}();
            } else {
                // $options = $options->withoutDebugMode();
                $options = $options->{$optionData['false']}();
            }
        }

        return $options;
    }
}
