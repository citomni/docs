# CitOmni Db Service - Usage and Query Patterns (PHP 8.2+)

> **Low overhead. High performance. Explicit by design.**

This document explains how to use CitOmni’s first-class `Db` service for production-grade MySQL access. It covers how the service is resolved, how configuration is read, how reads and writes are executed, how transactions behave, where the escape hatches are, and where the sharp edges are.

`Db` exists to provide a single, explicit, low-overhead database layer for CitOmni applications and provider packages. It wraps MySQLi directly. It does not provide an ORM, a query builder, a repository abstraction, automatic retries, magical reconnect logic, or “helpful” hidden behavior. That is intentional.

* PHP ≥ 8.2
* PSR-1 / PSR-4
* Tabs for indentation, **K&R** brace style
* English PHPDoc and inline comments
* Fail fast by default; use try/catch only when recovery is explicit and meaningful

---

## 1) What the Db service is

CitOmni’s database service is:

* A first-class service in `citomni/infrastructure`
* Implemented as `CitOmni\Infrastructure\Service\Db`
* Accessed via `$this->app->db`
* Built on **MySQLi only**
* Based on **prepared statements** by default
* Designed for **lazy connection**, **bounded statement caching**, and **deterministic cleanup**

It is for:

* Executing parameterized SQL safely and cheaply
* Performing common read and write operations with minimal ceremony
* Handling transactions explicitly
* Running efficiently on real-world hosting, including ordinary shared hosting

It is not for:

* Object mapping
* Query generation
* Cross-database portability
* Hiding SQL from the developer
* Reconstructing an application architecture inside the DB layer

If you already know the SQL you want to run, `Db` is designed to let you run it directly, consistently, and without adding framework theater.

---

## 2) How the service is accessed

The service is resolved through the `App` like any other CitOmni service:

```php
$row = $this->app->db->fetchRow(
	'SELECT id, email FROM users WHERE id = ?',
	[$userId]
);
```

**Resolution model**

* `$this->app->db` resolves through the service map
* The service is instantiated **once per request/process**
* The same instance is reused for the lifetime of that request/process
* The constructor contract exists for the service resolver, not for ordinary application code

In other words:

* You **do** use `$this->app->db`
* You **do not** manually `new Db(...)` in normal application code

That matters because the service lifecycle, config consumption, and lazy connection behavior are designed around framework-managed resolution.

---

## 3) Configuration model and validation

The service reads its configuration from:

```php
$this->app->cfg->db
```

Config is read and validated in `init()`, but the physical database connection is still **lazy**. That means:

* Missing or invalid DB config fails fast during service initialization
* The actual TCP/socket connection is **not** opened until the first operation that needs it

### Required keys

The following keys are required:

* `host`
* `user`
* `pass`
* `name`

Example:

```php
<?php
declare(strict_types=1);

return [
	'db' => [
		'host' => '127.0.0.1',
		'user' => 'app_user',
		'pass' => 'secret',
		'name' => 'app_db',
	],
];
```

### Optional keys

The service also supports:

* `charset`
  Defaults to `utf8mb4`
* `port`
  Defaults to `3306`
* `socket`
  Optional
* `connect_timeout`
  Defaults to `5`
* `sql_mode`
  Optional explicit session `sql_mode`
* `timezone`
  Optional explicit session `time_zone`
* `statement_cache_limit`
  Defaults to `128`; `0` disables statement caching

Example with explicit session policy:

```php
<?php
declare(strict_types=1);

return [
	'db' => [
		'host' => '127.0.0.1',
		'user' => 'app_user',
		'pass' => 'secret',
		'name' => 'app_db',
		'charset' => 'utf8mb4',
		'port' => 3306,
		'connect_timeout' => 5,
		'sql_mode' => 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION',
		'timezone' => '+00:00',
		'statement_cache_limit' => 128,
	],
];
```

### Why the key is `pass`

The password key is `pass`, not `password`.

That is not an accident. It is the contract used by the service:

```php
$this->app->cfg->db->pass
```

Use that exact key.

### Validation philosophy

The service validates config eagerly and explicitly:

* Required values must exist and be non-empty where appropriate
* Optional values use defaults when omitted
* Optional values still fail if supplied in an invalid shape
* Validation is deterministic and cheap

