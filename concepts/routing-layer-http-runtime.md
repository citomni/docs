# Routing Layer - CitOmni HTTP Runtime (v1.0)
*A deterministic architecture for predictable, high-performance routing.*

---

**Document type:** Technical Architecture  
**Version:** 1.0  
**Applies to:** CitOmni ≥ 8.2  
**Audience:** Framework developers, provider authors, and integrators  
**Status:** Stable and foundational  
**Author:** CitOmni Core Team  
**Copyright:** © 2012-present CitOmni

---

## 1. Overview

The **Routing Layer** is the deterministic entry point of the CitOmni HTTP runtime.  
It translates incoming request paths into concrete controller actions, enforcing predictable, cache-friendly resolution with zero dynamic scanning.

Routing in CitOmni is not a magical discovery process-it is a **merge of explicit, static route maps** built at application boot.  
Every route definition is pure data (`array<string,mixed>`), compiled at deploy time into an in-memory array with *no runtime evaluation or reflection*.

The result is a routing system that is:
- **Deterministic:** The same request always resolves the same controller.  
- **Fast:** Single in-memory lookup, sub-millisecond matching.  
- **Side-effect-free:** No dynamic autoloads or filesystem I/O once caches are warm.  
- **Transparent:** Every effective route is inspectable under `/appinfo.html`.

---

## 2. Design Philosophy

CitOmni's routing model is built on three principles:

| Principle | Description |
|------------|-------------|
| **Determinism** | Routes are resolved purely from static arrays merged during boot. No scanning, no annotation parsing. |
| **Fail-fast** | Invalid routes (missing controller/action/methods) trigger an immediate HTTP 500 or 404 with full context. |
| **Purity** | The router performs no application logic; it delegates to controllers and the error handler. |

These principles ensure that routing remains a **pure mapping layer**-not a behavioral one.

*Note:* All runtime faults are handled centrally through the ErrorHandler service; no separate "error route" definitions exist in the routing layer.

---

## 3. Routing Merge Model

Routes are assembled by the application kernel (`App::buildRoutes()`) at startup.

### 3.1 Merge Order (last-wins)

| Priority | Source | Symbol | Description |
|-----------|---------|---------|-------------|
| 1 | Vendor baseline | `\CitOmni\Http\Boot\Routes::MAP_HTTP` | Default framework routes (e.g., `/legal/`, `/maintenance`) |
| 2 | Providers | `ROUTES_HTTP` | Optional provider-level routes (listed in `/config/providers.php`) |
| 3 | Application base | `/config/citomni_http_routes.php` | Developer-defined application routes |
| 4 | Environment overlay | `/config/citomni_http_routes.{ENV}.php` | Per-environment overrides (e.g., dev/stage/prod) |

Merge semantics follow CitOmni's global "**last-wins**" rule:
- Associative arrays are merged recursively (later keys overwrite earlier ones).
- Numeric arrays are replaced wholesale.
- Empty arrays still count as valid overrides.

> Within the provider step, order in /config/providers.php also follows last-wins: routes from later providers overwrite identical path keys from earlier providers.

### 3.2 Purity and Caching

Each route layer is pure PHP returning an array:

```php
<?php
return [
	'/contact.html' => [
		'controller'    => \App\Http\Controller\ContactController::class,
		'action'        => 'index',
		'methods'       => ['GET'],
		'template_file' => 'public/contact.html',
		'template_layer'=> 'app',
	],
];
```

At deploy time, the complete merged result is compiled into
`/var/cache/routes.http.php` (or `.cli.php` for CLI mode) by `App::warmCache()`.

---

## 4. File Structure and Scope

| Path                                       | Purpose                                                              |
| ------------------------------------------ | -------------------------------------------------------------------- |
| `/config/citomni_http_routes.php`          | Application-level route definitions                                  |
| `/config/citomni_http_routes.{env}.php`    | Optional environment overlay (dev/stage/prod)                        |
| `/config/providers.php`                    | Lists provider classes that may each expose a `ROUTES_HTTP` constant |
| `/vendor/citomni/http/src/Boot/Routes.php` | Vendor baseline route map                                            |
| `/var/cache/routes.http.php`               | Precompiled, read-only runtime route array                           |

