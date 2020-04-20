<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Unit\Producer;

use PHPUnit\Framework\TestCase;
use Webgriffe\Esb\CrontabProducerInterface;
use Webgriffe\Esb\Producer\CleanOldElasticsearchDocuments;
use function Amp\Promise\wait;

class CleanOldElasticsearchDocumentsTest extends TestCase
{
    const CRONTAB_EXPRESSION = '0 15 10 ? * *';
    /**
     * @var CleanOldElasticsearchDocuments
     */
    private $producer;

    protected function setUp()
    {
        $this->producer = new CleanOldElasticsearchDocuments(self::CRONTAB_EXPRESSION);
    }

    /**
     * @test
     */
    public function it_is_a_crontab_producer()
    {
        $this->assertInstanceOf(CrontabProducerInterface::class, $this->producer);
    }

    /**
     * @test
     */
    public function it_has_a_crontab_expression()
    {
        $this->assertEquals(self::CRONTAB_EXPRESSION, $this->producer->getCrontab());
    }
}
