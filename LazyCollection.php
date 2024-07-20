<?php

declare(strict_types=1);

namespace Haphp\Support;

use ArrayIterator;
use Closure;
use DateTimeInterface;
use Exception;
use Generator;
use Haphp\Contracts\Support\ArrayableInterface;
use Haphp\Contracts\Support\CanBeEscapedWhenCastToStringInterface;
use Haphp\Contracts\Support\EnumerableInterface;
use Haphp\Support\Traits\EnumeratesValues;
use Haphp\Support\Traits\Macroable;
use InvalidArgumentException;
use IteratorAggregate;
use stdClass;
use Traversable;

/**
 * @template TKey of array-key
 *
 * @template-covariant TValue
 *
 * @implements EnumerableInterface<TKey, TValue>
 */
class LazyCollection implements CanBeEscapedWhenCastToStringInterface, EnumerableInterface
{
    /**
     * @use EnumeratesValues<TKey, TValue>
     */
    use EnumeratesValues, Macroable;

    /**
     * The source from which to generate items.
     *
     * @var (Closure(): Generator<TKey, TValue, mixed, void>)|array<TKey, TValue>|static
     */
    public Closure|array|LazyCollection $source;

    /**
     * Create a new lazy collection instance.
     *
     * @param  (Closure(): Generator<TKey, TValue, mixed, void>)|ArrayableInterface<TKey, TValue>|iterable<TKey, TValue>|self<TKey, TValue>|null  $source
     * @return void
     */
    public function __construct(ArrayableInterface|LazyCollection|array|Closure|null $source = null)
    {
        if ($source instanceof Closure || $source instanceof self) {
            $this->source = $source;
        } elseif ($source === null) {
            $this->source = static::empty();
        } elseif ($source instanceof Generator) {
            throw new InvalidArgumentException(
                'Generators should not be passed directly to LazyCollection. Instead, pass a generator function.'
            );
        } else {
            $this->source = $this->getArrayableInterfaceItems($source);
        }
    }

    /**
     * Create a new collection instance if the value isn't one already.
     *
     * @template TMakeKey of array-key
     * @template TMakeValue
     *
     * @param  ArrayableInterface<TMakeKey, TMakeValue>|iterable<TMakeKey, TMakeValue>|(Closure(): Generator<TMakeKey, TMakeValue, mixed, void>)|self<TMakeKey, TMakeValue>|null  $items
     * @return static<TMakeKey, TMakeValue>
     */
    public static function make($items = []): static
    {
        return new static($items);
    }

    /**
     * Create a collection with the given range.
     *
     * @return static<int, int>
     */
    public static function range(int $from, int $to): static
    {
        return new static(function () use ($from, $to) {
            if ($from <= $to) {
                for (; $from <= $to; $from++) {
                    yield $from;
                }
            } else {
                for (; $from >= $to; $from--) {
                    yield $from;
                }
            }
        });
    }

    /**
     * Get all items in the enumerable.
     *
     * @return array<TKey, TValue>
     *
     * @throws Exception
     */
    public function all(): array
    {
        if (is_array($this->source)) {
            return $this->source;
        }

        return iterator_to_array($this->getIterator());
    }

    /**
     * Eager load all items into a new lazy collection backed by an array.
     *
     * @throws Exception
     */
    public function eager(): static
    {
        return new static($this->all());
    }

    /**
     * Cache values as they're enumerated.
     *
     * @throws Exception
     */
    public function remember(): static
    {
        $iterator = $this->getIterator();

        $iteratorIndex = 0;

        $cache = [];

        return new static(function () use ($iterator, &$iteratorIndex, &$cache) {
            for ($index = 0; true; $index++) {
                if (array_key_exists($index, $cache)) {
                    yield $cache[$index][0] => $cache[$index][1];

                    continue;
                }

                if ($iteratorIndex < $index) {
                    $iterator->next();

                    $iteratorIndex++;
                }

                if (! $iterator->valid()) {
                    break;
                }

                $cache[$index] = [$iterator->key(), $iterator->current()];

                yield $cache[$index][0] => $cache[$index][1];
            }
        });
    }

    /**
     * Get the average value of a given key.
     *
     * @param  (callable(TValue): float|int)|string|null  $callback
     */
    public function avg(callable|int|string|null $callback = null): float|int|null
    {
        return $this->collect()->avg($callback);
    }

    /**
     * Get the median of a given key.
     *
     * @param  string|array<array-key, string>|null  $key
     */
    public function median($key = null): float|int|null
    {
        return $this->collect()->median($key);
    }

    /**
     * Get the mode of a given key.
     *
     * @param  string|array<string>|null  $key
     * @return array<int, float|int>|null
     */
    public function mode($key = null): ?array
    {
        return $this->collect()->mode($key);
    }