Each of these sources is optional except the vendor baseline; the kernel automatically skips empty or missing maps.

---

## 5. Route Definition Structure

Every route definition is a flat associative array keyed by the public path.
Keys are literal strings representing the URL path, or the special group regex for parameterized routes.
(Error routes are not best practice in CitOmni; faults should be delegated to the global ErrorHandler.)

### 5.1 Standard Route Keys

| Key              | Type          | Required | Description                                                                    |
| ---------------- | ------------- | -------- | ------------------------------------------------------------------------------ |
| `controller`     | string (FQCN) | ✔        | Fully-qualified controller class name                                          |
| `action`         | string        | ✔        | Method on the controller to invoke                                             |
| `methods`        | string[]      | ✔        | Allowed HTTP methods (e.g., `['GET']`, `['POST']`)                             |
| `template_file`  | string        | optional | Path within provider/app `templates/`                                          |
| `template_layer` | string        | optional | Provider or app namespace layer (e.g., `citomni/auth`, `aserno/byportal-core`) |
| `redirect`       | string        | optional | Target path for 30x redirects                                                  |
| `status`         | int           | optional | Custom fixed HTTP status (e.g., 410 Gone)                                      |
| `headers`        | array         | optional | Extra headers to inject into the response                                      |
| `middleware`     | array         | optional | Future reserved (not yet part of minimal core)                                 |

### 5.2 Example

```php
// -----------------------------
// Public auth (GET views)
// -----------------------------
'/login.html' => [
	'controller'    => \CitOmni\Auth\Controller\AuthController::class,
	'action'        => 'login',
	'methods'       => ['GET'],
	'template_file' => 'public/login.html',
	'template_layer'=> 'citomni/auth',
],

// -----------------------------
// Public auth (POST actions, PRG targets)
// -----------------------------
'/login' => [
	'controller' => \CitOmni\Auth\Controller\AuthController::class,
	'action'     => 'loginPost',
	'methods'    => ['POST'],
],
```

---

## 6. Content-Type Contract

CitOmni enforces explicit content semantics via **suffixes**:

| Suffix   | Purpose                          | Expected Output        | Example           |
| -------- | -------------------------------- | ---------------------- | ----------------- |
| `.html`  | Human-facing page                | `text/html`            | `/login.html`     |
| `.json`  | Programmatic endpoint            | `application/json`     | `/api/login.json` |
| *(none)* | Action-only (e.g., PRG redirect) | Redirect / status-only | `/login`          |

Suffixes are part of the **public API contract** and must be treated as stable entrypoints.

---

## 7. Regex and Parameterized Routes

### 7.1 Definition

Dynamic routes reside under the `regex` key as an ordered list.
Each entry defines a `pattern` (without delimiters) and the same structure as standard routes.

```php
'regex' => [
	[
		'pattern'    => '^/article/(?P<slug>[a-z0-9\-]+)\.html$',
		'controller' => \App\Http\Controller\ArticleController::class,
		'action'     => 'view',
		'methods'    => ['GET'],
	],
],
```

### 7.2 Built-in Placeholders

The framework provides convenience macros for common placeholders:

* `{id}` -> `(?P<id>[0-9]+)`
* `{slug}` -> `(?P<slug>[a-z0-9\-]+)`
* `{email}` -> `(?P<email>[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})`
* `{code}` -> `(?P<code>[A-Za-z0-9]{6,})`

These are resolved internally by the router during map compilation.

---

## 8. Error Handling Integration

CitOmni employs a single, centralized **ErrorHandler** service that governs all fault conditions (router 404/405/5xx responses, uncaught exceptions, PHP errors, and fatal shutdowns).

Under normal circumstances, framework components and controllers do **not** catch or handle errors themselves.  
CitOmni's design philosophy is *fail fast, bubble up*: exceptions and PHP errors are allowed to propagate to the global ErrorHandler, which then logs, classifies, and renders the response.

When an HTTP-level fault must be triggered intentionally (for example, a missing resource or invalid route), the recommended entrypoint is:

```php
$this->app->errorHandler->httpError($status, [...context...]);
```

Typical explicit invocations from the Router include:

```php
// 404 - no matching route found
$this->app->errorHandler->httpError(404, [
    'path'   => $uri,
    'method' => $method,
    'reason' => 'route_not_found',
]);

// 500 - controller or action missing
$this->app->errorHandler->httpError(500, [
    'reason'     => 'controller_missing',
    'controller' => (string)$controller,
    'action'     => $action,
    'route'      => $route,
]);
```

The **ErrorHandler** service writes structured JSONL logs (with rotation), performs automatic content negotiation (HTML vs. JSON), and emits safe, non-leaking responses based on configuration under `cfg.error_handler`.

This separation ensures that fault management remains deterministic, global, and side-effect-free, while preserving minimal overhead within the routing layer itself.

---

## 9. Runtime Behavior

### 9.1 Resolution Order

1. **Exact match:** `/path.html`, `/about.html`, `/api/foo.json`
2. **Regex group:** entries under `'regex'`, evaluated in array order.
3. **No match or invalid target:** Router delegates to `ErrorHandler->httpError(...)` instead of routing to an error controller.

### 9.2 Controller Invocation

Upon a match, the router performs:

```php
$controllerInstance = new $controller($this->app, [
	'template_file'  => $route['template_file']  ?? null,
	'template_layer' => $route['template_layer'] ?? null,
]);
\call_user_func_array([$controllerInstance, $action], $params);
```

* Controllers are **instantiated deterministically** with `$app` injection.
* If `$action` does not exist, a 500 "action_missing" is raised immediately.
* The router does not catch exceptions; they propagate to the global error handler.

> Note: init() is auto-invoked only when the controller extends CitOmni\Kernel\Controller\BaseController.

---

## 10. Caching and Performance

Routes are precompiled into
`/var/cache/routes.http.php`
via `App::warmCache()` together with config and service maps.

| Mode | Cache File                  | Contents                    |
| ---- | --------------------------- | --------------------------- |
| HTTP | `var/cache/routes.http.php` | Final merged route array    |
| CLI  | `var/cache/routes.cli.php`  | CLI command routes (if any) |

When OPcache runs with `validate_timestamps=0`, route updates require `opcache_reset()` after deploy.
The runtime performs **zero file I/O** during route lookup when caches are warm.

---

## 11. Best Practices

1. **Explicit over implicit:** Always declare full controller FQCN and allowed methods.
2. **Stable suffixes:** Treat `.html` and `.json` suffixes as part of your public interface.
3. **No logic in route files:** Keep route maps pure; business logic belongs in controllers.
4. **Environment overlays:** Use `/citomni_http_routes.stage.php` for staging variations instead of `if (ENV) ...` in code.
5. **Keep it deterministic:** Never modify `$app->routes` at runtime.
6. **Use `App::warmCache()` after route changes** to maintain atomic caches.
7. **Error delegation:** Never define or rely on "error routes" (e.g., `/404`). All faults must bubble to the global **ErrorHandler** instead, using `$this->app->errorHandler->httpError(...)` from the Router or controller level.
8. **Green performance:** Do not build or merge routes dynamically; the router should execute in ≤0.001 s.

---

## Appendix A - Example Complete Route File

```php
<?php
declare(strict_types=1);

use CitOmni\Auth\Controller\AuthController;
use CitOmni\Http\Controller\ErrorController;

return [

	// -----------------------------
	// Public auth (GET views)
	// -----------------------------
	'/login.html' => [
		'controller'    => AuthController::class,
		'action'        => 'login',
		'methods'       => ['GET'],
		'template_file' => 'public/login.html',
		'template_layer'=> 'citomni/auth',
	],

	// -----------------------------
	// Public auth (POST actions, PRG targets)
	// -----------------------------
	'/login' => [
		'controller' => AuthController::class,
		'action'     => 'loginPost',
		'methods'    => ['POST'],
	],

	// -----------------------------
	// Regex examples
	// -----------------------------
	'regex' => [
		[
			'pattern'    => '^/member/(?P<id>[0-9]+)\.html$',
			'controller' => \App\Http\Controller\MemberController::class,
			'action'     => 'view',
			'methods'    => ['GET'],
		],
	],

];
```

---

**End of document**

CitOmni Framework - Deterministic by design.
