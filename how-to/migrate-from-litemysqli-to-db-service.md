# CitOmni DB Service - Migrating from LiteMySQLi (PHP 8.2+)

> **Low overhead. High performance. Predictable by design.**

This document explains how to migrate existing CitOmni applications from the legacy LiteMySQLi-based pattern to the framework-native `Db` service in `citomni/infrastructure`. It covers the architectural rationale, the practical migration steps, the behavioral differences that matter in production, and concrete before/after examples for common database operations.

* PHP >= 8.2
* MySQLi only
* No ORM
* No query builder
* No magic compatibility layer
* Fail fast by default

---

## 1) What this document covers

CitOmni now provides a first-class database service:

* `CitOmni\Infrastructure\Service\Db`
* `CitOmni\Infrastructure\Exception\DbException`
* `CitOmni\Infrastructure\Exception\DbConnectException`
* `CitOmni\Infrastructure\Exception\DbQueryException`

This service replaces the older LiteMySQLi-centered pattern as the preferred CitOmni approach.

This is not merely a rename from one class to another. It is a shift in architectural ownership:

* from a legacy external-style wrapper pattern
* to a framework-native infrastructure service
* resolved by the `App`
* configured through CitOmni conventions
* with explicit error boundaries and stricter operational contracts

LiteMySQLi remains useful as a historical baseline and migration reference. It is no longer the preferred path for new CitOmni code.

---

## 2) Why CitOmni is moving from LiteMySQLi to `Db`

The migration exists because CitOmni values explicit infrastructure ownership.

The old pattern worked, and in many applications it worked well. But it had a structural limitation: Database access was typically mediated through a model wrapper that privately instantiated and cached a LiteMySQLi connection. That made practical sense at the time, but it left the database layer sitting slightly outside CitOmni's first-class service model.

The new `Db` service fixes that by making database access a native infrastructure concern.

### The reasons are architectural, not cosmetic

CitOmni prefers:

* low overhead
* high performance
* deterministic behavior
* fail-fast contracts
* explicit ownership
* no magic

A first-class `Db` service aligns with those principles better than a legacy wrapper pattern because:

* configuration is validated once in `init()`
* connection remains lazy
* service lifetime is controlled by the `App`
* exceptions are explicit and semantically separated
* session-level DB initialization is centralized
* database access no longer depends on ad hoc wrapper inheritance

In other words: The database layer is now where it belongs - in infrastructure, not as an accidental side effect of a legacy access pattern.

### What this does not mean

It does **not** mean:

* that LiteMySQLi was unusable
* that every old model must be redesigned from scratch
* that the new service is a drop-in clone in every behavioral detail
* that CitOmni is moving toward an ORM, active record, or query builder

Quite the opposite. The new service keeps the deliberately narrow CitOmni stance:

* MySQLi only
* SQL-first
* explicit queries
* prepared statements
* minimal abstraction
* predictable runtime cost

That is a feature, not a missing roadmap.

---

## 3) Scope of the migration

### What changes

The preferred access path changes from a LiteMySQLi-backed wrapper model to the first-class service:

```php
$this->app->db
```

That means, in practice:

* old base wrappers such as `BaseModelLiteMySQLi` are no longer the preferred entry point
* repository/model/service code can use `$this->app->db` directly
* connection/session setup now belongs to the infrastructure service
* exceptions become more explicit
* some behaviors are intentionally stricter

### What does not change

The migration does **not** require changing your whole application style.

These things remain true:

* You still write SQL yourself.
* You still use prepared statements.
* You still work with arrays and scalars.
* You still have helper methods such as:

  * `fetchValue()`
  * `fetchRow()`
  * `fetchAll()`
  * `insert()`
  * `update()`
  * `delete()`
  * `execute()`
  * `executeMany()`
  * `insertBatch()`
* Connection remains lazy.
* Statement caching still exists and remains bounded.
* Batch operations still exist.
* `easyTransaction()` still exists as a backward-compatibility alias.

So the migration is architectural, but it is not a wholesale change in day-to-day coding style.

---

## 4) Old pattern overview

In real applications, the old LiteMySQLi approach was usually not used by manually instantiating `LiteMySQLi` all over the codebase. Instead, it typically looked more like this:

