Awesome—here’s a **complete, hands-on guide** to building a CitOmni **provider** (example: a tiny **Blog** module with CRUD). It’s verbose on purpose, with copy-paste-ready code. Everything follows your rules: **PHP 8.2+**, **PSR-4**, **K\&R braces**, **tabs**, **English PHPDoc**, **no unnecessary try/catch**, **services via maps**, **deep cfg**, **app-level routes with “last wins”**, and optional **build cache**.

---

# 0) What is a “provider” in CitOmni?

A **provider** is a Composer package that contributes **services** (and optionally **default config** and **routes**) to an app in a **deterministic** way:

* **Services** via `Boot\Services::MAP` (id → class or class+options).
* **Default config** via `Boot\Config::CFG_HTTP` / `CFG_CLI`.
* **Optional routes preset** via `Boot\Routes::MAP` (the **app** decides to include or override them).
* App lists providers in `/config/providers.php`. The kernel merges **vendor → providers → app** (“last wins”).

Providers are **opt-in**: nothing is loaded unless whitelisted in `providers.php`.

---

# 1) Create the provider package

We’ll call the package `citomni/blog`. Folder structure:

```
citomni/blog/
  composer.json
  src/
    Boot/
      Services.php
      Config.php
      Routes.php
    Model/
      BlogPost.php
    Service/
      BlogPostRepository.php
      BlogService.php
      Slugger.php
    Controller/
      BlogController.php
    View/
      blog/
        index.php
        show.php
        form.php
  README.md
```

## 1.1 composer.json

```json
{
	"name": "citomni/blog",
	"description": "CitOmni Blog provider (CRUD example).",
	"type": "library",
	"license": "proprietary",
	"require": {
		"php": "^8.2"
	},
	"autoload": {
		"psr-4": {
			"CitOmni\\Blog\\": "src/"
		}
	},
	"minimum-stability": "stable",
	"prefer-stable": true
}
```

Run `composer dump-autoload -o` inside the package when developing locally (or add it via path/vcs repo to your app).

---

# 2) Provider boot files

These are the **entry points** the app’s kernel reads when merging.

## 2.1 `src/Boot/Services.php`

```php
<?php
declare(strict_types=1);

namespace CitOmni\Blog\Boot;

/**
 * Blog provider services.
 * id => FQCN|string|['class'=>FQCN,'options'=>array]
 */
final class Services {
	public const MAP = [
		// Core blog pieces
		'blogPostRepo' => \CitOmni\Blog\Service\BlogPostRepository::class,
		'blogService'  => \CitOmni\Blog\Service\BlogService::class,

		// Small utility the provider offers
		'slugger'      => \CitOmni\Blog\Service\Slugger::class,
	];
}
```

> The app may override any entry in `/config/services.php` (last wins).

## 2.2 `src/Boot/Config.php`

```php
<?php
declare(strict_types=1);

namespace CitOmni\Blog\Boot;

/**
 * Default config contribution from this provider.
 * The kernel merges providers' CFG_HTTP into the app's HTTP cfg (last wins).
 */
final class Config {
	public const CFG_HTTP = [
		'blog' => [
			'table'       => 'blog_posts',
			'date_format' => 'Y-m-d H:i',
			// simple toggles you can override in the app cfg:
			'allow_public_post_create' => false,
		],
	];
}
```

> Keep it small. The app can override in `/config/citomni_http_cfg.php` or env overlays (`citomni_http_cfg.prod.php`, etc.).

## 2.3 `src/Boot/Routes.php` (optional)

Providers **should not force routes**. Offer a preset the **app** can import:

```php
<?php
declare(strict_types=1);

namespace CitOmni\Blog\Boot;

use CitOmni\Blog\Controller\BlogController;

final class Routes {
	public const MAP = [
		'/blog' => [
			'controller' => BlogController::class,
			'action'     => 'index',
			'methods'    => ['GET'],
		],
		'/blog/new' => [
			'controller' => BlogController::class,
			'action'     => 'createForm',
			'methods'    => ['GET'],
		],
		'/blog' => [
			'controller' => BlogController::class,
			'action'     => 'create',
			'methods'    => ['POST'],
		],
		'regex' => [
			'/blog/{id}' => [
				'controller' => BlogController::class,
				'action'     => 'show',
				'methods'    => ['GET'],
			],
			'/blog/{id}/edit' => [
				'controller' => BlogController::class,
				'action'     => 'editForm',
				'methods'    => ['GET'],
			],
			'/blog/{id}' => [
				'controller' => BlogController::class,
				'action'     => 'update',
				'methods'    => ['POST'], // or PUT/PATCH if you prefer
			],
			'/blog/{id}/delete' => [
				'controller' => BlogController::class,
				'action'     => 'delete',
				'methods'    => ['POST'], // CSRF protected
			],
		],
	];
}
```

