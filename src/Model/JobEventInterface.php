<?php

declare(strict_types=1);

namespace Webgriffe\Esb\Model;

use Symfony\Component\Serializer\Annotation\DiscriminatorMap;

#[DiscriminatorMap(typeProperty: 'type', mapping: [
    'produced' => ProducedJobEvent::class,
    'reserved' => ReservedJobEvent::class,
    'worked' => WorkedJobEvent::class,
    'errored' => ErroredJobEvent::class,
    'requeued' => RequeuedJobEvent::class,
])]
interface JobEventInterface
{
    public function getTime(): \DateTime;
}
