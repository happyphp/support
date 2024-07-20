<?php

declare(strict_types=1);

namespace Haphp\Support;

use ArrayAccess;
use ArrayIterator;
use BackedEnum;
use Closure;
use Haphp\Contracts\Support\ArrayableInterface;
use Haphp\Contracts\Support\CanBeEscapedWhenCastToStringInterface;
use Haphp\Contracts\Support\EnumerableInterface;
use Haphp\Support\Traits\EnumeratesValues;
use Haphp\Support\Traits\Macroable;
use InvalidArgumentException;
use stdClass;
use Traversable;

/**
 * @template TKey of array-key
 *
 * @template-covariant TValue
 *
 * @implements ArrayAccess<TKey, TValue>
 * @implements EnumerableInterface<TKey, TValue>
 */
class Collection implements ArrayAccess, CanBeEscapedWhenCastToStringInterface, EnumerableInterface
{
    /**
     * @use EnumeratesValues<TKey, TValue>
     */
    use EnumeratesValues, Macroable;

    /**
     * The items contained in the collection.
     *
     * @var array<TKey, TValue>
     */
    protected array $items = [];

    /**
     * Create a new collection.
     *
     * @param  ArrayableInterface<TKey, TValue>|iterable<TKey, TValue>|null  $items
     * @return void
     */
    public function __construct(ArrayableInterface|array|null $items = [])
    {
        $this->items = $this->getArrayableInterfaceItems($items);
    }

    /**
     * Create a collection with the given range.
     *
     * @return static<int, int>
     */
    public static function range(int $from, int $to): static
    {
        return new static(range($from, $to));
    }

    /**
     * Get all the items in the collection.
     *
     * @return array<TKey, TValue>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Get a lazy collection for the items in this collection.
     *
     * @return LazyCollection<TKey, TValue>
     */
    public function lazy(): LazyCollection
    {
        return new LazyCollection($this->items);
    }

    /**
     * Get the average value of a given key.
     *
     * @param  (callable(TValue): float|int)|string|null  $callback
     */
    public function avg(callable|int|string|null $callback = null): float|int|null
    {
        $callback = $this->valueRetriever($callback);

        $items = $this
            ->map(fn ($value) => $callback($value))
            ->filter(fn ($value) => $value !== null);

        if ($count = $items->count()) {
            return $items->sum() / $count;
        }

        return null;
    }

    /**
     * Get the median of a given key.
     *
     * @param  string|array<array-key, string>|null  $key
     */
    public function median($key = null): float|int|null
    {
        $values = (isset($key) ? $this->pluck($key) : $this)
            ->filter(fn ($item) => $item !== null)
            ->sort()->values();

        $count = $values->count();

        if ($count === 0) {
            return null;
        }

        $middle = (int) ($count / 2);

        if ($count % 2) {
            return $values->get($middle);
        }

        return (new static([
            $values->get($middle - 1), $values->get($middle),
        ]))->average();
    }

    /**
     * Get the mode of a given key.
     *
     * @param  string|array<array-key, string>|null  $key
     * @return array<int, float|int>|null
     */
    public function mode($key = null): ?array
    {
        if ($this->count() === 0) {
            return null;
        }

        $collection = isset($key) ? $this->pluck($key) : $this;

        $counts = new static();

        $collection->each(fn ($value) => $counts[$value] = isset($counts[$value]) ? $counts[$value] + 1 : 1);

        $sorted = $counts->sort();

        $highestValue = $sorted->last();

        return $sorted->filter(fn ($value) => $value === $highestValue)
            ->sort()->keys()->all();
    }

    /**
     * Collapse the collection of items into a single array.
     *
     * @return static<int, mixed>
     */
    public function collapse(): static
    {
        return new static(Arr::collapse($this->items));
    }

    /**
     * Determine if an item exists in the collection.
     *
     * @param  (callable(TValue, TKey): bool)|TValue|string  $key
     */
    public function contains($key, mixed $operator = null, mixed $value = null): bool
    {
        if (func_num_args() === 1) {
            if ($this->useAsCallable($key)) {
                $placeholder = new stdClass();

                return $this->first($key, $placeholder) !== $placeholder;
            }

            return in_array($key, $this->items);
        }

        return $this->contains($this->operatorForWhere(...func_get_args()));
    }

    /**
     * Determine if an item exists, using strict comparison.
     *
     * @param  (callable(TValue): bool)|TValue|array-key  $key
     * @param  TValue|null  $value
     */
    public function containsStrict($key, $value = null): bool
    {
        if (func_num_args() === 2) {
            return $this->contains(fn ($item) => data_get($item, $key) === $value);
        }

        if ($this->useAsCallable($key)) {
            return $this->first($key) !== null;
        }

        return in_array($key, $this->items, true);
    }

