# Pending transfer Scheduler

This project uses Symfony Scheduler 7.4 for scheduled transaction execution and reporting:

- Every 10 seconds, it finds transfers whose status is still `pending` and whose `createdAt` is at least `TRANSFER_PENDING_THRESHOLD_SECONDS` seconds old, then dispatches each eligible transfer to its existing execution service.
- Every day at `DAILY_REPORT_TIME`, it writes a CSV summary for the previous calendar day in `REPORT_TIMEZONE`.

The automatic execution reuses the existing deposit, withdrawal, and account-transfer execution services. Their status, expiration, account, fee, balance, transaction, locking, and duplicate-execution checks remain authoritative.

## Installation and configuration

Install dependencies with:

```bash
composer install
```

The required dependencies are `symfony/scheduler: 7.4.*` and `dragonmantank/cron-expression` for the daily cron trigger; Messenger and Mailer are already installed. The default threshold is 60 seconds:

```dotenv
TRANSFER_PENDING_THRESHOLD_SECONDS=60
PENDING_TRANSFER_ALERT_RECIPIENTS=ops@example.com
PENDING_TRANSFER_ALERT_COOLDOWN_SECONDS=86400
REPORT_FROM_ADDRESS=transactions@example.com
REPORT_OUTPUT_DIRECTORY=var/reports
REPORT_TIMEZONE=UTC
DAILY_REPORT_TIME=02:00
```

Set the threshold and cooldown to positive integers. Empty `PENDING_TRANSFER_ALERT_RECIPIENTS` disables email alerts while monitoring and logging continue. Recipients are comma-separated. The default alert policy sends one alert for a transfer, then suppresses that transfer for 24 hours while it remains unresolved; after the cooldown, it can be reported again. A failed email is logged and is not marked as delivered, so a later run can retry it.

Reports are grouped by transfer type and source currency. Amounts are the persisted transfer amounts and fees are the persisted `Transfer.feeAmount`, counted once per completed transfer. Cross-currency transfers are not converted or combined with another currency. Reports are written outside the public directory with `0600` file permissions, using safe names such as `transactions_2026-10-20.csv`.

## Run locally

The provider is named `default`, so Symfony 7.4 generates the `scheduler_default` receiver:

```bash
php bin/console debug:messenger
php bin/console debug:scheduler default
php bin/console messenger:consume scheduler_default -vv
```

The worker uses the existing Messenger bus and database transport dependencies. It is independent from the existing `app:deposit:execute`, `app:withdrawal:execute`, and `app:transfer:execute` commands, which remain available for manual execution.

To run the relevant tests:

```bash
php bin/phpunit tests/Unit/Scheduler
```

## Production operation

Run one dedicated worker process per application deployment and supervise it with systemd, Supervisor, or the platform’s process manager. Configure a graceful restart/deploy hook so the worker receives a stop signal and is restarted with the new release. Monitor stderr/application logs and process health; monitoring, alert delivery, and report failures are logged and contained by their handlers so a failed run does not prevent later scheduled runs.

The worker is the automatic transaction-execution path for eligible pending transfers; run one dedicated `scheduler_default` worker per application deployment.
