<?php

namespace Haphp\Support\Traits;

use Haphp\Support\HigherOrderTapProxy;

trait Tappable
{
    /**
     * Call the given Closure with this instance then return the instance.
     */
    public function tap(?callable $callback = null): HigherOrderTapProxy|static
    {
        return tap($this, $callback);
    }
}
