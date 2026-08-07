<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

const ROOT = '/home/paellas/public_html/marketautomation';
const PHP_BIN = '/opt/cpanel/ea-php82/root/usr/bin/php';
const PROCESS_TIMEOUT_SECONDS = 300.0;

function out(string $key, mixed $value): void
{
    if (is_bool($value)) {
        $value = $value ? '1' : '0';
    }

    echo $key.'='.$value.PHP_EOL;
}

function failNow(string $message): never
{
    fwrite(STDERR, 'MAUTIC_ZENDER_STATUS_CRON_ERROR='.$message.PHP_EOL);
    exit(1);
}

$probe = in_array('--probe', $argv, true);

out('MAUTIC_ZENDER_STATUS_CRON_MODE', $probe ? 'probe' : 'execute');
out('PROVIDER_MUTATIONS', 0);
out('MESSAGES_SENT', 0);

if ($probe) {
    out('MAUTIC_ZENDER_STATUS_CRON_PROBE', 'PASS');
    exit(0);
}

if (!is_file(ROOT.'/vendor/autoload.php')) {
    failNow('vendor_autoload_missing');
}

require ROOT.'/vendor/autoload.php';

if (!class_exists(Process::class)) {
    failNow('symfony_process_unavailable');
}

$command = [
    PHP_BIN,
    ROOT.'/bin/console',
    '7cats:zender:sync:provider-status',
    '--execute',
    '--limit=2000',
    '--max-pages=20',
    '--no-interaction',
    '--no-debug',
];

$process = new Process(
    $command,
    ROOT,
    [
        'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        'HOME' => '/home/paellas',
        'USER' => 'paellas',
        'LOGNAME' => 'paellas',
        'LC_ALL' => 'C',
    ],
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

out('MAUTIC_ZENDER_STATUS_CRON_EXIT_CODE', $exitCode);

if (0 !== $exitCode) {
    exit($exitCode);
}

out('MAUTIC_ZENDER_STATUS_CRON_COMPLETED', true);
