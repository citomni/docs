# CitOmni View - `vars_providers` (Auto-injecting Template Variables)

> **Low overhead. High performance. Predictable by design.**

This guide explains **what `vars_providers` are**, how matching works (**include/exclude** semantics), the **config shape**, **execution order & precedence**, **performance considerations**, **debugging**, **testing**, and a **migration path** away from manual `$sitewide = ...` composition in controllers.

* PHP ≥ 8.2
* PSR-1 / PSR-4
* Tabs for indentation, **K&R** brace style
* English PHPDoc and inline comments
* Deterministic behavior; no catch-all exception handling in core

---

## 1) What is a `vars_provider`?

A **vars provider** is a small declarative rule in config that says:

> "For requests whose **path** matches X (and not Y), **call** this `[class, method]` and **inject** the **return value** into the template as **`$var`**."

**Example intention**

* Always inject `header` and `footer` on all public pages (but not `/admin/...`).
* Inject `categories`, `popular_tags` on news pages and the homepage.
* Inject `sidebar_events` on events/news/pages/home.

**Why?**
So controllers stay lean and don't manually assemble "sitewide" payload for every action. It also centralizes the policy for which pages get which shared UI fragments.

---

## 2) Where do I configure it?

In your **HTTP config** (merged via normal CitOmni precedence), under `view`:

```php
'view' => [
	'vars_providers' => [
		[
			'var'  => 'header',
			'call' => ['class' => \Vendor\Package\Model\SitewideModel::class, 'method' => 'header'],
			'exclude' => ['~^/admin/~'], // all paths EXCEPT /admin/...
		],
		[
			'var'  => 'footer',
			'call' => ['class' => \Vendor\Package\Model\SitewideModel::class, 'method' => 'footer'],
			'exclude' => ['~^/admin/~'],
		],
		[
			'var'  => 'categories',
			'call' => ['class' => \App\Model\AppSitewideModel::class, 'method' => 'newsCategories'],
			'include' => ['/nyheder/*', '/sider/*', '/'], // only here
		],
		[
			'var'  => 'popular_tags',
			'call' => ['class' => \App\Model\AppSitewideModel::class, 'method' => 'popularTags'],
			'include' => ['/nyheder/*', '/sider/*', '/'],
		],
		[
			'var'  => 'sidebar_events',
			'call' => ['class' => \App\Model\AppSitewideModel::class, 'method' => 'sidebarEvents'],
			'include' => ['/begivenheder/*', '/nyheder/*', '/sider/*', '/'],
		],
	],
],
```

---

## 3) Matching semantics (include/exclude)

**TL;DR**: You can specify **either** `include` or `exclude`, or **both**. Defaults are sensible and deterministic.

* `include` (optional)

  * **Missing or empty ⇒ "all paths are candidates".**
  * If present ⇒ **path must match at least one** include pattern.

* `exclude` (optional)

  * **Missing or empty ⇒ nothing is excluded.**
  * If present ⇒ if path matches **any** exclude pattern, the provider is **skipped**.

* **Order**: We first check `include` (who can be in), then `exclude` (who is removed).

**Pattern types**

* **Regex** when the string is delimited with `~...~` (e.g., `~^/admin/~`).
* Otherwise a **glob/prefix style**:

  * `/foo/*` matches anything starting with `/foo/`.
  * `/` is the homepage (exact path).
  * `*` means any path (useful for explicitness).

**Path used for matching**
`$this->app->request->path()` - i.e., **no scheme, host, or query string**. Example: `/nyheder/min-nyhed-a42.html`.

---

## 4) Execution order & variable precedence

**Provider execution order** = the order of entries in `view.vars_providers`. Keep related providers grouped; it's easier to reason about.

**Variable precedence** (deterministic):

1. **Controller payload** (explicit data passed to `View::render()`) **wins**.

   * If your controller sets `header` manually, providers **do not overwrite** it.
2. Then **vars providers** fill any **missing** keys.

   * Providers are only applied for the variables they declare.

> This guarantees controllers can always override, while the default is "providers fill the gaps".

---

## 5) What does the View do at runtime?

`View::render()` (simplified pseudo):

```php
public function render(string $file, string $layer = 'app', array $data = []): void {
	// 1) Build globals once (existing behavior)
	$vars = $this->globals ??= $this->buildGlobals();

	// 2) Apply vars_providers for this request path
	$provided = $this->resolveVarsProvidersForCurrentPath();

	// 3) Merge order: controller ($data) wins > providers ($provided) > globals ($vars)
	$final = $data + $provided + $vars;

	// 4) Call LiteView with the final payload
	LiteView::render(..., $final, ...);
}
```

**Key guarantees**

