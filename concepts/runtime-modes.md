# Runtime Modes - CitOmni Application Kernel

*How one CitOmni application boots, configures, and dispatches in HTTP and in CLI.*

---

## Document metadata

* **Packages:** `citomni/kernel`, `citomni/http`, `citomni/cli`
* **Key types:** `CitOmni\Kernel\Mode`, `CitOmni\Kernel\App`, `CitOmni\Http\Kernel`, `CitOmni\Cli\Kernel`
* **PHP version:** >= 8.5
* **Audience:** Framework developers, provider authors, and integrators
* **Status:** Stable
* **Document type:** Technical architecture

---

## 1. Introduction

A **runtime mode** is the execution context an `App` instance is built for. CitOmni has two:

* **HTTP mode:** A web request, handled by the front controller `public/index.php`.
* **CLI mode:** A command, run as a PHP process through `bin/citomni`.

The mode decides which mode package supplies the baseline, which application files take part in the merge, which dispatch map is built (routes or commands), which services exist, which error handler is installed, and which cache files are read and written.

An application is not an HTTP app or a CLI app. One app root with one `/config` directory normally runs in both modes, through its two entry points. Each `App` instance has exactly one mode, fixed at construction. One process may hold `App` instances of both modes; `cache:warm` builds the HTTP caches from the CLI that way.

This document describes the mode layer as implemented in `citomni/kernel` and the two mode packages, `citomni/http` and `citomni/cli`.

---

## 2. The `Mode` enum

```php
namespace CitOmni\Kernel;

enum Mode: string {
	case HTTP = 'http';
	case CLI  = 'cli';
}
```

The mode is passed to the `App` constructor and cannot change afterwards:

```php
$app = new \CitOmni\Kernel\App(CITOMNI_APP_PATH . '/config', \CitOmni\Kernel\Mode::CLI);
```

* **The entry point chooses the mode.** `\CitOmni\Http\Kernel` constructs its `App` with `Mode::HTTP`, and `\CitOmni\Cli\Kernel` with `Mode::CLI`. Neither `App` nor the two kernels derive the mode from `PHP_SAPI`. PHP's built-in development server (`php -S`, SAPI `cli-server`) runs `public/index.php` in HTTP mode, and a CLI process can construct an `App` in `Mode::HTTP`.
* **`App` does not expose its mode.** The mode is a private property without an accessor. Code that must behave differently per mode is registered per mode (`MAP_HTTP`, `MAP_CLI`, `ROUTES_HTTP`, `COMMANDS_CLI`) or checks a capability such as `$app->hasService('request')` (Section 7).
* **Each mode needs its own mode package.** `Mode::HTTP` reads `\CitOmni\Http\Boot\Registry`, and `Mode::CLI` reads `\CitOmni\Cli\Boot\Registry`. Neither mode references the other package, so an application may install only `citomni/http` or only `citomni/cli`.

---

## 3. What a mode determines

| Aspect | HTTP mode | CLI mode |
| --- | --- | --- |
| Entry point | `public/index.php` | `bin/citomni` |
| Kernel call | `\CitOmni\Http\Kernel::run(__DIR__)` | `\CitOmni\Cli\Kernel::run(CITOMNI_APP_PATH . '/config', $_SERVER['argv'] ?? [])` |
| Mode package | `citomni/http` | `citomni/cli` |
| Baseline class | `\CitOmni\Http\Boot\Registry` | `\CitOmni\Cli\Boot\Registry` |
| Baseline constants | `CFG_HTTP`, `MAP_HTTP`, `ROUTES_HTTP` | `CFG_CLI`, `MAP_CLI`, `COMMANDS_CLI` |
| App config files of the mode | `citomni_http_cfg.php`, `citomni_http_cfg.{ENV}.php` | `citomni_cli_cfg.php`, `citomni_cli_cfg.{ENV}.php` |
| App service file of the mode | `services_http.php` | `services_cli.php` |
| Dispatch map | `$app->routes`, keyed by path, with regex routes under `regex` | `$app->commands`, keyed by command name |
| App dispatch files | `citomni_http_routes.php`, `citomni_http_routes.{ENV}.php` | `citomni_cli_commands.php`, `citomni_cli_commands.{ENV}.php` |
| Map of the other mode | `$app->commands` is `[]` | `$app->routes` is `[]` |
| Dispatcher service | `router` (`CitOmni\Http\Service\Router`) | `runner` (`CitOmni\Cli\Service\Runner`) |
| Adapter base class | `CitOmni\Kernel\Controller\BaseController` | `CitOmni\Kernel\Command\BaseCommand` |
| Error handler (`errorHandler`) | `CitOmni\Http\Service\ErrorHandler` | `CitOmni\Cli\Service\ErrorHandler` |
| Failure output | Error page or JSON response; the request ends with `exit(0)` | Plain text on STDERR; the process ends with `exit(1)` |
| Error logs | `http_err_*.jsonl`, `http_router_*.jsonl`, `http_request.jsonl` | `cli_err_*.jsonl` |
| PHP intl extension | Required; boot throws without it | Optional; the ICU locale is set when intl is loaded |
| Cache files | `var/cache/cfg.http.php`, `var/cache/routes.http.php`, `var/cache/services.http.php` | `var/cache/cfg.cli.php`, `var/cache/commands.cli.php`, `var/cache/services.cli.php` |

