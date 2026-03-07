# CitOmni Txt Service Reference

> **Deterministic text lookup. Minimal overhead. Compatibility-first i18n.**

The CitOmni Txt Service provides language-file based text lookup for applications and provider packages.
It is a first-class service within `citomni/infrastructure` and replaces the previous LiteTxt-backed wrapper while preserving the existing public API.

This document defines the **behavioral contract**, **configuration model**, **runtime semantics**, **fallback policy**, and **empirically verified behavior** of the `Txt` service.

The purpose is not to discuss localization theory, but to document the **exact behavior of the CitOmni implementation**.

---

## Document metadata

* **Service:** `CitOmni\Infrastructure\Service\Txt`
* **Package:** `citomni/infrastructure`
* **Runtime availability:** HTTP and CLI
* **PHP version:** ≥ 8.2
* **Audience:** Framework developers and application integrators
* **Status:** Stable (runtime validated)
* **Document type:** Reference

---

# 1. Service overview

The CitOmni Txt Service resolves translated text values from PHP language files.

It is designed as a direct native replacement for the historical LiteTxt-backed integration and intentionally keeps the public API unchanged so existing application code can continue to call:

```php
$this->app->txt->get(...)
```

Key characteristics:

* deterministic file resolution
* explicit language validation
* application and vendor/package layer support
* in-memory file payload caching
* compatibility-first fallback behavior
* structured diagnostics through the native `Log` service

The service intentionally avoids external i18n libraries, dynamic loading abstractions, or runtime discovery mechanisms.
It is designed to remain **cheap, predictable, and operationally boring**.

---

# 2. Service resolution

The text service is registered as a normal CitOmni service and extends `BaseService`.

It is resolved through the application service container:

```php
$this->app->txt
```

Example:

```php
$title = $this->app->txt->get('page_title', 'site');
```

Initialization occurs lazily when the service is first resolved.

During initialization the service validates the configured active language and stores it for reuse during later lookups.

Downstream code that previously used the LiteTxt-backed wrapper does **not need to change**.

---

# 3. Configuration

The service reads the active language from:

```php
$this->app->cfg->locale->language
```

This configuration value is required.

## Required configuration key

| Key | Type | Description |
| --- | --- | --- |
| `locale.language` | string | Active language code used for file resolution |

Example:

```php
'locale' => [
	'language' => 'en',
]
```

Supported format:

* `xx`
* `xx_YY`

Examples:

* `en`
* `da`
* `en_GB`
* `da_DK`

Invalid or missing values produce a `TxtConfigException`.

---

# 4. Public API

The public API intentionally remains identical to the historical integration.

```php
public function get(string $key, string $file, string $layer = 'app', string $default = '', array $vars = []): string
```

No additional public methods are required for normal use.

---

# 5. Method reference

## `get()`

```php
public function get(
	string $key,
	string $file,
	string $layer = 'app',
	string $default = '',
	array $vars = []
): string
```

Resolves one text value from the active language layer.

### Parameters

| Parameter | Description |
| --- | --- |
| `$key` | Translation key to resolve |
| `$file` | Language file name without `.php` |
| `$layer` | Text layer. Use `'app'` or `'vendor/package'` |
| `$default` | Fallback value returned when lookup cannot yield a usable value |
| `$vars` | Placeholder variables applied as `%UPPERCASE_KEY% => string` |

### Return value

Always returns a string.

### Behavior

The lookup process performs the following steps:

1. validate `$file`
2. resolve the already validated active language
3. resolve the physical file path
4. load and cache the file payload on first access
5. resolve `$key`
6. return the string value, or cast a scalar value to string
7. fall back to `$default` when the value is missing, empty, null, or non-scalar
8. apply placeholder replacement to the final returned string

### Exceptions

| Exception | Condition |
| --- | --- |
| `TxtConfigException` | `locale.language` missing or invalid during service initialization |
| `InvalidArgumentException` | Invalid `$file` value |
| `InvalidArgumentException` | Invalid `$layer` value |

Operational content problems do **not** throw.
They are logged and resolved through fallback behavior.

---

# 6. File resolution model

The service supports two resolution layers:

## Application layer

When `$layer === 'app'`, the service resolves files under:

```php
CITOMNI_APP_PATH . '/language/<language>/<file>.php'
```

Example:

```php
$this->app->txt->get('welcome', 'messages');
```

With `locale.language = 'en'`, this resolves to:

```php
/app-root/language/en/messages.php
```

---

## Vendor/package layer

When `$layer !== 'app'`, the service expects a `vendor/package` slug and resolves files under:

```php
CITOMNI_APP_PATH . '/vendor/<vendor/package>/language/<language>/<file>.php'
```

Example:

```php
$this->app->txt->get('login_title', 'auth', 'citomni/auth');
```

With `locale.language = 'da'`, this resolves to:

```php
/app-root/vendor/citomni/auth/language/da/auth.php
```

---

## Layer validation rules

Vendor/package layers must match the expected `vendor/package` shape.

Example accepted value:

