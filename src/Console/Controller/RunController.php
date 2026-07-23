<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Console\Controller;

use function Amp\call;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\Promise;
use Psr\Log\LoggerInterface;
use Twig\Environment;
use Webgriffe\Esb\FlowManager;
use Webgriffe\Esb\Service\ElasticSearch;

/**
 * @internal
 */
class RunController extends AbstractController
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        Environment $twig,
        FlowManager $flowManager,
        ElasticSearch $elasticSearch,
        LoggerInterface $logger
    ) {
        parent::__construct($twig, $flowManager, $elasticSearch);
        $this->logger = $logger;
    }

    /**
     * @return Promise<Response>
     */
    public function __invoke(Request $request, string $flow): Promise
    {
        return call(function () use ($flow) {
            foreach ($this->getFlowManager()->getFlows() as $flowObject) {
                if ($flowObject->getCode() !== $flow) {
                    continue;
                }
                if (!$flowObject->canRunManually()) {
                    return new Response(
                        Status::BAD_REQUEST,
                        [],
                        "Flow \"$flow\" cannot be run manually because its producer is an HTTP request producer."
                    );
                }

                $jobsCount = yield $flowObject->getProducerInstance()->produceAndQueueJobs();
                $this->logger->info(
                    'Manually ran a flow\'s producer from the web console',
                    ['flow' => $flow, 'produced_jobs' => $jobsCount]
                );
                return new Response(302, ['Location' => ["/flow/$flow?ran=1&ranCount=$jobsCount"]]);
            }

            return new Response(Status::NOT_FOUND, [], "Flow \"$flow\" not found.");
        });
    }
}
