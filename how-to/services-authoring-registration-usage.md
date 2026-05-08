# CitOmni Services - Authoring, Registration, and Usage (PHP 8.2+)

> **Low overhead. High performance. Predictable by design.**

This document explains how to build Services for CitOmni: What they are, how they are registered, how they are resolved, how they consume configuration, and how to keep them deterministic and cheap.

It covers both reusable provider packages and app-local service overrides through `/config/services.php`.

---

**Document type:** Technical Guide  
**Version:** 1.1  
**Applies to:** CitOmni PHP 8.2+  
**Audience:** Application and provider developers  
**Status:** Stable and foundational  
**Author:** CitOmni Core Team  
**Copyright:** Copyright (c) 2012-present CitOmni

---

## Architecture overview

CitOmni uses explicit boot registries, deterministic merge rules, and lazy service resolution.

Provider packages expose boot metadata through:

```text
src/Boot/Registry.php
```

A provider registry may define these constants, all optional:

| Constant     | Read by              | Purpose                   |
|--------------|----------------------|---------------------------|
| MAP_HTTP     | App::buildServices() | Service map for HTTP mode |
| MAP_CLI      | App::buildServices() | Service map for CLI mode  |
| CFG_HTTP     | App::buildConfig()   | Config overlay for HTTP   |
| CFG_CLI      | App::buildConfig()   | Config overlay for CLI    |
| ROUTES_HTTP  | App::buildRoutes()   | HTTP route dispatch map   |
| COMMANDS_CLI | App::buildCommands() | CLI command dispatch map  |

Important:

* Services are not discovered by scanning directories.
* Registries are declarative.
* Routes are not config.
* CLI commands are not routes.
* HTTP dispatch uses routes.
* CLI dispatch uses commands.
* Service map merging uses PHP array union semantics.
* Config and dispatch maps use deep associative last-wins merging.

In normal HTTP usage, the front controller calls:

```php
\CitOmni\Http\Kernel::run(__DIR__);
```

The HTTP kernel resolves paths, constructs `App`, installs the error handler, configures runtime settings, defines `CITOMNI_PUBLIC_ROOT_URL` when needed, applies trusted proxies, runs the maintenance guard, and finally dispatches through the router.

---

## 1) What is a Service?

A Service is a small, focused object resolved by `App` and accessed as a property:

```php
$this->app->request;
$this->app->response;
$this->app->cookie;
$this->app->session;
$this->app->csrf;

// Your own service:
$this->app->greeter;
```

Resolution model:

* `App` holds a service map.
* The service map is an associative array of `id => definition`.
* The first access to `$this->app->{id}` instantiates the service.
* The created instance is cached for the current request/process.
* Later access to the same ID returns the same instance.

Services are therefore lazy singletons scoped to one request/process.

---

## 2) Constructor contract

Every Service must support this constructor contract:

```php
new Service(\CitOmni\Kernel\App $app, array $options = [])
```

CitOmni's `BaseService` already implements that contract:

```php
<?php
declare(strict_types=1);

namespace CitOmni\Kernel\Service;

use CitOmni\Kernel\App;

abstract class BaseService {
	protected App $app;
	protected array $options;

	public function __construct(App $app, array $options = []) {
		$this->app = $app;
		$this->options = $options;

		if (\method_exists($this, 'init')) {
			$this->init();
		}
	}
}
```

Rules:

* Do not manually instantiate regular Services from Controllers, Commands, Operations, or other Services.
* Register them in a service map.
* Access them through `$this->app->{id}`.
* Use `init()` for cheap one-time setup.
* Keep `init()` deterministic and low-cost.

The constructor contract exists for the resolver. Design for it, but do not call it directly in application flow.

---

## 3) Where Services come from

Services come from three layers:

1. Vendor baseline map.
2. Provider maps.
3. App-local `/config/services.php`.

The current `App::buildServices()` flow is:

```php
$map = match ($mode) {
	Mode::HTTP => \CitOmni\Http\Boot\Registry::MAP_HTTP,
	Mode::CLI  => \CitOmni\Cli\Boot\Registry::MAP_CLI,
};

foreach ($providers as $fqcn) {
	$constFq = $fqcn . '::' . $const;

	if (\defined($constFq)) {
		$pvmap = \constant($constFq);
		$map = $pvmap + $map;
	}
}

if (\is_file($appMapFile)) {
	$appMap = require $appMapFile;
	$map = $appMap + $map;
}
```

