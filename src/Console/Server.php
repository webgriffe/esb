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
use Webmozart\Assert\Assert;

/**
 * @internal
 */
class Server implements ContainerAwareInterface
{
    use CallableMaker;

    private ?ContainerInterface $container = null;

    /**
     * @param array<string, string> $config
     */
    public function __construct(
        private readonly string $publicDir,
        private readonly array $config,
        private readonly LoggerInterface $logger,
    ) {
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
    public function setContainer(?ContainerInterface $container = null): void
    {
        $this->container = $container;
    }


    /**
     * @return \Generator<int, Promise<mixed>, mixed, Response>
     *
     * @phpstan-ignore method.unused
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
        if ((yield File\exists($filePath)) && (yield File\isFile($filePath))) {
            $fileContent = yield File\read($filePath);
            Assert::string($fileContent);
            return new Response(Status::OK, [], $fileContent);
        }

        $dispatcher = $this->getDispatcher($request);

        $routeInfo = $dispatcher->dispatch($httpMethod, $uri);
        switch ($routeInfo[0]) {
            case Dispatcher::METHOD_NOT_ALLOWED:
                $allowedMethods = $routeInfo[1];
                Assert::isArray($allowedMethods);
                Assert::allString($allowedMethods);
                $response = new Response(
                    Status::METHOD_NOT_ALLOWED,
                    [],
                    'Method Not Allowed. Allowed methods: ' . implode(', ', $allowedMethods)
                );
                break;
            case Dispatcher::FOUND:
                $handler = $routeInfo[1];
                Assert::isCallable($handler);
                Assert::isArray($routeInfo[2]);
                $vars = array_values($routeInfo[2]);
                array_unshift($vars, $request);
                $responsePromise = \call_user_func_array($handler, $vars);
                Assert::isInstanceOf($responsePromise, Promise::class);
                $response = yield $responsePromise;
                Assert::isInstanceOf($response, Response::class);
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
