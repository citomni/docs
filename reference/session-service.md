# CitOmni Session Service Reference

> **Deterministic session handling. Minimal overhead. Security-first defaults.**

The CitOmni Session Service provides a lean wrapper around PHP's native session handling.

It is a first-class service within `citomni/http` and exposes a small, predictable API for session lifecycle, session state, session cookie policy, optional ID rotation, and optional fingerprint binding.

This document defines the **behavioral contract**, **configuration model**, **runtime semantics**, and **operational guarantees** of the `Session` service.

The intent is not to describe PHP sessions in general, but to document the **exact behavior of the CitOmni implementation**.

---

## Document metadata

* **Service:** `CitOmni\Http\Service\Session`
* **Package:** `citomni/http`
* **Runtime availability:** HTTP runtime
* **PHP version:** ≥ 8.2
* **Audience:** Framework developers and application integrators
* **Status:** Stable
* **Document type:** Reference

---

# 1. Service overview

The CitOmni Session Service manages PHP sessions using a **lazy-start**, **configuration-driven**, and **security-aware** wrapper.

Key characteristics:

* Lazy autostart
* Hardened runtime defaults
* Deterministic cookie policy resolution
* Optional session ID rotation
* Optional fingerprint binding
* Explicit failure semantics
* Minimal overhead

The service intentionally avoids framework magic and external abstractions.

It is designed to remain **cheap, predictable, and operationally safe**.

---

# 2. Service resolution

The session service is registered as a normal CitOmni service and extends `BaseService`.

It is resolved through the application service container:

```php
$this->app->session
````

Example:

```php
$this->app->session->start();
$this->app->session->set('user_id', $userId);
```

Service initialization occurs lazily when the service is first resolved.

The service performs no eager boot work beyond its normal construction path.

---

# 3. Configuration

The service primarily reads configuration from:

```php
$this->app->cfg->session
```

It also uses fallback values from:

```php
$this->app->cfg->cookie
$this->app->cfg->http
```

All configuration values are optional.

If the relevant configuration nodes are absent, built-in defaults are used.

## Supported configuration keys

| Key                           | Type          | Description                                         |
| ----------------------------- | ------------- | --------------------------------------------------- |
| `use_strict_mode`             | bool          | Enables PHP strict session mode                     |
| `use_only_cookies`            | bool          | Restricts session propagation to cookies only       |
| `lazy_write`                  | bool          | Enables PHP lazy session write behavior             |
| `gc_maxlifetime`              | int           | Session garbage collection lifetime in seconds      |
| `sid_length`                  | int           | Session ID length for PHP < 8.4                     |
| `sid_bits_per_character`      | int           | SID bits per character for PHP < 8.4                |
| `save_path`                   | string        | Session storage directory                           |
| `name`                        | string        | Session cookie name                                 |
| `rotate_interval`             | int           | Rotation interval in seconds. `0` disables rotation |
| `fingerprint.bind_user_agent` | bool          | Include user agent hash in fingerprint              |
| `fingerprint.bind_ip_octets`  | int           | Number of IPv4 octets to bind                       |
| `fingerprint.bind_ip_blocks`  | int           | Number of IPv6 blocks to bind                       |
| `cookie_secure`               | bool          | Session-specific override for Secure cookie flag    |
| `cookie_httponly`             | bool          | Session-specific override for HttpOnly cookie flag  |
| `cookie_samesite`             | string        | Session-specific override for SameSite              |
| `cookie_path`                 | string        | Session-specific override for cookie path           |
| `cookie_domain`               | string | null | Session-specific override for cookie domain         |

## Cookie fallback keys

If the session-specific cookie keys are absent, the service falls back to:

| Key               | Type          | Description            |
| ----------------- | ------------- | ---------------------- |
| `cookie.secure`   | bool          | Fallback Secure flag   |
| `cookie.httponly` | bool          | Fallback HttpOnly flag |
| `cookie.samesite` | string        | Fallback SameSite      |
| `cookie.path`     | string        | Fallback cookie path   |
| `cookie.domain`   | string | null | Fallback cookie domain |

## HTTPS inference

If `session.cookie_secure` and `cookie.secure` are both absent, Secure is inferred from:

1. `http.base_url` if it starts with `https://`
2. `Request::isHttps()` when the Request service is available
3. internal fallback detection using `$_SERVER['HTTPS']` or port `443`

