# Runtime Modes - CitOmni Application Kernel (v1.0)
*A deterministic execution model for multi-context application lifecycles.*

---

**Document type:** Technical Architecture  
**Version:** 1.0  
**Applies to:** CitOmni ≥ 8.2  
**Audience:** Framework developers, provider authors, and integrators  
**Status:** Stable and foundational  
**Author:** CitOmni Core Team  
**Copyright:** © 2012-present CitOmni

---

## 1. Introduction

CitOmni's architecture distinguishes between *runtime modes* - discrete execution contexts that define how an application is bootstrapped, configured, and executed.
Each mode represents a self-contained environment with deterministic baseline configuration, service mapping, and lifecycle semantics.

At present, two modes exist and are considered exhaustive for the PHP ecosystem:

* **HTTP Mode:** For all web-facing, request/response workloads.
* **CLI Mode:** For command-line, daemon, job, and automation workloads.

This document describes the rationale, structure, and operational implications of the mode layer, as implemented in `citomni/kernel` and its two canonical mode packages: `citomni/http` and `citomni/cli`.

---

## 2. Conceptual Overview

### 2.1 Definition

A **runtime mode** (sometimes referred to as an *execution mode*) defines a top-level *delivery environment* in which a CitOmni application operates.
It establishes:

1. The **entry point** (e.g., `public/index.php` vs. `bin/console`),
2. The **baseline configuration** (vendor defaults),
3. The **service map** (core components), and
4. The **I/O semantics** (request-response vs. stdin-stdout).

The runtime mode is not merely a technical distinction; it is a foundational partitioning of the application universe.
Every CitOmni app exists in exactly one mode at a time.

### 2.2 Philosophical Intent

CitOmni pursues *deterministic simplicity*: predictable behavior, minimal indirection, and zero "magic" resolution.
The runtime mode layer embodies that philosophy by defining *hard boundaries* between operational domains.

Rather than letting arbitrary "contexts" evolve dynamically, CitOmni anchors them to two static, compile-time constants:

```php
\CitOmni\Kernel\Mode::HTTP
\CitOmni\Kernel\Mode::CLI
```

These are not interchangeable; each boot pipeline, configuration tree, and service map is mode-specific.

---

## 3. Structural Overview

### 3.1 Mode Enum

```php
enum \CitOmni\Kernel\Mode: string {
	case HTTP = 'http';
	case CLI  = 'cli';
}
```

The `Mode` enum ensures strict typing throughout the kernel.
It is passed into the `App` constructor and dictates the resolution of baseline configuration and service mapping.

```php
$app = new \CitOmni\Kernel\App($configDir, \CitOmni\Kernel\Mode::HTTP);
```

### 3.2 Baseline Ownership

Each mode has a **baseline package** that owns and defines its initial state:

| Mode | Baseline Package | Baseline Constants                                                                 | Description                                 |
| ---- | ---------------- | ----------------------------------------------------------------------------------- | ------------------------------------------- |
| HTTP | `citomni/http`   | `\CitOmni\Http\Boot\Config::CFG`, `\CitOmni\Http\Boot\Services::MAP`, `\CitOmni\Http\Boot\Routes::MAP_HTTP` | Default configuration, services, and routes for HTTP. |
| CLI  | `citomni/cli`    | `\CitOmni\Cli\Boot\Config::CFG`,  `\CitOmni\Cli\Boot\Services::MAP`,  `\CitOmni\Cli\Boot\Routes::MAP_CLI`  | Default configuration, services, and routes for CLI.  |


Baseline configuration is immutable vendor data - a static array exported as a constant.
It forms the root node for all further configuration merges.

---

## 4. Mode Boot Sequence

### 4.1 Deterministic Merge Pipeline

When an `App` instance is created, it deterministically constructs **three** layered structures:

* **Configuration tree** (`Cfg`) - merged associative arrays with "last-wins" semantics
* **Routes table** (`$app->routes`) - deterministic HTTP/CLI routing map
* **Service map** (`$app->services`) - associative IDs mapped to FQCNs

The merge pipeline is identical for both HTTP and CLI modes, differing only in baseline sources and constant names.

#### 4.1.1 Configuration Merge Order

