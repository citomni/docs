# CitOmni Controllers - Authoring, Routing, and Usage (PHP 8.2+)

> **Thin HTTP adapters. Explicit routes. Predictable output.**

This document explains **how to build Controllers** for CitOmni: What they are, how they are **routed** and **instantiated**, what they are allowed to do, what they must **not** do, how they interact with **Services**, **Repositories**, and **Operations**, and how to keep them **deterministic**, **cheap**, and **transport-correct**.

It also documents the practical Controller-facing usage of the built-in **Request** and **Response** services.

---

**Document type:** Technical Guide  
**Version:** 1.0  
**Applies to:** CitOmni >= 8.2 (HTTP mode)  
**Audience:** Application and provider developers  
**Status:** Stable and foundational  
**Author:** CitOmni Core Team  
**Copyright:** Copyright (c) 2012-present CitOmni

---

## 1) What is a Controller?

A **Controller** in CitOmni is an **HTTP adapter**.

Its job is to translate the HTTP world into CitOmni's internal world, and back again.

A Controller typically owns:

* Reading HTTP input
* Parsing and normalizing request data
* Checking request-method semantics
* CSRF verification where relevant
* Session-related flow
* Cookie-related flow when it is transport-specific
* Access control decisions at the HTTP boundary
* Starting or touching session state when needed
* Setting, deleting, or reading cookies through the Cookie service
* Calling Repositories directly for trivial cases
* Calling Operations for non-trivial orchestration
* Rendering templates or returning JSON / text / HTML / redirects / downloads

A Controller does **not** exist to hold general business logic.

That responsibility belongs elsewhere:

* **Repository** owns SQL and persistence
* **Operation** owns non-trivial transport-agnostic orchestration
* **Service** owns reusable App-aware tools
* **Controller** owns HTTP adaptation

This is an architectural rule, not a style preference.

---

## 2) Where Controllers sit in the HTTP boot flow

In a CitOmni HTTP app, the request flow is broadly:

1. Front controller (`public/index.php`)
2. `\CitOmni\Http\Kernel::run()`
3. `Kernel::boot()`
4. `App` construction
5. Global `ErrorHandler` installation
6. Maintenance guard
7. Router dispatch
8. Controller instantiation
9. Controller action invocation

The kernel installs the global error handler early, runs maintenance checks, and then hands control to the router. The router dispatches to a controller/action pair based on the compiled route table.

That means a Controller is always executed inside a fully bootstrapped HTTP runtime with access to:

* `$this->app`
* `$this->routeConfig`
* Core HTTP services resolved through the App
* Global error handling already installed

---

## 3) Base contract

Controllers in CitOmni extend `BaseController`.

The base contract is:

```php
<?php
declare(strict_types=1);

namespace CitOmni\Kernel\Controller;

use CitOmni\Kernel\App;

abstract class BaseController {
	protected App $app;
	protected array $routeConfig = [];

	public function __construct(App $app, array $routeConfig = []) {
		$this->app = $app;
		$this->routeConfig = $routeConfig;
		if (\method_exists($this, 'init')) {
			$this->init();
		}
	}

	public function getRouteConfig(): array {
		return $this->routeConfig;
	}
}
````

Every Controller therefore receives:

* `$this->app`

  * The application object
  * Access to services and configuration
* `$this->routeConfig`

  * The route definition array for the matched route
  * Used for route-local metadata such as template file, template layer, method constraints, and small route-local flags

The optional `init()` hook is called automatically if present and should remain cheap and deterministic. The router instantiates Controllers through the route map, not the service map.  

---

## 4) The Controller responsibility boundary

Controllers are deliberately narrow.

### Controllers should do

* Read HTTP inputs from the Request service
* Normalize raw input into predictable scalars / arrays
* Perform route-level or request-level HTTP checks
* Enforce CSRF / session / auth concerns that are HTTP-specific
* Read and write session values through the Session service
* Use the Flash service for one-request feedback and old input
* Regenerate session IDs when authentication state changes
* Set, read, and delete cookies through the Cookie service
* Call a Repository directly for trivial CRUD-style cases
* Call an Operation when orchestration or reuse justifies it
* Shape the HTTP response
* Render templates
* Return JSON
* Return text or HTML
* Redirect
* Trigger file downloads
* Set response headers
* Trigger HTTP errors via the ErrorHandler

### Controllers must not do

* Write SQL
* Perform direct database querying
* Hide multi-step business workflows inside action methods
* Mix transport concerns with transport-agnostic orchestration
* Become a dumping ground for reusable domain logic
* Catch `\Throwable` broadly just to keep going
* Re-implement generic reusable behavior that belongs in a Service
* Reconstruct framework boot logic

CitOmni's architecture explicitly defines Controllers as adapters and keeps SQL in Repositories, orchestration in Operations, and reusable tools in Services.  

---

## 5) When Controller -> Repository is enough

Controller -> Repository is the default path for trivial cases.

Examples:

* Render a page from a simple DB lookup
* Toggle a boolean flag with straightforward permission checks
* Fetch a small list and render a template
* Perform a one-step insert/update with minimal rules

Example flow:

1. Read and normalize request input
2. Instantiate Repository
3. Call one Repository method
4. Render or return

This is preferable to introducing an Operation too early. CitOmni explicitly allows Controllers to call Repositories directly for trivial route-specific work. 

---

## 6) When to introduce an Operation

Move logic out of the Controller and into an Operation when one or more of these is true:

* The same workflow must be used by both HTTP and CLI
* Multiple controllers or routes need the same orchestration
* Multiple repositories are involved
* The action has meaningful branching or state transitions
* The action coordinates several side effects

  * For example logging, mail dispatch, token rotation, cache invalidation

Typical pattern:

```php
$operation = new \Vendor\Package\Operation\ResetPassword($this->app);
$result = $operation->run($input);
```

The Controller then translates `$result` into HTTP behavior.

Operations are transport-agnostic, explicitly instantiated by adapters, and return domain-shaped arrays rather than HTTP responses. 

---

## 7) Controllers are routed, not registered

Controllers are **not** registered in the service map.

That is a core difference from Services.

Controllers are discovered through **routes**, not through `/config/services.php`.

A route definition typically points to:

* `controller` => Controller FQCN
* `action` => Method name
* `methods` => Allowed HTTP methods
* Optional route-local metadata such as template information

Example route:

```php
<?php
declare(strict_types=1);