Service precedence:

```text
/config/services.php
  > last provider in /config/providers.php
  > earlier providers
  > vendor baseline registry
```

Because service maps are merged with PHP's `+` operator, the left side wins on duplicate string keys.

Example:

```php
$map = $providerMap + $map;
```

That means the provider wins over the previous map for matching service IDs.

The app layer always wins:

```php
$map = $appMap + $map;
```

---

## 4) Service definition shapes

A service definition may be either a class string:

```php
'response' => \CitOmni\Http\Service\Response::class,
```

Or an array with class and optional options:

```php
'greeter' => [
	'class' => \Vendor\Package\Service\Greeter::class,
	'options' => [
		'suffix' => '- from Example',
	],
],
```

The resolver accepts only these shapes:

```php
'id' => Fully\Qualified\ClassName::class
```

Or:

```php
'id' => [
	'class' => Fully\Qualified\ClassName::class,
	'options' => [],
]
```

Invalid definitions fail fast when the service is first accessed.

---

## 5) Configuration vs. service options

Options and config are separate concepts.

### Service options

Service options come from the service map:

```php
'mailer' => [
	'class' => \Vendor\Package\Service\Mailer::class,
	'options' => [
		'transport' => 'smtp',
	],
],
```

Options are passed as the second constructor argument:

```php
new Mailer($app, $options);
```

Use options for construction-time wiring.

Good use cases:

* Selecting an implementation detail.
* Passing a small adapter setting.
* App-specific wiring that should not become general runtime policy.

Avoid using options for:

* Large config trees.
* Secrets.
* Runtime policy.
* Values that should naturally belong in config.

### Config

Config is runtime policy. Read it through:

```php
$this->app->cfg
```

Config is built from:

```text
Vendor baseline registry:
- \CitOmni\Http\Boot\Registry::CFG_HTTP
- \CitOmni\Cli\Boot\Registry::CFG_CLI

Provider overlays:
- Provider::CFG_HTTP
- Provider::CFG_CLI

App base:
- /config/citomni_http_cfg.php
- /config/citomni_cli_cfg.php

App env:
- /config/citomni_http_cfg.{ENV}.php
- /config/citomni_cli_cfg.{ENV}.php
```

Config merge rule:

```text
Deep associative merge, last wins.
```

### Safe cfg access

Example leaf-level read:

```php
$timezone = (string)($this->app->cfg->locale->timezone ?? 'UTC');
```

Important detail:

```php
$value = $this->app->cfg->unknown->key ?? 'fallback';
```

This is not safe if `unknown` is missing, because the intermediate `unknown` access calls `__get()` directly.

Use `isset()` when an intermediate node might be absent:

```php
$value = 'fallback';

if (isset($this->app->cfg->my_node)) {
	$value = (string)($this->app->cfg->my_node->key ?? 'fallback');
}
```

### Package cfg ownership rule

Packages must ship cfg baselines via `Registry::CFG_HTTP` / `Registry::CFG_CLI` for every cfg path they read at runtime.

That makes package-owned intermediate keys guaranteed.

Good:

```php
$maxAge = (int)($this->app->cfg->authenticate->reauth->max_age_seconds ?? 300);
```

This is safe when `authenticate.reauth` is guaranteed by the package registry.

Avoid defensive `isset()` around package-owned cfg paths:

```php
if (isset($this->app->cfg->authenticate)) {
	// Usually a smell inside citomni/authenticate itself.
}
```

If a package-owned path is missing, fix the package baseline instead of adding defensive reads.

Use `isset()` for cross-package optional cfg only:

```php
if (isset($this->app->cfg->commerce)) {
	$timeout = (int)($this->app->cfg->commerce->checkout->timeout ?? 30);
}
```

Baseline top-level nodes may be accessed directly when the vendor baseline guarantees them.

---

## 6) Dispatch maps are not service maps

CitOmni has separate maps for:

* Config.
* Services.
* HTTP routes.
* CLI commands.

HTTP dispatch:

```text
App::$routes
```

CLI dispatch:

```text
App::$commands
```

Current cache files:

```text
/var/cache/cfg.http.php
/var/cache/cfg.cli.php

/var/cache/services.http.php
/var/cache/services.cli.php

/var/cache/routes.http.php
/var/cache/commands.cli.php
```

There is no `routes.cli.php` dispatch cache in the current model. CLI dispatch uses commands:

```text
commands.cli.php
```

HTTP routes are built from:

```text
Vendor baseline:
- \CitOmni\Http\Boot\Registry::ROUTES_HTTP

Provider registries:
- Provider::ROUTES_HTTP

App base:
- /config/citomni_http_routes.php

App env:
- /config/citomni_http_routes.{ENV}.php
```

CLI commands are built from:

```text
Vendor baseline:
- \CitOmni\Cli\Boot\Registry::COMMANDS_CLI

Provider registries:
- Provider::COMMANDS_CLI

App base:
- /config/citomni_cli_commands.php

App env:
- /config/citomni_cli_commands.{ENV}.php
```

Dispatch merge rule:

```text
Deep associative merge, last wins.
```

Services should not read routes or commands from config. Routes and commands are compiled dispatch maps, not config nodes.

---

## 7) `/config/services.php`

The application may define local services or override provider/vendor services in:

```text
/config/services.php
```

The file must return an associative array.

Example:

```php
<?php
declare(strict_types=1);

return [
	'greeter' => [
		'class' => \App\Service\Greeter::class,
		'options' => [
			'suffix' => '- from My App',
		],
	],

	'response' => \App\Http\Response::class,
];
```

Use `/config/services.php` when:

* The service is app-local.
* You need to override a vendor/provider service in one app.
* You want lightweight app-specific wiring.
* You are not building a reusable package yet.

Do not use it for reusable package defaults. Put those in the package registry instead.

---

## 8) Provider package registration

Reusable packages should expose service, config, route, and command contributions through:

```text
src/Boot/Registry.php
```

Package layout note:

Application code uses transport folders:

* HTTP controllers: `src/Http/Controller/`
* CLI commands: `src/Cli/Command/`

Provider packages use package-root transport folders by default:

* HTTP controllers: `src/Controller/`
* CLI commands: `src/Command/`

Do not invent `src/Http/Controller/` or `src/Cli/Command/` inside provider packages unless the package deliberately uses an application-like structure.

Example:

```php
<?php
declare(strict_types=1);
/*
 * This file is part of the CitOmni framework.
 * Low overhead, high performance, ready for anything.
 *
 * For more information, visit https://github.com/citomni
 *
 * Copyright (c) 2012-present Lars Grove Mortensen
 * SPDX-License-Identifier: MIT
 *
 * For full copyright, trademark, and license information,
 * please see the LICENSE file distributed with this source code.
 */

namespace Vendor\Package\Boot;

/**
 * Declare this provider package's boot contributions.
 *
 * Behavior:
 * - Registers HTTP and CLI service bindings.
 * - Registers HTTP and CLI cfg overlays.
 * - Registers HTTP routes.
 * - Registers CLI commands when the package provides CLI entry points.
 *
 * Notes:
 * - Keep registries declarative.
 * - No I/O, no runtime branching, no environment reads.
 * - Routes and commands are dispatch maps, not cfg.
 */
final class Registry {
	public const MAP_HTTP = [
		'greeter' => [
			'class' => \Vendor\Package\Service\Greeter::class,
			'options' => [
				'suffix' => '- from Vendor',
			],
		],
	];

	public const MAP_CLI = self::MAP_HTTP;

	public const CFG_HTTP = [
		'greeter' => [
			'default_suffix' => '- from cfg',
		],
	];

	public const CFG_CLI = self::CFG_HTTP;

	public const ROUTES_HTTP = [
		'/greeter' => [
			'controller' => \Vendor\Package\Controller\GreeterController::class,
			'action' => 'index',
			'methods' => ['GET'],
			'template_file' => 'greeter/index.html',
			'template_layer' => 'vendor/package',
		],
	];

	public const COMMANDS_CLI = [
		'greeter:say' => [
			'command' => \Vendor\Package\Command\GreeterSayCommand::class,
			'description' => 'Print a greeting',
			'options' => [],
		],
	];
}

```

If your package has no CLI services, config, or commands, omit the CLI constants.

If HTTP and CLI maps are intentionally identical, aliasing is fine:

```php
public const MAP_CLI = self::MAP_HTTP;
public const CFG_CLI = self::CFG_HTTP;
```

Do not define obsolete constants merely for symmetry.

---

## 9) Boot lifecycle in HTTP mode

A standard front controller looks like this:

```php
<?php
declare(strict_types=1);

define('CITOMNI_START_NS', hrtime(true));
define('CITOMNI_ENVIRONMENT', 'dev');
define('CITOMNI_PUBLIC_PATH', __DIR__);
define('CITOMNI_APP_PATH', \dirname(__DIR__));

if (\defined('CITOMNI_ENVIRONMENT') && \CITOMNI_ENVIRONMENT !== 'dev') {
	define('CITOMNI_PUBLIC_ROOT_URL', 'https://www.example.com');
}

require __DIR__ . '/../vendor/autoload.php';

\CitOmni\Http\Kernel::run(__DIR__);
```

HTTP boot flow:

```text
public/index.php
  -> \CitOmni\Http\Kernel::run(__DIR__)
  -> Kernel::boot($entryPath)
  -> Kernel::resolvePaths($entryPath)
  -> new App($configDir, Mode::HTTP)
  -> $app->errorHandler->install()
  -> Runtime::configure($app->cfg)
  -> Kernel::definePublicRootUrl($app)
  -> $app->request->setTrustedProxies(...)
  -> $app->maintenance->guard()
  -> $app->router->run()
```

`App` construction loads or builds:

```text
cfg
routes or commands
services
```

For HTTP:

```php
$this->cfg = new Cfg($this->loadCacheArray($paths['cfg']) ?? $this->buildConfig());
$this->routes = $dispatchArray ?? $this->buildRoutes();
$this->commands = [];
$this->services = $this->loadCacheArray($paths['services']) ?? $this->buildServices();
```

The router consumes:

```php
$this->app->routes
```

It does not read routes from config.

---

## 10) Service lifecycle

A service is not created during map building.

It is created when first accessed:

```php
$this->app->greeter
```

Resolver behavior:

```php
public function __get(string $id): object {
	if (isset($this->instances[$id])) {
		return $this->instances[$id];
	}

	if (!isset($this->services[$id])) {
		throw new \RuntimeException("Unknown app component: app->{$id}");
	}

	$def = $this->services[$id];

	if (\is_string($def)) {
		$class = $def;
		$instance = new $class($this);
	} elseif (\is_array($def) && isset($def['class']) && \is_string($def['class'])) {
		$class = $def['class'];
		$options = $def['options'] ?? [];
		$instance = new $class($this, $options);
	} else {
		throw new \RuntimeException("Invalid service definition for '{$id}'");
	}

	return $this->instances[$id] = $instance;
}
```

Consequences:

* Unknown service IDs fail fast.
* Malformed definitions fail fast.
* Services are instantiated lazily.
* Instances are cached per request/process.
* Constructors should stay cheap.
* Expensive work should be explicit, not hidden in `init()`.

---

## 11) Authoring a Service

Example service:

```php
<?php
declare(strict_types=1);
/*
 * This file is part of the CitOmni framework.
 * Low overhead, high performance, ready for anything.
 *
 * For more information, visit https://github.com/citomni
 *
 * Copyright (c) 2012-present Lars Grove Mortensen
 * SPDX-License-Identifier: MIT
 *
 * For full copyright, trademark, and license information,
 * please see the LICENSE file distributed with this source code.
 */

namespace Vendor\Package\Service;

use CitOmni\Kernel\Service\BaseService;

/**
 * Greeter: Build deterministic greeting strings.
 *
 * Provides a tiny greeting API used by examples and smoke tests.
 *
 * Behavior:
 * - Reads construction-time options from the service map.
 * - Falls back to cfg when no option is supplied.
 * - Validates public input strictly.
 *
 * Notes:
 * - No I/O.
 * - No global state.
 * - Designed to be boring, which is a feature.
 *
 * Typical usage:
 *   $message = $this->app->greeter->greet('Alice');
 *
 * @throws \InvalidArgumentException When the name is empty.
 */
final class Greeter extends BaseService {
	private string $suffix = '';

	/**
	 * Initialize cheap immutable state.
	 *
	 * Behavior:
	 * - Options override cfg.
	 * - The package registry owns the greeter cfg baseline.
	 *
	 * @return void
	 */
	protected function init(): void {
		$cfgSuffix = (string)($this->app->cfg->greeter->default_suffix ?? '');
		$optSuffix = (string)($this->options['suffix'] ?? '');

		$this->suffix = $optSuffix !== '' ? $optSuffix : $cfgSuffix;
	}

	/**
	 * Build a greeting.
	 *
	 * @param string $name Non-empty display name.
	 * @return string Greeting string.
	 * @throws \InvalidArgumentException When the name is empty.
	 */
	public function greet(string $name): string {
		$name = \trim($name);

		if ($name === '') {
			throw new \InvalidArgumentException('Name cannot be empty.');
		}

		return 'Hello, ' . $name . ($this->suffix !== '' ? ' ' . $this->suffix : '');
	}
}
```

