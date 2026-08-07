# Mautic Zender Cron Scheduling

## Native Mautic broadcast scheduler

The existing `paellas` cron executes:

```bash
php /home/paellas/public_html/marketautomation/bin/console mautic:broadcasts:send
```

It processes eligible Mautic broadcasts, including Segment Text Messages.
MauticZenderBundle does not duplicate or replace this native scheduler.

## Plugin-owned controlled Zender dispatcher

MauticZenderBundle owns only:

```bash
plugins/MauticZenderBundle/Resources/bin/cron-dispatch-zender.sh
```

The cPanel cron wakes the shell runner every minute. The shell runner keeps the
non-blocking `flock`, so a still-running dispatch cycle causes the next wakeup
to exit safely instead of overlapping.

The PHP runner no longer uses a global last-success epoch, global pacing sleep,
or a rigid five-message limit. Each wakeup invokes:

```bash
php bin/console 7cats:zender:dispatch --execute
```

The dispatch command derives the cycle from distinct live eligible Zender IDs
present in the queue. It selects at most one message per eligible ID, while the
per-ID cooldown, account quota, global quota, operating window, and atomic
claim constraints remain authoritative.

`PROCESS_TIMEOUT_SECONDS=3600` is only a process safety ceiling. It does not
pace messages and does not cap the number of participating Zender IDs. The
provider transport retains its own per-request timeout.

Diagnostic probe mode calls `cron-dispatch-zender.php --probe` directly. Probe
mode exits before loading Mautic or Symfony, performs no queue mutation, and
makes no provider request.

No standalone Mautic Zender systemd timer or service is required.
