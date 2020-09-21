<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Console\Controller;

use Amp\Beanstalk\BeanstalkClient;
use Amp\Http\Server\FormParser\Form;
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
use function Amp\Http\Server\FormParser\parseForm;

/**
 * @internal
 */
class MassActionController extends AbstractController
{
    public const SELECTED_JOBS_FIELD_NAME = 'selected[]';
    public const ACTION_FIELD_NAME = 'job-select-action';
    public const ACTION_REQUEUE = 'requeue';

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
    public function __invoke(Request $request, string $flow): Promise
    {
        return call(function () use ($request, $flow) {
            /** @var Form $form */
            $form = yield parseForm($request);
            $jobs = $form->getValueArray(self::SELECTED_JOBS_FIELD_NAME);
            $action = $form->getValue(self::ACTION_FIELD_NAME);
            if ($action === self::ACTION_REQUEUE) {
                yield from $this->requeue($jobs, $flow);
            }
            $jobsCount = count($jobs);

            return new Response(302, ['Location' => ["/flow/$flow?massActionSuccess=$action&massActionCount=$jobsCount"]]);
        });
    }

    /**
     * @param array<string> $jobs
     * @param string $flow
     * @return \Generator<Promise>
     */
    private function requeue(array $jobs, string $flow): \Generator
    {
        foreach ($jobs as $jobId) {
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
        }
    }
}
