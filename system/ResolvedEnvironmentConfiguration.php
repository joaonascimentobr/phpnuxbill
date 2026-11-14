<?php

declare(strict_types=1);

/**
 * Result of resolving stable environment settings alongside legacy globals.
 */
final readonly class ResolvedEnvironmentConfiguration
{
    /**
     * @param array<string, mixed> $globals Dynamic legacy config.php values consumed by PHPNuxBill.
     * @param list<string> $overrides
     */
    public function __construct(
        public array $globals,
        public EnvironmentConfigurationMode $mode,
        public array $overrides,
        public ?int $databaseDsnPort,
        public bool $applyErrorDisplayPolicy
    ) {
    }
}