```php
citomni/auth
```

Examples rejected:

```php
auth
citomni/auth/forms
../secrets
```

Invalid layer values produce `InvalidArgumentException`.

---

# 7. Language file contract

Language files are plain PHP files that must return an associative array.

Example:

```php
<?php
declare(strict_types=1);

return [
	'welcome' => 'Hello %NAME%',
	'items' => 3,
];
```

### Allowed resolved value types

The service accepts:

* string
* scalar values (`int`, `float`, `bool`)

Strings are returned as-is.
Scalar values are cast to string.

### Unsupported resolved value types

The service does **not** accept resolved values such as:

* array
* object
* resource

These are treated as unusable translation values and cause fallback to `$default`.

### Invalid payload behavior

If a language file does not return an array, the service:

* logs the condition
* caches the file as an empty dataset
* continues using fallback behavior for lookups against that file

This is an intentional compatibility-first decision.

---

# 8. Placeholder replacement

Placeholder replacement follows the historical LiteTxt convention.

Variables are transformed into placeholders using:

```php
'%' . strtoupper((string)$key) . '%'
```

Example:

```php
$this->app->txt->get(
	'welcome',
	'messages',
	'app',
	'Hello %NAME%',
	['name' => 'Lars']
);
```

This produces:

```php
Hello Lars
```

### Rules

* keys are uppercased
* values are cast to string
* replacement is performed using `strtr()`
* replacement is applied to both resolved values and fallback values

Example:

```php
$this->app->txt->get(
	'missing_key',
	'messages',
	'app',
	'Fallback for %NAME%',
	['name' => 'Lars']
);
```

Result:

```php
Fallback for Lars
```

---

# 9. Fallback and compatibility behavior

The service intentionally preserves the caller-visible lookup semantics of the previous LiteTxt-backed integration.

The following conditions do **not** throw:

* missing language file
* language file returns non-array payload
* key missing from otherwise valid file
* key exists but value is `null`
* key exists but value is `''`
* key resolves to a non-scalar value

In all such cases the service returns `$default` after optional placeholder replacement.

### Important compatibility note

The service preserves the **lookup and fallback contract** expected by calling code.

Diagnostics are modernized through the native `Log` service, and missing language files are now treated as a single explicit missing-file condition rather than relying purely on the old indirect LiteTxt behavior.

In practical application code, the service behaves as a drop-in replacement.

---

# 10. Runtime caching model

The service caches loaded language file payloads in memory, keyed by absolute file path.

This means:

* each file is loaded at most once per service instance / request / process
* repeated lookups against the same file are resolved from memory
* later on-disk changes during the same request are not reloaded

This behavior is intentional and desirable.

It makes lookup deterministic within a running request and avoids repeated filesystem access on hot paths.

### Example implication

If a language file is loaded once and then modified on disk later in the same request, subsequent lookups still use the originally cached payload.

That is not a bug.
It is the expected contract.

---

# 11. Logging model

The Txt service uses the native CitOmni `Log` service for operational diagnostics.

It writes to a dedicated log file:

```php
txt.jsonl
```

The service intentionally assumes that `$this->app->log` exists and is functional.

Absence or failure of the logger is considered framework-level misconfiguration and should propagate through the global error handler.
The Txt service does **not** implement its own fallback logging path.

### Typical log categories

The service emits structured diagnostic events such as:

* `txt.missing_file`
* `txt.invalid_file_payload`
* `txt.missing_key`
* `txt.non_scalar_value`

These logs are operational diagnostics, not application-facing exceptions.

---

# 12. Exception taxonomy

The Txt service intentionally uses a very small exception surface.

| Exception | Purpose |
| --- | --- |
| `TxtConfigException` | Invalid or missing runtime configuration for `locale.language` |
| `InvalidArgumentException` | Invalid caller-supplied `$file` or `$layer` |

This keeps the service explicit without creating unnecessary exception fragmentation.

Content problems in translation files are deliberately treated as logged fallback conditions rather than exceptional control flow.

---

# 13. Usage examples

Basic app-layer lookup:

```php
$this->app->txt->get('page_title', 'site');
```

App-layer lookup with fallback:

```php
$this->app->txt->get('missing_key', 'site', 'app', 'Fallback title');
```

Vendor/package lookup:

```php
$this->app->txt->get('login_title', 'auth', 'citomni/auth');
```

Placeholder replacement:

```php
$this->app->txt->get(
	'welcome',
	'messages',
	'app',
	'Hello %NAME%',
	['name' => 'Lars']
);
```

Scalar value cast to string:

```php
$count = $this->app->txt->get('item_count', 'messages');
```

---

# 14. Migration notes

The CitOmni Txt Service replaces the previous LiteTxt-backed wrapper.

The migration is intentionally minimal.

### Compatible behavior

* service identity remains `$this->app->txt`
* `get()` method signature remains unchanged
* calling code does not need to change
* placeholder semantics remain unchanged
* missing/unusable values still fall back to `$default`

### Operational differences

