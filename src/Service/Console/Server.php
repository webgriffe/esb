<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Service\Console;

use Amp\Beanstalk\Stats\System;
use function Amp\call;
use Amp\CallableMaker;
use Amp\Promise;
use Amp\ReactAdapter\ReactAdapter;
use FastRoute\RouteCollector;
use function Interop\React\Promise\adapt;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use React\Http\StreamingServer;
use React\Promise\PromiseInterface;
use React\Socket\Server as SocketServer;
use RingCentral\Psr7\Response;
use Amp\File;
use Webgriffe\Esb\Service\BeanstalkClientFactory;
use Webgriffe\Esb\Console\Controller\IndexController;

class Server
{
    const PORT = 8080;

    use CallableMaker;

    /**
     * @var BeanstalkClientFactory
     */
    private $beanstalkClientFactory;
    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(BeanstalkClientFactory $beanstalkClientFactory, LoggerInterface $logger)
    {
        $this->beanstalkClientFactory = $beanstalkClientFactory;
        $this->logger = $logger;
    }

    public function boot()
    {
        $server = new StreamingServer($this->callableFromInstanceMethod('requestHandler'));
        $server->listen(new SocketServer(self::PORT, ReactAdapter::get()));
        $this->logger->info('Web console server started.', ['port' => self::PORT ]);
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    /**
     * @param ServerRequestInterface $request
     * @return Response|PromiseInterface
     */
    private function requestHandler(ServerRequestInterface $request)
    {
        $beanstalkClient = $this->beanstalkClientFactory->create();
        return adapt(call(function () use ($request, $beanstalkClient) {
            try {
                $twig = yield $this->getTwig();
                $dispatcher = \FastRoute\simpleDispatcher(function(RouteCollector $r) use ($twig, $beanstalkClient) {
                    $r->addRoute('GET', '/', new IndexController($twig, $beanstalkClient));
                    // {id} must be a number (\d+)
                    $r->addRoute('GET', '/tube/{tube}', 'get_user_handler');
                    // The /{title} suffix is optional
                    $r->addRoute('GET', '/articles/{id:\d+}[/{title}]', 'get_article_handler');
                });

                // Fetch method and URI from somewhere
                $httpMethod = $request->getMethod();
                $uri = $request->getUri();

                $routeInfo = $dispatcher->dispatch($httpMethod, $uri);
                switch ($routeInfo[0]) {
                    case \FastRoute\Dispatcher::NOT_FOUND:
                        return new Response(404, [], 'Not Found');
                        break;
                    case \FastRoute\Dispatcher::METHOD_NOT_ALLOWED:
                        $allowedMethods = $routeInfo[1];
                        return new Response(
                            405,
                            [],
                            'Method Not Allowed. Allowed methods: ' . implode(', ', $allowedMethods)
                        );
                        break;
                    case \FastRoute\Dispatcher::FOUND:
                        $handler = $routeInfo[1];
                        $vars = $routeInfo[2];
                        return yield \call_user_func_array($handler, $vars);
                        break;
                }
            } catch (\Throwable $exception) {
                $body = $exception->getMessage() . PHP_EOL . $exception->getTraceAsString();
                return new Response(500, [], $body);
            }
        }));
    }

    private function getTwig(): Promise
    {
        return call(function () {
            $loader = new \Twig_Loader_Array(
                [
                    'index' => (yield File\get(__DIR__ . '/views/index.html.twig')),
                ]
            );
            return new \Twig_Environment($loader);
        });
    }
}
