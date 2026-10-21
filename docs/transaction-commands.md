# Manual transaction execution commands

Each command executes exactly one pending transaction. Select it with exactly one
of the legacy positional identifier, `--reference` (`-r`), or `--token` (`-k`):

```bash
php bin/console app:deposit:execute --reference=DEP-2026-001
php bin/console app:withdrawal:execute --token=withdrawal-token
php bin/console app:transfer:execute -r TRF-2026-001 --time=30
```

`--time` (`-t`) is an optional non-negative integer delay in seconds. If it is
omitted, execution remains immediate. Combining selection methods, supplying
an empty selector, or providing a negative/non-integer delay is rejected before
the executor is called.
