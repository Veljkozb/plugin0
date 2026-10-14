<?php

namespace Tests\Support;

use Logeecom\Settings\Repository\SettingsRepositoryInterface;

/**
 * The settings held in an array, for tests that are about the service and not about storage. It behaves
 * exactly as the interface promises: strings in, strings out, no meaning attached.
 */
final class InMemorySettingsRepository implements SettingsRepositoryInterface
{
    /**
     * @param array<string, string> $pairs What is stored at the start; nothing when empty, as after the migration
     */
    public function __construct(private array $pairs = [])
    {
    }

    /**
     * @return array<string, string> Values keyed by setting key
     */
    public function all(): array
    {
        return $this->pairs;
    }

    /**
     * @param array<string, string> $pairs Values keyed by setting key
     */
    public function put(array $pairs): void
    {
        $this->pairs = $pairs + $this->pairs;
    }

    /**
     * Removes every pair.
     */
    public function clear(): void
    {
        $this->pairs = [];
    }
}