* **No overwrite** of controller-supplied keys.
* Providers are **idempotent** (pure data return). They should not emit headers or perform side effects.
* **Fast-fail** on misconfig (e.g., invalid class/method) via SPL exceptions - let the global ErrorHandler handle rendering/logging.

---

## 6) Provider `call` contract

Each provider has:

```php
'call' => [
	'class'  => \Vendor\Package\ClassName::class,
	'method' => 'methodName',
	// (optional, future-safe) 'args' => [ ... ],
],
```

* The View will **instantiate** the class as `new ClassName($app)` (CitOmni Service-style constructor: `new ($app, array $options = [])` if applicable).
* It will then call `->method()` **with no arguments** (current recommended pattern for sitewide fragments).
* The method **must return** a value that is cheap to merge into the view data (array/scalars).
* **Performance**: If you do DB work, keep queries **small and indexed**; memoize **within request** (static `$memo`) if the method can be called more than once.

> If you later want argumentized providers, add an `'args'` array and let View pass them positionally. Keep the API boring.

---

## 7) Typical patterns

### A) "All pages except admin"

```php
[
	'var'  => 'header',
	'call' => ['class' => \Vendor\Package\Model\SitewideModel::class, 'method' => 'header'],
	'exclude' => ['~^/admin/~'],
],
```

### B) "Only on these areas"

```php
[
	'var'  => 'popular_tags',
	'call' => ['class' => \App\Model\AppSitewideModel::class, 'method' => 'popularTags'],
	'include' => ['/nyheder/*', '/sider/*', '/'],
],
```

### C) "All pages" (explicit)

```php
[
	'var'  => 'footer',
	'call' => ['class' => \Vendor\Package\Model\SitewideModel::class, 'method' => 'footer'],
	'include' => ['*'],
],
```

---

## 8) Migration: Remove controller-side sitewide plumbing

**Before** (per action):

```php
$sitewide = (new SitewideModel($this->app))->buildDefaultPayload();

$this->app->view->render(..., [
	// page-specific...
	'sidebar_events' => $sitewide['sidebar_events'],
	'categories'     => $sitewide['categories'],
	'popular_tags'   => $sitewide['popular_tags'],
	'header'         => $sitewide['header'],
	'footer'         => $sitewide['footer'],
]);
```

**After** (providers do it):

```php
$this->app->view->render(..., [
	// Only page-specific variables.
	// Providers inject: header, footer, sidebar_events, categories, popular_tags
]);
```

**Keep** manual injection **only** when you want **explicit override** for a special page.

---

## 9) Performance & caching best practices

* Providers should be **small** and **deterministic** per request.

  * Use **in-request memoization** (`static $memo`) if a provider may be executed multiple times.
* Avoid heavy allocations. Return only the data you need to render.
* Use **tight SELECTs** with **covering indexes**. Order deterministically (e.g., `(sort_order, id)`).
* If you need persistent caching later: Prefer **warm PHP arrays** stored under `/var/cache/...php` (atomically written), loaded by providers *instead of* multiple DB calls. In production with `opcache.validate_timestamps=0`, invalidate on deploy.

---

## 10) Error handling & failure modes

* On invalid config (e.g., class not found, method missing), the View should **throw** a clear **`InvalidArgumentException`** or **`RuntimeException`**.
  Let the global ErrorHandler render/log appropriately.
* If a provider throws due to DB errors, let it **bubble**; do not catch in View.
  This matches CitOmni's **fail fast** philosophy.

---

## 11) Security notes

* Providers run **server-side** and return data only. They should **not** set headers, cookies, or perform redirects. Keep them **pure**.
* If a provider depends on **user context** (e.g., role), read via `$this->app->role` or `$this->app->userAccount`, but **do not** mutate session or identity in a provider.
* Avoid leaking secrets. Providers should not return internal identifiers unless required by the template.

---

## 12) Testing `vars_providers`

### A) Unit-ish: Invoke provider class directly

```php
$app = bootstrap_test_http_app(); // your fixture builder
$model = new \App\Model\AppSitewideModel($app);

$header = $model->header();
$this->assertArrayHasKey('branding', $header);
$this->assertIsArray($header['topbar']);
```

### B) Integration: Render a view with a mocked request path

1. Configure `view.vars_providers` in a test cfg.
2. Force `$app->request` to a path like `/nyheder/foo-a42.html`.
3. Call `View::render()` with **no** `header/footer` in `$data`.
4. Assert the compiled template sees `$header`, `$footer`, etc.

---

## 13) Advanced usage (optional ideas)

These are **optional**; only add if/when needed:

* **`args` support** in `call`:

  ```php
  'call' => [
  	'class' => \Vendor\Class::class,
  	'method' => 'buildSomething',
  	'args' => [10, 'compact'], // positional
  ],
  ```

  Keep it boring; avoid magic named arguments in config.

* **`override` flag** (future):
  Allow providers to overwrite controller data if `override: true`. Default should remain **false** (controller wins).

* **Provider-level toggle** (feature flag):
  Add `'enabled' => true/false` to allow turning a provider off without removing the block.

---

## 14) Reference: Tiny matcher you can mirror

```php
/**
 * Decide if current path matches a provider rule.
 *
 * @param string $path       Current request path (e.g. "/nyheder/x.html")
 * @param array  $include    List of patterns (regex "~...~" or globs)
 * @param array  $exclude    List of patterns (regex "~...~" or globs)
 * @return bool
 */
private function matches(string $path, array $include = [], array $exclude = []): bool {
	$in = true;
	if ($include !== []) {
		$in = false;
		foreach ($include as $pat) {
			if ($this->pat($path, $pat)) { $in = true; break; }
		}
	}
	if (!$in) return false;

	foreach ($exclude as $pat) {
		if ($this->pat($path, $pat)) return false;
	}
	return true;
}

/** One pattern check */
private function pat(string $path, string $pat): bool {
	if ($pat !== '' && $pat[0] === '~') {
		return (bool)\preg_match($pat, $path);
	}
	// very small glob: '*' anywhere; typical forms are '/foo/*', '/', '*'
	if ($pat === '*') return true;
	if ($pat === '/') return $path === '/';
	if (\str_ends_with($pat, '/*')) {
		return \str_starts_with($path, \substr($pat, 0, -1)); // keep trailing slash before '*'
	}
	return $path === $pat; // exact match
}
```

---

## 15) FAQ

**Q: Do I have to specify both `include` and `exclude`?**
A: No. Use **either**. If **both** are present, we apply **include first**, then **exclude**.

**Q: What if my controller already passes `header`?**
A: The controller wins. Providers won't overwrite it (by design).

**Q: Do providers run even if the variable already exists in `$data`?**
A: The View **should** short-circuit to avoid wasted work. Implement it as: "if key exists in `$data`, skip provider".

**Q: Can a provider call another provider?**
A: Don't. Keep providers **pure** and independent. If they share logic, extract that logic into a **model/service** they can both use.

**Q: How do I disable providers on admin pages?**
A: Use `exclude: ['~^/admin/~']`.

**Q: Matching the homepage?**
A: Use `'/'` in `include` or rely on `exclude` only for "all but admin".

---

## 16) Authoring checklist (providers & call targets)

* [ ] Provider entries are **ordered** sensibly in `view.vars_providers`.
* [ ] Each `call` target is **fast** and **pure** (no headers/cookies/redirects).
* [ ] DB queries are **small, indexed, deterministic**.
* [ ] Use **in-request memo** (`static $memo`) for repeated calls.
* [ ] Controller can **override** any provider var by setting it in `$data`.
* [ ] Matching rules are **simple to read** (`include` or `exclude`, not both unless needed).
* [ ] All doc/comments in **English**, tabs, K&R braces.

---

## 17) Example: Final setup (copy/paste)

```php
'view' => [
	'vars_providers' => [
		[
			'var'  => 'header',
			'call' => ['class' => \Vendor\Package\Model\SitewideModel::class, 'method' => 'header'],
			'exclude' => ['~^/admin/~'],
		],
		[
			'var'  => 'footer',
			'call' => ['class' => \Vendor\Package\Model\SitewideModel::class, 'method' => 'footer'],
			'exclude' => ['~^/admin/~'],
		],
		[
			'var'  => 'categories',
			'call' => ['class' => \App\Model\AppSitewideModel::class, 'method' => 'newsCategories'],
			'include' => ['/nyheder/*', '/sider/*', '/'],
		],
		[
			'var'  => 'popular_tags',
			'call' => ['class' => \App\Model\AppSitewideModel::class, 'method' => 'popularTags'],
			'include' => ['/nyheder/*', '/sider/*', '/'],
		],
		[
			'var'  => 'sidebar_events',
			'call' => ['class' => \App\Model\AppSitewideModel::class, 'method' => 'sidebarEvents'],
			'include' => ['/begivenheder/*', '/nyheder/*', '/sider/*', '/'],
		],
	],
],
```

**Result:** Controllers can drop all the `$sitewide` plumbing. Templates receive **exactly** the old variables (`header`, `footer`, `categories`, `popular_tags`, `sidebar_events`) - now injected automatically and predictably.

---

### Closing note

Keep `vars_providers` **boring**: Small, explicit, and deterministic. Boring config is fast config - and fast config is green.
