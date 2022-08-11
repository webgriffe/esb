<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

use Amp\Artax\Response;
use Amp\Artax\SocketException;
use Webgriffe\AmpElasticsearch\Client;
use Amp\File\BlockingDriver;
use Amp\Loop;
use Amp\Promise;
use Monolog\Handler\TestHandler;
use org\bovigo\vfs\vfsStream;
use Symfony\Component\Yaml\Yaml;
use function Amp\call;
use function Amp\File\filesystem;

class KernelTestCase extends BeanstalkTestCase
{
    private const ELASTICSEARCH_CONNECTION_TIMEOUT = 60;

    /**
     * @var Kernel|null
     */
    protected static $kernel;
    /**
     * @var Client
     */
    protected $esClient;

    /**
     * @throws \Throwable
     */
    protected function setUp(): void
    {
        parent::setUp();
        filesystem(new BlockingDriver());
        $this->esClient = new Client(getenv('ES_BASE_URI') ?: 'http://127.0.0.1:9200');
        $this->elasticSearchReset();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::$kernel = null;
        gc_collect_cycles();
    }

    /**
     * @param array $localConfig
     * @throws \Exception
     */
    protected static function createKernel(array $localConfig)
    {
        $config = array_merge_recursive(
            ['services' => ['_defaults' => ['autowire' => true, 'autoconfigure' => true, 'public' => true]]],
            $localConfig
        );
        vfsStream::setup('root', null, ['config.yml' => Yaml::dump($config)]);
        self::$kernel = new Kernel(vfsStream::url('root/config.yml'), 'test');
    }

    /**
     * @return TestHandler
     * @throws \Exception
     */
    protected function logHandler()
    {
        /** @noinspection OneTimeUseVariablesInspection */
        /** @var TestHandler $logHandler */
        $logHandler = self::$kernel->getContainer()->get(TestHandler::class);
        return $logHandler;
    }

    protected function dumpLog()
    {
        $records = $this->logHandler()->getRecords();
        return implode('', array_map(function ($entry) {
            return $entry['formatted'];
        }, $records));
    }

    protected function stopWhen(callable $stopCondition, int $timeoutInSeconds = 10)
    {
        $start = Loop::now();
        //Setting the interval to a too-small value causes segmentation faults in some environments (such as Travis)
        Loop::repeat(1000, function ($watcherId) use ($start, $stopCondition, $timeoutInSeconds) {
            if (yield call($stopCondition)) {
                Loop::cancel($watcherId);
                Loop::stop();
            }
            if ((Loop::now() - $start) >= $timeoutInSeconds * 1000) {
                $log = $this->dumpLog();
                throw new \RuntimeException(
                    sprintf("Stop condition not reached within %s seconds timeout! Log:\n%s", $timeoutInSeconds, $log)
                );
            }
        });
    }

    private function elasticSearchReset(): void
    {
        $this->waitForElasticSearch();
        Promise\wait($this->esClient->deleteIndex('_all'));
        Promise\wait($this->esClient->refresh());
        do {
            $stats = Promise\wait($this->esClient->statsIndex('_all'));
        } while (count($stats['indices']));
    }

    private function waitForElasticSearch(): void
    {
        $start = time();
        $status = false;
        do {
            if (time() - $start >= self::ELASTICSEARCH_CONNECTION_TIMEOUT) {
                throw new \RuntimeException(
                    sprintf(
                        'ElasticSearch is still not available after %s seconds! Latest status was "%s".',
                        self::ELASTICSEARCH_CONNECTION_TIMEOUT,
                        is_string($status) ? $status : 'unknown'
                    )
                );
            }
            try {
                /** @var Response $response */
                $response = Promise\wait($this->esClient->catHealth());
                $status = $response[0]['status'];
            } catch (SocketException $e) {
                // We want to retry until timeout is reached
                $status = false;
            }
        } while (!in_array($status, ['green', 'yellow']));
    }
}
