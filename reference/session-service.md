# CitOmni Session Service Reference

> **Deterministic session handling. Minimal overhead. Security-first defaults.**

The CitOmni Session Service provides a lean wrapper around PHP's native session handling.

It is a first-class service within `citomni/http` and exposes a small, predictable API for session lifecycle, session state and session storage. The attributes of the session cookie come from the Cookie service, so the session cookie follows the same rule as every other cookie of the application.

This document defines the **behavioral contract**, **configuration model**, **runtime semantics**, and **operational guarantees** of the `Session` service.

The intent is not to describe PHP sessions in general, but to document the **exact behavior of the CitOmni implementation**.

---

## Document metadata

* **Service:** `CitOmni\Http\Service\Session`
* **Package:** `citomni/http`
* **Runtime availability:** HTTP runtime
* **PHP version:** >= 8.5
* **Audience:** Framework developers and application integrators
* **Status:** Stable
* **Document type:** Reference

---

# 1. Service overview

The CitOmni Session Service manages PHP sessions using a **lazy-start**, **configuration-driven**, and **security-aware** wrapper.

Key characteristics:

* Reads never create a session; writes do
* Hardened runtime settings, applied explicitly; a setting that PHP refuses throws
* Session cookie attributes resolved by the Cookie service
* Explicit storage retention and garbage collection
* Session id rotation at privilege changes, never on a timer
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
```

Example:

```php
$this->app->session->set('cart_id', $cartId);
$cartId = $this->app->session->get('cart_id');
```

Service initialization occurs lazily when the service is first resolved.

The service performs no eager boot work beyond its normal construction path. Runtime settings are applied once, right before the first `session_start()` of the request.

---

# 3. Ownership

Three components share the work around a browser session. Each owns one part:

| Component                        | Owns                                                                                           |
| -------------------------------- | ---------------------------------------------------------------------------------------------- |
| `Cookie` (`citomni/http`)        | The attributes of every cookie: Secure inference, SameSite, HttpOnly, Path, Domain, validation |
| `Session` (`citomni/http`)       | Session storage and id mechanics: Save path, retention, garbage collection, start, rotation    |
| `Auth` (`citomni/authenticate`)  | Login identity: Who is logged in, the active-session registry, idle and absolute lifetime      |

Consequences:

* Session takes the attributes of the PHP session cookie from `Cookie::attributes()`. The `session.cookie_*` settings override single attributes for the session cookie only.
* `session.gc_maxlifetime` is storage retention, not a login lifetime. A package that accepts a login for longer must raise it, as `citomni/authenticate` does.
* Session keeps no login state of its own.

---

# 4. Configuration

## 4.1 `session.*`

The service reads `$this->app->cfg->session`. The baseline ships every key (`Registry::CFG_HTTP`):

| Key                | Baseline                                       | Description                                                                 |
| ------------------ | ---------------------------------------------- | --------------------------------------------------------------------------- |
| `name`             | `'CITSESSID'`                                  | Session cookie name. `''` keeps PHP's `session.name`.                       |
| `save_path`        | `CITOMNI_APP_PATH . '/var/state/php_sessions'` | Storage directory, created when missing. `''` keeps PHP's save path.        |
| `gc_maxlifetime`   | `1440`                                         | Seconds PHP keeps idle session data (storage retention)                     |
| `gc_probability`   | `1`                                            | With `gc_divisor`: The chance per session start that PHP collects garbage   |
| `gc_divisor`       | `1000`                                         | See `gc_probability` (1/1000)                                               |
| `use_strict_mode`  | `true`                                         | PHP rejects session ids that storage does not know                          |
| `use_only_cookies` | `true`                                         | Session ids travel only in the cookie                                       |
| `lazy_write`       | `true`                                         | Unchanged session data is not rewritten                                     |
| `cookie_secure`    | `null`                                         | Override for Secure; `null` takes `cookie.secure`                           |
| `cookie_httponly`  | `true`                                         | Override for HttpOnly. The baseline keeps the session id away from scripts, whatever `cookie.httponly` says |
| `cookie_samesite`  | `null`                                         | Override for SameSite; `null` takes `cookie.samesite`                       |
| `cookie_path`      | `null`                                         | Override for Path; `null` takes `cookie.path`                               |
| `cookie_domain`    | `null`                                         | Override for Domain; `null` takes `cookie.domain`, `''` forces host-only    |

`citomni/authenticate` raises `session.gc_maxlifetime` to `1209600` (its idle-timeout default) in its own HTTP baseline.

## 4.2 `cookie.*`

The Cookie service reads `$this->app->cfg->cookie`. These are the default attributes of every cookie, the session cookie included:

| Key        | Baseline | Description                                                                                       |
| ---------- | -------- | ------------------------------------------------------------------------------------------------- |
| `secure`   | `null`   | `true`/`false` explicit; `null` infers (see 4.4)                                                  |
| `httponly` | `true`   | HttpOnly attribute                                                                                |
| `samesite` | `'Lax'`  | `'Lax'`, `'Strict'` or `'None'` in any casing; anything else throws                               |
| `path`     | `'/'`    | Path attribute, with a leading slash and no trailing slash; `null` or `''` means `/`              |
| `domain`   | `null`   | `null` or `''` means host-only. A domain shares the cookies with all its subdomains.              |

There is no inference of the domain from `http.base_url`. A Domain attribute widens the scope of a cookie to every subdomain, so it must be configured explicitly, and only when subdomains are meant to share the cookies.

## 4.3 Session cookie attributes

The session cookie is resolved once per request, before `session_start()`:

1. Every non-null `session.cookie_*` value becomes an override.
2. `Cookie::attributes($overrides)` puts the normalized overrides on top of the Cookie defaults and validates the combination. Normalized means, for example, `'/app/'` becomes `/app` and `'.Example.COM'` becomes `example.com`, exactly as for every other cookie.
3. The result is applied to the `session.cookie_*` directives, with lifetime `0` (see 4.8).

Rules that follow from this:

* `cookie.samesite`, `cookie.path`, `cookie.domain` and `cookie.secure` apply to the session cookie unless a `session.cookie_*` value overrides them.
* `session.cookie_httponly` is `true` in the baseline, so the session cookie stays HttpOnly even when `cookie.httponly` is `false`.
* `session.cookie_domain = ''` makes the session cookie host-only even when `cookie.domain` is set.
* The cookie lifetime is always `0` (until the browser closes), also when php.ini says otherwise. Persistent login belongs to a remember-me mechanism, not to the session cookie.

## 4.4 Secure inference

When the resolved Secure value is `null` (both `session.cookie_secure` and `cookie.secure` are `null`), Secure is `true` when any of these says https:

1. `http.base_url` starts with `https://`
2. `CITOMNI_PUBLIC_ROOT_URL` starts with `https://` (Kernel respects the constant when an entry point defines it before boot)
3. `Request::isHttps()`, which honors `http.trust_proxy` and `http.trusted_proxies`

