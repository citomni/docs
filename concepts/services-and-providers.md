# CitOmni Provider Packages (Design, Semantics, and Best Practices)

*A declarative overlay model for deterministic composition.*

---

**Document type:** Technical Architecture
**Version:** 1.0
**Applies to:** CitOmni ≥ 8.2
**Audience:** Framework contributors, core developers, and provider authors
**Status:** Stable and foundational
**Author:** CitOmni Core Team
**Copyright:** © 2012-present CitOmni

---

---

## 1. Introduction

**Providers** are the modular extension units of CitOmni. They contribute *capabilities* (services, routes, configuration overlays) to an application without incurring runtime "magic," reflection, or I/O during boot. Providers are declarative and composition-friendly: they expose **constant arrays** that the kernel reads and merges deterministically.

CitOmni's core distinguishes two **runtime modes** - HTTP and CLI. Providers may target either or both, contributing:
- **Service maps** (IDs -> classes) for dependency resolution
- **Configuration overlays** (deep, associative, last-wins)
- **Routes** (HTTP/CLI), exposed via `ROUTES_{HTTP|CLI}` constants (read by `App::buildRoutes()`)

Providers intentionally **do not** own baseline configuration; baseline is owned only by the mode packages `citomni/http` and `citomni/cli`. Providers *overlay* that baseline.

---

## 2. Goals and Non-Goals

### 2.1 Goals
- **Deterministic boot:** merge constant arrays; zero code execution during merge.
- **Low overhead:** no filesystem probing, no DI containers, no reflection, no autowiring.
- **Predictable precedence:** vendor baseline -> providers -> app base -> app environment overlay.
- **Minimal surface:** a single class `Boot/Registry.php` with up to six constants is sufficient for most providers:
  `CFG_HTTP`, `CFG_CLI`, `MAP_HTTP`, `MAP_CLI`, `ROUTES_HTTP`, and `ROUTES_CLI`.
  
Each constant is read directly by one of the kernel's three deterministic builders:
`App::buildConfig()`, `App::buildRoutes()`, or `App::buildServices()`.


### 2.2 Non-Goals
- Providers do **not** define new runtime modes.
- Providers do **not** own global boot sequences.
- Providers should not introduce hidden side effects at require-time.

---

## 3. How Providers Integrate

The kernel (`citomni/kernel`) composes **configuration, routes, and services** via a deterministic pipeline.

> See `App::buildConfig()`, `App::buildRoutes()`, and `App::buildServices()` for exact resolution logic.

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


### 3.2 Service Map Merge (ID map, left-wins - deterministic override ladder)

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

> In short: *left-wins* for services, *last-wins* for configuration and routes.

