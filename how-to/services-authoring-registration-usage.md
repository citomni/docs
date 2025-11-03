# CitOmni Services - Authoring, Registration, and Usage (PHP 8.2+)

> **Low overhead. High performance. Predictable by design.**

This document explains **how to build Services** for CitOmni: What they are, how they are **registered** and **resolved**, how they consume **configuration**, and how to keep them **deterministic** and **cheap**. It includes a production-ready **service skeleton** and authoritative guidance for **/config/services.php**.

* PHP ≥ 8.2
* PSR-1 / PSR-4
* Tabs for indentation, **K&R** brace style
* English PHPDoc and inline comments
* No catch-all exception handling in Services (let the global error handler handle failures)

---

## 1) What is a Service?

A **Service** is a small, focused object, instantiated **once per request/process** by the `App` and accessed as a property:

```php
// Anywhere you have $this->app:
$this->app->response;
$this->app->cookie;
$this->app->session;
$this->app->security;
// ...your own: $this->app->greeter, $this->app->imageResize, etc.
```

**Resolution model**

* `App` holds a **service map** (an associative array of `id => definition`).
* The first time you access `$this->app->{id}`, `App` **instantiates** and **caches** that service.
* Services are final (by convention), explicit, and deterministic.

---

## 2) Constructor contract (without calling it)

Every Service **must** support this constructor contract:

```php
new \Your\Namespace\Service\X(\CitOmni\Kernel\App $app, array $options = [])
```