| Layer | Source                                                                    | Purpose                                                    |
| ----- | ------------------------------------------------------------------------- | ---------------------------------------------------------- |
| (1)   | Vendor baseline (`citomni/http` or `citomni/cli`)                         | Core defaults, mode-specific.                              |
| (2)   | Providers (from `/config/providers.php`)                                  | Feature overlays; each may define `CFG_HTTP` or `CFG_CLI`. |
| (3)   | App base config (`/config/citomni_http_cfg.php` or `citomni_cli_cfg.php`) | Project-specific defaults.                                 |
| (4)   | Environment overlay (`citomni_http_cfg.{ENV}.php`)                        | Environment-specific modifications.                        |

Result: a deep associative array representing the merged, read-only runtime configuration, exposed as `$app->cfg`.

#### 4.1.2 Service Map Merge Order

| Layer | Source                                            | Constant(s)             |
| ----- | ------------------------------------------------- | ----------------------- |
| (1)   | Vendor baseline (`citomni/http` or `citomni/cli`) | `Boot\Services::MAP`    |
| (2)   | Providers                                         | `MAP_HTTP` or `MAP_CLI` |
| (3)   | Application overrides (`/config/services.php`)    | -                       |

Result: an associative array of service IDs resolved at runtime via `$app->__get()`.

#### 4.1.3 Route Map Merge Order

| Layer | Source | Constant(s) / File | Purpose |
|-------|---------|-------------------|----------|
| (1) | Vendor baseline | `\CitOmni\Http\Boot\Routes::MAP_HTTP` or `\CitOmni\Cli\Boot\Routes::MAP_CLI` | Core system routes |
| (2) | Providers | `ROUTES_HTTP` / `ROUTES_CLI` | Provider-level routes (from `/config/providers.php`) |
| (3) | Application base | `/config/citomni_{http|cli}_routes.php` | Project-specific routes |
| (4) | Environment overlay | `/config/citomni_{http|cli}_routes.{ENV}.php` | Environment-specific overrides |

The merge follows the global *last-wins* rule and is implemented in  
`App::buildRoutes()`.  
The resulting array is cached under  
`/var/cache/routes.{http|cli}.php` for zero-I/O runtime lookups.


### 4.2 Mode-specific Constants

CitOmni uses *constant arrays* for configuration and service definitions rather than runtime evaluation.
This design eliminates boot-time code execution and achieves **zero I/O**, **zero reflection**, and **O(1)** merge determinism.

Providers must therefore expose static constants:

```php
public const CFG_HTTP = [ /* overlay config */ ];
public const CFG_CLI  = [ /* overlay config */ ];

public const MAP_HTTP = [ /* service ids -> classes */ ];
public const MAP_CLI  = [ /* service ids -> classes */ ];

public const ROUTES_HTTP = [ /* route definitions */ ];
public const ROUTES_CLI  = [ /* CLI routing (if used) */ ];
````

These are read directly by `App::buildConfig()`, `App::buildRoutes()`, and `App::buildServices()`.

---

## 5. Why Only Two Modes?

### 5.1 Theoretical Completeness

PHP applications fundamentally execute in one of two paradigms:

| Paradigm         | Mode | Primary I/O model                                          |
| ---------------- | ---- | ---------------------------------------------------------- |
| Request-Response | HTTP | Environment variables, streams, $_SERVER, $_POST, headers. |
| Command-Stream   | CLI  | STDIN/STDOUT, argv, exit codes.                            |

All conceivable use cases (APIs, websites, daemons, workers, cronjobs, queues, serverless functions, etc.) fit naturally into one of these.

### 5.2 Exhaustiveness Justification

#### a) **HTTP mode covers:**

* Traditional web servers (Apache, Nginx, Caddy)
* FastCGI (PHP-FPM)
* REST, GraphQL, gRPC (over HTTP/2)
* Serverless environments (Lambda, Cloud Functions)
* Long-polling, WebSocket upgrades
* Reverse proxies and application gateways

#### b) **CLI mode covers:**

* Maintenance commands
* Scheduled tasks (cron)
* Build tools
* Import/export utilities
* Queue consumers and daemons
* Deployment orchestration
* CI/CD runners

Together, they exhaust PHP's operational universe.

### 5.3 Why Not Add a Third Mode?

Creating additional modes would violate CitOmni's **deterministic minimalism** and yield negligible functional gain.

A hypothetical "third mode" would require:

1. A new kind of entrypoint (not web, not shell),
2. A non-HTTP, non-CLI I/O model,
3. Distinct configuration semantics not expressible through providers.

Such an environment does not exist for PHP.
All other workloads can be represented as either:

* A **provider overlay** inside existing modes, or
* A **sub-system** (e.g., `citomni/queue`, `citomni/worker`) built on top of CLI.

### 5.4 Empirical Rationale

Empirically, PHP's entire ecosystem - from Laravel, Symfony, and Slim to custom CMSes - converges on the same dichotomy: *HTTP and CLI*.
CitOmni formalizes that dichotomy as a first-class construct rather than an afterthought.

---

## 6. Composition of a Mode Package

Every runtime mode in CitOmni (HTTP or CLI) is represented by a **mode package**.  
A mode package provides the baseline definitions that establish its configuration, routing, and service topology.  
Together, these constants form the immutable foundation upon which all provider overlays and application-level overrides are merged.

### 6.1 Mandatory Baseline Files

| File                    | Purpose                                                                 |
| ----------------------- | ----------------------------------------------------------------------- |
| `src/Boot/Config.php`   | Defines the baseline configuration constant `CFG`.                      |
| `src/Boot/Services.php` | Defines the baseline service map constant `MAP`.                        |
| `src/Boot/Routes.php`   | Defines the baseline route map (`MAP_HTTP` for HTTP, `MAP_CLI` for CLI). |

Each of these files must expose a single **static constant** returning a normalized PHP array.  
The kernel never executes code within these classes-only reads the constant values at boot time.

Example (simplified from `citomni/http`):

```php
namespace CitOmni\Http\Boot;

