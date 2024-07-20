<?php

declare(strict_types=1);

namespace Haphp\Support;

use Carbon\Carbon as BaseCarbon;
use Carbon\CarbonImmutable as BaseCarbonImmutable;
use DateTime;
use Haphp\Support\Traits\Conditionable;
use JetBrains\PhpStorm\NoReturn;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Uid\Ulid;

class Carbon extends BaseCarbon
{
    use Conditionable;

    /**
     * {@inheritdoc}
     */
    public static function setTestNow($testNow = null): void
    {
        BaseCarbon::setTestNow($testNow);
        BaseCarbonImmutable::setTestNow($testNow);
    }

    /**
     * Create a Carbon instance from a given ordered UUID or ULID.
     */
    public static function createFromId(string|Ulid|Uuid $id): DateTime
    {
        return Ulid::isValid($id)
            ? static::createFromInterface(Ulid::fromString($id)->getDateTime())
            : static::createFromInterface(Uuid::fromString($id)->getDateTime());
    }

    /**
     * Dump the instance and end the script.
     *
     * @param  mixed  ...$args
     */
    #[NoReturn]
    public function dd(...$args): void
    {
        dd($this, ...$args);
    }

    /**
     * Dump the instance.
     *
     * @return $this
     */
    public function dump(): static
    {
        dump($this);

        return $this;
    }
}
