# CitOmni Testing Guide (PHPUnit)

> Version: 1.0 • PHP ≥ 8.2 • PHPUnit ≥ 10.5  
> Scope: `citomni/http`, `citomni/kernel`, `citomni/cli`, `citomni/common`, and `app-skeleton`

This document explains **how we test CitOmni** in a monorepo-style setup. It covers: where tests live, how to run them, Windows/Linux command differences, how we isolate globals, sample tests for **Kernel base-URL auto-detection** and the **HTTP Router’s base-prefix normalization**, and patterns for writing more tests (maintenance, 405/OPTIONS, regex routes, etc.).

---

## 1) Repository layout & mental model

We develop CitOmni as multiple composer packages inside one workspace:

```
citomni/
├─ app-skeleton/         # Real app that consumes the packages via path repos
├─ kernel/               # citomni/kernel
├─ http/                 # citomni/http
└─ cli/                  # citomni/cli
```

Two common ways to run tests:

- **Option A (Umbrella runner, recommended):** Run PHPUnit **from `app-skeleton/`** and include each package’s `tests/` directory in `phpunit.xml`. This leverages the existing **path repositories** in `app-skeleton/composer.json` and avoids duplicating repository config per package.
- **Option B (Standalone package):** Run PHPUnit **inside a package** (e.g., `citomni/http`) by adding a `repositories` path to the other packages it depends on (e.g., kernel). This is handy if you want to publish packages independently.

---

## 2) Composer setup

### 2.1 app-skeleton (Umbrella)

`app-skeleton/composer.json` (excerpt):

```json
{
  "require": {
    "php": "^8.2",
    "citomni/kernel": "^1.0@dev",
    "citomni/http": "^1.0@dev",
    "citomni/cli": "^1.0@dev"
  },
  "repositories": [
    { "type": "path", "url": "../kernel", "options": { "symlink": true } },
    { "type": "path", "url": "../http",   "options": { "symlink": true } },
    { "type": "path", "url": "../cli",    "options": { "symlink": true } }
  ],
  "require-dev": {
    "phpunit/phpunit": "^10.5"
  },
  "autoload": {
    "psr-4": { "App\\": "src/" }
  },
  "config": { "optimize-autoloader": true, "apcu-autoloader": true },
  "minimum-stability": "dev",
  "prefer-stable": true
}
```

Create `app-skeleton/phpunit.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="tests/bootstrap.php" colors="true">
  <testsuites>
    <testsuite name="CitOmni Kernel">
      <directory>vendor/citomni/kernel/tests</directory>
    </testsuite>

    <testsuite name="CitOmni CLI">
      <directory>vendor/citomni/cli/tests</directory>
    </testsuite>

    <testsuite name="CitOmni HTTP">
      <directory>vendor/citomni/http/tests</directory>
    </testsuite>
  </testsuites>
</phpunit>

```

**Install / update:**

- Linux/macOS/Git Bash:
  ```bash
  cd app-skeleton
  composer update
  vendor/bin/phpunit
  ```
- Windows (CMD):
  ```bat
  cd app-skeleton
  composer update
  vendor\bin\phpunit.bat
  ```

> If Windows says `'vendor' is not recognized` use `vendor\bin\phpunit.bat` (CMD) or `./vendor/bin/phpunit` (PowerShell).

### 2.2 Standalone inside a package (optional)

If you want to run tests inside `citomni/http` without going through app-skeleton, add a path repo to kernel in `citomni/http/composer.json`:

```json
{
  "require": {
    "php": "^8.2",
    "citomni/kernel": "^1.0@dev",
    "larsgmortensen/liteview": "^1.0"
  },
  "require-dev": {
    "phpunit/phpunit": "^10.5"
  },
  "autoload": { "psr-4": { "CitOmni\\Http\\": "src/" } },
  "autoload-dev": { "psr-4": { "CitOmni\\Tests\\": "tests/" } },
  "repositories": [
    { "type": "path", "url": "../kernel", "options": { "symlink": true } }
  ],
  "minimum-stability": "dev",
  "prefer-stable": true
}
```

