<?php

namespace Logeecom\Settings\Repository;

/**
 * The only way into the stored settings: key and value pairs, as Requirement 4.1 describes them.
 *
 * It stores strings and knows nothing of their meaning. Turning a stored string into a setting, and deciding
 * what applies when a key is missing, belongs to the service, so a missing or broken row is handled in one
 * place.
 */
interface SettingsRepositoryInterface
{
    /**
     * Every stored pair. A key that has never been saved is simply absent.
     *
     * @return array<string, string> Values keyed by setting key
     */
    public function all(): array;

    /**
     * Stores each pair, replacing a stored value under the same key and adding the others.
     *
     * @param array<string, string> $pairs Values keyed by setting key
     */
    public function put(array $pairs): void;

    /**
     * Removes every stored pair, so every setting reads as its default again.
     */
    public function clear(): void;
}
