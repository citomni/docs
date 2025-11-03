# How to Build a CitOmni Provider (v1.0)

*A practical, deterministic guide for authoring provider packages.*

---

**Document type:** Implementation Guide
**Version:** 1.0
**Applies to:** CitOmni ≥ 8.2
**Audience:** Provider authors, app integrators, and core contributors
**Status:** Stable and canonical
**Author:** CitOmni Core Team
**Copyright:** © 2012-present CitOmni

---

## 1. What you're building

A **provider** is a Composer package that contributes **configuration overlays**, **routes**, and **services** to a CitOmni application **purely via constants**. No runtime scanning. No reflection. No side effects.

Providers expose up to six constants on a single class: `Boot\Registry`.

* `CFG_HTTP`, `CFG_CLI` - configuration overlays (deep, **last-wins**)
* `ROUTES_HTTP`, `ROUTES_CLI` - route overlays (path-keyed, **last-wins**)
* `MAP_HTTP`, `MAP_CLI` - service maps (ID-keyed, **left-wins per merge step**; in practice, **later providers override earlier ones**, and the **application layer wins last**)


The kernel reads those constants during boot and compiles three atomic caches:

```
var/cache/cfg.{http|cli}.php
var/cache/routes.{http|cli}.php
var/cache/services.{http|cli}.php
```

That's the whole trick: **constants in -> deterministic caches out**.

---

## 2. Prerequisites

* PHP ≥ 8.2, PSR-4 autoloading
* CitOmni kernel and (optionally) mode packages installed in the *application*
* Comfortable with the concepts in:

  * *Runtime Modes - CitOmni Application Kernel*
  * *Routing Layer - CitOmni HTTP Runtime*
  * *CitOmni Provider Packages (Design, Semantics, and Best Practices)*

---

## 3. Minimal provider layout

```
vendor-name/my-provider/
├─ composer.json
└─ src/
   ├─ Boot/
   │  └─ Registry.php                (constants only: CFG_*, ROUTES_*, MAP_*)
   ├─ Service/
   │  └─ DemoService.php             (example)
   ├─ Controller/                    (only if you expose HTTP routes)
   │  └─ DemoController.php
   └─ README.md
```

**composer.json (template):**

```json
{
  "name": "vendor-name/my-provider",
  "description": "CitOmni provider: demo capability",
  "type": "library",
  "license": "MIT",
  "require": {
    "php": ">=8.2",
    "citomni/kernel": "^1.0"
  },
  "autoload": {
    "psr-4": {
      "VendorName\\MyProvider\\": "src/"
    }
  },
  "config": { "optimize-autoloader": true, "sort-packages": true }
}
```

If you expose HTTP controllers or rely on HTTP services, add:

```json
"require": {
  "php": ">=8.2",
  "citomni/kernel": "^1.0",
  "citomni/http": "^1.0"
}
```

---

## 4. The heart of a provider: `Boot\Registry`

`Registry` **must not** execute code. It only defines constants.

