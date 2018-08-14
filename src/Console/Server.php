<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Console;

use Amp\Beanstalk\BeanstalkClient;
use Amp\CallableMaker;
use Amp\File;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Status;
use Amp\Loop;
use Amp\Promise;
use Amp\Socket;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Webgriffe\Esb\Console\Controller\DeleteController;
use Webgriffe\Esb\Console\Controller\IndexController;
use Webgriffe\Esb\Console\Controller\JobController;
use Webgriffe\Esb\Console\Controller\KickController;
use Webgriffe\Esb\Console\Controller\TubeController;
use Webgriffe\Esb\Service\BeanstalkClientFactory;
use function Amp\call;

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
        Loop::defer(function () {
            $port = self::PORT;
            $sockets = [
                Socket\listen("0.0.0.0:$port"),
                Socket\listen("[::]:$port"),
            ];

            $server = new \Amp\Http\Server\Server(
                $sockets,
                new CallableRequestHandler($this->callableFromInstanceMethod('requestHandler')),
                new NullLogger()
            );

            yield $server->start();

            $this->logger->info('Web console server started.', ['port' => self::PORT ]);
        });
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    /**
     * @param Request $request
     * @return \Generator
     */
    private function requestHandler(Request $request): \Generator
    {
        $beanstalkClient = $this->beanstalkClientFactory->create();
        // Fetch method and URI from somewhere
        $httpMethod = $request->getMethod();
        $uri = $request->getUri()->getPath();
        $filePath = __DIR__ . '/public/' . ltrim($uri, '/');
        if ((yield File\exists($filePath)) && (yield File\isfile($filePath))) {
            return new Response(Status::OK, [], yield File\get($filePath));
        }

        $twig = yield $this->getTwig();
        $dispatcher = $this->getDispatcher($request, $twig, $beanstalkClient);

        $routeInfo = $dispatcher->dispatch($httpMethod, $uri);
        switch ($routeInfo[0]) {
            case Dispatcher::METHOD_NOT_ALLOWED:
                $allowedMethods = $routeInfo[1];
                $response = new Response(
                    Status::METHOD_NOT_ALLOWED,
                    [],
                    'Method Not Allowed. Allowed methods: ' . implode(', ', $allowedMethods)
                );
                break;
            case Dispatcher::FOUND:
                $handler = $routeInfo[1];
                $vars = $routeInfo[2];
                /** @var Response $response */
                $response = yield \call_user_func_array($handler, $vars);
                $response->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
                $response->addHeader('Pragma', 'no-cache');
                $response->addHeader('Expires', '0');
                break;
            default: // Dispatcher::NOT_FOUND:
                $response = new Response(Status::NOT_FOUND, [], 'Not Found');
                break;
        }
        return $response;
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
     * @param Request $request
     * @param \Twig_Environment $twig
     * @param BeanstalkClient $beanstalkClient
     * @return Dispatcher
     */
    private function getDispatcher(
        Request $request,
        \Twig_Environment $twig,
        BeanstalkClient $beanstalkClient
    ): Dispatcher {
        return \FastRoute\simpleDispatcher(
            function (RouteCollector $r) use ($request, $twig, $beanstalkClient) {
                $r->addRoute('GET', '/', new IndexController($request, $twig, $beanstalkClient));
                $r->addRoute('GET', '/tube/{tube}', new TubeController($request, $twig, $beanstalkClient));
                $r->addRoute('GET', '/kick/{jobId:\d+}', new KickController($request, $twig, $beanstalkClient));
                $r->addRoute('GET', '/delete/{jobId:\d+}', new DeleteController($request, $twig, $beanstalkClient));
                $r->addRoute('GET', '/job/{jobId:\d+}', new JobController($request, $twig, $beanstalkClient));
            }
        );
    }
}
