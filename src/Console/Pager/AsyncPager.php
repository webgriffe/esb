<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Console\Pager;

use Amp\Promise;
use Pagerfanta\Adapter\AdapterInterface;
use Pagerfanta\Adapter\FixedAdapter;
use Pagerfanta\Exception\LessThan1CurrentPageException;
use Pagerfanta\Exception\LessThan1MaxPagesException;
use Pagerfanta\Exception\LessThan1MaxPerPageException;
use Pagerfanta\Exception\OutOfRangeCurrentPageException;
use Pagerfanta\PagerfantaInterface;
use Webmozart\Assert\Assert;
use function Amp\call;

/**
 * @internal
 * @implements PagerfantaInterface<mixed>
 */
class AsyncPager implements PagerfantaInterface
{
    /** @var int<0, max>|null */
    private ?int $nbResults = null;

    /** @var positive-int */
    private int $maxPerPage;

    /** @var positive-int */
    private int $currentPage;

    /** @var iterable<array-key, mixed>|null */
    private $slice;

    /** @var positive-int|null */
    private ?int $maxNbPages = null;

    private bool $allowOutOfRangePages = false;

    private bool $normalizeOutOfRangePages = false;

    /** @var iterable<array-key, mixed>|null */
    private $currentPageResults;

    /**
     * @noinspection MagicMethodsValidityInspection
     * @noinspection PhpMissingParentConstructorInspection
     */
    public function __construct(
        private readonly AsyncPagerAdapterInterface $adapter,
        int $maxPerPage = 10,
        int $currentPage = 1,
    ) {
        $this->checkMaxPerPage($maxPerPage);
        $this->checkCurrentPage($currentPage);
        $this->maxPerPage = $maxPerPage;
        $this->currentPage = $currentPage;
    }

    /**
     * @return Promise<void>
     */
    public function init(): Promise
    {
        return call(function () {
            $nbResults = yield $this->adapter->getNbResults();
            Assert::natural($nbResults);
            $this->nbResults = $nbResults;
            $offset = $this->calculateOffsetForCurrentPageResults();
            $length = $this->getMaxPerPage();
            $slice = yield $this->adapter->getSlice($offset, $length);
            Assert::isIterable($slice);
            $this->slice = $slice;
        });
    }

    /**
     * @return AdapterInterface<mixed>
     */
    public function getAdapter(): AdapterInterface
    {
        return new FixedAdapter($this->getNbResults(), $this->getCurrentPageResults());
    }

    /**
     * Sets whether or not allow out of range pages.
     */
    public function setAllowOutOfRangePages(bool $allowOutOfRangePages): static
    {
        $this->allowOutOfRangePages = $this->filterBoolean($allowOutOfRangePages);

        return $this;
    }

    /**
     * Returns whether or not allow out of range pages.
     */
    public function getAllowOutOfRangePages(): bool
    {
        return $this->allowOutOfRangePages;
    }

    /**
     * Sets whether or not normalize out of range pages.
     */
    public function setNormalizeOutOfRangePages(bool $normalizeOutOfRangePages): static
    {
        $this->normalizeOutOfRangePages = $this->filterBoolean($normalizeOutOfRangePages);

        return $this;
    }

    /**
     * Returns whether or not normalize out of range pages.
     */
    public function getNormalizeOutOfRangePages(): bool
    {
        return $this->normalizeOutOfRangePages;
    }

    /**
     * @param mixed $value
     */
    private function filterBoolean($value): bool
    {
        Assert::boolean($value);

        return $value;
    }

    /**
     * Sets the max per page.
     *
     * @throws LessThan1MaxPerPageException  If the max per page is less than 1.
     */
    public function setMaxPerPage(int $maxPerPage): static
    {
        $this->maxPerPage = $this->filterMaxPerPage($maxPerPage);
        $this->resetForMaxPerPageChange();

        return $this;
    }

