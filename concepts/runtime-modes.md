---
title: CitOmni Runtime / Execution Mode Layer
author: Lars Grove Mortensen
author_url: https://github.com/LarsGMortensen
version: 1.0
date: 2025-10-09
status: Stable
package: citomni/kernel
---

# CitOmni Runtime / Execution Mode Layer
> **Version:** 1.0  
> **Audience:** Framework contributors, core developers, and advanced integrators  
> **Scope:** citomni/kernel, citomni/http, citomni/cli  
> **Language level:** PHP ≥ 8.2  
> **Status:** Stable and foundational  
> **Author:** [Lars Grove Mortensen](https://github.com/LarsGMortensen)

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

| Mode | Baseline Package | Baseline Constant                | Description                                 |
| ---- | ---------------- | -------------------------------- | ------------------------------------------- |
| HTTP | `citomni/http`   | `\CitOmni\Http\Boot\Config::CFG` | Default configuration for all HTTP kernels. |
| CLI  | `citomni/cli`    | `\CitOmni\Cli\Boot\Config::CFG`  | Default configuration for all CLI kernels.  |

Baseline configuration is immutable vendor data - a static array exported as a constant.
It forms the root node for all further configuration merges.

---

## 4. Mode Boot Sequence

### 4.1 Deterministic Merge Pipeline

When an `App` instance is created, it deterministically constructs two layered structures:

* **Configuration tree** (`Cfg`): merged associative arrays with "last-wins" semantics.
* **Service map** (`$app->services`): associative IDs mapped to FQCNs.

The merge pipeline is identical for both HTTP and CLI modes, differing only in the baseline source and constant names.

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

### 4.2 Mode-specific Constants

CitOmni uses *constant arrays* for configuration and service definitions rather than runtime evaluation.
This design eliminates boot-time code execution and achieves **zero I/O**, **zero reflection**, and **O(1)** merge determinism.

Providers must therefore expose static constants:

```php
public const CFG_HTTP = [ /* overlay config */ ];
public const CFG_CLI  = [ /* overlay config */ ];

public const MAP_HTTP = [ /* service ids -> classes */ ];
public const MAP_CLI  = [ /* service ids -> classes */ ];
```

These are read directly by `App::buildConfig()` and `App::buildServices()`.

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

Each mode package (HTTP or CLI) must contain at least:

| File                    | Purpose                                        |
| ----------------------- | ---------------------------------------------- |
| `src/Boot/Config.php`   | Defines baseline configuration constant `CFG`. |
| `src/Boot/Services.php` | Defines baseline service map constant `MAP`.   |

Example - simplified from `citomni/http`:

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
		'router'  => \CitOmni\Http\Router::class,
		'request' => \CitOmni\Http\Request::class,
		// ...
	];
}
```

Providers, in contrast, **do not** own `Boot/Config.php`.
They contribute their overlays solely through `Boot/Services.php`:

```php
final class Services {
	public const MAP_HTTP = [
		'auth' => \CitOmni\Auth\Service\Auth::class,
	];
	public const CFG_HTTP = [
		'auth' => ['twofactor' => true],
	];
	public const MAP_CLI = self::MAP_HTTP;
	public const CFG_CLI = self::CFG_HTTP;
}
```

This keeps provider boot-time cost near zero and ensures that mode packages remain the only baseline owners.

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

Mode segregation allows for cache pre-warming:

```
var/cache/cfg.http.php
var/cache/services.http.php
var/cache/cfg.cli.php
var/cache/services.cli.php
```

Each file is atomic, pre-exported, and `opcache`-friendly.

---

## 9. Practical Implications

| Concern            | Impact of Mode Layer                                                             |
| ------------------ | -------------------------------------------------------------------------------- |
| **Autoloading**    | Mode packages provide PSR-4 namespaces under `CitOmni\Http\` and `CitOmni\Cli\`. |
| **Service Lookup** | `$this->app->id` resolution depends on the mode's service map.                   |
| **Error Handling** | HTTP and CLI each define their own `ErrorHandler` implementations.               |
| **Testing**        | Unit tests can bootstrap a lightweight mock of either mode for isolated testing. |
| **Deployments**    | Cache warming (`App::warmCache()`) is executed per mode to ensure separation.    |

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

| Aspect              | HTTP Mode                                | CLI Mode                         |
| ------------------- | ---------------------------------------- | -------------------------------- |
| Entry point         | `public/index.php`                       | `bin/console`                    |
| Baseline package    | `citomni/http`                           | `citomni/cli`                    |
| Boot constants      | `Boot\Config::CFG`, `Boot\Services::MAP` | Same                             |
| I/O model           | Request-Response (HTTP/FastCGI)          | Stream (stdin/stdout)            |
| Common overlays     | Providers (`CFG_HTTP`, `MAP_HTTP`)       | Providers (`CFG_CLI`, `MAP_CLI`) |
| Configuration cache | `var/cache/cfg.http.php`                 | `var/cache/cfg.cli.php`          |
| Service cache       | `var/cache/services.http.php`            | `var/cache/services.cli.php`     |

CitOmni's runtime/execution mode layer thus establishes a minimal yet complete framework for deterministic, high-performance PHP applications.
Its binary division (HTTP / CLI) is deliberate, sufficient, and theoretically closed under all current and foreseeable PHP workloads.

---

**In essence:**

> *Two modes, one philosophy: explicit, deterministic, fast.*
> CitOmni does not guess. It knows.

---
