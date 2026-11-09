<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Console\Controller;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\Promise;
use Webgriffe\AmpElasticsearch\Error as AmpElasticsearchError;
use Webmozart\Assert\Assert;
use function Amp\call;

/**
 * @internal
 */
class IndexController extends AbstractController
{
    /**
     * @return Promise<Response>
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

    /**
     * @return Promise<int>
     */
    private function getTotalJobs(string $flowCode): Promise
    {
        return call(function () use ($flowCode) {
            try {
                $response = yield $this->getElasticsearch()->getClient()->count($flowCode);

                return $this->extractCount($response);
            } catch (AmpElasticsearchError $e) {
                if ($this->isIndexNotFoundException($e)) {
                    return 0;
                }

                throw $e;
            }
        });
    }

    /**
     * @return Promise<int>
     */
    private function getErroredJobs(string $flowCode): Promise
    {
        return call(function () use ($flowCode) {
            try {
                $response = yield $this->getElasticsearch()->getClient()->count(
                    $flowCode,
                    [],
                    ['term' => ['lastEvent.type.keyword' => 'errored']]
                );

                return $this->extractCount($response);
            } catch (AmpElasticsearchError $e) {
                if ($this->isIndexNotFoundException($e)) {
                    return 0;
                }

                throw $e;
            }
        });
    }

    /**
     * @return Promise<int>
     */
    private function getWorkedJobs(string $flowCode): Promise
    {
        return call(function () use ($flowCode) {
            try {
                $response = yield $this->getElasticsearch()->getClient()->count(
                    $flowCode,
                    [],
                    ['term' => ['lastEvent.type.keyword' => 'worked']]
                );

                return $this->extractCount($response);
            } catch (AmpElasticsearchError $e) {
                if ($this->isIndexNotFoundException($e)) {
                    return 0;
                }

                throw $e;
            }
        });
    }

    private function extractCount(mixed $response): int
    {
        Assert::isArray($response);
        Assert::keyExists($response, 'count');
        Assert::integer($response['count']);

        return $response['count'];
    }

    private function isIndexNotFoundException(AmpElasticsearchError $e): bool
    {
        $error = $e->getData()['error'] ?? null;

        return is_array($error) && ($error['type'] ?? null) === 'index_not_found_exception';
    }
}