final class Config {
	public const CFG = [
		'identity' => [
			'package' => 'citomni/http',
			'mode'    => 'http',
		],
		'http' => [
			'base_url' => '',
			// ...
		],
	];
}

final class Services {
	public const MAP = [
		'router'   => \CitOmni\Http\Router::class,
		'request'  => \CitOmni\Http\Request::class,
		'response' => \CitOmni\Http\Response::class,
		'errorHandler' => \CitOmni\Http\Service\ErrorHandler::class,
		// ...
	];
}

final class Routes {
	public const MAP_HTTP = [
		'/maintenance.html' => [
			'controller'    => \CitOmni\Http\Controller\MaintenanceController::class,
			'action'        => 'view',
			'methods'       => ['GET'],
			'template_file' => 'public/maintenance.html',
			'template_layer'=> 'citomni/http',
		],
	];
}
````

These baseline maps together define the **minimum viable runtime** for the given mode.
All further additions-providers, extensions, or applications-layer deterministically on top of them.

---

### 6.2 Provider-Level Contributions

Providers do **not** own `Boot/Config.php`, `Boot/Services.php`, or `Boot/Routes.php`.
Instead, they contribute overlays via a lightweight composite class:

| File                    | Purpose                                                                                |
| ----------------------- | -------------------------------------------------------------------------------------- |
| `src/Boot/Registry.php` | Exposes provider-specific overlays for configuration, services, and routes (optional). |

A provider's `Registry` may define any combination of these constants:

| Constant                     | Scope    | Purpose                                |
| ---------------------------- | -------- | -------------------------------------- |
| `CFG_HTTP` / `CFG_CLI`       | Config   | Adds or overrides configuration keys.  |
| `MAP_HTTP` / `MAP_CLI`       | Services | Adds or overrides service definitions. |
| `ROUTES_HTTP` / `ROUTES_CLI` | Routes   | Adds or overrides routing entries.     |

Example (simplified provider overlay):

```php
namespace CitOmni\Auth\Boot;

final class Registry {
	public const CFG_HTTP = [
		'auth' => [
			'twofactor' => true,
			'max_attempts' => 5,
		],
	];

	public const MAP_HTTP = [
		'auth' => \CitOmni\Auth\Service\AuthService::class,
	];

	public const ROUTES_HTTP = [
		'/login.html' => [
			'controller'    => \CitOmni\Auth\Controller\AuthController::class,
			'action'        => 'login',
			'methods'       => ['GET'],
			'template_file' => 'public/login.html',
			'template_layer'=> 'citomni/auth',
		],
		'/login' => [
			'controller' => \CitOmni\Auth\Controller\AuthController::class,
			'action'     => 'loginPost',
			'methods'    => ['POST'],
		],
	];
}
```

