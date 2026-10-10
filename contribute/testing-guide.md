# CitOmni Testing Guide

CitOmni packages use **standalone PHP test suites** instead of a shared test framework or a PHPUnit-based umbrella runner. Each package owns its tests, and a single command runs every suite in that package. This keeps test execution explicit, reproducible, and independent of an application skeleton.

This guide defines the testing contract for CitOmni packages using **PHP 8.5 or later**. It covers isolated checks, MySQL/MariaDB integration tests, the aggregate runner, reporting, environment variables, and regression-test expectations.

## Quick start

Run the commands from the root of the package being tested.

```sh
php tests/run.php
```

To run one suite on its own, invoke its script directly.

```sh
php tests/error-handler/run.php
```

This is a real individual suite from `citomni/cli`. For a database suite, the entry point follows the pattern `php tests/<suite>/database.php`. The command syntax works in Windows Command Prompt, PowerShell, and other shells.

There is no PHPUnit installation, `phpunit.xml`, central `app-skeleton` runner, or per-session opt-in required by this convention. Database suites still require the local test database described below.

## Suite layout

Each package keeps its suites under `tests/`. Each suite is an independent executable PHP script, with its own checks, fixtures, and documentation.

```text
tests/
    run.php
    <suite-name>/
        run.php
        README.md
    <database-suite-name>/
        database.php
        README.md
```

The directory names above are illustrative. A suite entry point is one of the following.

| Entry point | Purpose |
| --- | --- |
| `tests/<suite>/run.php` | Isolated checks, including tests that use doubles in place of framework classes. |
| `tests/<suite>/database.php` | Integration checks using a real MySQL or MariaDB server. |
| `tests/run.php` | Package-level runner that discovers and executes all suites. |

Both entry-point names may exist in a suite directory; the aggregate runner treats them as separate executions. There is no suite registration file and no manual suite list to maintain.

### Isolation and bootstrapping

Every suite runs in its **own PHP process**. This matters when tests define constants, register error handlers, or use `class_alias()` to replace framework classes with doubles. These changes must not leak into another suite.

An isolated suite that uses `class_alias()` doubles for framework classes must run without loading the Composer bootstrap first. Otherwise, Composer may load the real class before its double can be registered. Other suites may load their actual dependencies when needed; tests must not bootstrap an application configuration just to obtain test database credentials.

All suite entry points are CLI-only. Use this guard at the start of each script.

```php
if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}
```

There is no additional `*_ALLOW_CREATE`, `*_ALLOW_DELETE`, or similar opt-in for the creation and removal of disposable test databases. These operations are part of the database-suite contract, not special execution modes.

## Reporting and failure handling

Tests report individual checks using a small, stable text format.

| Result | Output | Destination |
| --- | --- | --- |
| Pass | `PASS <check name>` | STDOUT |
| Fail | `FAIL <check name> - <message>` | STDERR |
| Skip | `SKIP <reason>` | For an optional check that was not run |

Each suite must finish with a summary on its **last non-empty output line**.

```text
N passed, M failed
N passed, M failed, K skipped
```

Replace `N`, `M`, and `K` with integer counts. Omit the skipped field if no checks were skipped. Return a non-zero process exit code when a check fails. Do not print debug messages after the summary; the aggregate runner parses that last line.

Tests fail fast on unexpected PHP and database diagnostics. Configure MySQLi to throw exceptions and convert PHP errors to `ErrorException`.

```php
\mysqli_report(\MYSQLI_REPORT_ERROR | \MYSQLI_REPORT_STRICT);

\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});
```

An unexpected failure should be visible, not hidden behind a generic success result. A suite must report failures accurately even when a check throws or a child process exits unexpectedly.

## The aggregate runner

Every package containing suites provides `tests/run.php`. Running it must execute **all** suite entry points, including those requiring a database connection.

The aggregate runner follows these rules.

1. Discover `tests/*/run.php` and `tests/*/database.php` automatically; newly added suites require no registration.
2. Run all `run.php` suites first, in alphabetical order. Then run all `database.php` suites, also in alphabetical order.
3. Start every suite in a separate process using `PHP_BINARY` and `proc_open()`. Never `require` a suite into the aggregate runner.
4. Use the parent's loaded `php.ini` with `-c` when available; if no configuration is loaded, use the appropriate default lookup or `-n`. Explicit parent `-d` overrides are not forwarded. Child processes inherit the environment, including the two shared test variables.
5. Print a `== <suite>/<script>` heading before each suite, with a blank line between suites. Forward suite output unchanged.
6. Print a final `== total (N suites)` heading and the accumulated `passed`, `failed`, and optional `skipped` counts.
7. Exit with code `1` if a check or suite fails, or if no suites are found; otherwise exit with `0`.

