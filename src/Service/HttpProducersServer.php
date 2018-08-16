<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Service;

use Amp\Beanstalk\BeanstalkClient;
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
use Webgriffe\Esb\JobsQueuer;
use function Amp\call;

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
     * @var HttpRequestProducerInterface[]
     */
    private $producers = [];
    /**
     * @var BeanstalkClient[]
     */
    private $beanstalkClients = [];
    /**
     * @var Server
     */
    private $httpServer;

    public function __construct(int $port, LoggerInterface $logger)
    {
        $this->port = $port;
        $this->logger = $logger;
    }

    /**
     * @return Promise
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
        return ($state === Server::STARTING || $state === Server::STARTED);
    }

    /**
     * @param HttpRequestProducerInterface $producer
     * @param BeanstalkClient $beanstalkClient
     */
    public function addProducer(HttpRequestProducerInterface $producer, BeanstalkClient $beanstalkClient)
    {
        $this->producers[] = $producer;
        $this->beanstalkClients[\get_class($producer)] = $beanstalkClient;
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    /**
     * @param Request $request
     * @return Response
     */
    private function requestHandler(Request $request)
    {
        $producer = $this->matchProducer($request);
        if (!$producer) {
            return new Response(Status::NOT_FOUND, [], 'Producer Not Found');
        }

        $this->logger->info(
            'Matched an HTTP Producer for an incoming HTTP request.',
            [
                'producer' => \get_class($producer),
                'request' => sprintf('%s %s', strtoupper($request->getMethod()), $request->getUri())
            ]
        );
        $beanstalkClient = $this->beanstalkClients[\get_class($producer)];
        $jobsCount = yield JobsQueuer::queueJobs($beanstalkClient, $this->logger, $producer, $request);
        $responseMessage = sprintf('Successfully scheduled %s job(s) to be queued.', $jobsCount);
        return new Response(Status::OK, [], sprintf('"%s"', $responseMessage));
    }

    /**
     * @param Request $request
     * @return false|HttpRequestProducerInterface
     */
    private function matchProducer(Request $request)
    {
        foreach ($this->producers as $producer) {
            if ($request->getUri()->getPath() === $producer->getAttachedRequestUri() &&
                $producer->getAttachedRequestMethod() === $request->getMethod()) {
                return $producer;
            }
        }
        return false;
    }
}
