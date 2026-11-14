<?php

declare(strict_types=1);

require_once __DIR__ . '/EnvironmentVariables.php';
require_once __DIR__ . '/EnvironmentConfigurationMode.php';
require_once __DIR__ . '/ResolvedEnvironmentConfiguration.php';
require_once __DIR__ . '/EnvironmentConfigurationStatus.php';

/**
 * Resolve application configuration from environment variables and the legacy
 * config.php file. Environment variables always take precedence when present.
 */
final class EnvironmentConfiguration
{
    /**
     * Read supported process variables or convert a provided environment boundary.
     *
     * @param array<string, mixed>|null $environment Optional input used by tests.
     */
    public static function readEnvironment(?array $environment = null): EnvironmentVariables
    {
        return EnvironmentVariables::fromEnvironment($environment);
    }

    public static function hasRequiredEnvironment(EnvironmentVariables $environment): bool
    {
        return $environment->hasRequiredEnvironment();
    }

    /**
     * Resolve without loading files or connecting to a database. Kept pure so
     * configuration behavior can be tested without application infrastructure.
     *
     * @param array<string, mixed> $legacy Dynamic values captured from config.php.
     */
    public static function resolve(array $legacy, EnvironmentVariables $environment, bool $configExists): ResolvedEnvironmentConfiguration
    {
        $environmentOnly = !$configExists;
        self::validateEnvironment($environment, $environmentOnly);

        $globals = $legacy;

        $appUrl = self::value($environment->appUrl, $legacy, 'APP_URL');
        if ($appUrl !== null) {
            $globals['APP_URL'] = $appUrl;
        }

        if ($environment->appEnv !== null) {
            $globals['_app_stage'] = strtolower(trim($environment->appEnv)) === 'production' ? 'Live' : 'Dev';
        } elseif ($environmentOnly) {
            $globals['_app_stage'] = 'Live';
        }

        $dbHost = self::value($environment->dbHost, $legacy, 'db_host');
        if ($dbHost !== null) {
            $globals['db_host'] = $dbHost;
        }
        $dbName = self::value($environment->dbName, $legacy, 'db_name');
        if ($dbName !== null) {
            $globals['db_name'] = $dbName;
        }
        $dbUser = self::value($environment->dbUser, $legacy, 'db_user');
        if ($dbUser !== null) {
            $globals['db_user'] = $dbUser;
        }

        if ($environment->dbPassword !== null) {
            // Set both names used by current and older integrations. Empty is
            // intentional here and must not fall back to config.php.
            $globals['db_pass'] = $environment->dbPassword;
            $globals['db_password'] = $environment->dbPassword;
        }

        $databaseDsnPort = null;
        if ($environment->dbPort !== null) {
            $databaseDsnPort = self::validatePort($environment->dbPort);
            $globals['db_port'] = (string) $databaseDsnPort;
        } elseif ($environmentOnly) {
            $databaseDsnPort = 3306;
            $globals['db_port'] = '3306';
        }

        $mode = $environmentOnly
            ? EnvironmentConfigurationMode::ENVIRONMENT
            : ($environment->hasAny() ? EnvironmentConfigurationMode::MIXED : EnvironmentConfigurationMode::CONFIG);

        return new ResolvedEnvironmentConfiguration(
            globals: $globals,
            mode: $mode,
            overrides: $environment->presentVariableNames(),
            databaseDsnPort: $databaseDsnPort,
            applyErrorDisplayPolicy: $environment->appEnv !== null || $environmentOnly
        );
    }

    /**
     * Load legacy config.php in an isolated scope, then publish resolved values
     * into the global scope expected by the application.
     *
     * @return EnvironmentConfigurationStatus Metadata without resolved credentials.
     */
    public static function bootstrap(string $configPath, ?EnvironmentVariables $environment = null): EnvironmentConfigurationStatus
    {
        $environment ??= self::readEnvironment();
        $configExists = is_file($configPath);

        if (!$configExists && !self::hasRequiredEnvironment($environment)) {
            self::validateEnvironment($environment, true);
        }
        self::validateEnvironment($environment, !$configExists);

        $appUrlWasPredefined = false;
        if ($environment->appUrl !== null) {
            if (defined('APP_URL') && constant('APP_URL') !== trim($environment->appUrl)) {
                throw new InvalidArgumentException('APP_URL was defined before environment configuration was loaded.');
            }
            if (!defined('APP_URL')) {
                define('APP_URL', trim($environment->appUrl));
                $appUrlWasPredefined = true;
            }
        }

        $legacy = [];
        if ($configExists) {
            $legacy = self::includeLegacyConfiguration($configPath, $appUrlWasPredefined);
            if (defined('APP_URL')) {
                $legacy['APP_URL'] = constant('APP_URL');
            }
        }

        $resolved = self::resolve($legacy, $environment, $configExists);
        foreach ($resolved->globals as $name => $value) {
            $GLOBALS[$name] = $value;
        }

        if ($resolved->applyErrorDisplayPolicy) {
            self::applyErrorDisplayPolicy($resolved->globals['_app_stage'] ?? 'Live');
        }

        if (isset($resolved->globals['APP_URL']) && !defined('APP_URL')) {
            define('APP_URL', $resolved->globals['APP_URL']);
        }

        unset($GLOBALS['APP_URL']);
        return new EnvironmentConfigurationStatus(
            mode: $resolved->mode,
            overrides: $resolved->overrides,
            databaseDsnPort: $resolved->databaseDsnPort
        );
    }

