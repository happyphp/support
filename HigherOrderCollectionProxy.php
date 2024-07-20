<?php

declare(strict_types=1);

namespace Haphp\Support;

use Haphp\Contracts\Support\EnumerableInterface;

/**
 * @mixin EnumerableInterface
 */
class HigherOrderCollectionProxy
{
    /**
     * Create a new proxy instance.
     *
     * @return void
     */
    public function __construct(
        protected EnumerableInterface $collection,
        protected string $method
    ) {
    }

    /**
     * Proxy accessing an attribute onto the collection items.
     *
     * @return mixed
     */
    public function __get(string $key)
    {
        return $this->collection->{$this->method}(fn ($value) => is_array($value) ? $value[$key] : $value->{$key});
    }

    /**
     * Proxy a method call onto the collection items.
     *
     * @return mixed
     */
    public function __call(string $method, array $parameters)
    {
        return $this->collection->{$this->method}(fn ($value) => $value->{$method}(...$parameters));
    }
}
