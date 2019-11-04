<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Model;

use Symfony\Component\Serializer\Annotation\DiscriminatorMap;

/**
 * @DiscriminatorMap(typeProperty="type", mapping={
 *    "produced"="Webgriffe\Esb\Model\ProducedJobEvent"
 * })
 */
interface JobEventInterface
{
    public function getTime(): \DateTime;
}
