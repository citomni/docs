# How to Build a CitOmni Provider (v1.0)

*A practical, deterministic guide for authoring provider packages.*

---

**Document type:** Implementation Guide
**Version:** 1.0
**Applies to:** CitOmni on PHP 8.5+
**Audience:** Provider authors, app integrators, and core contributors
**Status:** Stable and canonical
**Author:** CitOmni Core Team
**Copyright:** © 2012-present CitOmni

---

## 1. What you're building

A **provider** is a Composer package that contributes **configuration overlays**, **routes**, and **services** to a CitOmni application **purely via constants**. No runtime scanning. No reflection. No side effects.

Providers expose these constants on a single class, `Boot\Registry`, and declare only the ones they need:

* `CFG_COMMON`, `CFG_HTTP`, `CFG_CLI` - configuration overlays (deep, **last-wins**; the mode-specific constant wins over `CFG_COMMON`)
* `MAP_COMMON`, `MAP_HTTP`, `MAP_CLI` - service maps (ID-keyed, **left-wins per merge step**; in practice, **later providers override earlier ones**, and the **application layer wins last**)
* `ROUTES_HTTP` - HTTP routes (path-keyed, **last-wins**)
* `COMMANDS_CLI` - CLI commands


The kernel reads those constants during boot and compiles three atomic caches per mode:

```
var/cache/cfg.{http|cli}.php
var/cache/routes.http.php, var/cache/commands.cli.php
var/cache/services.{http|cli}.php
```

That's the whole trick: **constants in -> deterministic caches out**.