The log directory is `error_handler.log.path`. The HTTP baseline sets it to `var/logs`; the CLI baseline leaves it empty, which the CLI handler reads as `var/logs`. Exceptions, PHP errors, and shutdown fatals go to the `*_err_*.jsonl` files of each mode. In HTTP, errors raised through `httpError()` go to `http_router_404.jsonl`, `http_router_405.jsonl`, `http_router_5xx.jsonl`, or `http_router_other.jsonl`, and request rejections such as the 413 of Section 4.2 go to `http_request.jsonl`.

Shared by both modes:

* `App`, `Cfg`, `Arr`, `Runtime`, and the base classes of `citomni/kernel`.
* The common app files `providers.php`, `citomni_cfg.php`, `citomni_cfg.{ENV}.php`, and `services.php`.
* The provider constants `CFG_COMMON` and `MAP_COMMON`.
* Operations, repositories, services, and support classes that do not depend on the services of one mode (Section 7).

---

## 4. Entry points and boot sequences

### 4.1 Entry-point constants

The entry point defines a few constants before it hands over to a kernel. `App` and the mode packages read them; only `CITOMNI_PUBLIC_ROOT_URL` can also be defined by a kernel.

| Constant | Defined by | Purpose |
| --- | --- | --- |
| `CITOMNI_APP_PATH` | Both entry points; required | App root. `App` derives its cache paths and `getAppRoot()` from it. |
| `CITOMNI_ENVIRONMENT` | Both entry points | Selects the `{ENV}` overlays (Section 5). When it is not defined, `App` uses `prod`. |
| `CITOMNI_PUBLIC_PATH` | `public/index.php`; the scaffolded `bin/citomni` as well | Absolute path to `/public`, read by services such as `upload` and by shared code. |
| `CITOMNI_PUBLIC_ROOT_URL` | The entry point, or the HTTP kernel | Absolute public base URL without a trailing slash. In HTTP mode the kernel defines it when the entry point did not (Section 4.2, step 2.6). In CLI mode only the entry point defines it. The scaffolded `bin/citomni` defines it in `stage` and `prod` from the installer placeholders `STAGE_ROOT_URL` and `PROD_ROOT_URL`, and leaves it undefined in `dev`. |
| `CITOMNI_START_NS` | Both entry points; optional | `hrtime(true)` at entry. The HTTP `?_perf` footer measures from it. |

Each entry point defines its own `CITOMNI_ENVIRONMENT`. `citomni/installer` materializes `public/index.php` and `bin/citomni` for the same recorded environment, and `citomni-installer environment <env>` switches both. A hand-edited entry point can still disagree with the other one; Section 6.4 shows where a mismatch hurts.

### 4.2 HTTP boot

`public/index.php`, as scaffolded for production, without its comments and with an example URL:

```php
<?php
declare(strict_types=1);

define('CITOMNI_START_NS', hrtime(true));
define('CITOMNI_ENVIRONMENT', 'prod');
define('CITOMNI_PUBLIC_PATH', __DIR__);
define('CITOMNI_APP_PATH', \dirname(__DIR__));
define('CITOMNI_PUBLIC_ROOT_URL', 'https://www.example.com');

require __DIR__ . '/../vendor/autoload.php';

\CitOmni\Http\Kernel::run(__DIR__);
```

`\CitOmni\Http\Kernel::run(string $entryPath, array $opts = []): void` accepts the `public/` directory, the `config/` directory, or the app root. `$opts` is reserved and unused.

1. Start one top-level output buffer with implicit flush off, so the error handler can replace a partial response.
2. `Kernel::boot()`:
   1. Resolve the app root, the config directory, and the public path from the entry path.
   2. `new App($configDir, Mode::HTTP)`: Load cfg, routes, and services from the caches, or build them (Sections 5 and 6).
   3. `$app->errorHandler->install()`.
   4. `Runtime::configure($app->cfg)`: Timezone (`locale.timezone`, default `UTC`), default charset (`locale.charset`, default `UTF-8`), and ICU locale (`locale.icu_locale`, default `en_US`, only when intl is loaded).
   5. Throw `\RuntimeException` when the intl extension is missing.
   6. Define `CITOMNI_PUBLIC_ROOT_URL` when the entry point did not. In `dev` it is an absolute `http.base_url` or detected from the request. In every other environment an absolute `http.base_url` is required, and boot throws without one.
   7. Pass `http.trusted_proxies` to the `request` service when it is an array.
3. Reject a POST whose declared `Content-Length` exceeds `post_max_size` with status 413, through `errorHandler->httpError()`.
4. `$app->maintenance->guard()`.
5. `$app->router->run()`. Status 404 and 405 go through the error handler.
6. With `?_perf` in the query string, append an HTML comment with elapsed time, memory, and included-file count. Outside `prod` it also lists the included files.

### 4.3 CLI boot

`bin/citomni`, as scaffolded for production, without its comments and with an example URL:

```php
<?php
declare(strict_types=1);

define('CITOMNI_START_NS', hrtime(true));
define('CITOMNI_ENVIRONMENT', 'prod');
define('CITOMNI_PUBLIC_PATH', \dirname(__DIR__) . '/public');
define('CITOMNI_APP_PATH', \dirname(__DIR__));
define('CITOMNI_PUBLIC_ROOT_URL', 'https://www.example.com');

require CITOMNI_APP_PATH . '/vendor/autoload.php';

\CitOmni\Cli\Kernel::run(CITOMNI_APP_PATH . '/config', $_SERVER['argv'] ?? []);
```

`citomni/installer` materializes `bin/citomni` per environment, like `public/index.php`. The `stage` variant takes `CITOMNI_PUBLIC_ROOT_URL` from `STAGE_ROOT_URL` instead of `PROD_ROOT_URL`, and the `dev` variant does not define it.

Run it as `php bin/citomni <command>`. `\CitOmni\Cli\Kernel::run(string $configDir, array $argv): never` takes the config directory explicitly; there is no path resolution as in HTTP.

1. `Kernel::boot()`:
   1. `new App($configDir, Mode::CLI)`: Load cfg, commands, and services from the caches, or build them.
   2. `$app->errorHandler->install()`, when an `errorHandler` service is registered.
   3. `Runtime::configure($app->cfg)`, as in HTTP.
2. `$app->runner->run($argv)`. `$argv[1]` names the command. Without a command, or with `list`, the runner prints the command list and returns 0. An unknown command writes to STDERR and returns 2.
3. `exit()` with the returned code: `0` for success, `1` for failure, `2` for a usage error (`BaseCommand::SUCCESS`, `FAILURE`, and `USAGE`). Uncaught exceptions and fatal errors end the process with `1` through the CLI error handler.

The CLI boot has no output buffering, no maintenance guard, no public URL resolution, no trusted proxies, and no intl requirement.

The order matters when boot fails. In both modes, the error handler is a service of the `App`, so a failure while the `App` is constructed (for example a malformed `providers.php`) happens before any error handler is installed. Both modes install the error handler before they call `Runtime::configure()`, so an exception from `Runtime::configure()`, such as an invalid `locale.timezone`, goes through the error handler.