    /**
     * Collapse the collection of items into a single array.
     *
     * @return static<int, mixed>
     */
    public function collapse(): static
    {
        return new static(function () {
            foreach ($this as $values) {
                if (is_array($values) || $values instanceof EnumerableInterface) {
                    foreach ($values as $value) {
                        yield $value;
                    }
                }
            }
        });
    }

    /**
     * Determine if an item exists in the enumerable.
     *
     * @param  (callable(TValue, TKey): bool)|TValue|string  $key
     *
     * @throws Exception
     */
    public function contains($key, mixed $operator = null, mixed $value = null): bool
    {
        if (func_num_args() === 1 && $this->useAsCallable($key)) {
            $placeholder = new stdClass();

            /** @var callable $key */
            return $this->first($key, $placeholder) !== $placeholder;
        }

        if (func_num_args() === 1) {
            $needle = $key;

            foreach ($this as $value) {
                if ($value === $needle) {
                    return true;
                }
            }

            return false;
        }

        return $this->contains($this->operatorForWhere(...func_get_args()));
    }

    /**
     * Determine if an item exists, using strict comparison.
     *
     * @param  (callable(TValue): bool)|TValue|array-key  $key
     * @param  TValue|null  $value
     *
     * @throws Exception
     */
    public function containsStrict($key, $value = null): bool
    {
        if (func_num_args() === 2) {
            return $this->contains(fn ($item) => data_get($item, $key) === $value);
        }

        if ($this->useAsCallable($key)) {
            return $this->first($key) !== null;
        }

        foreach ($this as $item) {
            if ($item === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if an item is not contained in the enumerable.
     *
     * @throws Exception
     */
    public function doesntContain(mixed $key, mixed $operator = null, mixed $value = null): bool
    {
        return ! $this->contains(...func_get_args());
    }

    /**
     * Cross join the given iterables, returning all possible permutations.
     *
     * @template TCrossJoinKey
     * @template TCrossJoinValue
     *
     * @param  ArrayableInterface<TCrossJoinKey, TCrossJoinValue>|iterable<TCrossJoinKey, TCrossJoinValue>  ...$arrays
     * @return static<int, array<int, TValue|TCrossJoinValue>>
     */
    public function crossJoin(...$arrays): static
    {
        return $this->passthru('crossJoin', func_get_args());
    }

    /**
     * Count the number of items in the collection by a field or using a callback.
     *
     * @param  (callable(TValue, TKey): array-key)|string|null  $countBy
     * @return static<array-key, int>
     */
    public function countBy(callable|string|null $countBy = null): static
    {
        $countBy = $countBy === null
            ? $this->identity()
            : $this->valueRetriever($countBy);

        return new static(function () use ($countBy) {
            $counts = [];

            foreach ($this as $key => $value) {
                $group = $countBy($value, $key);

                if (empty($counts[$group])) {
                    $counts[$group] = 0;
                }

                $counts[$group]++;
            }

            yield from $counts;
        });
    }

    /**
     * Get the items that are not present in the given items.
     *
     * @param  ArrayableInterface<array-key, TValue>|iterable<array-key, TValue>  $items
     */
    public function diff($items): static
    {
        return $this->passthru('diff', func_get_args());
    }

    /**
     * Get the items that are not present in the given items, using the callback.
     *
     * @param  ArrayableInterface<array-key, TValue>|iterable<array-key, TValue>  $items
     * @param  callable(TValue, TValue): int  $callback
     */
    public function diffUsing($items, callable $callback): static
    {
        return $this->passthru('diffUsing', func_get_args());
    }

    /**
     * Get the items whose keys and values are not present in the given items.
     *
     * @param  ArrayableInterface<TKey, TValue>|iterable<TKey, TValue>  $items
     */
    public function diffAssoc($items): static
    {
        return $this->passthru('diffAssoc', func_get_args());
    }

    /**
     * Get the items whose keys and values are not present in the given items, using the callback.
     *
     * @param  ArrayableInterface<TKey, TValue>|iterable<TKey, TValue>  $items
     * @param  callable(TKey, TKey): int  $callback
     */
    public function diffAssocUsing($items, callable $callback): static
    {
        return $this->passthru('diffAssocUsing', func_get_args());
    }

    /**
     * Get the items whose keys are not present in the given items.
     *
     * @param  ArrayableInterface<TKey, TValue>|iterable<TKey, TValue>  $items
     */
    public function diffKeys($items): static
    {
        return $this->passthru('diffKeys', func_get_args());
    }

    /**
     * Get the items whose keys are not present in the given items, using the callback.
     *
     * @param  ArrayableInterface<TKey, TValue>|iterable<TKey, TValue>  $items
     * @param  callable(TKey, TKey): int  $callback
     */
    public function diffKeysUsing($items, callable $callback): static
    {
        return $this->passthru('diffKeysUsing', func_get_args());
    }

    /**
     * Retrieve duplicate items.
     *
     * @param  (callable(TValue): bool)|string|null  $callback
     */
    public function duplicates(callable|string|null $callback = null, bool $strict = false): static
    {
        return $this->passthru('duplicates', func_get_args());
    }

    /**
     * Retrieve duplicate items using strict comparison.
     *
     * @param  (callable(TValue): bool)|string|null  $callback
     */
    public function duplicatesStrict(callable|string|null $callback = null): static
    {
        return $this->passthru('duplicatesStrict', func_get_args());
    }

    /**
     * Get all items except for those with the specified keys.
     *
     * @param  EnumerableInterface<array-key, TKey>|array<array-key, TKey>  $keys
     */
    public function except($keys): static
    {
        return $this->passthru('except', func_get_args());
    }

    /**
     * Run a filter over each of the items.
     *
     * @param  (callable(TValue, TKey): bool)|null  $callback
     */
    public function filter(?callable $callback = null): static
    {
        if ($callback === null) {
            $callback = fn ($value) => (bool) $value;
        }

        return new static(function () use ($callback) {
            foreach ($this as $key => $value) {
                if ($callback($value, $key)) {
                    yield $key => $value;
                }
            }
        });
    }

    /**
     * Get the first item from enumerable passing the given truth test.
     *
     * @template TFirstDefault
     *
     * @param  (callable(TValue): bool)|null  $callback
     * @param  TFirstDefault|(Closure(): TFirstDefault)  $default
     * @return TValue|TFirstDefault
     *
     * @throws Exception
     */
    public function first(?callable $callback = null, $default = null)
    {
        $iterator = $this->getIterator();

        if ($callback === null) {
            if (! $iterator->valid()) {
                return value($default);
            }

            return $iterator->current();
        }

        foreach ($iterator as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }

        return value($default);
    }

    /**
     * Get a flattened list of the items in the collection.
     *
     * @param  int  $depth
     * @return static<int, mixed>
     */
    public function flatten($depth = INF): static
    {
        $instance = new static(function () use ($depth) {
            foreach ($this as $item) {
                if (! is_array($item) && ! $item instanceof EnumerableInterface) {
                    yield $item;
                } elseif ($depth === 1) {
                    yield from $item;
                } else {
                    yield from (new static($item))->flatten($depth - 1);
                }
            }
        });

        return $instance->values();
    }

    /**
     * Flip the items in the collection.
     *
     * @return static<TValue, TKey>
     */
    public function flip(): static
    {
        return new static(function () {
            foreach ($this as $key => $value) {
                yield $value => $key;
            }
        });
    }

    /**
     * Get an item by key.
     *
     * @template TGetDefault
     *
     * @param  TKey|null  $key
     * @param  TGetDefault|(Closure(): TGetDefault)  $default
     * @return TValue|TGetDefault
     */
    public function get($key, $default = null)
    {
        if ($key === null) {
            return null;
        }

        foreach ($this as $outerKey => $outerValue) {
            if ($outerKey === $key) {
                return $outerValue;
            }
        }

        return value($default);
    }

    /**
     * Group an associative array by a field or using a callback.
     *
     * @param  (callable(TValue, TKey): array-key)|array|string  $groupBy
     * @return static<array-key, static<array-key, TValue>>
     */
    public function groupBy(callable|array|string $groupBy, bool $preserveKeys = false): static
    {
        return $this->passthru('groupBy', func_get_args());
    }

    /**
     * Key an associative array by a field or using a callback.
     *
     * @param  (callable(TValue, TKey): array-key)|array|string  $keyBy
     * @return static<array-key, TValue>
     */
    public function keyBy(callable|array|string $keyBy): static
    {
        return new static(function () use ($keyBy) {
            $keyBy = $this->valueRetriever($keyBy);

            foreach ($this as $key => $item) {
                $resolvedKey = $keyBy($item, $key);

                if (is_object($resolvedKey)) {
                    $resolvedKey = (string) $resolvedKey;
                }

                yield $resolvedKey => $item;
            }
        });
    }

    /**
     * Determine if an item exists in the collection by key.
     *
     * @param  mixed  $key
     */
    public function has($key): bool
    {
        $keys = array_flip(is_array($key) ? $key : func_get_args());
        $count = count($keys);

        foreach ($this as $key => $value) {
            if (array_key_exists($key, $keys) && --$count === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if any of the keys exist in the collection.
     */
    public function hasAny(mixed $key): bool
    {
        $keys = array_flip(is_array($key) ? $key : func_get_args());

        foreach ($this as $key => $value) {
            if (array_key_exists($key, $keys)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Concatenate values of a given key as a string.
     */
    public function implode(callable|string $value, ?string $glue = null): string
    {
        return $this->collect()->implode(...func_get_args());
    }

    /**
     * Intersect the collection with the given items.
     *
     * @param  ArrayableInterface<TKey, TValue>|iterable<TKey, TValue>  $items
     */
    public function intersect($items): static
    {
        return $this->passthru('intersect', func_get_args());
    }

    /**
     * Intersect the collection with the given items, using the callback.
     *
     * @return LazyCollection
     */
    public function intersectUsing(): static
    {
        return $this->passthru('intersectUsing', func_get_args());
    }

    /**
     * Intersect the collection with the given items with additional index check.
     *
     * @param  ArrayableInterface<TKey, TValue>|iterable<TKey, TValue>  $items
     */
    public function intersectAssoc(ArrayableInterface|array $items): static
    {
        return $this->passthru('intersectAssoc', func_get_args());
    }

    /**
     * Intersect the collection with the given items with additional index check, using the callback.
     *
     * @param  ArrayableInterface<array-key, TValue>|iterable<array-key, TValue>  $items
     * @param  callable(TValue, TValue): int  $callback
     */
    public function intersectAssocUsing(ArrayableInterface|array $items, callable $callback): static
    {
        return $this->passthru('intersectAssocUsing', func_get_args());
    }

    /**
     * Intersect the collection with the given items by key.
     *
     * @param  ArrayableInterface<TKey, TValue>|iterable<TKey, TValue>  $items
     */
    public function intersectByKeys($items): static
    {
        return $this->passthru('intersectByKeys', func_get_args());
    }

    /**
     * Determine if the items are empty or not.
     *
     * @throws Exception
     */
    public function isEmpty(): bool
    {
        return ! $this->getIterator()->valid();
    }

    /**
     * Determine if the collection contains a single item.
     *
     * @throws Exception
     */
    public function containsOneItem(): bool
    {
        return $this->take(2)->count() === 1;
    }

    /**
     * Join all items from the collection using a string. The final items can use a separate glue string.
     */
    public function join(string $glue, string $finalGlue = ''): string
    {
        return $this->collect()->join(...func_get_args());
    }

    /**
     * Get the keys of the collection items.
     *
     * @return static<int, TKey>
     */
    public function keys(): static
    {
        return new static(function () {
            foreach ($this as $key => $value) {
                yield $key;
            }
        });
    }

    /**
     * Get the last item from the collection.
     *
     * @template TLastDefault
     *
     * @param  (callable(TValue, TKey): bool)|null  $callback
     * @param  TLastDefault|(Closure(): TLastDefault)  $default
     * @return TValue|TLastDefault
     */
    public function last(?callable $callback = null, $default = null)
    {
        $needle = $placeholder = new stdClass();

        foreach ($this as $key => $value) {
            if ($callback === null || $callback($value, $key)) {
                $needle = $value;
            }
        }

        return $needle === $placeholder ? value($default) : $needle;
    }

    /**
     * Get the values of a given key.
     *
     * @param  string|array<array-key, string>  $value
     * @return static<int, mixed>
     */
    public function pluck($value, ?string $key = null): static
    {
        return new static(function () use ($value, $key) {
            [$value, $key] = $this->explodePluckParameters($value, $key);

            foreach ($this as $item) {
                $itemValue = data_get($item, $value);

                if ($key === null) {
                    yield $itemValue;
                } else {
                    $itemKey = data_get($item, $key);

                    if (is_object($itemKey) && method_exists($itemKey, '__toString')) {
                        $itemKey = (string) $itemKey;
                    }

                    yield $itemKey => $itemValue;
                }
            }
        });
    }

    /**
     * Run a map over each of the items.
     *
     * @template TMapValue
     *
     * @param  callable(TValue, TKey): TMapValue  $callback
     * @return static<TKey, TMapValue>
     */
    public function map(callable $callback): static
    {
        return new static(function () use ($callback) {
            foreach ($this as $key => $value) {
                yield $key => $callback($value, $key);
            }
        });
    }

    /**
     * Run a dictionary map over the items.
     *
     * The callback should return an associative array with a single key/value pair.
     *
     * @template TMapToDictionaryKey of array-key
     * @template TMapToDictionaryValue
     *
     * @param  callable(TValue, TKey): array<TMapToDictionaryKey, TMapToDictionaryValue>  $callback
     * @return static<TMapToDictionaryKey, array<int, TMapToDictionaryValue>>
     */
    public function mapToDictionary(callable $callback): static
    {
        return $this->passthru('mapToDictionary', func_get_args());
    }

    /**
     * Run an associative map over each of the items.
     *
     * The callback should return an associative array with a single key/value pair.
     *
     * @template TMapWithKeysKey of array-key
     * @template TMapWithKeysValue
     *
     * @param  callable(TValue, TKey): array<TMapWithKeysKey, TMapWithKeysValue>  $callback
     * @return static<TMapWithKeysKey, TMapWithKeysValue>
     */
    public function mapWithKeys(callable $callback): static
    {
        return new static(function () use ($callback) {
            foreach ($this as $key => $value) {
                yield from $callback($value, $key);
            }
        });
    }

    /**
     * Merge the collection with the given items.
     *
     * @param  ArrayableInterface<TKey, TValue>|iterable<TKey, TValue>  $items
     */
    public function merge($items): static
    {
        return $this->passthru('merge', func_get_args());
    }

    /**
     * Recursively merge the collection with the given items.
     *
     * @template TMergeRecursiveValue
     *
     * @param  ArrayableInterface<TKey, TMergeRecursiveValue>|iterable<TKey, TMergeRecursiveValue>  $items
     * @return static<TKey, TValue|TMergeRecursiveValue>
     */
    public function mergeRecursive($items): static
    {
        return $this->passthru('mergeRecursive', func_get_args());
    }

    /**
     * Create a collection by using this collection for keys and another for its values.
     *
     * @template TCombineValue
     *
     * @param  IteratorAggregate<array-key, TCombineValue>|array<array-key, TCombineValue>|(callable(): Generator<array-key, TCombineValue>)  $values
     * @return static<TValue, TCombineValue>
     */
    public function combine($values): static
    {
        return new static(function () use ($values) {
            $values = $this->makeIterator($values);

            $errorMessage = 'Both parameters should have an equal number of elements';

            foreach ($this as $key) {
                if (! $values->valid()) {
                    trigger_error($errorMessage, E_USER_WARNING);

                    break;
                }

                yield $key => $values->current();

                $values->next();
            }

            if ($values->valid()) {
                trigger_error($errorMessage, E_USER_WARNING);
            }
        });
    }

    /**
     * Union the collection with the given items.
     *
     * @param  ArrayableInterface<TKey, TValue>|iterable<TKey, TValue>  $items
     */
    public function union($items): static
    {
        return $this->passthru('union', func_get_args());
    }

    /**
     * Create a new collection consisting of every n-th element.
     */
    public function nth(int $step, int $offset = 0): static
    {
        return new static(function () use ($step, $offset) {
            $position = 0;

            foreach ($this->slice($offset) as $item) {
                if ($position % $step === 0) {
                    yield $item;
                }

                $position++;
            }
        });
    }

    /**
     * Get the items with the specified keys.
     *
     * @param  EnumerableInterface<array-key, TKey>|array<array-key, TKey>|string  $keys
     */
    public function only($keys): static
    {
        if ($keys instanceof EnumerableInterface) {
            $keys = $keys->all();
        } elseif ($keys !== null) {
            $keys = is_array($keys) ? $keys : func_get_args();
        }

        return new static(function () use ($keys) {
            if ($keys === null) {
                yield from $this;
            } else {
                $keys = array_flip($keys);

                foreach ($this as $key => $value) {
                    if (array_key_exists($key, $keys)) {
                        yield $key => $value;

                        unset($keys[$key]);

                        if (empty($keys)) {
                            break;
                        }
                    }
                }
            }
        });
    }

    /**
     * Push all the given items onto the collection.
     *
     * @param  iterable<array-key, TValue>  $source
     */
    public function concat($source): static
    {
        return (new static(function () use ($source) {
            yield from $this;
            yield from $source;
        }))->values();
    }

    /**
     * Get one or a specified number of items randomly from the collection.
     *
     * @return static<int, TValue>|TValue
     *
     * @throws InvalidArgumentException
     */
    public function random(?int $number = null)
    {
        $result = $this->collect()->random(...func_get_args());

        return $number === null ? $result : new static($result);
    }

    /**
     * Replace the collection items with the given items.
     *
     * @param  ArrayableInterface<TKey, TValue>|iterable<TKey, TValue>  $items
     */
    public function replace($items): static
    {
        return new static(function () use ($items) {
            $items = $this->getArrayableInterfaceItems($items);

            foreach ($this as $key => $value) {
                if (array_key_exists($key, $items)) {
                    yield $key => $items[$key];

                    unset($items[$key]);
                } else {
                    yield $key => $value;
                }
            }

            foreach ($items as $key => $value) {
                yield $key => $value;
            }
        });
    }

    /**
     * Recursively replace the collection items with the given items.
     *
     * @param  ArrayableInterface<TKey, TValue>|iterable<TKey, TValue>  $items
     */
    public function replaceRecursive($items): static
    {
        return $this->passthru('replaceRecursive', func_get_args());
    }

    /**
     * Reverse items order.
     */
    public function reverse(): static
    {
        return $this->passthru('reverse', func_get_args());
    }

    /**
     * Search the collection for a given value and return the corresponding key if successful.
     *
     * @param  TValue|(callable(TValue,TKey): bool)  $value
     * @return TKey|false
     */
    public function search($value, bool $strict = false)
    {
        /** @var (callable(TValue,TKey): bool) $predicate */
        $predicate = $this->useAsCallable($value)
            ? $value
            : fn ($item) => $strict ? $item === $value : $item == $value;

        foreach ($this as $key => $item) {
            if ($predicate($item, $key)) {
                return $key;
            }
        }

        return false;
    }

    /**
     * Shuffle the items in the collection.
     */
    public function shuffle(?int $seed = null): static
    {
        return $this->passthru('shuffle', func_get_args());
    }

    /**
     * Create chunks representing a "sliding window" view of the items in the collection.
     *
     * @return static<int, static>
     */
    public function sliding(int $size = 2, int $step = 1): static
    {
        return new static(function () use ($size, $step) {
            $iterator = $this->getIterator();

            $chunk = [];

            while ($iterator->valid()) {
                $chunk[$iterator->key()] = $iterator->current();

                if (count($chunk) === $size) {
                    yield (new static($chunk))->tap(function () use (&$chunk, $step): void {
                        $chunk = array_slice($chunk, $step, null, true);
                    });

                    // If the $step between chunks is bigger than each chunk's $size,
                    // we will skip the extra items (which should never be in any
                    // chunk) before we continue to the next chunk in the loop.
                    if ($step > $size) {
                        $skip = $step - $size;

                        for ($i = 0; $i < $skip && $iterator->valid(); $i++) {
                            $iterator->next();
                        }
                    }
                }

                $iterator->next();
            }
        });
    }

    /**
     * Skip the first {$count} items.
     */
    public function skip(int $count): static
    {
        return new static(function () use ($count) {
            $iterator = $this->getIterator();

            while ($iterator->valid() && $count--) {
                $iterator->next();
            }

            while ($iterator->valid()) {
                yield $iterator->key() => $iterator->current();

                $iterator->next();
            }
        });
    }

    /**
     * Skip items in the collection until the given condition is met.
     *
     * @param  TValue|callable(TValue,TKey): bool  $value
     */
    public function skipUntil($value): static
    {
        $callback = $this->useAsCallable($value) ? $value : $this->equality($value);

        return $this->skipWhile($this->negate($callback));
    }

    /**
     * Skip items in the collection while the given condition is met.
     *
     * @param  TValue|callable(TValue,TKey): bool  $value
     */
    public function skipWhile($value): static
    {
        $callback = $this->useAsCallable($value) ? $value : $this->equality($value);

        return new static(function () use ($callback) {
            $iterator = $this->getIterator();

            while ($iterator->valid() && $callback($iterator->current(), $iterator->key())) {
                $iterator->next();
            }

            while ($iterator->valid()) {
                yield $iterator->key() => $iterator->current();

                $iterator->next();
            }
        });
    }

    /**
     * Get a slice of items from the enumerable.
     */
    public function slice(int $offset, ?int $length = null): static
    {
        if ($offset < 0 || $length < 0) {
            return $this->passthru('slice', func_get_args());
        }

        $instance = $this->skip($offset);

        return $length === null ? $instance : $instance->take($length);
    }

    /**
     * Split a collection into a certain number of groups.
     *
     * @return static<int, static>
     */
    public function split(int $numberOfGroups): static
    {
        return $this->passthru('split', func_get_args());
    }

    /**
     * Get the first item in the collection, but only if exactly one item exists. Otherwise, throw an exception.
     *
     * @param  (callable(TValue, TKey): bool)|string  $key
     * @return TValue
     *
     * @throws ItemNotFoundException
     * @throws MultipleItemsFoundException
     */
    public function sole($key = null, mixed $operator = null, mixed $value = null)
    {
        $filter = func_num_args() > 1
            ? $this->operatorForWhere(...func_get_args())
            : $key;

        return $this
            ->unless($filter === null)
            ->filter($filter)
            ->take(2)
            ->collect()
            ->sole();
    }

    /**
     * Get the first item in the collection but throw an exception if no matching items exist.
     *
     * @param  (callable(TValue, TKey): bool)|string  $key
     * @return TValue
     *
     * @throws ItemNotFoundException
     */
    public function firstOrFail($key = null, mixed $operator = null, mixed $value = null)
    {
        $filter = func_num_args() > 1
            ? $this->operatorForWhere(...func_get_args())
            : $key;

        return $this
            ->unless($filter === null)
            ->filter($filter)
            ->take(1)
            ->collect()
            ->firstOrFail();
    }

    /**
     * Chunk the collection into chunks of the given size.
     *
     * @return static<int, static>
     */
    public function chunk(int $size): static
    {
        if ($size <= 0) {
            return static::empty();
        }

        return new static(function () use ($size) {
            $iterator = $this->getIterator();

            while ($iterator->valid()) {
                $chunk = [];

                while (true) {
                    $chunk[$iterator->key()] = $iterator->current();

                    if (count($chunk) < $size) {
                        $iterator->next();

                        if (! $iterator->valid()) {
                            break;
                        }
                    } else {
                        break;
                    }
                }

                yield new static($chunk);

                $iterator->next();
            }
        });
    }

    /**
     * Split a collection into a certain number of groups, and fill the first groups completely.
     *
     * @return static<int, static>
     *
     * @throws Exception
     */
    public function splitIn(int $numberOfGroups): static
    {
        return $this->chunk((int) ceil($this->count() / $numberOfGroups));
    }

    /**
     * Chunk the collection into chunks with a callback.
     *
     * @param  callable(TValue, TKey, Collection<TKey, TValue>): bool  $callback
     * @return static<int, static<int, TValue>>
     */
    public function chunkWhile(callable $callback): static
    {
        return new static(function () use ($callback) {
            $iterator = $this->getIterator();

            $chunk = new Collection();

            if ($iterator->valid()) {
                $chunk[$iterator->key()] = $iterator->current();

                $iterator->next();
            }

            while ($iterator->valid()) {
                if (! $callback($iterator->current(), $iterator->key(), $chunk)) {
                    yield new static($chunk);

                    $chunk = new Collection();
                }

                $chunk[$iterator->key()] = $iterator->current();

                $iterator->next();
            }

            if ($chunk->isNotEmpty()) {
                yield new static($chunk);
            }
        });
    }

    /**
     * Sort through each item with a callback.
     *
     * @param  (callable(TValue, TValue): int)|int|null  $callback
     */
    public function sort(callable|int|null $callback = null): static
    {
        return $this->passthru('sort', func_get_args());
    }

    /**
     * Sort items in descending order.
     */
    public function sortDesc(int $options = SORT_REGULAR): static
    {
        return $this->passthru('sortDesc', func_get_args());
    }

    /**
     * Sort the collection using the given callback.
     *
     * @param  array<array-key, (callable(TValue, TValue): mixed)|(callable(TValue, TKey): mixed)|string|array{string, string}>|(callable(TValue, TKey): mixed)|string  $callback
     */
    public function sortBy($callback, int $options = SORT_REGULAR, bool $descending = false): static
    {
        return $this->passthru('sortBy', func_get_args());
    }

    /**
     * Sort the collection in descending order using the given callback.
     *
     * @param  array<array-key, (callable(TValue, TValue): mixed)|(callable(TValue, TKey): mixed)|string|array{string, string}>|(callable(TValue, TKey): mixed)|string  $callback
     */
    public function sortByDesc($callback, int $options = SORT_REGULAR): static
    {
        return $this->passthru('sortByDesc', func_get_args());
    }

    /**
     * Sort the collection keys.
     */
    public function sortKeys(int $options = SORT_REGULAR, bool $descending = false): static
    {
        return $this->passthru('sortKeys', func_get_args());
    }

    /**
     * Sort the collection keys in descending order.
     */
    public function sortKeysDesc(int $options = SORT_REGULAR): static
    {
        return $this->passthru('sortKeysDesc', func_get_args());
    }

    /**
     * Sort the collection keys using a callback.
     *
     * @param  callable(TKey, TKey): int  $callback
     */
    public function sortKeysUsing(callable $callback): static
    {
        return $this->passthru('sortKeysUsing', func_get_args());
    }

    /**
     * Take the first or last {$limit} items.
     */
    public function take(int $limit): static
    {
        if ($limit < 0) {
            return new static(function () use ($limit) {
                $limit = abs($limit);
                $ringBuffer = [];
                $position = 0;

                foreach ($this as $key => $value) {
                    $ringBuffer[$position] = [$key, $value];
                    $position = ($position + 1) % $limit;
                }

                for ($i = 0, $end = min($limit, count($ringBuffer)); $i < $end; $i++) {
                    $pointer = ($position + $i) % $limit;
                    yield $ringBuffer[$pointer][0] => $ringBuffer[$pointer][1];
                }
            });
        }

        return new static(function () use ($limit) {
            $iterator = $this->getIterator();

            while ($limit--) {
                if (! $iterator->valid()) {
                    break;
                }

                yield $iterator->key() => $iterator->current();

                if ($limit) {
                    $iterator->next();
                }
            }
        });
    }

    /**
     * Take items in the collection until the given condition is met.
     *
     * @param  TValue|callable(TValue,TKey): bool  $value
     */
    public function takeUntil($value): static
    {
        /** @var callable(TValue, TKey): bool $callback */
        $callback = $this->useAsCallable($value) ? $value : $this->equality($value);

        return new static(function () use ($callback) {
            foreach ($this as $key => $item) {
                if ($callback($item, $key)) {
                    break;
                }

                yield $key => $item;
            }
        });
    }

    /**
     * Take items in the collection until a given point in time.
     */
    public function takeUntilTimeout(DateTimeInterface $timeout): static
    {
        $timeout = $timeout->getTimestamp();

        return new static(function () use ($timeout) {
            if ($this->now() >= $timeout) {
                return;
            }

            foreach ($this as $key => $value) {
                yield $key => $value;

                if ($this->now() >= $timeout) {
                    break;
                }
            }
        });
    }

    /**
     * Take items in the collection while the given condition is met.
     *
     * @param  TValue|callable(TValue,TKey): bool  $value
     */
    public function takeWhile($value): static
    {
        /** @var callable(TValue, TKey): bool $callback */
        $callback = $this->useAsCallable($value) ? $value : $this->equality($value);

        return $this->takeUntil(fn ($item, $key) => ! $callback($item, $key));
    }

    /**
     * Pass each item in the collection to the given callback, lazily.
     *
     * @param  callable(TValue, TKey): mixed  $callback
     */
    public function tapEach(callable $callback): static
    {
        return new static(function () use ($callback) {
            foreach ($this as $key => $value) {
                $callback($value, $key);

                yield $key => $value;
            }
        });
    }

    /**
     * Flatten a multi-dimensional associative array with dots.
     */
    public function dot(): static
    {
        return $this->passthru('dot', []);
    }

    /**
     * Convert a flatten "dot" notation array into an expanded array.
     */
    public function undot(): static
    {
        return $this->passthru('undot', []);
    }

    /**
     * Return only unique items from the collection array.
     *
     * @param  (callable(TValue, TKey): mixed)|string|null  $key
     */
    public function unique(callable|string|null $key = null, bool $strict = false): static
    {
        $callback = $this->valueRetriever($key);

        return new static(function () use ($callback, $strict) {
            $exists = [];

            foreach ($this as $key => $item) {
                if (! in_array($id = $callback($item, $key), $exists, $strict)) {
                    yield $key => $item;

                    $exists[] = $id;
                }
            }
        });
    }

    /**
     * Reset the keys on the underlying array.
     *
     * @return static<int, TValue>
     */
    public function values(): static
    {
        return new static(function () {
            foreach ($this as $item) {
                yield $item;
            }
        });
    }

    /**
     * Zip the collection together with one or more arrays.
     *
     * E.g., new LazyCollection([1, 2, 3])->zip([4, 5, 6]);
     *      => [[1, 4], [2, 5], [3, 6]]
     *
     * @template TZipValue
     *
     * @param  ArrayableInterface<array-key, TZipValue>|iterable<array-key, TZipValue>  ...$items
     * @return static<int, static<int, TValue|TZipValue>>
     */
    public function zip(array|ArrayableInterface ...$items): static
    {
        $iterables = func_get_args();

        return new static(function () use ($iterables) {
            $iterators = Collection::make($iterables)->map(fn ($iterable) => $this->makeIterator($iterable))->prepend($this->getIterator());

            while ($iterators->contains->valid()) {
                yield new static($iterators->map->current());

                $iterators->each->next();
            }
        });
    }

    /**
     * Pad collection to the specified length with a value.
     *
     * @template TPadValue
     *
     * @param  TPadValue  $value
     * @return static<int, TValue|TPadValue>
     */
    public function pad(int $size, $value): static
    {
        if ($size < 0) {
            return $this->passthru('pad', func_get_args());
        }

        return new static(function () use ($size, $value) {
            $yielded = 0;

            foreach ($this as $index => $item) {
                yield $index => $item;

                $yielded++;
            }

            while ($yielded++ < $size) {
                yield $value;
            }
        });
    }

    /**
     * Get the values iterator.
     *
     * @return Traversable<TKey, TValue>
     *
     * @throws Exception
     */
    public function getIterator(): Traversable
    {
        return $this->makeIterator($this->source);
    }

    /**
     * Count the number of items in the collection.
     *
     * @throws Exception
     */
    public function count(): int
    {
        if (is_array($this->source)) {
            return count($this->source);
        }

        return iterator_count($this->getIterator());
    }

    /**
     * Make an iterator from the given source.
     *
     * @template TIteratorKey of array-key
     * @template TIteratorValue
     *
     * @param  (callable(): Generator<TIteratorKey, TIteratorValue>)|IteratorAggregate<TIteratorKey, TIteratorValue>|array<TIteratorKey, TIteratorValue>  $source
     *
     * @throws Exception
     */
    protected function makeIterator(callable|IteratorAggregate|array $source): Traversable|ArrayIterator
    {
        if ($source instanceof IteratorAggregate) {
            return $source->getIterator();
        }

        if (is_array($source)) {
            return new ArrayIterator($source);
        }

        if (is_callable($source)) {
            $maybeTraversable = $source();

            return $maybeTraversable instanceof Traversable
                ? $maybeTraversable
                : new ArrayIterator(Arr::wrap($maybeTraversable));
        }

        return new ArrayIterator((array) $source);
    }

    /**
     * Explode the "value" and "key" arguments passed to "pluck".
     *
     * @param  string|string[]  $value
     * @param  string|string[]|null  $key
     * @return array{string[],string[]|null}
     */
    protected function explodePluckParameters(array|string $value, array|string|null $key): array
    {
        $value = is_string($value) ? explode('.', $value) : $value;

        $key = $key === null || is_array($key) ? $key : explode('.', $key);

        return [$value, $key];
    }

    /**
     * Pass this lazy collection through a method on the collection class.
     */
    protected function passthru(string $method, array $params): static
    {
        return new static(function () use ($method, $params) {
            yield from $this->collect()->{$method}(...$params);
        });
    }

    /**
     * Get the current time.
     */
    protected function now(): int
    {
        return Carbon::now()->timestamp;
    }
}
