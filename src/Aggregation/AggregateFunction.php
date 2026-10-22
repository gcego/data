<?php

declare(strict_types=1);

namespace Gcego\Data\Aggregation;

enum AggregateFunction: string
{
    case Count = 'count';
    case Sum = 'sum';
    case Average = 'avg';
    case Minimum = 'min';
    case Maximum = 'max';
}