Create `citomni/http/phpunit.xml.dist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="vendor/autoload.php" colors="true">
  <testsuites>
    <testsuite name="CitOmni HTTP">
      <directory>tests</directory>
    </testsuite>
  </testsuites>
</phpunit>
```

Run inside `citomni/http`:

```bash
composer update
vendor/bin/phpunit
```

---

## 3) Where tests live

Each package keeps its own tests under `tests/`. For example:

```
citomni/http/
├─ src/
└─ tests/
   ├─ Http/
   │  └─ BaseUrlDetectionTest.php
   └─ Router/
      └─ BasePrefixNormalizationTest.php
```

Namespaces for test classes:

- `CitOmni\Tests\Http\BaseUrlDetectionTest`
- `CitOmni\Tests\Http\Router\BasePrefixNormalizationTest`

> Ensure `autoload-dev` maps `CitOmni\Tests\` -> `tests/` in the package(s).

---

## 4) Isolation: globals, constants & processes

A few PHPUnit attributes matter a lot for framework testing:

- `@RunInSeparateProcess` - Run the test in a separate PHP process. Use this whenever tests define constants (e.g., `CITOMNI_PUBLIC_ROOT_URL`) or modify `$_SERVER` in ways that might leak between tests.
- `@BackupGlobals(true)` - Back up and restore superglobals like `$_SERVER` between tests.
- `@DataProvider` - Table-driven tests for tricky matrixes (e.g., with/without `/public` webroot).

Example (top of a test class):

```php
#[RunInSeparateProcess]
#[BackupGlobals(true)]
```

With those in place, tests won’t interfere with each other.

---

## 5) Implemented tests (examples)

### 5.1 Kernel base-URL auto-detection strips trailing `/public`

**Goal:** When dev autodetection is used, if Apache/PHP report `SCRIPT_NAME` as `/.../public/index.php`, our detection must remove the trailing `/public` in the computed base URL.

`citomni/http/tests/Http/BaseUrlDetectionTest.php`

```php
<?php
declare(strict_types=1);

namespace CitOmni\Tests\Http;

use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class BaseUrlDetectionTest extends TestCase {
	#[RunInSeparateProcess]
	#[BackupGlobals(true)]
	public function testAutoDetectStripsTrailingPublic(): void {
		$_SERVER['HTTPS']       = 'off';
		$_SERVER['SERVER_PORT'] = '80';
		$_SERVER['HTTP_HOST']   = 'localhost';
		$_SERVER['SCRIPT_NAME'] = '/citomni/app-skeleton/public/index.php';
		$_SERVER['PHP_SELF']    = '/citomni/app-skeleton/public/index.php';

		$ref  = new \ReflectionClass(\CitOmni\Http\Kernel::class);
		$meth = $ref->getMethod('autoDetectBaseUrl');
		$meth->setAccessible(true);
		$base = $meth->invoke(null, false);

		$this->assertSame(
			'http://localhost/citomni/app-skeleton',
			$base,
			'autoDetectBaseUrl should remove trailing "/public".'
		);
	}
}
```

### 5.2 Router base-prefix normalization

**Goal:** Router should remove the base path (without `/public`) from `REQUEST_URI` so that `/` matches the home route - both when the webroot is the app-root with a root `.htaccess` rewrite and when the webroot is the `public/` folder.

`citomni/http/tests/Router/BasePrefixNormalizationTest.php`

```php
<?php
declare(strict_types=1);

namespace CitOmni\Tests\Http\Router;

use CitOmni\Http\Service\Router;
use CitOmni\Kernel\App;
use CitOmni\Kernel\Mode;
use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class DummyController {
	public function __construct(private App $app, private array $opts = []) {}
	public function index(): void { echo 'OK'; }
}

