<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Console;

use Amp\CallableMaker;
use Amp\File;
use Amp\Http\Server\HttpServer;
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
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
     * @var array<string, string>
     */
    private $config;
    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var ContainerInterface|null
     */
    private $container;

    /**
     * @param string $publicDir
     * @param array<string, string> $config
     * @param LoggerInterface $logger
     */
    public function __construct(string $publicDir, array $config, LoggerInterface $logger)
    {
        $this->publicDir = $publicDir;
        $this->config = $config;
        $this->logger = $logger;
    }

    public function boot(): void
    {
        Loop::defer(function () {
            $port = $this->config['port'];
            $sockets = [
                Socket\listen("0.0.0.0:$port"),
                Socket\listen("[::]:$port"),
            ];

            $server = new HttpServer(
                $sockets,
                new CallableRequestHandler($this->callableFromInstanceMethod('requestHandler')),
                $this->logger
            );

            yield $server->start();
        });
    }

    /**
     * @inheritDoc
     */
    public function setContainer(ContainerInterface $container = null): void
    {
        $this->container = $container;
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    /**
     * @param Request $request
     * @return \Generator<Promise>
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
                array_unshift($vars, $request);
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
                if (null === $this->container) {
                    throw new \RuntimeException('Container must be set on HTTP Server service.');
                }
                $this->container->set('console.controller.request', $request);
                $r->addRoute('GET', '/', $this->container->get('console.controller.index'));
                $r->addRoute('GET', '/flow/{flow}', $this->container->get('console.controller.flow'));
                $r->addRoute('GET', '/flow/{flow}/job/{jobId}', $this->container->get('console.controller.job'));
                $r->addRoute(
                    'GET',
                    '/flow/{flow}/job/{jobId}/requeue',
                    $this->container->get('console.controller.requeue')
                );
                $r->addRoute('POST', '/flow/{flow}/mass-action', $this->container->get('console.controller.mass_action'));
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
