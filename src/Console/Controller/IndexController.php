<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Console\Controller;

use Amp\Http\Server\Request;
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
    public function __invoke(Request $request): Promise
    {
        return call(function () {
            $flows = $this->getFlowManager()->getFlows();
            return new Response(Status::OK, [], $this->getTwig()->render('index.html.twig', array('flows' => $flows)));
        });
    }
}