* The `App` is always argument #1.
* `array $options` (argument #2) is **optional** and comes **only** from the **service map** (see §5).
* You **do not** manually `new` Services in application code; you **register** them and let `App` resolve them on first access.

> The contract exists for the resolver. It is documented here so you design your Service correctly - not so you call it directly.

---

## 3) Where Services come from (maps & precedence)

CitOmni merges **service maps** deterministically using PHP's array-union semantics (**left-wins per merge step**).
This ensures reproducible overrides without reflection or dynamic registration.

1. **Vendor baseline map** (per mode)

   * HTTP -> `\CitOmni\Http\Boot\Services::MAP`
   * CLI  -> `\CitOmni\Cli\Boot\Services::MAP`

   Defines the foundational services for each runtime mode (router, response, errorHandler, etc.).

2. **Provider maps** (optional, merged in the order listed in `/config/providers.php`)

   * Each provider exposes `Boot\Registry::MAP_HTTP` and/or `MAP_CLI`.

   During boot, the kernel iterates the provider list **in order** and performs `$map = $pvMap + $map;` for each.
   Because PHP's array-union preserves the left-hand value on key conflicts, **later providers in the list take precedence** over earlier ones within this step (provider N is merged before provider N−1), yielding: last-listed provider > first-listed provider > vendor baseline.

   The resulting map therefore reflects the deterministic hierarchy:
   **last-listed provider > first-listed provider > vendor baseline.**

3. **Application map** (optional, highest precedence)

   * `/config/services.php` - used for **app-local services** or **explicit overrides**.

   The kernel finally performs `$map = $appMap + $map;`, ensuring that **the application layer always wins** over all provider and vendor definitions.


**Definition shapes**

```php
// Simple (bare class reference)
'response' => \CitOmni\Http\Service\Response::class,

// With options (constructor's 2nd parameter)
'greeter' => [
	'class'   => \Vendor\Package\Service\Greeter::class,
	'options' => ['suffix' => '- from Example'], // optional
],
```

> The `App` enforces this structure strictly; any malformed entry fails fast during boot.

---

## 4) Configuration vs. Options (clear separation)

* **Options** (the service map's `'options'`) are for **construction-time wiring** only. They are passed as the constructor's 2nd parameter. Keep them minimal and deterministic.
* **Configuration** (the config tree) expresses **runtime policy**. Read it via the deep, read-only wrapper: `$this->app->cfg`.

**Config merge order (last wins):**

1. Vendor baseline: `\CitOmni\Http\Boot\Config::CFG` (or CLI)
2. Provider overlays (`/config/providers.php`)
3. App base: `/config/citomni_{http|cli}_cfg.php`
4. App env:  `/config/citomni_{http|cli}_cfg.{ENV}.php` (optional)

Example reads in a Service:

```php
$csrf = (bool)($this->app->cfg->security->csrf_protection ?? true);
$tz   = (string)($this->app->cfg->locale->timezone ?? 'UTC');
```

---

## 5) `/config/services.php` - the application's override and extension point

**Purpose.**
This file allows the **application** to:

* Add app-local Services.
* Override vendor/provider Services by **reusing the same ID**.
* Supply **lightweight options** to your own Services.

**Shape.**
Must return an associative array:

```php
<?php
return [
	// New app-local service:
	'greeter' => [
		'class'   => \App\Service\Greeter::class,
		'options' => ['suffix' => '- from My App'],
	],

	// Override a vendor ID with your own implementation:
	'response' => \App\Http\Response::class,
];
```

**Precedence.**
Anything in `/config/services.php` **wins over** provider maps, which themselves win over vendor maps.

**When to use it.**

* You want a **quick app-local service** (no provider packaging yet).
* You want to **swap** an existing implementation by ID.
* You need **per-app options** without touching providers.

---

## 6) Service lifecycle in a request

1. **Boot** (see your `Http\Kernel::boot()` implementation)

   * Builds `App` (mode: HTTP).
   * Installs global ErrorHandler.
   * Sets timezone/charset.
   * Derives `CITOMNI_PUBLIC_ROOT_URL`, and applies trusted proxies.

2. **Guard & route** (`Http\Kernel::run()`)

   * `$app->maintenance->guard()` (early cut-off).
   * `$app->router->run()` (dispatches controller/action).

3. **Service resolution**

   * When `$this->app->{id}` is first accessed, `App` resolves definition -> `new Class($app, $options)` -> caches instance -> returns it.

> Resolution is **on demand**, deterministic, and performed at most once per request/process per ID.

---

## 7) Authoring a Service (example)

The following example mirrors patterns in core Services. Note that it **does not** import `App` (we don't do that in core Services) and uses your **CitOmni PHPDoc template** precisely.

```php
<?php
declare(strict_types=1);

namespace Vendor\Package\Service;

use CitOmni\Kernel\Service\BaseService;

/**
 * Greeter: Deterministic greeting builder with minimal overhead.
 *
 * Provides a single greeting API that composes a string from a name plus
 * an optional suffix derived from options or config.
 *
 * Behavior:
 * - Options override config; both are optional.
 * - Strict input validation; no I/O; no global state.
 * - Post-init fields are immutable during the request lifecycle.
 *
 * Notes:
 * - Designed for micro-cost hot paths; avoid allocations in greet().
 *
 * Typical usage:
 *   $s = $this->app->greeter->greet('Alice'); // "Hello, Alice - from My App"
 *
 * @throws \InvalidArgumentException On invalid inputs (e.g., empty name).
 */
final class Greeter extends BaseService {
	/** @var string Immutable suffix computed at init() */
	private string $suffix = '';

	/**
	 * Initialize the service once per request/process by merging options
	 * (from the service map) with configuration (from $this->app->cfg).
	 *
	 * Behavior:
	 * - options['suffix'] wins over identity.app_name (if any).
	 * - Fails fast on invalid inputs (none are mandatory here).
	 *
	 * @return void
	 */
	protected function init(): void {
		$cfgAppName = '';
		try {
			$identity = $this->app->cfg->identity ?? (object)[];
			$cfgAppName = (string)($identity->app_name ?? '');
		} catch (\Throwable) {
			$cfgAppName = '';
		}

		$opt = $this->options; // copy
		$this->options = [];   // free memory, avoid accidental reuse

		$optSuffix = (string)($opt['suffix'] ?? '');
		$this->suffix = ($optSuffix !== '')
			? $optSuffix
			: ($cfgAppName !== '' ? '- from ' . $cfgAppName : '');
	}

	/**
	 * Build a greeting. Pure and allocation-lean.
	 *
	 * @param string $name Non-empty display name; trimmed; ASCII or UTF-8.
	 * @return string Greeting string.
	 *
	 * @throws \InvalidArgumentException If $name is empty after trim.
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

**Registering (two patterns)**

*Provider map (preferred for reusable packages)*

```php
// src/Boot/Services.php
namespace Vendor\Package\Boot;

final class Services {
	public const MAP_HTTP = [
		'greeter' => [
			'class'   => \Vendor\Package\Service\Greeter::class,
			'options' => ['suffix' => '- from Vendor'], // optional
		],
	];

	public const CFG_HTTP = [
		'identity' => ['app_name' => 'My App'], // example; optional
	];

	public const MAP_CLI = self::MAP_HTTP;
	public const CFG_CLI = self::CFG_HTTP;
}
```

*Application override/definition*

```php
// /config/services.php
<?php
return [
	'greeter' => [
		'class'   => \App\Service\Greeter::class,
		'options' => ['suffix' => '- from My App'],
	],
];
```

**Using it**

```php
$msg = $this->app->greeter->greet('World');
```

---

### Service class skeleton (drop-in)

```php
<?php
declare(strict_types=1);

namespace <Vendor>\<Package>\Service;

use CitOmni\Kernel\Service\BaseService;

/**
 * <ServiceName>: <One-line responsibility summary>.
 *
 * <Optional longer description: Intent, constraints, performance assumptions.>
 *
 * Behavior:
 * - <Key guarantee or step>
 *   1) <sub-note #1>
 *   2) <sub-note #2>
 *   3) <sub-note #3>
 * - <Another guarantee>
 *
 * Notes:
 * - <Caveats, performance, determinism, environment assumptions>
 *
 * Typical usage:
 *   <When this service class is expected to be called>.
 *
 */
final class <ServiceName> extends BaseService {
	/** @var <type> <Short meaning> */
	private <type> $<fieldName> = <default>;

	/**
	 * One-time initialization. Merge options (from service map) with config
	 * ($this->app->cfg) and pre-validate into cheap, immutable scalars.
	 *
	 * Behavior:
	 * - <options precedence over config, if desired>
	 * - <fail fast on invalid values>
	 *
	 * @return void
	 */
	protected function init(): void {
		// 1) Optional: read config node
		// $raw = $this->app->cfg-><cfgNode> ?? [];
		// $cfg = ($raw instanceof \CitOmni\Kernel\Cfg) ? $raw->toArray() : (is_array($raw) ? $raw : []);

		// 2) Consume options & clear them to free memory
		// $opt = $this->options;
		// $this->options = [];

		// 3) Compute final, validated values
		// $this-><fieldName> = <derived|validated|default>;
	}

	/**
	 * <MethodName>: <One-line summary>.
	 *
	 * <Optional longer description.>
	 *
	 * Typical usage:
	 *   <When this method is expected to run / be called>.
	 *
	 * Examples:
	 *
	 *   // <Happy path in ≤ 6 lines>
	 *   $result = $this->app-><serviceId>-><methodName>('<arg>');
	 *
	 *   // <Edge case / idempotency / sequencing>
	 *   <mini-snippet or narrative, not both>
	 *
	 * Failure:
	 * - <How failure is exposed or contained>.
	 *
	 * @param <type> $<param> <Constraints: e.g., non-empty>.
	 * @return <type> <Return contract, units>.
	 *
	 * @throws <\InvalidArgumentException> <On invalid input>.
	 * @throws <\RuntimeException> <On OS/process failure, if applicable>.
	 */
	public function <methodName>(<type> $<param>): <type> {
		// <validate inputs>
		// <pure/cheap logic>
		// return <result>;
	}
}
```

---

## 8) Patterns to emulate (regular Services)

When designing regular Services (not bootstrapped by the Kernel):

* **Init is cheap**: Pre-derive scalars in `init()`, drop `$this->options` afterward.
* **Read config via `$this->app->cfg`**; do not keep the entire node if you only need 2 scalars.
* **No global I/O** in hot paths; keep I/O at the edges, with narrow API and clear throws.
* **Fail fast** using SPL exceptions; never swallow exceptions globally.
* **Keep public APIs small and pure** where possible.

---

## 9) Kernel-bootstrapped Services (special cases)

A few Services participate directly in boot/guard/dispatch and are **invoked by the Kernel**, not "pulled" on demand:

* **ErrorHandler** - installed early (exception/error/shutdown handlers; response rendering/logging).
* **Maintenance** - consulted early by `Kernel::run()` (`$app->maintenance->guard()`).
* **Router** - invoked by `Kernel::run()` to resolve controllers/actions.

These Services still **conform** to the same constructor contract and map semantics, but their **lifecycle and call sites** are special (Kernel-controlled). Treat them as **infrastructure**: They must be exceptionally defensive about state (e.g., headers already sent), avoid recursion, and keep work minimal.

---

## 10) Performance and caching

* Avoid runtime reflection, directory scans, or dynamic loading.
* Pre-compute and store immutable scalars in `init()`.
* If a Service reads sizable config structures repeatedly, **derive once**.
* Use `App::warmCache()` during deploys to precompile:

  * `var/cache/cfg.{http|cli}.php`
  * `var/cache/services.{http|cli}.php`
  * Note: Route caches are built by the routes builder and are orthogonal to service caches; Services do not need to touch them.

* In production with `opcache.validate_timestamps=0`, invalidate per file or call `opcache_reset()` post-deploy.

---

## 11) Error handling (philosophy)

* Services may throw **SPL** exceptions for invalid inputs/state (`InvalidArgumentException`, `RuntimeException`, ...).
* Do **not** install try/catch "umbrellas" in Services; fail fast and let the global ErrorHandler decide how to render/log.
* If your Service performs OS/IO operations, validate **before** acting (paths exist? permissions? quotas?) to fail deterministically with clear messages.

---

## 12) Testing a Service

Because Services are constructed via a simple contract, they are easy to test without the full HTTP stack:

```php
public function testGreeter(): void {
	$app = new \CitOmni\Kernel\App(__DIR__ . '/../_fixtures/config', \CitOmni\Kernel\Mode::HTTP);
	$svc = new \Vendor\Package\Service\Greeter($app, ['suffix' => '- from Test']);
	$this->assertSame('Hello, Bob - from Test', $svc->greet('Bob'));
}
```

For config-dependent behavior, put a **minimal** tree in a `config/` fixture and let `App` build it.

---

## 13) FAQ

**Q: When should I use `/config/services.php` vs a provider map?**
A: Use a provider map when the Service belongs to a **reusable package**. Use `/config/services.php` when the Service is **app-local** or you want to **override** an existing ID in this specific app.

**Q: Can I pass secrets via service options?**
A: Prefer **config + secrets files** (e.g., `/var/secrets/*.php` referenced in config). Options are fine for **non-sensitive wiring** only.

**Q: How do Services relate to routes?**
A: Routes are compiled by a dedicated builder into their own cache (per mode) and consumed by the Router. Services should not read routes from config; they typically don't need route data at all. If you truly must inspect routing, do it via a narrow, explicit API you own-never by poking into internal route maps.

---

## 14) Authoring checklist

* [ ] Class is `final`, PSR-4, K&R, **tabs**, English docs.
* [ ] Constructor contract is respected (but **not** called manually).
* [ ] `init()` is either empty or merges options with config deterministically and is **cheap**.
* [ ] Public methods have clear contracts and **SPL throws** on invalid input.
* [ ] No catch-all; let the global ErrorHandler handle failures.
* [ ] If the service will run on hot paths, measure allocations and avoid avoidable work.
* [ ] Registered via provider map or `/config/services.php`, as appropriate.
* [ ] Documented with the **CitOmni PHPDoc template**.

---

### Closing note

Keep Services **small, explicit, and boring**. Boring code is fast code - and fast code is green code.
