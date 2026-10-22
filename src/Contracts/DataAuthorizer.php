<?php

declare(strict_types=1);

namespace Gcego\Data\Contracts;

use Gcego\Data\Definition\SourceDefinition;
use Gcego\Data\Query\QueryContext;
use Gcego\Data\Security\DataOperation;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Decides whether a read may happen. Bound to GateDataAuthorizer by default;
 * gcego/security will bind its own. Implementations must fail closed.
 */
interface DataAuthorizer
{
    /**
     * @param  mixed  $record  The resolved record for DataOperation::View, otherwise null.
     *
     * @throws AuthorizationException
     */
    public function authorize(SourceDefinition $source, DataOperation $operation, QueryContext $context, mixed $record = null): void;
}