final class BasePrefixNormalizationTest extends TestCase {
	#[RunInSeparateProcess]
	#[BackupGlobals(true)]
	#[DataProvider('cases')]
	public function testRouterStripsBasePrefixAndMatchesHome(string $publicRootUrl, string $scriptName, string $requestUri): void {
		// Simulate environment
		$_SERVER['SCRIPT_NAME']    = $scriptName;
		$_SERVER['PHP_SELF']       = $scriptName;
		$_SERVER['REQUEST_URI']    = $requestUri;
		$_SERVER['REQUEST_METHOD'] = 'GET';

		if (!\defined('CITOMNI_PUBLIC_ROOT_URL')) {
			\define('CITOMNI_PUBLIC_ROOT_URL', $publicRootUrl);
		}
		if (!\defined('CITOMNI_ENVIRONMENT')) {
			\define('CITOMNI_ENVIRONMENT', 'dev');
		}

		// Create a temp /config with minimal HTTP cfg
		$base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'citomni_test_' . bin2hex(random_bytes(4));
		$configDir = $base . DIRECTORY_SEPARATOR . 'config';
		mkdir($configDir, 0777, true);

		file_put_contents($configDir . DIRECTORY_SEPARATOR . 'citomni_http_cfg.php', <<<'PHP'
<?php
return [
	'timezone' => 'Europe/Copenhagen',
	'charset'  => 'UTF-8',
	'http'     => ['trust_proxy' => false],
	'routes'   => [
		'/' => [
			'controller' => \CitOmni\Tests\Http\Router\DummyController::class,
			'action'     => 'index',
			'methods'    => ['GET'],
		],
	],
];
PHP);

		file_put_contents($configDir . DIRECTORY_SEPARATOR . 'services.php', "<?php\nreturn [];\n");

		$app = new App($configDir, Mode::HTTP);
		$router = new Router($app);

		ob_start();
		$router->run();
		$out = ob_get_clean();

		$this->assertSame('OK', $out, 'Home route "/" should be matched after base-prefix stripping.');

		// Cleanup (best effort)
		@unlink($configDir . DIRECTORY_SEPARATOR . 'citomni_http_cfg.php');
		@unlink($configDir . DIRECTORY_SEPARATOR . 'services.php');
		@rmdir($configDir);
		@rmdir($base);
	}

	public static function cases(): array {
		return [
			'webroot_is_app_root' => [
				'publicRootUrl' => 'http://localhost/citomni/app-skeleton',
				'scriptName'    => '/citomni/app-skeleton/public/index.php',
				'requestUri'    => '/citomni/app-skeleton/',
			],
			'webroot_is_public' => [
				'publicRootUrl' => 'http://localhost',
				'scriptName'    => '/index.php',
				'requestUri'    => '/',
			],
		];
	}
}
```

---

## 6) Writing more tests (patterns & examples)

### 6.1 Router behaviors to cover

- **Exact routes**
  ```php
  $routes = [
    '/' => [ 'controller' => HomeController::class, 'methods' => ['GET'] ],
    '/login.html' => [ 'controller' => AuthController::class, 'methods' => ['GET','POST'] ],
  ];
  ```

- **Regex/placeholder routes**
  ```php
  $routes['regex'] = [
    '/user/{id}' => [ 'controller' => UserController::class, 'methods' => ['GET'] ],
    '/email/{email}' => [ 'controller' => EmailController::class, 'methods' => ['GET'] ],
  ];
  ```
  Test that `/user/123` hits the route and that the captured `123` is passed to the action.

- **ASCII guard & 404 fallback**  
  Simulate a URI containing non-ASCII and assert 404 + error dispatcher invoked.

- **HEAD/OPTIONS conveniences**  
  For a route with `['GET']`, assert:
  - `HEAD` returns `200` or `204` (depending on your controller) and is **allowed**.
  - `OPTIONS` returns `204` with `Allow: GET, HEAD, OPTIONS`.

- **405 + Allow**  
  For a `POST`-only route, hitting it with `GET` should yield `405` + `Allow` header.

- **Error routes**  
  Provide `routes[404]`, `routes[500]` etc. and ensure the router dispatches them correctly. Also test reentrancy guard (if an error route fails once, a minimal fallback is produced).

### 6.2 Maintenance gate (HTTP-only)

Provide `maintenance.enabled = true` and an `allowed_ips` list; assert that:

- A non-allowlisted client gets `503` and a `Retry-After: <seconds>` header.
- An allowlisted IP (fake via request service stub, or by setting `$_SERVER`) passes through.

### 6.3 Controller tests

Controllers can be exercised end-to-end via the Router using a minimal config file. Provide a real `App` (as in the examples) and **dummy controllers** that echo or return known values. Keep the tests focused on routing/dispatch semantics rather than database or templates.

### 6.4 Test utilities (optional)

If you find yourself repeating setup code (creating temp config dirs, writing small cfg arrays, etc.), create a tiny test helper in `tests/_helpers/TestKit.php` (autoload-dev only) with functions like:

```php
function makeTempConfig(array $cfg, array $services = []): string { /* returns $configDir */ }
function cleanupTempConfig(string $configDir): void { /* rm files & dirs */ }
```

---

## 7) Coverage & performance

- **Coverage**: enable either **Xdebug** or **PCOV** locally and run:
  ```bash
  vendor/bin/phpunit --coverage-html build/coverage
  ```
- **Speed tips**:
  - Use `@RunInSeparateProcess` **only** when needed (constants, INI changes). We use it here because Kernel logic defines constants.
  - Keep fixtures small: write only the few keys you need in the temp cfg.
  - Prefer **Option A** (umbrella runner) so Composer’s autoloader is already optimized once at the app root.

---

## 8) CI pipeline example (GitHub Actions)

Create `.github/workflows/tests.yml` in `app-skeleton` repo:

```yaml
name: tests
on: [push, pull_request]
jobs:
  phpunit:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          coverage: none
      - name: Install dependencies
        working-directory: app-skeleton
        run: composer update --no-interaction --prefer-dist
      - name: Run tests
        working-directory: app-skeleton
        run: vendor/bin/phpunit --colors=always