    /**
     * @return positive-int
     */
    private function filterMaxPerPage(int $maxPerPage): int
    {
        $this->checkMaxPerPage($maxPerPage);

        return $maxPerPage;
    }

    /**
     * @phpstan-assert positive-int $maxPerPage
     */
    private function checkMaxPerPage(int $maxPerPage): void
    {
        if ($maxPerPage < 1) {
            throw new LessThan1MaxPerPageException();
        }
    }

    private function resetForMaxPerPageChange(): void
    {
        $this->currentPageResults = null;
        $this->nbResults = null;
    }

    /**
     * Returns the max per page.
     */
    public function getMaxPerPage(): int
    {
        return $this->maxPerPage;
    }

    /**
     * Sets the current page.
     *
     * @throws LessThan1CurrentPageException  If the current page is less than 1.
     * @throws OutOfRangeCurrentPageException If It is not allowed out of range pages and they are not normalized.
     */
    public function setCurrentPage(int $currentPage): static
    {
        $this->currentPage = $this->filterCurrentPage($currentPage);
        $this->resetForCurrentPageChange();

        return $this;
    }

    /**
     * @return positive-int
     */
    private function filterCurrentPage(int $currentPage): int
    {
        $this->checkCurrentPage($currentPage);

        return $this->filterOutOfRangeCurrentPage($currentPage);
    }

    /**
     * @phpstan-assert positive-int $currentPage
     */
    private function checkCurrentPage(int $currentPage): void
    {
        if ($currentPage < 1) {
            throw new LessThan1CurrentPageException();
        }
    }

    /**
     * @param positive-int $currentPage
     * @return positive-int
     */
    private function filterOutOfRangeCurrentPage(int $currentPage): int
    {
        if ($this->notAllowedCurrentPageOutOfRange($currentPage)) {
            return $this->normalizeOutOfRangeCurrentPage($currentPage);
        }

        return $currentPage;
    }

    private function notAllowedCurrentPageOutOfRange(int $currentPage): bool
    {
        return !$this->getAllowOutOfRangePages() &&
            $this->currentPageOutOfRange($currentPage);
    }

    private function currentPageOutOfRange(int $currentPage): bool
    {
        return $currentPage > 1 && $currentPage > $this->getNbPages();
    }

    /**
     * @return positive-int
     * @throws OutOfRangeCurrentPageException If the page should not be normalized
     */
    private function normalizeOutOfRangeCurrentPage(int $currentPage): int
    {
        if ($this->getNormalizeOutOfRangePages()) {
            return $this->getNbPages();
        }

        throw new OutOfRangeCurrentPageException(
            sprintf(
                'Page "%d" does not exist. The currentPage must be inferior to "%d"',
                $currentPage,
                $this->getNbPages()
            )
        );
    }

    private function resetForCurrentPageChange(): void
    {
        $this->currentPageResults = null;
    }

    /**
     * {@inheritDoc}
     */
    public function getCurrentPage(): int
    {
        return $this->currentPage;
    }

    /**
     * Returns the results for the current page.
     *
     * @return iterable<array-key, mixed>
     */
    public function getCurrentPageResults(): iterable
    {
        if ($this->notCachedCurrentPageResults()) {
            $this->currentPageResults = $this->getCurrentPageResultsFromAdapter();
        }

        return $this->currentPageResults ?? [];
    }

    private function notCachedCurrentPageResults(): bool
    {
        return $this->currentPageResults === null;
    }

    /**
     * @return iterable<array-key, mixed>|null
     */
    private function getCurrentPageResultsFromAdapter()
    {
        return $this->slice;
    }

    /**
     * @return int<0, max>
     */
    private function calculateOffsetForCurrentPageResults(): int
    {
        return ($this->getCurrentPage() - 1) * $this->getMaxPerPage();
    }

