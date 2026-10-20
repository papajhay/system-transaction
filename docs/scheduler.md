# Pending transfer Scheduler

This project uses Symfony Scheduler 7.4 to run a read-only pending-transfer monitor. Every five minutes it finds transfers whose status is still `pending` and whose `createdAt` is older than `TRANSFER_PENDING_THRESHOLD_SECONDS`. It logs the count and never executes transfers, changes statuses, or changes account balances.

## Installation and configuration

Install dependencies with:

```bash
composer install
```

The required dependency is `symfony/scheduler: 7.4.*`; Messenger is already provided by `symfony/doctrine-messenger`. The default threshold is 3,600 seconds (one hour):

```dotenv
TRANSFER_PENDING_THRESHOLD_SECONDS=3600
```

Set this to a positive integer in the environment used by the worker. The service validates the value during construction and refuses zero or negative values.

## Run locally

The provider is named `default`, so Symfony 7.4 generates the `scheduler_default` receiver:

```bash
php bin/console debug:messenger
php bin/console messenger:consume scheduler_default -vv
```

The worker uses the existing Messenger bus and database transport dependencies. It is independent from the existing `app:deposit:execute`, `app:withdrawal:execute`, and `app:transfer:execute` commands, which remain manual and unchanged.

## Production operation

Run one dedicated worker process per application deployment and supervise it with systemd, Supervisor, or the platform’s process manager. Configure a graceful restart/deploy hook so the worker receives a stop signal and is restarted with the new release. Monitor stderr/application logs and process health; Scheduler failures are logged and contained by the handler so a failed read does not prevent later scheduled runs.

Do not run this worker as a replacement for transaction execution. It is intentionally an alerting/monitoring task only.
