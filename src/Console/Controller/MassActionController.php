<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Console\Controller;

use Amp\Beanstalk\BeanstalkClient;
use function Amp\call;
use Amp\Http\Server\FormParser\Form;
use function Amp\Http\Server\FormParser\parseForm;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Promise;
use Psr\Log\LoggerInterface;
use Twig\Environment;
use Webgriffe\Esb\FlowManager;
use Webgriffe\Esb\Model\Job;
use Webgriffe\Esb\Model\RequeuedJobEvent;
use Webgriffe\Esb\NonUtf8Cleaner;
use Webgriffe\Esb\Service\ElasticSearch;

/**
 * @internal
 */
class MassActionController extends AbstractController
{
    /**
     * Hard cap on how many jobs a single "select all matching" mass action can touch in one request. Elasticsearch
     * itself refuses to paginate past index.max_result_window (10000 by default) without a scroll/search-after,
     * which this simple action does not implement.
     */
    private const SELECT_ALL_MATCHING_MAX_JOBS = 10000;

    public const SELECTED_JOBS_FIELD_NAME = 'selected[]';
    public const SELECT_ALL_MATCHING_FIELD_NAME = 'select-all-matching';
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
            $action = $form->getValue(self::ACTION_FIELD_NAME);

            $queryParams = [];
            parse_str($request->getUri()->getQuery(), $queryParams);
            $query = (string)($queryParams['query'] ?? '');
            $page = (string)($queryParams['page'] ?? '1');

            if ($form->getValue(self::SELECT_ALL_MATCHING_FIELD_NAME) === '1') {
                // The user asked for every job matching the current search, not just the ones checked on this
                // page (which, with a filter/pagination active, are never the same thing).
                $jobs = yield from $this->fetchAllMatchingJobIds($flow, $query);
            } else {
                $jobs = $form->getValueArray(self::SELECTED_JOBS_FIELD_NAME);
            }

            if ($action === self::ACTION_REQUEUE) {
                yield from $this->requeue($jobs, $flow);
            }
            $jobsCount = count($jobs);

            $redirectQuery = http_build_query([
                'query' => $query,
                'page' => $page,
                'massActionSuccess' => $action,
                'massActionCount' => $jobsCount,
            ]);

            return new Response(302, ['Location' => ["/flow/$flow?$redirectQuery"]]);
        });
    }

    /**
     * @param string $flow
     * @param string $query
     * @return \Generator<Promise>
     */
    private function fetchAllMatchingJobIds(string $flow, string $query): \Generator
    {
        $response = yield $this->getElasticsearch()->getClient()->uriSearchOneIndex(
            $flow,
            $query,
            ['size' => self::SELECT_ALL_MATCHING_MAX_JOBS, '_source' => 'false']
        );

        $totalMatching = $response['hits']['total']['value'];
        $ids = array_map(
            static function (array $hit): string {
                return $hit['_id'];
            },
            $response['hits']['hits']
        );

        if ($totalMatching > count($ids)) {
            $this->logger->warning(
                'A "select all matching" mass action matched more jobs than the maximum supported in one go; ' .
                'only a subset was processed.',
                [
                    'flow' => $flow,
                    'query' => $query,
                    'total_matching' => $totalMatching,
                    'processed' => count($ids),
                    'max_supported' => self::SELECT_ALL_MATCHING_MAX_JOBS,
                ]
            );
        }

        return $ids;
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
            if ($job instanceof Job) {
                $job->setBeanstalkId($jobBeanstalkId);
                yield $this->getElasticsearch()->indexJob($job, $flow);
            }

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