Examples:

* Invalid `port` fails
* Negative `statement_cache_limit` fails
* Missing `name` fails
* Missing `pass` does **not** fail purely because the string is empty, since some environments may legitimately use an empty password

The point is not “strictness for style points.” The point is that database misconfiguration should fail early and clearly, not half-work until a later query explodes in a less useful place.

---

## 4) Connection lifecycle and session behavior

The service is **lazy**. No connection is opened in `init()`.

The first call that needs a real connection triggers:

1. `mysqli_init()`
2. connect timeout application
3. `real_connect(...)`
4. `set_charset(...)`
5. session settings (`sql_mode`, `time_zone`)
6. ownership of the connection by the service

This ordering matters.

A connection is only considered “live” once all session setup has succeeded. If connection setup fails midway, the service closes the partial connection best-effort and remains in a clean “no connection” state.

### Session settings after connect

Session settings are applied **after** connect.

That includes:

* optional `sql_mode`
* optional explicit `timezone`
* otherwise a derived UTC offset based on PHP’s active default timezone

This keeps runtime behavior explicit and avoids the classic “the SQL ran, but the session quietly used some other mode/timezone” class of bugs.

---

## 5) Read operations

The service provides a small set of explicit read helpers. They are intentionally boring. That is a compliment.

### `fetchValue()`

Fetches the first column of the first row, or `null` if no rows match.

```php
$displayName = $this->app->db->fetchValue(
	'SELECT display_name FROM users WHERE id = ?',
	[$userId]
);
```

Typical use:

* counts
* flags
* scalar lookups
* single-column metadata

Example with cast:

```php
$userCount = (int)$this->app->db->fetchValue(
	'SELECT COUNT(*) FROM users WHERE is_active = ?',
	[1]
);
```

---

### `fetchRow()`

Fetches the first row as an associative array, or `null` if nothing matches.

```php
$user = $this->app->db->fetchRow(
	'SELECT id, email, display_name FROM users WHERE id = ?',
	[$userId]
);

if ($user === null) {
	throw new \RuntimeException('User not found.');
}
```

---

### `fetchAll()`

Fetches all rows as an array of associative arrays.

```php
$users = $this->app->db->fetchAll(
	'SELECT id, email FROM users WHERE is_active = ? ORDER BY id ASC',
	[1]
);
```

Returns an empty array when no rows match.

Use it when:

* the result set is reasonably bounded
* you genuinely want all rows in memory

Do not use it for an unbounded export just because it has a friendly name.

---

### `countRows()`

Counts rows either from:

* an existing `mysqli_result`, or
* a SQL string plus params

Examples:

```php
$count = $this->app->db->countRows(
	'SELECT id FROM users WHERE is_active = ?',
	[1]
);
```

Or with an existing result:

```php
$result = $this->app->db->select(
	'SELECT id, email FROM users WHERE is_active = ?',
	[1]
);

try {
	$count = $this->app->db->countRows($result);
} finally {
	$result->free();
}
```

Note that this is **not** a semantic replacement for `COUNT(*)`. It counts rows in the returned result. For server-side counting with less transferred data, `SELECT COUNT(*) ...` is still the right tool.

---

### `exists()`

Checks whether at least one row exists for a given `WHERE` fragment.

```php
$exists = $this->app->db->exists(
	'users',
	'email = ?',
	[$email]
);
```

Behavior:

* Builds `SELECT 1 ... LIMIT 1`
* Requires a **non-empty** `WHERE` fragment
* Fails fast if the `WHERE` is empty

That last part is deliberate. An empty existence check is not “convenient.” It is usually a bug dressed as optimism.

---

### `select()`

Runs a prepared `SELECT` and returns the raw `mysqli_result`.

```php
$result = $this->app->db->select(
	'SELECT id, email FROM users WHERE is_active = ? ORDER BY id ASC',
	[1]
);

try {
	while ($row = $result->fetch_assoc()) {
		// Process row
	}
} finally {
	$result->free();
}
```

Important:

* Caller owns the returned result
* Caller must free it
* This path requires **mysqlnd**

This is the lowest-level prepared read API you will usually need.

---

### `selectNoMysqlnd()`