return [
	'/account/login' => [
		'controller'     => \App\Http\Controller\AuthController::class,
		'action'         => 'login',
		'methods'        => ['GET', 'POST'],
		'template_file'  => 'public/account/login.html',
		'template_layer' => 'app',
	],
];
```

CitOmni builds routes deterministically from vendor / provider / app route sources, and the router consumes the resulting route table for dispatch.  

---

## 8) Where routes are declared

Depending on context, controller routes may come from different places.

### Mode packages

Mode packages such as `citomni/http` expose baseline routes via boot metadata used by `App::buildRoutes()`.

### Provider packages

Reusable provider packages typically declare HTTP routes in:

`src/Boot/Registry.php`

Using constants such as:

* `ROUTES_HTTP`
* optionally `ROUTES_CLI` if relevant for a CLI routing setup

Example:

```php
<?php
declare(strict_types=1);

namespace Vendor\Package\Boot;

final class Registry {
	public const ROUTES_HTTP = [
		'/greeter' => [
			'controller' => \Vendor\Package\Controller\GreeterController::class,
			'action' => 'index',
			'methods' => ['GET'],
			'template_file' => 'greeter/index.html',
			'template_layer' => 'vendor/package',
		],
	];
}
```

### Application layer

Application-local HTTP routes live in:

* `/config/citomni_http_routes.php`
* `/config/citomni_http_routes.{ENV}.php`

Routes are separate from config and separate from service-map registration. 

---

## 9) Controller constructor model

The router instantiates the controller with:

```php
new ControllerFqcn($app, $routeConfig)
```

That is the effective constructor contract.

A Controller therefore always needs to be compatible with:

```php
__construct(\CitOmni\Kernel\App $app, array $routeConfig = [])
```

In normal cases, you do not override the constructor at all. You inherit `BaseController`'s constructor and optionally use `init()`.

### Recommended rule

* Do not override `__construct()` unless there is a compelling reason
* Prefer `protected function init(): void` for cheap setup
* Keep `init()` deterministic and low-overhead

---

## 10) Accessing route config

`$this->routeConfig` contains the matched route definition.

Typical uses:

* Template file
* Template layer
* Route-local flags
* Small presentational metadata
* Route-specific policy markers

Example:

```php
$template = $this->routeConfig['template_file'] . '@' . $this->routeConfig['template_layer'];
```

### Recommended discipline

* Treat route config as route metadata, not as a replacement for app config
* Use route config for per-route behavior
* Use `$this->app->cfg` for runtime policy
* Use Services for reusable behavior

Do not turn route config into a miscellaneous bag of unrelated application state.

---

## 11) Accessing Services from a Controller

Controllers access Services through the App:

```php
$this->app->request;
$this->app->response;
$this->app->session;
$this->app->flash;
$this->app->cookie;
$this->app->errorHandler;
$this->app->tplEngine;
````

Typical HTTP-bound uses include:

* `request` for input, headers, URL metadata, AJAX detection, and client IP
* `response` for redirects, JSON, downloads, headers, cache policy, and HTML / text output
* `session` for login state, session rotation, and authenticated request state
* `flash` for one-request feedback messages and old input across redirects
* `cookie` for remember-me style flows, preference cookies, consent state, or other browser-stored tokens
* `errorHandler` for framework-managed HTTP errors
* `tplEngine` for HTML rendering

Services are resolved lazily from the service map and cached per request / process. Controllers themselves are not service-map singletons.

This means:

* Service access is cheap after first resolution
* Controllers can rely on the same Service instance throughout the request
* Reusable infrastructure belongs in Services, not copied across Controllers

---

## 12) Accessing config from a Controller

Controllers read runtime policy through the read-only config wrapper:

```php
$this->app->cfg
```

Example:

```php
$csrfEnabled = (bool)($this->app->cfg->security->csrf_protection ?? true);
```

Important behavior:

* Direct access to unknown keys fails fast
* `??` works safely for the final key
* Intermediate missing nodes still require care

Example:

```php
$http = isset($this->app->cfg->http) ? $this->app->cfg->http : null;
$trustProxy = (bool)($http !== null && isset($http->trust_proxy) ? $http->trust_proxy : false);
```

CitOmni treats config as runtime policy and keeps it separate from route tables and service wiring.

---

## 13) Canonical Controller action shape

A Controller action is usually a public `void` method.

Typical flow:

1. Read request input
2. Normalize to a stable array / scalar shape
3. Perform HTTP-bound checks
4. Delegate to Repository or Operation
5. Translate the result to an HTTP response

Example outline:

```php
public function index(): void {
	// 1) Read input
	// 2) Normalize
	// 3) HTTP-bound checks
	// 4) Repository or Operation
	// 5) Render / json / redirect / error
}
```

This is also the canonical adapter flow described in the architecture guide.

---

## 14) The Request service API in Controllers

CitOmni's `request` service is the preferred way for Controllers to read HTTP input and request metadata.

### Core input methods

```php
$method = $this->app->request->method();           // GET / POST / PUT / ...
$getAll = $this->app->request->get();              // entire $_GET
$postAll = $this->app->request->post();            // entire $_POST
$id = $this->app->request->get('id');              // $_GET['id'] ?? null
$name = $this->app->request->post('name');         // $_POST['name'] ?? null
```

### Unified input access

```php
$value = $this->app->request->input('email');
$value = $this->app->request->input('page', 1, 'get');
$value = $this->app->request->input('token', '', 'post');
$all = $this->app->request->input(null, [], 'post');
```

