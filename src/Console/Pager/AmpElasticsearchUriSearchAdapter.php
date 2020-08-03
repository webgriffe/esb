<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Console\Pager;

use Amp\Promise;
use Amp\Success;
use Webgriffe\AmpElasticsearch\Client;
use Webgriffe\AmpElasticsearch\Error as AmpElasticsearchError;
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
     * @var array<string, string|int>
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
     * @param array<string, string|int> $options
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
            try {
                $response = yield $this->client->uriSearchOneIndex(
                    $this->index,
                    $this->query,
                    $this->options
                );
                return $response['hits']['total']['value'];
            } catch (AmpElasticsearchError $e) {
                if ($this->isIndexNotFoundException($e)) {
                    return 0;
                }
                throw $e;
            }
        });
    }

    public function getSlice(int $offset, int $length): Promise
    {
        return call(function () use ($offset, $length) {
            try {
                $response = yield $this->client->uriSearchOneIndex(
                    $this->index,
                    $this->query,
                    array_merge($this->options, ['from' => $offset, 'size' => $length])
                );
            } catch (AmpElasticsearchError $e) {
                if ($this->isIndexNotFoundException($e)) {
                    $this->totalHits = 0;
                    return [];
                }
                throw $e;
            }

            $this->totalHits = $response['hits']['total']['value'];
            $jobs = [];
            foreach ($response['hits']['hits'] as $rawJob) {
                $jobs[] = $rawJob['_source'];
            }
            return $jobs;
        });
    }

    private function isIndexNotFoundException(AmpElasticsearchError $e): bool
    {
        $exceptionData = $e->getData();
        return
            $exceptionData &&
            isset($exceptionData['error']) &&
            isset($exceptionData['error']['type']) &&
            $exceptionData['error']['type'] === 'index_not_found_exception'
        ;
    }
}
