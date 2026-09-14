# SMS Manager test architecture

The suite is organized by supported SMS Manager behavior. Permanent names describe the contract under test rather than the work item that introduced the coverage.

## Directory responsibilities

- `Integration/` contains the complete PHPUnit behavioral suite.
- `Fixtures/` contains deterministic vectors and direct-only disposable-project entry points.
- `Support/` owns shared lifecycle infrastructure, including exact database, grant, queue-shadow, process, and filesystem cleanup.
- `Stubs/` contains narrowly scoped provider and bootstrap doubles.
- `js/` contains encoding, hook, and quality-orchestration behavior tests.

## Ownership and cleanup

Normal workspace tests hide queue, settings, log, and analytics persistence behind connection-local temporary tables. Provider and sender rows are recorded by exact primary key and removed in foreign-key order. The complete package gate runs PHPUnit in a unique MySQL database and temporary Craft project; the runner records its database, grant, project path, and child process before use and removes only those exact resources on success, failure, `HUP`, `INT`, and `TERM`.

Tests never truncate owner tables, flush shared caches or Redis, drain persistent queues, use owner browser sessions, or delete rows by broad prefixes. An operational failure remains the returned failure if cleanup also fails, while cleanup-only failures remain nonzero.

## Package authority

`composer ci` is the fast read-only PHPStan and ECS check. `composer ci:full` adds the complete PHPUnit suite. `composer quality-gate` is the package-owned authority used by CI and Act; it covers Composer validation and audit, static/style checks, test conventions, disposable PHPUnit, JavaScript encoding vectors, generated asset parity, customer archive installation/bootstrap, the pre-commit contract, and orchestration failure propagation. PHP compatibility matrices, PostgreSQL runtime checks, real-browser smoke, and live-provider tests remain separate release authorities.
