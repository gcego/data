# Changelog

## Unreleased

### Breaking

- No longer depends on `gcego/components` or `gcego/runtime`. `Source` is a standalone immutable value object (`final class Source`), and `Gcego\Data\Property` replaces `Gcego\Components\Properties\Property`. `configurations()` returns a list; use `configuration(Filter::class)` and `hasConfiguration(Filter::class)`. `validate()` returns a list of messages. `Source::Name` replaces `Component::Name`; `named()` sets it.
- `SourceConfiguration` no longer extends a components contract; it declares `normalize(): array` itself. `Support\Callback` no longer implements `RuntimeValue`.
- Depends only on `gcego/core` ^1.0 (from Packagist). All `gcego/blueprints` and `gcego/registry` integration is removed (`BlueprintRole`/`BlueprintPart`, `DataManager::sourceOf()`), as is `Integration\SourceRenderer`: gcego/data is the domain-agnostic data engine other packages build on.

### Added

- `Data` facade (`Gcego\Data\Facades\Data`, auto-aliased) for `DataManager`.
- Scoped queries: `DataManager::scopedQuery(Source, $input, $context)` returns the rows a request may see as an unordered builder (view authorization, mandatory constraints and scopes such as tenancy, fixed and request filters, search), for trusted packages that aggregate on the database themselves (gcego/analytics). New `Contracts\ProvidesScopedQueries`, implemented by the Eloquent and query-builder providers.
- Aggregation: `DataManager::aggregate(Source, Aggregation, $input, $context)` computes count, sum, avg, min and max, optionally grouped by a column or a time bucket (day, week, month, year) over a date range, in the database for Eloquent and query-builder sources (in memory for collections). Same authorization, mandatory constraints, scopes and allowlisted filters as `query()`. New `Aggregation\{Aggregation, AggregateFunction, TimeBucket, AggregateResult}` and `Contracts\SupportsAggregation`.

### Changed

- The config file is only offered for publishing in the console.

## 0.1.0 - 2026-10-17

First release.

- `Source` component (the blueprint `source` part): model shorthand, Property syntax and configuration nodes.
- Providers: Eloquent, query builder (table or trusted factory) and in-memory collection, with capability interfaces.
- Fixed `Filter` conditions and allowlisted `Filterable` request filters with 11 operators and typed values.
- `Search` with an escaped, bound LIKE strategy (ILIKE on PostgreSQL) and pluggable `SearchStrategy`.
- `Sort` defaults, allowlisted `Sortable` keys and stable key tie-breaking.
- `Paginate`: page numbers, simple and cursor pagination, capped page sizes.
- `With` and allowlisted `Includable` eager loading, relation paths in filters and search.
- Scoped `RecordResolver` for integer, UUID, ULID and custom keys.
- Fail-closed `GateDataAuthorizer` (model policies), application `ScopeRegistry` and per-call `QueryContext` scopes.
- `QueryInspector` diagnostics and SQL explanation without binding values outside debug mode.
