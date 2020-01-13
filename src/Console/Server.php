<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Console;

use Amp\Beanstalk\BeanstalkClient;
use Amp\CallableMaker;
use Amp\File;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\Loop;
use Amp\Promise;
use Amp\Socket;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Webgriffe\Esb\Console\Controller\DeleteController;
use Webgriffe\Esb\Console\Controller\IndexController;
use Webgriffe\Esb\Console\Controller\JobController;
use Webgriffe\Esb\Console\Controller\KickController;
use Webgriffe\Esb\Console\Controller\TubeController;
use function Amp\call;

/**
 * @internal
 */
class Server implements ContainerAwareInterface
{
    use CallableMaker;

    /**
     * @var string
     */
    private $publicDir;
    /**
     * @var array
     */
    private $config;
    /**
     * @var ContainerInterface
     */
    private $container;

    public function __construct(string $publicDir, array $config)
    {
        $this->publicDir = $publicDir;
        $this->config = $config;
    }

    public function boot()
    {
        Loop::defer(function () {
            $port = $this->config['port'];
            $sockets = [
                Socket\listen("0.0.0.0:$port"),
                Socket\listen("[::]:$port"),
            ];

            /** @var LoggerInterface $logger */
            $logger = $this->container->get('console.logger');
            $server = new \Amp\Http\Server\Server(
                $sockets,
                new CallableRequestHandler($this->callableFromInstanceMethod('requestHandler')),
                $logger
            );

            yield $server->start();
        });
    }

    /**
     * @inheritDoc
     */
    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    /**
     * @param Request $request
     * @return \Generator
     */
    private function requestHandler(Request $request): \Generator
    {
        if (!$this->isAuthorized($request)) {
            return new Response(Status::UNAUTHORIZED, ['WWW-Authenticate' => 'Basic realm="ESB Console"']);
        }
        // Fetch method and URI from somewhere
        $httpMethod = $request->getMethod();
        $uri = $request->getUri()->getPath();
        $filePath = $this->publicDir . DIRECTORY_SEPARATOR . ltrim($uri, '/');
        if ((yield File\exists($filePath)) && (yield File\isfile($filePath))) {
            return new Response(Status::OK, [], yield File\get($filePath));
        }

        $dispatcher = $this->getDispatcher($request);

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

    /**
     * @param Request $request
     * @return Dispatcher
     */
    private function getDispatcher(Request $request): Dispatcher
    {
        return \FastRoute\simpleDispatcher(
            function (RouteCollector $r) use ($request) {
                $r->addRoute('GET', '/', new IndexController($request, $this->container));
                $r->addRoute('GET', '/tube/{tube}', new TubeController($request, $this->container));
                $r->addRoute('GET', '/kick/{jobId:\d+}', new KickController($request, $this->container));
                $r->addRoute('GET', '/delete/{jobId:\d+}', new DeleteController($request, $this->container));
                $r->addRoute('GET', '/job/{jobId:\d+}', new JobController($request, $this->container));
            }
        );
    }

    /**
     * @param Request $request
     * @return bool
     */
    private function isAuthorized(Request $request): bool
    {
        $authorization = $request->getHeader('Authorization');
        if (!$authorization) {
            return false;
        }
        $authorization = str_ireplace('Basic ', '', $authorization);
        $authorization = base64_decode($authorization);
        list($username, $password) = explode(':', $authorization);
        return $username === $this->config['username'] && $password === $this->config['password'];
    }
}
