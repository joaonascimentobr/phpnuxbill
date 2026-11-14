<?php

require_once __DIR__ . '/../system/EnvironmentConfiguration.php';

if (($argv[1] ?? '') === '--bootstrap-probe') {
    $scenario = $argv[2] ?? '';
    if ($scenario === 'legacy') {
        $configuration = EnvironmentConfiguration::bootstrap(__DIR__ . '/fixtures/legacy-config.php', []);
    } elseif ($scenario === 'mixed') {
        $configuration = EnvironmentConfiguration::bootstrap(__DIR__ . '/fixtures/legacy-config.php', [
            'APP_URL' => 'https://override.example.invalid',
            'APP_ENV' => 'development',
            'DB_PASSWORD' => '',
            'DB_PORT' => '3307',
        ]);
    } elseif ($scenario === 'environment') {
        $configuration = EnvironmentConfiguration::bootstrap(__DIR__ . '/fixtures/missing-' . getmypid() . '.php', [
            'APP_URL' => 'https://env.example.invalid',
            'DB_HOST' => 'environment-db',
            'DB_NAME' => 'environment-database',
            'DB_USER' => 'environment-user',
            'DB_PASSWORD' => 'probe-secret',
        ]);
    } else {
        fwrite(STDERR, 'Unknown bootstrap test scenario.' . PHP_EOL);
        exit(2);
    }

    echo json_encode([
        'mode' => $configuration['mode'],
        'url' => APP_URL,
        'stage' => $_app_stage,
        'host' => $db_host,
        'database' => $db_name,
        'user' => $db_user,
        'password_is_empty' => $db_pass === '',
        'password_is_environment_value' => $db_pass === 'probe-secret',
        'port' => $configuration['database_dsn_port'],
        'display_errors' => ini_get('display_errors'),
        'radius_host' => $radius_host ?? null,
    ], JSON_THROW_ON_ERROR);
    exit(0);
}

$passed = 0;

function checkSame($expected, $actual, string $message): void
{
    global $passed;
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
    $passed++;
}

function checkTrue(bool $actual, string $message): void
{
    checkSame(true, $actual, $message);
}

function expectConfigurationError(callable $callback, string $messageFragment): string
{
    global $passed;
    try {
        $callback();
    } catch (InvalidArgumentException $error) {
        if (strpos($error->getMessage(), $messageFragment) === false) {
            throw new RuntimeException('Unexpected validation message: ' . $error->getMessage());
        }
        $passed++;
        return $error->getMessage();
    }

    throw new RuntimeException('Expected an environment configuration error.');
}

function runBootstrapProbe(string $scenario): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open([PHP_BINARY, __FILE__, '--bootstrap-probe', $scenario], $descriptors, $pipes, __DIR__);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the isolated PHP bootstrap probe.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException('Bootstrap probe failed with exit code ' . $exitCode . '. ' . $stderr);
    }

    $result = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    return $result;
}

// Exercise the actual loader in fresh processes so APP_URL can be independently defined per scenario.
$probe = runBootstrapProbe('legacy');
checkSame('config', $probe['mode'], 'A traditional config.php should load through the bootstrap helper.');
checkSame('https://legacy.example.invalid/billing', $probe['url'], 'The legacy APP_URL constant should be preserved.');
checkSame('legacy-db', $probe['host'], 'The legacy database host should be published globally.');
checkSame('radius-db', $probe['radius_host'], 'Legacy optional RADIUS configuration should be published globally.');

$probe = runBootstrapProbe('mixed');
checkSame('mixed', $probe['mode'], 'Present ENV values should select mixed configuration mode.');
checkSame('https://override.example.invalid', $probe['url'], 'ENV APP_URL should override an unguarded legacy APP_URL constant.');
checkSame('Dev', $probe['stage'], 'APP_ENV development should map to the legacy Dev stage.');
checkSame('1', $probe['display_errors'], 'Development mode should enable displayed PHP errors as the legacy Dev stage does.');
checkTrue($probe['password_is_empty'], 'An empty ENV password should override the legacy password in the bootstrap.');
checkSame(3307, $probe['port'], 'The ENV database port should reach the PDO/ORM configuration.');
checkSame('radius-db', $probe['radius_host'], 'ENV overrides should leave legacy RADIUS settings intact.');