Runs a prepared `SELECT` without relying on `mysqlnd` and returns a generator that yields rows one by one.

```php
foreach ($this->app->db->selectNoMysqlnd(
	'SELECT id, email FROM users WHERE is_active = ? ORDER BY id ASC',
	[1]
) as $row) {
	// Process row
}
```

This exists because `select()` depends on `get_result()`, which depends on `mysqlnd`. On some environments, especially older or constrained shared-hosting setups, `mysqlnd` may not be available. `selectNoMysqlnd()` is the honest fallback.

#### Important caveat: Reference semantics

`selectNoMysqlnd()` yields rows using MySQLi `bind_result()` semantics. That means the yielded arrays are safe for **normal streaming iteration**, but they are **not** deep-detached copies.

Safe pattern:

```php
foreach ($this->app->db->selectNoMysqlnd(
	'SELECT id, email FROM users WHERE is_active = ?',
	[1]
) as $row) {
	echo $row['email'] . PHP_EOL;
}
```

Dangerous pattern:

```php
$rows = [];

foreach ($this->app->db->selectNoMysqlnd(
	'SELECT id, email FROM users WHERE is_active = ?',
	[1]
) as $row) {
	$rows[] = $row;
}
```

Why dangerous?

Because later fetches update the same underlying bound values. If you accumulate yielded rows without explicitly copying scalar values, you can end up observing the last fetched row repeatedly.

If you must accumulate, detach explicitly:

```php
$rows = [];

foreach ($this->app->db->selectNoMysqlnd(
	'SELECT id, email FROM users WHERE is_active = ?',
	[1]
) as $row) {
	$rows[] = [
		'id' => $row['id'],
		'email' => $row['email'],
	];
}
```

That may look less elegant than pretending the problem does not exist. It is also correct.

---

## 6) Write operations

The write API is explicit and small: parameterized SQL first, convenience helpers where they genuinely reduce noise.

### `execute()`

Runs a non-`SELECT` statement and returns the affected row count.

```php
$affected = $this->app->db->execute(
	'UPDATE users SET last_login_at = NOW() WHERE id = ?',
	[$userId]
);
```

Use it for:

* `UPDATE`
* `DELETE`
* `INSERT` when you are writing the SQL yourself
* DDL that does not require raw mode

---

### `executeMany()`

Runs the same prepared statement repeatedly with different parameter sets.

```php
$total = $this->app->db->executeMany(
	'UPDATE users SET is_active = ? WHERE id = ?',
	[
		[1, 101],
		[1, 102],
		[1, 103],
	]
);
```

Behavior:

* Prepares once
* Reuses the statement across parameter sets
* Returns the total affected row count

For larger batches, it is usually wise to wrap `executeMany()` in a transaction so you do not pay per-row commit overhead.

Example:

```php
$this->app->db->transaction(function(\CitOmni\Infrastructure\Service\Db $db): void {
	$db->executeMany(
		'UPDATE users SET is_active = ? WHERE id = ?',
		[
			[1, 101],
			[1, 102],
			[1, 103],
		]
	);
});
```

---

### `insert()`

Builds and executes a parameterized `INSERT` from a table name plus a column/value map.

```php
$userId = $this->app->db->insert('users', [
	'email' => $email,
	'display_name' => $displayName,
	'is_active' => 1,
]);
```

Behavior:

* Table and column identifiers are validated and quoted
* Empty data fails fast
* Returns the current insert id

Use it when you want a single-row insert without hand-writing placeholder lists.

---

### `insertBatch()`

Inserts multiple rows efficiently and intentionally supports **two strategies**.

#### Strategy 1: Single multi-row `INSERT`

For small payloads, `insertBatch()` builds one multi-row statement and sends it in one round trip.

```php
$affected = $this->app->db->insertBatch('user_tags', [
	['user_id' => 10, 'tag' => 'alpha'],
	['user_id' => 10, 'tag' => 'beta'],
	['user_id' => 10, 'tag' => 'gamma'],
]);
```

This is the cheap fast path.

#### Strategy 2: Chunked fallback in its own transaction

For larger payloads, the service falls back to chunked execution using repeated single-row inserts inside **its own transaction**.

This is not a hidden implementation detail you can ignore. It affects transaction safety.

