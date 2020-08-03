<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Console\Pager;

use Amp\Promise;
use Pagerfanta\Exception\LessThan1CurrentPageException;
use Pagerfanta\Exception\LessThan1MaxPerPageException;
use Pagerfanta\Exception\NotValidCurrentPageException;
use Pagerfanta\Exception\NotValidMaxPerPageException;
use Pagerfanta\Exception\OutOfRangeCurrentPageException;
use Pagerfanta\PagerfantaInterface;
use Webmozart\Assert\Assert;
use function Amp\call;

/**
 * @internal
 */
class AsyncPager implements PagerfantaInterface
{
    /**
     * @var AsyncPagerAdapterInterface
     */
    private $adapter;
    /**
     * @var int|null
     */
    private $nbResults;
    /**
     * @var iterable<mixed>|null
     */
    private $slice;
    /**
     * @var bool
     */
    private $allowOutOfRangePages;
    /**
     * @var bool
     */
    private $normalizeOutOfRangePages;
    /**
     * @var int
     */
    private $maxPerPage;
    /**
     * @var int
     */
    private $currentPage;
    /**
     * @var iterable<mixed>|null
     */
    private $currentPageResults;

    /**
     * @noinspection MagicMethodsValidityInspection
     * @noinspection PhpMissingParentConstructorInspection
     */
    public function __construct(AsyncPagerAdapterInterface $adapter, int $maxPerPage = 10, int $currentPage = 1)
    {
        $this->adapter = $adapter;
        $this->allowOutOfRangePages = false;
        $this->normalizeOutOfRangePages = false;
        $this->maxPerPage = $maxPerPage;
        $this->currentPage = $currentPage;
    }

    /**
     * @return Promise<void>
     */
    public function init(): Promise
    {
        return call(function () {
            $this->nbResults = yield $this->adapter->getNbResults();
            $offset = $this->calculateOffsetForCurrentPageResults();
            $length = $this->getMaxPerPage();
            $this->slice = yield $this->adapter->getSlice($offset, $length);
        });
    }

    /**
     * Returns the adapter.
     *
     * @return AsyncPagerAdapterInterface The adapter.
     */
    public function getAdapter()
    {
        return $this->adapter;
    }

    /**
     * Sets whether or not allow out of range pages.
     *
     * @param boolean $value
     *
     * @return self
     */
    public function setAllowOutOfRangePages($value)
    {
        $this->allowOutOfRangePages = $this->filterBoolean($value);

        return $this;
    }

    /**
     * Returns whether or not allow out of range pages.
     *
     * @return boolean
     */
    public function getAllowOutOfRangePages(): bool
    {
        return $this->allowOutOfRangePages;
    }

    /**
     * Sets whether or not normalize out of range pages.
     *
     * @param boolean $value
     *
     * @return self
     */
    public function setNormalizeOutOfRangePages($value)
    {
        $this->normalizeOutOfRangePages = $this->filterBoolean($value);

        return $this;
    }

    /**
     * Returns whether or not normalize out of range pages.
     *
     * @return boolean
     */
    public function getNormalizeOutOfRangePages()
    {
        return $this->normalizeOutOfRangePages;
    }

    /**
     * @param mixed $value
     * @return bool
     */
    private function filterBoolean($value): bool
    {
        Assert::boolean($value);

        return $value;
    }

    /**
     * Sets the max per page.
     *
     * Tries to convert from string and float.
     *
     * @param integer $maxPerPage
     *
     * @return self
     *
     * @throws NotValidMaxPerPageException If the max per page is not an integer even converting.
     * @throws LessThan1MaxPerPageException  If the max per page is less than 1.
     */
    public function setMaxPerPage($maxPerPage)
    {
        $this->maxPerPage = $this->filterMaxPerPage($maxPerPage);
        $this->resetForMaxPerPageChange();

        return $this;
    }

    /**
     * @param mixed $maxPerPage
     * @return int
     */
    private function filterMaxPerPage($maxPerPage): int
    {
        $maxPerPage = $this->toInteger($maxPerPage);
        $this->checkMaxPerPage($maxPerPage);

        return $maxPerPage;
    }

