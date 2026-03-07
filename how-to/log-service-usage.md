# CitOmni Log Service - Usage and Operational Patterns (PHP 8.2+)

> **Low overhead. High performance. Deterministic by design.**

This document explains how to use CitOmni's first-class `Log` service for production-grade application logging. It covers how the service is resolved, how configuration is read, how writes behave, how file naming and rotation work, what the service guarantees under concurrency, and where the sharp edges are.

`Log` exists to provide a native, explicit, low-overhead logging layer for CitOmni applications and provider packages. It replaces the former LiteLog-backed wrapper while intentionally preserving the public usage pattern through `$this->app->log`.

It does not attempt to be a log aggregation platform, a tracing system, a structured event bus, or a policy engine for observability. It writes newline-delimited JSON log entries to local files, rotates deterministically, and fails fast on meaningful write-path errors. That is intentional.

* PHP ≥ 8.2
* PSR-1 / PSR-4
* Tabs for indentation, **K&R** brace style
* English PHPDoc and inline comments
* Fail fast by default; use try/catch only when recovery is explicit and meaningful

---

## 1) What the Log service is

CitOmni's logging service is:

* A first-class service in `citomni/infrastructure`
* Implemented as `CitOmni\Infrastructure\Service\Log`
* Accessed via `$this->app->log`
* Built around **newline-delimited JSON**
* Designed for **deterministic append**, **bounded normalization fallback**, and **safe concurrent writes**

It is for:

* Writing structured application logs cheaply
* Recording events, failures, state transitions, and diagnostics
* Keeping operational logging explicit and local
* Supporting ordinary PHP hosting and CLI runtimes without extra infrastructure

It is not for:

* Distributed tracing
* Remote transport
* Full-text indexing
* Search and analytics
* Arbitrary file system browsing
* Serializing whole object graphs "because it might be useful later"

If you need a fast, explicit, production-usable logger that writes one JSON object per line and behaves predictably under concurrent writes, `Log` is designed to do exactly that.

---

## 2) How the service is accessed

The service is resolved through the `App` like any other CitOmni service:

```php
$this->app->log->write(
	null,
	'auth',
	'User logged in',
	['userId' => 42]
);
````

You can also target a specific flat log file:

```php
$this->app->log->write(
	'audit',
	'security',
	['action' => 'password_reset_requested'],
	['email' => $email]
);
```

**Resolution model**

* `$this->app->log` resolves through the service map
* The service is instantiated **once per request/process**
* The same instance is reused for the lifetime of that request/process
* The constructor contract exists for the resolver, not for ordinary application code

In other words:

* You **do** use `$this->app->log`
* You **do not** manually `new Log(...)` in normal application code

That matters because configuration validation, directory handling, and logger runtime state are designed around framework-managed resolution.

---

## 3) Configuration model and validation

The service reads its configuration from:

```php
$this->app->cfg->log
```

Configuration is read and validated in `init()`. The logger derives its runtime scalars once and then drops constructor options.

### Supported keys

The service supports these config keys:

* `path`
  Target log directory
* `default_file`
  Default log filename when `write()` receives `null` or `''`
* `max_bytes`
  Maximum active file size before rotation
* `max_files`
  Maximum number of rotated files to keep, or `null` for unlimited retention

Example:

```php
<?php
declare(strict_types=1);