Providers are listed explicitly in the app-layer's `/config/providers.php`.
During application boot, the kernel sequentially reads each listed class, merging their constants as follows:

1. `CFG_HTTP` / `CFG_CLI` -> merged into configuration tree.
2. `ROUTES_HTTP` / `ROUTES_CLI` -> merged into route table.
3. `MAP_HTTP` / `MAP_CLI` -> merged into service map.

This deterministic sequence ensures that provider order in `/config/providers.php` defines overlay precedence (last listed wins):
- **Configuration & routes:** *last-wins* (later providers overwrite earlier entries).
- **Services:** *left-wins per merge step*; the kernel unions maps as `$map = $pvMap + $map;`, so **later providers** (iterated later, placed on the left) override earlier ones; the **application** wins last via `$map = $appMap + $map;`.

---

### 6.3 Kernel Integration

At boot, the `App` kernel performs three independent merges:

| Builder Method    | Reads (in order)                                                                                               | Merge rule                                   | Writes Cache To                     |
|-------------------|----------------------------------------------------------------------------------------------------------------|----------------------------------------------|-------------------------------------|
| `buildConfig()`   | `Boot/Config::CFG` -> providers `CFG_{HTTP|CLI}` -> app base cfg -> env overlay                                   | **last-wins** (deep associative)             | `var/cache/cfg.{http|cli}.php`      |
| `buildRoutes()`   | `Boot/Routes::MAP_{HTTP|CLI}` -> providers `ROUTES_{HTTP|CLI}` -> app base routes -> env overlay                  | **last-wins** (by path key)                  | `var/cache/routes.{http|cli}.php`   |
| `buildServices()` | `Boot/Services::MAP` -> providers `MAP_{HTTP|CLI}` -> app `/config/services.php`                                 | **left-wins per step** via union (`+`)       | `var/cache/services.{http|cli}.php` |

> For services the kernel applies `$map = $pvMap + $map;` per provider and `$map = $appMap + $map;` last.  
> That makes **later providers override earlier ones**, and the **application** override both.

---

### 6.4 Architectural Rationale

This tri-part composition (`Config`, `Routes`, `Services`) achieves:

* **Full determinism:** all boot data known at compile-time.
* **Mode isolation:** HTTP and CLI remain strictly separated.
* **Zero boot overhead:** constants only-no functions, constructors, or filesystem reads.
* **Extensibility:** providers can safely extend without mutating vendor baselines.
* **Cache atomicity:** each map (`cfg`, `routes`, `services`) is compiled independently by `App::warmCache()`.

---

**In summary:**

> Each mode package defines its immutable *baseline world* through three constants-`CFG`, `MAP`, and `MAP_HTTP|CLI`.
> Providers extend it through `Boot/Registry.php`, and the kernel fuses everything into atomic caches for ultra-fast, deterministic startup.

---

## 7. Integration with Kernel

The `App` class in `citomni/kernel` centralizes all mode awareness.
It is the single orchestrator that binds mode type to boot sequence.

### 7.1 Mode Resolution in `App::buildConfig()`

```php
$base = match ($mode) {
	Mode::HTTP => \CitOmni\Http\Boot\Config::CFG,
	Mode::CLI  => \CitOmni\Cli\Boot\Config::CFG,
};
```

### 7.2 Provider Overlay Resolution

```php
$constName = ($mode === Mode::HTTP) ? 'CFG_HTTP' : 'CFG_CLI';
$constFq = $fqcn . '::' . $constName;
if (\defined($constFq)) {
	$pv = \constant($constFq);
	$cfg = Arr::mergeAssocLastWins($cfg, Arr::normalizeConfig($pv));
}
```

The same logic applies for `MAP_HTTP`/`MAP_CLI` in `buildServices()`.

Thus, `App` functions as a **deterministic, side-effect-free merger** - reading only constants, never executing arbitrary code.

---

## 8. Design Principles

### 8.1 Determinism

All mode initialization is pure and reproducible.
The same inputs (mode, providers, environment) always yield the same configuration and service graph.

### 8.2 Zero-Execution Boot

No function calls, file I/O, or reflection during merge.
Only constant resolution and static array normalization.