    /**
     * @param mixed $maxPerPage
     */
    private function checkMaxPerPage($maxPerPage): void
    {
        if (!is_int($maxPerPage)) {
            throw new NotValidMaxPerPageException();
        }

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
     *
     * @return integer
     */
    public function getMaxPerPage()
    {
        return $this->maxPerPage;
    }

    /**
     * Sets the current page.
     *
     * Tries to convert from string and float.
     *
     * @param integer $currentPage
     *
     * @return self
     *
     * @throws NotValidCurrentPageException If the current page is not an integer even converting.
     * @throws LessThan1CurrentPageException  If the current page is less than 1.
     * @throws OutOfRangeCurrentPageException If It is not allowed out of range pages and they are not normalized.
     */
    public function setCurrentPage($currentPage)
    {
        $this->useDeprecatedCurrentPageBooleanArguments(func_get_args());

        $this->currentPage = $this->filterCurrentPage($currentPage);
        $this->resetForCurrentPageChange();

        return $this;
    }

    /**
     * @param array<bool> $arguments
     */
    private function useDeprecatedCurrentPageBooleanArguments($arguments): void
    {
        $this->useDeprecatedCurrentPageAllowOutOfRangePagesBooleanArgument($arguments);
        $this->useDeprecatedCurrentPageNormalizeOutOfRangePagesBooleanArgument($arguments);
    }

    /**
     * @param array<bool> $arguments
     */
    private function useDeprecatedCurrentPageAllowOutOfRangePagesBooleanArgument($arguments): void
    {
        $index = 1;
        $method = 'setAllowOutOfRangePages';

        $this->useDeprecatedBooleanArgument($arguments, $index, $method);
    }

    /**
     * @param array<bool> $arguments
     */
    private function useDeprecatedCurrentPageNormalizeOutOfRangePagesBooleanArgument($arguments): void
    {
        $index = 2;
        $method = 'setNormalizeOutOfRangePages';

        $this->useDeprecatedBooleanArgument($arguments, $index, $method);
    }

    /**
     * @param array<bool> $arguments
     * @param int $index
     * @param string $method
     */
    private function useDeprecatedBooleanArgument($arguments, $index, $method): void
    {
        if (isset($arguments[$index])) {
            $this->$method($arguments[$index]);
        }
    }

    /**
     * @param mixed $currentPage
     * @return int
     */
    private function filterCurrentPage($currentPage): int
    {
        $currentPage = $this->toInteger($currentPage);
        $this->checkCurrentPage($currentPage);
        $currentPage = $this->filterOutOfRangeCurrentPage($currentPage);

        return $currentPage;
    }

    /**
     * @param mixed $currentPage
     */
    private function checkCurrentPage($currentPage): void
    {
        if (!is_int($currentPage)) {
            throw new NotValidCurrentPageException();
        }

        if ($currentPage < 1) {
            throw new LessThan1CurrentPageException();
        }
    }

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
     * @param int $currentPage
     *
     * @return int
     *
     * @throws OutOfRangeCurrentPageException If the page should not be normalized
     */
    private function normalizeOutOfRangeCurrentPage($currentPage): int
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
     * @return iterable<mixed>|null
     */
    public function getCurrentPageResults()
    {
        if ($this->notCachedCurrentPageResults()) {
            $this->currentPageResults = $this->getCurrentPageResultsFromAdapter();
        }

        return $this->currentPageResults;
    }

    private function notCachedCurrentPageResults(): bool
    {
        return $this->currentPageResults === null;
    }

    /**
     * @return iterable<mixed>|null
     */
    private function getCurrentPageResultsFromAdapter()
    {
        return $this->slice;
    }

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
    public function getCurrentPageOffsetEnd(): ?int
    {
        return $this->hasNextPage() ?
            $this->getCurrentPage() * $this->getMaxPerPage() :
            $this->getNbResults();
    }

    /**
     * {@inheritDoc}
     */
    public function getNbResults(): ?int
    {
        return $this->nbResults;
    }

    /**
     * Returns the number of pages.
     *
     * @return integer
     */
    public function getNbPages()
    {
        $nbPages = $this->calculateNbPages();

        if ($nbPages == 0) {
            return $this->minimumNbPages();
        }

        return $nbPages;
    }

    private function calculateNbPages(): int
    {
        return (int) ceil($this->getNbResults() / $this->getMaxPerPage());
    }

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
     * @return integer
     *
     * @throws \LogicException If there is no previous page.
     */
    public function getPreviousPage()
    {
        if (!$this->hasPreviousPage()) {
            throw new \LogicException('There is no previous page.');
        }

        return $this->currentPage - 1;
    }

    /**
     * Returns whether there is next page or not.
     *
     * @return boolean
     */
    public function hasNextPage()
    {
        return $this->currentPage < $this->getNbPages();
    }

    /**
     * Returns the next page.
     *
     * @return integer
     *
     * @throws \LogicException If there is no next page.
     */
    public function getNextPage()
    {
        if (!$this->hasNextPage()) {
            throw new \LogicException('There is no next page.');
        }

        return $this->currentPage + 1;
    }

    public function count(): int
    {
        return $this->getNbResults() ?? 0;
    }

    /**
     * @return \Traversable<mixed>
     */
    public function getIterator()
    {
        $results = $this->getCurrentPageResults();

        if ($results instanceof \Iterator) {
            return $results;
        }

        if ($results instanceof \IteratorAggregate) {
            return $results->getIterator();
        }

        if (null === $results) {
            return new \ArrayIterator([]);
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

        if (null === $results) {
            return [];
        }

        if (!is_array($results)) {
            throw new \RuntimeException('Invalid current page results.');
        }

        return $results;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function toInteger($value)
    {
        if ($this->needsToIntegerConversion($value)) {
            return (int) $value;
        }

        return $value;
    }

    /**
     * @param mixed $value
     * @return bool
     */
    private function needsToIntegerConversion($value): bool
    {
        return (is_string($value) || is_float($value)) && (int) $value == $value;
    }

    /**
     * Get page number of the item at specified position (1-based index)
     *
     * @param integer $position
     *
     * @return integer
     */
    public function getPageNumberForItemAtPosition($position)
    {
        Assert::integer($position);

        if ($this->getNbResults() < $position) {
            throw new \OutOfBoundsException(sprintf(
                'Item requested at position %d, but there are only %d items.',
                $position,
                $this->getNbResults()
            ));
        }

        return (int) ceil($position/$this->getMaxPerPage());
    }
}