* language resolution and caching are now implemented natively
* diagnostics are written through the native `Log` service
* initialization now validates `locale.language` once during service startup

No application changes are required unless code depended on LiteTxt internals rather than the wrapper contract.

---

# 15. Empirical validation and test methodology

The Txt service was validated using a dedicated runtime self-test endpoint in a CitOmni development environment.

The purpose of this validation was not to benchmark i18n throughput, but to verify the **behavioral contract** that application code depends on.

The tests were executed against a real running application with the native `Txt` and `Log` services enabled.

This matters because it validates:

* real service resolution
* real configuration consumption
* real filesystem interaction
* real logging integration
* real placeholder replacement behavior
* real per-request cache semantics

In other words: The test environment exercised the service as it is actually used, not as an isolated code fragment.

---

## 15.1 Test scope

The runtime validation covered the following behaviors:

| Test | Purpose |
| --- | --- |
| basic app-layer lookup | verifies normal resolution from app language files |
| scalar cast to string | verifies non-string scalar compatibility |
| boolean scalar cast | verifies boolean-to-string behavior |
| placeholder replacement | verifies `%UPPERCASE_KEY%` substitution |
| missing key fallback | verifies fallback when key is absent |
| empty string fallback | verifies empty values are treated as unusable |
| null fallback | verifies null values are treated as unusable |
| vendor-layer lookup | verifies `vendor/package` path resolution |
| invalid payload fallback | verifies non-array file payload handling |
| non-scalar fallback | verifies array/object style values are rejected |
| missing file fallback | verifies missing files do not throw |
| in-memory cache behavior | verifies same-request cache semantics |
| invalid file argument | verifies caller validation |
| invalid layer argument | verifies caller validation |
| diagnostic logging | verifies integration with native `Log` service |

This is a strong contract-oriented test set for a small infrastructure service.

---

## 15.2 Runtime self-test results

Observed runtime result:

```text
Txt Service Test
Status: PASS
```

Detailed observed results:

```text
Basic app-layer lookup + placeholder replacement    OK
Scalar value cast to string                         OK
Boolean scalar cast to string                       OK
Missing key returns default with placeholder replacement    OK
Empty string value returns default                  OK
Null value returns default                          OK
Vendor-layer lookup                                 OK
Invalid file payload returns default                OK
Non-scalar translation value returns default        OK
Missing file returns default                        OK
In-memory cache keeps first loaded value within same request    OK
Invalid file argument throws                        OK
Invalid layer argument throws                       OK
Txt service writes diagnostic log entries           OK
```

All runtime contract tests passed.

---

## 15.3 Interpretation of the results

The test results support the following conclusions:

### Lookup contract stability

The service resolves values correctly from both application and vendor/package layers.

This confirms that the basic path-resolution model is functioning correctly in practice.

### Compatibility of fallback behavior

The service correctly falls back to `$default` for:

* missing keys
* empty values
* null values
* missing files
* invalid file payloads
* non-scalar values

This is the most important compatibility property for migration safety.

### Placeholder behavior

Placeholder replacement was verified in successful lookups and in fallback paths.

This confirms that the service preserves one of the small but important behavioral details of the historical implementation.

### Cache determinism

The self-test explicitly verified that once a file is loaded, later modifications on disk during the same request do not affect subsequent lookups through the same service instance.

That confirms the intended in-memory cache behavior.

### Logging integration

The self-test verified that `txt.jsonl` was written and that diagnostic events were emitted during fallback/error scenarios.

This confirms that the old direct `error_log()` dependency has been fully replaced by the first-class CitOmni `Log` service in real runtime use.

---

## 15.4 Limits of the validation

The current validation is strong for behavioral correctness, but it is not intended to prove every conceivable property.

For example, the runtime self-test does not attempt to prove:

* multi-process concurrency behavior
* filesystem race resistance under deployment churn
* syntactically broken PHP language file behavior
* every possible malformed configuration permutation

That is acceptable.

Unlike the `Log` service, the `Txt` service is not primarily a concurrency-sensitive component.
Its main risk surface lies in **lookup correctness**, **fallback semantics**, **cache determinism**, and **diagnostic behavior**.
Those areas were covered directly.

---

# 16. Operational notes

Some practical considerations:

* language files are executable PHP files and therefore must remain trusted application assets
* missing or malformed translation content is treated as an operational content problem, not as a fatal framework exception
* syntactically broken PHP language files are still real code defects and may fail fast during include
* lookup is synchronous and local-filesystem based
* the service prioritizes deterministic behavior and compatibility over advanced localization features

The Txt service is intentionally not a full translation framework.
It is a focused text lookup service.

That is a feature, not a limitation.

---

# 17. Closing note

The CitOmni Txt Service is intentionally small, explicit, and compatibility-oriented.

It provides deterministic language file lookup, lightweight in-memory caching, structured fallback diagnostics, and a stable public API suitable for both framework packages and application code.

Its design remains aligned with CitOmni's architectural priorities:

**minimal abstraction, deterministic behavior, low runtime overhead, and migration-safe first-class infrastructure.**