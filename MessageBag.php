<?php

declare(strict_types=1);

namespace Haphp\Support;

use Haphp\Contracts\Support\ArrayableInterface;
use Haphp\Contracts\Support\JsonableInterface;
use Haphp\Contracts\Support\MessageBagInterface;
use Haphp\Contracts\Support\MessageProviderInterface;
use JsonSerializable;

class MessageBag implements JsonableInterface, JsonSerializable, MessageBagInterface, MessageProviderInterface
{
    /**
     * All the registered messages.
     */
    protected array $messages = [];

    /**
     * Default format for message output.
     */
    protected string $format = ':message';

    /**
     * Create a new message bag instance.
     *
     * @return void
     */
    public function __construct(array $messages = [])
    {
        foreach ($messages as $key => $value) {
            $value = $value instanceof ArrayableInterface ? $value->toArray() : (array) $value;

            $this->messages[$key] = array_unique($value);
        }
    }

    /**
     * Convert the message bag to its string representation.
     *
     * @return string
     */
    public function __toString()
    {
        return $this->toJson();
    }

    /**
     * Get the keys present in the message bag.
     */
    public function keys(): array
    {
        return array_keys($this->messages);
    }

    /**
     * Add a message to the message bag.
     *
     * @return $this
     */
    public function add(string $key, string $message): static
    {
        if ($this->isUnique($key, $message)) {
            $this->messages[$key][] = $message;
        }

        return $this;
    }

    /**
     * Add a message to the message bag if the given conditional is "true".
     *
     * @return $this
     */
    public function addIf(bool $boolean, string $key, string $message): MessageBag|static
    {
        return $boolean ? $this->add($key, $message) : $this;
    }

    /**
     * Merge a new array of messages into the message bag.
     *
     * @return $this
     */
    public function merge(MessageProviderInterface|array $messages): static
    {
        if ($messages instanceof MessageProviderInterface) {
            $messages = $messages->getMessageBag()->getMessages();
        }

        $this->messages = array_merge_recursive($this->messages, $messages);

        return $this;
    }

    /**
     * Determine if messages exist for all the given keys.
     */
    public function has(array|string|null $key): bool
    {
        if ($this->isEmpty()) {
            return false;
        }

        if ($key === null) {
            return $this->any();
        }

        $keys = is_array($key) ? $key : func_get_args();

        foreach ($keys as $key) {
            if ($this->first($key) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine if messages exist for any of the given keys.
     */
    public function hasAny(array|string|null $keys = []): bool
    {
        if ($this->isEmpty()) {
            return false;
        }

        $keys = is_array($keys) ? $keys : func_get_args();

        foreach ($keys as $key) {
            if ($this->has($key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if messages don't exist for all the given keys.
     */
    public function missing(array|string|null $key): bool
    {
        $keys = is_array($key) ? $key : func_get_args();

        return ! $this->hasAny($keys);
    }

    /**
     * Get the first message from the message bag for a given key.
     */
    public function first(?string $key = null, ?string $format = null): string
    {
        $messages = $key === null ? $this->all($format) : $this->get($key, $format);

        $firstMessage = Arr::first($messages, null, '');

        return is_array($firstMessage) ? Arr::first($firstMessage) : $firstMessage;
    }

    /**
     * Get all the messages from the message bag for a given key.
     */
    public function get(string $key, ?string $format = null): array
    {
        // If the message exists in the message bag, we will transform it and return
        // the message. Otherwise, we will check if the key is implicit & collect
        // all the messages that match the given key and output it as an array.
        if (array_key_exists($key, $this->messages)) {
            return $this->transform(
                $this->messages[$key],
                $this->checkFormat($format),
                $key
            );
        }

        if (str_contains($key, '*')) {
            return $this->getMessagesForWildcardKey($key, $format);
        }

        return [];
    }

    /**
     * Get all the messages for every key in the message bag.
     */
    public function all(?string $format = null): array
    {
        $format = $this->checkFormat($format);

        $all = [];

        foreach ($this->messages as $key => $messages) {
            $all = array_merge($all, $this->transform($messages, $format, $key));
        }

        return $all;
    }

    /**
     * Get all the unique messages for every key in the message bag.
     */
    public function unique(?string $format = null): array
    {
        return array_unique($this->all($format));
    }

    /**
     * Remove a message from the message bag.
     *
     * @return $this
     */
    public function forget(string $key): static
    {
        unset($this->messages[$key]);

        return $this;
    }

    /**
     * Get the raw messages in the message bag.
     */
    public function messages(): array
    {
        return $this->messages;
    }

    /**
     * Get the raw messages in the message bag.
     */
    public function getMessages(): array
    {
        return $this->messages();
    }

    public function getMessageBag(): MessageBagInterface
    {
        return $this;
    }

    /**
     * Get the default message format.
     */
    public function getFormat(): string
    {
        return $this->format;
    }

    /**
     * Set the default message format.
     */
    public function setFormat(string $format = ':message'): static
    {
        $this->format = $format;

        return $this;
    }

    /**
     * Determine if the message bag has any messages.
     */
    public function isEmpty(): bool
    {
        return ! $this->any();
    }

    /**
     * Determine if the message bag has any messages.
     */
    public function isNotEmpty(): bool
    {
        return $this->any();
    }

    /**
     * Determine if the message bag has any messages.
     */
    public function any(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Get the number of messages in the message bag.
     */
    public function count(): int
    {
        return count($this->messages, COUNT_RECURSIVE) - count($this->messages);
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return $this->getMessages();
    }

    /**
     * Convert the object into something JSON serializable.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Convert the object to its JSON representation.
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options);
    }

    /**
     * Determine if a key and message combination already exists.
     */
    protected function isUnique(string $key, string $message): bool
    {
        $messages = $this->messages;

        return ! isset($messages[$key]) || ! in_array($message, $messages[$key]);
    }

    /**
     * Get the messages for a wildcard key.
     */
    protected function getMessagesForWildcardKey(string $key, ?string $format): array
    {
        return collect($this->messages)
            ->filter(fn ($messages, $messageKey) => Str::is($key, $messageKey))
            ->map(function ($messages, $messageKey) use ($format) {
                return $this->transform(
                    $messages,
                    $this->checkFormat($format),
                    $messageKey
                );
            })->all();
    }

    /**
     * Format an array of messages.
     */
    protected function transform(array $messages, string $format, string $messageKey): array
    {
        if ($format === ':message') {
            return $messages;
        }

        return collect($messages)
            ->map(function ($message) use ($format, $messageKey) {
                // We will simply spin through the given messages and transform each one,
                // replacing the :message placeholder with the real message allowing
                // the messages to be easily formatted to each developer's desires.
                return str_replace([':message', ':key'], [$message, $messageKey], $format);
            })->all();
    }

    /**
     * Get the appropriate format based on the given format.
     */
    protected function checkFormat(string $format): string
    {
        return $format ?: $this->format;
    }
}