### 4.4 Booting without dispatch

Both kernels expose the boot step on its own:

* `\CitOmni\Http\Kernel::boot(string $entryPath, array $opts = []): App` runs step 2 of Section 4.2.
* `\CitOmni\Cli\Kernel::boot(string $configDir): App` runs step 1 of Section 4.3.

Both change process-wide state: They apply `Runtime::configure()` and install a global error handler, and the HTTP boot defines `CITOMNI_PUBLIC_ROOT_URL`. `new App($configDir, $mode)` does none of this. It only loads or builds cfg, the dispatch map, and the services, which is what tests, scripts, and cache tooling usually need.

---

## 5. How `App` assembles a mode

`App` holds three artifacts. Each one is loaded from its cache file when present (Section 6) and otherwise built from the sources below.

| Artifact | Exposed as | Merge rule | Environment overlay |
| --- | --- | --- | --- |
| Configuration | `$app->cfg`, a read-only `Cfg` | Deep associative merge, last wins | Yes |
| Dispatch map | `$app->routes` (HTTP) or `$app->commands` (CLI) | Deep associative merge, last wins | Yes |
| Service map | `$app->{id}`, lazy singletons | PHP array union per service id, later source wins | No |

### 5.1 Application files

| File in `/config` | Read in | Scaffold source |
| --- | --- | --- |
| `providers.php` | Both modes | `citomni/kernel` |
| `citomni_cfg.php`, `citomni_cfg.{ENV}.php` | Both modes | `citomni/kernel` |
| `services.php` | Both modes | `citomni/kernel` |
| `citomni_http_cfg.php`, `citomni_http_cfg.{ENV}.php` | HTTP | `citomni/http` |
| `citomni_http_routes.php`, `citomni_http_routes.{ENV}.php` | HTTP | `citomni/http` |
| `services_http.php` | HTTP | `citomni/http` |
| `citomni_cli_cfg.php`, `citomni_cli_cfg.{ENV}.php` | CLI | `citomni/cli` |
| `citomni_cli_commands.php`, `citomni_cli_commands.{ENV}.php` | CLI | `citomni/cli` |
| `services_cli.php` | CLI | `citomni/cli` |

`{ENV}` is the value of `CITOMNI_ENVIRONMENT`, or `prod` when it is not defined. The kernel does not validate the value. The scaffolds, installed by `citomni/installer`, create `dev`, `stage`, and `prod` variants.

Every file is optional at runtime, and a missing file is skipped. A file that exists must return the expected type. Configuration files return an array, an object, or a `Traversable`; `providers.php`, dispatch files, and service files return an array. Anything else throws `\RuntimeException`.

### 5.2 Configuration

Sources in merge order. Later sources win.

| Step | Source | HTTP | CLI |
| --- | --- | --- | --- |
| 1 | Mode baseline | `\CitOmni\Http\Boot\Registry::CFG_HTTP` | `\CitOmni\Cli\Boot\Registry::CFG_CLI` |
| 2 | Each provider, in `providers.php` order | `CFG_COMMON`, then `CFG_HTTP` | `CFG_COMMON`, then `CFG_CLI` |
| 3 | App common base | `citomni_cfg.php` | `citomni_cfg.php` |
| 4 | App mode base | `citomni_http_cfg.php` | `citomni_cli_cfg.php` |
| 5 | App common environment overlay | `citomni_cfg.{ENV}.php` | `citomni_cfg.{ENV}.php` |
| 6 | App mode environment overlay | `citomni_http_cfg.{ENV}.php` | `citomni_cli_cfg.{ENV}.php` |

Merge rules of `Arr::mergeAssocLastWins()`:

* Associative arrays merge recursively, key by key.
* Lists are replaced as a whole and never concatenated.
* Empty values (`''`, `0`, `false`, `null`, `[]`) are valid overrides and win.
* Every source passes through `Arr::normalizeConfig()` first. Objects and `Traversable`s become arrays; enum cases stay enum cases.