NOTE: Use the correct license header for your package namespace. CitOmni-owned examples use the standard MIT CitOmni header.

Register it:

```php
<?php
declare(strict_types=1);

return [
	'greeter' => [
		'class' => \Vendor\Package\Service\Greeter::class,
		'options' => [
			'suffix' => '- from services.php',
		],
	],
];
```

Use it:

```php
$message = $this->app->greeter->greet('World');
```

---

## 12) Service skeleton

```php
<?php
declare(strict_types=1);

namespace Vendor\Package\Service;

use CitOmni\Kernel\Service\BaseService;

/**
 * ServiceName: One-line responsibility summary.
 *
 * Optional longer description of intent, constraints, and performance assumptions.
 *
 * Behavior:
 * - Key guarantee or step.
 * - Another guarantee.
 *
 * Notes:
 * - Caveats, determinism, and performance notes.
 *
 * Typical usage:
 *   $result = $this->app->serviceId->methodName('value');
 */
final class ServiceName extends BaseService {
	private string $value = '';

	/**
	 * Initialize cheap immutable state.
	 *
	 * Behavior:
	 * - Read construction-time options.
	 * - Read cfg only when needed.
	 * - Validate derived values once.
	 *
	 * @return void
	 */
	protected function init(): void {
		// For package-owned cfg, ship the baseline in Registry::CFG_HTTP / CFG_CLI.
		$cfgValue = (string)($this->app->cfg->service_name->value ?? '');

		// For cross-package optional cfg, guard the intermediate node first:
		// if (isset($this->app->cfg->optional_package)) {
		// 	$cfgValue = (string)($this->app->cfg->optional_package->feature->value ?? $cfgValue);
		// }

		$optValue = (string)($this->options['value'] ?? '');

		$this->value = $optValue !== '' ? $optValue : $cfgValue;
	}

	/**
	 * Method summary.
	 *
	 * @param string $input Input description.
	 * @return string Result description.
	 * @throws \InvalidArgumentException When input is invalid.
	 */
	public function methodName(string $input): string {
		$input = \trim($input);

		if ($input === '') {
			throw new \InvalidArgumentException('Input cannot be empty.');
		}

		return $input . $this->value;
	}
}
```

---

## 13) Reading full cfg nodes

`$this->app->cfg` returns nested `Cfg` objects for associative arrays.

For scalar reads, use direct property access:

```php
$charset = (string)($this->app->cfg->locale->charset ?? 'UTF-8');
````

When you need to merge a whole package-owned cfg node with options, convert the node first:

```php
$raw = $this->app->cfg->greeter;
$cfgNode = ($raw instanceof \CitOmni\Kernel\Cfg) ? $raw->toArray() : (\is_array($raw) ? $raw : []);

$options = \CitOmni\Kernel\Arr::mergeAssocLastWins($cfgNode, $this->options);
```

This assumes that `greeter` is owned by the current package and guaranteed by its `Registry::CFG_HTTP` / `Registry::CFG_CLI` baseline.

For cross-package optional cfg, guard the intermediate node first:

```php
$cfgNode = [];

