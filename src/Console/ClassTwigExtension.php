<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Console;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class ClassTwigExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('class', [$this, 'getClass'])
        ];
    }

    /**
     * @param mixed $object
     * @return string
     */
    public function getClass($object): string
    {
        if (!is_object($object)) {
            throw new \InvalidArgumentException(
                sprintf('Cannot get class for an argument of type "%s".', gettype($object))
            );
        }

        return get_class($object);
    }
}