    /**
     * Determine if an item is not contained in the collection.
     */
    public function doesntContain(mixed $key, mixed $operator = null, mixed $value = null): bool
    {
        return ! $this->contains(...func_get_args());
    }

    /**
     * Cross join with the given lists, returning all possible permutations.
     *
     * @template TCrossJoinKey
     * @template TCrossJoinValue
     *
     * @param  ArrayableInterface<TCrossJoinKey, TCrossJoinValue>|iterable<TCrossJoinKey, TCrossJoinValue>  ...$lists
     * @return static<int, array<int, TValue|TCrossJoinValue>>
     */
    public function crossJoin(...$lists): static
    {
        return new static(Arr::crossJoin(
            $this->items,
            ...array_map([$this, 'getArrayableItems'], $lists)
        ));
    }

    /**
     * Get the items in the collection that are not present in the given items.
     *
     * @param  ArrayableInterface<array-key, TValue>|iterable<array-key, TValue>  $items
     */
    public function diff($items): static
    {
        return new static(array_diff($this->items, $this->getArrayableItems($items)));
    }

    /**
     * Get the items in the collection that are not present in the given items, using the callback.
     *
     * @param  ArrayableInterface<array-key, TValue>|iterable<array-key, TValue>  $items
     * @param  callable(TValue, TValue): int  $callback
     */
    public function diffUsing($items, callable $callback): static
    {
        return new static(array_udiff($this->items, $this->getArrayableItems($items), $callback));
    }

    /**
     * Get the items in the collection whose keys and values are not present in the given items.
     *
     * @param  ArrayableInterface<TKey, TValue>|iterable<TKey, TValue>  $items
     */
    public function diffAssoc($items): static
    {
        return new static(array_diff_assoc($this->items, $this->getArrayableItems($items)));
    }

    /**
     * Get the items in the collection whose keys and values are not present in the given items, using the callback.
     *
     * @param  ArrayableInterface<TKey, TValue>|iterable<TKey, TValue>  $items
     * @param  callable(TKey, TKey): int  $callback
     */
    public function diffAssocUsing($items, callable $callback): static
    {
        return new static(array_diff_uassoc($this->items, $this->getArrayableItems($items), $callback));
    }

    /**
     * Get the items in the collection whose keys are not present in the given items.
     *
     * @param  ArrayableInterface<TKey, TValue>|iterable<TKey, TValue>  $items
     */
    public function diffKeys($items): static
    {
        return new static(array_diff_key($this->items, $this->getArrayableItems($items)));
    }

    /**
     * Get the items in the collection whose keys are not present in the given items, using the callback.
     *
     * @param  ArrayableInterface<TKey, TValue>|iterable<TKey, TValue>  $items
     * @param  callable(TKey, TKey): int  $callback
     */
    public function diffKeysUsing($items, callable $callback): static
    {
        return new static(array_diff_ukey($this->items, $this->getArrayableItems($items), $callback));
    }

    /**
     * Retrieve duplicate items from the collection.
     *
     * @param  (callable(TValue): bool)|string|null  $callback
     */
    public function duplicates(callable|string|null $callback = null, bool $strict = false): static
    {
        $items = $this->map($this->valueRetriever($callback));

        $uniqueItems = $items->unique(null, $strict);

        $compare = $this->duplicateComparator($strict);

        $duplicates = new static();

        foreach ($items as $key => $value) {
            if ($uniqueItems->isNotEmpty() && $compare($value, $uniqueItems->first())) {
                $uniqueItems->shift();
            } else {
                $duplicates[$key] = $value;
            }
        }

        return $duplicates;
    }

    /**
     * Retrieve duplicate items from the collection using strict comparison.
     *
     * @param  (callable(TValue): bool)|string|null  $callback
     */
    public function duplicatesStrict(callable|string|null $callback = null): static
    {
        return $this->duplicates($callback, true);
    }

    /**
     * Get all items except for those with the specified keys.
     *
     * @param  EnumerableInterface<array-key, TKey>|array<array-key, TKey>|string  $keys
     */
    public function except($keys): static
    {
        if ($keys === null) {
            return new static($this->items);
        }

        if ($keys instanceof EnumerableInterface) {
            $keys = $keys->all();
        } elseif (! is_array($keys)) {
            $keys = func_get_args();
        }

        return new static(Arr::except($this->items, $keys));
    }

