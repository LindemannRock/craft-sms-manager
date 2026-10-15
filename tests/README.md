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

### MPP transport isolation and shared admission

`TransportIsolation` replaces Craft's Guzzle client factory before enabled plugins bootstrap. Only an explicitly installed `MockHandler` can handle a request; every intercepted request is counted. A missing/non-mock handler terminates the test process with exit 90 before transport, so provider exception handling cannot swallow a forbidden fallback. A subprocess regression verifies this boundary. Fixture installation and archive bootstrap also deny HTTP; their expected Craft license refresh is intercepted and rejected locally. No SMS provider request or queue-worker command is part of these checks.

MPP guard/release tables join the connection-local empty shadows. Concurrency tests additionally require the runner-owned `sms_manager_qg_<random>` database, use a separate connection and independent PHP process against its real guard tables, synchronize through pipes, and kill the admitted process to prove durable fail-closed recovery. They delete exact fixture-owned keys and close processes/connections in failure cleanup. No client SQL package is executed by tests. Timed recovery uses an overridden clock, with no sleeping or provider recovery probe.
