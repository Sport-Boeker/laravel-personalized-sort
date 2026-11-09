<?php

namespace SportBoeker\PersonalizedSort\ValueObjects;

use Illuminate\Contracts\Auth\Authenticatable;

final readonly class Context
{
    /**
     * @param  array<string, mixed>  $preferences
     * @param  array<string, mixed>  $attributes  JSON-serializable request context, including tenant or location.
     */
    public function __construct(
        public ?Authenticatable $user = null,
        public array $preferences = [],
        public array $attributes = [],
    ) {}

    public function isGuest(): bool
    {
        return $this->user === null;
    }

    /** @param array<string, mixed> $preferences */
    public function withPreferences(array $preferences): self
    {
        return new self($this->user, $preferences, $this->attributes);
    }
}