```php
<?php
declare(strict_types=1);

namespace CitOmni\Infrastructure\Model;

use CitOmni\Kernel\App;
use LiteMySQLi\LiteMySQLi;

abstract class BaseModelLiteMySQLi {
	protected App $app;
	private ?LiteMySQLi $conn = null;

	public function __construct(App $app) {
		$this->app = $app;
		if (\method_exists($this, 'init')) {
			$this->init();
		}
	}

	protected function establish(): LiteMySQLi {
		if ($this->conn instanceof LiteMySQLi) {
			return $this->conn;
		}

		$cfg = $this->app->cfg->db;

		$this->conn = new LiteMySQLi(
			(string)$cfg->host,
			(string)$cfg->user,
			(string)$cfg->pass,
			(string)$cfg->name,
			$cfg->charset ?? 'utf8mb4'
		);

		return $this->conn;
	}

	public function __get(string $name): mixed {
		if ($name !== 'db') {
			throw new \OutOfBoundsException("Unknown property: {$name}");
		}
		return $this->establish();
	}
}
```

### What that pattern did well

It gave you:

* lazy connection creation
* a convenient `$this->db` property inside descendants
* a single place to read DB config
* very little ceremony in actual query code

### What it did less well

It also meant:

* database access depended on a particular inheritance pattern
* connection ownership lived in a wrapper rather than a first-class infrastructure service
* configuration validation mostly happened at connection time
* exception semantics were generic MySQLi/LiteMySQLi behavior rather than explicit CitOmni contracts
* the actual framework-level database boundary was not clearly represented

That was serviceable. It was not ideal for the longer-term CitOmni architecture.

---

## 5) New pattern overview

The new preferred path is simple:

```php
$this->app->db
```

This is a regular CitOmni infrastructure service that lives in `citomni/infrastructure` and extends `BaseService`.

### Core characteristics

The `Db` service is:

* framework-native
* lazily connected
* config-aware
* validated during `init()`
* explicit about connection failures versus query failures
* intentionally narrow in scope

It is not:

* an ORM
* a query builder
* a schema tool
* a repository framework
* a bag of magic convenience layers

### Exception hierarchy

The service introduces a proper exception tree:

* `DbException`

  * shared base class
* `DbConnectException`

  * for config, connection, and session-initialization failures
* `DbQueryException`

  * for prepare, bind, execute, transaction, result, and query failures

That separation matters in real code because it lets you distinguish:

* infrastructure boot/connect problems
* from SQL/query/runtime problems

without inspecting a generic exception message and hoping for the best.

---

## 6) Architectural differences

This section matters more than method names. The methods are familiar. The ownership model is different.

### 6.1 Ownership and access path

**Old pattern**

* DB connection was typically owned by a wrapper base model.
* Access happened through `$this->db` inside wrapper descendants.

**New pattern**

* DB access is owned by the CitOmni infrastructure service layer.
* Access happens through `$this->app->db`.

This is the most important architectural shift in the migration.

### 6.2 Lifecycle

**Old pattern**

* Config was read when the wrapper established the connection.
* Connection lived inside the wrapper instance.

**New pattern**

* Config is read and validated in `init()`.
* Connection remains lazy and is only opened when needed.
* The service itself is resolved by the `App` like other CitOmni services.

This yields a better separation:

* configuration validation early
* network connection late

That is both practical and deterministic.

### 6.3 Config validation

The new `Db` service validates its configuration in `init()`.

Required config keys include:

* `host`
* `user`
* `pass`
* `name`

Optional values such as:

* `charset`
* `port`
* `socket`
* `connect_timeout`
* `sql_mode`
* `timezone`
* `statement_cache_limit`

are validated explicitly when present.

This matters because invalid configuration now fails fast with a `DbConnectException`, rather than waiting until a query happens to trigger connection creation.

### 6.4 Statement handling

Both the old and new implementations support prepared statements and a bounded statement cache.

The new `Db` service keeps that behavior but places it behind a first-class service contract.

Important practical points:

* cached prepared statements are reused by SQL string
* cache size is bounded
* cache size `0` disables statement caching
* uncached statements are explicitly closed
* statement result cleanup is attempted before reuse

So the performance-oriented spirit remains intact.

### 6.5 Transaction semantics

The new service is intentionally stricter around transactions.

It provides:

* `beginTransaction()`
* `commit()`
* `rollback()`
* `transaction(callable $callback)`
* `easyTransaction(callable $callback)` as a compatibility alias

The stricter part is this:

* `commit()` and `rollback()` do **not** open a new lazy connection
* they require an already-open connection
* if no active connection exists, they fail explicitly