    /**
     * Run a filter over each of the items.
     *
     * @param  (callable(TValue, TKey): bool)|null  $callback
     */
    public function filter(?callable $callback = null): static
    {
        if ($callback) {
            return new static(Arr::where($this->items, $callback));
        }

        return new static(array_filter($this->items));
    }

    /**
     * Get the first item from the collection passing the given truth test.
     *
     * @template TFirstDefault
     *
     * @param  (callable(TValue, TKey): bool)|null  $callback
     * @param  TFirstDefault|(Closure(): TFirstDefault)  $default
     * @return TValue|TFirstDefault
     */
    public function first(?callable $callback = null, $default = null)
    {
        return Arr::first($this->items, $callback, $default);
    }

    /**
     * Get a flattened array of the items in the collection.
     *
     * @param  int  $depth
     * @return static<int, mixed>
     */
    public function flatten($depth = INF): static
    {
        return new static(Arr::flatten($this->items, $depth));
    }

    /**
     * Flip the items in the collection.
     *
     * @return static<TValue, TKey>
     */
    public function flip(): static
    {
        return new static(array_flip($this->items));
    }

    /**
     * Remove an item from the collection by key.
     *
     * \Haphp\Contracts\Support\ArrayableInterface<array-key, TValue>|iterable<array-key, TKey>|TKey  $keys
     *
     * @return $this
     */
    public function forget($keys): static
    {
        foreach ($this->getArrayableItems($keys) as $key) {
            $this->offsetUnset($key);
        }

        return $this;
    }

    /**
     * Get an item from the collection by key.
     *
     * @template TGetDefault
     *
     * @param  TKey  $key
     * @param  TGetDefault|(Closure(): TGetDefault)  $default
     * @return TValue|TGetDefault
     */
    public function get($key, $default = null)
    {
        if (array_key_exists($key, $this->items)) {
            return $this->items[$key];
        }

        return value($default);
    }

    /**
     * Get an item from the collection by key or add it to collection if it does not exist.
     *
     * @template TGetOrPutValue
     *
     * @param  mixed  $key
     * @param  TGetOrPutValue|(Closure(): TGetOrPutValue)  $value
     * @return TValue|TGetOrPutValue
     */
    public function getOrPut($key, $value)
    {
        if (array_key_exists($key, $this->items)) {
            return $this->items[$key];
        }

        $this->offsetSet($key, $value = value($value));

        return $value;
    }

    /**
     * Group an associative array by a field or using a callback.
     *
     * @param  (callable(TValue, TKey): array-key)|array|string  $groupBy
     * @return static<array-key, static<array-key, TValue>>
     */
    public function groupBy(callable|array|string $groupBy, bool $preserveKeys = false): static
    {
        if (! $this->useAsCallable($groupBy) && is_array($groupBy)) {
            $nextGroups = $groupBy;

            $groupBy = array_shift($nextGroups);
        }

        $groupBy = $this->valueRetriever($groupBy);

        $results = [];

        foreach ($this->items as $key => $value) {
            $groupKeys = $groupBy($value, $key);

            if (! is_array($groupKeys)) {
                $groupKeys = [$groupKeys];
            }

            foreach ($groupKeys as $groupKey) {
                $groupKey = match (true) {
                    is_bool($groupKey) => (int) $groupKey,
                    $groupKey instanceof BackedEnum => $groupKey->value,
                    $groupKey instanceof \Stringable => (string) $groupKey,
                    default => $groupKey,
                };

                if (! array_key_exists($groupKey, $results)) {
                    $results[$groupKey] = new static();
                }

                $results[$groupKey]->offsetSet($preserveKeys ? $key : null, $value);
            }
        }

        $result = new static($results);

        if (! empty($nextGroups)) {
            return $result->map->groupBy($nextGroups, $preserveKeys);
        }

        return $result;
    }

    /**
     * Key an associative array by a field or using a callback.
     *
     * @param  (callable(TValue, TKey): array-key)|array|string  $keyBy
     * @return static<array-key, TValue>
     */
    public function keyBy(callable|array|string $keyBy): static
    {
        $keyBy = $this->valueRetriever($keyBy);

        $results = [];

        foreach ($this->items as $key => $item) {
            $resolvedKey = $keyBy($item, $key);

            if (is_object($resolvedKey)) {
                $resolvedKey = (string) $resolvedKey;
            }

            $results[$resolvedKey] = $item;
        }

        return new static($results);
    }