Behavior:

* With `source = 'auto'`, GET requests read from `$_GET`, otherwise from `$_POST`
* `input(null, ..., $source)` returns the entire selected bag
* `input($key, $default, $source)` returns the scalar / value or the default

### Presence checks

```php
$hasEmail = $this->app->request->has('email');
$hasPassword = $this->app->request->hasPost('password');
$hasAvatar = $this->app->request->hasFile('avatar');
```

### Whitelisting and excluding input keys

```php
$data = $this->app->request->only(['email', 'password'], 'post');
$data = $this->app->request->except(['csrf_token'], 'post');
```

These are useful for small predictable payloads and to avoid dragging unrelated keys into Controller logic.

### Basic sanitizing helper

```php
$q = $this->app->request->sanitize('q', 'get');
```

Notes:

* `sanitize()` trims and escapes with `htmlspecialchars()`
* This is useful for simple presentational reuse, search fields, or echoing user input back into HTML
* It is **not** a replacement for domain validation
* It is **not** a replacement for proper output escaping discipline everywhere else

### Files

```php
$file = $this->app->request->file('avatar');
$files = $this->app->request->files();
```

File handling is delegated through the `files` service. The Request service gives the controller a clean surface for checking and retrieving uploaded files.

### JSON request body

```php
$payload = $this->app->request->json();
```

Behavior:

* Returns `null` when content type is absent, not JSON-like, unreadable, empty, invalid JSON, or not an array
* Accepts:

  * `application/json`
  * `text/json`
  * any content type ending in `+json`
* Caches the parsed result internally after first parse

This makes it well-suited for JSON endpoints:

```php
$payload = $this->app->request->json();

if (!\is_array($payload)) {
	$this->app->response->jsonProblem(
		'Invalid JSON payload',
		400,
		'Expected a JSON object request body.'
	);
}
```

### Headers and server metadata

```php
$contentType = $this->app->request->contentType();
$auth = $this->app->request->header('Authorization');
$headers = $this->app->request->headers();
$cookie = $this->app->request->cookie('remember_me');
$serverName = $this->app->request->server('SERVER_NAME');
$referer = $this->app->request->referer();
$userAgent = $this->app->request->getUserAgent();
```

### URL and path helpers

```php
$scheme = $this->app->request->scheme();
$host = $this->app->request->host();
$port = $this->app->request->port();
$uri = $this->app->request->uri();
$path = $this->app->request->path();
$trimmedPath = $this->app->request->pathWithBaseTrimmed();
$queryString = $this->app->request->queryString();
$queryAll = $this->app->request->queryAll();
$fullUrl = $this->app->request->fullUrl();
$baseUrl = $this->app->request->baseUrl();
```

These helpers are especially useful for:

* canonical links
* redirects
* audit logging
* building absolute URLs
* route diagnostics
* JSON Problem `instance` context via the Response service

### HTTPS, AJAX, and client IP

```php
$isHttps = $this->app->request->isHttps();
$isSecure = $this->app->request->isSecure();
$isAjax = $this->app->request->isAjax();
$ip = $this->app->request->ip();
$clientIp = $this->app->request->getClientIp();
```

The Request service supports trusted proxies using `http.trust_proxy` and `http.trusted_proxies` config and can resolve forwarded host / port / proto / client IP when the remote address is trusted. Controllers should rely on this service rather than hand-rolling proxy logic. The service also returns `'CLI'` as IP when running under CLI SAPI and `'unknown'` when no public client IP can be resolved. This keeps transport edge cases centralized.

### Recommended Request usage rules

* Prefer `$this->app->request` over raw `$_GET`, `$_POST`, `$_FILES`, and `$_SERVER`
* Normalize early into scalars / stable arrays
* Use `only()` for small form payloads
* Use `json()` for JSON endpoints
* Use `contentType()` and `isAjax()` only as routing / transport hints, not as business rules
* Treat headers and IP information as transport metadata

---

## 15) The Response service API in Controllers

CitOmni's `response` service is the preferred way for Controllers to shape HTTP output.

Several Response methods terminate the request with `exit`, which means a Controller action usually ends immediately after calling them.

### Status code

```php
$this->app->response->setStatus(204);
```

Sets the HTTP status code if headers have not yet been sent. If headers are already sent, the service logs the situation if a `log` service exists.

### Redirect

```php
$this->app->response->redirect('/member/');
$this->app->response->redirect('/login/', 303);
```

Behavior:

* Sends a sanitized `Location` header
* Uses the given status code, default `302`
* Terminates immediately (`never` return type)

This is the correct place for HTTP redirects. Operations must not return redirect instructions as transport artifacts.

### Generic headers

```php
$this->app->response->setHeader('X-Robots-Tag', 'noindex, noarchive');
$this->app->response->setHeader('Cache-Control', 'no-store, no-cache', true);
```

This is useful for route-specific security, caching, and metadata headers.

### No-cache and no-index helpers

```php
$this->app->response->noCache();
$this->app->response->noIndex();
```

`noIndex()` also sets no-cache related headers.

### Member-area headers

```php
$this->app->response->memberHeaders();
$this->app->response->memberHeaders(true, false);
```

Behavior:

* Sends no-cache headers
* Sends anti-indexing headers by default
* Sets a default member-area CSP and related hardening headers
* Optionally allows or blocks external `https:` resources depending on `allowExternal`

Use this in authenticated member-facing areas that need a reasonably strong default policy.

### Admin headers

```php
$this->app->response->adminHeaders();
```

Behavior:

* Sends no-cache headers
* Sends strict anti-indexing headers
* Sends a tighter same-origin oriented CSP and related hardening headers
* Adds HSTS when HTTPS is detected

Use this in backoffice / admin areas where you want a stricter default surface.

### JSON responses

```php
$this->app->response->json(['ok' => true]);
$this->app->response->json(['ok' => true], true);
```

Behavior:

* Sends `Content-Type: application/json`
* Uses charset from `cfg->locale->charset`
* Encodes using:

  * `JSON_UNESCAPED_UNICODE`
  * `JSON_UNESCAPED_SLASHES`
  * `JSON_THROW_ON_ERROR`
* Optional pretty print when the second argument is `true`
* Terminates immediately

### JSON with status

```php
$this->app->response->jsonStatus(['ok' => false], 422);
$this->app->response->jsonStatus(['ok' => true, 'id' => 123], 201);
```

Use this when the status code matters and should be explicit.

### JSON without caching

```php
$this->app->response->jsonNoCache(['ok' => true]);
$this->app->response->jsonStatusNoCache(['ok' => false], 429);
```

Useful for AJAX, auth flows, rate limits, volatile status endpoints, or other endpoints that should not be cached.

### RFC-style problem responses

```php
$this->app->response->jsonProblem(
	'Validation failed',
	422,
	'The email field is required.',
	'about:blank',
	['errors' => ['email' => 'Required']]
);
```

Behavior:

* Sends `application/problem+json`
* Includes:

  * `type`
  * `title`
  * `status`
  * `detail`
* Adds `instance` automatically when the Request service exists
* Merges any extra payload keys
* Terminates immediately

This is a good default for JSON error responses in API-like endpoints.

### Plain text and HTML

```php
$this->app->response->text('OK', 200);
$this->app->response->html($html, 200);
```

Both methods set charset from config and terminate immediately.

Use `text()` for health checks, diagnostics, or simple machine-readable plain-text endpoints. Use `html()` for small hand-built pages or when a template would be unnecessary.

### File downloads

```php
$this->app->response->download($path);
$this->app->response->download($path, 'invoice-2026-03.pdf');
```

Behavior:

* Validates that the file exists and is readable
* Clears output buffers
* Detects MIME type when possible
* Sends a safe download disposition
* Streams the file
* Terminates immediately

Use this for invoices, exports, generated archives, and similar download endpoints.

### Recommended Response usage rules

* Prefer Response methods over raw `header()`, `http_response_code()`, and manual exits
* Treat Response calls as the end of the Controller flow when the method is `never`
* Use `jsonProblem()` for structured JSON errors
* Use `memberHeaders()` / `adminHeaders()` for area-wide security defaults
* Use `download()` instead of ad hoc file streaming code in Controllers

---

## 16) The Session service API in Controllers

CitOmni's `session` service is the preferred way for Controllers to work with session state.

The service starts the session lazily on first use. In practice, the common read / write methods ensure the session is started automatically.

### Starting and state checks

```php
$this->app->session->start();
$isActive = $this->app->session->isActive();
$sessionId = $this->app->session->id();
````

Notes:

* `start()` explicitly ensures that the session is started
* `get()`, `set()`, `has()`, and `remove()` ensure startup automatically
* `regenerate()` requires an already active session and throws if no session is active

### Reading and writing session values

```php
$userId = $this->app->session->get('user_id');
$this->app->session->set('user_id', 123);
$hasUser = $this->app->session->has('user_id');
$this->app->session->remove('user_id');
```

Use this for authenticated user state, CSRF-related state, multi-step form state, or other request-to-request browser session state.

### Destroying the session

```php
$this->app->session->destroy();
$this->app->session->destroy(false);
```

Behavior:

* Clears session data
* Destroys the PHP session
* Resets `$_SESSION`
* Deletes the session cookie by default

This is the correct default for logout flows.

### Regenerating the session ID

```php
$this->app->session->regenerate();
$this->app->session->regenerate(true);
```

Use this after login, privilege elevation, or other security-sensitive auth transitions.

Notes:

* `regenerate()` throws when no session is active
* The service records the rotation timestamp internally
* The service may also rotate automatically depending on `session.rotate_interval` config

### Recommended Session usage rules

* Prefer `$this->app->session` over raw `$_SESSION`
* Regenerate the session ID after successful login
* Destroy the session on logout
* Do not store large arbitrary payloads in session state
* Keep session usage transport-focused inside Controllers

---

## 17) The Flash service API in Controllers

CitOmni's `flash` service is the preferred way for Controllers to work with one-request feedback messages and old input across redirects.

The Flash service uses the Session service internally, but Controllers should interact with flash state through `$this->app->flash`, not through Session methods.

### Storing flash messages

```php
$this->app->flash->set('success', 'Profile updated.');
$this->app->flash->add('info', 'Settings were saved.');
$this->app->flash->success('Welcome back.');
$this->app->flash->warning('Please review the highlighted fields.');
$this->app->flash->error('Login failed.');
````

Behavior:

* `set()` stores a message bucket directly under a key
* `add()` appends to a message bucket and keeps the newest entries within service limits
* `success()`, `info()`, `warning()`, and `error()` are convenience helpers

This is ideal for POST -> redirect -> GET flows.

### Reading flash messages

```php
$success = $this->app->flash->take('success');
$error = $this->app->flash->peek('error');
$all = $this->app->flash->peekAll();
$allAndClear = $this->app->flash->pullAll();
```

Behavior:

* `take()` returns one message bucket and removes it immediately
* `peek()` returns one message bucket without removing it
* `peekAll()` returns both message and old-input bags without clearing them
* `pullAll()` returns both bags and clears them unless keep-mode is enabled

### Preserving flash for an extra request

```php
$this->app->flash->keep();
$this->app->flash->keep(false);
```

Behavior:

* `keep(true)` marks the current flash payload to survive the next `pullAll()` cycle
* `keep(false)` removes that keep marker again

### Old input

```php
$this->app->flash->old([
	'email' => $email,
	'name' => $name,
]);

$oldEmail = $this->app->flash->oldValue('email');
$hasOldEmail = $this->app->flash->hasOld('email');
```

Use this when redirecting back to a form and you want to repopulate selected fields safely.

### Clearing flash state

```php
$this->app->flash->forgetMsg('success');
$this->app->flash->forgetOld(['email']);
$this->app->flash->clear();
```

Behavior:

* `forgetMsg()` removes one message bucket
* `forgetOld()` removes selected old-input keys
* `clear()` removes all flash message, old-input, and keep-state data

### Recommended Flash usage rules

* Use `$this->app->flash` as the public flash API in Controllers
* Use flash messages for one-request UI feedback
* Use old input only for compact, user-facing form values
* Keep flash payloads small and transport-focused
* Let the Flash service own flash-bag lifecycle behavior

---

## 18) The Cookie service API in Controllers

CitOmni's `cookie` service is the preferred way for Controllers to work with browser cookies.

The service derives sensible defaults from `cookie` and `http` config, including `secure`, `httponly`, `samesite`, `path`, and optional `domain`.

### Reading cookies

```php
$token = $this->app->cookie->get('remember_token');
$token = $this->app->cookie->get('remember_token', '');
$hasToken = $this->app->cookie->has('remember_token');
````

### Setting cookies

```php
$this->app->cookie->set('remember_token', $token);
$this->app->cookie->set('remember_token', $token, [
	'ttl' => 60 * 60 * 24 * 30,
]);
$this->app->cookie->set('consent', 'yes', [
	'ttl' => 31536000,
	'samesite' => 'Lax',
]);
```

Behavior:

* Cookie names are validated strictly
* Options are merged with service defaults
* `ttl` is supported and converted to `expires`
* `SameSite=None` requires `Secure=true`
* When the cookie is visible to the current request scope, the service updates `$_COOKIE` in-memory

### Deleting cookies

```php
$this->app->cookie->delete('remember_token', [
	'path' => '/',
	'domain' => 'example.com',
]);
```

Use the same path / domain semantics as the cookie was set with when deletion needs to be exact.

### Reading effective defaults

```php
$defaults = $this->app->cookie->defaults();
```

This is useful when a Controller or collaborator needs to align custom cookie behavior with the framework's configured defaults.

### Recommended Cookie usage rules

* Prefer `$this->app->cookie` over raw `setcookie()` and direct `$_COOKIE` access
* Use cookies for browser-stored transport state, not as a persistence layer
* Store only compact values in cookies
* Keep sensitive cookies `HttpOnly` unless client-side JavaScript explicitly needs access
* Be deliberate with `SameSite=None`, because it requires `Secure=true`
* For long-lived authentication, store only a token reference in the cookie and keep the actual token record server-side

---

## 19) ErrorHandler vs Response

Both have a place, but they are not the same.

### Use `response` when

* You intentionally return JSON / HTML / text / redirect / file download
* The endpoint owns the response contract directly
* The request completed in an expected application flow

### Use `errorHandler->httpError()` when

* You want a framework-managed HTTP error response
* The route should terminate as a 403 / 404 / 405 / 500-style HTTP error page
* The endpoint has reached an HTTP failure state rather than a normal business response

Example:

```php
$this->app->errorHandler->httpError(404, [
	'title' => 'Page not found',
	'message' => 'The requested page does not exist.',
]);
```

The ErrorHandler is installed early during boot and exposes `httpError(int $status, array $context = [])` as the application-facing method for this purpose.

---

## 20) Rendering templates

A common Controller responsibility is HTML rendering.

Example pattern:

```php
$this->app->tplEngine->render(
	$this->routeConfig['template_file'] . '@' . $this->routeConfig['template_layer'],
	[
		'meta_title' => 'Login',
		'meta_description' => 'Sign in to your account.',
	]
);
```

Template rendering belongs in Controllers, not in Operations or Repositories. The example `PublicController` in the boot-sequence material shows this pattern directly.

### Notes

* Template rendering belongs in Controllers
* Template payload arrays should use predictable keys
* Build view data close to the response boundary
* Do not move template rendering into a generic domain layer

---

## 21) Method-specific action handling

A route may allow one or more methods, but the Controller should still keep method handling explicit and readable.

Preferred pattern:

```php
public function login(): void {
	$method = $this->app->request->method();

	if ($method === 'GET') {
		$this->renderLoginForm();
		return;
	}

	if ($method === 'POST') {
		$this->handleLoginSubmit();
		return;
	}

	$this->app->errorHandler->httpError(405, [
		'message' => 'Method not allowed.',
	]);
}
```

This is clearer and more testable than mixing GET and POST behavior in one long block.

---

## 22) CSRF, sessions, auth, flash, and cookies

These belong in Controllers because they are HTTP concerns.

Examples of Controller-owned concerns:

* Verify CSRF token on POST
* Start or touch the session when login state is needed
* Read and write session state through the Session service
* Store and consume one-request feedback through the Flash service
* Preserve flash payloads for an extra request when needed
* Store and read old input through the Flash service where appropriate
* Regenerate the session ID after login or privilege elevation
* Destroy the session on logout
* Read, set, and delete cookies through the Cookie service
* Handle remember-me request semantics
* Add member/admin response headers
* Decide whether an unauthenticated or unauthorized user should be redirected

What does **not** belong in the Controller is the transport-agnostic decision graph behind the authenticated workflow. That part belongs in an Operation when it grows beyond the trivial.

A practical rule is simple:

* Session and cookie **transport handling** belongs in the Controller
* Flash **UI feedback handling** belongs in the Controller through the Flash service
* Authentication **workflow logic** belongs in an Operation once it stops being trivial
* Persistence of long-lived tokens belongs in a Repository

---

## 23) JSON endpoints and AJAX endpoints

Controllers often need a slightly different style for API-like endpoints.

Typical JSON endpoint pattern:

```php
public function saveProfileApi(): void {
	if ($this->app->request->method() !== 'POST') {
		$this->app->response->jsonProblem(
			'Method not allowed',
			405,
			'This endpoint only accepts POST.'
		);
	}

	$payload = $this->app->request->json();

	if (!\is_array($payload)) {
		$this->app->response->jsonProblem(
			'Invalid JSON payload',
			400,
			'Expected a JSON object request body.'
		);
	}

	$operation = new \App\Operation\UpdateProfile($this->app);
	$result = $operation->run([
		'name' => \trim((string)($payload['name'] ?? '')),
		'phone' => \trim((string)($payload['phone'] ?? '')),
	]);

	if (($result['ok'] ?? false) !== true) {
		$this->app->response->jsonStatusNoCache([
			'ok' => false,
			'message' => (string)($result['message'] ?? 'Update failed.'),
		], 422);
	}

	$this->app->response->jsonStatusNoCache([
		'ok' => true,
		'message' => 'Profile updated.',
	], 200);
}
```

### Recommended JSON endpoint rules

* Use `request->json()` for JSON bodies
* Normalize the payload immediately
* Return structured errors with `jsonProblem()` or `jsonStatus()`
* Use `jsonStatusNoCache()` for rapidly changing authenticated endpoints
* Do not let Operations return Response objects

---

## 24) Repositories in Controllers

For trivial read / write endpoints, Controllers may instantiate Repositories directly.

Example:

```php
$repo = new \App\Repository\UserRepository($this->app);
$user = $repo->findById($userId);
```

This is allowed and often preferable when:

* Only one repository is involved
* The workflow is small
* The logic is route-specific
* No reuse across CLI or multiple controllers is needed

But the Repository remains the persistence boundary. Do not migrate orchestration into it just because the Controller is getting too large.

---

## 25) Operations in Controllers

When orchestration becomes non-trivial, Controllers should instantiate an Operation explicitly.

Example:

```php
$operation = new \App\Operation\AuthenticateUser($this->app);
$result = $operation->run([
	'identifier' => $identifier,
	'password' => $password,
	'ip' => $this->app->request->ip(),
]);
```

The Controller then translates `$result` into HTTP behavior such as:

* render form again with errors
* redirect on success
* return JSON error
* set session state
* set cookies

This preserves the transport boundary cleanly.

---

## 26) Suggested directory layout for Controllers

### Application layer

Controllers live under:

```text
src/Http/Controller/
```

Examples:

```text
src/Http/Controller/AuthController.php
src/Http/Controller/PublicController.php
src/Http/Controller/AccountController.php
```

### Provider packages

Reusable package controllers typically live under:

```text
src/Controller/
```

Examples:

```text
src/Controller/GreeterController.php
src/Controller/Admin/SubscriptionController.php
```

This matches the architecture guide's directory conventions. 

---

## 27) Naming conventions

Use clear, concrete names.

Good examples:

* `AuthController`
* `PublicController`
* `AccountController`
* `SubscriptionController`

Avoid vague buckets:

* `MainController`
* `CommonController`
* `GeneralController`
* `HelperController`

Action names should be concrete and understandable:

* `index`
* `login`
* `logout`
* `register`
* `forgotPassword`
* `websiteLicense`
* `downloadInvoice`
* `saveProfileApi`

The action name should be understandable without opening the method body.

---

## 28) Controller class skeleton

```php
<?php
declare(strict_types=1);

