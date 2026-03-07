# CitOmni Log Service Reference

> **Deterministic logging. Minimal overhead. Operationally safe.**

The CitOmni Log Service provides structured application logging using newline-delimited JSON files (`.jsonl`).
It is a first-class service within `citomni/infrastructure` and replaces the previous LiteLog-backed wrapper while preserving the existing public API.

This document defines the **behavioral contract**, **configuration model**, **runtime semantics**, and **operational guarantees** of the `Log` service.

The intent is not to describe logging theory, but to document the **exact behavior of the CitOmni implementation**.

---

## Document metadata

* **Service:** `CitOmni\Infrastructure\Service\Log`
* **Package:** `citomni/infrastructure`
* **Runtime availability:** HTTP and CLI
* **PHP version:** ≥ 8.2
* **Audience:** Framework developers and application integrators
* **Status:** Stable (empirically validated)
* **Document type:** Reference

---

# 1. Service overview

The CitOmni Log Service writes **structured log entries** to disk using the **JSON Lines format**.

Each log entry is a single JSON object written as one line terminated by `"\n"`.

Key characteristics:

* Deterministic file naming
* Explicit configuration
* Size-based rotation
* Process-safe file locking
* Structured JSON entries
* Explicit failure semantics

The service intentionally avoids unnecessary abstraction layers and external logging frameworks.
It is designed to remain **cheap, predictable, and operationally robust**.

---

# 2. Service resolution

The logger is registered as a normal CitOmni service and extends `BaseService`.

It is resolved through the application service container:

```php
$this->app->log
```

Example:

```php
$this->app->log->write(
	null,
	'auth',
	'User logged in',
	['userId' => $id]
);
```

Service initialization occurs lazily when the service is first resolved.

Downstream code that previously used the LiteLog wrapper does **not need to change**.

---

# 3. Configuration

The service reads configuration from:

```
$this->app->cfg->log
```

All configuration values are optional.

If the configuration node is absent, built-in defaults are used.

## Supported configuration keys

| Key            | Type       | Description                                              |
| -------------- | ---------- | -------------------------------------------------------- |
| `path`         | string     | Directory where log files are written                    |
| `default_file` | string     | Default log file when `$file` parameter is null or empty |
| `max_bytes`    | int        | Maximum active log file size before rotation             |
| `max_files`    | int | null | Maximum number of rotated files to retain                |

Example configuration:

```php
'log' => [
	'path' => CITOMNI_APP_PATH . '/var/logs',
	'default_file' => 'app.jsonl',
	'max_bytes' => 10485760,
	'max_files' => 10,
]
```

### Default values

If not configured:

| Setting       | Default                          |
| ------------- | -------------------------------- |
| log directory | `CITOMNI_APP_PATH . '/var/logs'` |
| default file  | `citomni_app.jsonl`              |
| max file size | 10 MB                            |
| rotated files | unlimited                        |

### Directory requirements

The configured directory must:

* exist or be creatable
* be writable by the PHP process

Failure to satisfy these conditions results in a `LogDirectoryException`.

---

# 4. Public API

The public API intentionally remains identical to the historical LiteLog wrapper.

```php
public function setDir(string $dir, bool $autoCreate = false): void
public function setMaxFileSize(int $bytes): void
public function setMaxRotatedFiles(?int $count): void
public function write(?string $file, string $category, string|array|object $message, array $context = []): void
```

---

# 5. Method reference

## `setDir()`

```php
public function setDir(string $dir, bool $autoCreate = false): void
```

Sets the directory used for log storage.

### Behavior

* The directory path is normalized
* If the directory does not exist:

  * it is created when `autoCreate = true`
  * otherwise an exception is thrown
* Directory writability is validated

### Exceptions

| Exception               | Condition                                      |
| ----------------------- | ---------------------------------------------- |
| `LogDirectoryException` | Directory does not exist and cannot be created |
| `LogDirectoryException` | Directory exists but is not writable           |

---

## `setMaxFileSize()`

```php
public function setMaxFileSize(int $bytes): void
```

Sets the maximum size of the active log file before rotation occurs.

### Constraints

* Minimum value: **1024 bytes**

### Exceptions

| Exception            | Condition                      |
| -------------------- | ------------------------------ |
| `LogConfigException` | Provided size is below minimum |

---

## `setMaxRotatedFiles()`

```php
public function setMaxRotatedFiles(?int $count): void
```

Defines the number of rotated files retained.

### Behavior

* `null` disables pruning
* Values must be **≥ 1**

### Exceptions

| Exception            | Condition           |
| -------------------- | ------------------- |
| `LogConfigException` | Invalid count value |

---

## `write()`

```php
public function write(
	?string $file,
	string $category,
	string|array|object $message,
	array $context = []
): void
```

Writes a single structured log entry.

### Parameters

| Parameter   | Description                                                         |
| ----------- | ------------------------------------------------------------------- |
| `$file`     | Target log file name (flat name only). `null` uses the default file |
| `$category` | Application-defined log category                                    |
| `$message`  | Log message payload                                                 |
| `$context`  | Optional structured context data                                    |

