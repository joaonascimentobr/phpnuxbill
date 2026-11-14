<?php

declare(strict_types=1);

/**
 * Safe metadata returned after configuration values have been published.
 */
final readonly class EnvironmentConfigurationStatus
{
    /**
     * @param list<string> $overrides
     */
    public function __construct(
        public EnvironmentConfigurationMode $mode,
        public array $overrides,
        public ?int $databaseDsnPort
    ) {
    }
}