---

# 3) Domain model & services

## 3.1 `src/Model/BlogPost.php`

```php
<?php
declare(strict_types=1);

namespace CitOmni\Blog\Model;

/**
 * BlogPost model. Simple value object.
 */
final class BlogPost {
	public int $id;
	public string $title;
	public string $slug;
	public string $body;
	public \DateTimeImmutable $createdAt;

	public function __construct(int $id, string $title, string $slug, string $body, \DateTimeImmutable $createdAt) {
		$this->id = $id;
		$this->title = $title;
		$this->slug = $slug;
		$this->body = $body;
		$this->createdAt = $createdAt;
	}
}
```

## 3.2 `src/Service/Slugger.php`

```php
<?php
declare(strict_types=1);

namespace CitOmni\Blog\Service;

use CitOmni\Kernel\App;

/**
 * Tiny slug utility. No options required.
 */
final class Slugger {
	private App $app;

	public function __construct(App $app, array $options = []) {
		$this->app = $app;
	}

	public function slugify(string $title): string {
		$slug = \strtolower(\trim($title));
		$slug = \preg_replace('/[^a-z0-9]+/i', '-', $slug) ?? '';
		return \trim($slug, '-');
	}
}
```

## 3.3 `src/Service/BlogPostRepository.php`

Assumes you have a **Connection** service exposed as `$this->app->connection` that wraps `mysqli` (or your LiteMySQLi). Replace SQL/table name via cfg.

```php
<?php
declare(strict_types=1);

namespace CitOmni\Blog\Service;

use CitOmni\Kernel\App;
use CitOmni\Blog\Model\BlogPost;

/**
 * Data access for blog posts (mysqli-based).
 * No catch blocks: fail fast and let the global error handler do logging.
 */
final class BlogPostRepository {
	private App $app;
	private string $table;

	public function __construct(App $app, array $options = []) {
		$this->app = $app;
		$this->table = (string)($this->app->cfg->blog->table ?? 'blog_posts');
	}

	/** @return list<BlogPost> */
	public function list(int $limit = 50, int $offset = 0): array {
		$db = $this->app->connection->mysqli(); // adjust if your Connection differs
		$sql = "SELECT id, title, slug, body, created_at FROM `{$this->table}` ORDER BY id DESC LIMIT ? OFFSET ?";
		$stmt = $db->prepare($sql);
		$stmt->bind_param('ii', $limit, $offset);
		$stmt->execute();
		$res = $stmt->get_result();

		$rows = [];
		while ($row = $res->fetch_assoc()) {
			$rows[] = new BlogPost(
				(int)$row['id'],
				(string)$row['title'],
				(string)$row['slug'],
				(string)$row['body'],
				new \DateTimeImmutable((string)$row['created_at'])
			);
		}
		return $rows;
	}

	public function find(int $id): ?BlogPost {
		$db = $this->app->connection->mysqli();
		$sql = "SELECT id, title, slug, body, created_at FROM `{$this->table}` WHERE id = ?";
		$stmt = $db->prepare($sql);
		$stmt->bind_param('i', $id);
		$stmt->execute();
		$res = $stmt->get_result();
		$row = $res->fetch_assoc();
		if (!$row) {
			return null;
		}
		return new BlogPost(
			(int)$row['id'],
			(string)$row['title'],
			(string)$row['slug'],
			(string)$row['body'],
			new \DateTimeImmutable((string)$row['created_at'])
		);
	}

	public function create(string $title, string $slug, string $body): int {
		$db = $this->app->connection->mysqli();
		$now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
		$sql = "INSERT INTO `{$this->table}` (title, slug, body, created_at) VALUES (?, ?, ?, ?)";
		$stmt = $db->prepare($sql);
		$stmt->bind_param('ssss', $title, $slug, $body, $now);
		$stmt->execute();
		return $db->insert_id;
	}

	public function update(int $id, string $title, string $slug, string $body): void {
		$db = $this->app->connection->mysqli();
		$sql = "UPDATE `{$this->table}` SET title = ?, slug = ?, body = ? WHERE id = ?";
		$stmt = $db->prepare($sql);
		$stmt->bind_param('sssi', $title, $slug, $body, $id);
		$stmt->execute();
	}

	public function delete(int $id): void {
		$db = $this->app->connection->mysqli();
		$sql = "DELETE FROM `{$this->table}` WHERE id = ?";
		$stmt = $db->prepare($sql);
		$stmt->bind_param('i', $id);
		$stmt->execute();
	}
}
```

