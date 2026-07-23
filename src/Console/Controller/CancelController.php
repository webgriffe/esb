<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Console\Controller;

use Amp\Beanstalk\BeanstalkClient;
use function Amp\call;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Promise;
use Psr\Log\LoggerInterface;
use Twig\Environment;
use Webgriffe\Esb\FlowManager;
use Webgriffe\Esb\Model\CancelledJobEvent;
use Webgriffe\Esb\Model\Job;
use Webgriffe\Esb\Service\ElasticSearch;

/**
 * @internal
 */
class CancelController extends AbstractController
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var BeanstalkClient
     */
    private $beanstalkClient;

    public function __construct(
        Environment $twig,
        FlowManager $flowManager,
        ElasticSearch $elasticSearch,
        LoggerInterface $logger,
        BeanstalkClient $beanstalkClient
    ) {
        parent::__construct($twig, $flowManager, $elasticSearch);
        $this->logger = $logger;
        $this->beanstalkClient = $beanstalkClient;
    }

    /**
     * @return Promise<Response>
     */
    public function __invoke(Request $request, string $flow, string $jobId): Promise
    {
        return call(function () use ($flow, $jobId) {
            $job = yield $this->getElasticsearch()->fetchJob($jobId, $flow);

            $beanstalkId = $job instanceof Job ? $job->getBeanstalkId() : null;
            if ($beanstalkId === null) {
                // No Beanstalk id was ever recorded for this Job (it predates this feature, or it was already
                // removed from the tube), so there is nothing left in the queue to cancel.
                return new Response(302, ['Location' => ["/flow/$flow/job/$jobId?cancelled=0"]]);
            }

            $deleted = yield $this->beanstalkClient->delete($beanstalkId);
            if (!$deleted) {
                // Beanstalk returns NOT_FOUND both when the job no longer exists and when it is currently
                // reserved by a worker (i.e. already being processed), so it can no longer be cancelled.
                return new Response(302, ['Location' => ["/flow/$flow/job/$jobId?cancelled=0"]]);
            }

            $job->addEvent(new CancelledJobEvent(new \DateTime()));
            yield $this->getElasticsearch()->indexJob($job, $flow);

            $this->logger->info(
                'Manually cancelled a queued Job from the web console',
                ['flow' => $flow, 'job_uuid' => $jobId, 'beanstalk_id' => $beanstalkId]
            );

            return new Response(302, ['Location' => ["/flow/$flow/job/$jobId?cancelled=1"]]);
        });
    }
}
