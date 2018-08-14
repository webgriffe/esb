<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Service\Console;

use Amp\Beanstalk\BeanstalkClient;
use Amp\CallableMaker;
use Amp\File;
use Amp\Promise;
use Amp\ReactAdapter\ReactAdapter;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use React\Http\StreamingServer;
use React\Promise\PromiseInterface;
use React\Socket\Server as SocketServer;
use RingCentral\Psr7\Response;
use Webgriffe\Esb\Console\Controller\DeleteController;
use Webgriffe\Esb\Console\Controller\IndexController;
use Webgriffe\Esb\Console\Controller\JobController;
use Webgriffe\Esb\Console\Controller\KickController;
use Webgriffe\Esb\Console\Controller\TubeController;
use Webgriffe\Esb\Service\BeanstalkClientFactory;
use function Amp\call;
use function Interop\React\Promise\adapt;

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
                // Fetch method and URI from somewhere
                $httpMethod = $request->getMethod();
                $uri = $request->getUri()->getPath();
                $filePath = __DIR__ . '/public/' . ltrim($uri, '/');
                if ((yield File\exists($filePath)) && (yield File\isfile($filePath))) {
                    return new Response(200, [], yield File\get($filePath));
                }

                $twig = yield $this->getTwig();
                $dispatcher = $this->getDispatcher($request, $twig, $beanstalkClient);

                $routeInfo = $dispatcher->dispatch($httpMethod, $uri);
                switch ($routeInfo[0]) {
                    case Dispatcher::NOT_FOUND:
                        return new Response(404, [], 'Not Found');
                        break;
                    case Dispatcher::METHOD_NOT_ALLOWED:
                        $allowedMethods = $routeInfo[1];
                        return new Response(
                            405,
                            [],
                            'Method Not Allowed. Allowed methods: ' . implode(', ', $allowedMethods)
                        );
                        break;
                    case Dispatcher::FOUND:
                        $handler = $routeInfo[1];
                        $vars = $routeInfo[2];
                        /** @var Response $response */
                        $response = yield \call_user_func_array($handler, $vars);
                        return $response
                            ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
                            ->withHeader('Pragma', 'no-cache')
                            ->withHeader('Expires', '0')
                        ;
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
            $templates = [];
            $viewsPath = __DIR__ . '/views';
            $files = yield File\scandir($viewsPath);
            foreach ($files as $file) {
                if (preg_match('/^.*?\.html\.twig$/', $file)) {
                    $templates[$file] = yield File\get(rtrim($viewsPath, '/') . '/' . $file);
                }
            }
            $loader = new \Twig_Loader_Array($templates);
            return new \Twig_Environment($loader);
        });
    }

    /**
     * @param ServerRequestInterface $request
     * @param \Twig_Environment $twig
     * @param BeanstalkClient $beanstalkClient
     * @return Dispatcher
     */
    private function getDispatcher(
        ServerRequestInterface $request,
        \Twig_Environment $twig,
        BeanstalkClient $beanstalkClient
    ): Dispatcher {
        $dispatcher = \FastRoute\simpleDispatcher(
            function (RouteCollector $r) use ($request, $twig, $beanstalkClient) {
                $r->addRoute('GET', '/', new IndexController($request, $twig, $beanstalkClient));
                $r->addRoute('GET', '/tube/{tube}', new TubeController($request, $twig, $beanstalkClient));
                $r->addRoute('GET', '/kick/{jobId:\d+}', new KickController($request, $twig, $beanstalkClient));
                $r->addRoute('GET', '/delete/{jobId:\d+}', new DeleteController($request, $twig, $beanstalkClient));
                $r->addRoute('GET', '/job/{jobId:\d+}', new JobController($request, $twig, $beanstalkClient));
            }
        );
        return $dispatcher;
    }
}