```php
<?php
declare(strict_types=1);

namespace VendorName\MyProvider\Boot;

/**
 * Registry: declarative overlay surface for CitOmni.
 * Constants only; read directly by kernel builders:
 * - App::buildConfig()   -> CFG_HTTP / CFG_CLI (deep, last-wins)
 * - App::buildRoutes()   -> ROUTES_HTTP / ROUTES_CLI (path-keyed, last-wins)
 * - App::buildServices() -> MAP_HTTP / MAP_CLI (left-wins per step: later providers > earlier; app wins last)
 */
final class Registry {

	/** Config overlays (deep, last-wins) */
	public const CFG_HTTP = [
		'myProvider' => [
			'enabled'    => true,
			'api_base'   => 'https://api.example.test',
			'cache_ttl'  => 120,
		],
	];

	/** Service map (ID -> class or shape) - left-wins per merge step */
	public const MAP_HTTP = [
		// Bare FQCN: constructor receives (App $app)
		'demo' => \VendorName\MyProvider\Service\DemoService::class,

		// With options: constructor receives (App $app, array $options)
		'demoWithOpts' => [
			'class'   => \VendorName\MyProvider\Service\DemoService::class,
			'options' => ['mode' => 'strict']
		],
	];

	/** HTTP routes (path-keyed, last-wins; remember: suffix (.html/.json) is the public contract) */
	public const ROUTES_HTTP = [
		'/demo.html' => [
			'controller'    => \VendorName\MyProvider\Controller\DemoController::class,
			'action'        => 'index',
			'methods'       => ['GET'],
			'template_file' => 'public/demo.html', // optional if your renderer needs it
			'template_layer'=> 'vendor-name/my-provider',
		],
		'/demo' => [
			'controller' => \VendorName\MyProvider\Controller\DemoController::class,
			'action'     => 'postAction',
			'methods'    => ['POST'],
		],
	];

	/** CLI mirrors if needed
	public const CFG_CLI = [...];
	public const MAP_CLI = [...];
	public const ROUTES_CLI = [...]; // rare
	*/
}
```

> Keep keys **associative**; avoid numeric lists unless you intend downstream replacement.

---

## 5) Example service (authoring & registration)

