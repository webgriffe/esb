<?php

namespace Webgriffe\Esb\Callback;

use Amp\Beanstalk\BeanstalkClient;
use Amp\CallableMaker;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\Socket;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Webgriffe\Esb\HttpRequestProducerInterface;
use Webgriffe\Esb\JobsQueuer;
use Webgriffe\Esb\Service\BeanstalkClientFactory;
use function Amp\call;

class HttpServerRunner
{
    use CallableMaker;

    /**
     * @var HttpRequestProducerInterface[]
     */
    private $producers;
    /**
     * @var int
     */
    private $port;
    /**
     * @var BeanstalkClientFactory
     */
    private $beanstalkClientFactory;
    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var BeanstalkClient[]
     */
    private $beanstalkClients = [];

    public function __construct(
        array $producers,
        int $port,
        BeanstalkClientFactory $beanstalkClientFactory,
        LoggerInterface $logger
    ) {
        $this->producers = $producers;
        $this->port = $port;
        $this->beanstalkClientFactory = $beanstalkClientFactory;
        $this->logger = $logger;
    }

    /**
     * @return \Generator
     * @throws Socket\SocketException
     */
    public function __invoke()
    {
        foreach ($this->producers as $producer) {
            $beanstalkClient = $this->beanstalkClientFactory->create();
            $this->beanstalkClients[\get_class($producer)] = $beanstalkClient;
            yield call(new ProducerInitializer($producer, $beanstalkClient, $this->logger));
        }

        $sockets = [
            Socket\listen("0.0.0.0:{$this->port}"),
            Socket\listen("[::]:{$this->port}"),
        ];

        $server = new \Amp\Http\Server\Server(
            $sockets,
            new CallableRequestHandler($this->callableFromInstanceMethod('requestHandler')),
            new NullLogger()
        );

        yield $server->start();
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
