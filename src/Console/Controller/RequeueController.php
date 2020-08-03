<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Console\Controller;

use Amp\Beanstalk\BeanstalkClient;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Promise;
use Psr\Log\LoggerInterface;
use Twig\Environment;
use Webgriffe\Esb\FlowManager;
use Webgriffe\Esb\Model\RequeuedJobEvent;
use Webgriffe\Esb\NonUtf8Cleaner;
use Webgriffe\Esb\Service\ElasticSearch;
use function Amp\call;

class RequeueController extends AbstractController
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
        return call(function () use ($jobId, $flow) {
            $job = yield $this->getElasticsearch()->fetchJob($jobId, $flow);
            $job->addEvent(new RequeuedJobEvent(new \DateTime()));
            yield $this->getElasticsearch()->indexJob($job, $flow);

            yield $this->beanstalkClient->use($flow);
            $jobBeanstalkId = yield $this->beanstalkClient->put(
                $job->getUuid(),
                $job->getTimeout(),
                $job->getDelay(),
                $job->getPriority()
            );

            $this->logger->info(
                'Successfully re-queued a Job',
                [
                    'job_beanstalk_id' => $jobBeanstalkId,
                    'job_uuid' => $job->getUuid(),
                    'payload_data' => NonUtf8Cleaner::clean($job->getPayloadData())
                ]
            );

            return new Response(302, ['Location' => ["/flow/$flow/job/$jobId?requeued=1"]]);
        });
    }
}
