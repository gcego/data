<?php

declare(strict_types=1);

namespace Gcego\Data\Security;

use Gcego\Data\Compilation\SourceDefinition;
use Gcego\Data\Contracts\DataAuthorizer;
use Gcego\Data\DataConfig;
use Gcego\Data\Query\QueryContext;
use Illuminate\Contracts\Auth\Access\Gate;

/**
 * The default authorizer, built on Laravel's Gate. It fails closed.
 *
 * - Eloquent sources use the model's policy: viewAny(user) to list,
 *   view(user, record) for one record, create(user) to create, and
 *   update / delete / restore / forceDelete(user, record) to write. A model
 *   without a policy (or an explicit ability of that name) is denied.
 * - Query-builder and collection sources check the configured ability
 *   (gcego-data.query) for every operation:
 *   Gate::define('gcego-data.query', fn (?User $user, string $operation, string $source, mixed $record = null) => ...).
 *
 * The user is QueryContext::$user, or the Gate's current user.
 */
final readonly class GateDataAuthorizer implements DataAuthorizer
{
    public function __construct(private Gate $gate, private DataConfig $config) {}

    public function authorize(SourceDefinition $source, DataOperation $operation, QueryContext $context, mixed $record = null): void
    {
        $gate = $context->user === null ? $this->gate : $this->gate->forUser($context->user);

        if ($source->model !== null) {
            $gate->authorize($operation->value, $operation->isRecordLevel() && is_object($record) ? $record : $source->model);

            return;
        }

        $gate->authorize($this->config->ability, [$operation->value, $source->name, $record]);
    }
}