    /**
     * Determine if an item exists in the collection by key.
     *
     * @param  TKey|array<array-key, TKey>  $key
     */
    public function has($key): bool
    {
        $keys = is_array($key) ? $key : func_get_args();

        foreach ($keys as $value) {
            if (! array_key_exists($value, $this->items)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine if any of the keys exist in the collection.
     */
    public function hasAny(mixed $key): bool
    {
        if ($this->isEmpty()) {
            return false;
        }

        $keys = is_array($key) ? $key : func_get_args();

        foreach ($keys as $value) {
            if ($this->has($value)) {
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
        if ($this->useAsCallable($value)) {
            return implode($glue ?? '', $this->map($value)->all());
        }

        $first = $this->first();

        if (is_array($first) || (is_object($first) && ! $first instanceof Stringable)) {
            return implode($glue ?? '', $this->pluck($value)->all());
        }

        return implode($glue ?? '', $this->items);
    }

    /**
     * Intersect the collection with the given items.
     *
     * @param  ArrayableInterface<TKey, TValue>|iterable<TKey, TValue>  $items
     */
    public function intersect($items): static
    {
        return new static(array_intersect($this->items, $this->getArrayableItems($items)));
    }

    /**
     * Intersect the collection with the given items, using the callback.
     *
     * @param  ArrayableInterface<array-key, TValue>|iterable<array-key, TValue>  $items
     * @param  callable(TValue, TValue): int  $callback
     */
    public function intersectUsing(ArrayableInterface|array $items, callable $callback): static
    {
        return new static(array_uintersect($this->items, $this->getArrayableItems($items), $callback));
    }

    /**
     * Intersect the collection with the given items with additional index check.
     *
     * @param  ArrayableInterface<TKey, TValue>|iterable<TKey, TValue>  $items
     */
    public function intersectAssoc(ArrayableInterface|array $items): static
    {
        return new static(array_intersect_assoc($this->items, $this->getArrayableItems($items)));
    }

    /**
     * Intersect the collection with the given items with additional index check, using the callback.
     *
     * @param  ArrayableInterface<array-key, TValue>|iterable<array-key, TValue>  $items
     * @param  callable(TValue, TValue): int  $callback
     */
    public function intersectAssocUsing(ArrayableInterface|array $items, callable $callback): static
    {
        return new static(array_intersect_uassoc($this->items, $this->getArrayableItems($items), $callback));
    }

    /**
     * Intersect the collection with the given items by key.
     *
     * @param  ArrayableInterface<TKey, TValue>|iterable<TKey, TValue>  $items
     */
    public function intersectByKeys($items): static
    {
        return new static(array_intersect_key(
            $this->items,
            $this->getArrayableItems($items)
        ));
    }

    /**
     * Determine if the collection is empty or not.
     */
    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    /**
     * Determine if the collection contains a single item.
     */
    public function containsOneItem(): bool
    {
        return $this->count() === 1;
    }

    /**
     * Join all items from the collection using a string. The final items can use a separate glue string.
     */
    public function join(string $glue, string $finalGlue = ''): string
    {
        if ($finalGlue === '') {
            return $this->implode($glue);
        }

        $count = $this->count();

        if ($count === 0) {
            return '';
        }

        if ($count === 1) {
            return (string) $this->last();
        }

        $collection = new static($this->items);

        $finalItem = $collection->pop();

        return $collection->implode($glue).$finalGlue.$finalItem;
    }

    /**
     * Get the keys of the collection items.
     *
     * @return static<int, TKey>
     */
    public function keys(): static
    {
        return new static(array_keys($this->items));
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
        return Arr::last($this->items, $callback, $default);
    }

    /**
     * Get the values of a given key.
     *
     * @param  string|int|array<array-key, string>  $value
     * @return static<array-key, mixed>
     */
    public function pluck($value, ?string $key = null): static
    {
        return new static(Arr::pluck($this->items, $value, $key));
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
        return new static(Arr::map($this->items, $callback));
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
        $dictionary = [];

        foreach ($this->items as $key => $item) {
            $pair = $callback($item, $key);

            $key = key($pair);

            $value = reset($pair);

            if (! isset($dictionary[$key])) {
                $dictionary[$key] = [];
            }

            $dictionary[$key][] = $value;
        }

        return new static($dictionary);
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
        return new static(Arr::mapWithKeys($this->items, $callback));
    }

    /**
     * Merge the collection with the given items.
     *
     * @param  ArrayableInterface<TKey, TValue>|iterable<TKey, TValue>  $items
     */
    public function merge($items): static
    {
        return new static(array_merge($this->items, $this->getArrayableItems($items)));
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
        return new static(array_merge_recursive($this->items, $this->getArrayableItems($items)));
    }

    /**
     * Create a collection by using this collection for keys and another for its values.
     *
     * @template TCombineValue
     *
     * @param  ArrayableInterface<array-key, TCombineValue>|iterable<array-key, TCombineValue>  $values
     * @return static<TValue, TCombineValue>
     */
    public function combine($values): static
    {
        return new static(array_combine($this->all(), $this->getArrayableItems($values)));
    }

    /**
     * Union the collection with the given items.
     *
     * @param  ArrayableInterface<TKey, TValue>|iterable<TKey, TValue>  $items
     */
    public function union($items): static
    {
        return new static($this->items + $this->getArrayableItems($items));
    }

    /**
     * Create a new collection consisting of every n-th element.
     */
    public function nth(int $step, int $offset = 0): static
    {
        $new = [];

        $position = 0;

        foreach ($this->slice($offset)->items as $item) {
            if ($position % $step === 0) {
                $new[] = $item;
            }

            $position++;
        }

        return new static($new);
    }

    /**
     * Get the items with the specified keys.
     *
     * @param  EnumerableInterface<array-key, TKey>|array<array-key, TKey>|string|null  $keys
     */
    public function only($keys): static
    {
        if ($keys === null) {
            return new static($this->items);
        }

        if ($keys instanceof EnumerableInterface) {
            $keys = $keys->all();
        }

        $keys = is_array($keys) ? $keys : func_get_args();

        return new static(Arr::only($this->items, $keys));
    }

    /**
     * Get and remove the last N items from the collection.
     *
     * @return static<int, TValue>|TValue|null
     */
    public function pop(int $count = 1)
    {
        if ($count === 1) {
            return array_pop($this->items);
        }

        if ($this->isEmpty()) {
            return new static();
        }

        $results = [];

        $collectionCount = $this->count();

        foreach (range(1, min($count, $collectionCount)) as $item) {
            $results[] = array_pop($this->items);
        }

        return new static($results);
    }

    /**
     * Push an item onto the beginning of the collection.
     *
     * @param  TValue  $value
     * @param  TKey  $key
     * @return $this
     */
    public function prepend($value, $key = null): static
    {
        $this->items = Arr::prepend($this->items, ...func_get_args());

        return $this;
    }

    /**
     * Push one or more items onto the end of the collection.
     *
     * @param  TValue  ...$values
     * @return $this
     */
    public function push(...$values): static
    {
        foreach ($values as $value) {
            $this->items[] = $value;
        }

        return $this;
    }

    /**
     * Push all the given items onto the collection.
     *
     * @param  iterable<array-key, TValue>  $source
     */
    public function concat($source): static
    {
        $result = new static($this);

        foreach ($source as $item) {
            $result->push($item);
        }

        return $result;
    }

    /**
     * Get and remove an item from the collection.
     *
     * @template TPullDefault
     *
     * @param  TKey  $key
     * @param  (Closure(): TPullDefault)|null  $default
     * @return TValue|TPullDefault
     */
    public function pull($key, ?Closure $default = null)
    {
        return Arr::pull($this->items, $key, $default);
    }

    /**
     * Put an item in the collection by key.
     *
     * @param  TKey  $key
     * @param  TValue  $value
     * @return $this
     */
    public function put($key, $value): static
    {
        $this->offsetSet($key, $value);

        return $this;
    }

    /**
     * Get one or a specified number of items randomly from the collection.
     *
     * @param  (callable(self<TKey, TValue>): int)|int|null  $number
     * @return static<int, TValue>|TValue
     *
     * @throws InvalidArgumentException
     */
    public function random($number = null, bool $preserveKeys = false)
    {
        if ($number === null) {
            return Arr::random($this->items);
        }

        if (is_callable($number)) {
            return new static(Arr::random($this->items, $number($this), $preserveKeys));
        }

        return new static(Arr::random($this->items, $number, $preserveKeys));
    }

    /**
     * Replace the collection items with the given items.
     *
     * @param  ArrayableInterface<TKey, TValue>|iterable<TKey, TValue>  $items
     */
    public function replace($items): static
    {
        return new static(array_replace($this->items, $this->getArrayableItems($items)));
    }

    /**
     * Recursively replace the collection items with the given items.
     *
     * @param  ArrayableInterface<TKey, TValue>|iterable<TKey, TValue>  $items
     */
    public function replaceRecursive($items): static
    {
        return new static(array_replace_recursive($this->items, $this->getArrayableItems($items)));
    }

    /**
     * Reverse items order.
     */
    public function reverse(): static
    {
        return new static(array_reverse($this->items, true));
    }

    /**
     * Search the collection for a given value and return the corresponding key if successful.
     *
     * @param  TValue|(callable(TValue,TKey): bool)  $value
     * @return TKey|false
     */
    public function search($value, bool $strict = false)
    {
        if (! $this->useAsCallable($value)) {
            return array_search($value, $this->items, $strict);
        }

        foreach ($this->items as $key => $item) {
            if ($value($item, $key)) {
                return $key;
            }
        }

        return false;
    }

    /**
     * Get and remove the first N items from the collection.
     *
     * @return static<int, TValue>|TValue|null
     */
    public function shift(int $count = 1)
    {
        if ($count === 1) {
            return array_shift($this->items);
        }

        if ($this->isEmpty()) {
            return new static();
        }

        $results = [];

        $collectionCount = $this->count();

        foreach (range(1, min($count, $collectionCount)) as $item) {
            $results[] = array_shift($this->items);
        }

        return new static($results);
    }

    /**
     * Shuffle the items in the collection.
     */
    public function shuffle(?int $seed = null): static
    {
        return new static(Arr::shuffle($this->items, $seed));
    }

    /**
     * Create chunks representing a "sliding window" view of the items in the collection.
     *
     * @return static<int, static>
     */
    public function sliding(int $size = 2, int $step = 1): static
    {
        $chunks = floor(($this->count() - $size) / $step) + 1;

        return static::times($chunks, fn ($number) => $this->slice(($number - 1) * $step, $size));
    }

    /**
     * Skip the first {$count} items.
     */
    public function skip(int $count): static
    {
        return $this->slice($count);
    }

    /**
     * Skip items in the collection until the given condition is met.
     *
     * @param  TValue|callable(TValue,TKey): bool  $value
     */
    public function skipUntil($value): static
    {
        return new static($this->lazy()->skipUntil($value)->all());
    }

    /**
     * Skip items in the collection while the given condition is met.
     *
     * @param  TValue|callable(TValue,TKey): bool  $value
     */
    public function skipWhile($value): static
    {
        return new static($this->lazy()->skipWhile($value)->all());
    }

    /**
     * Slice the underlying collection array.
     */
    public function slice(int $offset, ?int $length = null): static
    {
        return new static(array_slice($this->items, $offset, $length, true));
    }

    /**
     * Split a collection into a certain number of groups.
     *
     * @return static<int, static>
     */
    public function split(int $numberOfGroups): static
    {
        if ($this->isEmpty()) {
            return new static();
        }

        $groups = new static();

        $groupSize = floor($this->count() / $numberOfGroups);

        $remain = $this->count() % $numberOfGroups;

        $start = 0;

        for ($i = 0; $i < $numberOfGroups; $i++) {
            $size = $groupSize;

            if ($i < $remain) {
                $size++;
            }

            if ($size) {
                $groups->push(new static(array_slice($this->items, $start, (int) $size)));

                $start += $size;
            }
        }

        return $groups;
    }

    /**
     * Split a collection into a certain number of groups, and fill the first groups completely.
     *
     * @return static<int, static>
     */
    public function splitIn(int $numberOfGroups): static
    {
        return $this->chunk((int) ceil($this->count() / $numberOfGroups));
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

        $items = $this->unless($filter === null)->filter($filter);

        $count = $items->count();

        if ($count === 0) {
            throw new ItemNotFoundException();
        }

        if ($count > 1) {
            throw new MultipleItemsFoundException($count);
        }

        return $items->first();
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

        $placeholder = new stdClass();

        $item = $this->first($filter, $placeholder);

        if ($item === $placeholder) {
            throw new ItemNotFoundException();
        }

        return $item;
    }

    /**
     * Chunk the collection into chunks of the given size.
     *
     * @return static<int, static>
     */
    public function chunk(int $size): static
    {
        if ($size <= 0) {
            return new static();
        }

        $chunks = [];

        foreach (array_chunk($this->items, $size, true) as $chunk) {
            $chunks[] = new static($chunk);
        }

        return new static($chunks);
    }

    /**
     * Chunk the collection into chunks with a callback.
     *
     * @param  callable(TValue, TKey, static<int, TValue>): bool  $callback
     * @return static<int, static<int, TValue>>
     */
    public function chunkWhile(callable $callback): static
    {
        return new static(
            $this->lazy()->chunkWhile($callback)->mapInto(static::class)
        );
    }

    /**
     * Sort through each item with a callback.
     *
     * @param  (callable(TValue, TValue): int)|int|null  $callback
     */
    public function sort(callable|int|null $callback = null): static
    {
        $items = $this->items;

        $callback && is_callable($callback)
            ? uasort($items, $callback)
            : asort($items, $callback ?? SORT_REGULAR);

        return new static($items);
    }

    /**
     * Sort items in descending order.
     */
    public function sortDesc(int $options = SORT_REGULAR): static
    {
        $items = $this->items;

        arsort($items, $options);

        return new static($items);
    }

    /**
     * Sort the collection using the given callback.
     *
     * @param  array<array-key, (callable(TValue, TValue): mixed)|(callable(TValue, TKey): mixed)|string|array{string, string}>|(callable(TValue, TKey): mixed)|string  $callback
     */
    public function sortBy($callback, int $options = SORT_REGULAR, bool $descending = false): static
    {
        if (is_array($callback) && ! is_callable($callback)) {
            return $this->sortByMany($callback);
        }

        $results = [];

        $callback = $this->valueRetriever($callback);

        // First, we will loop through the items and get the comparator from a callback
        // function which we were given. Then, we will sort the returned values and
        // grab all the corresponding values for the sorted keys from this array.
        foreach ($this->items as $key => $value) {
            $results[$key] = $callback($value, $key);
        }

        $descending ? arsort($results, $options)
            : asort($results, $options);

        // Once we have sorted all the keys in the array, we will loop through them
        // and grab the corresponding model, so we can set the underlying items list
        // to the sorted version. Then we'll just return the collection instance.
        foreach (array_keys($results) as $key) {
            $results[$key] = $this->items[$key];
        }

        return new static($results);
    }

    /**
     * Sort the collection in descending order using the given callback.
     *
     * @param  array<array-key, (callable(TValue, TValue): mixed)|(callable(TValue, TKey): mixed)|string|array{string, string}>|(callable(TValue, TKey): mixed)|string  $callback
     */
    public function sortByDesc($callback, int $options = SORT_REGULAR): static
    {
        return $this->sortBy($callback, $options, true);
    }

    /**
     * Sort the collection keys.
     */
    public function sortKeys(int $options = SORT_REGULAR, bool $descending = false): static
    {
        $items = $this->items;

        $descending ? krsort($items, $options) : ksort($items, $options);

        return new static($items);
    }

    /**
     * Sort the collection keys in descending order.
     */
    public function sortKeysDesc(int $options = SORT_REGULAR): static
    {
        return $this->sortKeys($options, true);
    }

    /**
     * Sort the collection keys using a callback.
     *
     * @param  callable(TKey, TKey): int  $callback
     */
    public function sortKeysUsing(callable $callback): static
    {
        $items = $this->items;

        uksort($items, $callback);

        return new static($items);
    }

    /**
     * Splice a portion of the underlying collection array.
     *
     * @param  array<array-key, TValue>  $replacement
     */
    public function splice(int $offset, ?int $length = null, array $replacement = []): static
    {
        if (func_num_args() === 1) {
            return new static(array_splice($this->items, $offset));
        }

        return new static(array_splice($this->items, $offset, $length, $this->getArrayableItems($replacement)));
    }

    /**
     * Take the first or last {$limit} items.
     */
    public function take(int $limit): static
    {
        if ($limit < 0) {
            return $this->slice($limit, abs($limit));
        }

        return $this->slice(0, $limit);
    }

    /**
     * Take items in the collection until the given condition is met.
     *
     * @param  TValue|callable(TValue,TKey): bool  $value
     */
    public function takeUntil($value): static
    {
        return new static($this->lazy()->takeUntil($value)->all());
    }

    /**
     * Take items in the collection while the given condition is met.
     *
     * @param  TValue|callable(TValue,TKey): bool  $value
     */
    public function takeWhile($value): static
    {
        return new static($this->lazy()->takeWhile($value)->all());
    }

    /**
     * Transform each item in the collection using a callback.
     *
     * @param  callable(TValue, TKey): TValue  $callback
     * @return $this
     */
    public function transform(callable $callback): static
    {
        $this->items = $this->map($callback)->all();

        return $this;
    }

    /**
     * Flatten a multi-dimensional associative array with dots.
     */
    public function dot(): static
    {
        return new static(Arr::dot($this->all()));
    }

    /**
     * Convert a flatten "dot" notation array into an expanded array.
     */
    public function undot(): static
    {
        return new static(Arr::undot($this->all()));
    }

    /**
     * Return only unique items from the collection array.
     *
     * @param  (callable(TValue, TKey): mixed)|string|null  $key
     */
    public function unique(callable|string|null $key = null, bool $strict = false): static
    {
        if ($key === null && $strict === false) {
            return new static(array_unique($this->items, SORT_REGULAR));
        }

        $callback = $this->valueRetriever($key);

        $exists = [];

        return $this->reject(function ($item, $key) use ($callback, $strict, &$exists) {
            if (in_array($id = $callback($item, $key), $exists, $strict)) {
                return true;
            }

            $exists[] = $id;

            return false;
        });
    }

    /**
     * Reset the keys on the underlying array.
     *
     * @return static<int, TValue>
     */
    public function values(): static
    {
        return new static(array_values($this->items));
    }

    /**
     * Zip the collection together with one or more arrays.
     *
     * E.g., new Collection([1, 2, 3])->zip([4, 5, 6]);
     *      => [[1, 4], [2, 5], [3, 6]]
     *
     * @template TZipValue
     *
     * @param  ArrayableInterface<array-key, TZipValue>|iterable<array-key, TZipValue>  ...$items
     * @return static<int, static<int, TValue|TZipValue>>
     */
    public function zip(array|ArrayableInterface ...$items): static
    {
        $arrayableItems = array_map(fn ($items) => $this->getArrayableItems($items), func_get_args());

        $params = array_merge([fn () => new static(func_get_args()), $this->items], $arrayableItems);

        return new static(array_map(...$params));
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
        return new static(array_pad($this->items, $size, $value));
    }

    /**
     * Get an iterator for the items.
     *
     * @return ArrayIterator<TKey, TValue>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    /**
     * Count the number of items in the collection.
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Count the number of items in the collection by a field or using a callback.
     *
     * @param  (callable(TValue, TKey): array-key)|string|null  $countBy
     * @return static<array-key, int>
     */
    public function countBy(callable|string|null $countBy = null): static
    {
        return new static($this->lazy()->countBy($countBy)->all());
    }

    /**
     * Add an item to the collection.
     *
     * @param  TValue  $item
     * @return $this
     */
    public function add($item): static
    {
        $this->items[] = $item;

        return $this;
    }

    /**
     * Get a base Support collection instance from this collection.
     *
     * @return Collection<TKey, TValue>
     */
    public function toBase(): Collection
    {
        return new self($this);
    }

    /**
     * Determine if an item exists at an offset.
     *
     * @param  TKey  $offset
     */
    public function offsetExists($offset): bool
    {
        return isset($this->items[$offset]);
    }

    /**
     * Get an item at a given offset.
     *
     * @param  TKey  $offset
     * @return TValue
     */
    public function offsetGet($offset): mixed
    {
        return $this->items[$offset];
    }

    /**
     * Set the item at a given offset.
     *
     * @param  TKey|null  $offset
     * @param  TValue  $value
     */
    public function offsetSet($offset, $value): void
    {
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    /**
     * Unset the item at a given offset.
     *
     * @param  TKey  $offset
     */
    public function offsetUnset($offset): void
    {
        unset($this->items[$offset]);
    }

    /**
     * Get the comparison function to detect duplicates.
     *
     * @return callable(TValue, TValue): bool
     */
    protected function duplicateComparator(bool $strict): callable
    {
        if ($strict) {
            return fn ($a, $b) => $a === $b;
        }

        return fn ($a, $b) => $a === $b;
    }

    /**
     * Sort the collection using multiple comparisons.
     *
     * @param  array<array-key, (callable(TValue, TValue): mixed)|(callable(TValue, TKey): mixed)|string|array{string, string}>  $comparisons
     */
    protected function sortByMany(array $comparisons = []): static
    {
        $items = $this->items;

        uasort($items, function ($a, $b) use ($comparisons) {
            foreach ($comparisons as $comparison) {
                $comparison = Arr::wrap($comparison);

                $prop = $comparison[0];

                $ascending = Arr::get($comparison, 1, true) === true ||
                    Arr::get($comparison, 1, true) === 'asc';

                if (! is_string($prop) && is_callable($prop)) {
                    $result = $prop($a, $b);
                } else {
                    $values = [data_get($a, $prop), data_get($b, $prop)];

                    if (! $ascending) {
                        $values = array_reverse($values);
                    }

                    $result = $values[0] <=> $values[1];
                }

                if ($result === 0) {
                    continue;
                }

                return $result;
            }
        });

        return new static($items);
    }
}
