<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

const ROOT = '/home/paellas/public_html/marketautomation';
const PHP_BIN = '/opt/cpanel/ea-php82/root/usr/bin/php';
const PROCESS_TIMEOUT_SECONDS = 3600.0;

function out(string $key, mixed $value): void
{
    if (is_bool($value)) {
        $value = $value ? '1' : '0';
    }

    echo $key.'='.$value.PHP_EOL;
}

function failNow(string $message): never
{
    fwrite(STDERR, 'MAUTIC_ZENDER_CRON_ERROR='.$message.PHP_EOL);
    exit(1);
}

$probe = in_array('--probe', $argv, true);

out('MAUTIC_ZENDER_CRON_MODE', $probe ? 'probe' : 'dispatch');
out('MAUTIC_ZENDER_CRON_QUEUE_DRIVEN_WAKEUP', true);
out('MAUTIC_ZENDER_CRON_GLOBAL_EPOCH_AUTHORITY', false);
out('MAUTIC_ZENDER_CRON_EXPLICIT_LIMIT', false);
out('MAUTIC_ZENDER_CRON_PARENT_KERNEL_BOOTS', 0);
out('MAUTIC_ZENDER_CRON_PROCESS_TIMEOUT_SECONDS', (int) PROCESS_TIMEOUT_SECONDS);

if ($probe) {
    out('MAUTIC_ZENDER_CRON_PROBE', 'PASS');
    out('MESSAGES_SENT', 0);
    exit(0);
}

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

$command = [
    PHP_BIN,
    ROOT.'/bin/console',
    '7cats:zender:dispatch',
    '--execute',
    '--no-interaction',
    '--no-debug',
];

out('MAUTIC_ZENDER_CRON_SELECTION_AUTHORITY', 'distinct_live_eligible_zender_ids');
out('MAUTIC_ZENDER_CRON_DISPATCH_LIMIT_ARGUMENT', 'absent');
out('MAUTIC_ZENDER_CRON_DISPATCH_STARTED', true);

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

out('MAUTIC_ZENDER_CRON_DISPATCH_EXIT_CODE', $exitCode);

if (0 !== $exitCode) {
    exit($exitCode);
}

out('MAUTIC_ZENDER_CRON_COMPLETED', true);