The request service is required. There is no fallback to raw server variables.

An https base URL is a site policy: The cookie is Secure even when this request reached PHP over plain HTTP, as behind a TLS proxy that is not trusted. Browsers only see the https side.

## 4.5 Validation

Invalid cookie configuration throws instead of falling back to another policy:

| Value                                                    | Exception                   |
| -------------------------------------------------------- | --------------------------- |
| `cookie.secure` that is neither a bool nor `null`        | `\RuntimeException`         |
| SameSite other than Lax, Strict or None                  | `\InvalidArgumentException` |
| `secure` or `httponly` that is not a bool                | `\InvalidArgumentException` |
| `domain` that is neither a string nor `null`             | `\InvalidArgumentException` |
| `SameSite=None` without Secure, for any resolved cookie  | `\RuntimeException`         |

A typo such as `'Nnoe'` or the string `'false'` must not silently change the cookie policy.

## 4.6 Storage retention and garbage collection

* `gc_maxlifetime` is how long PHP keeps idle session data. It is not a login lifetime.
* PHP collects garbage with the probability `gc_probability / gc_divisor` per session start. The baseline sets `1/1000` explicitly, because Debian and Ubuntu ship `gc_probability = 0` and clean only the save paths of their php.ini files from cron. That cron job never sees the save path set here, so without the explicit setting, session files would never be collected on those systems.
* Set `gc_probability` to `0` only when a scheduled job cleans `session.save_path`.
* Where garbage collection did not run before, the first collections delete every session file older than `gc_maxlifetime`.