$probe = runBootstrapProbe('environment');
checkSame('environment', $probe['mode'], 'The bootstrap should initialize without config.php when required ENV is complete.');
checkSame('https://env.example.invalid', $probe['url'], 'ENV-only APP_URL should become the application constant.');
checkSame('environment-db', $probe['host'], 'ENV-only DB_HOST should be published globally.');
checkTrue($probe['password_is_environment_value'], 'ENV-only DB_PASSWORD should be published to the application.');
checkSame(3306, $probe['port'], 'ENV-only bootstrap should default DB_PORT to 3306.');
checkSame('0', $probe['display_errors'], 'ENV-only production mode should keep PHP errors hidden.');

$traditional = [
    'APP_URL' => 'https://legacy.example.invalid/billing',
    '_app_stage' => 'Live',
    'db_host' => 'legacy-db',
    'db_port' => '',
    'db_name' => 'legacy_database',
    'db_user' => 'legacy_user',
    'db_pass' => 'legacy-password',
    'radius_host' => 'radius-db',
    'radius_name' => 'radius_database',
    'radius_user' => 'radius_user',
    'radius_pass' => 'radius-password',
];

// Traditional config-only installation keeps all legacy values and DSN behavior.
$resolved = EnvironmentConfiguration::resolve($traditional, [], true);
checkSame('config', $resolved['mode'], 'Config-only mode should be detected.');
checkSame($traditional, $resolved['globals'], 'Config-only values, including optional RADIUS settings, should be unchanged.');
checkSame(null, $resolved['database_dsn_port'], 'Config-only mode should not change the legacy DSN when DB_PORT is absent.');

// Complete ENV-only configuration works without config.php and defaults the port.
$environmentOnly = [
    'APP_URL' => 'https://isp.example.invalid',
    'DB_HOST' => 'mariadb',
    'DB_NAME' => 'phpnuxbill',
    'DB_USER' => 'phpnuxbill',
    'DB_PASSWORD' => 'secret-for-test-only',
];
$resolved = EnvironmentConfiguration::resolve([], $environmentOnly, false);
checkSame('environment', $resolved['mode'], 'Missing config.php should select ENV-only mode.');
checkSame('https://isp.example.invalid', $resolved['globals']['APP_URL'], 'ENV-only APP_URL should be loaded.');
checkSame('mariadb', $resolved['globals']['db_host'], 'ENV-only DB_HOST should be loaded.');
checkSame('secret-for-test-only', $resolved['globals']['db_pass'], 'ENV-only DB_PASSWORD should map to db_pass.');
checkSame('Live', $resolved['globals']['_app_stage'], 'APP_ENV should default to production in ENV-only mode.');
checkSame('3306', $resolved['globals']['db_port'], 'ENV-only DB_PORT should default to 3306.');
checkSame(3306, $resolved['database_dsn_port'], 'The default ENV-only port should be used by PDO/ORM DSNs.');

// Only present variables override config.php; unset values retain legacy values.
$resolved = EnvironmentConfiguration::resolve($traditional, [
    'DB_HOST' => 'override-db',
    'DB_PASSWORD' => 'override-password',
], true);
checkSame('override-db', $resolved['globals']['db_host'], 'Present DB_HOST should override the config file.');
checkSame('legacy_database', $resolved['globals']['db_name'], 'Unset DB_NAME should fall back to config.php.');
checkSame('override-password', $resolved['globals']['db_pass'], 'Present DB_PASSWORD should override the config file.');
checkSame('radius-db', $resolved['globals']['radius_host'], 'Environment overrides must preserve optional RADIUS settings.');
checkSame(null, $resolved['database_dsn_port'], 'Unset DB_PORT should keep the original connection behavior.');