Behavior:

* Used when the payload is above the service’s internal row/byte threshold
* Validates that all rows have the same column set
* Starts and owns its own transaction
* Throws if a transaction is already active

That last point is critical.

#### Why the chunked fallback refuses active transactions

The chunked fallback must **not** run inside an already-active transaction. The service detects this and throws.

Reason:

* MySQL/InnoDB does not provide true nested transactions
* Silently beginning another transaction inside an active one is not safe
* Implicit commit behavior is exactly the kind of surprise CitOmni tries to avoid

So this is wrong:

```php
$this->app->db->transaction(function(\CitOmni\Infrastructure\Service\Db $db) use ($rows): void {
	$db->insertBatch('audit_log', $rows);
});
```

It may work for small payloads that stay on the single-statement path, but it will fail if the payload crosses into the chunked fallback path.

If you are already inside a transaction and need full control for a large batch, do it explicitly with `executeMany()`.

Example:

```php
$this->app->db->transaction(function(\CitOmni\Infrastructure\Service\Db $db) use ($rows): void {
	$paramSets = [];

	foreach ($rows as $row) {
		$paramSets[] = [
			$row['user_id'],
			$row['tag'],
		];
	}

	$db->executeMany(
		'INSERT INTO `user_tags` (`user_id`,`tag`) VALUES (?, ?)',
		$paramSets
	);
});
```

That is slightly more verbose. It is also explicit about ownership of the transaction boundary, which is the point.

---

### `update()`

Builds and executes a parameterized `UPDATE`.

```php
$affected = $this->app->db->update(
	'users',
	[
		'display_name' => $displayName,
		'is_active' => 1,
	],
	'id = ?',
	[$userId]
);
```

Behavior:

* Empty data fails
* Empty `WHERE` fails
* `WHERE` params are appended after `SET` params

This method exists to remove the repetitive part of straightforward updates, not to invent an abstract query language.

---

### `delete()`

Builds and executes a parameterized `DELETE`.

```php
$affected = $this->app->db->delete(
	'user_sessions',
	'user_id = ? AND expires_at < NOW()',
	[$userId]
);
```

Behavior:

* Empty `WHERE` fails fast

Again, this is deliberate. “Delete everything” should never happen because someone forgot to finish a string.

---

## 7) Transactions

The service exposes explicit transaction control and one callback-based helper.

### `beginTransaction()`

Begins a transaction.

```php
$this->app->db->beginTransaction();
```

This **does** open a lazy connection if needed. That is correct, because beginning a transaction is itself a meaningful database operation.

---

### `commit()`

Commits the current transaction.

```php
$this->app->db->commit();
```

Important behavior:

* `commit()` does **not** open a new lazy connection
* It requires an already-open connection
* If no connection exists, it throws

That is intentional. There is nothing sensible to commit on a connection that does not exist.

---

### `rollback()`

Rolls back the current transaction.

```php
$this->app->db->rollback();
```

Same strict behavior as `commit()`:

* does not open a new lazy connection
* requires an active connection
* throws otherwise

This avoids nonsense states such as “rollback succeeded” on a transaction that never existed.

---

### Manual transaction pattern

```php
$db = $this->app->db;

$db->beginTransaction();

try {
	$orderId = $db->insert('orders', [
		'user_id' => $userId,
		'status' => 'pending',
	]);

	$db->insert('order_lines', [
		'order_id' => $orderId,
		'sku' => $sku,
		'qty' => $qty,
	]);

	$db->commit();
} catch (\Throwable $e) {
	$db->rollback();
	throw $e;
}
```

This is appropriate when you need manual sequencing or several different failure branches.

---

### `transaction()`

Wraps a callback in a transaction, commits on success, rolls back on failure, and returns the callback result.

```php
$orderId = $this->app->db->transaction(
	function(\CitOmni\Infrastructure\Service\Db $db) use ($userId, $sku, $qty): int {
		$orderId = $db->insert('orders', [
			'user_id' => $userId,
			'status' => 'pending',
		]);

		$db->insert('order_lines', [
			'order_id' => $orderId,
			'sku' => $sku,
			'qty' => $qty,
		]);

		return $orderId;
	}
);
```