## Example configuration

```php
'session' => [
	'use_strict_mode' => true,
	'use_only_cookies' => true,
	'lazy_write' => true,
	'gc_maxlifetime' => 1440,
	'name' => 'CITOMNISESSID',
	'rotate_interval' => 900,
	'fingerprint' => [
		'bind_user_agent' => true,
		'bind_ip_octets' => 2,
		'bind_ip_blocks' => 0,
	],
	'cookie_samesite' => 'Lax',
	'cookie_httponly' => true,
],
'cookie' => [
	'secure' => true,
	'path' => '/',
	'domain' => null,
],
```

### Default values

If not configured:

| Setting                  | Default            |
| ------------------------ | ------------------ |
| `use_strict_mode`        | `true`             |
| `use_only_cookies`       | `true`             |
| `lazy_write`             | `true`             |
| `gc_maxlifetime`         | `1440`             |
| `sid_length`             | `48` for PHP < 8.4 |
| `sid_bits_per_character` | `6` for PHP < 8.4  |
| cookie lifetime          | `0`                |
| cookie `httponly`        | `true`             |
| cookie `samesite`        | `Lax`              |
| cookie `path`            | `/`                |
| cookie `domain`          | `null`             |
| `rotate_interval`        | `0`                |
| fingerprint binding      | disabled           |

### Save path behavior

When `save_path` is configured:

* the path is created if missing
* directory creation is attempted with `0775`
* the resulting path is passed to `ini_set('session.save_path', ...)`

If directory creation fails silently at the PHP level, later session startup may fail.

---

# 4. Public API

The public API is intentionally small.

```php
public function start(): void
public function isActive(): bool
public function id(): ?string
public function get(string $key): mixed
public function set(string $key, mixed $value): void
public function has(string $key): bool
public function remove(string $key): void
public function destroy(bool $forgetCookie = true): void
public function regenerate(bool $deleteOld = true): void
```

---

# 5. Method reference

## `start()`

```php
public function start(): void
```

Explicitly starts the session.

### Behavior

* no-op if a session is already active
* otherwise calls the internal startup pipeline
* guarantees that `$_SESSION` is available on return

### Exceptions

| Exception          | Condition                                 |
| ------------------ | ----------------------------------------- |
| `RuntimeException` | Headers already sent before session start |
| `RuntimeException` | `session_start()` fails                   |

---

## `isActive()`

```php
public function isActive(): bool
```

Returns whether a PHP session is currently active.

### Behavior

* returns `true` only when `session_status() === PHP_SESSION_ACTIVE`
* does not start the session
* has no side effects

---

## `id()`

```php
public function id(): ?string
```

Returns the current session ID.

### Behavior

* returns `session_id()` when a session is active
* returns `null` when no session is active
* does not start the session

---

## `get()`

```php
public function get(string $key): mixed
```

Retrieves a session value by key.

### Behavior

* lazily starts the session if needed
* returns the stored value when present
* returns `null` when absent
* does not modify session state

### Notes

* keys are case-sensitive
* values may be scalar, array, object, or `null`

---

## `set()`

```php
public function set(string $key, mixed $value): void
```

Stores a value in the session.

### Behavior

* lazily starts the session if needed
* writes directly to `$_SESSION[$key]`
* overwrites any existing value for the same key

### Notes

* keys are case-sensitive
* values must be serializable by the active PHP session handler

---

## `has()`

```php
public function has(string $key): bool
```

Checks whether a session key exists.

### Behavior

* lazily starts the session if needed
* uses `array_key_exists()`
* distinguishes between absent keys and keys explicitly set to `null`

---

## `remove()`

```php
public function remove(string $key): void
```

Removes a session key.

### Behavior