// An explicitly empty password overrides the legacy password rather than falling back.
$resolved = EnvironmentConfiguration::resolve($traditional, ['DB_PASSWORD' => ''], true);
checkSame('', $resolved['globals']['db_pass'], 'An explicitly empty DB_PASSWORD must remain empty.');
checkSame('', $resolved['globals']['db_password'], 'The legacy password alias must also remain explicitly empty.');
checkSame('legacy-password', EnvironmentConfiguration::resolve($traditional, [], true)['globals']['db_pass'], 'An absent DB_PASSWORD should preserve the legacy password.');

// ENV-only mode rejects absent required variables and invalid values without echoing secrets.
$message = expectConfigurationError(
    static function () {
        EnvironmentConfiguration::resolve([], ['APP_URL' => 'https://isp.example.invalid'], false);
    },
    'DB_HOST'
);
checkTrue(strpos($message, 'secret-for-test-only') === false, 'Missing-variable errors must not reveal credential values.');

$badUrlEnvironment = $environmentOnly;
$badUrlEnvironment['APP_URL'] = 'not-a-url';
$message = expectConfigurationError(
    static function () use ($badUrlEnvironment) {
        EnvironmentConfiguration::resolve([], $badUrlEnvironment, false);
    },
    'APP_URL'
);
checkTrue(strpos($message, 'secret-for-test-only') === false, 'URL validation errors must not reveal credentials.');

$badAppEnvironment = $environmentOnly;
$badAppEnvironment['APP_ENV'] = 'staging';
expectConfigurationError(
    static function () use ($badAppEnvironment) {
        EnvironmentConfiguration::resolve([], $badAppEnvironment, false);
    },
    'APP_ENV'
);

$badPortEnvironment = $environmentOnly;
$badPortEnvironment['DB_PORT'] = '65536';
expectConfigurationError(
    static function () use ($badPortEnvironment) {
        EnvironmentConfiguration::resolve([], $badPortEnvironment, false);
    },
    'DB_PORT'
);

expectConfigurationError(
    static function () use ($traditional) {
        EnvironmentConfiguration::resolve($traditional, ['APP_URL' => ''], true);
    },
    'APP_URL'
);
expectConfigurationError(
    static function () use ($traditional) {
        EnvironmentConfiguration::resolve($traditional, ['DB_PORT' => ''], true);
    },
    'DB_PORT'
);
expectConfigurationError(
    static function () use ($environmentOnly) {
        $environment = $environmentOnly;
        $environment['DB_PORT'] = 'mysql';
        EnvironmentConfiguration::resolve([], $environment, false);
    },
    'DB_PORT'
);

checkSame(
    'mysql:host=mariadb;port=3307;dbname=phpnuxbill',
    EnvironmentConfiguration::mysqlDsn('mariadb', 'phpnuxbill', 3307),
    'A validated ENV port should be included in the database DSN.'
);
checkSame(
    'mysql:host=mariadb;dbname=phpnuxbill',
    EnvironmentConfiguration::mysqlDsn('mariadb', 'phpnuxbill'),
    'The legacy DSN should remain unchanged when no environment port applies.'
);

// HTTP and cron entry points share init.php, which delegates to the same loader.
$init = file_get_contents(__DIR__ . '/../init.php');
$boot = file_get_contents(__DIR__ . '/../system/boot.php');
$cron = file_get_contents(__DIR__ . '/../system/cron.php');
$cronReminder = file_get_contents(__DIR__ . '/../system/cron_reminder.php');
checkTrue(strpos($init, 'EnvironmentConfiguration::bootstrap') !== false, 'The shared app bootstrap must load environment configuration.');
checkTrue(strpos($boot, 'init.php') !== false, 'HTTP bootstrap must include init.php.');
checkTrue(strpos($cron, 'init.php') !== false, 'The primary cron script must include init.php.');
checkTrue(strpos($cronReminder, 'init.php') !== false, 'The reminder cron script must include init.php.');

echo 'Environment configuration tests passed: ' . $passed . PHP_EOL;