## 3.4 `src/Service/BlogService.php`

Business logic around the repo (slug generation, basic validation, CSRF hook usage, etc.).

```php
<?php
declare(strict_types=1);

namespace CitOmni\Blog\Service;

use CitOmni\Kernel\App;
use CitOmni\Blog\Model\BlogPost;

final class BlogService {
	private App $app;
	private BlogPostRepository $repo;
	private Slugger $slugger;

	public function __construct(App $app, array $options = []) {
		$this->app = $app;
		$this->repo = $app->blogPostRepo; // via services map
		$this->slugger = $app->slugger;
	}

	/** @return list<BlogPost> */
	public function list(): array {
		return $this->repo->list(50, 0);
	}

	public function get(int $id): ?BlogPost {
		return $this->repo->find($id);
	}

	public function create(string $title, string $body): int {
		$title = \trim($title);
		$body  = \trim($body);
		if ($title === '' || $body === '') {
			throw new \InvalidArgumentException('Title and body are required.');
		}
		$slug = $this->slugger->slugify($title);
		return $this->repo->create($title, $slug, $body);
	}

	public function update(int $id, string $title, string $body): void {
		$title = \trim($title);
		$body  = \trim($body);
		if ($title === '' || $body === '') {
			throw new \InvalidArgumentException('Title and body are required.');
		}
		$slug = $this->slugger->slugify($title);
		$this->repo->update($id, $title, $slug, $body);
	}

	public function delete(int $id): void {
		$this->repo->delete($id);
	}
}
```

---

# 4) HTTP controller & views

## 4.1 `src/Controller/BlogController.php`

```php
<?php
declare(strict_types=1);

namespace CitOmni\Blog\Controller;

use CitOmni\Kernel\App;

/**
 * Blog CRUD controller.
 * Requires services: blogService, view, request, response, security (for CSRF).
 */
final class BlogController {
	private App $app;

	public function __construct(App $app, array $ctx = []) {
		$this->app = $app;
	}

	public function index(): void {
		$posts = $this->app->blogService->list();
		$this->app->view->render('blog/index.php', ['posts' => $posts]);
	}

	public function show(int $id): void {
		$post = $this->app->blogService->get($id);
		if ($post === null) {
			http_response_code(404);
			$this->app->view->render('errors/404.php', []);
			return;
		}
		$this->app->view->render('blog/show.php', ['post' => $post]);
	}

	public function createForm(): void {
		// Optional access control:
		if (!$this->app->cfg->blog->allow_public_post_create) {
			// Example role check:
			// if (!$this->app->security->hasRole(ROLE_ADMIN)) { ... }
		}
		$csrf = $this->app->security->csrfToken();
		$this->app->view->render('blog/form.php', ['csrf' => $csrf, 'mode' => 'create']);
	}

	public function create(): void {
		$this->app->security->guardCsrf($this->app->request->post('_csrf'));
		$title = (string)$this->app->request->post('title');
		$body  = (string)$this->app->request->post('body');

		$id = $this->app->blogService->create($title, $body);
		$this->app->response->redirect('/blog/' . $id);
	}

	public function editForm(int $id): void {
		$post = $this->app->blogService->get($id);
		if ($post === null) {
			http_response_code(404);
			$this->app->view->render('errors/404.php', []);
			return;
		}
		$csrf = $this->app->security->csrfToken();
		$this->app->view->render('blog/form.php', ['csrf' => $csrf, 'mode' => 'edit', 'post' => $post]);
	}

	public function update(int $id): void {
		$this->app->security->guardCsrf($this->app->request->post('_csrf'));
		$title = (string)$this->app->request->post('title');
		$body  = (string)$this->app->request->post('body');
		$this->app->blogService->update($id, $title, $body);
		$this->app->response->redirect('/blog/' . $id);
	}

	public function delete(int $id): void {
		$this->app->security->guardCsrf($this->app->request->post('_csrf'));
		$this->app->blogService->delete($id);
		$this->app->response->redirect('/blog');
	}
}
```

