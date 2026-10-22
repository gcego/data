<?php

declare(strict_types=1);

namespace Gcego\Data\Tests\Fixtures\Sources;

use Gcego\Data\Contracts\SourceProvider;
use Gcego\Data\Definitions\Constraints;
use Gcego\Data\Definitions\Model;
use Gcego\Data\Definitions\Name;
use Gcego\Data\Definitions\Sort;
use Gcego\Data\Filters\Filter;
use Gcego\Data\Source;
use Gcego\Data\Tests\Fixtures\Models\Subject;
use Gcego\Data\Tests\Fixtures\Tenancy;

/**
 * A class-based source with an injected dependency.
 */
final readonly class PublishedSubjects implements SourceProvider
{
    public function __construct(private Tenancy $tenancy) {}

    public function source(): Source
    {
        return Source::define(
            Name::make('published-subjects'),
            Model::make(Subject::class),
            Constraints::make([Filter::make('is_published', true), Filter::make('institution_id', $this->tenancy->institution ?? 1)]),
            Sort::make('name'),
        );
    }
}
