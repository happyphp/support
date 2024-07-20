<?php

declare(strict_types=1);

namespace Haphp\Support;

use Closure;
//use Haphp\Console\Application as Artisan;
use Haphp\Contracts\Container\BindingResolutionException;
use Haphp\Contracts\Foundation\ApplicationInterface;
use Haphp\Contracts\Foundation\CachesConfigurationInterface;
use Haphp\Contracts\Foundation\CachesRoutesInterface;
use Haphp\Contracts\Support\DeferrableProviderInterface;

//use Haphp\View\Compilers\BladeCompiler;

abstract class AbstractServiceProvider
{
    /**
     * The paths that should be published.
     */
    public static array $publishes = [];

    /**
     * The paths that should be published by group.
     */
    public static array $publishGroups = [];

    /**
     * The application instance.
     */
    protected ApplicationInterface $app;

    /**
     * All the registered booting callbacks.
     */
    protected array $bootingCallbacks = [];

    /**
     * All the registered booted callbacks.
     */
    protected array $bootedCallbacks = [];

    /**
     * Create a new service provider instance.
     */
    public function __construct(ApplicationInterface $app)
    {
        $this->app = $app;
    }

    /**
     * Get the paths to publish.
     */
    public static function pathsToPublish(?string $provider = null, ?string $group = null): array
    {
        if (null !== ($paths = static::pathsForProviderOrGroup($provider, $group))) {
            return $paths;
        }

        return collect(static::$publishes)->reduce(fn ($paths, $p) => array_merge($paths, $p), []);
    }

    /**
     * Get the service providers available for publishing.
     */
    public static function publishableProviders(): array
    {
        return array_keys(static::$publishes);
    }

    /**
     * Get the groups available for publishing.
     */
    public static function publishableGroups(): array
    {
        return array_keys(static::$publishGroups);
    }