A suite that ends without a valid summary counts as one failure, including after a fatal error. A suite that returns a non-zero exit code but claims zero failed checks also counts as one failure. A failed database connection does **not** cause a database suite to be skipped.

For stderr, pass `['file', 'php://stderr', 'w']` to `proc_open()` rather than handing it the parent's `STDERR` stream. This preserves combined output redirection such as `php tests/run.php > out.txt 2>&1` without risking an unintended file-position reset.

### Example output

The following example is based on two isolated suites in `citomni/cli`.

```text
== cache-commands/run.php
PASS Registry maps cache:warm and cache:clear to the commands
...
16 passed, 0 failed

== error-handler/run.php
PASS uncaught exception exits 1 and reports class, throw site, error_id, and dev trace on stderr
...
SKIP concurrent writers lose no records across rotations: Set CITOMNI_TEST_PARALLEL=1 to run multi-process checks
16 passed, 0 failed, 1 skipped

== total (2 suites)
32 passed, 0 failed, 1 skipped
```

The headings show the relative paths needed to run a suite directly. The aggregate runner is not itself a suite and does not have a separate suite README or a fixed expected total.

### Reference implementation

The following `tests/run.php` implementation is based on the [CitOmni CLI package runner](https://github.com/citomni/cli/blob/main/tests/run.php). Adapt its namespace and license header for the package where it is used. The discovery, process isolation, summary parsing, and exit behavior should remain consistent.

```php
<?php
declare(strict_types=1);
/*
 * This file is part of the CitOmni framework.
 * Low overhead, high performance, ready for anything.
 *
 * For more information, visit https://github.com/citomni
 *
 * Copyright (c) 2012-present Lars Grove Mortensen
 * SPDX-License-Identifier: MIT
 *
 * For full copyright, trademark, and license information,
 * please see the LICENSE file distributed with this source code.
 */

namespace CitOmni\Cli\Tests;

/*
 * Run every suite under tests/ in its own PHP process. Execute isolated suites
 * (tests/<suite>/run.php) before database suites (tests/<suite>/database.php).
 *
 * Suites replace framework classes with class_alias doubles and install their
 * own error handlers, so they never share a process. Each suite's output is
 * passed through unchanged under a "== <suite>/<script>" heading, which is also
 * the path for running that suite alone. The last line, under "== total", adds
 * up the suites' totals.
 *
 * Usage:
 *   php tests/run.php
 *   php tests/error-handler/run.php    (one suite alone)
 *
 * Notes:
 * - Suites run with the same PHP binary and php.ini as this script, and with -n
 *   when this script runs with -n. -d settings are not passed on.
 * - Environment variables such as CITOMNI_TEST_PARALLEL and CITOMNI_TEST_PASSWORD
 *   reach the suites, because child processes inherit the environment.
 * - Database suites need the test database server. Nothing is skipped when it is
 *   down; those suites fail.
 * - A suite counts as one failure when it exits non-zero with no failed checks,
 *   or when its last line is not a summary (for example after a fatal error).
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}

\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// Isolated suites first, then database suites; each group in alphabetical order.
$suites = [];
foreach (['run.php', 'database.php'] as $name) {
	$found = \glob(__DIR__ . '/*/' . $name) ?: [];
	\sort($found);
	\array_push($suites, ...$found);
}

if ($suites === []) {
	\fwrite(\STDERR, "FAIL no suites found in tests/*/run.php or tests/*/database.php\n");
	exit(1);
}

// Use the parent php.ini, default lookup, or no configuration.
$iniArgs = match (true) {
	\php_ini_loaded_file() !== false   => ['-c', \php_ini_loaded_file()],
	\php_ini_scanned_files() !== false => [],
	default                            => ['-n'],
};

$passed  = 0;
$failed  = 0;
$skipped = 0;

foreach ($suites as $i => $script) {
	$suite = \basename(\dirname($script)) . '/' . \basename($script);
	\fwrite(\STDOUT, ($i > 0 ? "\n" : '') . "== {$suite}\n");

	// Stdout is piped so the summary line can be read; stderr goes straight through.
	// Use a fresh php://stderr instead of STDERR. The proc_open() call first seeks a
	// stream to the position PHP recorded for it, 0 for an unused STDERR, which would
	// rewind out.txt in `php tests/run.php > out.txt 2>&1`.
	$proc = \proc_open([\PHP_BINARY, ...$iniArgs, $script], [
		0 => ['pipe', 'r'],
		1 => ['pipe', 'w'],
		2 => ['file', 'php://stderr', 'w'],
	], $pipes);
	if (!\is_resource($proc)) {
		throw new \RuntimeException('Cannot start suite: ' . $suite);
	}
	\fclose($pipes[0]);

	$last = '';
	while (($line = \fgets($pipes[1])) !== false) {
		\fwrite(\STDOUT, $line);
		if (\trim($line) !== '') {
			$last = \rtrim($line, "\r\n");
		}
	}
	\fclose($pipes[1]);
	$exit = \proc_close($proc);

	if (\preg_match('/^(\d+) passed, (\d+) failed(?:, (\d+) skipped)?$/', $last, $m) !== 1) {
		$failed++;
		\fwrite(\STDERR, "FAIL {$suite} - exit code {$exit} and no summary line\n");
		continue;
	}

	$passed  += (int)$m[1];
	$failed  += (int)$m[2];
	$skipped += (int)($m[3] ?? 0);

	if ($exit !== 0 && (int)$m[2] === 0) {
		$failed++;
		\fwrite(\STDERR, "FAIL {$suite} - exit code {$exit} although no check failed\n");
	}
}

\fwrite(\STDOUT, "\n== total (" . \count($suites) . (\count($suites) === 1 ? ' suite)' : ' suites)') . "\n");
\fwrite(\STDOUT, "{$passed} passed, {$failed} failed" . ($skipped > 0 ? ", {$skipped} skipped" : '') . "\n");
exit($failed === 0 ? 0 : 1);
```

## Shared environment variables

The testing convention defines **exactly two** environment variables shared by CitOmni packages.

| Variable | Meaning | Default when unset |
| --- | --- | --- |
| `CITOMNI_TEST_PASSWORD` | Password for the local MySQL/MariaDB test connection. | No password. |
| `CITOMNI_TEST_PARALLEL` | Set to `1` to enable optional multi-process concurrency checks. | Such checks are skipped. |

Do not introduce package-specific variables such as `AUTH_TEST_PASSWORD`, app-specific prefixes, overrides for host/user/port, or environment-variable fallback chains. A new shared `CITOMNI_TEST_*` variable is justified only by a concrete need that the existing variables cannot meet; do not add one preemptively for symmetry.

Downstream packages adopting this testing convention use the same variables. Framework code must not depend on names that belong to a consuming application or a separate vendor.

Environment variables are inherited by suite processes, and by any child workers started without an explicit replacement environment. **Never pass database passwords as command-line arguments**, embed them in source code, or print them in output or logs.

## Database integration tests

A database suite runs against a real local MySQL or MariaDB server. It must not rely on application configuration or alternate connection settings.

### Connection contract

Use these exact parameters for the test connection.

| Setting | Value |
| --- | --- |
| Host | `127.0.0.1` (TCP) |
| Port | `3306` |
| Username | `root` |
| Password | `CITOMNI_TEST_PASSWORD`, or empty if unset |
| Character set | `utf8mb4` |

A reusable connection helper can be kept inside the database suite or its focused test utilities.

```php
function connect(string $database = ''): \mysqli {
	// An unset CITOMNI_TEST_PASSWORD means an empty password.
	$db = new \mysqli('127.0.0.1', 'root', (string) \getenv('CITOMNI_TEST_PASSWORD'), $database, 3306);
	$db->set_charset('utf8mb4');
	return $db;
}
```

Because database tests create and drop schemas using the `root` account, use an **isolated local development database server**. Never point these tests at a production database or a server holding data that cannot be replaced.

### Disposable databases and cleanup

Each invocation creates **one uniquely named database**, using a random suffix to avoid collisions between runs. For example, a package or suite can construct a name in the following form.

```php
$database = 'citomni_cli_cache_test_' . \bin2hex(\random_bytes(6));
```

Use `utf8mb4` and `utf8mb4_unicode_ci` when creating the database. Import and exercise the schema there, then drop **only that exact database** in a `finally` block. Do not modify or clean up other databases, including other test runs that may be executing at the same time.

Database setup, failure reporting, and cleanup must remain explicit. There is no create/drop authorization environment variable, and missing server access is a failure rather than a skip.

### Current schema and migration tests

For packages with SQL files, treat `sql/<package>.sql` as the **current complete schema**. It already contains changes from prior migrations. Never import that current schema and blindly replay historical migrations on top of it.

To test a particular migration, construct its **pre-migration schema** deliberately.

1. Start with the current schema file and remove precisely the schema element or change introduced by the migration under test.
2. Verify that the intended element was matched **exactly once** during that removal. A silent mismatch makes the test invalid.
3. Import the resulting pre-migration schema into the temporary test database.
4. Execute the corresponding migration from `sql/migrations/` and assert the intended result.

Tests must refer only to files that actually exist. When a migration or documentation file moves, update the associated paths in tests and documentation in the same change.

## Optional concurrency checks

Checks involving **multiple simultaneous processes** are opt-in. They run only when `CITOMNI_TEST_PARALLEL=1`; otherwise they report `SKIP <reason>`, including a clear instruction for enabling them. This is the one specified optional category; database suites are not optional just because MySQL/MariaDB is unavailable.

For a single Command Prompt session, enable concurrency checks with the following commands.

```bat
set "CITOMNI_TEST_PARALLEL=1"
php tests/run.php
```

For a single PowerShell session, use the following.

```powershell
$env:CITOMNI_TEST_PARALLEL = '1'
php tests/run.php
```

Without the explicit value `1`, multi-process concurrency checks remain skipped. The aggregate runner still includes their skipped counts in its totals.

## Setting the database password on Windows

If local `root` authentication requires a password, set `CITOMNI_TEST_PASSWORD` **once** as a persistent user environment variable. This avoids configuring every terminal session and avoids typing the password directly into PowerShell history.

```powershell
$securePassword = Read-Host 'Database password' -AsSecureString
[Environment]::SetEnvironmentVariable('CITOMNI_TEST_PASSWORD', [Net.NetworkCredential]::new('', $securePassword).Password, 'User')
```

New terminals see the variable after it is saved. Restart existing terminal windows, IDEs, and terminal-host applications that inherited the previous environment; their already-running processes do not acquire the updated value automatically.

The MariaDB for Windows error `The server requested authentication method unknown to the client [auth_gssapi_client]` may indicate that the password is absent or incorrect in the current process. Verify that the new process receives `CITOMNI_TEST_PASSWORD` before investigating other database authentication issues. Do not print the password to verify it.

## Adding and maintaining suites

A new suite is ready to contribute when it has a standalone entry point, clear checks, and a suite-level `README.md`.

- **Choose the right entry point.** Use `run.php` for isolated checks and `database.php` when the suite genuinely needs MySQL/MariaDB.
- **Make each result observable.** Emit `PASS` or `FAIL` for each check, `SKIP` only for applicable optional checks, and the required summary as the final line. Fail with a non-zero exit code when appropriate.
- **Demonstrate regressions.** A regression test must be shown to fail against the code *without* the fix it protects; merely passing after a fix is not enough to establish that the test catches the bug.
- **Document how to run the suite.** The suite `README.md` must state the direct command and expected totals, including differences when optional checks are enabled. Update those totals whenever checks change.
- **Follow CitOmni code conventions.** Use PHP 8.5+, `declare(strict_types=1);`, the package's appropriate MIT license header, PSR-1/PSR-4 naming, tabs for indentation, K&R braces, and English PHPDoc and comments.

See [CitOmni Coding & Documentation Conventions](./CONVENTIONS.md) for the wider style and documentation rules.

## Troubleshooting

| Symptom | What to check |
| --- | --- |
| A `database.php` suite fails to connect | Confirm that MySQL/MariaDB is listening on `127.0.0.1:3306`, that the local `root` account can create/drop temporary databases, and that `CITOMNI_TEST_PASSWORD` is present when required. |
| A suite fails with `no summary line` | The process terminated before emitting a valid final total, or printed additional non-empty output afterward. Run that suite directly to inspect its diagnostics. |
| An aggregate run reports a failure but the suite claims `0 failed` | The child exited non-zero. Check its own error output rather than trusting its summary alone. |
| A `class_alias()` double collides with a real class | Remove premature Composer bootstrapping from that isolated suite and keep it in its own PHP process. |
| A concurrency check is skipped | Set `CITOMNI_TEST_PARALLEL=1` when multi-process checks are required. |
| A migration test fails after a schema change | Check that it starts from a deliberately constructed pre-migration schema, that the removal matched once, and that its file paths still exist. |

A clean test run ends with a valid total and exit code `0`. A failing test run gives a non-zero exit code, including when a suite crashes before reporting its own results.