* lazily starts the session if needed
* calls `unset($_SESSION[$key])`
* no-op when the key does not exist

---

## `destroy()`

```php
public function destroy(bool $forgetCookie = true): void
```

Destroys the active session.

### Behavior

The destroy operation performs the following steps:

1. ensure a session is active
2. call `session_unset()`
3. call `session_destroy()`
4. call `session_write_close()`
5. reset local `$_SESSION` to an empty array
6. optionally expire the client session cookie

### Cookie expiry behavior

When `$forgetCookie = true`:

* the session cookie is expired using the active cookie parameters
* path, domain, secure, httponly, and samesite are preserved from the active runtime values
* the expiry timestamp is set to a time in the past

### Notes

* this method is intended for logout and other explicit session teardown flows
* a new session may later be created through `start()` or any lazy read/write call

---

## `regenerate()`

```php
public function regenerate(bool $deleteOld = true): void
```

Regenerates the session ID.

### Behavior

* requires an active session
* calls `session_regenerate_id($deleteOld)`
* stores the current timestamp in `$_SESSION['_sess_rotated_at']`

### Typical use

* after login
* after privilege elevation
* after other security-sensitive state transitions

### Exceptions

| Exception          | Condition                |
| ------------------ | ------------------------ |
| `RuntimeException` | No active session exists |

---

# 6. Startup lifecycle

The service uses a **lazy startup gate** through its internal `ensureStarted()` method.

The startup sequence is:

1. return immediately if the session is already active
2. verify that headers have not already been sent
3. initialize INI directives and cookie parameters once
4. call `session_start()`
5. apply optional fingerprint binding
6. apply optional rotation checks

This lifecycle is deterministic and shared by all lazy public APIs.

### Important implication

Calling `get()`, `set()`, `has()`, or `remove()` can trigger session startup.

Code that must avoid sending session headers late in the response should call `start()` explicitly.

---

# 7. Cookie policy resolution

The service resolves session cookie flags deterministically.

Resolution order for Secure:

1. `session.cookie_secure`
2. `cookie.secure`
3. HTTPS inference from `http.base_url`
4. `Request::isHttps()`
5. internal server-variable fallback

Resolution order for the remaining cookie flags:

* `session.cookie_httponly` -> `cookie.httponly` -> built-in default
* `session.cookie_samesite` -> `cookie.samesite` -> built-in default
* `session.cookie_path` -> `cookie.path` -> built-in default
* `session.cookie_domain` -> `cookie.domain` -> built-in default

### SameSite invariant

`SameSite=None` requires `Secure=true`.

If `SameSite=None` is resolved while Secure is false, the service throws a `RuntimeException`.

This is a hard invariant.

---

# 8. Security model

The Session service includes two optional security mechanisms beyond PHP's native runtime behavior.

## 8.1 Session ID rotation

Rotation is controlled by:

```php
session.rotate_interval
```

Behavior:

* `0` disables interval-based rotation
* when enabled, the service stores the last rotation timestamp in:

```php
$_SESSION['_sess_rotated_at']
```

* if the configured interval has elapsed, the session ID is regenerated

This mechanism is supplementary.

Application code should still explicitly call `regenerate()` after login or privilege changes.

---

## 8.2 Fingerprint binding

Fingerprint binding is controlled by:

```php
session.fingerprint.bind_user_agent
session.fingerprint.bind_ip_octets
session.fingerprint.bind_ip_blocks
```

Behavior:

* the service derives a compact fingerprint from enabled components
* the fingerprint is stored in:

```php
$_SESSION['_sess_fpr']
```

* on mismatch, the service:

  1. destroys the current session
  2. starts a fresh session
  3. stores the new fingerprint

### User agent binding

When enabled, the service stores a SHA-1 hash of the current user agent string.

### IPv4 binding

When enabled, the service binds to the leading IPv4 octets.

Examples:

| Config | Bound prefix      |
| ------ | ----------------- |
| `1`    | `203`             |
| `2`    | `203.0`           |
| `3`    | `203.0.113`       |
| `4`    | full IPv4 address |

