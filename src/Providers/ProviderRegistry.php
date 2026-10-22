<?php

declare(strict_types=1);

namespace Gcego\Data\Providers;

use Gcego\Data\Compilation\SourceDefinition;
use Gcego\Data\Contracts\DataProvider;
use Gcego\Data\Exceptions\UnsupportedProviderException;
use Illuminate\Contracts\Container\Container;

/**
 * Named data providers. Built in: "eloquent", "query" and "collection".
 *
 * Packages add providers in their service provider:
 *
 *     $this->app->make(ProviderRegistry::class)->register('reports', ReportProvider::class);
 *
 * Class names are resolved from the container once, on first use.
 */
final class ProviderRegistry
{
    /**
     * @var array<string, DataProvider|class-string<DataProvider>>
     */
    private array $providers = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  DataProvider|class-string<DataProvider>  $provider
     *
     * @throws UnsupportedProviderException
     */
    public function register(string $name, DataProvider|string $provider, bool $override = false): void
    {
        if (isset($this->providers[$name]) && ! $override) {
            throw UnsupportedProviderException::conflict($name);
        }

        $this->providers[$name] = $provider;
    }

    public function has(string $name): bool
    {
        return isset($this->providers[$name]);
    }

    /**
     * @throws UnsupportedProviderException
     */
    public function get(string $name): DataProvider
    {
        $provider = $this->providers[$name] ?? throw UnsupportedProviderException::unknown($name, $this->names());

        if (is_string($provider)) {
            $instance = $this->container->make($provider);
            $provider = $this->providers[$name] = $instance instanceof DataProvider ? $instance : throw UnsupportedProviderException::unsupported($name, 'the DataProvider contract');
        }

        return $provider;
    }

    /**
     * The provider of a definition, after it validated the definition.
     */
    public function for(SourceDefinition $source): DataProvider
    {
        $provider = $this->get($source->provider);
        $provider->prepare($source);

        return $provider;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->providers);
    }
}
