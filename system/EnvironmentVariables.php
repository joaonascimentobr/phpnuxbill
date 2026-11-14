<?php

declare(strict_types=1);

/**
 * Immutable, typed values read from the process environment.
 */
final readonly class EnvironmentVariables
{
    public function __construct(
        public ?string $appUrl = null,
        public ?string $appEnv = null,
        public ?string $dbHost = null,
        public ?string $dbPort = null,
        public ?string $dbName = null,
        public ?string $dbUser = null,
        public ?string $dbPassword = null
    ) {
    }

    /**
     * Convert environment input into the typed configuration object.
     *
     * @param array<string, mixed>|null $environment Optional input used by tests.
     */
    public static function fromEnvironment(?array $environment = null): self
    {
        $read = static function (string $name) use ($environment): ?string {
            if ($environment === null) {
                $value = getenv($name);
                return $value === false ? null : $value;
            }

            if (!array_key_exists($name, $environment)) {
                return null;
            }

            $value = $environment[$name];
            if (!is_string($value)) {
                throw new InvalidArgumentException($name . ' must be provided as a string.');
            }

            return $value;
        };

        return new self(
            appUrl: $read('APP_URL'),
            appEnv: $read('APP_ENV'),
            dbHost: $read('DB_HOST'),
            dbPort: $read('DB_PORT'),
            dbName: $read('DB_NAME'),
            dbUser: $read('DB_USER'),
            dbPassword: $read('DB_PASSWORD')
        );
    }

    public function hasAny(): bool
    {
        return $this->appUrl !== null
            || $this->appEnv !== null
            || $this->dbHost !== null
            || $this->dbPort !== null
            || $this->dbName !== null
            || $this->dbUser !== null
            || $this->dbPassword !== null;
    }

    public function hasRequiredEnvironment(): bool
    {
        return $this->appUrl !== null
            && $this->dbHost !== null
            && $this->dbName !== null
            && $this->dbUser !== null
            && $this->dbPassword !== null;
    }

    /**
     * @return list<string>
     */
    public function missingRequiredVariables(): array
    {
        $missing = [];
        if ($this->appUrl === null) {
            $missing[] = 'APP_URL';
        }
        if ($this->dbHost === null) {
            $missing[] = 'DB_HOST';
        }
        if ($this->dbName === null) {
            $missing[] = 'DB_NAME';
        }
        if ($this->dbUser === null) {
            $missing[] = 'DB_USER';
        }
        if ($this->dbPassword === null) {
            $missing[] = 'DB_PASSWORD';
        }

        return $missing;
    }

    /**
     * @return list<string>
     */
    public function presentVariableNames(): array
    {
        $present = [];
        if ($this->appUrl !== null) {
            $present[] = 'APP_URL';
        }
        if ($this->appEnv !== null) {
            $present[] = 'APP_ENV';
        }
        if ($this->dbHost !== null) {
            $present[] = 'DB_HOST';
        }
        if ($this->dbPort !== null) {
            $present[] = 'DB_PORT';
        }
        if ($this->dbName !== null) {
            $present[] = 'DB_NAME';
        }
        if ($this->dbUser !== null) {
            $present[] = 'DB_USER';
        }
        if ($this->dbPassword !== null) {
            $present[] = 'DB_PASSWORD';
        }

        return $present;
    }
}
