<?php

namespace Haphp\Support;

class HigherOrderTapProxy
{
    /**
     * Create a new tap proxy instance.
     *
     * @return void
     */
    public function __construct(
        public mixed $target
    ) {
    }

    /**
     * Dynamically pass method calls to the target.
     *
     * @return mixed
     */
    public function __call(string $method, array $parameters)
    {
        $this->target->{$method}(...$parameters);

        return $this->target;
    }
}