Behavior:

* Begins transaction
* Executes callback
* Commits on success
* Rolls back on any exception
* Rethrows the original exception if rollback succeeds
* Throws a new `DbQueryException` if rollback itself also fails, with the original exception chained

That last part matters because dual-failure cases should not silently discard one half of the problem.

---

### `easyTransaction()`

`easyTransaction()` exists as a backward-compatibility alias and returns `mixed`.

```php
$result = $this->app->db->easyTransaction(
	function(\CitOmni\Infrastructure\Service\Db $db): string {
		$db->execute(
			'UPDATE jobs SET started_at = NOW() WHERE id = ?',
			[123]
		);

		return 'ok';
	}
);
```

Use `transaction()` in new code unless you specifically need the legacy-compatible name. Both execute the same logic.

---

## 8) Raw SQL escape hatches

Prepared statements are the default path. Two explicit escape hatches exist for cases where prepared statements are not the right tool.

### `queryRaw()`

Executes raw SQL without parameter binding.

```php
$this->app->db->queryRaw('OPTIMIZE TABLE `users`');
```

Possible return values:

* `mysqli_result` for result-producing queries
* `true` for non-result queries

Use it for:

* DDL
* administrative commands
* one-off maintenance statements
* carefully controlled internal SQL that does not fit the prepared path

Do **not** use it with untrusted input.

Ever.

“Trusted because I trimmed it” is not a trust model.

---

### `queryRawMulti()`

Executes multiple raw SQL statements via `multi_query()`.

```php
$results = $this->app->db->queryRawMulti(
	'
	CREATE TABLE IF NOT EXISTS `tmp_seed` (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
		`name` VARCHAR(100) NOT NULL,
		PRIMARY KEY (`id`)
	);

	INSERT INTO `tmp_seed` (`name`) VALUES (\'alpha\'), (\'beta\');
	SELECT COUNT(*) AS total FROM `tmp_seed`;
	'
);

try {
	foreach ($results as $result) {
		if ($result instanceof \mysqli_result) {
			// Read and free
			$rows = $result->fetch_all(\MYSQLI_ASSOC);
			$result->free();
		}
	}
} finally {
	foreach ($results as $result) {
		if ($result instanceof \mysqli_result) {
			try {
				$result->free();
			} catch (\Throwable) {
			}
		}
	}
}
```

Use it for:

* migration batches
* schema setup
* seeding
* tightly controlled multi-statement administrative work

Important behavior:

* Results are returned in order
* Result-producing statements return `mysqli_result`
* Non-result statements return `true`
* On mid-batch failure, the service frees already-collected results and best-effort drains remaining pending results to re-sync the connection

That cleanup behavior exists because leaving a MySQLi connection mid-`multi_query()` is a fine way to make the next query fail in a completely different place.

---

## 9) Error handling

The service uses three DB-layer exception types:

* `CitOmni\Infrastructure\Exception\DbException`
* `CitOmni\Infrastructure\Exception\DbConnectException`
* `CitOmni\Infrastructure\Exception\DbQueryException`

### Exception roles

**`DbException`**
Base type for catch-all DB-layer handling when you truly want to handle all database failures at one boundary.

**`DbConnectException`**
Used for connection and session-initialization failures.

Examples:

* invalid config
* connect failure
* `set_charset()` failure
* failure while applying session `sql_mode` or `time_zone`

**`DbQueryException`**
Used for query preparation, binding, execution, and SQL-contract failures.

Examples:

* malformed SQL
* placeholder mismatch
* empty `WHERE` on `update()` / `delete()` / `exists()`
* invalid SQL identifiers
* transaction misuse
* query execution failure

---

### Why raw parameter values are excluded from query exception messages

Query exception messages may include:

* SQL text
* parameter **types**

They do **not** include raw parameter values.

That is deliberate and correct.

Why?

Because raw parameter values often contain:

* passwords
* email addresses
* tokens
* personal data
* internal identifiers
* anything else you do not want sprayed into logs at 03:12 on a Sunday

Type-only context is usually enough to diagnose:

* placeholder count mismatch
* obvious type mismatch
* wrong call shape

Without leaking runtime secrets.

Example of the kind of context you may see conceptually:

