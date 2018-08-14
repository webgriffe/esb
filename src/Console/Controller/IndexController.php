<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Console\Controller;

use function Amp\call;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\Promise;

class IndexController
{
    use ControllerTrait;

    /**
     * @return Promise
     */
    public function __invoke(): Promise
    {
        return call(function () {
            $tubes = yield array_map(
                function (string $tube) {
                    return $this->beanstalkClient->getTubeStats($tube);
                },
                yield $this->beanstalkClient->listTubes()
            );
            return new Response(Status::OK, [], $this->twig->render('index.html.twig', array('tubes' => $tubes)));
        });
    }
}
