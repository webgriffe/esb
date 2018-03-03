<?php

namespace Webgriffe\Esb\Service\Console;

use function Amp\call;
use Amp\CallableMaker;
use Amp\ReactAdapter\ReactAdapter;
use function Interop\React\Promise\adapt;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\StreamingServer;
use React\Promise\PromiseInterface;
use React\Socket\Server as SocketServer;
use RingCentral\Psr7\Response;
use Amp\File;

class Server
{
    use CallableMaker;

    public function boot()
    {
        $server = new StreamingServer($this->callableFromInstanceMethod('requestHandler'));
        $server->listen(new SocketServer(8080, ReactAdapter::get()));
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    /**
     * @param ServerRequestInterface $request
     * @return Response|PromiseInterface
     */
    private function requestHandler(ServerRequestInterface $request)
    {
        return adapt(call(function () use ($request) {
            return new Response(200, [], yield File\get(__DIR__ . '/index.html'));
        }));
    }
}
