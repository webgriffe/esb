<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Console\Controller;

use Amp\Http\Server\Request;
use function Amp\call;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\Promise;
use Webgriffe\AmpElasticsearch\Error as AmpElasticsearchError;

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
                    'workedJobs' => yield $this->getWorkedJobs($flowCode),
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
            try {
                $response = yield $this->getElasticsearch()->getClient()->search(
                    ['match_all' => new \stdClass()],
                    $flowCode
                );
                return $response['hits']['total']['value'];
            } catch (AmpElasticsearchError $e) {
                if ($this->isIndexNotFoundException($e)) {
                    return 0;
                }
                throw $e;
            }
        });
    }

    private function getErroredJobs(string $flowCode): Promise
    {
        return call(function () use ($flowCode) {
            try {
                $response = yield $this->getElasticsearch()->getClient()->search(
                    ['term' => ['lastEvent.type.keyword' => 'errored']],
                    $flowCode
                );
                return $response['hits']['total']['value'];
            } catch (AmpElasticsearchError $e) {
                if ($this->isIndexNotFoundException($e)) {
                    return 0;
                }
                throw $e;
            }
        });
    }

    private function getWorkedJobs(string $flowCode): Promise
    {
        return call(function () use ($flowCode) {
            try {
                $response = yield $this->getElasticsearch()->getClient()->search(
                    ['term' => ['lastEvent.type.keyword' => 'worked']],
                    $flowCode
                );
                return $response['hits']['total']['value'];
            } catch (AmpElasticsearchError $e) {
                if ($this->isIndexNotFoundException($e)) {
                    return 0;
                }
                throw $e;
            }
        });
    }

    private function isIndexNotFoundException(AmpElasticsearchError $e): bool
    {
        $exceptionData = $e->getData();
        return (
            $exceptionData &&
            isset($exceptionData['error']) &&
            isset($exceptionData['error']['type']) &&
            $exceptionData['error']['type'] === 'index_not_found_exception'
        );
    }
}