if (isset($this->app->cfg->commerce)) {
	$raw = $this->app->cfg->commerce;
	$cfgNode = ($raw instanceof \CitOmni\Kernel\Cfg) ? $raw->toArray() : (\is_array($raw) ? $raw : []);
}
```

This pattern is for optional cfg contributed by another package, where the current package does not own the baseline.

Use whole-node conversion only when you need whole-node merging. For one or two scalar values, direct reads are cheaper and clearer.

---

## 14) Services vs. Controllers, Commands, Operations, and Repositories

Services are framework/application facilities resolved through the service map.

Hard rule:

- Every class in `src/Service/` must extend `CitOmni\Kernel\Service\BaseService`.
- Every class in `src/Service/` must be registered in the relevant service map.
- App-aware classes that are not service-map singletons belong in `src/Support/`, `src/Policy/`, `src/State/`, or another fitting layer — not `src/Service/`.

They are not the right place for every kind of logic.

### Controllers

Controllers are HTTP adapters.

They own:

* Input parsing.
* CSRF verification.
* Session handling.
* Redirects.
* HTTP response shaping.
* Template selection.

Controllers should not contain SQL or complex multi-step business logic.

### Commands

Commands are CLI adapters.

They own:

* Argument parsing.
* Option parsing.
* Terminal output.
* Exit codes.

Commands should not contain SQL or complex orchestration.

### Operations

Operations own transport-agnostic orchestration.

They are instantiated explicitly:

```php
$op = new \Vendor\Package\Operation\DoSomething($this->app);
```

Do not register Operations in the service map.

### Repositories

Repositories own persistence.

All SQL and datastore I/O belongs in Repositories.

### Services

Services are shared facilities.

Good examples:

* Router.
* Response.
* Request.
* Cookie.
* Session.
* Log.
* Mailer.
* Template engine.
* Formatter.
* Auth facade.
* Role facade.

Services should not contain SQL.

---

## 15) Kernel-controlled Services

Most Services are resolved by application code on demand.

A few infrastructure Services are invoked directly by the kernel or router flow:

```php
$app->errorHandler->install();
$app->maintenance->guard();
$app->router->run();
```

These still use the same constructor contract and service map semantics, but their call sites are special.

### ErrorHandler

Installed early during HTTP boot.

It must be defensive because it handles failures from the rest of the application.

### Maintenance

Runs before routing.

It may terminate the request early with a maintenance response.

### Router

Runs after maintenance.

It consumes `App::$routes`, resolves controller/action, and creates the controller instance.

Router does not read routes from `$this->app->cfg`.

---

## 16) Helper methods on App

`App` exposes helper methods for capability checks:

```php
$this->app->hasService('log');
$this->app->hasAnyService('log', 'audit');
$this->app->hasPackage('vendor/package');
$this->app->hasNamespace(\Vendor\Package::class);
```

Use `hasService()` before optional service usage:

```php
if ($this->app->hasService('log')) {
	$this->app->log->write('app.json', 'event', 'something_happened', []);
}
```

Do not use `hasService()` to hide required dependencies. If a Service is required for correct behavior, let missing registration fail fast.

---

## 17) Error handling philosophy

Services may throw SPL exceptions:

```php
throw new \InvalidArgumentException('Name cannot be empty.');
throw new \RuntimeException('Unable to write file.');
throw new \OutOfBoundsException('Unknown key.');
```

Default rule:

```text
Fail fast.
```

Avoid catch-all wrappers:

```php
try {
	// ...
} catch (\Throwable) {
	// Usually a design smell in regular Services.
}
```

Use `try/catch` only when:

* Failure is genuinely recoverable.
* The fallback is explicit.
* The fallback is documented.
* Swallowing the failure does not hide corruption or security issues.

Boot infrastructure such as `ErrorHandler` is a special case because it cannot rely on itself to handle all of its own failures.

---

## 18) Performance rules

Services sit close to hot paths. Keep them cheap.

Recommended:

* Resolve config once in `init()` when reused.
* Store derived scalar values.
* Avoid repeated whole-node `toArray()` calls.
* Avoid reflection.
* Avoid directory scans.
* Avoid runtime discovery.
* Avoid large object graphs.
* Keep public APIs narrow.
* Let expensive work happen in explicit methods, not constructors.
* Avoid hidden I/O in `init()` unless the service is explicitly an I/O service.

Good:

```php
protected function init(): void {
	$this->enabled = (bool)($this->app->cfg->feature->enabled ?? false);
}
```

Less good:

```php
protected function init(): void {
	$this->allFiles = \glob(CITOMNI_APP_PATH . '/**/*.php');
}
```

Boring code is fast code. Fast code is green code.

---

## 19) Cache behavior

`App` loads cache files when present.

Current cache paths:

```php
[
	'cfg' => CITOMNI_APP_PATH . '/var/cache/cfg.http.php',
	'dispatch' => CITOMNI_APP_PATH . '/var/cache/routes.http.php',
	'services' => CITOMNI_APP_PATH . '/var/cache/services.http.php',
]
```

For CLI:

```php
[
	'cfg' => CITOMNI_APP_PATH . '/var/cache/cfg.cli.php',
	'dispatch' => CITOMNI_APP_PATH . '/var/cache/commands.cli.php',
	'services' => CITOMNI_APP_PATH . '/var/cache/services.cli.php',
]
```

Warm cache through:

```php
$app->warmCache();
```

Warm cache writes:

* Config cache.
* Dispatch cache.
* Service map cache.

The service cache stores service definitions, not service instances.

Services are still instantiated lazily per request/process.

When deploying with aggressive OPcache settings, invalidate changed cache files or reset OPcache after deployment.

---

## 20) Testing a Service

Services can be tested with a real `App` and fixture config:

```php
public function testGreeter(): void {
	$app = new \CitOmni\Kernel\App(
		__DIR__ . '/../_fixtures/config',
		\CitOmni\Kernel\Mode::HTTP
	);

	$service = new \Vendor\Package\Service\Greeter($app, [
		'suffix' => '- from Test',
	]);

	$this->assertSame('Hello, Bob - from Test', $service->greet('Bob'));
}
```

This is one of the few places where direct construction is acceptable: You are testing the class contract itself.

For integration tests, prefer resolving through the app:

```php
$this->assertSame(
	'Hello, Bob - from Test',
	$app->greeter->greet('Bob')
);
```

Test guidance:

* Use minimal fixture config.
* Do not rely on route data unless the service explicitly needs routing.
* Prefer testing service behavior, not boot internals.
* Keep tests deterministic and file-system-light.

---

## 21) FAQ

### When should I use a provider registry?

Use a provider registry when the Service belongs to a reusable package.

Example:

```text
vendor/package/src/Boot/Registry.php
```

The provider should expose:

```php
public const MAP_HTTP = [...];
public const CFG_HTTP = [...];
public const ROUTES_HTTP = [...];
```

And CLI equivalents only when relevant:

```php
public const MAP_CLI = [...];
public const CFG_CLI = [...];
public const COMMANDS_CLI = [...];
```

### When should I use `/config/services.php`?

Use `/config/services.php` when:

* The Service is app-local.
* You need an app-specific override.
* You need local wiring without creating a provider package.

### Can I use service options for secrets?

Avoid it.

Prefer a secrets file under:

```text
/var/secrets
```

And keep `/var/secrets` out of VCS and deploy overwrites.

Service options are best for non-sensitive construction-time wiring.

### Can Services read routes?

Usually no.

HTTP routes live in:

```php
$this->app->routes
```

CLI commands live in:

```php
$this->app->commands
```

They are dispatch maps, not config.

If a Service truly needs route-related behavior, expose a narrow API for the specific need. Do not poke around in internal dispatch maps unless the Service is explicitly responsible for dispatch/routing.

### Should provider registries contain logic?

No.

Registries should be declarative:

* No I/O.
* No database calls.
* No environment branching.
* No service resolution.
* No request inspection.
* No filesystem scanning.

### Can a Service depend on another Service?

Yes, but keep it deliberate.

Example:

```php
if ($this->app->hasService('log')) {
	$this->app->log->write('events.json', 'greeter', 'greeted', [
		'name' => $name,
	]);
}
```

Avoid circular service dependencies. If `A` resolves `B` in `init()` and `B` resolves `A` in `init()`, you built a tiny dependency ouroboros. It will not be grateful.

---

## 22) Authoring checklist

* [ ] The class is `final` by default.
* [ ] The class extends `CitOmni\Kernel\Service\BaseService`.
* [ ] The namespace follows PSR-4.
* [ ] The service has a small, clear responsibility.
* [ ] The service has no SQL.
* [ ] The service does not own transport concerns.
* [ ] The constructor contract is respected.
* [ ] `init()` is cheap and deterministic.
* [ ] Package-owned cfg paths have baselines for all intermediate nodes.
* [ ] Cross-package optional cfg reads guard missing intermediate nodes with `isset()`.
* [ ] Options are used only for construction-time wiring.
* [ ] Public methods validate input.
* [ ] Public methods document return contracts and exceptions.
* [ ] Required failures fail fast.
* [ ] Recoverable failures have explicit fallbacks.
* [ ] No catch-all exception swallowing.
* [ ] No runtime reflection or directory scanning.
* [ ] Registered through provider `Registry::MAP_HTTP` / `MAP_CLI` or `/config/services.php`.
* [ ] Provider dispatch uses `ROUTES_HTTP` for HTTP routes and `COMMANDS_CLI` for CLI commands.
* [ ] Routes and commands are not placed inside cfg.
* [ ] Cache behavior is understood and deployment-safe.

---

### Closing note

Keep Services **small, explicit, and boring**. Boring code is fast code - and fast code is green code.
