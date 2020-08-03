<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Console;

use Pagerfanta\PagerfantaInterface;
use Pagerfanta\View\ViewInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class PagerfantaTwigExtension extends AbstractExtension
{
    /**
     * @var ViewInterface
     */
    private $view;

    public function __construct(ViewInterface $view)
    {
        $this->view = $view;
    }

    public function getFunctions()
    {
        return [
            new TwigFunction('pagerfanta', [$this, 'renderPagerfanta'], ['is_safe' => ['html']])
        ];
    }

    /**
     * @param PagerfantaInterface $pagerfanta
     * @param string $route
     * @param array<string, string|int> $options
     * @return string
     */
    public function renderPagerfanta(
        PagerfantaInterface $pagerfanta,
        string $route,
        array $options = []
    ): string {
        $routeGenerator = function ($page) use ($route): string {
            return strtr($route, ['{page}' => $page]);
        };

        return $this->view->render($pagerfanta, $routeGenerator, $options);
    }
}
