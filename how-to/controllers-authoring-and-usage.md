# CitOmni Controllers - Authoring and Usage (PHP 8.2+)

> **Transient, explicit, deterministic.**  
> Controllers define the public execution surface of CitOmni HTTP applications.

This document describes **how to design, author, and use Controllers** in CitOmni.  
It explains their **lifecycle**, **constructor contract**, **interaction with the Router**, and best practices for **security**, **rendering**, and **error propagation**.  
Controllers are not services-they are **instantiated per request**, dispatched once, and discarded.

---

**Document type:** Technical Guide  
**Version:** 1.0  
**Applies to:** CitOmni ≥ 8.2 (HTTP mode)  
**Audience:** Application and provider developers  
**Status:** Stable and foundational  
**Author:** CitOmni Core Team  
**Copyright:** © 2012-present CitOmni

---

## 1. What is a Controller?

A **Controller** is the execution endpoint for an HTTP route.  
It transforms an incoming request into a response by invoking one of its **action methods**.

Controllers are:
- **Transient** - one instance per request/action.
- **Declarative** - referenced by route maps, not registered or auto-scanned.
- **Deterministic** - constructed and executed synchronously by the Router.
- **Service-backed** - use `$this->app->serviceId` for dependencies.
- **Pure in purpose** - they perform request handling, not domain logic.

The Router instantiates controllers directly based on route definitions:

```php
$controller = new $controllerClass($app, $routeConfig);
$controller->$action(...$params);
```

---

## 2. Constructor contract

Controllers follow a strict constructor signature:

```php
public function __construct(\CitOmni\Kernel\App $app, array $routeCtx = [])
```

* `$app` - The current application instance, granting access to config and services.
* `$routeCtx` - Route metadata: Typically `['template_file' => ..., 'template_layer' => ...]`.

Controllers may optionally define an `init()` method that runs automatically after construction (init() is only auto-invoked when the controller extends CitOmni\Kernel\Controller\BaseController. If you don't extend it, init() won't be called automatically).

---

## 3. Lifecycle and invocation

### 3.1 Creation flow

1. The Router matches a route definition.
2. It instantiates the controller class with `(App $app, array $routeCtx)`.
3. If `init()` exists, it is called once (when extending CitOmni\Kernel\Controller\BaseController).
4. The Router invokes the action method (e.g., `index()` or `postAction()`).
5. The controller finishes and is discarded.

### 3.2 Example lifecycle

```
Router::dispatch()
 ├── new DemoController($app, $route)
 ├── $controller->init()
 ├── $controller->index()
 └── (ErrorHandler catches any uncaught exception)
```

Controllers do not persist between requests and hold no global state.

---

## 4. Route linkage

Controllers are bound declaratively via the **routes map**
(`citomni_http_routes.php` or provider `Boot/Registry.php`):

```php
'/demo.html' => [
	'controller'    => \Vendor\Demo\Controller\DemoController::class,
	'action'        => 'index',
	'methods'       => ['GET'],
	'template_file' => 'public/demo.html',
	'template_layer'=> 'vendor/demo',
],
```

* The Router validates that both the controller class and action method exist.
* Missing controllers or actions trigger `ErrorHandler->httpError(500, [...])`.
* Invalid HTTP methods trigger `ErrorHandler->httpError(405, [...])`.

---

## 5. Controller anatomy

Controllers typically extend `\CitOmni\Kernel\Controller\BaseController` to inherit common helpers (`$this->app`, `$this->routeConfig`, etc.) and the optional `init()` hook.

```php
<?php
declare(strict_types=1);

namespace Vendor\Demo\Controller;

use CitOmni\Kernel\Controller\BaseController;

final class DemoController extends BaseController {

	protected function init(): void {
		// Optional setup logic, e.g. access control, service priming.
	}

	public function index(): void {
		$msg = $this->app->demo->greet('World');
		$this->app->tplEngine->render('public/demo.html@vendor/demo', [
			'message' => $msg,
		]);
	}

	public function postAction(): void {
		// Example POST endpoint
		$this->app->response->redirect('/demo.html');
	}
}
```

**Key rule:** Controllers orchestrates and delegates to Services, Models, or domain objects.

---

## 6. Configuration and service access

Controllers consume configuration and services through the shared `App` instance:

```php
$ttl = (int)($this->app->cfg->myProvider->cache_ttl ?? 60);
$user = $this->app->userAccount;
$this->app->log->write('access.jsonl', 'info', 'User viewed demo page.', [ /* add context here */ ]); // See the Log-service in citomni/http for further info.
```

All config reads are immutable (`$app->cfg` is read-only).
All services are lazily resolved and cached once per request.

---

## 7. Error handling philosophy

Controllers must **fail fast** and let the global `ErrorHandler` record, classify, and render failures.

Never use `try/catch` for general flow control.
Throw SPL exceptions (`\InvalidArgumentException`, `\RuntimeException`, etc.) when inputs are invalid or actions cannot proceed.

Example:

```php
if (!$this->app->security->verifyCsrf($token)) {
	throw new \RuntimeException('Invalid CSRF token.');
}
```

The error propagates to `ErrorHandler`, which logs and renders a proper HTTP error page or JSON payload.

---

## 8. Rendering and responses

Controllers can use:

* **TemplateEngine:**

  ```php
  $this->app->tplEngine->render('public/demo.html@vendor/demo', ['msg' => 'Hello']);
  ```
* **Response redirect:**

  ```php
  $this->app->response->redirect('/login.html');
  ```
* **JSON response:**

  ```php
  $this->app->response->json(['status' => 'ok']);
  ```

> Controllers decide *what* to return, never *how* it is sent.
> Output encoding, headers, and content type are managed by the Response service.


**Data precedence with TemplateEngine + vars_providers**

When rendering, the final template data is merged as:
**controller payload > vars_providers > globals**.
Controller-supplied keys are never overwritten by providers. See "vars_providers - Auto-injecting Template Variables" for details.


---

## 9. Security patterns

Controllers are expected to enforce authentication and authorization explicitly, not via hidden middleware.

### 9.1 Role gating (example)

```php
protected function init(): void {
	$user = $this->app->userAccount;
	if (!$user->isLoggedin() || !$this->app->role->atLeast('operator')) {
		$this->app->response->redirect('../login.html');
	}
}
```

### 9.2 CSRF enforcement

```php
public function savePost(): void {
	$token = (string)($this->app->request->post('csrf_token') ?? '');
	if (!$this->app->security->verifyCsrf($token)) {
		$this->app->errorHandler->httpError(400, [
			'reason' => 'csrf_failed',
			'message'=> 'Invalid CSRF token.',
		]);
		return;
	}

	// Proceed with valid input...
}
```

Security enforcement is deterministic, testable, and centralized.

---

## 10. Testing controllers

Controllers can be tested without a running HTTP server:

```php
$app = new \CitOmni\Kernel\App(__DIR__ . '/../_fixtures/config', \CitOmni\Kernel\Mode::HTTP);
$ctrl = new \Vendor\Demo\Controller\DemoController($app, []);
ob_start();
$ctrl->index();
$out = ob_get_clean();

$this->assertStringContainsString('Hello', $out);
```

Mock or stub services via `/config/services.php` in a test fixture for isolation.

---

## 11. Best practices

| Principle                         | Description                                                                          |
| --------------------------------- | ------------------------------------------------------------------------------------ |
| **No global state**               | Controllers must be stateless between requests.                                      |
| **No logic in constructors**      | Use `init()` for safe setup, not `__construct()`.                                    |
| **Use services for domain logic** | Keep controllers thin; delegate heavy work.                                          |
| **Fail fast, bubble up**          | Never swallow exceptions-`ErrorHandler` owns them.                                   |
| **Render explicitly**             | Always call `Response` or `TemplateEngine` intentionally.                            |
| **Respect suffixes**              | `.html` -> `text/html`, `.json` -> `application/json`.                               |
| **No reflection/magic**           | All actions are explicitly named and called.                                         |
| **No sitewide plumbing**          | Don't assemble header/footer/sidebar/etc. in controllers; use `view.vars_providers`. |


---

## 12. Admin controllers (extend the framework base)

For admin areas, extend the citomni/admin's `AdminBaseController` instead of re-implementing login/role gating. The base controller already:
- Resolves the current user,
- Enforces login + minimum role (operator by default),
- Applies admin-specific response headers.

**Minimal example**

```php
<?php
declare(strict_types=1);

namespace Vendor\Package\Controller;

use CitOmni\Admin\Controller\AdminBaseController;

final class AdminController extends AdminBaseController {
	public function index(): void {
		$myVar = "Some value that we want to inject into the template";	
		$this->app->tplEngine->render('admin/dashboard.html@vendor-name/my-provider', [
			'myTemplateVar' => $myVar,
		]);
	}
}
```

**Stricter policies (optional)**

If you need stricter gating or extra checks, add them **after** the base init:

```php
<?php
declare(strict_types=1);

namespace Vendor\Package\Controller;

use CitOmni\Admin\Controller\AdminBaseController;

final class BillingAdminController extends AdminBaseController {

	protected function init(): void {
		parent::init(); // Keep base login/role checks and headers

		// Extra guard (example): Require at least "manager"
		if (!$this->app->role->atLeast('manager')) {
			$this->app->response->redirect('../login.html'); // or a policy page
		}
	}
}
```

This pattern centralizes authentication, reduces per-route duplication, and ensures predictable, testable access control.

> Note: Redirection targets and minimum roles are governed by your application policy; avoid duplicating what the base already does unless you're intentionally tightening requirements.

---

## 13. Checklist

* [ ] Class is `final`, PSR-4, tabs, K&R braces, English docs.
* [ ] Constructor matches `(App $app, array $routeCtx = [])`.
* [ ] Optional `init()` is pure and side-effect-free.
* [ ] Action methods are deterministic, documented, and throw SPL exceptions on failure.
* [ ] Uses `$this->app->cfg` for config, `$this->app->serviceId` for dependencies.
* [ ] No catch-all try/catch blocks.
* [ ] Rendering uses TemplateEngine or Response service explicitly.
* [ ] No file I/O, global state, or runtime reflection.

---

## 14. Closing note

> Controllers are **transient executors**, not global services.
> They belong to routes, not to the service container.
> Their role is to translate intent into output-cleanly, explicitly, and predictably.

**One request in. One response out. Nothing hidden.**