### 8.3 Explicit Boundaries

Modes define the outermost boundary of an app.
Providers extend; they never redefine mode semantics.

### 8.4 Minimalism

Two modes are sufficient; adding more would only increase entropy.

### 8.5 Performance

Mode segregation allows for **cache pre-warming** of all deterministic build artifacts.

```

var/cache/cfg.http.php
var/cache/routes.http.php
var/cache/services.http.php

var/cache/cfg.cli.php
var/cache/routes.cli.php
var/cache/services.cli.php

```

Each file represents a fully merged, side-effect-free PHP array returned by  
`App::buildConfig()`, `App::buildRoutes()`, and `App::buildServices()` respectively.

All cache files are:

* **Atomic** - written via temporary file + rename (no partial writes)  
* **Pre-exported** - pure `return [ ... ];` structures, no runtime code  
* **OPcache-friendly** - safe to precompile with `validate_timestamps=0`  
* **Deterministic** - identical inputs always yield identical output  
* **Independent** - config, routes, and services can be warmed or invalidated separately

When these caches are present, the application performs **zero filesystem I/O** during boot or routing.  
Startup time typically drops below one millisecond with memory peaks under a few hundred kilobytes.

---

## 9. Practical Implications

| Concern            | Impact of Mode Layer                                                                                                                       |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------ |
| **Autoloading**    | Mode packages provide PSR-4 namespaces under `CitOmni\Http\` and `CitOmni\Cli\`.                                                           |
| **Service Lookup** | `$this->app->id` resolution depends on the mode's service map.                                                                             |
| **Error Handling** | HTTP and CLI each define their own `ErrorHandler` implementations.                                                                         |
| **Testing**        | Unit tests can bootstrap a lightweight mock of either mode for isolated testing.                                                           |
| **Deployments**    | Cache warming (`App::warmCache()`) is executed per mode to ensure separation.                                                              |
| **Routing**        | Each mode maintains its own compiled route cache (`var/cache/routes.{http or cli}.php`), merged deterministically by `App::buildRoutes()`. |
| **Boot constants** | Each mode's vendor baseline explicitly includes `Boot/Routes` for first-party routes.                                                      | 

---

## 10. Future Directions

No additional runtime modes are planned or expected.
Future expansion will occur within existing modes, via providers or sub-systems.

Potential evolutions include:

* Specialized **HTTP adapters** (e.g., for Swoole or RoadRunner), still under HTTP mode.
* **CLI workers** and **asynchronous daemons**, still under CLI mode.
* Shared **runtime metrics** services across both modes.

Each is an extension *inside* a mode, not a new one.

---

## 11. Summary

| Aspect              | HTTP Mode                                                                 | CLI Mode                                                             |
| ------------------- | ------------------------------------------------------------------------- | -------------------------------------------------------------------- |
| Entry point         | `public/index.php`                                                        | `bin/console`                                                        |
| Baseline package    | `citomni/http`                                                            | `citomni/cli`                                                        |
| Boot constants      | `Boot\Config::CFG`, `Boot\Services::MAP`, `Boot\Routes::MAP_HTTP`         | `Boot\Config::CFG`, `Boot\Services::MAP`, `Boot\Routes::MAP_CLI`     |
| I/O model           | Request-Response (HTTP/FastCGI)                                           | Stream (stdin/stdout)                                                |
| Common overlays     | Providers (`CFG_HTTP`, `MAP_HTTP`, `ROUTES_HTTP`)                         | Providers (`CFG_CLI`, `MAP_CLI`, `ROUTES_CLI`)                       |
| Configuration cache | `var/cache/cfg.http.php`                                                  | `var/cache/cfg.cli.php`                                              |
| Routes cache        | `var/cache/routes.http.php`                                               | `var/cache/routes.cli.php`                                           |
| Service cache       | `var/cache/services.http.php`                                             | `var/cache/services.cli.php`                                         |

CitOmni's runtime/execution mode layer thus establishes a minimal yet complete framework for deterministic, high-performance PHP applications.
Its binary division (HTTP / CLI) is deliberate, sufficient, and theoretically closed under all current and foreseeable PHP workloads.

---

**In essence:**

> *Two modes, one philosophy: explicit, deterministic, fast.*
> CitOmni does not guess. It knows.