namespace App\Http\Controller;

use CitOmni\Kernel\Controller\BaseController;

/**
 * AccountController: HTTP adapter for account-related routes.
 *
 * Handles request parsing, HTTP-bound validation, access control decisions,
 * and response shaping for account pages and account mutations.
 *
 * Behavior:
 * - Reads request input and normalizes it into predictable scalar values.
 * - Delegates trivial persistence work directly to Repositories.
 * - Delegates non-trivial workflows to Operations.
 * - Shapes HTTP output through templates, redirects, JSON, or httpError().
 *
 * Notes:
 * - This class is transport-specific by design.
 * - Do not place SQL or multi-step domain orchestration here.
 *
 * Typical usage:
 *   Routed via explicit route definitions using:
 *   'controller' => \App\Http\Controller\AccountController::class
 */
final class AccountController extends BaseController {

	/**
	 * One-time per-instance initialization.
	 *
	 * Behavior:
	 * - Reads cheap route-local metadata if needed.
	 * - Must remain deterministic and low-overhead.
	 *
	 * @return void
	 */
	protected function init(): void {
	}

	/**
	 * Show the account overview page.
	 *
	 * @return void
	 */
	public function index(): void {
		$this->app->tplEngine->render(
			$this->routeConfig['template_file'] . '@' . $this->routeConfig['template_layer'],
			[
				'meta_title' => 'My account',
			]
		);
	}
}
```

---

## 29) Form-handling Controller example

```php
<?php
declare(strict_types=1);

namespace App\Http\Controller;

use CitOmni\Kernel\Controller\BaseController;
use App\Operation\AuthenticateUser;

/**
 * AuthController: HTTP adapter for authentication routes.
 */
final class AuthController extends BaseController {

	/**
	 * Render or process the login form.
	 *
	 * @return void
	 */
	public function login(): void {
		$method = $this->app->request->method();

		if ($method === 'GET') {
			$this->app->tplEngine->render(
				$this->routeConfig['template_file'] . '@' . $this->routeConfig['template_layer'],
				[
					'meta_title' => 'Login',
				]
			);
			return;
		}

		if ($method !== 'POST') {
			$this->app->errorHandler->httpError(405, [
				'message' => 'Method not allowed.',
			]);
		}

		$data = $this->app->request->only(['identifier', 'password'], 'post');

		$identifier = \trim((string)($data['identifier'] ?? ''));
		$password = (string)($data['password'] ?? '');

		if ($identifier === '' || $password === '') {
			$this->app->response->noCache();

			$this->app->tplEngine->render(
				$this->routeConfig['template_file'] . '@' . $this->routeConfig['template_layer'],
				[
					'meta_title' => 'Login',
					'error' => 'Please fill in both fields.',
					'identifier' => $identifier,
				]
			);
			return;
		}

		$operation = new AuthenticateUser($this->app);
		$result = $operation->run([
			'identifier' => $identifier,
			'password' => $password,
			'ip' => $this->app->request->ip(),
			'user_agent' => (string)($this->app->request->getUserAgent() ?? ''),
		]);

		if (($result['ok'] ?? false) !== true) {
			$this->app->response->noCache();

			$this->app->tplEngine->render(
				$this->routeConfig['template_file'] . '@' . $this->routeConfig['template_layer'],
				[
					'meta_title' => 'Login',
					'error' => (string)($result['message'] ?? 'Login failed.'),
					'identifier' => $identifier,
				]
			);
			return;
		}

		// This example assumes the authentication operation returns `user_id` on success.
		$this->app->session->set('user_id', (int)$result['user_id']);
		$this->app->session->regenerate(true);
		$this->app->flash->success('Welcome back.');

		$this->app->response->redirect('/member/');
	}
}
```

---

## 30) JSON API Controller example

```php
<?php
declare(strict_types=1);