Below is a **production-ready** example aligned with [CitOmni Services - Authoring, Registration, and Usage](https://github.com/citomni/docs/blob/main/how-to/services-authoring-registration-usage.md).
It uses `BaseService`, keeps construction **cheap & deterministic**, reads policy from `$this->app->cfg`, and treats map `'options'` as **construction-time wiring**.

### 5.1 Authoring (Service class)

```php
<?php
declare(strict_types=1);

namespace VendorName\MyProvider\Service;

use CitOmni\Kernel\Service\BaseService;

/**
 * Greeter: Deterministic greeting builder with minimal overhead.
 *
 * Responsibilities
 * - Build a greeting string from a validated name and an optional suffix.
 * - Options override config; both are optional.
 * - No I/O; no global state; fail-fast on invalid inputs.
 *
 * Performance
 * - Computes immutable scalars in init(); hot-path methods allocate minimally.
 *
 * Typical usage:
 *   $msg = $this->app->greeter->greet('Alice'); // "Hello, Alice - from My App"
 *
 * @throws \InvalidArgumentException On empty/invalid input name.
 */
final class Greeter extends BaseService {
	/** @var string Immutable suffix computed at init() */
	private string $suffix = '';

	/**
	 * One-time initialization per request/process.
	 * Merges construction options (from map) with runtime policy (from cfg).
	 *
	 * Precedence:
	 *   options['suffix'] > cfg.identity.app_name (prefixed with " - from ")
	 *
	 * @return void
	 */
	protected function init(): void {
		// 1) Read optional policy from config
		$appName = '';
		$identity = $this->app->cfg->identity ?? null;
		if (\is_object($identity)) {
			$appName = (string)($identity->app_name ?? '');
		}

		// 2) Consume options and clear them (free memory, avoid reuse)
		$opt = $this->options;
		$this->options = [];

		// 3) Compute immutable scalar
		$optSuffix = (string)($opt['suffix'] ?? '');
		$this->suffix = ($optSuffix !== '')
			? $optSuffix
			: ($appName !== '' ? ' - from ' . $appName : '');
	}

	/**
	 * Build a greeting (pure, allocation-lean).
	 *
	 * @param string $name Non-empty display name; trimmed.
	 * @return string Greeting string.
	 *
	 * @throws \InvalidArgumentException If $name is empty after trim.
	 */
	public function greet(string $name): string {
		$name = \trim($name);
		if ($name === '') {
			throw new \InvalidArgumentException('Name cannot be empty.');
		}
		return 'Hello, ' . $name . $this->suffix;
	}
}
```

**Key points**

* Extends `BaseService`; **do not** `new` it manually-let `App` resolve on first access.
* Reads config via `$this->app->cfg` (deep, read-only wrapper).
* Treats map `'options'` as construction-time hints; clears `$this->options` in `init()`.

---

### 5.2 Registration (provider map)

Expose the service via your provider's `Boot\Registry`:

```php
<?php
declare(strict_types=1);

namespace VendorName\MyProvider\Boot;

final class Registry {
	public const MAP_HTTP = [
		'greeter' => [
			'class'   => \VendorName\MyProvider\Service\Greeter::class,
			'options' => ['suffix' => ' - from Vendor'], // optional
		],
	];

	public const CFG_HTTP = [
		'identity' => ['app_name' => 'My App'], // optional
	];

	public const MAP_CLI = self::MAP_HTTP;
	public const CFG_CLI = self::CFG_HTTP;
}
```

> **Services precedence:** vendor baseline -> providers (iterated in order, but **later providers override earlier ones**) -> **application**.  
> The kernel applies `$map = $pvMap + $map;` per provider and `$map = $appMap + $map;` last.  
> Array union is **left-wins per step**, which means the application layer always wins overall.

---

### 5.3 Application override (optional)

Swap implementation or tweak options without touching the provider:

```php
<?php
// /config/services.php
return [
	// Replace implementation:
	'greeter' => \App\Service\CustomGreeter::class,

	// ...or keep class and change options:
	// 'greeter' => [
	// 	'class'   => \Vendor\Package\Service\Greeter::class,
	// 	'options' => ['suffix' => ' - from Production'],
	// ],
];
```

---

### 5.4 Usage

```php
// Anywhere with $this->app:
$msg = $this->app->greeter->greet('World');
```

That's it: **constants in -> deterministic caches out**. Your service now follows the CitOmni contract for **authoring, registration, and usage**.

---

## 6. Example controller (only if you expose HTTP routes)

```php
<?php
declare(strict_types=1);

namespace VendorName\MyProvider\Controller;

use CitOmni\Kernel\Controller\BaseController;

/**
 * DemoController: simple example for provider-exposed HTTP route.
 *
 * Controllers are short-lived action handlers:
 * - Instantiated once per request by the Router.
 * - `$this->app` is injected automatically.
 * - `init()` runs before the first action call.
 *
 * @see \CitOmni\Http\Router
 */
final class DemoController extends BaseController {

	protected function init(): void {
		// Optional setup (runs once per controller instance)
	}

	public function index(): void {
		$msg = $this->app->greeter->greet('World');
		$this->app->tplEngine->render('public/demo.html@vendor-name/my-provider', [
			'message' => $msg,
		]);
	}

	public function postAction(): void {
		// Example CSRF guard (recommended for POST actions)
		// Tip: Render field via {{ $csrfField() }} in your template.
		$this->app->security->guardCsrf($this->app->request->post('_csrf'));

		// Handle input / persistence / etc.
		$this->app->response->redirect('/demo.html');
	}
}
```

> The router **instantiates** controllers; they are *not* services. Exceptions bubble deterministically to the mode-specific ErrorHandler, which logs and renders structured responses (HTML or JSON).

---

## 7. Register the provider in an application

In the **app** repository:

### 7.1 Add to `/config/providers.php`

```php
<?php
declare(strict_types=1);

return [
	// other providers...
	\VendorName\MyProvider\Boot\Registry::class,
];
```

### 7.2 Warm caches

Call your warm-up (HTTP mode shown):

```php
// e.g., in a deploy step or admin tool:
$this->app->warmCache(overwrite: true, opcacheInvalidate: true);

// Resulting artifacts:
var/cache/cfg.http.php
var/cache/routes.http.php
var/cache/services.http.php
```

After warming, the app boots and routes without including provider boot files (the kernel reads atomic cache files only).

---

## 8. Overriding from the application

### 8.1 Override service implementation or options

`/config/services.php`:

```php
<?php
return [
	// Replace provider service by ID:
	'demo' => \App\Service\CustomDemoService::class,

	// Or just tweak options:
	'demoWithOpts' => [
		'class'   => \VendorName\MyProvider\Service\DemoService::class,
		'options' => ['mode' => 'paranoid'],
	],
];
```

*Services use **left-wins** per merge step; the app runs last, so the app's ID wins.*

### 8.2 Override configuration

`/config/citomni_http_cfg.php`:

```php
<?php
return [
	'myProvider' => [
		'enabled'   => true,
		'api_base'  => 'https://api.example.prod',
		'cache_ttl' => 300,
	],
];
```

*Config uses **last-wins** (deep).*

### 8.3 Override a route

`/config/citomni_http_routes.php`:

```php
<?php
use App\Controller\BetterDemoController;

return [
	'/demo.html' => [
		'controller'    => BetterDemoController::class,
		'action'        => 'index',
		'methods'       => ['GET'],
		'template_file' => 'public/demo.html',
		'template_layer'=> 'app',
	],
];
```

*Routes use **last-wins by path key**. Remember: suffixes (`.html`, `.json`) are part of the public contract.*

Re-warm caches after any of the above.

---

## 9. Routing specifics (HTTP)

* Prefer literal, path-keyed entries:

  ```php
  '/feature.html' => [...], '/api/feature.json' => [...]
  ```
* Action-only endpoints (PRG, redirects) have **no suffix**:

  ```
  '/feature' => ['methods' => ['POST']]
  ```
* Regex routes go under a `regex` list **only if necessary**; otherwise keep routing simple and literal.
* The router raises 404/405/5xx via the central **ErrorHandler**. Do not define "error routes."

---

## 10. Error handling (don't catch broadly)

Let exceptions and PHP errors bubble:

* The HTTP mode's `\CitOmni\Http\Service\ErrorHandler` performs logging (JSONL rotation),
  content negotiation (HTML/JSON), and safe rendering.
* If you must intentionally raise an HTTP fault, call:

  ```php
  $this->app->errorHandler->httpError(404, ['reason' => 'not_found']);
  ```

---

## 11. Testing your provider

* **Unit**: test services as plain classes (pass a minimal App test harness).
* **Routes**: assert that `/demo.html` resolves to the correct controller/action.
* **Overrides**: add tests proving app-side `services.php` wins for the same ID.
* **Purity**: require `Boot/Registry.php` and assert no side effects (no output, no globals, no function calls).

---

## 12. Performance notes

* Constants only in `Boot/Registry.php`.
* Constructors should be cheap; defer I/O till first use.
* Caches are **atomic** `return [ ... ];` files; safe for OPcache with `validate_timestamps=0`.
* Warming any cache does not require warming the others, but warming **all three** is recommended after provider changes.

---

## 13. Versioning, stability, and API surface

* **Service IDs** and top-level **config keys** are public API. Renaming them is a **breaking change**.
* Route **path keys** are public contract; changing or removing them is breaking.
* Use SemVer. Document deprecations and provide migration notes.

---

## 14. Quick checklist (TL;DR)

* [ ] Create `src/Boot/Registry.php` with any of: `CFG_*`, `ROUTES_*`, `MAP_*`.
* [ ] No code execution in `Boot/Registry.php`. **Constants only.**
* [ ] Services follow `__construct(App $app, array $options = [])`.
* [ ] Routes include correct suffixes (`.html` / `.json`) where applicable.
* [ ] Add provider FQCN to the app's `/config/providers.php`.
* [ ] Rebuild caches: cfg, routes, services.
* [ ] Write unit tests for services and route resolution.
* [ ] Document your public service IDs and config keys.

---

## 15. Complete minimal example (copy-paste ready)

**`src/Boot/Registry.php`**

```php
<?php
declare(strict_types=1);

namespace VendorName\MyProvider\Boot;

/**
 * Provider registry: declarative overlays only.
 * Constants are read by the kernel builders:
 * - App::buildConfig()   -> CFG_HTTP / CFG_CLI
 * - App::buildRoutes()   -> ROUTES_HTTP / ROUTES_CLI
 * - App::buildServices() -> MAP_HTTP / MAP_CLI
 */
final class Registry {

	/** HTTP configuration overlay (deep, last-wins) */
	public const CFG_HTTP = [
		'myProvider' => [
			'enabled'   => true,
			'cache_ttl' => 60,
		],
	];

	/** HTTP service map (ID -> class or ['class'=>..., 'options'=>...]) */
	public const MAP_HTTP = [
		// Bare class (constructor receives App, []):
		'demo' => \VendorName\MyProvider\Service\DemoService::class,

		// Example with options (uncomment if needed):
		// 'demo' => [
		// 	'class'   => \VendorName\MyProvider\Service\DemoService::class,
		// 	'options' => ['suffix' => ' - from MyProvider'],
		// ],
	];

	/** HTTP routes (path-keyed, last-wins) */
	public const ROUTES_HTTP = [
		'/demo.html' => [
			'controller'    => \VendorName\MyProvider\Controller\DemoController::class,
			'action'        => 'index',
			'methods'       => ['GET'],
			'template_file' => 'public/demo.html',
			'template_layer'=> 'vendor-name/my-provider',
		],
	];
}
```

---

**`src/Service/DemoService.php`**

```php
<?php
declare(strict_types=1);

namespace VendorName\MyProvider\Service;

use CitOmni\Kernel\Service\BaseService;

/**
 * DemoService: Tiny example service.
 *
 * - Deterministic init(): consumes options, derives immutable scalars.
 * - No I/O in init(); keep hot paths allocation-lean.
 */
final class DemoService extends BaseService {

	private string $suffix = '';

	protected function init(): void {
		$opt = $this->options;
		$this->options = []; // free memory, avoid accidental reuse

		$appName = (string)($this->app->cfg->identity->app_name ?? '');
		$this->suffix = (string)($opt['suffix'] ?? ($appName !== '' ? ' - from ' . $appName : ''));
	}

	public function ping(): string {
		return 'pong' . $this->suffix;
	}
}
```

---

**`src/Controller/DemoController.php`**

```php
<?php
declare(strict_types=1);

namespace VendorName\MyProvider\Controller;

use CitOmni\Kernel\Controller\BaseController;

/**
 * DemoController: provider-exposed HTTP route handler.
 *
 * Controllers are instantiated by the Router (not services).
 * Exceptions bubble to the mode-specific ErrorHandler.
 */
final class DemoController extends BaseController {

	protected function init(): void {
		// Optional per-controller setup (once per instance).
		// Keep it side-effect free and cheap.
	}

	public function index(): void {
		$msg = 'Service says: ' . $this->app->demo->ping();

		// Render using provider layer notation:
		$this->app->tplEngine->render('public/demo.html@vendor-name/my-provider', [
			'message' => $msg,
		]);
	}
}
```

---

**App integration**

```php
// /config/providers.php
<?php
return [
	\VendorName\MyProvider\Boot\Registry::class,
];
```

*(Optional) App-side overrides for services:*

```php
// /config/services.php
<?php
return [
	// Override provider's 'demo' with app-local implementation or options:
	// 'demo' => [
	// 	'class'   => \App\Service\DemoService::class,
	// 	'options' => ['suffix' => ' - from ThisApp'],
	// ],
];
```

**Deploy step (warm caches per mode):**

* `var/cache/cfg.http.php`
* `var/cache/routes.http.php`
* `var/cache/services.http.php`

```php
// Anywhere in your deploy/bootstrap script:
$this->app->warmCache(overwrite: true, opcacheInvalidate: true);
```

Then visit **`/demo.html`**.

---

**In essence:**

> A provider is a **declarative overlay**: constants on `Boot\Registry`, tiny Services extending `BaseService`, and simple Controllers extending `BaseController`.
> The kernel composes config, routes, and services into atomic caches. **Explicit. Deterministic. Fast.**
