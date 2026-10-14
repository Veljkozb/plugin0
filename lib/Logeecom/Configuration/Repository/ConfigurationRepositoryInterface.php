<?php

namespace Logeecom\Configuration\Repository;

use Logeecom\Configuration\Domain\Configuration;

/**
 * The only way into the stored configuration.
 *
 * There is exactly one configuration, created by a migration and only ever updated, so the contract offers a
 * read and a write and nothing that could create or delete one: the rule is kept by what cannot be called.
 */
interface ConfigurationRepositoryInterface
{
    /**
     * The one stored configuration.
     *
     * @return Configuration What is stored now
     */
    public function get(): Configuration;

    /**
     * Overwrites the one stored configuration with this one.
     *
     * @param Configuration $configuration The configuration to store
     */
    public function save(Configuration $configuration): void;
}