This is deliberate. Silent lazy connection creation during `commit()` or `rollback()` would be operationally misleading.

### 6.6 Fail-fast behavior

The new service is stricter about invalid operations.

Examples include:

* empty SQL in `select()`, `execute()`, and `queryRaw()`
* empty `WHERE` in `exists()`, `update()`, and `delete()`
* invalid identifier names
* invalid batch shapes
* invalid config values

That strictness is intentional. It helps surface mistakes early, rather than allowing vague or dangerous behavior to slip through.

---

## 7) Configuration expectations

The DB config uses `pass`, not `password`.

A typical configuration shape looks like this:

```php
<?php
return [
	'db' => [
		'host' => '127.0.0.1',
		'user' => 'app_user',
		'pass' => 'secret',
		'name' => 'app_db',
		'charset' => 'utf8mb4',
		'port' => 3306,
		'connect_timeout' => 5,
		'statement_cache_limit' => 128,
		// Optional:
		// 'socket' => '/var/run/mysqld/mysqld.sock',
		// 'sql_mode' => 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION',
		// 'timezone' => '+01:00',
	],
];
```

### Notes

* `pass` is the expected key.
* `charset` defaults to `utf8mb4`.
* `port` defaults to `3306`.
* `connect_timeout` defaults to `5`.
* `statement_cache_limit` defaults to `128`.

### Session initialization

On first real connection, the service may apply session settings such as:

* `sql_mode`
* `time_zone`

If an explicit DB `timezone` is configured, that is used.
Otherwise, the service attempts to derive a MySQL offset from PHP's default timezone.

If session initialization fails, that is treated as a connection/session-init failure and surfaced as `DbConnectException`.

---

## 8) Before/after migration examples

The examples below use realistic CitOmni-style code. They focus on the migration shape, not on any particular package layout.

### 8.1 Fetch one value

#### Before

```php
public function getUserEmail(int $userId): ?string {
	return $this->db->fetchValue(
		'SELECT email FROM users WHERE id = ? LIMIT 1',
		[$userId]
	);
}
```

#### After

```php
public function getUserEmail(int $userId): ?string {
	$value = $this->app->db->fetchValue(
		'SELECT email FROM users WHERE id = ? LIMIT 1',
		[$userId]
	);

	return $value !== null ? (string)$value : null;
}
```

### 8.2 Fetch one row

#### Before

```php
public function getUserById(int $userId): ?array {
	return $this->db->fetchRow(
		'SELECT id, email, status FROM users WHERE id = ? LIMIT 1',
		[$userId]
	);
}
```

#### After

```php
public function getUserById(int $userId): ?array {
	return $this->app->db->fetchRow(
		'SELECT id, email, status FROM users WHERE id = ? LIMIT 1',
		[$userId]
	);
}
```

### 8.3 Fetch all rows

#### Before

```php
public function getActiveUsers(): array {
	return $this->db->fetchAll(
		'SELECT id, email FROM users WHERE status = ? ORDER BY id ASC',
		['active']
	);
}
```

#### After

```php
public function getActiveUsers(): array {
	return $this->app->db->fetchAll(
		'SELECT id, email FROM users WHERE status = ? ORDER BY id ASC',
		['active']
	);
}
```

### 8.4 Insert one row

#### Before

```php
public function createUser(array $data): int {
	return $this->db->insert('users', [
		'email' => $data['email'],
		'status' => $data['status'],
		'created_at' => $data['created_at'],
	]);
}
```

#### After

```php
public function createUser(array $data): int {
	return $this->app->db->insert('users', [
		'email' => $data['email'],
		'status' => $data['status'],
		'created_at' => $data['created_at'],
	]);
}
```

### 8.5 Update rows

#### Before

```php
public function markUserInactive(int $userId): int {
	return $this->db->update(
		'users',
		['status' => 'inactive'],
		'id = ?',
		[$userId]
	);
}
```

#### After

```php
public function markUserInactive(int $userId): int {
	return $this->app->db->update(
		'users',
		['status' => 'inactive'],
		'id = ?',
		[$userId]
	);
}
```

### 8.6 Delete rows

#### Before

```php
public function deleteExpiredTokens(string $cutoff): int {
	return $this->db->delete(
		'auth_tokens',
		'expires_at < ?',
		[$cutoff]
	);
}
```

#### After