namespace App\Http\Controller;

use CitOmni\Kernel\Controller\BaseController;
use App\Operation\CreateApiToken;

/**
 * ApiTokenController: HTTP adapter for token endpoints.
 */
final class ApiTokenController extends BaseController {

	/**
	 * Create a new API token from a JSON request.
	 *
	 * @return void
	 */
	public function create(): void {
		if ($this->app->request->method() !== 'POST') {
			$this->app->response->jsonProblem(
				'Method not allowed',
				405,
				'This endpoint only accepts POST.'
			);
		}

		if ($this->app->request->contentType() !== 'application/json') {
			$this->app->response->jsonProblem(
				'Unsupported media type',
				415,
				'Expected application/json.'
			);
		}

		$payload = $this->app->request->json();

		if (!\is_array($payload)) {
			$this->app->response->jsonProblem(
				'Invalid JSON payload',
				400,
				'Expected a JSON object request body.'
			);
		}

		$operation = new CreateApiToken($this->app);
		$result = $operation->run([
			'label' => \trim((string)($payload['label'] ?? '')),
			'ip' => $this->app->request->ip(),
		]);

		if (($result['ok'] ?? false) !== true) {
			$this->app->response->jsonStatusNoCache([
				'ok' => false,
				'message' => (string)($result['message'] ?? 'Token creation failed.'),
			], 422);
		}

		$this->app->response->jsonStatusNoCache([
			'ok' => true,
			'token' => (string)$result['token'],
		], 201);
	}
}
```

---

## 31) Download Controller example

```php
<?php
declare(strict_types=1);

namespace App\Http\Controller;

use CitOmni\Kernel\Controller\BaseController;
use App\Repository\InvoiceRepository;

/**
 * InvoiceController: HTTP adapter for invoice endpoints.
 */
final class InvoiceController extends BaseController {

	/**
	 * Download a generated invoice PDF.
	 *
	 * @return void
	 */
	public function download(): void {
		$invoiceId = (int)$this->app->request->input('id', 0, 'get');

		if ($invoiceId < 1) {
			$this->app->errorHandler->httpError(404, [
				'message' => 'Invoice not found.',
			]);
		}

		$repo = new InvoiceRepository($this->app);
		$file = $repo->getInvoiceFilePath($invoiceId);

		if ($file === '') {
			$this->app->errorHandler->httpError(404, [
				'message' => 'Invoice file not found.',
			]);
		}

		$this->app->response->download($file, 'invoice-' . $invoiceId . '.pdf');
	}
}
```

## 32) Logout Controller example

```php
<?php
declare(strict_types=1);

namespace App\Http\Controller;

use CitOmni\Kernel\Controller\BaseController;

/**
 * AuthController: HTTP adapter for authentication routes.
 */
final class AuthController extends BaseController {

	/**
	 * Destroy the authenticated session and redirect to login.
	 *
	 * @return void
	 */
	public function logout(): void {
		if ($this->app->request->method() !== 'POST') {
			$this->app->errorHandler->httpError(405, [
				'message' => 'Method not allowed.',
			]);
		}

		$this->app->cookie->delete('remember_token', [
			'path' => '/',
		]);

		$this->app->session->destroy(true);

		$this->app->response->redirect('/login/');
	}
}
```

---

## 33) Provider package example

### Controller class

```php
<?php
declare(strict_types=1);

namespace Vendor\Package\Controller;

use CitOmni\Kernel\Controller\BaseController;

/**
 * GreeterController: Public HTTP adapter for greeting pages.
 */
final class GreeterController extends BaseController {

	/**
	 * Render a simple greeting page.
	 *
	 * @return void
	 */
	public function index(): void {
		$message = $this->app->greeter->greet('World');

		$this->app->tplEngine->render(
			$this->routeConfig['template_file'] . '@' . $this->routeConfig['template_layer'],
			[
				'meta_title' => 'Greeter',
				'message' => $message,
			]
		);
	}
}
```

### Provider registry

```php
<?php
declare(strict_types=1);

namespace Vendor\Package\Boot;

final class Registry {
	public const ROUTES_HTTP = [
		'/greeter' => [
			'controller' => \Vendor\Package\Controller\GreeterController::class,
			'action' => 'index',
			'methods' => ['GET'],
			'template_file' => 'greeter/index.html',
			'template_layer' => 'vendor/package',
		],
	];
}
```

---

## 34) App-local route example

```php
<?php
declare(strict_types=1);

