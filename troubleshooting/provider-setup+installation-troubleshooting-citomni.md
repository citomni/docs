# Troubleshooting CitOmni Provider Setup & Installation (v1.0)

*A practical guide to diagnosing "provider won't load", class-not-found, and merge-order surprises.*

---

**Applies to:** CitOmni ≥ 8.2  
**Audience:** Provider authors & app integrators  
**Status:** Canonical troubleshooting reference

---

## 0) TL;DR - The 60-second checklist

1. **Providers list** - `/config/providers.php` returns **FQCNs of Boot classes**:
   ```php
   return [
     \Vendor\Pkg\Boot\Registry::class, // NOT "Provider\Services"
   ];
````

2. **File & namespace** - Provider boot file is here and matches PSR-4:

   ```
   vendor/vendor-name/pkg/src/Boot/Registry.php
   namespace Vendor\Pkg\Boot;
   final class Registry { public const MAP_HTTP = [...]; ... }
   ```

3. **Constants exist** - `Registry` actually defines `CFG_*`, `ROUTES_*`, `MAP_*` as **public const arrays**.

4. **Composer autoload** - Autoloader knows about the package:

   ```
   composer dump-autoload -o
   php -r "require 'vendor/autoload.php'; var_dump(class_exists('\Vendor\Pkg\Boot\Registry'));"
   ```

5. **Warm caches** - Rebuild artifacts after changes:

   ```
   // your deploy/admin step
   $this->app->warmCache(overwrite: true, opcacheInvalidate: true);
   ```

   Confirm files were produced:

   ```
   var/cache/cfg.http.php
   var/cache/routes.http.php
   var/cache/services.http.php
   ```

6. **No stale OPcache** (prod):

   * Invalidate per-file or call `opcache_reset()` post-deploy.

---

## 1) Typical symptoms -> likely cause -> fix

| Symptom                                                                | Likely Cause                                                                                | Fix                                                                                                                                                                                                                      |                                          |
| ---------------------------------------------------------------------- | ------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ---------------------------------------- |
| `RuntimeException: Provider class not found: Vendor\Pkg\Boot\Registry` | Wrong FQCN in `/config/providers.php`, wrong **path or namespace**, or autoload not rebuilt | Ensure file path & `namespace Vendor\Pkg\Boot;`, then `composer dump-autoload -o`. Verify with `class_exists()` (see §0).                                                                                                |                                          |
| "Nothing from my provider shows up"                                    | You defined the **old** `Provider/Services.php` or `Boot/Services.php` only                 | **Migrate** to `src/Boot/Registry.php` with `CFG_*`, `ROUTES_*`, `MAP_*`. Add Registry FQCN to `providers.php`.                                                                                                          |                                          |
| Routes aren't applied                                                  | You put routes in config or wrong constant name                                             | Use `public const ROUTES_HTTP = [...]` (path-keyed). Re-warm **routes** cache.                                                                                                                                           |                                          |
| Services override doesn't work                                         | Wrong **merge mental model**                                                                | **Services use left-wins per step**. Kernel does `$map = $pvMap + $map;` for each provider **in order** -> **later providers override earlier ones**. The **app** runs last: `$map = $appMap + $map;` -> app wins overall. |                                          |
| Config overrides don't stick                                           | Using list arrays or wrong files                                                            | **Config is deep last-wins.** Ensure you override with associative keys in `/config/citomni_{http                                                                                                                        | cli}_cfg.php`(and optional`{ENV}` file). |
| Changes don't appear in prod                                           | Stale caches / OPcache                                                                      | Re-warm caches & invalidate OPcache (`opcache_invalidate()` per file or `opcache_reset()`), then hard-reload.                                                                                                            |                                          |
| Class autoloads locally, but not in CI/prod                            | Case mismatch on PSR-4 paths                                                                | Verify exact case of directories & namespaces. Linux is case-sensitive.                                                                                                                                                  |                                          |
| Duplicate or "ghost" provider version                                  | Two installs (packagist + path repo)                                                        | Run `composer show vendor/pkg -P`. Keep only one source. Prefer path repo with `"symlink": true` in dev.                                                                                                                 |                                          |
| "My controller template isn't getting provider data"                   | You push data manually in controllers and collide with providers                            | Rendering merge order is **controller payload > vars_providers > globals**. Use distinct keys or rely on `vars_providers` for sitewide data.                                                                             |                                          |

---

## 2) The new golden rules (sanity checks)

### 2.1 Providers file - **Boot\Registry** only (constants; no code)

```php
<?php
declare(strict_types=1);

namespace Vendor\Pkg\Boot;

final class Registry {
  public const CFG_HTTP = [ /* deep, last-wins */ ];
  public const MAP_HTTP = [ /* id => FQCN or ['class'=>..., 'options'=>...] */ ];
  public const ROUTES_HTTP = [ /* path-keyed, last-wins */ ];