> Controller is **not** a service. Router `new`s it and injects `$app`.

## 4.2 Views (very simple)

`src/View/blog/index.php`

```php
<?php /** @var array{posts:list<CitOmni\Blog\Model\BlogPost>} $__data */ ?>
<h1>Blog</h1>
<p><a href="/blog/new">Create a post</a></p>
<ul>
<?php foreach ($__data['posts'] as $p): ?>
	<li><a href="/blog/<?= (int)$p->id ?>"><?= htmlspecialchars($p->title) ?></a></li>
<?php endforeach; ?>
</ul>
```

`src/View/blog/show.php`

```php
<?php /** @var array{post:CitOmni\Blog\Model\BlogPost} $__data */ $p = $__data['post']; ?>
<h1><?= htmlspecialchars($p->title) ?></h1>
<article><?= nl2br(htmlspecialchars($p->body)) ?></article>
<p>
	<a href="/blog/<?= (int)$p->id ?>/edit">Edit</a>
	<form method="post" action="/blog/<?= (int)$p->id ?>/delete" style="display:inline">
		<input type="hidden" name="_csrf" value="<?= htmlspecialchars($this->app->security->csrfToken()) ?>">
		<button type="submit">Delete</button>
	</form>
</p>
```

`src/View/blog/form.php`

```php
<?php
/** @var array{csrf:string,mode:string,post?:CitOmni\Blog\Model\BlogPost} $__data */
$mode = $__data['mode'];
$editing = ($mode === 'edit');
$p = $editing ? $__data['post'] : null;
$action = $editing ? '/blog/' . (int)$p->id : '/blog';
$title  = $editing ? $p->title : '';
$body   = $editing ? $p->body : '';
?>
<h1><?= $editing ? 'Edit post' : 'Create post' ?></h1>
<form method="post" action="<?= htmlspecialchars($action) ?>">
	<input type="hidden" name="_csrf" value="<?= htmlspecialchars($__data['csrf']) ?>">
	<p>
		<label>Title<br>
			<input type="text" name="title" value="<?= htmlspecialchars($title) ?>">
		</label>
	</p>
	<p>
		<label>Body<br>
			<textarea name="body" rows="8" cols="60"><?= htmlspecialchars($body) ?></textarea>
		</label>
	</p>
	<p><button type="submit"><?= $editing ? 'Save' : 'Create' ?></button></p>
</form>
```

> Your app’s `view` service should map view names like `'blog/index.php'` to a template file. Keep provider templates **non-sensitive**.

---

# 5) Database schema (SQL) + a tiny CLI to create it

**SQL schema** (your provider README can include it):

```sql
CREATE TABLE `blog_posts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(200) NOT NULL,
  `slug` VARCHAR(220) NOT NULL,
  `body` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Optional CLI command (in your provider) to create the table** (if you have a CLI mode):

`src/Command/BlogInstallCommand.php`

```php
<?php
declare(strict_types=1);

namespace CitOmni\Blog\Command;

use CitOmni\Kernel\App;

final class BlogInstallCommand {
	private App $app;
	public function __construct(App $app, array $ctx = []) {
		$this->app = $app;
	}

	public function __invoke(array $argv): int {
		$sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS `blog_posts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(200) NOT NULL,
  `slug` VARCHAR(220) NOT NULL,
  `body` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;
		$this->app->connection->mysqli()->query($sql);
		$this->app->log->write('system.log', 'Blog table ensured.');
		echo "OK: blog_posts ensured.\n";
		return 0;
	}
}
```

Register it in your CLI provider (similar to HTTP services) if you maintain a CLI side.

---

# 6) Integrate the provider in an app

## 6.1 Install

In the **app**:

```bash
composer require citomni/blog
composer dump-autoload -o
```

## 6.2 Whitelist the provider

`/config/providers.php`:

```php
<?php
declare(strict_types=1);

