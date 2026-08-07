<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

const ROOT = '/home/paellas/public_html/marketautomation';
const PHP_BIN = '/opt/cpanel/ea-php82/root/usr/bin/php';
const PROCESS_TIMEOUT_SECONDS = 180.0;

function out(string $key, string|int|bool $value): void
{
    if (is_bool($value)) {
        $value = $value ? '1' : '0';
    }

    echo $key.'='.$value.PHP_EOL;
}

function failNow(string $reason): never
{
    out('RECEIVED_IMPORT_CRON_ERROR', hash('sha256', $reason));
    out('PROVIDER_MUTATIONS', 0);
    out('MESSAGES_SENT', 0);
    exit(1);
}

$probe = in_array('--probe', $argv, true);

if (!is_file(ROOT.'/vendor/autoload.php')) {
    failNow('vendor_autoload_missing');
}

if (!is_file(ROOT.'/bin/console')) {
    failNow('mautic_console_missing');
}

require ROOT.'/vendor/autoload.php';

if (!class_exists(Process::class)) {
    failNow('symfony_process_unavailable');
}

$environment = [
    'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
    'HOME' => '/home/paellas',
    'USER' => 'paellas',
    'LOGNAME' => 'paellas',
    'LC_ALL' => 'C',
];

if ($probe) {
    $process = new Process(
        [
            PHP_BIN,
            ROOT.'/bin/console',
            'list',
            '--env=prod',
            '--no-debug',
            '--no-interaction',
            '--no-ansi',
        ],
        ROOT,
        $environment,
        null,
        PROCESS_TIMEOUT_SECONDS
    );

    $exitCode = $process->run();

    if (
        0 !== $exitCode
        || !str_contains(
            $process->getOutput(),
            '7cats:zender:import:received'
        )
    ) {
        failNow('received_import_command_not_registered');
    }

    out('RECEIVED_IMPORT_CRON_PROBE', 'PASS');
    out('PROBE_PROVIDER_READ_REQUESTS', 0);
    out('PROBE_DATABASE_WRITES', 0);
    out('PROBE_MESSAGES_SENT', 0);
    out('PROVIDER_MUTATIONS', 0);
    out('MESSAGES_SENT', 0);
    exit(0);
}

$command = [
    PHP_BIN,
    ROOT.'/bin/console',
    '7cats:zender:import:received',
    '--execute',
    '--limit=50',
    '--max-pages=5',
    '--env=prod',
    '--no-debug',
    '--no-interaction',
    '--no-ansi',
];

out('RECEIVED_IMPORT_CRON_MODE', 'execute');
out('RECEIVED_IMPORT_CRON_LIMIT', 50);
out('RECEIVED_IMPORT_CRON_MAX_PAGES', 5);
out('RECEIVED_IMPORT_CRON_STARTED', true);

$process = new Process(
    $command,
    ROOT,
    $environment,
    null,
    PROCESS_TIMEOUT_SECONDS
);

$exitCode = $process->run(
    static function (string $type, string $buffer): void {
        if (Process::ERR === $type) {
            fwrite(STDERR, $buffer);

            return;
        }

        echo $buffer;
    }
);

out('RECEIVED_IMPORT_CRON_EXIT_CODE', $exitCode);
out('PROVIDER_MUTATIONS', 0);
out('MESSAGES_SENT', 0);

if (0 !== $exitCode) {
    exit($exitCode);
}

out('RECEIVED_IMPORT_CRON_COMPLETED', true);
