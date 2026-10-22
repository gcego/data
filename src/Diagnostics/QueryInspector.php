<?php

declare(strict_types=1);

namespace Gcego\Data\Diagnostics;

use Gcego\Data\Contracts\DataProvider;
use Gcego\Data\Contracts\InspectsQueries;
use Gcego\Data\Contracts\ResolvesRecords;
use Gcego\Data\Contracts\SupportsCursorPagination;
use Gcego\Data\Contracts\SupportsRelationships;
use Gcego\Data\DataConfig;
use Gcego\Data\Definition\SourceCompiler;
use Gcego\Data\Definition\SourceDefinition;
use Gcego\Data\Exceptions\UnsupportedProviderException;
use Gcego\Data\Providers\ProviderRegistry;
use Gcego\Data\Query\QueryContext;
use Gcego\Data\Query\QueryInput;
use Gcego\Data\Query\QueryPipeline;
use Gcego\Data\Query\QueryResolver;
use Gcego\Data\Source;
use Illuminate\Contracts\Auth\Access\Gate;
use Throwable;

/**
 * Explains a source without reading data: its compiled configuration,
 * provider capabilities, definition errors and authorization setup, and,
 * through explain(), the SQL a request would run.
 *
 * Bindings (which may hold personal data) are only returned when explicitly
 * requested and the application is in debug mode. Nothing is logged.
 */
final readonly class QueryInspector
{
    public function __construct(
        private SourceCompiler $compiler,
        private ProviderRegistry $providers,
        private QueryResolver $resolver,
        private QueryPipeline $pipeline,
        private DataConfig $config,
        private Gate $gate,
        private bool $debug = false,
    ) {}

    public function inspect(Source|SourceDefinition $source): DataReport
    {
        try {
            $definition = $source instanceof Source ? $this->compiler->compile($source) : $source;
        } catch (Throwable $exception) {
            return new DataReport([], [new DataDiagnostic('source', DataDiagnostic::Error, $exception->getMessage())]);
        }

        $diagnostics = [new DataDiagnostic('source', DataDiagnostic::Ok, sprintf('Source "%s" compiles.', $definition->name))];
        $capabilities = [];

        try {
            $provider = $this->providers->for($definition);
            $capabilities = self::capabilities($provider);
            $diagnostics[] = new DataDiagnostic('provider', DataDiagnostic::Ok, sprintf('Provider "%s" (%s) accepts the source.', $definition->provider, $provider::class), ['capabilities' => $capabilities]);
        } catch (Throwable $exception) {
            $diagnostics[] = new DataDiagnostic('provider', DataDiagnostic::Error, $exception->getMessage(), ['provider' => $definition->provider]);
        }

        $diagnostics[] = $this->authorization($definition);

        $perPage = $definition->pagination->perPage ?? $this->config->defaultPerPage;

        if ($perPage > $this->config->maxPerPage || ($definition->pagination->max ?? 0) > $this->config->maxPerPage) {
            $diagnostics[] = new DataDiagnostic('pagination', DataDiagnostic::Warning, sprintf('Page sizes above %d are capped by gcego-data.pagination.max_per_page.', $this->config->maxPerPage));
        } else {
            $diagnostics[] = new DataDiagnostic('pagination', DataDiagnostic::Ok, sprintf('%s pagination, %d per page.', ucfirst($definition->pagination->mode->value), $perPage));
        }

        if ($definition->sorts === [] && $definition->sortables === []) {
            $diagnostics[] = new DataDiagnostic('sorting', DataDiagnostic::Warning, 'No default ordering: records are ordered by key only.');
        }

        return new DataReport([...$definition->toArray(), 'capabilities' => $capabilities], $diagnostics);
    }

    /**
     * The SQL for a request, without executing it. Authorization is not checked; no data is read.
     *
     * @return array{sql: string, bindings: int, values?: list<mixed>}
     */
    public function explain(Source|SourceDefinition $source, ?QueryInput $input = null, ?QueryContext $context = null, bool $includeBindings = false): array
    {
        $definition = $source instanceof Source ? $this->compiler->compile($source) : $source;
        $provider = $this->providers->for($definition);

        if (! $provider instanceof InspectsQueries) {
            throw UnsupportedProviderException::unsupported($definition->provider, 'query inspection');
        }

        $explained = $provider->explain($definition, $this->resolver->resolve($definition, $input ?? QueryInput::none()), $this->pipeline->constraints($definition, $context ?? new QueryContext));
        $result = ['sql' => $explained['sql'], 'bindings' => count($explained['bindings'])];

        if ($includeBindings && $this->debug) {
            $result['values'] = $explained['bindings'];
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    public static function capabilities(DataProvider $provider): array
    {
        return array_values(array_filter([
            'fetch',
            $provider instanceof ResolvesRecords ? 'records' : null,
            $provider instanceof SupportsRelationships ? 'relationships' : null,
            $provider instanceof SupportsCursorPagination ? 'cursor-pagination' : null,
            $provider instanceof InspectsQueries ? 'explain' : null,
        ]));
    }

    private function authorization(SourceDefinition $definition): DataDiagnostic
    {
        if ($definition->model !== null) {
            return $this->gate->getPolicyFor($definition->model) !== null || $this->gate->has('viewAny')
                ? new DataDiagnostic('authorization', DataDiagnostic::Ok, sprintf('Reads are authorized by the %s policy (viewAny, view).', class_basename($definition->model)))
                : new DataDiagnostic('authorization', DataDiagnostic::Warning, sprintf('%s has no policy: every authorized read is denied. Register a policy with viewAny and view.', $definition->model));
        }

        return $this->gate->has($this->config->ability)
            ? new DataDiagnostic('authorization', DataDiagnostic::Ok, sprintf('Reads are authorized by the "%s" ability.', $this->config->ability))
            : new DataDiagnostic('authorization', DataDiagnostic::Warning, sprintf('The "%s" ability is not defined: every authorized read is denied.', $this->config->ability));
    }
}