  // Mirrors (optional)
  // public const CFG_CLI = [...];
  // public const MAP_CLI = [...];
  // public const ROUTES_CLI = [...]; // rarely needed
}
```

### 2.2 Merge rules (authoritative)

* **Config:** deep **last-wins** across layers.
* **Routes:** by path key, **last-wins** across layers.
* **Services:** **left-wins per merge step**, but the kernel applies:

  1. Baseline -> `$map`
  2. **Providers in listed order:** `$map = $pvMap + $map;`
     -> **later providers override earlier ones**
  3. **App last:** `$map = $appMap + $map;`
     -> **app overrides all**

### 2.3 Shapes (strict)

* Service definitions:

  ```php
  'id' => \Vendor\Pkg\Service\Foo::class,
  // or
  'id' => ['class' => \Vendor\Pkg\Service\Foo::class, 'options' => ['k'=>'v']],
  ```
* No closures, no factories, no runtime code in `Registry`.

---

## 3) Quick commands that save hours

**Verify a provider is autoloadable**

```bash
php -r "require 'vendor/autoload.php'; var_dump(class_exists('\Vendor\Pkg\Boot\Registry'));"
```

**Inspect compiled caches (HTTP)**

```bash
php -r "var_export(require 'var/cache/services.http.php');"
php -r "var_export(require 'var/cache/routes.http.php');"
php -r "var_export(require 'var/cache/cfg.http.php');"
```

If your ID/route/config isn't there, the issue is **before** runtime: registration/merge/cache warming.

**Detect duplicate installs**

```bash
composer show vendor/pkg -P
```

**Rebuild and nuke OPcache**

```php
$this->app->warmCache(overwrite: true, opcacheInvalidate: true);
// If you control the PHP process:
opcache_reset();
```

---

## 4) Monorepo & path repositories (common pitfall)

If the provider isn't published yet, add a **path** repo in the app's `composer.json`:

```json
"repositories": [
  { "type": "path", "url": "../my-provider", "options": { "symlink": true } }
]
```

Then:

```
composer require vendor-name/my-provider:*@dev
composer dump-autoload -o
```

Re-check with `class_exists()` (see §3).

---

## 5) Minimal known-good examples

### 5.1 `/config/providers.php`

```php
<?php
return [
  \Vendor\PkgA\Boot\Registry::class,
  \Vendor\PkgB\Boot\Registry::class, // listed later -> overrides PkgA on service ID clashes
];
```

### 5.2 Provider `Registry` (HTTP)

```php
<?php
declare(strict_types=1);

namespace Vendor\PkgA\Boot;

final class Registry {
  public const CFG_HTTP = ['featureA' => ['enabled' => true]];
  public const MAP_HTTP = ['greeter' => \Vendor\PkgA\Service\Greeter::class];
  public const ROUTES_HTTP = [
    '/demo.html' => [
      'controller'    => \Vendor\PkgA\Controller\DemoController::class,
      'action'        => 'index',
      'methods'       => ['GET'],
      'template_file' => 'public/demo.html',
      'template_layer'=> 'vendor/pkg-a',
    ],
  ];
}
```

### 5.3 App override `/config/services.php`

```php
<?php
return [
  // App wins overall (applied last):
  'greeter' => \App\Service\CustomGreeter::class,
];
```

---

## 6) Stale/incorrect caches - how to confirm

1. Delete caches:

   ```
   rm -f var/cache/*.{http,cli}.php
   ```
2. Warm again:

   ```php
   $this->app->warmCache(overwrite: true, opcacheInvalidate: true);
   ```
3. Inspect:

   ```bash
   php -r "var_export(require 'var/cache/services.http.php');"
   ```

If your provider's IDs are missing, go back to §0 (autoload & FQCN).

---

## 7) Migration notes (old -> new)

| Old pattern                                           | New pattern                                                        |
| ----------------------------------------------------- | ------------------------------------------------------------------ |
| `src/Provider/Services.php`                           | `src/Boot/Registry.php`                                            |
| `namespace Vendor\Pkg\Provider; final class Services` | `namespace Vendor\Pkg\Boot; final class Registry`                  |
| `MAP_HTTP`, `CFG_HTTP` on *Services.php*              | Put **all** `CFG_*`, `ROUTES_*`, `MAP_*` on **Registry**           |
| Implicit route ingestion                              | **Always** `ROUTES_HTTP` (path-keyed, last-wins)                   |
| Unclear precedence for services                       | **Authoritative**: later providers override earlier, app wins last |

**Before (legacy)**

```php
namespace Vendor\Pkg\Provider;
final class Services {
  public const MAP_HTTP = ['id' => \Vendor\Pkg\Service\Foo::class];
}
```

**After (current)**

```php
namespace Vendor\Pkg\Boot;
final class Registry {
  public const MAP_HTTP = ['id' => \Vendor\Pkg\Service\Foo::class];
}
```

---

## 8) "I just need to boot now" (safe temporary bypass)

If a single provider blocks boot during debugging:

1. Comment it out in `/config/providers.php`.
2. If needed, add temporary service entries in `/config/services.php` to get past missing IDs.
3. Warm caches, proceed, then fix the provider properly.

---

## 9) When to ask for help (what to include)

Send these to your friendly reviewer (or, as last resort, support@citomni.com):

* `/config/providers.php` contents
* App `composer.json` (autoload + any `repositories`)
* Provider file paths and the exact `namespace` of `src/Boot/Registry.php`
* Output of:

  ```
  php -r "require 'vendor/autoload.php'; var_dump(class_exists('\Vendor\Pkg\Boot\Registry'));"
  php -r "var_export(require 'var/cache/services.http.php');"
  ```
* Your current cache warm step and how OPcache is invalidated

---

**In essence:**
Providers are **constants-only overlays**. If the class autoloads, the constants exist, and caches are warmed, the kernel will compose them deterministically. Most issues reduce to: wrong FQCN, outdated autoload, missing constants, or stale caches.
