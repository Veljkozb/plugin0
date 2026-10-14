<?php

namespace Tests\Support;

use Logeecom\Configuration\Domain\Configuration;
use Logeecom\Configuration\Repository\ConfigurationRepositoryInterface;

/**
 * The configuration held in a property, for tests that are about the service and not about storage.
 *
 * It lives in tests/ and not in the application: the application runs on the Eloquent repository only. It
 * behaves exactly as the interface promises and nothing more. Holding the object itself is safe here, unlike
 * the payment methods' array rows: Configuration is immutable, so a caller cannot change what is stored
 * without calling save().
 */
final class InMemoryConfigurationRepository implements ConfigurationRepositoryInterface
{
    private Configuration $configuration;

    /**
     * @param Configuration|null $configuration What is stored at the start; the migration's row when null
     */
    public function __construct(?Configuration $configuration = null)
    {
        $this->configuration = $configuration ?? new Configuration();
    }

    /**
     * @return Configuration What is stored now
     */
    public function get(): Configuration
    {
        return $this->configuration;
    }

    /**
     * @param Configuration $configuration The configuration to store
     */
    public function save(Configuration $configuration): void
    {
        $this->configuration = $configuration;
    }
}