    /**
     * {@inheritDoc}
     */
    public function getCurrentPageOffsetStart(): int
    {
        return $this->getNbResults() ?
            $this->calculateOffsetForCurrentPageResults() + 1 :
            0;
    }

    /**
     * {@inheritDoc}
     */
    public function getCurrentPageOffsetEnd(): int
    {
        return $this->hasNextPage() ?
            $this->getCurrentPage() * $this->getMaxPerPage() :
            $this->getNbResults();
    }

    /**
     * @return int<0, max>
     */
    public function getNbResults(): int
    {
        return $this->nbResults ?? 0;
    }

    /**
     * Returns the number of pages.
     */
    public function getNbPages(): int
    {
        $nbPages = $this->calculateNbPages();

        if (0 === $nbPages) {
            return $this->minimumNbPages();
        }

        if (null !== $this->maxNbPages && $this->maxNbPages < $nbPages) {
            return $this->maxNbPages;
        }

        return $nbPages;
    }

    public function setMaxNbPages(int $maxNbPages): static
    {
        if ($maxNbPages < 1) {
            throw new LessThan1MaxPagesException();
        }

        $this->maxNbPages = $maxNbPages;

        return $this;
    }

    public function resetMaxNbPages(): static
    {
        $this->maxNbPages = null;

        return $this;
    }

    /**
     * @return int<0, max>
     */
    private function calculateNbPages(): int
    {
        $nbPages = (int) ceil($this->getNbResults() / $this->getMaxPerPage());
        \assert($nbPages >= 0);

        return $nbPages;
    }

    /**
     * @return positive-int
     */
    private function minimumNbPages(): int
    {
        return 1;
    }

    /**
     * {@inheritDoc}
     */
    public function haveToPaginate(): bool
    {
        return $this->getNbResults() > $this->maxPerPage;
    }

    /**
     * {@inheritDoc}
     */
    public function hasPreviousPage(): bool
    {
        return $this->currentPage > 1;
    }

    /**
     * Returns the previous page.
     *
     * @throws \LogicException If there is no previous page.
     */
    public function getPreviousPage(): int
    {
        if (!$this->hasPreviousPage()) {
            throw new \LogicException('There is no previous page.');
        }

        \assert($this->currentPage > 1);

        return $this->currentPage - 1;
    }

    /**
     * Returns whether there is next page or not.
     */
    public function hasNextPage(): bool
    {
        return $this->currentPage < $this->getNbPages();
    }

    /**
     * Returns the next page.
     *
     * @throws \LogicException If there is no next page.
     */
    public function getNextPage(): int
    {
        if (!$this->hasNextPage()) {
            throw new \LogicException('There is no next page.');
        }

        return $this->currentPage + 1;
    }

    public function count(): int
    {
        return $this->getNbResults();
    }

    /**
     * @return \Traversable<mixed>
     */
    public function getIterator(): \Traversable
    {
        $results = $this->getCurrentPageResults();

        if ($results instanceof \Iterator) {
            return $results;
        }

        if ($results instanceof \IteratorAggregate) {
            return $results->getIterator();
        }

        if (!is_array($results)) {
            throw new \RuntimeException('Invalid current page results.');
        }

        return new \ArrayIterator($results);
    }

    /**
     * @return array<mixed>
     */
    public function jsonSerialize(): array
    {
        $results = $this->getCurrentPageResults();
        if ($results instanceof \Traversable) {
            return iterator_to_array($results);
        }

        return $results;
    }

    /**
     * Get page number of the item at specified position (1-based index)
     */
    public function getPageNumberForItemAtPosition(int $position): int
    {
        if ($this->getNbResults() < $position) {
            throw new \OutOfBoundsException(sprintf(
                'Item requested at position %d, but there are only %d items.',
                $position,
                $this->getNbResults()
            ));
        }

        $pageNumber = (int) ceil($position / $this->getMaxPerPage());
        \assert($pageNumber > 0);

        return $pageNumber;
    }
}
