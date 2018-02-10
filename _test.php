<?php // basic (and dumb) HTTP server

require __DIR__ . '/vendor/autoload.php';

use Amp\Loop;
use Amp\ReactAdapter\ReactAdapter;

Loop::run(function () {
    $server = new \React\Http\Server(function (\Psr\Http\Message\ServerRequestInterface $request) {
        $body = $request->getBody();
        return new \React\Http\Response(
            200,
            array(
                'Content-Type' => 'text/plain'
            ),
            "Request body is: $body"
        );
    });

    $socket = new React\Socket\Server(8080, ReactAdapter::get());
    $server->listen($socket);
    echo "Server running at http://127.0.0.1:8080\n";
});