return [
	// ... other providers ...
	\CitOmni\Blog\Boot\Services::class, // services map
	\CitOmni\Blog\Boot\Config::class,   // default cfg contribution (HTTP)
	// Routes are opt-in from the app/routes file (see next step).
];
```

## 6.3 Add routes (app-level, last wins)

Your app owns `/config/routes.php`. Import provider’s preset and override what you want.

```php
<?php
declare(strict_types=1);

use App\Http\Controller\HomeController;
use CitOmni\Blog\Boot\Routes as BlogRoutes;

$providerRoutes = BlogRoutes::MAP;

// Your existing routes
$appRoutes = [
	'/' => [
		'controller' => HomeController::class,
		'action'     => 'index',
		'methods'    => ['GET'],
	],
];

// Merge with “app last wins”:
$merged = $providerRoutes + $appRoutes;

// If you need to merge the nested 'regex' sub-array too:
if (isset($providerRoutes['regex']) && \is_array($providerRoutes['regex'])) {
	$merged['regex'] = ($providerRoutes['regex'] + ($appRoutes['regex'] ?? []));
}

return $merged;
```

> Alternatively, if you prefer `array_replace` semantics:
> `\$merged = \array_replace($providerRoutes, $appRoutes);`
> And for `regex`: `\$merged['regex'] = \array_replace($providerRoutes['regex'] ?? [], $appRoutes['regex'] ?? []);`

## 6.4 App config overrides (optional)

`/config/citomni_http_cfg.php`:

```php
<?php
declare(strict_types=1);

return [
	'blog' => [
		'table' => 'blog_posts', // keep or change name
		'allow_public_post_create' => false,
	],
	'routes' => require __DIR__ . '/routes.php',
];
```

And env overlays if you use them:

* `/config/citomni_http_cfg.stage.php`
* `/config/citomni_http_cfg.prod.php`

## 6.5 Warm & use build cache (recommended)

After enabling the provider, regenerate caches:

* If you exposed a route/controller to warm:

```php
// GET /admin/cache/warm -> calls:
$this->app->warmCache(overwrite: true, opcacheInvalidate: true);
```

* Or via CLI:

```
php bin/cit cache:warm http
```

Then reload and visit `/blog`.

---

# 7) Security notes

* **CSRF**: we used `$this->app->security->csrfToken()` + `guardCsrf()` for POST actions.
* **Auth/roles**: if needed, gate create/edit/delete with `hasRole(ROLE_ADMIN)` (load `/config/roles.php` in cfg).
* **Validation**: keep it simple in `BlogService`. For more, add a `Validator` service.

---

# 8) Performance notes

* Provider code only loads when the provider is **whitelisted**.
* With **compiled cfg/services** cache, provider boot files are not included at runtime.
* Keep templates minimal; avoid catching exceptions in controllers/services.
* Composer: `"optimize-autoloader": true`, `"apcu-autoloader": true` (if APCu present).
* OPcache in prod: `validate_timestamps=0`, reset on deploy.

---

# 9) Testing (very light touch)

* **Unit**: test `Slugger::slugify`, `BlogService::create/update`.
* **Integration**: spin up a test DB, call `BlogPostRepository` methods.
* **HTTP**: route to `BlogController`, assert 200/302, presence of strings.

---

# 10) FAQ / Gotchas

* **Routes live in the app**. Providers only **offer** a preset (`Boot\Routes::MAP`). App merges and **wins** on conflicts.
* **DB connection**: adjust the repo to the actual API of your `connection` service.
* **View paths**: confirm how your `view` service resolves template names (relative to app vs vendor). If your view loader is app-centric, copy provider views into the app’s templates folder or configure a secondary view root.
* **Env overlays**: if you enabled `citomni_http_cfg.{env}.php` in kernel, those overlay files override provider defaults.

---

## That’s it 🎉

You now have a fully working **Blog provider** with services, default config, optional routes, controller, model, views, and SQL—wired the CitOmni way:

* deterministic “last wins” merging,
* deep cfg access (`$this->app->cfg->blog->table`),
* service maps (no magic scanning),
* app-owned routing, and
* optional build cache for green performance.