`$app->cfg` is read-only. A direct read throws `\OutOfBoundsException` at the first missing key, at any depth. `??` and `isset()` check every node on the path and never throw on a missing key. Own cfg, which the package's Registry ships or the app defines in its own config files, is read directly; app cfg read by a package, another package's cfg, and a path present in one mode only are read with `?? <default>`:

```php
// Inside citomni/authenticate, which ships authenticate.reauth.max_age_seconds:
$maxAge = (int)$this->app->cfg->authenticate->reauth->max_age_seconds;

// locale.timezone is app cfg; a package reads it with a default.
$timezone = (string)($this->app->cfg->locale->timezone ?? 'UTC');
```

See [Reading cfg](../how-to/services-authoring-registration-usage.md#reading-cfg) for the full rule.

### 5.3 Dispatch maps

HTTP dispatches routes; CLI dispatches commands. There is no shared dispatch constant: No `ROUTES_COMMON`, no `COMMANDS_COMMON`, and no `ROUTES_CLI`.

| Step | Source | HTTP routes | CLI commands |
| --- | --- | --- | --- |
| 1 | Mode baseline | `\CitOmni\Http\Boot\Registry::ROUTES_HTTP` | `\CitOmni\Cli\Boot\Registry::COMMANDS_CLI` |
| 2 | Each provider, in `providers.php` order | `ROUTES_HTTP` | `COMMANDS_CLI` |
| 3 | App base | `citomni_http_routes.php` | `citomni_cli_commands.php` |
| 4 | App environment overlay | `citomni_http_routes.{ENV}.php` | `citomni_cli_commands.{ENV}.php` |

Dispatch maps use the merge of Section 5.2, so an override merges into the entry it overrides:

* A later entry with the same path or command name merges into the earlier entry, and keys it omits survive. A provider that sets only `controller` and `action` for `/` keeps the `methods`, `template_file`, and `template_layer` of the `citomni/http` route; an app that sets only `command` for `cache:warm` keeps the `description` of the `citomni/cli` command.
* Lists such as `methods` are replaced.
* A whole source that returns `[]` is skipped and never wipes earlier entries.
* Inside a source, `[]` is an ordinary override and replaces what was merged before it. `'regex' => []` in an app routes file drops every regex route merged before it, including those of providers.
* A provider constant or app file that is not an array throws `\RuntimeException`.

The map of the other mode is always empty: `$app->commands` is `[]` in HTTP mode, and `$app->routes` is `[]` in CLI mode.

The shape of an entry belongs to its dispatcher. See the [Router reference](../reference/router-reference-light.md) for routes and the [`citomni/cli` README](https://github.com/citomni/cli#readme) for commands.

### 5.4 Service maps

Precedence, highest first:

| Precedence | HTTP | CLI |
| --- | --- | --- |
| 1 | `services_http.php` | `services_cli.php` |
| 2 | `services.php` | `services.php` |
| 3 | `MAP_HTTP` of the last provider | `MAP_CLI` of the last provider |
| 4 | `MAP_COMMON` of the last provider | `MAP_COMMON` of the last provider |
| ... | Earlier providers, in the same pattern | Earlier providers, in the same pattern |
| Lowest | `\CitOmni\Http\Boot\Registry::MAP_HTTP` | `\CitOmni\Cli\Boot\Registry::MAP_CLI` |

The kernel applies each source as `$map = $source + $map;`. The source applied later sits on the left of the union and wins:

* A later provider in `providers.php` overrides an earlier one. Within one provider, the mode map overrides `MAP_COMMON`.
* A definition replaces the earlier definition for the same id as a whole. `options` do not merge across sources.
* Service maps have no environment overlay. Behavior that differs per environment belongs in cfg.
* A provider constant or app file that is not an array throws `\RuntimeException`.

A definition is an FQCN string or `['class' => FQCN, 'options' => [...]]`. The `App` creates a service on first access, as `new $class($app)` or `new $class($app, $options)`, and keeps it for its own lifetime.

### 5.5 Providers

Packages contribute to a mode through providers. `config/providers.php` returns an ordered list of provider class names, normally `Boot\Registry` classes, and every listed class must exist. The kernel reads only constants from a provider class, and every constant is optional:

| Constant | Read in | Merged into |
| --- | --- | --- |
| `CFG_COMMON` | Both modes | Configuration, before the mode constant of the same provider |
| `CFG_HTTP`, `CFG_CLI` | Its mode | Configuration |
| `MAP_COMMON` | Both modes | Service map, below the mode constant of the same provider |
| `MAP_HTTP`, `MAP_CLI` | Its mode | Service map |
| `ROUTES_HTTP` | HTTP | Route table |
| `COMMANDS_CLI` | CLI | Command table |

The mode packages own the baselines; there is no vendor `CFG_COMMON` or `MAP_COMMON`. A package that reads a cfg path in both modes ships its default in `CFG_COMMON`, and a service used in both modes goes in `MAP_COMMON` instead of being copied into both mode maps.

An illustrative provider that contributes to both modes:

```php
<?php
declare(strict_types=1);

namespace Acme\Reports\Boot;

class Registry {

	// Defaults for cfg paths the package reads in both modes.
	public const CFG_COMMON = [
		'reports' => [
			'retention_days' => 30,
		],
	];

	// Defaults that only HTTP reads.
	public const CFG_HTTP = [
		'reports' => [
			'page_size' => 50,
		],
	];

	// Services available in both modes.
	public const MAP_COMMON = [
		'reportStore' => \Acme\Reports\Service\ReportStore::class,
	];

	// HTTP dispatch.
	public const ROUTES_HTTP = [
		'/reports' => [
			'controller' => \Acme\Reports\Controller\ReportController::class,
			'action'     => 'index',
			'methods'    => ['GET'],
		],
	];

	// CLI dispatch.
	public const COMMANDS_CLI = [
		'reports:build' => [
			'command'     => \Acme\Reports\Command\BuildReportsCommand::class,
			'description' => 'Build the daily reports.',
		],
	];
}
```

---

## 6. Compiled caches

### 6.1 Files

| Mode | Configuration | Dispatch map | Service map |
| --- | --- | --- | --- |
| HTTP | `var/cache/cfg.http.php` | `var/cache/routes.http.php` | `var/cache/services.http.php` |
| CLI | `var/cache/cfg.cli.php` | `var/cache/commands.cli.php` | `var/cache/services.cli.php` |

The paths are relative to `CITOMNI_APP_PATH`. Each file is a PHP script that returns the merged array, written with `var_export()`.

### 6.2 Loading

* The constructor loads the three files independently. A missing file, or one that does not return an array, is a cache miss, and only that artifact is built from its sources.
* With all three files present, construction reads those three files and nothing else, apart from enum classes their values reference. It does not read `providers.php`, load any `Registry` class, or include any app config file.
* Cache files are not checked against their sources. A cache built from older sources is used as is until it is warmed again or cleared, also after changes to `/config`, to `providers.php`, or to `Registry` constants in a `composer update`.
* Cache file names carry the mode, not the environment. A cache warmed for `prod` is used as is when the same app root runs as `dev`.
* The three files of a mode can come from different warm runs.
* A cache file that fails while it is included is not a cache miss. A syntax error throws `\ParseError`, and a reference to an enum class or case that no longer exists throws `\Error`.

### 6.3 Warming and clearing

`App::warmCache(bool $overwrite = true, bool $opcacheInvalidate = true, ?string $env = null): array` writes the three files of its own mode. `App::clearCache(bool $opcacheInvalidate = true): array` removes them. Both return `['cfg' => ?string, 'dispatch' => ?string, 'services' => ?string]` with the paths written or removed, and `null` for a file skipped or not present. Neither touches the files of the other mode.

* All three arrays are built before the first write, so a build error leaves the existing files untouched.
* Each file is replaced atomically through a temporary file and `rename()`. The set of three is not: When a write fails, the files written before it stay.
* With `$overwrite = false`, an existing file is skipped.
* `$env` builds cfg and the dispatch map for another environment than `CITOMNI_ENVIRONMENT`. The service map has no environment overlay, so `$env` does not affect it.
* With `$opcacheInvalidate`, each file is invalidated in the OPcache of the calling SAPI only. A CLI process cannot reach the OPcache of the web server (PHP-FPM, mod_php).

### 6.4 Operating the caches of both modes

`citomni/cli` ships `cache:warm` and `cache:clear`. They handle the CLI caches through the running CLI `App`, and the HTTP caches through a second `App` that they construct in `Mode::HTTP` from the same `/config`.

```bash
php bin/citomni cache:warm --env=prod
php bin/citomni cache:warm --mode=http --json
php bin/citomni cache:clear --mode=cli
```

`--mode` is `all` (the default), `cli`, or `http`. With `all`, the CLI caches come first and the HTTP caches follow when `citomni/http` is installed. `--env` exists for `cache:warm` only and defaults to `CITOMNI_ENVIRONMENT`, or `prod` when it is not defined.

* **Environment mismatch:** Without `--env`, `cache:warm` builds the HTTP caches for the environment of the CLI process. Entry points materialized by `citomni/installer` agree on that environment. When `bin/citomni` was edited by hand and says another environment than `public/index.php`, the HTTP caches get that environment's overlays, and the web server uses them as is. Pass `--env` in that case.
* **HTTP config in a CLI process:** Building the HTTP caches evaluates the HTTP config files of the app in the CLI process. A config file that needs `CITOMNI_PUBLIC_ROOT_URL` fails there in `dev`, because the scaffolded `bin/citomni` defines it only outside `dev` (Section 7).
* **Literal paths:** Config values derived from `CITOMNI_APP_PATH` are written into the cache as literal paths. Warm the caches on the target host, at the deployed path.
* **Web server OPcache:** With `opcache.validate_timestamps=0`, reload PHP-FPM or the web server after warming from the CLI. Alternatively, `POST /_system/reset-cache`, which removes the HTTP caches and resets OPcache inside the web SAPI, and warm again. `POST /_system/warmup-cache` warms the HTTP caches inside the web SAPI. Both routes are part of the `citomni/http` baseline and are protected by `webhooksAuth`.
* **OPcache in the CLI:** The CLI SAPI uses OPcache only with `opcache.enable_cli=1`. Without it, the CLI caches still skip the merge, but PHP compiles them in every process.

A deploy step that warms both modes:

```bash
composer install --no-dev --optimize-autoloader
php bin/citomni cache:warm --mode=all --env=prod
```

The [`citomni/cli` README](https://github.com/citomni/cli#cache-operations) and the [`citomni/kernel` README](https://github.com/citomni/kernel#caches) cover permissions, recovery from broken caches, and JSON output.

---

## 7. Writing code for both modes

* **Shared logic sits below the adapters.** Controllers and commands belong to one mode; operations, repositories, services, and support classes do not. `app:info` in CLI and `/_system/appinfo.json` in HTTP are both thin adapters over `CitOmni\Kernel\Support\AppInfo`. See [Architecture](./architecture.md).
* **Services exist per mode.** A service registered only in `MAP_HTTP` does not exist in CLI mode: `$app->request` throws `\RuntimeException` with the message `Unknown app component: app->request`. Register shared services in `MAP_COMMON` or `services.php`, and check optional ones with `$app->hasService()`.
* **Cfg trees differ per mode.** The HTTP baseline ships `http`, `error_handler`, `session`, `cookie`, `security`, `view`, `maintenance`, `webhooks`, and `nonce`. The CLI baseline ships only `error_handler`. In CLI mode, `$app->cfg->session` throws `\OutOfBoundsException` unless a provider or the app adds `session`. Shared code reads such paths with `??`.
* **Capability helpers see only the current mode.** `hasService()`, `hasAnyService()`, `hasPackage()`, and `hasNamespace()` inspect the service map and dispatch map of the current `App`. A package that contributes only CLI commands is invisible to `hasPackage()` in HTTP mode.
* **`CITOMNI_PUBLIC_ROOT_URL` exists in HTTP only after boot.** The HTTP kernel defines it in step 2.6 when the entry point did not. Config files are evaluated earlier, in step 2.2 when no cfg cache exists, so they can rely on the constant only where the entry point defines it, and the `dev` scaffolds of `public/index.php` and `bin/citomni` do not. In CLI mode it exists only when `bin/citomni` defines it, so shared code that builds absolute URLs depends on the entry point.
* **Long-running CLI processes catch per iteration.** The CLI error handler treats every exception that reaches it as fatal and ends the process with exit code 1. A worker that must survive a failed item catches the exception inside its loop.
* **Long work leaves HTTP.** An HTTP request runs to completion inside one script execution. `citomni/jobrunner` enqueues a job from HTTP and executes it in a CLI process through `bin/citomni`. See [JobRunner execution ownership](./JobRunner-execution-ownership.md).

---

## 8. Why two modes

### 8.1 Two execution shapes

CitOmni runs in two execution shapes, and each mode matches one of them:

| | HTTP mode | CLI mode |
| --- | --- | --- |
| Unit of work | One request per script execution | One command per process |
| Input | Superglobals: Server variables, query, body, cookies, files | `argv` |
| Output | Status line, headers, body | STDOUT, STDERR, exit code |
| Failure | Error page or JSON response, logged; the request ends | Message on STDERR, logged; exit code 1 |
| Typical host | PHP-FPM behind a web server, Apache mod_php, `php -S` in development | Shell, cron, deploy scripts, worker processes |

The shapes differ in input, output, failure semantics, and lifecycle, which is why each has its own kernel, dispatcher, and error handler. Everything else is built inside one of them. JSON APIs, webhooks, and HTML pages are HTTP routes. Scheduled tasks, imports, deploy steps, and workers are CLI commands. Capabilities that both need are services, operations, or providers.

### 8.2 Why not a third mode

A new mode needs a new execution shape: Its own input model, output model, failure semantics, and entry point. A provider cannot supply those, which is why providers extend the two modes and never define new ones. No third mode is planned.

### 8.3 Limits of the HTTP kernel

The HTTP kernel is written for the shared-nothing model of PHP. It defines `CITOMNI_PUBLIC_ROOT_URL` as a process constant, the `request` service reads superglobals, services live as long as the `App`, and the error handler ends the request with `exit(0)`. Persistent application servers that serve many requests per process, such as Swoole, RoadRunner, or FrankenPHP in worker mode, do not fit that model. Supporting one would take a different HTTP adapter, and it would still be HTTP mode, not a new one.

---

## 9. Design principles

* **Determinism:** The same mode, sources, provider order, and environment produce the same configuration, dispatch map, and service map.
* **Data-only composition:** The merge reads class constants and PHP files that return arrays. It does not instantiate providers, run provider code, scan directories, or use reflection. Without caches it does include `providers.php` and the app files, and it loads the `Registry` classes through the autoloader.
* **Explicit mode selection:** The entry point names the mode. Nothing detects it.
* **Mode isolation:** Each mode reads only its own mode package, its own app files, and its own cache files. Sharing is explicit, through `citomni_cfg.php`, `services.php`, `CFG_COMMON`, and `MAP_COMMON`.
* **Caches are a performance tool:** With warm caches, construction reads three files. They are not a correctness mechanism; keeping them current is the job of the deploy step.

---

## 10. Summary

CitOmni has two runtime modes because it runs in two execution shapes. The entry point declares the mode, an `App` keeps it for its lifetime, and the mode selects the baseline, the app files, the dispatch map, the services, the error handler, and the cache files. Everything else is shared.

> *Two modes, one philosophy: Explicit, deterministic, fast.*
> CitOmni does not guess. It knows.

---

## Related documents

* [Architecture](./architecture.md): Layers, adapters, and wiring.
* [Provider packages](./services-and-providers.md) and [Build a provider](../how-to/providers-build-a-provider.md): Provider design.
* [Routing layer](./routing-layer-http-runtime.md) and [Router reference](../reference/router-reference-light.md): HTTP dispatch.
* [Maintenance mode](./maintenance-mode.md): The maintenance guard in the HTTP boot.
* [JobRunner execution ownership](./JobRunner-execution-ownership.md): Work started in HTTP and run in CLI.
* Package READMEs: [`citomni/kernel`](https://github.com/citomni/kernel#readme), [`citomni/http`](https://github.com/citomni/http#readme), [`citomni/cli`](https://github.com/citomni/cli#readme).