    public static function mysqlDsn(?string $host, ?string $database, ?int $port = null): string
    {
        $dsn = 'mysql:host=' . ($host ?? '');
        if ($port !== null) {
            $dsn .= ';port=' . $port;
        }

        return $dsn . ';dbname=' . ($database ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    private static function includeLegacyConfiguration(string $configPath, bool $appUrlWasPredefined): array
    {
        if ($appUrlWasPredefined) {
            set_error_handler(static function (int $severity, string $message): bool {
                if (($severity & E_WARNING) && strpos($message, 'Constant APP_URL already defined') !== false) {
                    return true;
                }
                return false;
            });
        }

        try {
            return (static function (string $file): array {
                require $file;
                $variables = get_defined_vars();
                unset($variables['file']);
                return $variables;
            })($configPath);
        } finally {
            if ($appUrlWasPredefined) {
                restore_error_handler();
            }
        }
    }

    /**
     * @param array<string, mixed> $legacy
     * @return string|null
     */
    private static function value(?string $environmentValue, array $legacy, string $legacyName): ?string
    {
        if ($environmentValue !== null) {
            return trim($environmentValue);
        }

        if (!array_key_exists($legacyName, $legacy) || $legacy[$legacyName] === null) {
            return null;
        }

        if (!is_string($legacy[$legacyName])) {
            throw new InvalidArgumentException($legacyName . ' in config.php must be a string or null.');
        }

        return $legacy[$legacyName];
    }

    private static function validateEnvironment(EnvironmentVariables $environment, bool $environmentOnly): void
    {
        if ($environmentOnly) {
            $missing = $environment->missingRequiredVariables();
            if ($missing) {
                throw new InvalidArgumentException('Missing required environment variables: ' . implode(', ', $missing) . '.');
            }
        }

        if ($environment->appUrl !== null) {
            self::validateApplicationUrl($environment->appUrl);
        }
        if ($environment->appEnv !== null) {
            $appEnv = strtolower(trim($environment->appEnv));
            if (!in_array($appEnv, ['production', 'development'], true)) {
                throw new InvalidArgumentException('APP_ENV must be production or development.');
            }
        }
        if ($environment->dbHost !== null) {
            self::validateDsnPart('DB_HOST', $environment->dbHost);
        }
        if ($environment->dbName !== null) {
            self::validateDsnPart('DB_NAME', $environment->dbName);
        }
        if ($environment->dbUser !== null && trim($environment->dbUser) === '') {
            throw new InvalidArgumentException('DB_USER must not be empty.');
        }
        if ($environment->dbPort !== null) {
            self::validatePort($environment->dbPort);
        }
        // Empty DB_PASSWORD remains valid for passwordless database accounts.
    }

    private static function validateApplicationUrl(string $value): void
    {
        $url = trim($value);
        if ($url === '' || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new InvalidArgumentException('APP_URL must be an absolute HTTP or HTTPS URL.');
        }

        $parts = parse_url($url);
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535))) {
            throw new InvalidArgumentException('APP_URL must be an absolute HTTP or HTTPS URL without credentials, query, or fragment.');
        }
    }

    private static function validateDsnPart(string $name, string $value): void
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException($name . ' must not be empty.');
        }
        if (preg_match('/[;\x00-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException($name . ' contains unsupported characters.');
        }
    }

    private static function validatePort(string $value): int
    {
        $port = trim($value);
        if ($port === '' || !ctype_digit($port)) {
            throw new InvalidArgumentException('DB_PORT must be an integer between 1 and 65535.');
        }

        $number = (int) $port;
        if ($number < 1 || $number > 65535) {
            throw new InvalidArgumentException('DB_PORT must be an integer between 1 and 65535.');
        }

        return $number;
    }

    private static function applyErrorDisplayPolicy(string $stage): void
    {
        error_reporting(E_ERROR);
        $displayErrors = $stage !== 'Live' ? '1' : '0';
        ini_set('display_errors', $displayErrors);
        ini_set('display_startup_errors', $displayErrors);
    }
}