**Why "left-wins" for services?**  
Service IDs form an intentional override surface. Using PHP's `+` operator preserves the **left** entry on key conflict, which means:
1) Baseline -> provider: **provider** can replace a baseline service by reusing the same ID.  
2) Provider -> app: the **app** can replace either baseline or provider by reusing the same ID.  
This yields a crisp, two-step override ladder without reflection or registries.
(PHP's array union operator `+` preserves the **left** value on key conflicts.)

---

## 4. Provider Anatomy

A minimal provider exposes one class with constants:

```php
namespace Vendor\Package\Boot;

final class Registry {
	public const MAP_HTTP = [
		'foo' => \Vendor\Package\Service\Foo::class,
	];

	public const CFG_HTTP = [
		'foo' => [
			'enabled' => true,
		],
	];

	public const ROUTES_HTTP = [
		'/' => [
			'controller' => \Vendor\Package\Controller\HomeController::class,
			'action'     => 'index',
			'methods'    => ['GET'],
		],
	];

	public const MAP_CLI = self::MAP_HTTP;

	public const CFG_CLI = [
		'foo' => ['enabled' => true],
	];

	public const ROUTES_CLI = [
		// define CLI command routes if your CLI layer uses them
	];
}
```

*(If you prefer a separate `Boot/Routes.php`, keep it, but reference its constant from `Registry::ROUTES_HTTP`.)*


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

### 4.2 Optional `Boot/Routes.php` holder

If you insist on doing so; providers may keep route definitions in a separate constant holder for clarity and reuse.
When present, reference it from `Boot/Registry` via `ROUTES_HTTP` / `ROUTES_CLI`.

```php
<?php
declare(strict_types=1);

namespace Vendor\Package\Boot;

final class Routes {
    /** @var array<string,array<string,mixed>> */
    public const MAP = [
        '/' => [
            'controller' => \Vendor\Package\Controller\HomeController::class,
            'action'     => 'index',
            'methods'    => ['GET'],
        ],
        '/login.html' => [
            'controller'    => \Vendor\Package\Controller\AuthController::class,
            'action'        => 'login',
            'methods'       => ['GET'],
            'template_file' => 'public/login.html',
            'template_layer'=> 'vendor/package',
        ],
        '/login' => [
            'controller' => \Vendor\Package\Controller\AuthController::class,
            'action'     => 'loginPost',
            'methods'    => ['POST'],
        ],
    ];
}
```

Reference from `Boot/Registry`:

```php
<?php
declare(strict_types=1);

namespace Vendor\Package\Boot;

final class Registry {
    public const MAP_HTTP = [
        'auth' => \Vendor\Package\Service\AuthService::class,
    ];

    public const CFG_HTTP = [
        'auth' => ['enabled' => true],
    ];

    // Bind the separate holder into the provider's route overlay
    public const ROUTES_HTTP = \Vendor\Package\Boot\Routes::MAP;

    // Mirror for CLI if applicable
    public const MAP_CLI    = self::MAP_HTTP;
    public const CFG_CLI    = ['auth' => ['enabled' => true]];
    // public const ROUTES_CLI = \Vendor\Package\Boot\CliRoutes::MAP; // optional
}
```

**Notes**

* Keep the `Routes::MAP` array **associative by path** (e.g., `'/login.html' => [...]`), not a numeric list; this preserves path-level *last-wins* overrides.
* Include `action` and `methods` explicitly; `.html`/`.json` suffix is part of the public contract.
* Important: If you don't insist on keeping a separate file, you can define `ROUTES_HTTP` directly as a constant array on `Registry` (this is the prefered best practice).


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

Applications opt-in providers declaratively via the app-layer's **`/config/providers.php`**.
This file returns an ordered list of provider **boot classes** (usually `\Vendor\Package\Boot\Registry::class`).

```php
<?php
return [
	\CitOmni\Auth\Boot\Registry::class,
	\CitOmni\Common\Boot\Registry::class,
	\Vendor\Package\Boot\Registry::class,
];
```

### 6.1 Contract

* **Type:** Must return an **array of FQCN strings** (no callables, no objects).
* **Existence:** Each class **must exist** (autoloadable) or boot fails fast.
* **Surface:** A provider *may* define any of these **constants** on its `Boot\Registry`:

  * `CFG_HTTP`, `CFG_CLI` - configuration overlays (deep, **last-wins**)
  * `ROUTES_HTTP`, `ROUTES_CLI` - route overlays (path-keyed, **last-wins**)
  * `MAP_HTTP`, `MAP_CLI` - service maps (ID-keyed, **left-wins per merge step**)
* **Errors:** Non-strings, missing classes, or malformed constants are **fail-fast** - the kernel throws a `RuntimeException` during boot.

### 6.2 Deterministic Builders and Caches

The kernel consumes provider constants through three **independent builders**, each producing an atomic cache:

| Builder                | Reads (by mode)                                                     | Merge rule                  | Cache file                             |
| ---------------------- | ------------------------------------------------------------------- | --------------------------- | -------------------------------------- |
| `App::buildConfig()`   | Baseline `Boot\Config::CFG` + providers `CFG_*` + app overlays      | **last-wins** (deep)        | `var/cache/cfg.{http or cli}.php`      |
| `App::buildRoutes()`   | Baseline `Boot\Routes::MAP_*` + providers `ROUTES_*` + app overlays | **last-wins** (by path key) | `var/cache/routes.{http or cli}.php`   |
| `App::buildServices()` | Baseline `Boot\Services::MAP` + providers `MAP_*` + app overrides   | **left-wins** (per step)    | `var/cache/services.{http or cli}.php` |

Each builder operates independently and writes its own atomic cache file
(`cfg`, `routes`, or `services`), ensuring side-effect-free startup.

> **Rule of thumb:**
> **Configuration & routes** use **last-wins** (later overlays overwrite earlier keys).
> **Services** use **left-wins within each merge step** (the left-hand map keeps its value on key conflict), yielding a clean override ladder:
>
> 1. Provider can override **baseline** by reusing the same service ID;
> 2. The **app** can override both by redefining the same ID in `/config/services.php`.

### 6.3 Provider Order and Precedence

The **order** in `/config/providers.php` defines overlay precedence **among providers**:

* For **configuration/routes** (last-wins): Entries from **later providers** overwrite earlier providers for the same keys/paths.
* For **services** (left-wins per step): The **left-hand** map in each merge call prevails. The kernel unions provider maps in the listed order so that **earlier providers** take precedence over later ones *within the provider step*. The application's `/config/services.php` is applied last and thus overrides both.

> **Recommendation:** Place providers you want to be easily overridden by others **later** in the list for config/routes; for services, prefer documenting stable IDs and rely on the app's final override in `/config/services.php`.

### 6.4 Operational Notes

* **Zero execution at boot:** The kernel **only reads constants**; no provider code is executed during merge.
* **Atomic artifacts:** All three outputs are cached as pure `return [...]` arrays; safe for OPcache with `validate_timestamps=0`.
* **Warm-up:** Use `App::warmCache()` to (re)generate `cfg`, `routes`, and `services` after changing providers or overlays.

### 6.5 Quick Checklist

* [ ] `/config/providers.php` returns **FQCN strings** to `Boot\Registry` classes.
* [ ] Providers expose **constants only** (`CFG_*`, `ROUTES_*`, `MAP_*`).
* [ ] Understand precedence: **last-wins** (config/routes) vs **left-wins** (services).
* [ ] Run **cache warm-up** after edits to providers or overlays.

---

## 7. Contributing Configuration

Providers contribute under namespaces they own to avoid collisions:

```php
<?php
declare(strict_types=1);

namespace Vendor\Package\Boot;

final class Registry
{
    /** Service map (HTTP) */
    public const MAP_HTTP = [
        'auth'        => \Vendor\Package\Service\AuthService::class,
        'userAccount' => \Vendor\Package\Model\UserAccountModel::class,
    ];

    /** Config overlay (HTTP) */
    public const CFG_HTTP = [
        'auth' => [
            'enabled'                => true,
            'twofactor_protection'   => true,
            'session_key'            => 'auth_user_id',
            // other provider defaults...
        ],
        // You can keep other provider-scoped keys here too (no routes here on purpose)
    ];

    /**
     * Routes (HTTP)
     * Keep this associative by path; suffix is part of the public contract.
     */
    public const ROUTES_HTTP = [
        // GET view
        '/login.html' => [
            'controller'    => \Vendor\Package\Controller\AuthController::class,
            'action'        => 'login',
            'methods'       => ['GET'],
            'template_file' => 'public/login.html',
            'template_layer'=> 'vendor/package',
        ],

        // POST action (PRG target)
        '/login' => [
            'controller' => \Vendor\Package\Controller\AuthController::class,
            'action'     => 'loginPost',
            'methods'    => ['POST'],
        ],
    ];

    /** CLI mirrors (adjust if your provider actually differs on CLI) */
    public const MAP_CLI = self::MAP_HTTP;

    public const CFG_CLI = [
        'auth' => [
            'enabled'              => true,
            'twofactor_protection' => true,
            'session_key'          => 'auth_user_id',
        ],
    ];

    // Define ROUTES_CLI only if the provider exposes CLI "routes" (rare):
    // public const ROUTES_CLI = [...];
}
```

**Notes**

* Routes live **directly** on `Registry` via `ROUTES_HTTP`; no separate routes file.
* Keep route keys **literal paths** and include `.html` / `.json` suffixes where applicable.
* `CFG_HTTP` remains purely for configuration (no routes mixed in), which keeps merges clear:

  * `CFG_*` -> config tree
  * `ROUTES_*` -> route table
  * `MAP_*` -> service map
* App overlays can override any single route by re-declaring the same path key.

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

* Contribute routes via `ROUTES_HTTP` (constant on `Boot/Registry`).
* Prefer an **associative map keyed by the public path**:

  ```php
  '/account.html' => [
      'controller' => \Pkg\Account\Controller::class,
      'action'     => 'view',
      'methods'    => ['GET'],
  ]
  ```

> **Merging rule:** Route entries are merged by **path key** with CitOmni's global *last-wins* semantics.
> Downstream layers (app base/env) can override a single route by re-declaring the same path key.

  This enables deep merges and path-level overrides in app overlays.
* Avoid list-style routes (numeric keys) unless you intend for app overlays to replace the whole set.

> **Methods:** Use uppercase method names (e.g., ["GET","POST"]). Mixed case is discouraged.

*For full runtime semantics, see also* **Routing Layer - CitOmni HTTP Runtime (v1.0)**.

### 8.1 Middleware, Guards, and Policies (Optional Structure)

CitOmni's philosophy favors **explicit, deterministic enforcement** over implicit middleware chains.
Accordingly, providers are expected to **co-locate route declarations** within their `Boot/Registry.php` file rather than dispersing them across auxiliary holders.
While a separate `Boot/Routes.php` is *permitted*, it is discouraged: Co-location reduces I/O, avoids redundant autoloads, and aligns with the framework's *green-by-design* principle of minimal runtime overhead.

---

#### 8.1.1 Layered responsibilities

Each provider contributes to three orthogonal domains, none of which should be conflated:

| Layer             | Constant(s)                | Responsibility                                               | Typical contents                            |
| ----------------- | -------------------------- | ------------------------------------------------------------ | ------------------------------------------- |
| **Configuration** | `CFG_HTTP / CFG_CLI`       | Declarative defaults and policy parameters                   | guard names, role thresholds, CSRF settings |
| **Routes**        | `ROUTES_HTTP / ROUTES_CLI` | Public contract: literal paths -> controller/action/method(s) | `.html`, `.json`, or action-only routes     |
| **Services**      | `MAP_HTTP / MAP_CLI`       | Concrete implementations of policies or utilities            | `auth`, `role`, `security`, `response`, ...   |

This separation mirrors the kernel's independent build stages
(`App::buildConfig()`, `App::buildRoutes()`, and `App::buildServices()`), each merged and cached deterministically.

---

#### 8.1.2 Enforcement pattern: Deterministic base-controller gating

Instead of opaque middleware layers, CitOmni applications perform guard and role enforcement inside a dedicated **base controller**.
This pattern centralizes authorization, eliminates per-route logic duplication, and keeps access policies explicit, testable, and cache-friendly.

```php
<?php
declare(strict_types=1);

namespace CitOmni\Admin\Controller;

use CitOmni\Kernel\Controller\BaseController;
use CitOmni\Auth\Model\UserAccountModel;

class AdminBaseController extends BaseController {

	protected UserAccountModel $userAccount;

	protected function init(): void {

		// 1) Resolve current user/account
		$this->userAccount = $this->app->userAccount;

		// 2) Enforce login requirement
		if (!$this->userAccount->isLoggedin()) {
			$this->app->response->redirect('../login.html');
		}

		// 3) Enforce minimum role
		if (!$this->app->role->atLeast('operator')) {
			$this->app->response->redirect('../login.html');
		}

		// 4) Mark response as administrative context
		$this->app->response->adminHeaders();
	}
}
```

**Rationale:**

* Deterministic execution (no dynamic middleware stack).
* Zero reflection or handler discovery.
* Reproducible behavior across all inheriting controllers.
* Negligible runtime overhead-no conditional routing or deferred closures.

---

#### 8.1.3 CSRF integrity

Cross-Site Request Forgery protection is managed by the `Security` service, which implements token issuance, verification, and structured failure logging.
Templates emit CSRF fields declaratively through the TemplateEngine service, ensuring seamless integration without runtime branching:

```html
<form method="post" action="{{ $url('/login') }}">
	{{{ $csrfField() }}}
	<!-- additional fields -->
</form>
```

In POST endpoints, verification is explicit and context-aware:

```php
public function loginPost(): void {

	$token = (string)($this->app->request->post($this->app->security->csrfFieldName()) ?? '');

	if (($this->app->cfg->security->csrf_protection ?? true) && !$this->app->security->verifyCsrf($token)) {
		$this->app->security->logFailedCsrf('login_post');
		$this->app->errorHandler->httpError(400, [
			'reason'  => 'csrf_failed',
			'message' => 'Invalid CSRF token.',
		]);
		return;
	}

	// continue with authentication and redirect
}
```

**Operational semantics**

* Field name derives from `cfg.security.csrf_field_name` or defaults to `csrf_token`.
* Protection can be toggled via `cfg.security.csrf_protection`.
* Token storage and verification are handled through the session service.
* Failures are logged as JSON lines via the log service (default directory `var/logs/`, file name `csrf_failures.jsonl`), or to PHP's error log if the log service is unavailable.

This approach preserves immutability and ensures all form endpoints operate under the same deterministic security contract.

---

#### 8.1.4 Declarative routes only

Route maps (`ROUTES_HTTP / ROUTES_CLI`) must remain pure data structures.
They describe what the system *offers*, never how it behaves. Example:

```php
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
```

No procedural logic, closures, or dynamic evaluation are permitted.
The router consumes this map as an immutable lookup table, guaranteeing constant-time resolution and no runtime side effects.

---

#### 8.1.5 When (and only when) to isolate `Boot/Routes.php`

A standalone `Boot/Routes.php` file is justified solely when a provider maintains an **exceptionally large or shared** routing surface.
Even then, it should export a single constant and be referenced declaratively:

```php
public const ROUTES_HTTP = \Vendor\Package\Boot\Routes::MAP;
```

Otherwise, route co-location within `Boot/Registry.php` remains the preferred pattern - not merely for convenience, but because it eliminates one additional autoload operation and file read per provider. Fewer filesystem interactions translate directly into lower latency, smaller memory footprint, and improved cache locality-key pillars of CitOmni's **green-by-design** runtime model.

---

#### 8.1.6 Summary

| Aspect                 | Recommended Practice                                    | Rationale                                    |
| ---------------------- | ------------------------------------------------------- | -------------------------------------------- |
| **Route placement**    | Co-locate in `Boot/Registry.php`                        | Minimizes I/O; aligns with green-by-design   |
| **Authorization**      | Implement in base controller `init()`                   | Explicit, deterministic, zero reflection     |
| **CSRF handling**      | Use `Security` service (`$csrfField()`, `verifyCsrf()`) | Centralized, auditable, log-integrated       |
| **Brute-force limits** | (future concern)                                        | Currently handled in `UserAccountModel` only |
| **Route semantics**    | Pure, declarative data (no runtime logic or closures)   | Enables cacheable, constant-time routing     |

---

**In essence:**

> Authorization, integrity, and routing in CitOmni are declarative by design.
> Enforcement is explicit, co-located, and zero-magic-supporting both deterministic behavior and sustainable runtime efficiency.

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

These helpers are **zero-I/O** and derive from the **already merged** service map and configuration; package detection may also consider the compiled **route table** for controller FQCNs referenced there.

---

## 11. Package Layout and Autoloading

A typical provider:

```
vendor/package
├─ composer.json
└─ src
   ├─ Boot
   ├─ Registry.php             (REQUIRED: exposes CFG_*, MAP_*, and optionally ROUTES_*)
   ├─ Routes.php               (optional (but not best practice); define a MAP constant and reference it from Registry::ROUTES_*)
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

* **Constants, not code:** keep `Boot/Registry.php` (and, if used, `Boot/Routes.php`) purely declarative.
* **Avoid heavy constructors:** defer external I/O (DB, HTTP calls) until needed.
* **Exploit caches:** apps may pre-warm var/cache/cfg.{mode}.php, var/cache/routes.{mode}.php, and var/cache/services.{mode}.php; providers automatically benefit from all three deterministic caches.
* **No global state:** use `$this->app` for runtime access; avoid static registries.
* **Warmed caches are ABI:** Treat `var/cache/cfg.{mode}.php`, `var/cache/routes.{mode}.php`, and `var/cache/services.{mode}.php` as build artifacts.
  Providers should be compatible with stale-until-replaced semantics; do not rely on runtime mutation of config structures.

---

## 13. Error Handling and Security

* Do **not** catch broadly in provider services; allow the mode-specific \CitOmni\Http\Service\ErrorHandler or \CitOmni\Cli\Service\ErrorHandler to handle and log all uncaught exceptions deterministically.
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

* Runtime code in `Boot\Registry` or `Boot\Routes`.
* Secret material or environment-specific values in provider constants.
* List-style routes when fine-grained path-level overrides are desirable.
* Constructors that perform network or filesystem I/O eagerly.
* Catch-all exception handlers that swallow errors.

---

## 17. Example: Authentication Provider (Illustrative, updated)

```php
<?php
declare(strict_types=1);

namespace CitOmni\Auth\Boot;

final class Registry
{
    /** Service map (HTTP) */
    public const MAP_HTTP = [
        'auth'        => \CitOmni\Auth\Service\Auth::class,
        'userAccount' => \CitOmni\Auth\Model\UserAccountModel::class,
    ];

    /** Config overlay (HTTP) - no routes mixed in */
    public const CFG_HTTP = [
        'auth' => [
            'twofactor_protection' => true,
            'session_key'          => 'auth_user_id', // app/env may override
        ],
    ];

    /**
     * Routes (HTTP) - associative by literal path.
     * Suffix is part of the public contract: .html for views, .json for APIs.
     * Action-only endpoints (e.g., PRG) have no suffix.
     */
    public const ROUTES_HTTP = [
        // Public auth (GET view)
        '/login.html' => [
            'controller'    => \CitOmni\Auth\Controller\AuthController::class,
            'action'        => 'login',                // renders login view
            'methods'       => ['GET'],
            'template_file' => 'public/login.html',
            'template_layer'=> 'citomni/auth',
        ],

        // Public auth (POST action, PRG target)
        '/login' => [
            'controller' => \CitOmni\Auth\Controller\AuthController::class,
            'action'     => 'loginPost',               // handles POST, sets flash, redirects
            'methods'    => ['POST'],
        ],

        // Logout (POST)
        '/logout' => [
            'controller' => \CitOmni\Auth\Controller\AuthController::class,
            'action'     => 'logoutPost',
            'methods'    => ['POST'],
        ],
    ];

    /** CLI mirrors (adjust if this provider truly differs on CLI) */
    public const MAP_CLI = self::MAP_HTTP;

    public const CFG_CLI = [
        'auth' => [
            'twofactor_protection' => true,
            'session_key'          => 'auth_user_id',
        ],
    ];

    // Define ROUTES_CLI only if you expose CLI "routes" (rare):
    // public const ROUTES_CLI = [...];
}
```

**Notes**

* Routes live directly on Registry (ROUTES_HTTP). These keys form part of the public routing contract and are compiled into /var/cache/routes.http.php by App::buildRoutes() during cache warm-up.
* `CFG_HTTP` is strictly configuration-kept separate from routes.
* The **suffix contract** is enforced: `.html` for human-facing views, `.json` for programmatic endpoints, no suffix for action/redirect endpoints like `/login`.

---

## 18. App-Side Overrides (Illustrative, updated)

**/config/services.php** - override a provider service by ID:

```php
<?php
return [
    'auth' => \App\Service\CustomAuthService::class, // overrides provider's 'auth' service
];
```

**/config/citomni_http_cfg.prod.php** - environment config overrides (no routes here):

```php
<?php
return [
    'auth' => [
        'twofactor_protection' => false, // prod policy override
        'session_key'          => 'sess_uid',
    ],
];
```

**/config/citomni_http_routes.prod.php** - override a single route by re-declaring its path key:

```php
<?php
use App\Controller\LoginController;

return [
    // Override only the GET view controller; keep the public contract (/login.html) intact
    '/login.html' => [
        'controller'    => LoginController::class, // app-specific controller
        'action'        => 'login',
        'methods'       => ['GET'],
        'template_file' => 'public/login.html',
        'template_layer'=> 'app', // switch layer if desired
    ],

    // Optionally override the POST action target too:
    '/login' => [
        'controller' => LoginController::class,
        'action'     => 'loginPost',
        'methods'    => ['POST'],
    ],
];
```

Remember: .html and .json suffixes are part of the public API; never remove them when overriding routes-only adjust controller bindings.

This keeps the merge model clean and deterministic:

* **Config overlays** in `citomni_http_cfg*.php`
* **Route overrides** in `citomni_http_routes*.php`
* **Service overrides** in `services.php`

All three participate in cache warming (`cfg`, `routes`, `services`) and respect our last-wins/left-wins rules exactly as documented.

---

## 19. Quick Checklist for Provider Authors

* [ ] PHP ≥ 8.2; PSR-4 autoload; classes in PascalCase; methods camelCase.
* [ ] `src/Boot/Registry.php` exposing any of: `CFG_{HTTP|CLI}`, `MAP_{HTTP|CLI}`, `ROUTES_{HTTP|CLI}`.
* [ ] Optional (but not best practice): `src/Boot/Routes.php` holding a `MAP` constant, referenced by `Boot/Registry`.
* [ ] No `Boot/Config.php` (reserved for mode baselines).
* [ ] Keep overlays associative; avoid lists unless full replacement is desired.
* [ ] Constructor signature: `__construct(App $app, array $options = [])`.
* [ ] No secrets in constants; document required keys for app overlays.
* [ ] Unit tests for service resolution and (if HTTP) route correctness.
* [ ] Document stable service IDs for overrideability.
* [ ] Changelog and SemVer for any public contract changes.
* [ ] Run `App::warmCache()` to rebuild **cfg**, **routes**, and **services** caches.

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

* Contribute **configuration**, **routes**, and **service IDs** via constants,
* Are merged by `App::buildConfig()`, `App::buildRoutes()`, and `App::buildServices()` in a predictable order,
* Rely on a **minimal construction contract**,
* Avoid runtime side effects and hidden magic,
* Automatically participate in cache pre-warming (`cfg`, `routes`, `services`).

**One philosophy:** explicit, deterministic, fast.
**One pattern:** constants in, predictable behavior out.

> *Three overlays, one philosophy: explicit, deterministic, fast.*
> CitOmni providers declare; the kernel composes.