## 4.7 Save path

When `save_path` is not empty:

* the directory is created with `0775` when it is missing
* a directory that cannot be created throws `\RuntimeException` (`Cannot create session.save_path: ...`)
* the path is applied with `ini_set('session.save_path', ...)`

## 4.8 Applied settings

`use_strict_mode`, `use_only_cookies`, `lazy_write`, `gc_maxlifetime`, `gc_probability`, `gc_divisor`, `save_path`, the session name and the session cookie directives (`session.cookie_lifetime`, `_path`, `_domain`, `_secure`, `_httponly`, `_samesite`) are applied with `ini_set()`. When PHP refuses one and a different value stays in place, the service throws `\RuntimeException` and names the directive. PHP refuses a value that it rejects (for example `gc_divisor = 0` or a numeric session name) and a setting that the server locks with `php_admin_value`. A locked setting that already holds the configured value is accepted.

`session_name()` is not used: It only warns about a name that PHP refuses and leaves the previous one in place, so reads would look for a cookie name that the session does not use.

The setup counts as done only when every step succeeded. A caller that catches a refusal and uses the session again in the same request gets the same exception, not a session started on php.ini values.

## 4.9 Removed options

| Option                                   | Behavior now                                   |
| ---------------------------------------- | ---------------------------------------------- |
| `rotate_interval` above `0`              | Throws `\RuntimeException` at session start    |
| `fingerprint` with any binding enabled   | Throws `\RuntimeException` at session start    |
| `rotate_interval = 0`, disabled bindings | Ignored                                        |
| `sid_length`, `sid_bits_per_character`   | Ignored                                        |

Why:

* Periodic rotation called `regenerate(true)` on a timer. The old session is deleted at once, so every concurrent request that still presented the old id lost the session (see 9).
* Fingerprint binding used the raw `REMOTE_ADDR`, which behind a proxy is the proxy's address, and on a mismatch it destroyed the session without telling the code that owned its contents.
* Failing loudly beats silently ignoring a security setting that someone enabled on purpose.
* PHP 8.4 deprecated changing the session id length and alphabet.

## 4.10 Example configuration

```php
'session' => [
	'name'           => 'CITSESSID',
	'gc_maxlifetime' => 1209600,  // At least the longest idle login (citomni/authenticate sets this)
],
'cookie' => [
	'samesite' => 'Lax',
	'domain'   => null,           // Host-only; set 'example.com' only to share cookies with subdomains
],
```

---

# 5. Public API

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

# 6. Method reference

## `start()`

```php
public function start(): void
```

Explicitly starts the session, creating it when it does not exist.

### Behavior

* no-op if a session is already active
* otherwise runs the startup pipeline (see 8)
* guarantees that `$_SESSION` is available on return

### Notes

* `set()` and `regenerate()` start the session when needed; reads do not.
* Use it when a session must exist before the first write, or when its headers should be sent early.

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

### Notes

* `false` does not mean that the request has no session. A session is resumed on its first read or write, so a request with the session cookie reports `false` until then.

---

## `id()`

```php
public function id(): ?string
```

Returns the current session id.

### Behavior

* returns `session_id()` when a session is active
* returns `null` when no session has been started or resumed yet
* does not start the session

---

## `get()`

```php
public function get(string $key): mixed
```

Retrieves a session value by key.

### Behavior

* resumes an existing session (see 7); never creates one
* returns the stored value when present
* returns `null` when the key or the session is missing
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

* starts the session when it is not active; a write creates the session
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

* resumes an existing session; never creates one
* returns `false` without a session
* uses `array_key_exists()`, so it distinguishes between absent keys and keys explicitly set to `null`

---

## `remove()`

```php
public function remove(string $key): void
```

Removes a session key.

### Behavior

* resumes an existing session; never creates one
* calls `unset($_SESSION[$key])`
* no-op when the key or the session does not exist

---

## `destroy()`

```php
public function destroy(bool $forgetCookie = true): void
```

Destroys the session.

### Behavior

1. resume an existing session; without one, return without headers
2. clear `$_SESSION`
3. call `session_destroy()`; throw when storage refuses (see below)
4. remove the session cookie from `$_COOKIE`, so a later read in the same request does not resume the destroyed id
5. when `$forgetCookie = true`, expire the session cookie with the attributes it was set with (path, domain, secure, httponly, samesite)

