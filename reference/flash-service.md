---
title: Flash Service (HTTP)
author: CitOmni Core Team
author_url: https://github.com/citomni
version: 1.0
date: 2025-10-28
status: Stable
package: citomni/http
---

# Flash Service (HTTP)
> **Version:** 1.0  
> **Audience:** Application developers, provider authors  
> **Scope:** citomni/http (service ID: `flash`)  
> **Language level:** PHP ≥ 8.2  
> **Status:** Stable

---

## 1. Introduction

The **Flash** service provides a tiny, deterministic **one-request message store** designed for PRG flows (POST -> Redirect -> GET). It persists short-lived **messages** (e.g., `success`, `error`) and **"old input"** (form fields for re-population) across a single redirect boundary.

Flash operates on top of CitOmni's **Session** service (no direct superglobals), is **side-effect free** during resolution, and enforces strict **caps** to avoid unbounded growth. Its API is intentionally small to keep hot paths **cheap** and **predictable**.

The terminology, merge order, and service registration used here follow the same deterministic principles defined in CitOmni's mode and service system. :contentReference[oaicite:0]{index=0}

---

## 2. Responsibilities & Non-Responsibilities

### 2.1 Responsibilities
- Store **messages** under logical keys (e.g., `success`, `info`, `warning`, `error`).
- Store **old input** (associative key/value) for the next HTTP request only.
- Provide **read**, **peek**, **take**, and **pull** semantics with **clear-on-read** options.
- Support **reflash** semantics via a single-shot `keep()` flag.

