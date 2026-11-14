<?php

/**
 * Resolve application configuration from environment variables and the legacy
 * config.php file. Environment variables always take precedence when present.
 */
final class EnvironmentConfiguration
{
    private const ENVIRONMENT_VARIABLES = [
        'APP_URL',
        'APP_ENV',
        'DB_HOST',
        'DB_PORT',
        'DB_NAME',
        'DB_USER',
        'DB_PASSWORD',
    ];

    private const REQUIRED_ENVIRONMENT_VARIABLES = [
        'APP_URL',
        'DB_HOST',
        'DB_NAME',
        'DB_USER',
        'DB_PASSWORD',
    ];

    /**
     * Return only supported variables that are actually present.
     * An empty string remains present and is validated separately.
     */
    public static function readEnvironment(?array $environment = null): array
    {
        $values = [];

        foreach (self::ENVIRONMENT_VARIABLES as $name) {
            if ($environment !== null) {
                if (array_key_exists($name, $environment)) {
                    $values[$name] = (string) $environment[$name];
                }
                continue;
            }

            $value = getenv($name);
            if ($value !== false) {
                $values[$name] = $value;
            }
        }

        return $values;
    }

    public static function hasRequiredEnvironment(array $environment): bool
    {
        foreach (self::REQUIRED_ENVIRONMENT_VARIABLES as $name) {
            if (!array_key_exists($name, $environment)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve without loading files or connecting to a database. Kept pure so
     * configuration behavior can be tested without application infrastructure.
     */
    public static function resolve(array $legacy, array $environment, bool $configExists): array
    {
        $environment = self::readEnvironment($environment);
        $environmentOnly = !$configExists;
        self::validateEnvironment($environment, $environmentOnly);

        $globals = $legacy;

        $appUrl = self::value($environment, 'APP_URL', $legacy, 'APP_URL');
        if ($appUrl !== null) {
            $globals['APP_URL'] = $appUrl;
        }

        if (array_key_exists('APP_ENV', $environment)) {
            $globals['_app_stage'] = strtolower(trim($environment['APP_ENV'])) === 'production' ? 'Live' : 'Dev';
        } elseif ($environmentOnly) {
            $globals['_app_stage'] = 'Live';
        }

        $databaseMappings = [
            'DB_HOST' => 'db_host',
            'DB_NAME' => 'db_name',
            'DB_USER' => 'db_user',
        ];
        foreach ($databaseMappings as $environmentName => $legacyName) {
            $value = self::value($environment, $environmentName, $legacy, $legacyName);
            if ($value !== null) {
                $globals[$legacyName] = $value;
            }
        }

        if (array_key_exists('DB_PASSWORD', $environment)) {
            // Set both names used by current and older integrations. Empty is
            // intentional here and must not fall back to config.php.
            $globals['db_pass'] = $environment['DB_PASSWORD'];
            $globals['db_password'] = $environment['DB_PASSWORD'];
        }

        $databaseDsnPort = null;
        if (array_key_exists('DB_PORT', $environment)) {
            $databaseDsnPort = self::validatePort($environment['DB_PORT']);
            $globals['db_port'] = (string) $databaseDsnPort;
        } elseif ($environmentOnly) {
            $databaseDsnPort = 3306;
            $globals['db_port'] = '3306';
        }

        $mode = $environmentOnly ? 'environment' : (empty($environment) ? 'config' : 'mixed');

        return [
            'globals' => $globals,
            'mode' => $mode,
            'overrides' => array_keys($environment),
            'database_dsn_port' => $databaseDsnPort,
            'apply_error_display_policy' => array_key_exists('APP_ENV', $environment) || $environmentOnly,
        ];
    }

    /**
     * Load legacy config.php in an isolated scope, then publish resolved values
     * into the global scope expected by the application.
     */
    public static function bootstrap(string $configPath, ?array $environment = null): array
    {
        $environment = self::readEnvironment($environment);
        $configExists = is_file($configPath);

        if (!$configExists && !self::hasRequiredEnvironment($environment)) {
            self::validateEnvironment($environment, true);
        }
        self::validateEnvironment($environment, !$configExists);

        $appUrlWasPredefined = false;
        if (array_key_exists('APP_URL', $environment)) {
            if (defined('APP_URL') && constant('APP_URL') !== trim($environment['APP_URL'])) {
                throw new InvalidArgumentException('APP_URL was defined before environment configuration was loaded.');
            }
            if (!defined('APP_URL')) {
                define('APP_URL', trim($environment['APP_URL']));
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
        foreach ($resolved['globals'] as $name => $value) {
            $GLOBALS[$name] = $value;
        }

        if ($resolved['apply_error_display_policy']) {
            self::applyErrorDisplayPolicy($resolved['globals']['_app_stage'] ?? 'Live');
        }

        if (isset($resolved['globals']['APP_URL']) && !defined('APP_URL')) {
            define('APP_URL', $resolved['globals']['APP_URL']);
        }

        unset($GLOBALS['APP_URL']);
        return [
            'mode' => $resolved['mode'],
            'overrides' => $resolved['overrides'],
            'database_dsn_port' => $resolved['database_dsn_port'],
        ];
    }

    public static function mysqlDsn(string $host, string $database, ?int $port = null): string
    {
        $dsn = 'mysql:host=' . $host;
        if ($port !== null) {
            $dsn .= ';port=' . $port;
        }

        return $dsn . ';dbname=' . $database;
    }

    private static function includeLegacyConfiguration(string $configPath, bool $appUrlWasPredefined): array
    {
        if ($appUrlWasPredefined) {
            set_error_handler(static function ($severity, $message) {
                if (($severity & E_WARNING) && strpos($message, 'Constant APP_URL already defined') !== false) {
                    return true;
                }
                return false;
            });
        }

        try {
            return (static function ($file) {
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

    private static function value(array $environment, string $environmentName, array $legacy, string $legacyName)
    {
        if (array_key_exists($environmentName, $environment)) {
            return self::normalizeEnvironmentValue($environmentName, $environment[$environmentName]);
        }

        return array_key_exists($legacyName, $legacy) ? $legacy[$legacyName] : null;
    }

    private static function normalizeEnvironmentValue(string $name, string $value): string
    {
        return $name === 'DB_PASSWORD' ? $value : trim($value);
    }

    private static function validateEnvironment(array $environment, bool $environmentOnly): void
    {
        if ($environmentOnly) {
            $missing = [];
            foreach (self::REQUIRED_ENVIRONMENT_VARIABLES as $name) {
                if (!array_key_exists($name, $environment)) {
                    $missing[] = $name;
                }
            }
            if ($missing) {
                throw new InvalidArgumentException('Missing required environment variables: ' . implode(', ', $missing) . '.');
            }
        }

        foreach ($environment as $name => $value) {
            switch ($name) {
                case 'APP_URL':
                    self::validateApplicationUrl($value);
                    break;
                case 'APP_ENV':
                    $environmentName = strtolower(trim($value));
                    if (!in_array($environmentName, ['production', 'development'], true)) {
                        throw new InvalidArgumentException('APP_ENV must be production or development.');
                    }
                    break;
                case 'DB_HOST':
                    self::validateDsnPart($name, $value);
                    break;
                case 'DB_NAME':
                    self::validateDsnPart($name, $value);
                    break;
                case 'DB_USER':
                    if (trim($value) === '') {
                        throw new InvalidArgumentException('DB_USER must not be empty.');
                    }
                    break;
                case 'DB_PORT':
                    self::validatePort($value);
                    break;
                case 'DB_PASSWORD':
                    // Empty passwords are allowed when explicitly configured.
                    break;
            }
        }

        if ($environmentOnly) {
            foreach (['DB_HOST', 'DB_NAME', 'DB_USER'] as $name) {
                if (trim($environment[$name]) === '') {
                    throw new InvalidArgumentException($name . ' must not be empty.');
                }
            }
        }
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
