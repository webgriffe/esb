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
use Webgriffe\Esb\JobsQueuer;
use Webgriffe\Esb\Model\Job;
use Webgriffe\Esb\ProducerInterface;
use Webgriffe\Esb\Service\BeanstalkClientFactory;

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

    public function __invoke()
    {
        foreach ($this->producers as $producer) {
            yield call([$producer, 'init']);
            $this->logger->info(
                'A Producer has been successfully initialized',
                ['producer' => \get_class($producer)]
            );
            $this->beanstalkClients[\get_class($producer)] = $this->beanstalkClientFactory->create();
            yield $this->beanstalkClients[\get_class($producer)]->use($producer->getTube());
        }
        $server = new \React\Http\Server($this->callableFromInstanceMethod('requestHandler'));
        $server->listen(new \React\Socket\Server($this->port, ReactAdapter::get()));
    }

    /** @noinspection PhpUnusedPrivateMethodInspection
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    private function requestHandler(ServerRequestInterface $request): ResponseInterface
    {
        $producer = $this->matchProducer($request);
        if (!$producer) {
            return new Response(404, [], 'Producer Not Found');
        }

        $this->logger->info(
            'Matched an HTTP Producer for an incoming HTTP request.',
            [
                'producer' => \get_class($producer),
                'request' => sprintf('%s %s', strtoupper($request->getMethod()), $request->getUri())
            ]
        );
        $beanstalkClient = $this->beanstalkClients[\get_class($producer)];
        $jobsCount = JobsQueuer::queueJobs($beanstalkClient, $this->logger, $producer, $request);
        $responseMessage = sprintf('Successfully scheduled %s job(s) to be queued.', $jobsCount);
        $statusCode = 200;
        return new Response($statusCode, [], sprintf('"%s"', $responseMessage));
    }

    /**
     * @param ServerRequestInterface $request
     * @return false|HttpRequestProducerInterface
     */
    private function matchProducer(ServerRequestInterface $request)
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