### 2.2 Non-Responsibilities
- Rendering or formatting of messages (UI is caller's concern).
- Cross-tab or cross-device synchronization (session-scoped only).
- Arbitrary data warehousing (strict caps are enforced).
- Direct use of `$_SESSION`, `$_GET`, or `$_POST` (goes through CitOmni services).

---

## 3. Service Identity & Ownership

- **Service ID:** `flash`  
- **Namespace:** `CitOmni\Http\Service\Flash`  
- **Ownership:** `citomni/http` package (HTTP mode)  
- **Lifespan:** one instance per request/process (standard Service semantics)

Registration is via the HTTP **service map**; see §10. :contentReference[oaicite:1]{index=1}

---

## 4. Data Model (Session Keys & Limits)

### 4.1 Session Keys

| Key           | Type                                   | Description                                   |
|--------------|----------------------------------------|-----------------------------------------------|
| `_flash.msg` | `array<string, string|array<mixed>>`   | Message buckets keyed by logical name.        |
| `_flash.old` | `array<string, mixed>`                 | Old input associative map.                    |
| `_flash.keep`| `bool`                                  | Single-shot keep flag (reflash behavior).     |

> Flash is **isolated** from any internal "session flash" helpers you may use elsewhere; it **does not** rely on `Session::_flash/_flash_next`. This prevents coupling and keeps semantics explicit.

### 4.2 Limits (Fail-Fast Caps)

| Constant             | Default | Meaning                                                                    |
|----------------------|---------|----------------------------------------------------------------------------|
| `MAX_MSG_KEYS`       | 32      | Max number of distinct message buckets.                                    |
| `MAX_MSG_LIST_SIZE`  | 16      | Max list length **per** bucket (append semantics; evict oldest on overflow).|
| `MAX_OLD_KEYS`       | 64      | Max number of distinct old-input keys.                                     |
| `MAX_MSG_STRLEN`     | 2048    | Max **bytes** for string payload (UTF-8 safe truncation).                  |

Exceeding a cap throws `\RuntimeException` (fail fast; handled by global ErrorHandler).

---

## 5. API Reference

All methods are **O(1)** against session structures and avoid reflection, filesystem I/O, or global state mutation beyond the Session API.

### 5.1 Messages

```php
// Replace a bucket with a payload (string or array).
public function set(string $key, string|array $message): void;

// Append to a bucket (promotes existing string to list).
// On overflow, evicts oldest entries to keep the newest N items.
public function add(string $key, string|array $message): void;

// Convenience writers: map to add()
public function success(string $message): void;
public function info(string $message): void;
public function warning(string $message): void;
public function error(string $message): void;

// Read-and-clear a single bucket (idempotent when absent).
public function take(string $key): string|array|null;

// Read without clearing a single bucket.
public function peek(string $key): string|array|null;

// Peek all bags without clearing.
public function peekAll(): array{
	// ['msg' => array<string, string|array>, 'old' => array<string, mixed>]
}
````

### 5.2 Old Input

```php
// Merge old input (left-biased: new overrides existing keys).
public function old(array $fields): void;

// Read old input without clearing; default fallback if absent.
public function oldValue(string $key, mixed $default = null): mixed;

// Presence check for old input.
public function hasOld(string $key): bool;
```

### 5.3 Lifecycle & Clearing

```php
// Pull everything at once; clear both bags unless keep() was set.
// If keep() was set, preserves bags and consumes the keep flag.
public function pullAll(): array{
	// ['msg' => array<string, string|array>, 'old' => array<string, mixed>]
}

// Set or clear the single-shot keep flag (reflash).
public function keep(bool $enable = true): void;

// Hard clear of both bags and keep flag.
public function clear(): void;

// Selective removal helpers.
public function forgetMsg(string $key): void;
public function forgetOld(array $keys): void;
```

---

## 6. Semantics & Guarantees

1. **Lazy session start:** Flash never starts sessions during `init()`. Session starts only **on first use** via `Session::start()` (through the service).
2. **Deterministic bags:** `_flash.msg` and `_flash.old` are always arrays when present; created lazily in `ensureBags()`.
3. **Reflash behavior:** Calling `keep(true)` sets `_flash.keep`. The next `pullAll()` **preserves** bags and **consumes** `_flash.keep`.
4. **UTF-8 safety:** `MAX_MSG_STRLEN` applies to **bytes**, but trimming backs off to the last valid UTF-8 boundary.
5. **Fail fast:** Caps are enforced with `\RuntimeException`; no global catch within the service.

---

## 7. Typical Usage

### 7.1 POST -> Redirect -> GET (PRG)

**Controller (POST action):**

```php
// Validate...
$this->app->flash->error('Invalid login');             // or ->success(...)
$this->app->flash->old($this->app->request->only(['username']));
$this->app->response->redirect('login.html');          // short-circuit exit
```

**Controller (GET action):**

```php
$flash = $this->app->flash->pullAll();                  // read + clear (normal case)
$error = $flash['msg']['error'] ?? null;
$old   = $flash['old'] ?? [];
$this->app->view->render('auth/login.html', compact('error', 'old'));
```

### 7.2 Multi-step Forms (Keep Once)

```php
// Step 1 succeeded; keep messages + old input for the next view as well.
$this->app->flash->success('Step 1 complete');
$this->app->flash->keep();
$this->app->response->redirect('wizard/step-2.html');
```

### 7.3 Buckets as Lists

```php
$this->app->flash->add('info', 'Imported 42 items');
$this->app->flash->add('info', 'Cleaned duplicates');
$this->app->flash->add('info', 'Done'); // oldest evicted on overflow
```

---

## 8. Interoperability (Session, Request, Response)

* **Session:** Flash exclusively uses `Session`'s public API (`isActive()`, `start()`, `has()`, `get()`, `set()`, `remove()`). It does **not** assume any specific session handler or cookie name.
* **Request:** Flash is transport-agnostic; it does not read `$_POST/$_GET` itself. Use `Request::only()` / `except()` to assemble old input.
* **Response:** Flash does not send headers; controllers are responsible for `redirect()` and content rendering.

---

## 9. Performance Characteristics

* **Hot path budget:** All operations are in-memory manipulations of small arrays.
* **Zero I/O:** No filesystem access, no reflection, no directory scans.
* **Side-effect free `init()`:** Service resolution is cheap and predictable.
* **Caps prevent growth:** Guards avoid pathological memory usage from unbounded messages.

> For broader boot and merge determinism (config + services) see the runtime and service merge references. 

---

## 10. Registration & Configuration

### 10.1 Provider Map (preferred for reusable packages)

```php
// src/Boot/Services.php
namespace CitOmni\Http\Boot;

final class Services {
	public const MAP_HTTP = [
		'flash' => \CitOmni\Http\Service\Flash::class,
	];

	public const CFG_HTTP = [];  // Flash does not require config
	public const MAP_CLI  = self::MAP_HTTP;
	public const CFG_CLI  = self::CFG_HTTP;
}
```

### 10.2 Application Map (override or app-local)

```php
// /config/services.php
<?php
return [
	'flash' => \CitOmni\Http\Service\Flash::class,
];
```

> **Configuration:** The Flash service does not consume `$app->cfg` today. Caps are **constants** by design to keep runtime semantics invariant across environments.

---

## 11. Error Handling

* **Exceptions:** The service throws `\RuntimeException` when caps are exceeded or when an invariant is violated (e.g., a bucket already containing neither string nor array).
* **No catch-all:** The service never suppresses exceptions; the **global ErrorHandler** is responsible for logging and rendering.
* **Headers:** Flash does not interact with headers; any redirect/JSON rendering is performed by `Response`.

---

## 12. Security Considerations

* **Scope:** Flash data is session-scoped; it is **not** CSRF protection. Use the CSRF mechanisms defined in HTTP security guides.
* **Size & content:** Message arrays may include arbitrary structures; **sanitize at render time** if any content can originate from user input.
* **Keep flag:** `keep()` preserves data only within the same session; it does not extend lifetime beyond normal session expiration.

---

## 13. Troubleshooting

| Symptom                            | Likely Cause / Fix                                                                   |
| ---------------------------------- | ------------------------------------------------------------------------------------ |
| Messages disappear after redirect  | You called `pullAll()` twice; use `peekAll()` to inspect without clearing.           |
| Messages persist unexpectedly      | `keep()` was set; ensure it is not called or call `keep(false)` to cancel.           |
| Buckets "lose" entries             | `MAX_MSG_LIST_SIZE` evicted oldest items; increase at code level if truly needed.    |
| Cap exceptions (`key cap reached`) | Too many distinct message buckets or old-input keys; consolidate or clear earlier.   |
| No messages across redirect        | Session not starting; ensure `Session` is available and not blocked by headers sent. |

---

## 14. Testing Guidance

Use the Kernel to construct a minimal HTTP app for tests, then instantiate the service with the standard service contract:

```php
public function testFlashSetAndPull(): void {
	$app = new \CitOmni\Kernel\App(__DIR__ . '/_fixtures/config', \CitOmni\Kernel\Mode::HTTP);
	$flash = new \CitOmni\Http\Service\Flash($app, []);

	$flash->success('Hello');
	$out = $flash->pullAll();

	$this->assertSame('Hello', $out['msg']['success'][0] ?? null);
	$this->assertSame([], $flash->peekAll()['msg'] ?? []); // cleared on pullAll()
}
```

> Tests should **not** rely on implicit globals; drive everything through Services (`Session`, `Response`, etc.) to keep them hermetic. 

---

## 15. FAQ

**Q: Why not store all messages as lists?**
A: `set()` allows a natural "single value" bucket, while `add()` promotes to a list when needed. This keeps simple cases efficient and avoids unnecessary array allocations.

**Q: Can we make caps configurable?**
A: We deliberately keep caps as **class constants** to preserve invariant semantics across environments and avoid accidental environment-driven regressions. If you need different caps, subclass/override via the service map.

**Q: How does this relate to `Session::flash()` methods?**
A: They are independent. Flash maintains its **own keys** and semantics to avoid coupling; you may use either in different layers of your app.

---

## 16. Changelog

* **1.0 (2025-10-28):** Initial stable document for `citomni/http` Flash service.

---