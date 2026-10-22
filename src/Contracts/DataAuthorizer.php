<?php

declare(strict_types=1);

namespace Gcego\Data\Contracts;

use Gcego\Data\Compilation\SourceDefinition;
use Gcego\Data\Query\QueryContext;
use Gcego\Data\Security\DataOperation;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Decides whether a read or write may happen. Bound to GateDataAuthorizer by default;
 * gcego/security will bind its own. Implementations must fail closed.
 */
interface DataAuthorizer
{
    /**
     * @param  mixed  $record  The record for record-level operations (view, update, delete, restore, forceDelete), otherwise null.
     *
     * @throws AuthorizationException
     */
    public function authorize(SourceDefinition $source, DataOperation $operation, QueryContext $context, mixed $record = null): void;
}