* SQL included
* params represented as `[string, int, null]`

Not as the actual contents of those values.

---

### What callers should typically catch

In most application code, let failures bubble unless you have a real recovery strategy.

Typical boundary catch:

```php
try {
	$this->app->db->insert('users', [
		'email' => $email,
		'display_name' => $displayName,
	]);
} catch (\CitOmni\Infrastructure\Exception\DbException $e) {
	// Log, wrap, or convert at a boundary that has a real policy.
	throw $e;
}
```

More specific catch when you genuinely care:

```php
try {
	$user = $this->app->db->fetchRow(
		'SELECT id, email FROM users WHERE id = ?',
		[$userId]
	);
} catch (\CitOmni\Infrastructure\Exception\DbQueryException $e) {
	throw $e;
}
```

What you should generally not do:

* catch `\Throwable` deep inside a low-level DB caller “just in case”
* swallow DB exceptions and continue with half-valid state
* pretend a failed write is optional when it is not

---

## 10) Performance and runtime behavior

The `Db` service is designed around a few explicit runtime choices.

### Lazy connection

The service does not connect until needed.

Benefits:

* zero DB connect cost on requests that never touch the database
* no connection side effects during early service initialization
* clearer boundaries between config validation and network activity

---

### Bounded statement cache

Prepared statements are cached by trimmed SQL string, with a deterministic FIFO eviction policy.

Behavior:

* cache hit reuses the existing statement
* cache miss prepares and stores
* full cache evicts the oldest statement
* limit `0` disables caching entirely

This improves hot-path performance without turning the service into a stateful mystery machine.

---

### Deterministic cleanup

The service provides:

* `clearStatementCache()`
* `close()`
* destructor cleanup

Behavior is best-effort and idempotent where appropriate.

After `close()`:

* cached statements are gone
* connection is closed
* transaction flag is reset
* later DB use can reopen the connection lazily

This is useful for long-running CLI processes where cleanup timing may matter more than in one-request-one-process HTTP lifecycles.

---

### Safe metadata defaults before first connect

Several metadata methods intentionally return safe defaults **before any connection has been opened**:

* `lastInsertId()` returns `0`
* `affectedRows()` returns `0`
* `getLastError()` returns `null`
* `getLastErrorCode()` returns `0`

This is not an omission. It is a design choice.

These methods are safe to call even if no query has run yet. They do not force a connection just to answer “nothing has happened.”

---

### Shared-hosting pragmatism

The service is designed with ordinary PHP hosting realities in mind:

* MySQLi only
* no dependency on ORM stacks
* mysqlnd path available when present
* mysqlnd-free fallback available when not
* no dependency on persistent connections
* no hidden retry logic
* no expensive abstraction layers for basic SQL execution

That makes it appropriate for both “grown-up app server” environments and “you do not control much of the stack” hosting environments.

---

### Why there is no ORM or query builder

Because they are not free.

They add:

* abstraction layers
* allocations
* runtime policy that is harder to see from the call site
* extra API surface
* often, a false sense that SQL complexity disappeared

CitOmni’s DB service is designed around the assumption that explicit SQL is cheaper, clearer, and easier to reason about in a performance-conscious framework.

That does not mean ORMs are universally bad. It means they are not part of this service.

---

## 11) Common pitfalls

These are the mistakes most likely to cause real bugs.

### Empty `WHERE` clauses

This fails fast in:

* `exists()`
* `update()`
* `delete()`

Good.

Do not try to work around it unless you truly intend a whole-table operation, in which case write the SQL explicitly and make that decision visible.

---

### Misusing raw SQL

`queryRaw()` and `queryRawMulti()` are escape hatches, not the default mode.

Do not pass user input into raw SQL.

Not directly. Not after trimming. Not after “simple escaping.” Not because you are in a hurry.

Use placeholders.

---

### Assuming `select()` works everywhere

`select()` requires `mysqlnd`.

If `mysqlnd` is unavailable, use `selectNoMysqlnd()`.

Do not discover this at deploy time by assuming every PHP environment looks like your current machine.

---

### Accumulating rows from `selectNoMysqlnd()` without copying

This is the most important caveat of `selectNoMysqlnd()`.

Streaming iteration is fine.

Accumulation without explicit detachment is not.