### Failure

When storage refuses the destruction, PHP closes the session and keeps its data. The method throws `\RuntimeException` before it touches the cookie, so storage and browser keep the session unchanged and the request can be retried.

A refused cookie header (headers already sent) does not throw. The browser keeps an id whose session no longer exists, and strict mode gives the next request that uses the session a new one.

### Notes

* After `destroy()`, the next write creates a new session with a new id.

---

## `regenerate()`

```php
public function regenerate(bool $deleteOld = true): void
```

Regenerates the session id.

### Behavior

* starts the session when it is not active yet, like `set()`
* calls `session_regenerate_id($deleteOld)`; the session data moves to the new id
* throws `\RuntimeException` when PHP refuses the rotation, instead of continuing on the previous id

PHP refuses the rotation when headers were already sent, and when the storage handler cannot destroy the old session (`$deleteOld = true`). In the latter case PHP also closes the session.

When no session existed yet, `session_start()` issues an id and the call rotates it once more. PHP replaces the queued `Set-Cookie` header, so only the final id reaches the client.

### Typical use

* after login and logout (`citomni/authenticate` does both)
* after privilege elevation
* after other security-sensitive state transitions

See 9 for the behavior under concurrent requests.

---

# 7. Reads and session creation

Only `start()`, `set()` and `regenerate()` create a session. `get()`, `has()`, `remove()` and `destroy()` resume a session that exists and otherwise leave the request untouched.

A read resumes the session when:

1. a session is active, or
2. a session was established earlier in this request and closed since (`session_write_close()` keeps the id in `session_id()`), or
3. the request carries the session cookie as a non-empty string.

Otherwise the read returns `null`, `false` or nothing, without touching storage or sending headers. A guest page that reads session state gets no session cookie, no session file and none of the cache headers that `session_start()` sends (`Expires`, `Cache-Control: no-store, no-cache, must-revalidate`, `Pragma: no-cache`), so it stays cacheable.

Edge cases:

* Under strict mode, a cookie whose id storage does not know (expired, collected, forged) still starts one new, empty session. Its cookie replaces the stale one.
* Array-shaped cookie input (`CITSESSID[]=x`) counts as absent.
* Only the session cookie counts. With `session.use_only_cookies` off, reads do not resume an id that arrives in the URL.

The Flash and Csrf services follow the same rule: Flash readers and CSRF verification never create a session. Code that relied on a read to create the session calls `start()`.

---

# 8. Startup lifecycle

Every path to `session_start()` goes through one internal gate:

1. return immediately if the session is already active
2. throw when headers were already sent
3. once per request, initialize the runtime settings; after a throw, the next start runs this step again:
   1. refuse removed options that are still enabled (see 4.9)
   2. apply the hardening and storage settings, and create `save_path` (see 4.6 to 4.8)
   3. apply the session name
   4. resolve the session cookie through `Cookie::attributes()` and apply it (see 4.3)
4. call `session_start()`; throw when it fails

---

# 9. Rotation and concurrency

`regenerate(true)` deletes the old session at once. A concurrent request that still presents the old id then gets a new, empty session, and its `Set-Cookie` may replace the new id in the browser. There is no grace window.

Consequences:

* Rotate at privilege changes (login, logout, elevation), not on a timer. This is why `session.rotate_interval` was removed.
* `regenerate(false)` keeps the old id usable with a copy of the data until garbage collection. It avoids the race but leaves the old id valid, so it is no defense against session fixation at login.
* `citomni/authenticate` stores the registry key of a login in the session, so a later rotation by application code keeps the login registered.

---

# 10. Failure semantics

The service follows a **fail-fast** model.

### It throws `RuntimeException` when:

* headers were already sent before the session starts
* `session_start()` fails
* PHP refuses a setting, the session name and the cookie directives included, and a different value stays in place
* `session.save_path` cannot be created
* `session.rotate_interval` or `session.fingerprint` is still enabled
* `session_regenerate_id()` fails
* `session_destroy()` fails

Invalid cookie attributes throw from the Cookie service (see 4.5).

### It intentionally does not throw when:

