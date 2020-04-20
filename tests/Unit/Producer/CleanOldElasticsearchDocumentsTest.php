<?php
declare(strict_types=1);

namespace Webgriffe\Esb\Unit\Producer;

use PHPUnit\Framework\TestCase;
use Webgriffe\Esb\CrontabProducerInterface;
use Webgriffe\Esb\Producer\CleanOldElasticsearchDocuments;
use function Amp\Promise\wait;

class CleanOldElasticsearchDocumentsTest extends TestCase
{
    /**
     * @test
     */
    public function it_is_a_crontab_producer()
    {
        $producer = new CleanOldElasticsearchDocuments();
        $this->assertInstanceOf(CrontabProducerInterface::class, $producer);
    }
}
