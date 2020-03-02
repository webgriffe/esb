<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Console\Controller;

use Amp\Beanstalk\BeanstalkClient;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Twig\Environment;
use Webgriffe\AmpElasticsearch\Client;
use function Amp\call;

/**
 * @internal
 */
class JobController extends AbstractController
{
    /**
     * @var Client
     */
    private $elasticSearchClient;

    public function __construct(Environment $twig, BeanstalkClient $beanstalkClient, Client $elasticSearchClient)
    {
        parent::__construct($twig, $beanstalkClient);
        $this->elasticSearchClient = $elasticSearchClient;
    }


    public function __invoke(Request $request, string $jobId)
    {
        return call(function () use ($jobId) {
            $result = yield $this->elasticSearchClient->search(['term' => ['uuid.keyword' => ['value' => $jobId]]]);
            $document = $result['hits']['hits'][0];
            $job = $document['_source'];

            return new Response(
                Status::OK,
                [],
                $this->getTwig()->render('job.html.twig', ['job' => $job])
            );
        });
    }
}