return [
	'log' => [
		'path' => CITOMNI_APP_PATH . '/var/logs',
		'default_file' => 'citomni_app.jsonl',
		'max_bytes' => 10485760,
		'max_files' => 10,
	],
];
```

### Defaults

If omitted, the service uses:

* `path` -> `CITOMNI_APP_PATH . '/var/logs'`
* `default_file` -> `citomni_app.jsonl`
* `max_bytes` -> `10485760` (10 MiB)
* `max_files` -> `null` (unlimited rotated files)

### Validation philosophy

The service validates supported config values explicitly and cheaply:

* `path` must be a non-empty string when provided
* `default_file` must be a non-empty string when provided
* `max_bytes` must be an integer
* `max_files` must be an integer or `null`

Further runtime validation then applies:

* the directory must exist or be auto-creatable
* the directory must be writable
* filenames must pass the logger's flat-file policy
* maximum file size must be at least `1024`
* rotated-file retention count must be `>= 1` or `null`

This is intentional. Logging misconfiguration should fail early and clearly, not degrade into "nothing got logged, apparently" six layers later.

---

## 4) Directory behavior

The logger writes into one active directory at a time.

### Default directory

Unless configured otherwise, it writes to:

```php
CITOMNI_APP_PATH . '/var/logs'
```

### Directory normalization

`setDir()` normalizes directory paths to include a trailing directory separator.

Example:

```php
$this->app->log->setDir(CITOMNI_APP_PATH . '/var/logs');
```

The runtime path becomes effectively:

```php
CITOMNI_APP_PATH . '/var/logs' . DIRECTORY_SEPARATOR
```

### Auto-create behavior

During service initialization, the logger calls:

```php
$this->setDir($path ?? self::DEFAULT_DIR, true);
```

That means the configured log directory may be created automatically if it does not already exist.

If you call `setDir()` manually later without enabling auto-create:

```php
$this->app->log->setDir('/some/path', false);
```

then a missing directory fails immediately.

### Writable directory requirement

The logger requires the target directory to be writable. A directory that exists but is not writable is treated as a fatal logger setup error.

That is the correct behavior. A logger that cannot write should complain loudly instead of quietly roleplaying as documentation.

---

## 5) File naming policy

The logger intentionally enforces a strict file naming policy.

## Accepted policy

Log file names must be:

* Flat only
* ASCII letters, digits, `.`, `_`, or `-`
* Not hidden-file style
* Not dot-only or otherwise meaningless
* Normalized to end in `.jsonl`

Examples of accepted input:

* `app`
* `app.log`
* `audit`
* `audit.jsonl`
* `worker-queue`
* `security_events.log`

Examples of normalized output:

* `app` -> `app.jsonl`
* `app.log` -> `app.jsonl`
* `audit.jsonl` -> `audit.jsonl`

## Rejected input

The following are rejected:

* `.env`
* `.log`
* `...`
* `../app.log`
* `subdir/app.log`
* `foo/bar`
* names containing spaces
* names containing other punctuation outside `.`, `_`, `-`

This policy exists to keep logger-managed files:

* flat
* predictable
* easy to rotate and prune safely
* harder to misuse as a general file writer

### Default file selection

`write()` uses the configured default file when `$file` is:

* `null`
* `''`

Example:

```php
$this->app->log->write(
	null,
	'http',
	'Request completed',
	['status' => 200]
);
```

This writes to the configured default file.

---

## 6) Entry format

Each call to `write()` produces exactly one JSON object followed by `"\n"`.

Base structure:

```json
{
	"timestamp": "2026-03-07T20:15:00+01:00",
	"category": "auth",
	"message": "User logged in"
}
```

If context is non-empty, the entry also contains `context`:

```json
{
	"timestamp": "2026-03-07T20:15:00+01:00",
	"category": "auth",
	"message": "User logged in",
	"context": {
		"userId": 42,
		"ip": "203.0.113.10"
	}
}
```

### Field order

The service builds entries in this order:

1. `timestamp`
2. `category`
3. `message`
4. `context` only when non-empty

### Timestamp format

The logger uses:

```php
date(DATE_ATOM)
```

That yields an ISO 8601 / Atom-style timestamp with timezone offset.

### Message types

The `message` parameter supports:

* `string`
* `array`
* `object`

Examples:

```php
$this->app->log->write(null, 'app', 'Started job');
$this->app->log->write(null, 'app', ['event' => 'job_started', 'jobId' => 12]);
$this->app->log->write(null, 'app', $someObject);
```

### Context type

The `context` parameter must be an array:

```php
$this->app->log->write(
	null,
	'mail',
	'Mail send failed',
	[
		'to' => $recipient,
		'template' => 'welcome',
	]
);
```

The logger treats `context` as optional structured metadata, not as a dumping ground for everything the process has ever known.

---

## 7) Write behavior and concurrency guarantees

The logger is designed to serialize append, rotation, and pruning per active log file.

### Sidecar lock files

For each active log file, the service uses a sidecar lock file:

```text
/path/to/app.jsonl.lock
```

This lock is used to coordinate:

* append
* pre-write rotation
* post-write rotation
* pruning

### Write sequence

A `write()` call behaves broadly like this:

1. Resolve the target file name
2. Build active file path
3. Open sidecar lock file
4. Acquire exclusive lock
5. Check whether the active file already exceeds the size limit
6. Rotate and prune if needed before append
7. Encode the entry line
8. Append the entry
9. Check resulting file size
10. Rotate and prune again if the write crossed the limit
11. Release lock

This sequence matters.

It means the logger avoids obvious race windows between "should rotate" and "is writing now" for concurrent writers targeting the same file.

### Practical concurrency guarantee

The service is designed so concurrent writers to the same active log file do not interleave bytes within a line and do not perform conflicting rotation simultaneously. That is the operational goal of the sidecar lock model.

This is not a distributed consensus protocol, nor does it attempt to synchronize across multiple machines writing to a shared non-POSIX fantasy filesystem. It is designed for ordinary local filesystem semantics in PHP hosting and CLI environments.

Which, mercifully, is where most logs still live.

---

## 8) Rotation behavior

The logger rotates active files when they reach or exceed the configured size limit.

### Pre-append rotation

Before appending, the logger checks:

* if the active file exists
* if its size is already `>= max_bytes`

If so, it rotates before writing the new entry.

### Post-append rotation

After appending, the logger checks the active file size again using `fstat()` on the append handle result. If the file has now crossed the limit, it rotates again.

This two-stage design handles both:

* files already full before the current write
* files pushed over the threshold by the current write

### Rotated filename format

Rotation names are deterministic and include:

* base filename
* timestamp
* process id
* optional collision counter

Pattern:

```text
<base>_<YYYYmmdd_HHMMSS>_<pid>.jsonl
<base>_<YYYYmmdd_HHMMSS>_<pid>_1.jsonl
<base>_<YYYYmmdd_HHMMSS>_<pid>_2.jsonl
```

Example:

```text
citomni_app_20260307_201530_1234.jsonl
citomni_app_20260307_201530_1234_1.jsonl
```

This format keeps rotated files:

* in the same directory
* grouped by active file family
* highly unlikely to collide
* straightforward to prune

### Rotation failures

Rename failures during rotation are fatal and raise `LogRotationException`.

That is correct. Once the logger has concluded rotation is required, a failed rotation is not a minor inconvenience. It is a write-path failure.

---

## 9) Retention and pruning

The logger supports optional pruning of rotated files via `max_files`.

### Unlimited retention

When `max_files` is `null`, no pruning occurs.

### Bounded retention

When `max_files` is an integer, the logger keeps at most that many rotated files for the active file family.

Example:

```php
'log' => [
	'max_files' => 5,
]
```

This keeps the 5 newest rotated files and attempts to delete older ones.

### Scope of pruning

Pruning applies to files matching the active family pattern:

```text
<filename>_*.jsonl
```

For example, if the active file is:

```text
audit.jsonl
```

then pruning targets:

```text
audit_*.jsonl
```

### Sort behavior

Pruning sorts rotated files by:

1. `filemtime()`
2. lexical path comparison as tie-breaker

This keeps deletion deterministic enough for operational use.

### Important: Pruning failures are non-fatal

If pruning cannot delete an old rotated file, the logger does **not** fail the write.

That exception is deliberate.

CitOmni's logging service treats:

* lock failures
* append-open failures
* short writes
* rotation failures

as fatal

but treats:

* old-file cleanup failures

as non-fatal housekeeping failures

This is the right trade-off. Failing to remove old clutter is not the same as failing to write the current event.

---

## 10) JSON encoding and fallback behavior

The logger first attempts normal JSON encoding with these flags:

```php
JSON_UNESCAPED_UNICODE
| JSON_UNESCAPED_SLASHES
| JSON_INVALID_UTF8_SUBSTITUTE
```

That gives the service a reasonably robust fast path.

### First pass

The logger encodes the entry directly.

If that succeeds, it writes the JSON line immediately.

### Fallback normalization

If normal encoding fails, the logger falls back to bounded normalization through `normalizeEncodableValue()`.

This fallback is intentionally limited.

Behavior:

* strings are normalized toward valid UTF-8
* arrays are normalized recursively
* recursion depth is capped
* objects are reduced to deterministic placeholder structures
* deep or cyclic traversal is intentionally avoided

This is logging robustness, not object persistence.

### Maximum normalization depth

The logger caps fallback recursion at:

```php
8
```

Beyond that, values are replaced with:

```text
[max-normalize-depth-exceeded]
```

### Object normalization

Objects are reduced to:

```php
[
	'__log_object' => \get_class($value),
]
```

The logger does not walk object graphs or attempt reflective serialization.

That is intentional for both performance and stability reasons.

### Final fallback line

If JSON still cannot be produced even after normalization, the logger emits a deterministic fallback entry with:

* original timestamp
* sanitized category
* a plain fallback message

Example shape:

```json
{
	"timestamp": "2026-03-07T20:15:00+01:00",
	"category": "error",
	"message": "Log fallback: JSON encode failed"
}
```

This ensures the logger still produces *something* operationally useful instead of vanishing into offended silence.

---

## 11) String normalization behavior

The logger includes a bounded string normalization path for problematic input.

### Fast valid-UTF-8 path

If `mb_check_encoding()` exists and confirms valid UTF-8, the original string is preserved.

### Fallback conversion attempts

If needed, the logger tries:

1. `iconv('CP1252', 'UTF-8//IGNORE', ...)`
2. `mb_convert_encoding(..., 'UTF-8', 'ISO-8859-1')` when available
3. otherwise `iconv('ISO-8859-1', 'UTF-8//IGNORE', ...)`

### Final cleanup

If conversion still does not produce a useful safe string, the logger removes control characters via:

```php
/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/
```

This is intentionally pragmatic. The logger attempts to salvage loggable text cheaply and predictably, not to perform archaeology on every broken byte sequence ever emitted by legacy software.

---

## 12) Public API

The service exposes a deliberately small public surface.

### `write()`

Writes one entry.

```php
$this->app->log->write(
	null,
	'auth',
	'User logged in',
	['userId' => 42]
);
```

Signature:

```php
write(?string $file, string $category, string|array|object $message, array $context = []): void
```

### `setDir()`

Changes the active log directory.

```php
$this->app->log->setDir(CITOMNI_APP_PATH . '/var/logs/custom', true);
```

Use this only when you deliberately want to override the configured directory during runtime.

### `setMaxFileSize()`

Changes the rotation threshold.

```php
$this->app->log->setMaxFileSize(5 * 1024 * 1024);
```

The minimum accepted value is `1024`.

### `setMaxRotatedFiles()`

Changes rotated file retention.

```php
$this->app->log->setMaxRotatedFiles(20);
$this->app->log->setMaxRotatedFiles(null);
```

Use `null` for unlimited retention.

---

## 13) Error handling

The logger uses a small set of logger-specific exception types:

* `CitOmni\Infrastructure\Exception\LogConfigException`
* `CitOmni\Infrastructure\Exception\LogDirectoryException`
* `CitOmni\Infrastructure\Exception\LogFileException`
* `CitOmni\Infrastructure\Exception\LogRotationException`
* `CitOmni\Infrastructure\Exception\LogWriteException`

### Exception roles

**`LogConfigException`**

Used for invalid supported config values or invalid runtime policy values.

Examples:

* non-string `path`
* non-string `default_file`
* non-integer `max_bytes`
* `max_bytes < 1024`
* invalid rotated-file limit

**`LogDirectoryException`**

Used when the directory cannot be used operationally.

Examples:

* empty directory path
* missing directory without auto-create
* failed directory creation
* directory not writable

**`LogFileException`**

Used for file-path and lock/open failures.

Examples:

* invalid filename
* forbidden subdirectory usage
* hidden-file style file name
* lock file open failure
* lock acquisition failure
* append file open failure

**`LogRotationException`**

Used when the active file cannot be rotated.

Example:

* failed `rename()` during rotation

**`LogWriteException`**

Used when the logger cannot fully write the encoded entry.

Example:

* short write or failed `fwrite()`

### What callers should typically catch

In most application code, let logger failures bubble unless you have a real fallback policy.

Example boundary catch:

```php
try {
	$this->app->log->write(
		null,
		'mail',
		'Mail send failed',
		['to' => $recipient]
	);
} catch (\CitOmni\Infrastructure\Exception\LogWriteException $e) {
	throw $e;
}
```

Or broader logger-layer handling:

```php
try {
	$this->app->log->write(
		'audit',
		'security',
		['action' => 'login_failed'],
		['email' => $email]
	);
} catch (
	\CitOmni\Infrastructure\Exception\LogConfigException |
	\CitOmni\Infrastructure\Exception\LogDirectoryException |
	\CitOmni\Infrastructure\Exception\LogFileException |
	\CitOmni\Infrastructure\Exception\LogRotationException |
	\CitOmni\Infrastructure\Exception\LogWriteException $e
) {
	throw $e;
}
```

In practice, most applications should not micromanage logger errors deep in the call tree. Let them surface at a boundary that has an actual policy.

---

## 14) Typical usage patterns

### Basic application event

```php
$this->app->log->write(
	null,
	'app',
	'Application started'
);
```

### Structured message payload

```php
$this->app->log->write(
	null,
	'queue',
	[
		'event' => 'job_started',
		'jobId' => $jobId,
		'type' => $jobType,
	]
);
```

### Context-rich failure log

```php
$this->app->log->write(
	'mail',
	'mail',
	'Mailer transport failed',
	[
		'to' => $recipient,
		'template' => $template,
		'retryable' => false,
	]
);
```

### Audit-style separate file

```php
$this->app->log->write(
	'audit',
	'security',
	[
		'action' => 'password_changed',
		'userId' => $userId,
	],
	[
		'actorId' => $actorId,
		'ip' => $ip,
	]
);
```

### Logging arrays instead of hand-building strings

Good:

```php
$this->app->log->write(
	null,
	'payment',
	[
		'event' => 'capture_failed',
		'orderId' => $orderId,
		'gateway' => $gateway,
	]
);
```

Less good:

```php
$this->app->log->write(
	null,
	'payment',
	'capture_failed for order ' . $orderId . ' via ' . $gateway
);
```

Both are valid, but structured payloads are often easier to inspect and post-process.

---

## 15) Operational guidance

### Separate files by concern, not by whim

Reasonable examples:

* `citomni_app.jsonl`
* `audit.jsonl`
* `mail.jsonl`
* `worker.jsonl`

Less reasonable examples:

* one file per controller
* one file per user
* one file per request path
* dynamically derived filenames from untrusted or high-cardinality input

The logger supports explicit file targeting, but that is not an invitation to reinvent fragmentation as a feature.

### Keep categories stable

Use categories that remain useful over time:

* `auth`
* `http`
* `mail`
* `queue`
* `db`
* `security`

Avoid categories that are too specific or ephemeral unless there is a real operational reason.

### Prefer structured context for metadata

Use `context` for:

* identifiers
* flags
* request metadata
* narrow operational facts

Do not use it to dump enormous payloads casually, especially in hot paths.

### Rotate intentionally

The default 10 MiB threshold is a reasonable starting point for many applications, but not a law of nature.

Smaller thresholds:

* rotate more often
* produce more files
* may ease local inspection

Larger thresholds:

* reduce rotation frequency
* keep fewer, larger active files
* may be more suitable for busy applications

Set `max_files` deliberately if storage bounds matter.

---

## 16) Common pitfalls

### Using subdirectories in `$file`

This fails:

```php
$this->app->log->write('security/audit', 'security', '...');
```

The logger does not allow subdirectories in filenames.

If you need another directory, change the logger directory explicitly with `setDir()` or config.

### Assuming `.log` is preserved

This:

```php
$this->app->log->write('app.log', 'app', 'Started');
```

does **not** write to `app.log`.

It writes to:

```text
app.jsonl
```

The logger normalizes managed file names to `.jsonl`.

### Hidden-file names

This fails:

```php
$this->app->log->write('.env', 'app', 'No.');
```

Correctly.

### Treating the logger as arbitrary serialization storage

Passing huge objects or deeply nested runtime state is a bad idea even though the logger has bounded fallback behavior.

Fallback exists for robustness, not as a recommendation to log everything with a pulse.

### Assuming pruning failures are fatal

They are not.

Old rotated files may fail to delete without causing the current write to fail.

That is expected.

### Assuming invalid configuration will be tolerated

It will not.

Invalid logger configuration is treated as a contract failure.

Good.

---

## 17) FAQ

### Q: Should I always pass a specific file name?

No.

Use `null` or `''` when the default file is the right destination. Use explicit file names only when you deliberately want separate operational streams.

### Q: Should I log strings or arrays?

Both are supported.

Use strings for simple human-readable events. Use arrays when structure matters, especially for recurring operational events or machine-friendly inspection.

### Q: Can I log objects directly?

Yes, but with an important caveat.

Objects are accepted, but if normal JSON encoding fails, fallback handling reduces objects to a deterministic placeholder with the class name. The logger does not promise faithful object serialization.

### Q: Can I use nested paths like `audit/security` as the filename?

No.

Log filenames are flat only.

### Q: Why does the logger force `.jsonl`?

Because the service manages newline-delimited JSON log files as its storage format. Normalizing the extension keeps behavior explicit and file families easy to reason about.

### Q: Does the logger rotate before or after write?

Both, depending on the state.

It rotates:

* before append if the active file is already at or above the threshold
* after append if the current write pushed the file over the threshold

### Q: Are pruning failures fatal?

No.

Pruning is non-fatal housekeeping. Write-path failures and rotation failures are fatal.

---

## 18) Usage checklist

* [ ] Resolve the logger through `$this->app->log`
* [ ] Put logger config in `$this->app->cfg->log`
* [ ] Use `path`, `default_file`, `max_bytes`, and `max_files` deliberately
* [ ] Treat invalid logger config as a real failure
* [ ] Use flat filenames only
* [ ] Expect valid names to normalize to `.jsonl`
* [ ] Use stable categories
* [ ] Prefer structured arrays for recurring event shapes
* [ ] Use `context` for narrow metadata, not indiscriminate payload dumping
* [ ] Remember that one call writes one JSON object plus `"\n"`
* [ ] Expect concurrent writes to serialize per active file via sidecar lock files
* [ ] Expect rotation to happen before and/or after append depending on file size state
* [ ] Remember that pruning failures are non-fatal
* [ ] Catch logger exceptions only where you have a real policy

---

### Closing note

Use the `Log` service the same way you would use any other good operational tool: Deliberately, consistently, and without asking it to become an analytics platform, a serializer, or a confession booth. It is there to write structured events cheaply and predictably. That is exactly the job.