```php
public function deleteExpiredTokens(string $cutoff): int {
	return $this->app->db->delete(
		'auth_tokens',
		'expires_at < ?',
		[$cutoff]
	);
}
```

### 8.7 Execute custom SQL

#### Before

```php
public function touchLastSeen(int $userId, string $time): int {
	return $this->db->execute(
		'UPDATE users SET last_seen_at = ? WHERE id = ?',
		[$time, $userId]
	);
}
```

#### After

```php
public function touchLastSeen(int $userId, string $time): int {
	return $this->app->db->execute(
		'UPDATE users SET last_seen_at = ? WHERE id = ?',
		[$time, $userId]
	);
}
```

### 8.8 Transaction

#### Before

```php
public function createUserWithProfile(array $user, array $profile): int {
	$userId = 0;

	$this->db->easyTransaction(function($db) use ($user, $profile, &$userId): void {
		$db->insert('users', $user);
		$userId = $db->lastInsertId();

		$profile['user_id'] = $userId;
		$db->insert('user_profiles', $profile);
	});

	return $userId;
}
```

#### After

```php
public function createUserWithProfile(array $user, array $profile): int {
	return (int)$this->app->db->transaction(function(\CitOmni\Infrastructure\Service\Db $db) use ($user, $profile): int {
		$userId = $db->insert('users', $user);

		$profile['user_id'] = $userId;
		$db->insert('user_profiles', $profile);

		return $userId;
	});
}
```

### 8.9 Backward-compatible transaction alias

If you are migrating incrementally and want minimal diff noise first, this still works:

```php
$this->app->db->easyTransaction(function(\CitOmni\Infrastructure\Service\Db $db): void {
	$db->execute('UPDATE counters SET value = value + 1 WHERE name = ?', ['jobs']);
});
```

That said, new code should prefer `transaction()` because it expresses the real API more clearly.

### 8.10 Batch execute

#### Before

```php
public function assignRoles(array $rows): int {
	$sql = 'INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)';
	return $this->db->executeMany($sql, $rows);
}
```

Where `$rows` might look like:

```php
[
	[10, 2],
	[10, 3],
	[11, 2],
]
```

#### After

```php
public function assignRoles(array $rows): int {
	$sql = 'INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)';
	return $this->app->db->executeMany($sql, $rows);
}
```

### 8.11 Batch insert by associative rows

#### Before

```php
public function insertAuditRows(array $rows): int {
	return $this->db->insertBatch('audit_log', $rows);
}
```

#### After

```php
public function insertAuditRows(array $rows): int {
	return $this->app->db->insertBatch('audit_log', $rows);
}
```

With input such as:

```php
[
	[
		'event_type' => 'login',
		'user_id' => 10,
		'created_at' => '2026-03-07 10:00:00',
	],
	[
		'event_type' => 'logout',
		'user_id' => 10,
		'created_at' => '2026-03-07 12:00:00',
	],
]
```

---

## 9) A realistic migration shape in application code

The simplest migration path is often to keep your repository/model classes and change only the access path.

### Before

```php
abstract class BaseModelLiteMySQLi {
	protected App $app;
	private ?LiteMySQLi $conn = null;

	public function __construct(App $app) {
		$this->app = $app;
	}

	public function __get(string $name): mixed {
		if ($name !== 'db') {
			throw new \OutOfBoundsException("Unknown property: {$name}");
		}
		return $this->establish();
	}
}
```

```php
final class UserRepository extends BaseModelLiteMySQLi {
	public function getByEmail(string $email): ?array {
		return $this->db->fetchRow(
			'SELECT id, email, status FROM users WHERE email = ? LIMIT 1',
			[$email]
		);
	}
}
```

### After

```php
final class UserRepository {
	public function __construct(private \CitOmni\Kernel\App $app) {
	}

	public function getByEmail(string $email): ?array {
		return $this->app->db->fetchRow(
			'SELECT id, email, status FROM users WHERE email = ? LIMIT 1',
			[$email]
		);
	}
}
```

This is often the cleanest destination:

* no legacy DB wrapper inheritance
* no private connection slot in the repository
* no artificial `$this->db` property indirection
* explicit use of the infrastructure service

That said, if you need an intermediate step, you can temporarily keep a lightweight wrapper that proxies to `$this->app->db`. More on that in the migration strategy section.

---

## 10) Exception handling changes

One of the biggest practical improvements is exception clarity.

### Old world

With LiteMySQLi, failures generally surfaced as:

* `\mysqli_sql_exception`
* `\InvalidArgumentException`
* or generic exception behavior from surrounding code

That was workable, but semantically broad.

### New world

With `Db`, failures are categorized:

* `DbConnectException`

  * invalid DB config
  * connection failure
  * session init failure
  * connect timeout setup problems
* `DbQueryException`

  * invalid SQL usage
  * prepare failures
  * bind failures
  * execute failures
  * result handling failures
  * transaction failures
* `DbException`

  * shared base if you want one catch point

### Practical recommendation

Catch narrowly when you genuinely need different handling.

```php
try {
	$user = $this->app->db->fetchRow(
		'SELECT id, email FROM users WHERE id = ?',
		[$userId]
	);
} catch (\CitOmni\Infrastructure\Exception\DbConnectException $e) {
	// Infrastructure problem: config, connection, session init.
	throw $e;
} catch (\CitOmni\Infrastructure\Exception\DbQueryException $e) {
	// Query problem: SQL, params, execution, transaction, etc.
	throw $e;
}
```

In many cases, you should not catch either and should instead let the global error handler do its job.

That remains the CitOmni default philosophy.

### A useful middle ground

If you want one boundary for application code, catch `DbException`:

```php
try {
	$count = $this->app->db->fetchValue('SELECT COUNT(*) FROM users');
} catch (\CitOmni\Infrastructure\Exception\DbException $e) {
	// Database-layer failure, regardless of subtype.
	throw $e;
}
```

---

## 11) Behavioral differences and caveats

This section is intentionally candid. The new service is similar in spirit, but it is not a drop-in clone in every operational detail.

### 11.1 `commit()` and `rollback()` are strict

The new `Db` service does not let `commit()` or `rollback()` lazily create a connection.

That means this will fail:

```php
$this->app->db->commit();
```

if no connection has been opened yet.

This is intentional. A transaction finalizer should not silently open a fresh connection and pretend that it is finishing previous transactional work. That would be misleading.

### 11.2 Empty `WHERE` is rejected in helper methods

The new service explicitly rejects empty `WHERE` clauses in:

* `exists()`
* `update()`
* `delete()`

This is stricter than ad hoc SQL-building habits and deliberately so. It prevents a class of accidental full-table operations.

### 11.3 Empty SQL is rejected

Methods such as:

* `select()`
* `execute()`
* `queryRaw()`
* `selectNoMysqlnd()`
* `executeMany()`

validate that SQL is not empty.

Again, that is deliberate fail-fast behavior.

### 11.4 `insertBatch()` fallback and transaction guard behavior

`insertBatch()` may use one of two strategies:

* a single large multi-row `INSERT`
* a chunked fallback using transactional execution

If the batch is too large for the single-INSERT path and the service is already inside a transaction, the method refuses to auto-start its own fallback transaction and throws a `DbQueryException`.

This is intentional. Nested transactional assumptions are precisely where silent convenience tends to become operational confusion.

If you need full control in that situation:

* manage the transaction explicitly yourself
* chunk rows yourself
* call `executeMany()` directly

### 11.5 Raw SQL still exists, but it is an escape hatch

The new service still provides:

* `queryRaw()`
* `queryRawMulti()`

These are useful when you genuinely need lower-level control.

They are not an invitation to abandon prepared statements casually.

Use them as escape hatches, not as your default style.

### 11.6 mysqlnd vs no-mysqlnd paths

The service supports both:

* `select()` for environments with mysqlnd and `get_result()`
* `selectNoMysqlnd()` for environments where mysqlnd is unavailable

That is useful, but you should treat `selectNoMysqlnd()` as the compatibility path, not the default ergonomic path.

If your deployment environment has mysqlnd, `fetchValue()`, `fetchRow()`, `fetchAll()`, and `select()` are the normal route.

### 11.7 Safe defaults before first connection

Metadata-style methods such as:

* `lastInsertId()`
* `affectedRows()`
* `getLastError()`
* `getLastErrorCode()`

return safe defaults before the first connection exists.

That means, before first connect, you may see:

* `0`
* `null`

rather than an exception.

This is intentional and practical.

### 11.8 Exception messages do not expose raw parameter values

When SQL/query failures are wrapped, exception messages may include:

* the SQL text
* parameter type information such as `[int, string, bool]`

They do **not** include raw parameter values.

This matters for production safety:

* sensitive values are not leaked into logs
* debugging still gets enough context to identify the query shape

