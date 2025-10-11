---
title: CitOmni Provider Packages (Design, Semantics, and Best Practices)
author: Lars Grove Mortensen
author_url: https://github.com/LarsGMortensen
version: 1.0
date: 2025-10-09
status: Stable
package: citomni/*
---

# CitOmni Provider Packages  
> **Version:** 1.0  
> **Audience:** Framework contributors, core developers, and provider authors  
> **Scope:** Provider package model across the CitOmni ecosystem (applies to `citomni/*` and third-party providers)  
> **Language level:** PHP ≥ 8.2  
> **Status:** Stable and foundational  
> **Author:** [Lars Grove Mortensen](https://github.com/LarsGMortensen)

---

## 1. Introduction

**Providers** are the modular extension units of CitOmni. They contribute *capabilities* (services, routes, configuration overlays) to an application without incurring runtime "magic," reflection, or I/O during boot. Providers are declarative and composition-friendly: they expose **constant arrays** that the kernel reads and merges deterministically.

CitOmni's core distinguishes two **runtime modes** - HTTP and CLI. Providers may target either or both, contributing:
- **Service maps** (IDs -> classes) for dependency resolution
- **Configuration overlays** (deep, associative, last-wins)
- **Routes** (for HTTP providers), contributed as part of the configuration overlay

Providers intentionally **do not** own baseline configuration; baseline is owned only by the mode packages `citomni/http` and `citomni/cli`. Providers *overlay* that baseline.

---

## 2. Goals and Non-Goals

### 2.1 Goals
- **Deterministic boot:** merge constant arrays; zero code execution during merge.
- **Low overhead:** no filesystem probing, no DI containers, no reflection, no autowiring.
- **Predictable precedence:** vendor baseline -> providers -> app base -> app environment overlay.
- **Minimal surface:** a single class `Boot\Services` with four constants is sufficient for most providers.

### 2.2 Non-Goals
- Providers do **not** define new runtime modes.
- Providers do **not** own global boot sequences.
- Providers should not introduce hidden side effects at require-time.

---

## 3. How Providers Integrate

The kernel (`citomni/kernel`) composes configuration and services via a deterministic pipeline.

> See `App::buildConfig()` and `App::buildServices()` in `citomni/kernel` for exact resolution logic.

### 3.1 Configuration Merge (deep, last-wins)

Order:
1. **Mode baseline**:  
   - HTTP -> `\CitOmni\Http\Boot\Config::CFG`  
   - CLI  -> `\CitOmni\Cli\Boot\Config::CFG`
2. **Providers list** (`/config/providers.php`) -> each provider may contribute `CFG_HTTP` or `CFG_CLI`
3. **App base** (`/config/citomni_{http|cli}_cfg.php`)
4. **App environment overlay** (`/config/citomni_{http|cli}_cfg.{ENV}.php`)

Mechanics:
- Associative arrays are merged recursively (**last-wins**).
- List arrays (PHP "lists") are **replaced** (not concatenated).
- Some keys (e.g., `routes`) are exposed raw to userland via the read-only wrapper but still follow normal merge semantics during build.

> **Note on `routes`:**  
> `routes` is exposed as a **raw array** by the read-only `Cfg` wrapper (no nested `Cfg` objects). This keeps route lookups simple and predictable while still honoring normal merge semantics during build.

### 3.2 Service Map Merge (ID map, left-wins)

Order:
1. **Mode baseline**: `Boot\Services::MAP`
2. **Providers**: `MAP_HTTP` / `MAP_CLI`  
   The kernel applies:  
   `$map = $pvmap + $map;`
   -> **Provider entries take precedence over baseline** for identical IDs.
3. **App overrides**: `/config/services.php`  
   The kernel applies:  
   `$map = $appMap + $map;`  
   -> **App entries take precedence over provider and baseline**.

**Implication:** service IDs are an intentional override surface; later layers *replace* earlier mappings for the same ID.

> **Why "left-wins" for services?**  
> Service IDs form an intentional override surface. Using PHP's `+` operator preserves the **left** entry on key conflict, which means:
> 1) Baseline -> provider: **provider** can replace a baseline service by reusing the same ID.  
> 2) Provider -> app: the **app** can replace either baseline or provider by reusing the same ID.  
> This yields a crisp, two-step override ladder without reflection or registries.
> (PHP's array union operator `+` preserves the **left** value on key conflicts.)

---

## 4. Provider Anatomy

A minimal provider exposes one class with constants:

```php
<?php
declare(strict_types=1);

namespace Vendor\Package\Boot;

final class Services {
	public const MAP_HTTP = [
		'foo' => \Vendor\Package\Service\Foo::class,
	];

	public const CFG_HTTP = [
		'foo' => [
			'enabled' => true,
			// provider defaults (associative; deep-merge friendly)
		],
		// Optionally: routes contributed as associative map
		'routes' => \Vendor\Package\Boot\Routes::MAP,
	];

	// CLI support (often mirrors HTTP unless diverging is needed)
	public const MAP_CLI = self::MAP_HTTP;
	public const CFG_CLI = [
		'foo' => ['enabled' => true],
	];
}
```

Optional `Routes` class:

```php
<?php
declare(strict_types=1);

namespace Vendor\Package\Boot;

final class Routes {
	public const MAP = [
		'/' => [
			'controller' => \Vendor\Package\Controller\HomeController::class,
			'methods'    => ['GET'],
			// additional route metadata...
		],
		'/login' => [
			'controller' => \Vendor\Package\Controller\AuthController::class,
			'methods'    => ['GET','POST'],
		],
	];
}
```

**Key rules:**

* **No `Boot/Config.php`** in providers. Baseline is reserved for `citomni/http` and `citomni/cli`.
* Use **constant arrays** only (`public const ...`).
* Make config **associative**; avoid lists unless you intend full replacement by downstream layers.
* For routes, prefer associative maps keyed by the path (e.g., `'/path' => [...]`) to allow granular deep merges.

### 4.1 Service Map Entries with Options (Shape and Example)

Service definitions MAY be either a bare FQCN (string) or a shape with per-instance options:

```php
public const MAP_HTTP = [
    // Bare class: constructor receives (App $app)
    'foo' => \Vendor\Package\Service\Foo::class,

    // With options: constructor receives (App $app, array $options)
    'bar' => [
        'class'   => \Vendor\Package\Service\Bar::class,
        'options' => [
            'cache_ttl' => 300,
            'endpoint'  => 'https://api.example.test',
        ],
    ],
];
```

**Constructor Contract (recap):**

```php
public function __construct(\CitOmni\Kernel\App $app, array $options = []);
```

**Guidelines:**

* Keep `options` strictly scalars/arrays (no objects).
* Options define **defaults** at the provider level; applications may override them via `services.php` or provider CFG keys.
* Avoid work in constructors; defer I/O until the first method call.
* Applications can override per-service `options` by redefining the **same service ID** in `/config/services.php` and providing a new `['options' => ...]` array.

**App override with options (`/config/services.php`):**

```php
<?php
return [
	// Replace provider's "bar" service and tweak options
	'bar' => [
		'class'   => \App\Service\Bar::class,
		'options' => [
			'cache_ttl' => 120, // was 300 in provider
			'endpoint'  => 'https://api.example.prod',
		],
	],
];
```

---

## 5. Service Construction Contract

CitOmni's kernel resolves services from the map and instantiates them lazily:

```php
// In \CitOmni\Kernel\App::__get():
if (is_string($def)) {
	$class = $def;
	$instance = new $class($this);
} elseif (is_array($def) && isset($def['class'])) {
	$class   = $def['class'];
	$options = $def['options'] ?? [];
	$instance = new $class($this, $options);
}
```

**Constructor contract for provider services:**

```php
public function __construct(\CitOmni\Kernel\App $app, array $options = []);
```

* **Do not** catch exceptions unless absolutely necessary; let the global handler log them.
* Side effects should be minimized; defer expensive work until first *use* (not at construction).

---

## 6. Registering Providers in an App

Applications opt-in providers via `/config/providers.php`:

```php
<?php
return [
	\CitOmni\Auth\Boot\Services::class,
	\CitOmni\Common\Boot\Services::class,
	\Vendor\Package\Boot\Services::class,
];
```

**Best practice:** keep this list short, explicit, and ordered according to your intent.
Remember: **Configuration is last-wins**, **service map precedence is left-wins** (for equal IDs) at each merge step.

> **Contract for `/config/providers.php`**  
> Must return an **array of FQCN strings** pointing to provider boot classes (usually `\Vendor\Package\Boot\Services::class`).  
> Each class **must exist** and may define any of: `CFG_HTTP`, `CFG_CLI`, `MAP_HTTP`, `MAP_CLI`.  
> Invalid entries (non-strings, missing classes) are **fail-fast** errors: The kernel throws a RuntimeException during boot.

---

## 7. Contributing Configuration

Providers contribute under namespaces they own to avoid collisions:

```php
public const CFG_HTTP = [
	'auth' => [
		'enabled' => true,
		'twofactor_protection' => true,
	],
	'routes' => \CitOmni\Auth\Boot\Routes::MAP,
];
```

**Recommendations:**

* Keep keys *namespaced* by provider (e.g., `auth`, `commerce`, `cms`) to prevent accidental merges across providers.
* Avoid embedding secrets. Providers may define *shape* and defaults; real secrets live in the **app layer** (e.g., env-specific overlays).
* For performance, prefer **scalars and arrays** only; no objects.

### 7.1 Naming Conventions for Config Keys and Service IDs

- **Top-level config keys**: short, provider-scoped nouns (e.g., `auth`, `cms`, `commerce`).  
  Avoid generic names (e.g., `core`, `common`) unless you *own* the concept.
- **Nested keys**: snake_case or lowerCamelCase consistently within a provider; do not mix.
- **Service IDs**: lowerCamelCase, stable across versions (treat as public API).  
  Examples: `auth`, `userAccount`, `imageOptimizer`.
- **Routes map**: keys are literal paths (`'/login'`), values are associative arrays.  
  For path-variants (locales, versions), prefer separate entries instead of computed keys.

---

## 8. Routes in Providers (HTTP Mode)

* Contribute routes via `CFG_HTTP['routes']`.
* Prefer **associative map keyed by path**:

  ```php
  '/account' => ['controller' => \Pkg\Account\Controller::class, ...]
  ```

> **Merging rule:** Route entries are merged by **path key**. Downstream layers (app base/env) can override a single route by re-declaring the same path key and adjusting controller or metadata.

  This enables deep merges and path-level overrides in app overlays.
* Avoid list-style routes (numeric keys) unless you intend for app overlays to replace the whole set.

> **Methods:** Use uppercase method names (e.g., ["GET","POST"]). Mixed case is discouraged.

### 8.1 Middleware, Guards, and Policies (Optional Structure)

Providers that offer HTTP cross-cutting features (auth guards, CSRF, rate-limits) should express them through configuration and documented service IDs, not by hidden hooks.

Example pattern:
```php
public const CFG_HTTP = [
    'auth' => [
        'guards' => [
            'default' => 'session',
            'api'     => 'token',
        ],
    ],
    'routes' => \Vendor\Package\Boot\Routes::MAP,
];
```

Applications decide **where** to apply guards (per route, per group) by overriding route metadata or by integrating with the host application's router policy layer.

---

## 9. Precedence and Overriding

### 9.1 Configuration (deep, last-wins)

* Baseline (mode) -> Provider(s) -> App base -> App env overlay
* A downstream layer can override any upstream scalar or associative subtree.
* For list arrays, downstream **replaces** upstream.

### 9.2 Service IDs (left-wins in each merge step)

* Provider IDs override baseline where identical.
* App `/config/services.php` overrides both baseline and providers.
* If you want users to be able to override your service easily, **document your IDs** and keep them stable (semantic versioning).

---

## 10. Discovery and Introspection (App helpers)

Providers benefit from kernel's helper methods (for conditional logic in controllers/services):

* `App::hasService(string $id): bool`
* `App::hasAnyService(string ...$ids): bool`
* `App::hasPackage(string $slug): bool`  *(maps FQCNs -> `vendor/package`)*
* `App::hasNamespace(string $prefix): bool`

These helpers are **zero-I/O** and derive from the **already merged** service map and configuration (and, for `hasPackage`, also routes' controllers).

---

## 11. Package Layout and Autoloading

A typical provider:

```
vendor/package
├─ composer.json
└─ src
   ├─ Boot
   │  ├─ Services.php          (REQUIRED)
   │  └─ Routes.php            (optional, HTTP only)
   ├─ Controller               (HTTP controllers)
   │  └─ *.php
   ├─ Command                  (CLI commands)
   │  └─ *.php
   ├─ Service
   │  └─ *.php
   ├─ Model
   │  └─ *.php
   └─ Exception
      └─ *.php
```

**composer.json (minimal template):**

```json
{
  "name": "vendor/package",
  "description": "CitOmni provider: Foo capabilities",
  "type": "library",
  "license": "GPL-3.0-or-later",
  "require": {
    "php": ">=8.2",
    "citomni/kernel": "^1.0"
  },
  "autoload": {
    "psr-4": {
      "Vendor\\Package\\": "src/"
    }
  }
}
```

**composer.json (extended - recommended for public/teams):**

```json
{
  "name": "vendor/package",
  "description": "CitOmni provider: Foo capabilities",
  "type": "library",
  "license": "GPL-3.0-or-later",
  "keywords": ["citomni", "provider", "php8.2"],
  "authors": [
    {
      "name": "Lars Grove Mortensen",
      "homepage": "https://github.com/LarsGMortensen"
    }
  ],
  "support": {
    "issues": "https://github.com/vendor/package/issues",
    "source": "https://github.com/vendor/package"
  },
  "require": {
    "php": ">=8.2",
    "citomni/kernel": "^1.0"
  },
  "require-dev": {
    "phpunit/phpunit": "^11.0",
    "phpstan/phpstan": "^1.11"
  },
  "autoload": {
    "psr-4": {
      "Vendor\\Package\\": "src/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "Vendor\\Package\\Tests\\": "tests/"
    }
  },
  "config": {
    "optimize-autoloader": true,
    "sort-packages": true
  },
  "scripts": {
    "test": "phpunit",
    "stan": "phpstan analyse --memory-limit=512M"
  }
}
```

**Namespaces (PSR-4):** map `Vendor\\Package\\` -> `src/` and use
`Vendor\Package\Controller\*`, `Vendor\Package\Command\*`, `Vendor\Package\Service\*`,
`Vendor\Package\Model\*`, `Vendor\Package\Exception\*`.

**Coding conventions (CitOmni standard):**
* PHP >= 8.2; PSR-4 autoload.
* Classes: PascalCase; methods/variables: camelCase; constants: UPPER_SNAKE_CASE.
* K&R brace style (opening brace on the same line).
* Tabs for indentation.
* All PHPDoc and inline comments in English.

### 11.1 Composer Dependencies (mode-aware)

Most providers should depend on `citomni/kernel` plus the mode package(s) they truly **require**:

- If the provider **requires HTTP runtime features** (e.g., controllers, routes), add `"citomni/http": "^1.0"`.
- If the provider **requires CLI runtime features** (e.g., commands), add `"citomni/cli": "^1.0"`.
- If the provider **requires both**, require **both** packages.
- If the provider is **runtime-agnostic** (pure services/models with no HTTP/CLI coupling), `"citomni/kernel"` alone is sufficient.
- Keep **only** the dependencies that are actually necessary (minimize surface and installation footprint).

**Examples**

HTTP-only:
```json
{
  "require": {
    "php": ">=8.2",
    "citomni/kernel": "^1.0",
    "citomni/http": "^1.0"
  }
}
```

CLI-only:

```json
{
  "require": {
    "php": ">=8.2",
    "citomni/kernel": "^1.0",
    "citomni/cli": "^1.0"
  }
}
```

Both modes:

```json
{
  "require": {
    "php": ">=8.2",
    "citomni/kernel": "^1.0",
    "citomni/http": "^1.0",
    "citomni/cli": "^1.0"
  }
}
```

Runtime-agnostic:

```json
{
  "require": {
    "php": ">=8.2",
    "citomni/kernel": "^1.0"
  }
}
```

**Tip:** For public packages, prefer a concise set of `"keywords"`, add `"support"` URLs, and enable:

```json
"config": {
  "optimize-autoloader": true,
  "sort-packages": true
}
```

Avoid `"minimum-stability"` unless you truly need pre-releases.

For production builds, consider running `composer dump-autoload -o` in your deploy pipeline to generate an optimized class map.

> **Namespaces (PSR-4):** map `Vendor\\Package\\` -> `src/` and use `Vendor\Package\Controller\*`, `Vendor\Package\Command\*`, `Vendor\Package\Service\*`, etc.

## 11.2 File-Scope Purity (No Side Effects)

Provider boot files **must not** perform work at file scope. The following are disallowed in `src/Boot/*`:

- `new` object constructions,
- I/O (filesystem, network, DB),
- environment inspection (`getenv`, `$_SERVER`, time-dependent code).

Declare **constants only**. This ensures zero-cost autoload and deterministic cache warming.

---

## 12. Performance Guidance

* **Constants, not code:** keep `Boot\Services` and `Boot\Routes` purely declarative.
* **Avoid heavy constructors:** defer external I/O (DB, HTTP calls) until needed.
* **Exploit caches:** apps may pre-warm `var/cache/cfg.{mode}.php` and `var/cache/services.{mode}.php`; your provider benefits automatically.
* **No global state:** use `$this->app` for runtime access; avoid static registries.
* **Warmed caches are ABI:** Treat `var/cache/cfg.{mode}.php` and `var/cache/services.{mode}.php` as build artifacts.  
  Providers should be compatible with stale-until-replaced semantics; do not rely on runtime mutation of config structures.

---

## 13. Error Handling and Security

* Do **not** catch broadly in provider services; allow global error handlers in HTTP/CLI to log appropriately.
* Never ship secrets. Providers declare option structures; the app supplies secrets via env overlays.
* Mask secrets when exposing diagnostics (app-level helpers may already do this).

---

## 14. Testing Providers

* **Unit tests:** instantiate service classes with a minimal `App` test harness (using a tiny config directory and no providers, or a synthetic provider list).
* **HTTP routes:** verify that route maps resolve to controllers, methods, and metadata as expected.
* **Service precedence:** add tests to ensure app overrides beat provider IDs when intended.
* **Matrix by mode:** test provider behavior under both HTTP and CLI (when applicable); a provider's CFG/MAP must not leak mode-specific keys into the other mode.
* **No side effects in requires:** add a test that simply `require`s your `Boot/Services.php` and asserts no output, no globals, and no function calls were made.

---

## 15. Versioning and Stability

* **Service IDs are API:** changing an ID is a breaking change.
* **Config keys are API:** renaming top-level keys or changing value shapes is a breaking change.
* Use **SemVer**; document deprecations explicitly and provide migration notes.
* Removing or renaming a public route path key is a breaking change; adding new routes is typically a minor change.

---

## 16. Anti-Patterns (Avoid)

* Runtime code in `Boot\Services`/`Boot\Routes`.
* Secret material or environment-specific values in provider constants.
* List-style routes when fine-grained path-level overrides are desirable.
* Constructors that perform network or filesystem I/O eagerly.
* Catch-all exception handlers that swallow errors.

---

## 17. Example: Authentication Provider (Illustrative)

```php
<?php
declare(strict_types=1);

namespace CitOmni\Auth\Boot;

final class Services {
	public const MAP_HTTP = [
		'auth'        => \CitOmni\Auth\Service\Auth::class,
		'userAccount' => \CitOmni\Auth\Model\UserAccountModel::class,
	];

	public const CFG_HTTP = [
		'auth' => [
			'twofactor_protection' => true,
			'session_key' => 'auth_user_id', // default; override in app env if needed
		],
		'routes' => \CitOmni\Auth\Boot\Routes::MAP,
	];

	public const MAP_CLI = self::MAP_HTTP;

	public const CFG_CLI = [
		'auth' => [
			'twofactor_protection' => true,
			'session_key' => 'auth_user_id',
		],
	];
}
```

Routes:

```php
<?php
declare(strict_types=1);

namespace CitOmni\Auth\Boot;

final class Routes {
	public const MAP = [
		'/login' => [
			'controller' => \CitOmni\Auth\Controller\LoginController::class,
			'methods'    => ['GET','POST'],
		],
		'/logout' => [
			'controller' => \CitOmni\Auth\Controller\LogoutController::class,
			'methods'    => ['POST'],
		],
	];
}
```

---

## 18. App-Side Overrides (Illustrative)

`/config/services.php`:

```php
<?php
return [
	'auth' => \App\Service\CustomAuthService::class, // overrides provider
];
```

`/config/citomni_http_cfg.prod.php`:

```php
<?php
return [
	'auth' => [
		'twofactor_protection' => false,  // prod policy override
		'session_key' => 'sess_uid',
	],
	'routes' => [
		'/login' => [
			'controller' => \App\Controller\LoginController::class, // override one route
		],
	],
];
```

---

## 19. Quick Checklist for Provider Authors

* [ ] PHP ≥ 8.2; PSR-4 autoload; classes in PascalCase; methods camelCase.
* [ ] `src/Boot/Services.php` with `MAP_HTTP`, `CFG_HTTP`, (and optionally `MAP_CLI`, `CFG_CLI`).
* [ ] Optional `src/Boot/Routes.php` with `Routes::MAP` (associative by path).
* [ ] No `Boot/Config.php` (reserved for mode baselines).
* [ ] Keep overlays associative; avoid lists unless full replacement is desired.
* [ ] Constructor signature: `__construct(App $app, array $options = [])`.
* [ ] No secrets in constants; document required keys for app overlays.
* [ ] Unit tests for service resolution and (if HTTP) route correctness.
* [ ] Document stable service IDs for overrideability.
* [ ] Changelog and SemVer for any public contract changes.

---

## 20. Deprecation Policy for Providers

- **Service IDs** and **top-level config keys** are public API. Renaming them is a **breaking change**.
- To deprecate behavior:
  1. Introduce the new key/ID in a minor release.
  2. Continue honoring the old key/ID, but emit a warning via the provider's service (at **first use**, not at boot).
  3. Document a migration path with explicit examples.
  4. Remove the old key/ID in the next major release.

---

## 21. Summary

Providers are **pure, declarative overlays** that enhance CitOmni applications with new capabilities while preserving determinism and performance. They:

* Contribute **service IDs** and **configuration** via constants,
* May contribute **routes** for HTTP,
* Are merged in a **predictable order** with clear precedence,
* Rely on a **minimal construction contract**,
* Avoid runtime side effects and hidden magic.

**One philosophy:** explicit, deterministic, fast.
**One pattern:** constants in, predictable behavior out.
