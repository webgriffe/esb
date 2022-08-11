<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Console\Controller;

use function Amp\call;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\Promise;
use Webgriffe\Esb\Console\Pager\AmpElasticsearchUriSearchAdapter;
use Webgriffe\Esb\Console\Pager\AsyncPager;

/**
 * @internal
 */
class FlowController extends AbstractController
{
    /**
     * @return Promise<Response>
     */
    public function __invoke(Request $request, string $flowCode): Promise
    {
        return call(function () use ($request, $flowCode) {
            $queryParams = [];
            parse_str($request->getUri()->getQuery(), $queryParams);
            $query = $queryParams['query'] ?? '';
            $page = (int)($queryParams['page'] ?? '1');
            $massActionSuccess = $queryParams['massActionSuccess'] ?? false;
            $massActionCount = $queryParams['massActionCount'] ?? 0;
            $adapter = new AmpElasticsearchUriSearchAdapter(
                $this->getElasticsearch()->getClient(),
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
                        'massActionSuccess' => $massActionSuccess,
                        'massActionCount' => $massActionCount
                    ]
                )
            );
        });
    }
}