That trade-off is deliberate and sensible.

---

## 12) Performance notes

This migration was not justified by theory alone. Practical benchmarking matters.

### Observed result

Practical benchmarks showed:

* no meaningful regression
* and in several runs a small advantage for the new `Db` service

That is the right kind of result:

* no architectural tax worth worrying about
* and occasionally a modest win

### How to interpret that

Do not over-read micro-benchmarks.

In real database code, the dominant cost is usually:

* network round-trips
* server execution time
* index quality
* result size
* transaction design

not the tiny difference between one thin wrapper shape and another.

So the main performance conclusion is:

* the new service does not introduce meaningful runtime penalty
* the architectural gains come without sacrificing the low-overhead goal
* query design and schema quality still matter much more than micro-ceremony

That is exactly where a framework should land.

---

## 13) Recommended migration strategy

The safest migrations are incremental.

### Stage 1: Introduce the new service and verify config

First ensure that:

* the `Db` service is available as `$this->app->db`
* your DB config is valid
* the config uses `pass`
* the application boots cleanly

Do this before touching repositories/models.

### Stage 2: Migrate call sites with low behavioral risk

Start with the easy wins:

* `fetchValue()`
* `fetchRow()`
* `fetchAll()`
* `execute()`
* `insert()`
* `update()`
* `delete()`

These usually migrate with very small diffs.

### Stage 3: Migrate transaction-heavy code carefully

Then review code that uses:

* `beginTransaction()`
* `commit()`
* `rollback()`
* `easyTransaction()`
* `insertBatch()`
* `executeMany()`

This is where the intentionally stricter semantics matter most.

### Stage 4: Remove legacy wrapper inheritance

Once the query code is stable, remove classes such as `BaseModelLiteMySQLi` from the preferred path.

The clean endpoint is usually:

* repositories/models receive `App`
* they use `$this->app->db`
* no wrapper base class exists solely to vend a DB handle

### Stage 5: Tighten exception handling only where justified

Do not scatter `try/catch` everywhere just because the exception types are better now.

Instead:

* let failures bubble by default
* add narrow catches only at genuine application boundaries
* use `DbException` when one shared DB boundary is enough
* use `DbConnectException` versus `DbQueryException` only when the distinction changes behavior

### A temporary compatibility bridge

If you want to reduce churn during rollout, you can keep a very thin transitional base class:

```php
abstract class BaseModelDb {
	protected \CitOmni\Kernel\App $app;

	public function __construct(\CitOmni\Kernel\App $app) {
		$this->app = $app;
	}

	public function __get(string $name): mixed {
		if ($name !== 'db') {
			throw new \OutOfBoundsException("Unknown property: {$name}");
		}

		return $this->app->db;
	}
}
```

This can be a useful stepping stone.

It should not be the final architectural destination unless you deliberately want that style.

---

## 14) Common mistakes during migration

### Mistake 1: Treating the migration as a blind search-and-replace

Changing:

* `LiteMySQLi`
* to `Db`

is not enough by itself.

You should also review:

* transaction assumptions
* exception handling
* empty `WHERE` behavior
* config validation expectations

### Mistake 2: Assuming `commit()` or `rollback()` can lazily connect

They cannot. That is intentional.

If your code relied on vague transaction state, fix the code rather than trying to preserve the ambiguity.

### Mistake 3: Forgetting that the config key is `pass`

The new service expects:

```php
'pass' => 'secret'
```

not:

```php
'password' => 'secret'
```

### Mistake 4: Relying on permissive empty helper inputs

The new service rejects dangerous empties such as:

* empty SQL
* empty `WHERE`

That is a good thing. Adjust your calling code accordingly.

### Mistake 5: Catching `\Throwable` everywhere

The new exception tree exists to improve precision, not to encourage broader catch-all patterns.

CitOmni still prefers:

* explicit contracts
* narrow catches
* fail fast by default

### Mistake 6: Logging exception messages as if parameter values were present

The wrapped query exceptions intentionally include:

* SQL text
* parameter type list

not raw parameter values.

That is safer, but it also means your old debugging habit of reading literal input values from exception text no longer applies.

If you need request-level correlation, add it at your logging boundary. Do not weaken DB exception hygiene.

### Mistake 7: Assuming `insertBatch()` can do anything inside any transaction context

For large payloads, `insertBatch()` may need a transactional fallback.
If you are already in a transaction, that may be refused.

