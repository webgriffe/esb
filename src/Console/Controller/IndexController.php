<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Console\Controller;

use function Amp\call;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\Promise;

/**
 * @internal
 */
class IndexController extends AbstractController
{
    /**
     * @return Promise
     */
    public function __invoke(): Promise
    {
        return call(function () {
            $tubes = yield array_map(
                function (string $tube) {
                    return $this->getBeanstalkClient()->getTubeStats($tube);
                },
                yield $this->getBeanstalkClient()->listTubes()
            );
            return new Response(Status::OK, [], $this->getTwig()->render('index.html.twig', array('tubes' => $tubes)));
        });
    }
}
