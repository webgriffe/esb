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
            $flowView = [];
            foreach ($flows as $flow) {
                $flowCode = $flow->getCode();
                $flowView[$flowCode] = [
                    'code' => $flowCode,
                    'description' => $flow->getDescription(),
                    'producer' => $flow->getProducerClassName(),
                    'worker' => $flow->getWorkerClassName(),
                    'erroredJobs' => yield $this->getErroredJobs($flowCode),
                    'totalJobs' => yield $this->getTotalJobs($flowCode),
                ];
            }
            return new Response(Status::OK, [], $this->getTwig()->render('index.html.twig', ['flows' => $flowView]));
        });
    }

    private function getTotalJobs(string $flowCode): Promise
    {
        return call(function () use ($flowCode) {
            $response = yield $this->getElasticsearchClient()->search(['match_all' => new \stdClass()], $flowCode);
            return $response['hits']['total']['value'];
        });
    }

    private function getErroredJobs(string $flowCode): Promise
    {
        return call(function () use ($flowCode) {
            $response = yield $this->getElasticsearchClient()->search(
                ['term' => ['lastEvent.type.keyword' => 'errored']],
                $flowCode
            );
            return $response['hits']['total']['value'];
        });
    }
}