return [
	'/legal/website-license/' => [
		'controller' => \App\Http\Controller\PublicController::class,
		'action' => 'websiteLicense',
		'methods' => ['GET'],
	],
	'/' => [
		'controller' => \App\Http\Controller\PublicController::class,
		'action' => 'index',
		'methods' => ['GET'],
		'template_file' => 'public/index.html',
		'template_layer' => 'app',
	],
];
```

---

## 35) Route-config design guidance

Keep route arrays declarative and boring.

Good route metadata:

* `controller`
* `action`
* `methods`
* `template_file`
* `template_layer`
* small route-local flags

Avoid putting large business policies directly in route arrays.

If policy is global or environment-sensitive, prefer config.

If behavior is reusable and executable, prefer a Service or Operation.

The route should describe how the router should dispatch the request, not become an alternative application container.

---

## 36) Error handling philosophy in Controllers

CitOmni prefers fail-fast behavior.

Controllers should generally not wrap the whole action in try/catch.

### Good pattern

* Validate input
* Call collaborator
* Let unexpected failures bubble to the global ErrorHandler

### Catch only when

* There is a real recoverable branch
* You can intentionally produce a better HTTP response
* The fallback is explicit and documented

Example:

```php
try {
	$result = $operation->run($payload);
} catch (\InvalidArgumentException $e) {
	$this->app->response->jsonStatus([
		'ok' => false,
		'message' => $e->getMessage(),
	], 422);
}
```

Do not catch `\Throwable` broadly just to keep the request alive. CitOmni explicitly prefers narrow, justified exception handling. 

---

## 37) Performance guidance

Controllers run on the hot request path, so keep them lean.

### Recommended

* Normalize input once
* Keep per-action allocations small
* Read only the config values you need
* Delegate reusable behavior to Services
* Delegate non-trivial workflows to Operations
* Keep template payloads explicit and minimal
* Use `request` and `response` helpers instead of duplicating protocol parsing
* Use route config instead of recomputing route-local metadata

### Avoid

* Repeated deep config traversal inside loops
* Loading unnecessary collaborators
* Rebuilding the same derived values multiple times
* Embedding heavy logic in action methods
* Copy-paste logic across multiple controllers
* Re-implementing proxy / header / JSON parsing in Controllers

CitOmni optimizes for low overhead, explicit wiring, and predictable shapes. Controllers should reflect that philosophy. 

---

## 38) Testing Controllers

Controller testing usually sits above pure unit level because Controllers are HTTP adapters.

Typical approaches:

* Instantiate a Controller with a real or fixture-backed `App`
* Provide a route config array
* Populate request globals or use test bootstrapping that does so
* Assert on response side effects, rendered output, headers, redirects, JSON payloads, or error behavior

Example:

```php
public function testWebsiteLicensePageRenders(): void {
	$app = new \CitOmni\Kernel\App(
		__DIR__ . '/../_fixtures/config',
		\CitOmni\Kernel\Mode::HTTP
	);

	$routeConfig = [
		'controller' => \App\Http\Controller\PublicController::class,
		'action' => 'websiteLicense',
	];

	$controller = new \App\Http\Controller\PublicController($app, $routeConfig);

	\ob_start();
	$controller->websiteLicense();
	$output = \ob_get_clean();

	$this->assertIsString($output);
}
```

For reusable logic, prefer extracting into Services or Operations and testing those directly. Controller tests should focus on boundary behavior.

---

## 39) Common mistakes

### Mistake one

Putting SQL in the Controller.

Why it is wrong:

* Violates the persistence boundary
* Bloats the HTTP layer
* Hurts reuse

Correct fix:

* Move SQL into a Repository

### Mistake two

Putting reusable workflow logic in the Controller.

Why it is wrong:

* Couples business flow to HTTP
* Prevents reuse from CLI
* Produces oversized action methods

Correct fix:

* Move orchestration into an Operation

### Mistake three

Reading raw `$_GET`, `$_POST`, `$_FILES`, or `$_SERVER` everywhere.

Why it is wrong:

* Spreads protocol handling across the codebase
* Duplicates normalization logic
* Makes testing and review harder

Correct fix:

* Use `$this->app->request`

### Mistake four

Calling `header()` and `exit` manually all over the Controller.

Why it is wrong:

* Duplicates protocol output logic
* Makes behavior inconsistent
* Bypasses centralized response helpers

Correct fix:

* Use `$this->app->response`

### Mistake five

Putting template rendering inside an Operation.

Why it is wrong:

* Couples domain workflow to HTTP transport

Correct fix:

* Return domain-shaped arrays from the Operation
* Render in the Controller

### Mistake six

Using route config as a generic storage bag.

Why it is wrong:

* Obscures routing intent
* Mixes dispatch metadata with unrelated runtime state

Correct fix:

* Keep route config narrow and declarative

### Mistake seven

Overriding the constructor unnecessarily.

Why it is wrong:

* Fights the base contract
* Adds avoidable complexity

Correct fix:

* Use `init()` when cheap setup is needed

---

## 40) Authoring checklist

* [ ] Class extends `BaseController`
* [ ] Class is `final` by default
* [ ] Namespace and location match CitOmni structure
* [ ] No SQL in the Controller
* [ ] No multi-step reusable business workflow in the Controller
* [ ] Uses Repository directly only for trivial cases
* [ ] Uses Operation for non-trivial orchestration
* [ ] Uses Services via `$this->app->{id}`
* [ ] Uses `$this->app->request` for HTTP input and metadata
* [ ] Uses `$this->app->response` for HTTP output
* [ ] Uses `$this->routeConfig` only for route-local metadata
* [ ] Uses `$this->app->cfg` for runtime policy
* [ ] Public actions are clear, explicit, and readable top-to-bottom
* [ ] Response shaping stays in the Controller
* [ ] Error handling is fail-fast by default
* [ ] PHPDoc and comments are in English
* [ ] Tabs and K&R brace style are used
* [ ] Route wiring is declared explicitly in route files or provider registries

---

## 41) Summary

A CitOmni Controller is a thin, explicit HTTP adapter.

It exists to:

* read HTTP input
* perform HTTP-bound checks
* delegate real work to the correct layer
* shape the HTTP response

It does not exist to own persistence or general orchestration.

The mechanical rule is simple:

* **Controller** speaks HTTP
* **Operation** decides what happens
* **Repository** talks to storage
* **Service** provides reusable tools

Use the built-in `request` and `response` services as the canonical transport surface. That keeps Controllers smaller, keeps protocol edge cases centralized, and saves future-you from debugging five subtly different home-grown JSON / redirect / proxy / header implementations. Architecture is allowed to be practical. In fact, it should be.
