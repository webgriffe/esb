<?php

namespace Webgriffe\Esb\Console;

use Pagerfanta\Adapter\AdapterInterface;
use Webgriffe\AmpElasticsearch\Client;
use function Amp\call;
use function Amp\Promise\wait;

class AmpElasticsearchUriSearchAdapter implements AdapterInterface
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

    /**
     * @inheritDoc
     * @throws \Throwable
     */
    public function getNbResults()
    {
        if ($this->totalHits !== null) {
            return $this->totalHits;
        }
        $response = wait(
        $this->client->uriSearchOneIndex(
            $this->index,
            $this->query,
            $this->options
        )
    );
        return $response['hits']['total']['value'];
    }

    /**
     * @inheritDoc
     * @throws \Throwable
     */
    public function getSlice($offset, $length)
    {
        $response = wait(
            $this->client->uriSearchOneIndex(
                $this->index,
                $this->query,
                array_merge($this->options, ['from' => $offset, 'size' => $length])
            )
        );
        $this->totalHits = $response['hits']['total']['value'];
        $jobs = [];
        foreach ($response['hits']['hits'] as $rawJob) {
            $jobs[] = $rawJob['_source'];
        }
        return $jobs;
    }
}