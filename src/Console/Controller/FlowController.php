<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Console\Controller;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\Promise;
use Webgriffe\Esb\Console\Pager\AmpElasticsearchUriSearchAdapter;
use Webgriffe\Esb\Console\Pager\AsyncPager;
use function Amp\call;

/**
 * @internal
 */
class FlowController extends AbstractController
{
    public function __invoke(Request $request, string $flowCode): Promise
    {
        return call(function () use ($request, $flowCode) {
            $queryParams = [];
            parse_str($request->getUri()->getQuery(), $queryParams);
            $query = $queryParams['query'] ?? '';
            $page = (int)($queryParams['page'] ?? '1');
            $adapter = new AmpElasticsearchUriSearchAdapter(
                $this->getElasticsearchClient(),
                $flowCode,
                $query,
                ['sort' => 'lastEvent.time:desc']
            );
            $pager = new AsyncPager($adapter, 10, $page);
            yield $pager->init();
            return new Response(
                Status::OK,
                [],
                $this->getTwig()->render(
                    'flow.html.twig',
                    [
                        'flowCode' => $flowCode,
                        'pager' => $pager,
                        'query' => $query,
                    ]
                )
            );
        });
    }
}
