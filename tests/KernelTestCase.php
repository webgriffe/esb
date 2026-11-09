<?php
declare(strict_types=1);

namespace Webgriffe\Esb;

use Amp\File\Driver\BlockingDriver;
use Amp\Http\Client\SocketException;
use Webgriffe\AmpElasticsearch\Client;
use Amp\Loop;
use Amp\Promise;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use org\bovigo\vfs\vfsStream;
use Symfony\Component\Yaml\Yaml;
use Webmozart\Assert\Assert;
use function Amp\call;
use function Amp\File\filesystem;

class KernelTestCase extends BeanstalkTestCase
{
    private const ELASTICSEARCH_CONNECTION_TIMEOUT = 60;

    protected static ?Kernel $kernel = null;

    protected Client $esClient;

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
     * @param array<array-key, mixed> $localConfig
     * @throws \Exception
     */
    protected static function createKernel(array $localConfig): void
    {
        $config = array_merge_recursive(
            ['services' => ['_defaults' => ['autowire' => true, 'autoconfigure' => true, 'public' => true]]],
            $localConfig
        );
        vfsStream::setup('root', null, ['config.yml' => Yaml::dump($config)]);
        self::$kernel = new Kernel(vfsStream::url('root/config.yml'), 'test');
    }

    protected static function kernel(): Kernel
    {
        Assert::notNull(self::$kernel, 'Kernel not created, call createKernel() first.');

        return self::$kernel;
    }

    /**
     * @throws \Exception
     */
    protected function logHandler(): TestHandler
    {
        $logHandler = self::kernel()->getContainer()->get(TestHandler::class);
        Assert::isInstanceOf($logHandler, TestHandler::class);

        return $logHandler;
    }

    protected function dumpLog(): string
    {
        $records = $this->logHandler()->getRecords();
        return implode('', array_map(static fn (LogRecord $entry): string => is_string($entry->formatted) ? $entry->formatted : '', $records));
    }

    protected function stopWhen(callable $stopCondition, int $timeoutInSeconds = 10): void
    {
        $start = Loop::now();
        //Setting the interval to a too-small value causes segmentation faults in some environments (such as Travis)
        Loop::repeat(1000, function (string $watcherId) use ($start, $stopCondition, $timeoutInSeconds) {
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

    /**
     * @param Promise<mixed> $promise
     */
    protected static function wait(Promise $promise): mixed
    {
        return Promise\wait($promise);
    }

    private function elasticSearchReset(): void
    {
        $this->waitForElasticSearch();
        self::wait($this->esClient->deleteIndex('_all'));
        self::wait($this->esClient->refresh());
        do {
            $stats = self::wait($this->esClient->statsIndex('_all'));
            Assert::isArray($stats);
            Assert::keyExists($stats, 'indices');
            Assert::isArray($stats['indices']);
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
                $response = self::wait($this->esClient->catHealth());
                Assert::isArray($response);
                Assert::keyExists($response, 0);
                Assert::isArray($response[0]);
                $status = $response[0]['status'] ?? false;
            } catch (SocketException $e) {
                // We want to retry until timeout is reached
                $status = false;
            }
        } while (!in_array($status, ['green', 'yellow']));
    }
}