### Behavior

The write operation performs the following steps:

1. Resolve the effective file name
2. Normalize it to `.jsonl`
3. Acquire an exclusive lock
4. Perform pre-append rotation if necessary
5. Append one JSON line
6. Perform post-append rotation if needed
7. Prune old rotated files

### Exceptions

| Exception              | Condition                    |
| ---------------------- | ---------------------------- |
| `LogFileException`     | Lock file cannot be opened   |
| `LogFileException`     | Lock acquisition fails       |
| `LogFileException`     | Log file cannot be opened    |
| `LogWriteException`    | Write fails or is incomplete |
| `LogRotationException` | File rotation fails          |

---

# 6. File naming policy

Logger-managed files must follow strict rules.

### Allowed characteristics

* ASCII letters
* digits
* `.`
* `_`
* `-`

Subdirectories are **not permitted**.

All files are normalized to the `.jsonl` extension.

### Examples

| Input         | Result        |
| ------------- | ------------- |
| `app`         | `app.jsonl`   |
| `app.log`     | `app.jsonl`   |
| `audit.jsonl` | `audit.jsonl` |

### Rejected names

| Example      | Reason                 |
| ------------ | ---------------------- |
| `.env`       | hidden file style      |
| `.log`       | meaningless base name  |
| `...`        | invalid                |
| `../app.log` | subdirectory traversal |

Invalid names produce a `LogFileException`.

---

# 7. Log entry structure

Each log entry is a **single JSON object written on its own line**.

Example:

```json
{"timestamp":"2026-03-07T20:15:02+00:00","category":"auth","message":"User logged in","context":{"userId":42}}
```

### Fields

| Field       | Description                      |
| ----------- | -------------------------------- |
| `timestamp` | ISO-8601 timestamp (`DATE_ATOM`) |
| `category`  | Application-defined category     |
| `message`   | Primary message payload          |
| `context`   | Optional structured context      |

The `context` field is only included when non-empty.

### Line termination

Every entry ends with:

```
\n
```

This makes the log compatible with **JSON Lines** tooling and streaming parsers.

---

# 8. Encoding and fallback behavior

The logger first attempts normal JSON encoding.

Flags used:

```
JSON_UNESCAPED_UNICODE
JSON_UNESCAPED_SLASHES
JSON_INVALID_UTF8_SUBSTITUTE
```

### Normal path

If JSON encoding succeeds, the line is written directly.

### Fallback normalization

If encoding fails:

1. Values are normalized into safer encodable structures
2. Arrays are normalized recursively
3. Objects are replaced with a minimal representation

Example object fallback:

```json
{
  "__log_object": "SomeClass"
}
```

### Deterministic fallback line

If JSON encoding still fails, the logger emits a fallback entry:

```json
{
  "timestamp": "...",
  "category": "...",
  "message": "Log fallback: JSON encode failed"
}
```

This prevents silent log loss.

The fallback line intentionally does **not guarantee full payload preservation**.

---

# 9. Rotation and pruning

Rotation is **size-based**.

Two rotation points exist:

* **pre-append rotation**
  when the current file already exceeds the limit

* **post-append rotation**
  when the new write crosses the limit

### Rotated file naming

Rotated files follow this pattern:

```
{basename}_{timestamp}_{pid}.jsonl
```

Example:

```
app_20260307_201530_4217.jsonl
```

A counter suffix is added if needed to avoid collisions.

---

## Pruning

When `max_files` is configured:

* rotated files exceeding the limit are deleted
* oldest files are removed first

Pruning failures are treated as **non-fatal housekeeping issues**.

---

# 10. Concurrency model

The logger uses **sidecar lock files**.

Example:

```
app.jsonl.lock
```

The write process:

1. open lock file
2. acquire `flock(LOCK_EX)`
3. perform rotation / append / pruning
4. release lock

This ensures process-safe writes on **local filesystems**.

The mechanism should **not be interpreted as distributed locking**.

---

# 11. Empirical validation and test results

The CitOmni Log Service was subjected to a series of functional and concurrency validation tests during development.

The goal of these tests was to verify the following properties:

* correctness of structured log output
* deterministic file naming
* robustness under concurrent writes
* absence of JSON corruption
* absence of lost entries
* correct rotation behavior under load
* correct pruning behavior
* correct fallback handling for invalid data

The tests were executed both in a **controlled development environment** and on a **production deployment** to validate behavior under realistic conditions.

---

## 11.1 Functional service validation

A dedicated runtime self-test endpoint (`logSelfTest`) was implemented to verify the behavioral contract of the logger.

The self-test executes a sequence of deterministic checks against an isolated test directory.

### Test coverage

The following behaviors were verified:

| Test | Purpose |
|-----|--------|
| directory creation | verifies `setDir()` behavior and writability validation |
| default file resolution | verifies `null` and empty filename handling |
| filename normalization | verifies `.jsonl` extension normalization |
| array payload handling | verifies structured message encoding |
| object payload handling | verifies object normalization behavior |
| invalid UTF-8 handling | verifies fallback normalization |
| invalid filename rejection | verifies filename policy enforcement |
| rotation configuration validation | verifies limits and configuration constraints |
| deep structure normalization | verifies bounded recursive normalization |
| rotation behavior | verifies rotation and pruning logic |

Example self-test result:

```

passed: 13
failed: 0
duration: ~12 ms

```

All validation steps completed successfully.

---

## 11.2 Concurrency test methodology

To validate concurrent write safety, a dedicated concurrency test harness was implemented.

The test architecture consisted of two components:

1. a **worker endpoint** performing repeated log writes
2. a **multi-request test launcher** generating parallel HTTP requests

Each request performed multiple log writes with the following structure:

```

{
"category": "concurrency",
"context": {
"request_id": "...",
"entry_index": n
}
}

```

This allowed deterministic verification that:

* every expected entry was written
* no entries were duplicated
* no entries were lost
* log lines were not interleaved or corrupted

---

## 11.3 Production concurrency tests

Concurrency tests were executed directly against a production deployment.

### Scenario A

```

concurrentRequests = 8
entriesPerRequest = 20
payloadSize = 200 bytes

```

Result:

```

expected_entries: 160
observed_entries: 160
invalid_lines: 0
category_failures: 0

```

---

### Scenario B

```

concurrentRequests = 12
entriesPerRequest = 40
payloadSize = 400 bytes

```

Result:

```

expected_entries: 480
observed_entries: 480
invalid_lines: 0
category_failures: 0

```

---

### Scenario C (stress test)

```

concurrentRequests = 12
entriesPerRequest = 200
payloadSize = 800 bytes

```

Result:

```

expected_entries: 2400
observed_entries: 2400
invalid_lines: 0
category_failures: 0

```

Observed filesystem state:

```

concurrency_probe.jsonl
concurrency_probe_20260307_194925_3716.jsonl

```

This confirms that **file rotation occurred while concurrent writes were in progress**.

Despite this, all expected log entries were present and valid.

---

## 11.4 Observed guarantees

Across all tests the following properties held:

* No malformed JSON lines were observed
* No lost entries were detected
* No duplicated entries occurred
* Rotation under concurrent load did not corrupt log files
* Locking successfully serialized concurrent writes
* Structured payloads were preserved

The empirical results confirm that the logger maintains **write integrity and deterministic behavior under parallel load**.

---

## 11.5 Interpretation

The concurrency model relies on `flock()` with sidecar lock files.

The validation tests demonstrate that this mechanism is sufficient to guarantee safe writes for the intended deployment model:

* PHP-FPM or similar multi-process environments
* local filesystem logging
* moderate parallel request workloads

The logger is therefore considered **operationally safe for production use** within the constraints documented in this specification.

---

# 12. Exception taxonomy

The logger defines explicit exception types.

| Exception               | Purpose                      |
| ----------------------- | ---------------------------- |
| `LogException`          | Base logging exception       |
| `LogConfigException`    | Invalid configuration values |
| `LogDirectoryException` | Directory access problems    |
| `LogFileException`      | Lock or file access failures |
| `LogWriteException`     | Incomplete write operations  |
| `LogRotationException`  | Rotation failures            |

This separation allows callers to distinguish configuration errors from operational failures.

---

# 13. Usage examples

Basic usage:

```php
$this->app->log->write(
	null,
	'auth',
	'User logged in',
	['userId' => $userId]
);
```

Custom file:

```php
$this->app->log->write(
	'audit',
	'security',
	'Permission change',
	['userId' => 42, 'role' => 'admin']
);
```

Structured message payload:

```php
$this->app->log->write(
	null,
	'order',
	[
		'orderId' => 10042,
		'status' => 'paid'
	]
);
```

---

# 14. Migration notes

The CitOmni Log Service replaces the previous LiteLog-backed wrapper.

The migration is intentionally minimal.

### Compatible behavior

* `$this->app->log` service identity unchanged
* `write()` method signature unchanged
* calling code can remain unchanged

### Operational differences

* All log files now use the `.jsonl` extension
* logging is implemented natively inside CitOmni
* rotation and locking are handled internally

No application changes are required unless code depended on previous LiteLog internals.

---

# 15. Operational notes

Some practical considerations:

* Logging is synchronous by design
* log files are local filesystem files
* large log volumes may require external rotation or ingestion
* the logger prioritizes **deterministic behavior over extensibility**

The encoding fallback exists because **losing a log entry entirely can be operationally worse than recording a degraded entry**.

---

# 16. Closing note

The CitOmni Log Service is intentionally small, explicit, and predictable.

It provides structured logging suitable for application diagnostics and operational monitoring while remaining aligned with CitOmni's architectural priorities:

**minimal abstraction, deterministic behavior, and low runtime overhead.**