If you need all rows in memory and you are on a mysqlnd-free environment, copy values explicitly.

---

### Large `insertBatch()` inside an active transaction

This is a real hazard.

Small payloads may use the single-statement path and appear to work.

Large payloads may switch to the chunked fallback and throw because that path owns its own transaction.

If transaction ownership matters, use `executeMany()` explicitly inside your own transaction.

---

### Assuming metadata methods must connect

They do not.

Methods such as:

* `lastInsertId()`
* `affectedRows()`
* `getLastError()`
* `getLastErrorCode()`

return safe defaults before first connect.

Do not write code that treats those defaults as evidence that “the DB is broken.” In many cases, it simply means no query has happened yet.

---

### Forgetting to free result objects

If you call `select()` or raw query methods that return `mysqli_result`, you own the result and must free it.

Use `try/finally` where appropriate.

Example:

```php
$result = $this->app->db->select(
	'SELECT id, email FROM users WHERE is_active = ?',
	[1]
);

try {
	while ($row = $result->fetch_assoc()) {
		// Process
	}
} finally {
	$result->free();
}
```

---

### Treating helper methods as a query language

Methods like `insert()`, `update()`, and `delete()` are convenience wrappers for obvious cases.

Once the SQL stops being obvious, write the SQL.

That is usually clearer than trying to force a convenience helper to impersonate an abstraction layer it was never meant to be.

---

## 12) FAQ

### Q: Should I always use `fetchAll()` for reads?

No.

Use `fetchAll()` when the result set is bounded and you really want all rows in memory. For large or streaming reads, use `select()` or `selectNoMysqlnd()` depending on environment.

---

### Q: Should I use `insert()` or write the SQL manually?

Use `insert()` for straightforward single-row inserts where a column/value map is convenient.

Write the SQL manually when:

* the statement is non-trivial
* you need SQL expressions in values
* readability is better with explicit SQL
* you are already composing a larger query flow

---

### Q: Can I rely on automatic reconnect?

No.

The service does not claim hidden reconnect behavior, retry loops, or automatic recovery semantics. Failures surface as exceptions.

---

### Q: Does `commit()` quietly do nothing if no transaction exists?

No.

It does not open a new lazy connection and does not silently pretend everything is fine. If there is no active connection, it throws.

---

### Q: When should I catch `DbException` instead of the specific subclasses?

Catch `DbException` at a boundary that genuinely handles “database failure” as one category.

Catch `DbConnectException` or `DbQueryException` only when your logic actually differs between connection/session failures and query-contract failures.

---

### Q: Is `easyTransaction()` preferred?

No.

It exists for backward compatibility. New code should generally prefer `transaction()` unless the alias is useful for compatibility or consistency with surrounding code.

---

## 13) Usage checklist

* [ ] Use the service via `$this->app->db`
* [ ] Put DB config in `$this->app->cfg->db`
* [ ] Use the required keys: `host`, `user`, `pass`, `name`
* [ ] Treat config validation failure as a boot/runtime contract failure, not something to hide
* [ ] Prefer prepared statements and placeholders
* [ ] Use `fetchValue()`, `fetchRow()`, `fetchAll()` for common read shapes
* [ ] Use `select()` when you need the raw result and mysqlnd is available
* [ ] Use `selectNoMysqlnd()` when mysqlnd is unavailable, and respect its reference-semantics caveat
* [ ] Use `execute()`, `executeMany()`, `insert()`, `insertBatch()`, `update()`, and `delete()` deliberately
* [ ] Never pass untrusted input to `queryRaw()` or `queryRawMulti()`
* [ ] Keep transaction ownership explicit
* [ ] Do not call large `insertBatch()` payloads inside an already-active transaction
* [ ] Do not assume `commit()` or `rollback()` can act on a nonexistent connection
* [ ] Free `mysqli_result` objects you receive directly
* [ ] Let DB exceptions bubble unless you have a real recovery or conversion policy
* [ ] Remember that safe metadata methods return safe defaults before first connect

---

### Closing note

Use the `Db` service the same way you would use a good hand tool: Directly, intentionally, and without pretending it is a robot. It is there to make correct SQL execution cheap, explicit, and dependable. That is more than enough.