For a complete, tested provider that applies this guide, see [citomni/demo-provider](https://github.com/citomni/demo-provider). Its `src/README.md` holds the cross-cutting rules, and each layer folder has a README with that layer's rules and a working example.

---

## 2. Prerequisites

* PHP 8.5+, PSR-4 autoloading
* CitOmni kernel and (optionally) mode packages installed in the *application*
* Comfortable with the concepts in:

  * [Runtime Modes - CitOmni Application Kernel](../concepts/runtime-modes.md)
  * [Routing Layer - CitOmni HTTP Runtime](../concepts/routing-layer-http-runtime.md)
  * [CitOmni Provider Packages (Design, Semantics, and Best Practices)](../concepts/services-and-providers.md)

---

## 3. Minimal provider layout

```
vendor-name/my-provider/
├─ composer.json
├─ templates/                        (only if you render templates)
│  └─ public/
│     └─ demo.html
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
    "php": "^8.5",
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
  "php": "^8.5",
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
 * Registry: Boot contributions of this provider package.
 *
 * Declares cfg baselines, services and routes as constant arrays for the App to merge at boot.
 *
 * Rules:
 * - Constants only: No code runs here, and nothing reads the environment.
 */
final class Registry {

	/** Cfg baseline for both modes (deep, last-wins). */
	public const array CFG_COMMON = [
		'my_provider' => [
			'enabled'   => true,                        // true turns the provider's features on.
			'api_base'  => 'https://api.example.test',  // Base URL of the remote API.
			'cache_ttl' => 120,                         // Seconds that fetched data stays cached.
			'demo'      => [
				'suffix' => '',  // Text appended to every ping answer, such as " - from My App"; empty for none.
			],
		],
	];

	/** Cfg baseline for HTTP: The template layer that the package's routes render from. */
	public const array CFG_HTTP = [
		'view' => [
			'template_layers' => [
				'vendor-name/my-provider' => \CITOMNI_APP_PATH . '/vendor/vendor-name/my-provider/templates',  // Templates of this package.
			],
		],
	];

	/** Services for both modes (ID -> class or shape; left-wins per merge step). */
	public const array MAP_COMMON = [
		// Bare FQCN: constructor receives (App $app)
		'demo' => \VendorName\MyProvider\Service\DemoService::class,

		// With options: constructor receives (App $app, array $options); the suffix option wins over cfg
		'demoWithOpts' => [
			'class'   => \VendorName\MyProvider\Service\DemoService::class,
			'options' => ['suffix' => ' - strict'],
		],
	];

	/** HTTP routes (path-keyed, last-wins; remember: suffix (.html/.json) is the public contract) */
	public const array ROUTES_HTTP = [
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

	// Mode-specific contributions, only for what depends on one mode:
	// public const array CFG_CLI = [...];
	// public const array MAP_HTTP = [...];
	// public const array MAP_CLI = [...];
	// public const array COMMANDS_CLI = [...];
}
```

> Keep keys **associative**; avoid numeric lists unless you intend downstream replacement.

> A package that renders templates registers its layer under `view.template_layers` in `CFG_HTTP`, because `view` is cfg of citomni/http and only HTTP mode renders. A layer slug is `vendor/package`; `app` is reserved for the application's own templates. Template references then use `relative/path.html@vendor-name/my-provider`.

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
 * Greeter: Service that builds greeting strings.
 *
 * Builds a greeting from a validated name and an optional suffix. Printing or rendering the
 * greeting is left to the caller.
 *
 * Rules:
 * - No I/O and no global state; compute immutable scalars in init().
 * - Reject invalid input with \InvalidArgumentException.
 */
final class Greeter extends BaseService {
	/** Text appended to every greeting; empty for none. */
	private string $suffix;

	/**
	 * Resolve the greeting suffix once per instance.
	 *
	 * Behavior:
	 * - The suffix option wins over cfg my_provider.greeter.suffix.
	 *
	 * @return void
	 */
	protected function init(): void {
		// Package-owned cfg is the right operand, which ?? does not guard, so a missing baseline throws.
		$this->suffix = (string)($this->options['suffix'] ?? $this->app->cfg->my_provider->greeter->suffix);
	}

	/**
	 * Build a greeting for a name.
	 *
	 * @param  string  $name  Display name; trimmed, must not be empty.
	 * @return string  "Hello, {name}" followed by the suffix.
	 * @throws \InvalidArgumentException  When the trimmed name is empty.
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
* Reads its own cfg, `my_provider.greeter.suffix`, directly. The provider ships that baseline (5.2), so a missing baseline throws `\OutOfBoundsException` instead of hiding behind a default.
* Treats map `'options'` as construction-time wiring: A set `suffix` option wins over cfg, an empty string included.

---

### 5.2 Registration (provider map)

Expose the service via your provider's `Boot\Registry`:

```php
<?php
declare(strict_types=1);

namespace VendorName\MyProvider\Boot;

final class Registry {
	/** Services for HTTP and CLI. */
	public const array MAP_COMMON = [
		'greeter' => \VendorName\MyProvider\Service\Greeter::class,
	];

	/** Cfg baseline for both modes. */
	public const array CFG_COMMON = [
		'my_provider' => [
			'greeter' => [
				'suffix' => '',  // Text appended to every greeting, such as " - from My App"; empty for none.
			],
		],
	];
}
```

Both modes use the service and its cfg, so they go in `MAP_COMMON` and `CFG_COMMON`. Declare `MAP_HTTP` / `MAP_CLI` or `CFG_HTTP` / `CFG_CLI` only for what depends on one mode. The cfg sits under the provider's own root key, `my_provider`, so it never collides with another package's cfg.

> **Services precedence:** vendor baseline -> providers (iterated in order; within a provider, `MAP_HTTP` / `MAP_CLI` wins over `MAP_COMMON`, and **later providers override earlier ones**) -> **application** (`/config/services.php`, then `/config/services_{http|cli}.php`).
>
> The kernel applies `$map = $pvMap + $map;` per provider constant, `MAP_COMMON` first, and `$map = $appMap + $map;` last.
>
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
	// 	'class'   => \VendorName\MyProvider\Service\Greeter::class,
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
 * DemoController: HTTP adapter for the provider's demo routes.
 */
final class DemoController extends BaseController {

	/**
	 * Run setup and guards that every action needs.
	 *
	 * Notes:
	 * - Holds no access guard, so each action is exactly as exposed as its route.
	 *
	 * @return void
	 * @see \CitOmni\Kernel\Controller\BaseController::__construct()  Calls it before every action.
	 */
	protected function init(): void {
	}

	/**
	 * Render the demo page with a greeting.
	 *
	 * @return void
	 */
	public function index(): void {
		$msg = $this->app->greeter->greet('World');
		$this->app->tplEngine->render('public/demo.html@vendor-name/my-provider', [
			'message' => $msg,
		]);
	}

	/**
	 * Handle the demo form and redirect to the demo page.
	 *
	 * Behavior:
	 * - A rejected CSRF token throws, and the global error handler answers.
	 * - Otherwise redirects to /demo.html.
	 *
	 * @return void
	 * @throws \CitOmni\Http\Exception\CsrfVerificationException  When the CSRF token is rejected.
	 */
	public function postAction(): void {
		// The form renders its token with {{{ $csrfField() }}}.
		$this->app->csrf->requireValid();

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

From the app root, in a deploy step:

```text
php bin/citomni cache:warm
```

The `cache:warm` command of citomni/cli warms CLI first, then HTTP when citomni/http is installed (`--mode=cli|http|all`, `--env=<env>`):

```text
var/cache/cfg.cli.php
var/cache/commands.cli.php
var/cache/services.cli.php
var/cache/cfg.http.php
var/cache/routes.http.php
var/cache/services.http.php
```

`$this->app->warmCache()` writes only the three files of one mode: the mode of the App it is called on.

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
		'options' => ['suffix' => ' - paranoid'],
	],
];
```

*Services use **left-wins** per merge step; the app runs last, so the app's ID wins.*

### 8.2 Override configuration

`/config/citomni_cfg.php`, which both modes read:

```php
<?php
return [
	'my_provider' => [
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
use App\Http\Controller\BetterDemoController;

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
* Placeholder routes such as `/user/{id}` go under the `regex` key **only if necessary**; otherwise keep routing simple and literal.
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

Write the checks as standalone suites under `tests/`, as described in the [CitOmni Testing Guide](../contribute/testing-guide.md). The `tests/` folder of [citomni/demo-provider](https://github.com/citomni/demo-provider) shows such suites for a provider.

---

## 12. Performance notes

* Constants only in `Boot/Registry.php`.
* Constructors should be cheap; defer I/O till first use.
* Caches are **atomic** `return [ ... ];` files, written through a temp file and a rename.
* Warm the caches after every provider change; the App uses existing cache files without comparing them with the Registry.
* With `opcache.validate_timestamps=0`, OPcache invalidation from the CLI does not reach the web server. Reload PHP-FPM or the web server after `cache:warm`, or use the HMAC-protected `POST /_system/reset-cache` of citomni/http and warm again.

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
* [ ] Register the template layer under `view.template_layers` in `CFG_HTTP` when the package renders templates.
* [ ] Rebuild caches: `php bin/citomni cache:warm`.
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
 * Registry: Boot contributions of this provider package.
 *
 * Declares cfg baselines, services and routes as constant arrays for the App to merge at boot.
 *
 * Rules:
 * - Constants only: No code runs here, and nothing reads the environment.
 */
final class Registry {

	/** Cfg baseline for both modes (deep, last-wins). */
	public const array CFG_COMMON = [
		'my_provider' => [
			'enabled'   => true,  // true turns the provider's features on.
			'cache_ttl' => 60,    // Seconds that fetched data stays cached.
			'demo'      => [
				'suffix' => '',  // Text appended to every ping answer, such as " - from My App"; empty for none.
			],
		],
	];

	/** Cfg baseline for HTTP: The template layer that the package's routes render from. */
	public const array CFG_HTTP = [
		'view' => [
			'template_layers' => [
				'vendor-name/my-provider' => \CITOMNI_APP_PATH . '/vendor/vendor-name/my-provider/templates',  // Templates of this package.
			],
		],
	];

	/** Services for both modes (ID -> class or ['class'=>..., 'options'=>...]). */
	public const array MAP_COMMON = [
		// Bare class (constructor receives App, []):
		'demo' => \VendorName\MyProvider\Service\DemoService::class,

		// Example with options (uncomment if needed):
		// 'demo' => [
		// 	'class'   => \VendorName\MyProvider\Service\DemoService::class,
		// 	'options' => ['suffix' => ' - from MyProvider'],
		// ],
	];

	/** HTTP routes (path-keyed, last-wins) */
	public const array ROUTES_HTTP = [
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
 * Rules:
 * - No I/O in init(); derive immutable scalars there and keep hot paths allocation-lean.
 */
final class DemoService extends BaseService {

	/** Text appended to every ping answer; empty for none. */
	private string $suffix;

	/**
	 * Resolve the ping suffix once per instance.
	 *
	 * Behavior:
	 * - The suffix option wins over cfg my_provider.demo.suffix.
	 *
	 * @return void
	 */
	protected function init(): void {
		$this->suffix = (string)($this->options['suffix'] ?? $this->app->cfg->my_provider->demo->suffix);
	}

	/**
	 * Answer a liveness string.
	 *
	 * @return string  "pong" followed by the suffix.
	 */
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
 * DemoController: HTTP adapter for the provider's demo page.
 */
final class DemoController extends BaseController {

	/**
	 * Run setup and guards that every action needs.
	 *
	 * Notes:
	 * - Holds no access guard, so each action is exactly as exposed as its route.
	 *
	 * @return void
	 * @see \CitOmni\Kernel\Controller\BaseController::__construct()  Calls it before every action.
	 */
	protected function init(): void {
	}

	/**
	 * Render the demo page with the demo service's ping answer.
	 *
	 * @return void
	 */
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

**Deploy step (warm caches for both modes):**

```text
php bin/citomni cache:warm
```

Then visit **`/demo.html`**.

---

**In essence:**

> A provider is a **declarative overlay**: constants on `Boot\Registry`, tiny Services extending `BaseService`, and simple Controllers extending `BaseController`.
> The kernel composes config, routes, and services into atomic caches. **Explicit. Deterministic. Fast.**