This is not a bug. It is the service preventing unclear transactional behavior.

---

## 15) FAQ

### Q: Is `Db` a drop-in replacement for LiteMySQLi?

Not completely.

The method set is intentionally familiar, and many calls migrate almost mechanically. But the service is not presented as a byte-for-byte behavioral clone. Some behaviors are stricter on purpose, especially around:

* config validation
* empty SQL / empty `WHERE`
* transaction finalization
* batch fallback behavior
* exception semantics

Treat it as a migration with continuity, not as a promise of invisible substitution.

### Q: Do I need to rewrite all repositories/models?

No.

In many cases you can migrate incrementally:

1. keep the existing class
2. replace `$this->db` usage with `$this->app->db`
3. remove the legacy DB wrapper later

### Q: Should repositories/models still receive `App`?

In the current CitOmni approach described here, yes, that is the straightforward access path:

```php
$this->app->db
```

That keeps DB access aligned with the first-class infrastructure service model.

### Q: Is LiteMySQLi deprecated or forbidden?

This document does not claim that LiteMySQLi is forbidden everywhere. It remains useful as:

* historical reference
* migration baseline
* understanding of previous app behavior

It is simply no longer the preferred CitOmni path.

### Q: Does the new service add heavy abstraction?

No.

It is still:

* MySQLi-based
* SQL-first
* explicit
* small in surface area
* intentionally free of ORM/query-builder layers

### Q: Does `easyTransaction()` still exist?

Yes.

It exists as a backward-compatibility alias for `transaction()`.

New code should prefer `transaction()`, but `easyTransaction()` is there to help reduce migration friction.

### Q: What should I catch?

Usually nothing at the query site.

Let the global error handler handle failures unless you have a real boundary that needs different behavior.

When you do need catching:

* catch `DbConnectException` for infrastructure/connect problems
* catch `DbQueryException` for SQL/query/transaction problems
* catch `DbException` if one DB boundary is enough

### Q: Why include SQL text in exceptions but not parameter values?

Because that gives a useful debugging signal without leaking sensitive runtime data into logs.

That is the right trade-off for production systems.

---

## 16) Migration checklist

Use this as a practical rollout list.

* [ ] Ensure `CitOmni\Infrastructure\Service\Db` is available as `$this->app->db`.
* [ ] Verify DB config keys: `host`, `user`, `pass`, `name`.
* [ ] Review optional config keys: `charset`, `port`, `socket`, `connect_timeout`, `sql_mode`, `timezone`, `statement_cache_limit`.
* [ ] Migrate low-risk read helpers first:

  * [ ] `fetchValue()`
  * [ ] `fetchRow()`
  * [ ] `fetchAll()`
* [ ] Migrate write helpers:

  * [ ] `insert()`
  * [ ] `update()`
  * [ ] `delete()`
  * [ ] `execute()`
* [ ] Review transaction-heavy code:

  * [ ] `beginTransaction()`
  * [ ] `commit()`
  * [ ] `rollback()`
  * [ ] `transaction()`
  * [ ] `easyTransaction()`
* [ ] Review batch-heavy code:

  * [ ] `executeMany()`
  * [ ] `insertBatch()`
* [ ] Remove or phase out `BaseModelLiteMySQLi`.
* [ ] Replace generic database catches with explicit `Db*Exception` catches only where genuinely needed.
* [ ] Verify logging and error handling assumptions:

  * [ ] SQL text may be present
  * [ ] parameter values are not exposed
  * [ ] parameter types may be present
* [ ] Test mysqlnd-dependent code paths if your environment varies.
* [ ] Validate that strict empty-SQL and empty-`WHERE` checks do not break legacy calling code.
* [ ] Re-run performance checks on realistic workloads, not just micro-benchmarks.

---

## 17) Closing note

The move from LiteMySQLi to `Db` is best understood as an infrastructure correction.

It keeps the good parts of the old style:

* explicit SQL
* low ceremony
* prepared statements
* bounded statement caching
* lazy connection

while bringing database access into CitOmni's proper architectural center:

* first-class service ownership
* explicit config validation
* clearer exceptions
* stricter runtime contracts
* no hidden magic

That is the real value of the migration.

LiteMySQLi helped establish the baseline. The new `Db` service is the framework-native path forward. It is not dramatically different in day-to-day usage, and that is part of its success. The architecture improves, the migration remains manageable, and production code stays boring in the best possible sense.
