<?php

declare(strict_types=1);

namespace Haphp\Support;

class HigherOrderWhenProxy
{
    /**
     * The condition for proxying.
     */
    protected bool $condition;

    /**
     * Indicates whether the proxy has a condition.
     */
    protected bool $hasCondition = false;

    /**
     * Determine whether the condition should be negated.
     */
    protected bool $negateConditionOnCapture;

    /**
     * Create a new proxy instance.
     *
     * @return void
     */
    public function __construct(
        protected mixed $target
    ) {
    }

    /**
     * Proxy accessing an attribute onto the target.
     *
     * @return mixed
     */
    public function __get(string $key)
    {
        if (! $this->hasCondition) {
            $condition = $this->target->{$key};

            return $this->condition($this->negateConditionOnCapture ? ! $condition : $condition);
        }

        return $this->condition
            ? $this->target->{$key}
            : $this->target;
    }

    /**
     * Proxy a method call on the target.
     *
     * @return mixed
     */
    public function __call(string $method, array $parameters)
    {
        if (! $this->hasCondition) {
            $condition = $this->target->{$method}(...$parameters);

            return $this->condition($this->negateConditionOnCapture ? ! $condition : $condition);
        }

        return $this->condition
            ? $this->target->{$method}(...$parameters)
            : $this->target;
    }

    /**
     * Set the condition on the proxy.
     *
     * @return $this
     */
    public function condition(bool $condition): static
    {
        [$this->condition, $this->hasCondition] = [$condition, true];

        return $this;
    }

    /**
     * Indicate that the condition should be negated.
     *
     * @return $this
     */
    public function negateConditionOnCapture(): static
    {
        $this->negateConditionOnCapture = true;

        return $this;
    }
}