```

> If you prefer per-package CI, add analogous workflows inside `citomni/http`, `citomni/kernel`, etc., with appropriate path repositories in each package’s `composer.json`.

---

## 9) Troubleshooting

- **`'vendor' is not recognized ...` on Windows**  
  Use `vendor\bin\phpunit.bat` (CMD) or `./vendor/bin/phpunit` (PowerShell).

- **Composer can’t find `citomni/kernel` when running inside `citomni/http`**  
  Add a `repositories: [{ type: "path", url: "../kernel" }]` entry to `citomni/http/composer.json`, or run tests from **app-skeleton** (Option A).

- **Constants already defined / cross-test pollution**  
  Add `#[RunInSeparateProcess]` and `#[BackupGlobals(true)]`.

- **Readonly properties prevent injection**  
  Don’t try to set `App::$cfg` after construction. Build a **real `App`** with a **temp `/config`** instead.

- **Links unexpectedly include `/public`**  
  Ensure you have the Kernel autodetect patch that strips trailing `/public`, and the Router base-prefix patch using `CITOMNI_PUBLIC_ROOT_URL`.

---

## 10) Style & conventions for tests

- **Namespaces:** `CitOmni\Tests\...` under `tests/`.
- **One assertion per behavior** where feasible; table-driven via `@DataProvider` for variations.
- **No side effects** in test fixtures: keep temporary files in `sys_get_temp_dir()` and clean up.
- **Comments in English** (aligns with the project’s documentation rule).
- **PSR-4** file layout; keep test classes in `tests/Feature|Unit|...` folders if you prefer that taxonomy.

---

## 11) Extensibility notes (re: final classes)

`App` being `final` doesn’t give meaningful performance benefits in PHP 8.2. If you prefer easier extension points for advanced tests, consider making `App` non-final and locking critical methods with `final`, while exposing small `protected` hooks (`buildConfig()`, `buildServices()`, etc.). If you keep `final`, use the **temp-config** pattern shown above to instantiate a real `App`.

---

## 12) Quick checklist

- [ ] PHPUnit installed in **app-skeleton** (`require-dev`).
- [ ] `phpunit.xml` includes `vendor/citomni/http/tests` (+ others as needed).
- [ ] Tests marked with `@RunInSeparateProcess` when defining constants / touching `$_SERVER`.
- [ ] Kernel autodetect test green (strips `/public`).
- [ ] Router base-prefix test green (home route matches under both webroot modes).
- [ ] Stage/Prod have absolute `http.base_url` in `citomni_http_cfg.{env}.php`.

That’s it. With this structure you can confidently evolve the Router/Kernel without regressions - and you can add package-specific or app-level tests incrementally with minimal boilerplate.

