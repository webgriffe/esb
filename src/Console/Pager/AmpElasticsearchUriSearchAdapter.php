<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Console\Pager;

use Amp\Promise;
use Amp\Success;
use Webgriffe\AmpElasticsearch\Client;
use Webgriffe\AmpElasticsearch\Error as AmpElasticsearchError;
use Webmozart\Assert\Assert;
use function Amp\call;

final class AmpElasticsearchUriSearchAdapter implements AsyncPagerAdapterInterface
{
    /** @var array<string, string|int>  */
    private readonly array $options;

    private ?int $totalHits = null;

    /**
     * @param array<string, string|int> $options
     */
    public function __construct(
        private readonly Client $client,
        private readonly string $index,
        private readonly string $query,
        array $options = [],
    ) {
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

                return self::extractHits($response)['total']['value'];
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

            $hits = self::extractHits($response);
            $this->totalHits = $hits['total']['value'];
            $jobs = [];
            foreach ($hits['hits'] as $rawJob) {
                Assert::isArray($rawJob);
                Assert::keyExists($rawJob, '_source');
                $jobs[] = $rawJob['_source'];
            }

            return $jobs;
        });
    }

    /**
     * @return array{total: array{value: int<0, max>}, hits: array<mixed>}
     */
    private static function extractHits(mixed $response): array
    {
        Assert::isArray($response);
        Assert::keyExists($response, 'hits');
        $hits = $response['hits'];
        Assert::isArray($hits);
        Assert::keyExists($hits, 'total');
        Assert::isArray($hits['total']);
        Assert::keyExists($hits['total'], 'value');
        Assert::natural($hits['total']['value']);
        Assert::keyExists($hits, 'hits');
        Assert::isArray($hits['hits']);

        return [
            'total' => ['value' => $hits['total']['value']],
            'hits' => $hits['hits'],
        ];
    }

    private function isIndexNotFoundException(AmpElasticsearchError $e): bool
    {
        $error = $e->getData()['error'] ?? null;

        return is_array($error) && ($error['type'] ?? null) === 'index_not_found_exception';
    }
}
