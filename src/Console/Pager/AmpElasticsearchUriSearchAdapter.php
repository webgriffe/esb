<?php

namespace Webgriffe\Esb\Console\Pager;

use Amp\Promise;
use Amp\Success;
use Webgriffe\AmpElasticsearch\Client;
use Webgriffe\Esb\Console\Pager\AsyncPagerAdapterInterface;
use function Amp\call;

final class AmpElasticsearchUriSearchAdapter implements AsyncPagerAdapterInterface
{
    /**
     * @var Client
     */
    private $client;
    /**
     * @var string
     */
    private $index;
    /**
     * @var string
     */
    private $query;
    /**
     * @var array
     */
    private $options;
    /**
     * @var int|null
     */
    private $totalHits;


    /**
     * AmpElasticsearchAdapter constructor.
     * @param Client $client
     * @param string $index
     * @param string $query
     * @param array $options
     */
    public function __construct(Client $client, string $index, string $query, array $options = [])
    {
        $this->client = $client;
        $this->index = $index;
        $this->query = $query;
        $this->options = $options;
    }

    public function getNbResults(): Promise
    {
        if ($this->totalHits !== null) {
            return new Success($this->totalHits);
        }

        return call(function () {
            $response = yield $this->client->uriSearchOneIndex(
                $this->index,
                $this->query,
                $this->options
            );
            return $response['hits']['total']['value'];
        });
    }

    public function getSlice(int $offset, int $length): Promise
    {
        return call(function () use ($offset, $length) {
            $response = yield $this->client->uriSearchOneIndex(
                $this->index,
                $this->query,
                array_merge($this->options, ['from' => $offset, 'size' => $length])
            );
            $this->totalHits = $response['hits']['total']['value'];
            $jobs = [];
            foreach ($response['hits']['hits'] as $rawJob) {
                $jobs[] = $rawJob['_source'];
            }
            return $jobs;
        });
    }
}