### IPv6 binding

When enabled, the service binds to the leading IPv6 16-bit blocks after normalization.

### Notes

* fingerprint binding is best-effort
* aggressive IP binding may cause false mismatches in mobile or proxied environments
* all binding options are disabled by default

---

# 9. Internal state keys

The service reserves the following internal session keys:

| Key                  | Purpose                               |
| -------------------- | ------------------------------------- |
| `'_sess_rotated_at'` | Timestamp of last session ID rotation |
| `'_sess_fpr'`        | Stored session fingerprint            |

Applications should treat these keys as reserved implementation details.

They should not be repurposed for application-level data.

---

# 10. Failure semantics

The service follows a **fail-fast** model.

### It may throw `RuntimeException` when:

* headers were already sent before session startup
* `session_start()` fails
* `SameSite=None` is configured without `Secure=true`
* `regenerate()` is called without an active session

### It intentionally does not throw when:

* a requested key is absent
* a removed key does not exist
* `has()` checks a missing key
* `isActive()` is called before startup
* `id()` is called before startup

This keeps the public API predictable for normal application flows.

---

# 11. Runtime semantics and determinism

The service is intentionally conservative.

Key runtime guarantees:

* startup settings are initialized once per request or process
* startup is idempotent
* no duplicate startup work occurs after activation
* session state access remains direct and thin
* session policy derives from explicit configuration and deterministic fallbacks

The service does not introduce additional abstraction layers over the underlying session store.

It remains a thin wrapper around PHP session primitives.

---

# 12. Performance characteristics

The Session service is designed for low overhead.

Key performance properties:

* no eager session startup
* no heavy boot logic
* no per-call config rebuild beyond normal service access
* no extra serialization layer beyond the native PHP session handler
* no work performed by `isActive()` or `id()`
* optional security checks are cheap and fully conditional

The main cost remains the native PHP session handler and the chosen storage backend.

---

# 13. Operational notes

## Session cookie lifetime

The service always sets cookie lifetime to `0`.

This means the session cookie is a browser-session cookie.

There is no service-level configuration key for overriding lifetime.

## CLI behavior

The service is intended for HTTP runtime usage.

It should not be treated as a general CLI state persistence mechanism.

## Request dependency

The service can consult the Request service for HTTPS detection when available.

If the Request service is absent, it falls back to internal server-variable checks.

---

# 14. Usage examples

## Basic session usage

```php
$this->app->session->set('user_id', 42);

$userId = $this->app->session->get('user_id');

if ($this->app->session->has('user_id')) {
	// Logged-in state exists
}
```

## Explicit early startup

```php
$this->app->session->start();
```

## Logout flow

```php
$this->app->session->destroy();
```

## Rotation after login

```php
$this->app->session->regenerate(true);
$this->app->session->set('user_id', $userId);
```

## Check active state without starting

```php
if ($this->app->session->isActive()) {
	$sessionId = $this->app->session->id();
}
```

---

# 15. Non-goals

The Session service does **not** provide:

* flash message handling
* CSRF protection
* remember-me authentication
* distributed session coordination
* custom session storage abstraction
* application-level authorization state

These concerns belong elsewhere.

In particular, flash handling belongs to the dedicated Flash service rather than the Session service.

---

# 16. Practical guidance

Use the Session service when you need:

* authenticated user state
* short-lived server-side request continuity
* explicit logout and session teardown
* controlled session cookie behavior
* predictable session lifecycle management

Call `start()` explicitly when header timing matters.

Call `regenerate()` explicitly after login and privilege changes.

Use conservative fingerprint settings unless you fully control the client/network environment.

---

# 17. Summary

The CitOmni Session Service is a **thin, deterministic, and security-aware** wrapper around PHP's native session system.

It provides:

* a small public API
* lazy startup
* hardened defaults
* deterministic cookie policy resolution
* optional rotation
* optional fingerprint binding
* explicit and predictable failure semantics

Its purpose is not to be a session framework.

Its purpose is to provide a **stable operational contract** for session handling inside CitOmni applications.