* a requested key or the session is absent
* a removed key does not exist
* `has()` checks a missing key
* `destroy()` finds no session
* `isActive()` or `id()` is called before startup

---

# 11. Interplay with citomni/authenticate

* `Auth` stores the identity id and the registry key (the SHA-256 of the id issued at login) in the session. A rotation outside Auth keeps the login registered.
* `Auth` refuses to start (`session_retention_too_short`) when `session.gc_maxlifetime` is shorter than a nonzero `authenticate.session.idle_timeout_seconds`. Its HTTP baseline sets both to `1209600`; an application that overrides one must override the other.
* `Auth` reads its state through `has()` and `get()`, so the identity check on a guest page creates no session.
* `Support\AuthCookie` writes the remember-me and device cookies host-only, and expires them in every domain scope of the host whenever it writes or deletes them: The request host and each of its parent domains, plus those of `CITOMNI_PUBLIC_ROOT_URL` (else `http.base_url`). This clears cookies that earlier versions of the Cookie service wrote with the base URL's host as Domain.

Deploy `citomni/authenticate` before or together with the `citomni/http` version described here. Its cookie scopes and retention settings are what make the host-only default and the explicit garbage collection safe.

---

# 12. Upgrading from earlier versions

* Remove `session.rotate_interval` and `session.fingerprint` from application and provider configuration. While either is enabled, every request that uses the session throws. Remove `sid_length` and `sid_bits_per_character`; they are ignored.
* Cookies are host-only unless `cookie.domain` is set. An application that shares cookies with subdomains must set it. Cookies written earlier with the inferred domain stay in browsers until they expire, and an older cookie with the same name shadows a new host-only one: The browser sends the older cookie first, and PHP keeps the first value. Expire the domain scope once, or rename long-lived cookies.
* The session cookie follows `cfg.cookie`. An application that set `cookie.samesite` or `cookie.path` for its other cookies and wants the previous session cookie sets `session.cookie_samesite = 'Lax'` and `session.cookie_path = '/'`.
* Garbage collection runs at 1/1000 per session start, also on Debian and Ubuntu. Keep `gc_maxlifetime` at least as long as the longest idle login.
* A server that locks a session setting with `php_admin_value` must lock it to the configured value.
* Reads no longer create a session. Code that needs the session cookie early calls `start()`.

---

# 13. Performance characteristics

The Session service is designed for low overhead.

Key performance properties:

* no eager session startup
* no storage access and no headers for reads in a request without a session
* runtime settings initialized once per request, only when a session starts
* no extra serialization layer beyond the native PHP session handler
* no work performed by `isActive()` or `id()`

The main cost remains the native PHP session handler and the chosen storage backend.

---

# 14. Usage examples

## Basic session usage

```php
$this->app->session->set('cart_id', 42);

$cartId = $this->app->session->get('cart_id');

if ($this->app->session->has('cart_id')) {
	// A cart exists
}
```

## Explicit early startup

```php
$this->app->session->start();
```

## Discarding the session

```php
$this->app->session->destroy();
```

## Rotation at a privilege change

```php
$this->app->session->regenerate(true);
$this->app->session->set('elevated_until', \time() + 300);
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
* login identity or remember-me authentication
* cookie attribute policy (the Cookie service owns it)
* distributed session coordination
* custom session storage abstraction
* application-level authorization state

These concerns belong elsewhere.

In particular, flash handling belongs to the dedicated Flash service, and login state to `citomni/authenticate`.

---

# 16. Practical guidance

Use the Session service when you need:

* short-lived server-side request continuity
* multi-step form state
* explicit session teardown
* predictable session lifecycle management

Call `start()` explicitly when header timing matters.

Call `regenerate()` explicitly after login and privilege changes.

Leave `cookie.domain` unset unless subdomains must share the cookies.

---

# 17. Summary

The CitOmni Session Service is a **thin, deterministic, and security-aware** wrapper around PHP's native session system.

It provides:

* a small public API
* reads that never create a session
* explicitly applied storage, retention and garbage collection settings
* a session cookie that follows the Cookie service's rule
* rotation at privilege changes, with documented behavior under concurrency
* explicit and predictable failure semantics

Its purpose is not to be a session framework.

Its purpose is to provide a **stable operational contract** for session handling inside CitOmni applications.