    /**
     * Get the default providers for a Happy application.
     */
    public static function defaultProviders(): DefaultProviders
    {
        return new DefaultProviders();
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {

    }

    /**
     * Register a booting callback to be run before the "boot" method is called.
     */
    public function booting(Closure $callback): void
    {
        $this->bootingCallbacks[] = $callback;
    }

    /**
     * Register a booted callback to be run after the "boot" method is called.
     */
    public function booted(Closure $callback): void
    {
        $this->bootedCallbacks[] = $callback;
    }

    /**
     * Call the registered booting callbacks.
     */
    public function callBootingCallbacks(): void
    {
        $index = 0;

        while ($index < count($this->bootingCallbacks)) {
            $this->app->call($this->bootingCallbacks[$index]);

            $index++;
        }
    }

    /**
     * Call the registered booted callbacks.
     */
    public function callBootedCallbacks(): void
    {
        $index = 0;

        while ($index < count($this->bootedCallbacks)) {
            $this->app->call($this->bootedCallbacks[$index]);

            $index++;
        }
    }

    /**
     * Register the package's custom Artisan commands.
     */
    public function commands(mixed $commands): void
    {
        $commands = is_array($commands) ? $commands : func_get_args();

        //Artisan::starting(function ($artisan) use ($commands): void {
        //    $artisan->resolveCommands($commands);
        //});
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [];
    }

    /**
     * Get the events that trigger this service provider to register.
     */
    public function when(): array
    {
        return [];
    }

    /**
     * Determine if the provider is deferred.
     */
    public function isDeferred(): bool
    {
        return $this instanceof DeferrableProviderInterface;
    }

    /**
     * Get the paths for the provider or group (or both).
     */
    protected static function pathsForProviderOrGroup(?string $provider, ?string $group): array
    {
        if ($provider && $group) {
            return static::pathsForProviderAndGroup($provider, $group);
        }

        if ($group && array_key_exists($group, static::$publishGroups)) {
            return static::$publishGroups[$group];
        }

        if ($provider && array_key_exists($provider, static::$publishes)) {
            return static::$publishes[$provider];
        }

        return [];
    }

    /**
     * Get the paths for the provider and group.
     */
    protected static function pathsForProviderAndGroup(string $provider, string $group): array
    {
        if (! empty(static::$publishes[$provider]) && ! empty(static::$publishGroups[$group])) {
            return array_intersect_key(static::$publishes[$provider], static::$publishGroups[$group]);
        }

        return [];
    }

    /**
     * Merge the given configuration with the existing configuration.
     *
     * @throws BindingResolutionException
     */
    protected function mergeConfigFrom(string $path, string $key): void
    {
        if (! ($this->app instanceof CachesConfigurationInterface && $this->app->configurationIsCached())) {
            $config = $this->app->make('config');

            $config->set($key, array_merge(
                require $path,
                $config->get($key, [])
            ));
        }
    }

    /**
     * Load the given routes file if routes are not already cached.
     */
    protected function loadRoutesFrom(string $path): void
    {
        if (! ($this->app instanceof CachesRoutesInterface && $this->app->routesAreCached())) {
            require $path;
        }
    }

    /**
     * Register a view file namespace.
     */
    protected function loadViewsFrom(array|string $path, string $namespace): void
    {
        $this->callAfterResolving('view', function ($view) use ($path, $namespace): void {
            if (isset($this->app->config['view']['paths']) &&
                is_array($this->app->config['view']['paths'])) {
                foreach ($this->app->config['view']['paths'] as $viewPath) {
                    if (is_dir($appPath = $viewPath.'/vendor/'.$namespace)) {
                        $view->addNamespace($namespace, $appPath);
                    }
                }
            }

            $view->addNamespace($namespace, $path);
        });
    }

    /**
     * Register the given view components with a custom prefix.
     *
     * @param  string  $prefix
     */
    protected function loadViewComponentsAs($prefix, array $components): void
    {
        //$this->callAfterResolving(BladeCompiler::class, function ($blade) use ($prefix, $components): void {
        //    foreach ($components as $alias => $component) {
        //        $blade->component($component, is_string($alias) ? $alias : null, $prefix);
        //    }
        //});
    }

    /**
     * Register a translation file namespace.
     */
    protected function loadTranslationsFrom(string $path, string $namespace): void
    {
        $this->callAfterResolving('translator', function ($translator) use ($path, $namespace): void {
            $translator->addNamespace($namespace, $path);
        });
    }

    /**
     * Register a JSON translation file path.
     */
    protected function loadJsonTranslationsFrom(string $path): void
    {
        $this->callAfterResolving('translator', function ($translator) use ($path): void {
            $translator->addJsonPath($path);
        });
    }

    /**
     * Register database migration paths.
     */
    protected function loadMigrationsFrom(array|string $paths): void
    {
        $this->callAfterResolving('migrator', function ($migrator) use ($paths): void {
            foreach ((array) $paths as $path) {
                $migrator->path($path);
            }
        });
    }

    /**
     * Register Eloquent model factory paths.
     *
     *@deprecated Will be removed in a future Laravel version.
     */
    protected function loadFactoriesFrom(array|string $paths): void
    {
        //$this->callAfterResolving(ModelFactory::class, function ($factory) use ($paths): void {
        //    foreach ((array) $paths as $path) {
        //        $factory->load($path);
        //    }
        //});
    }

    /**
     * Set an after resolving listener, or fire immediately if already resolved.
     */
    protected function callAfterResolving(string $name, callable $callback): void
    {
        $this->app->afterResolving($name, $callback);

        if ($this->app->resolved($name)) {
            $callback($this->app->make($name), $this->app);
        }
    }

    /**
     * Register paths to be published by the publish command.
     */
    protected function publishes(array $paths, mixed $groups = null): void
    {
        $this->ensurePublishArrayInitialized($class = static::class);

        static::$publishes[$class] = array_merge(static::$publishes[$class], $paths);

        foreach ((array) $groups as $group) {
            $this->addPublishGroup($group, $paths);
        }
    }

    /**
     * Ensure the publish array for the service provider is initialized.
     */
    protected function ensurePublishArrayInitialized(string $class): void
    {
        if (! array_key_exists($class, static::$publishes)) {
            static::$publishes[$class] = [];
        }
    }

    /**
     * Add a publish group / tag to the service provider.
     */
    protected function addPublishGroup(string $group, array $paths): void
    {
        if (! array_key_exists($group, static::$publishGroups)) {
            static::$publishGroups[$group] = [];
        }

        static::$publishGroups[$group] = array_merge(
            static::$publishGroups[$group],
            $paths
        );
    }
}
