# CitOmni Repositories - Authoring and Usage (PHP 8.2+)

> **Low overhead. High performance. Predictable by design.**

This document explains **how to build Repositories** for CitOmni: What they are, where they belong in the architecture, how they use the `Db` service and the repository-facing `ValueToSql` / `ValueFromSql` services, what they may and may not do, and how to keep them **deterministic**, **cheap**, and **reviewable**.

It also documents the practical Repository-facing usage of the built-in **Db**, **ValueToSql**, and **ValueFromSql** services, so the document can be used as a complete authoring guide for normal Repository work.

It includes a production-ready **repository skeleton** and practical guidance for writing SQL-centric persistence code that fits CitOmni's architectural contract.

---

**Document type:** Technical Guide  
**Version:** 1.0  
**Applies to:** CitOmni >= 8.2  
**Audience:** Application and provider developers  
**Status:** Stable and foundational  
**Author:** CitOmni Core Team  
**Copyright:** Copyright (c) 2012-present CitOmni

---

## Architecture overview (where Repository fits)

CitOmni separates transport, orchestration, persistence, reusable tools, and pure helpers into explicit categories:

- **Adapters** (Controllers/Commands) own transport concerns.
- **Operations** own transport-agnostic orchestration and decision flow.
- **Repositories** own persistence and SQL.
- **Services** provide reusable App-aware tools.
- **Utils** are pure functions.

A Repository is therefore **not**:

- a controller helper,
- a service-map singleton,
- an orchestration layer,
- a template/data formatter,
- or a place to hide miscellaneous business logic.

A Repository is the **hard persistence boundary**. All SQL and datastore IO live here. This is a core architectural invariant in CitOmni and not merely a stylistic preference.

---

## 1) What is a Repository?

A **Repository** is a small, focused persistence object that owns interaction with storage.

Typical responsibilities:

- Execute SQL queries.
- Read and write rows.
- Normalize raw result sets into stable array shapes.
- Encapsulate table names, joins, where clauses, ordering, limits, and transaction-safe persistence steps that belong to storage concerns.

A Repository is instantiated explicitly:

```php
$repo = new \App\Repository\UserRepository($this->app);
````

Unlike Services, Repositories are **not** registered in the service map and are **not** accessed as `$this->app->userRepository`.

That difference is deliberate:

* Services are reusable singleton-style tools.
* Repositories are explicit persistence units created at the call site.
* The call graph stays obvious in code review.

---

## 2) Constructor contract

Every Repository must support this constructor contract:

```php
new \Your\Namespace\Repository\X(\CitOmni\Kernel\App $app)
```

Repository classes extend `BaseRepository`, which standardizes the minimal structure while keeping overhead low.

The constructor is not configurable via service-map options. If a Repository needs behavior variation, that policy should usually come from:

* method arguments,
* persistence-related config via `$this->app->cfg`,
* or a separate Operation deciding which Repository calls to make.

Repositories should be cheap to instantiate and safe to create per call site.

---

## 3) Where Repositories belong

Repository classes belong in:

* Application layer: `src/Repository/`
* Provider packages: `src/Repository/`
* Mode packages: `src/Repository/` only if the package genuinely owns persistence

Examples:

```text
/app-root/src/Repository/UserRepository.php
/provider-package-root/src/Repository/SubscriptionRepository.php
```

Repository classes must not be placed in:

* `src/Service/`
* `src/Operation/`
* `src/Http/Controller/`
* `src/Cli/Command/`
* `src/Util/`

If a class contains SQL, it belongs in Repository. If it does not belong to persistence, it probably does not belong in Repository.

---

## 4) What a Repository may do

A Repository may:

* Use `$this->app->db` for SQL execution.
* Read persistence-related config from `$this->app->cfg`.
* Quote table/column choices through the `Db` service's helper APIs indirectly by using high-level methods such as `insert()`, `update()`, `delete()`, `exists()`, etc.
* Return predictable array shapes.
* Return scalar IDs, counts, booleans, or stable associative arrays.
* Own storage-specific transaction steps when they are still purely persistence-related.

Examples of appropriate Repository concerns:

* Insert a user row.
* Fetch a user by email.
* Check whether a slug already exists.
* Update a password hash.
* Delete expired tokens.
* Fetch a paginated list from one or more tables.
* Perform a storage-specific upsert-like sequence when that is still fundamentally about persistence mechanics.

---

## 5) What a Repository must not do

A Repository must not:

- Parse HTTP requests.
- Read POST/GET/session/cookies directly.
- Perform CSRF checks.
- Shape HTTP or CLI output.
- Render templates.
- Send mail.
- Build user-facing translated text.
- Log general application events.
- Coordinate broader workflows across unrelated concerns.
- Decide a multi-step application flow that belongs in Operation.

As a practical rule of thumb, Repository code should not call cross-cutting infrastructure such as:

- `$this->app->mailer`
- `$this->app->txt`
- `$this->app->log`

Those dependencies usually indicate that the method is no longer "just persistence" and should instead be split between Repository plus an Operation or Service with explicit orchestration responsibility.

Examples of code that does **not** belong in Repository:

* "If login succeeds, create session, flash message, redirect user."
* "If payment succeeds, mail customer, clear cache, log audit event, update subscription."
* "If CLI option `--force` is missing, print warning and exit 1."

Those are adapter and/or Operation concerns.

---

## 6) Repository vs. Operation vs. Service

A frequent design question is: **Should this be Repository, Operation, or Service?**

CitOmni's default path is intentionally simple:

- **Controller/Command -> Repository** for trivial read/write.
- **Controller/Command -> Operation -> Repository** for non-trivial orchestration.

If a use case is basically "read a row", "write a row", "check existence", or "update a narrow persistence field", introducing an Operation class is usually unnecessary overhead.

### Put it in Repository when

- The primary responsibility is SQL or datastore IO.
- The method can be described as a persistence action.
- The code mostly builds queries, executes them, and returns stable data.
- The adapter can call it directly without introducing a meaningful workflow layer.

Examples:

- `findById()`
- `findByEmail()`
- `insertUser()`
- `updatePasswordHash()`
- `deleteExpiredResetTokens()`

### Put it in Operation when

- The method coordinates multiple repositories.
- The method combines persistence plus mail/log/cache/etc.
- The method expresses a decision graph or business workflow.
- The same orchestration is shared across HTTP and CLI.
- The adapter would otherwise start accumulating multi-step application logic.

If none of these apply, Controller -> Repository is usually sufficient, and an Operation class is likely overengineering.

Examples:

* `AuthenticateUser`
* `FinalizeAuctionPurchase`
* `ResetPassword`
* `PublishArticle`

### Put it in Service when

* The class is a reusable App-aware tool.
* It is not fundamentally a persistence boundary.
* It should be service-map resolved and reused as a singleton per request/process.

Examples:

* `Mailer`
* `Txt`
* `Cookie`
* `BruteForce`
* `FormatNumber`

A good sanity check:

* If the class name sounds like storage, it is usually a Repository.
* If it sounds like an action or workflow, it is usually an Operation.
* If it sounds like a reusable tool, it is usually a Service.

---

## 7) Dependency model: Why Repository receives App

Repositories receive `App` for the same reasons other CitOmni core categories do:

- explicit wiring,
- deterministic construction,
- minimal ceremony,
- low overhead.

By receiving `App`, a Repository gets:

- shared access to the same `Db` service,
- access to persistence-related configuration,
- a constructor pattern aligned with CitOmni's base classes.

This does **not** mean "anything goes".

Repository access to `App` is constrained by responsibility:

Allowed:
- `$this->app->db`
- `$this->app->valueToSql`
- `$this->app->valueFromSql`
- persistence-related config reads
- small storage-adjacent helpers directly tied to persistence behavior

Not appropriate:
- `$this->app->mailer`
- `$this->app->txt`
- `$this->app->log`
- `$this->app->response`
- `$this->app->session`
- cross-cutting orchestration logic

The boundary is defined by responsibility, not by withholding `App`.
The point is not to artificially deny access. The point is to keep persistence local and orchestration elsewhere.

---

## 8) The Db service API in Repositories

CitOmni's `db` service is the canonical persistence surface for Repositories.

It is a MySQLi-based service with:

- lazy connection,
- prepared statements,
- bounded statement cache,
- transaction helpers,
- read helpers,
- write helpers,
- raw escape hatches for exceptional cases,
- fail-fast exception behavior.

Repositories should normally build on this API instead of re-implementing low-level MySQLi handling themselves.

### Core rule

Repository code should use:

```php
$this->app->db
````

and not raw `mysqli`, ad hoc connection bootstrapping, or home-grown query wrappers.

This keeps:

* connection handling centralized,
* placeholder binding consistent,
* transaction behavior explicit,
* failure behavior predictable.

### Read helpers

Use the narrowest read helper that matches the query contract.

#### Scalar value

```php
$count = $this->app->db->fetchValue(
	'SELECT COUNT(*) FROM users WHERE status = ?',
	['active']
);
```

Use `fetchValue()` when the query should return a single scalar value such as a count, max id, or status flag.

#### Single row

```php
$row = $this->app->db->fetchRow(
	'SELECT id, email, status FROM users WHERE id = ? LIMIT 1',
	[$userId]
);
```

Use `fetchRow()` for single-row lookups such as `findById()` or `findByEmail()`.

#### Multiple rows

```php
$rows = $this->app->db->fetchAll(
	'SELECT id, email, status FROM users WHERE status = ? ORDER BY id DESC LIMIT 50',
	['active']
);
```

Use `fetchAll()` for list queries returning zero or more rows with the same shape.

### Existence checks

```php
$exists = $this->app->db->exists(
	'users',
	'email = ?',
	[$email]
);
```

Use `exists()` when the method contract is a boolean existence test. This is usually clearer than manually selecting a count just to convert it back to `true` or `false`.

### Generic write execution

```php
$affected = $this->app->db->execute(
	'UPDATE users SET last_login_at = ? WHERE id = ?',
	[$timestamp, $userId]
);
```

Use `execute()` when you have a custom prepared statement that does not map cleanly to a higher-level helper.

### Bulk execution

```php
$affected = $this->app->db->executeMany(
	'INSERT INTO audit_log (user_id, action) VALUES (?, ?)',
	[
		[$userId, 'login'],
		[$userId, 'logout'],
	]
);
```

Use `executeMany()` for repeated execution of the same prepared statement with multiple param sets.

### Insert helpers

#### Single-row insert

```php
$userId = $this->app->db->insert('users', [
	'email'         => $email,
	'password_hash' => $passwordHash,
	'status'        => 'active',
	'created_at'    => $createdAt,
]);
```

Use `insert()` for straightforward row inserts where the Repository already owns the table and column contract.

#### Batch insert

```php
$inserted = $this->app->db->insertBatch('user_role', [
	['user_id' => $userId, 'role_id' => 1],
	['user_id' => $userId, 'role_id' => 2],
]);
```

Use `insertBatch()` for real batch workloads. Be aware that its chunked fallback owns its own transaction behavior.

### Update helper

```php
$affected = $this->app->db->update(
	'users',
	['password_hash' => $hash],
	'id = ?',
	[$userId]
);
```

Behavior:

* validates and quotes identifiers,
* requires a non-empty `WHERE`,
* appends `WHERE` params after `SET` params.

Use `update()` for standard updates where the Repository owns the table/column names and the method contract is clear.

### Delete helper

```php
$deleted = $this->app->db->delete(
	'password_reset_tokens',
	'expires_at < ?',
	[$now]
);
```

Behavior:

* requires a non-empty `WHERE`,
* fails fast otherwise.

Use `delete()` for explicit, bounded delete operations. A Repository should never hide an unbounded delete behind a vague method contract.

### Transactions

Use transactions when the sequence is still persistence-local.

```php
$this->app->db->transaction(function($db): void use ($userId, $roleIds) {
	$db->delete('user_role', 'user_id = ?', [$userId]);

	$rows = [];
	foreach ($roleIds as $roleId) {
		$rows[] = [
			'user_id' => $userId,
			'role_id' => $roleId,
		];
	}

	if ($rows !== []) {
		$db->insertBatch('user_role', $rows);
	}
});
```

Use Repository-local transactions when:

* the whole sequence is storage-related,
* the transaction boundary belongs to one Repository responsibility,
* no broader workflow orchestration is mixed in.

If the transaction spans multiple repositories or broader workflow logic, let an Operation own it instead.

### Raw query escape hatches

The `Db` service also exposes lower-level escape hatches for exceptional cases such as migrations, admin tooling, or unusual SQL that does not fit the normal helper surface.

Use these sparingly. Normal Repository code should prefer prepared statements and the narrow high-level methods above.

### Failure model

`Db` fails fast.

Typical failure surfaces are:

* `DbConnectException` for connection/session-init problems,
* `DbQueryException` for query prepare/bind/execute failures.

Repositories should usually let these bubble rather than wrapping them in vague boolean return values.

### Recommended Db usage rules in Repositories

* Prefer `$this->app->db` over raw `mysqli`
* Prefer the narrowest helper that matches the method contract
* Prefer explicit SQL over abstraction-heavy generic query builders
* Use `exists()` for boolean existence checks
* Use `fetchValue()` for scalar queries
* Use `fetchRow()` for one-row lookups
* Use `fetchAll()` for list queries
* Use `insert()`, `update()`, and `delete()` for straightforward table operations
* Use `transaction()` only when the transaction boundary is still persistence-local
* Let DB exceptions bubble by default

---

### The ValueToSql and ValueFromSql service APIs in Repositories

In CitOmni, `ValueToSql` and `ValueFromSql` are valid Repository-facing services.

They belong naturally in Repository work because they solve a persistence-adjacent boundary problem:

- `ValueToSql` normalizes incoming values into strict SQL-friendly values for parameter binding.
- `ValueFromSql` formats raw SQL values into stable UI/form-friendly values when Repository methods intentionally prepare data for edit forms or similar persistence-backed input flows.

Use them through:

```php
$this->app->valueToSql
$this->app->valueFromSql
````

and not by duplicating ad hoc parsing/formatting logic inside each Repository.

### Core rule

Use these services for **value normalization at the persistence boundary**.

Use `ValueToSql` when a Repository method accepts user-/form-shaped input and needs to normalize it into strict SQL values before executing queries.

Use `ValueFromSql` when a Repository method returns database values that are intentionally shaped for form repopulation, edit screens, or other UI-facing persistence output.

Do **not** use them as a substitute for broader business validation or workflow decisions.

### What ValueToSql is for

`ValueToSql` converts common UI/form inputs into SQL-safe scalar values with strict fail-fast behavior.

Typical Repository use cases:

* normalize localized integers before binding,
* normalize localized decimals into SQL dot-decimal strings,
* normalize checkbox/toggle values into SQL booleans (`0`/`1`),
* normalize dates and times into SQL-friendly strings,
* normalize nullable text fields consistently,
* validate enum-like input against an allowed value list,
* encode JSON payloads deterministically before storage.

Typical methods:

* `integer(mixed $value, bool $required = false, int $min = \PHP_INT_MIN, int $max = \PHP_INT_MAX, bool $allowNegative = true): ?int`
* `decimal(mixed $value, bool $required = false, int $scale = 2, bool $allowNegative = true): ?string`
* `boolean(mixed $value, bool $required = false): ?int`
* `date(mixed $value, bool $required = false): ?string`
* `time(mixed $value, bool $required = false): ?string`
* `dateTime(mixed $value, bool $required = false): ?string`
* `text(mixed $value, bool $required = false, int $maxLen = 0, bool $trim = true): ?string`
* `enum(mixed $value, array $allowed, bool $required = false): ?string`
* `json(mixed $value, bool $required = false): ?string`

Example:

```php
$price = $this->app->valueToSql->decimal($input['price'] ?? null, required: true, scale: 2);
$yearBuilt = $this->app->valueToSql->integer($input['year_built'] ?? null, min: 1800, max: 2100);
$isActive = $this->app->valueToSql->boolean($input['is_active'] ?? null);

$this->app->db->execute(
	'UPDATE property SET price = ?, year_built = ?, is_active = ? WHERE id = ?',
	[$price, $yearBuilt, $isActive, $propertyId]
);
```

### What ValueFromSql is for

`ValueFromSql` converts raw SQL result values into locale-aware strings or simple PHP values suitable for form fields and edit flows.

Typical Repository use cases:

* convert SQL integers into display/form strings,
* convert SQL decimals into locale-formatted strings,
* convert SQL boolean-style values into PHP booleans,
* convert SQL date/time values into HTML form field values,
* decode JSON columns into arrays for editing,
* normalize nullable text values before returning edit data.

Typical methods:

* `integer(mixed $value, bool $required = false, ?bool $groupThousands = null): ?string`
* `decimal(mixed $value, bool $required = false, ?int $scale = null, ?bool $groupThousands = null, ?bool $trimTrailingZeros = null, ?string $rounding = null): ?string`
* `boolean(mixed $value, bool $required = false): ?bool`
* `date(mixed $value, bool $required = false, string $format = 'YYYY-MM-DD'): ?string`
* `time(mixed $value, bool $required = false, ?bool $includeSeconds = null): ?string`
* `dateTimeLocal(mixed $value, bool $required = false, ?bool $includeSeconds = null): ?string`
* `text(mixed $value, bool $required = false): ?string`
* `json(mixed $value, bool $required = false): ?array`

Example:

```php
$row = $this->app->db->fetchRow(
	'SELECT price, year_built, is_active, available_from FROM property WHERE id = ? LIMIT 1',
	[$propertyId]
);

if ($row === null) {
	return null;
}

return [
	'price' => $this->app->valueFromSql->decimal($row['price'] ?? null),
	'year_built' => $this->app->valueFromSql->integer($row['year_built'] ?? null, groupThousands: false),
	'is_active' => $this->app->valueFromSql->boolean($row['is_active'] ?? null),
	'available_from' => $this->app->valueFromSql->date($row['available_from'] ?? null),
];
```

### Repository boundary guidance for Value*Sql

Use `ValueToSql` and `ValueFromSql` when they make the Repository's persistence contract more explicit and deterministic.

Good fit:

* methods that persist form-backed values,
* methods that load row data for edit forms,
* methods that must enforce strict locale-aware numeric normalization close to SQL,
* methods that must decode/encode JSON columns consistently.

Less suitable:

* broad domain validation,
* cross-field business rules,
* workflow branching,
* user-facing translation/messages.

Those concerns belong elsewhere.

### Recommended Value*Sql usage rules in Repositories

* Prefer `ValueToSql` over hand-written locale parsing in Repository methods
* Prefer `ValueFromSql` over ad hoc result formatting for edit flows
* Let `ValueToSqlException`, `ValueFromSqlException`, and configuration exceptions fail fast by default
* Keep normalization narrow and method-local
* Do not turn Repository methods into general form-processing classes
* Do not mix value normalization with broader business workflows

---

## 9) Common Db usage patterns in Repositories

The following patterns cover most day-to-day Repository work.

### Find by id

```php
public function findById(int $userId): ?array {
	if ($userId < 1) {
		throw new \InvalidArgumentException('User id must be >= 1.');
	}

	$row = $this->app->db->fetchRow(
		'SELECT id, email, status, created_at
		 FROM users
		 WHERE id = ?
		 LIMIT 1',
		[$userId]
	);

	if ($row === null) {
		return null;
	}

	return [
		'id'         => (int)$row['id'],
		'email'      => (string)$row['email'],
		'status'     => (string)$row['status'],
		'created_at' => (string)$row['created_at'],
	];
}
````

### Exists by unique field

```php
public function existsByEmail(string $email): bool {
	$email = \trim($email);
	if ($email === '') {
		throw new \InvalidArgumentException('Email cannot be empty.');
	}

	return $this->app->db->exists('users', 'email = ?', [$email]);
}
```

### Insert and return id

```php
public function insertUser(array $data): int {
	return $this->app->db->insert('users', [
		'email'         => $data['email'],
		'password_hash' => $data['password_hash'],
		'status'        => $data['status'],
		'created_at'    => $data['created_at'],
	]);
}
```

### Update with bounded WHERE

```php
public function updatePasswordHash(int $userId, string $passwordHash): int {
	if ($userId < 1) {
		throw new \InvalidArgumentException('User id must be >= 1.');
	}
	if ($passwordHash === '') {
		throw new \InvalidArgumentException('Password hash cannot be empty.');
	}

	return $this->app->db->update(
		'users',
		['password_hash' => $passwordHash],
		'id = ?',
		[$userId]
	);
}
```

### Delete expired rows

```php
public function deleteExpiredResetTokens(string $now): int {
	return $this->app->db->delete(
		'password_reset_tokens',
		'expires_at < ?',
		[$now]
	);
}
```

---

## 10) Return shapes and contracts

Repositories should return predictable, documented shapes.

Good return contracts:

- `?array<string,mixed>` for a single row or null
- `array<int,array<string,mixed>>` for lists
- `int` for inserted IDs or affected row counts
- `bool` for existence checks

Avoid:

- dynamically shaped arrays with undocumented optional keys,
- mixing scalars and arrays from the same method,
- returning transport-specific objects,
- returning magic mutable state containers.

Examples:

```php
/**
 * @return array{id:int,email:string,password_hash:string,status:string}|null
 */
public function findAuthRowByEmail(string $email): ?array
````

```php
/**
 * @return array<int,array{id:int,email:string,status:string,created_at:string}>
 */
public function listRecentUsers(int $limit = 50): array
```

A Repository contract should be understandable without spelunking through calling code.

### Practical PHPDoc shape patterns

Use concrete array-shape PHPDoc wherever the method returns a stable row or row-list contract.

**1. Single row or null**

Use this for lookup methods such as `findById()` or `findByEmail()`:

```php
/**
 * @return array{id:int,email:string,status:string,created_at:string}|null
 */
public function findById(int $userId): ?array
```

**2. List of rows**

Use this for list/query methods that return zero or more rows with the same shape:

```php
/**
 * @return array<int,array{id:int,email:string,status:string}>
 */
public function listActiveUsers(int $limit = 100): array
```

**3. Narrow projection / storage-specific summary row**

Use this when the method intentionally returns a small projection rather than a full entity-shaped row:

```php
/**
 * @return array{attempt_count:int,last_attempt_at:string|null}|null
 */
public function findFailureWindow(string $identifier): ?array
```

Recommendations:

* Keep keys stable.
* Keep types normalized.
* Do not mix "full row" and "partial row" shapes in the same method.
* If a method returns a narrow projection, say so clearly in the method name or PHPDoc.

---

## 11) Read methods vs write methods

Repositories usually benefit from an explicit split between read and write methods.

### Typical read methods

* `findById()`
* `findByEmail()`
* `existsBySlug()`
* `countActiveForUser()`
* `listRecent()`
* `searchByTerm()`

### Typical write methods

* `insertUser()`
* `updatePasswordHash()`
* `markAsVerified()`
* `deleteExpiredTokens()`
* `incrementFailureCount()`

This naming makes code review easier:

* reads sound like reads,
* writes sound like writes,
* side effects become visible from method names.

---

## 12) Authoring a Repository (example)

The following example shows the recommended Repository style for CitOmni.

```php
<?php
declare(strict_types=1);

namespace Vendor\Package\Repository;

use CitOmni\Kernel\Repository\BaseRepository;

/**
 * UserRepository: Persistence access for user records and credential-adjacent storage.
 *
 * Owns SQL for reading and writing user rows. Returns stable array shapes and
 * scalar persistence results. Does not perform transport work, orchestration,
 * mail dispatch, or user-facing text composition.
 *
 * Behavior:
 * - All SQL lives here.
 * - Uses the shared Db service from the App.
 * - Returns predictable array shapes or scalar persistence results.
 *
 * Notes:
 * - This Repository is intentionally boring. That is a compliment.
 * - Workflow decisions belong in Operation, not here.
 */
final class UserRepository extends BaseRepository {

	/**
	 * Find the authentication row for a given email address.
	 *
	 * Returns only the columns needed for authentication-related persistence work.
	 *
	 * @param string $email Normalized email address.
	 * @return array{id:int,email:string,password_hash:string,status:string}|null Matching row or null.
	 */
	public function findAuthRowByEmail(string $email): ?array {
		$email = \trim($email);
		if ($email === '') {
			throw new \InvalidArgumentException('Email cannot be empty.');
		}

		$row = $this->app->db->fetchRow(
			'SELECT id, email, password_hash, status
			 FROM users
			 WHERE email = ?
			 LIMIT 1',
			[$email]
		);

		if ($row === null) {
			return null;
		}

		return [
			'id'            => (int)$row['id'],
			'email'         => (string)$row['email'],
			'password_hash' => (string)$row['password_hash'],
			'status'        => (string)$row['status'],
		];
	}

	/**
	 * Insert a new user and return the generated id.
	 *
	 * @param array{email:string,password_hash:string,status:string,created_at:string} $data Row payload.
	 * @return int New user id.
	 */
	public function insertUser(array $data): int {
		return $this->app->db->insert('users', [
			'email'         => $data['email'],
			'password_hash' => $data['password_hash'],
			'status'        => $data['status'],
			'created_at'    => $data['created_at'],
		]);
	}

	/**
	 * Update the password hash for a specific user.
	 *
	 * @param int    $userId       User id.
	 * @param string $passwordHash New password hash.
	 * @return int Affected row count.
	 */
	public function updatePasswordHash(int $userId, string $passwordHash): int {
		if ($userId < 1) {
			throw new \InvalidArgumentException('User id must be >= 1.');
		}
		if ($passwordHash === '') {
			throw new \InvalidArgumentException('Password hash cannot be empty.');
		}

		return $this->app->db->update(
			'users',
			['password_hash' => $passwordHash],
			'id = ?',
			[$userId]
		);
	}

	/**
	 * Check whether a user exists for the given email address.
	 *
	 * @param string $email Normalized email address.
	 * @return bool True when the email already exists.
	 */
	public function existsByEmail(string $email): bool {
		$email = \trim($email);
		if ($email === '') {
			throw new \InvalidArgumentException('Email cannot be empty.');
		}

		return $this->app->db->exists('users', 'email = ?', [$email]);
	}
}
```

---

## 13) Repository skeleton (drop-in)

```php
<?php
declare(strict_types=1);

namespace <Vendor>\<Package>\Repository;

use CitOmni\Kernel\Repository\BaseRepository;

/**
 * <RepositoryName>: <One-line responsibility summary>.
 *
 * <Optional longer description: What storage this Repository owns and what it does not own.>
 *
 * Behavior:
 * - Owns SQL and datastore IO for <entity/aggregate/table-set>.
 * - Returns stable array shapes and scalar persistence results.
 * - Performs no transport shaping and no workflow orchestration.
 *
 * Notes:
 * - Keep methods explicit and predictable.
 * - Prefer narrow methods over "doEverything()" persistence buckets.
 */
final class <RepositoryName> extends BaseRepository {

	/**
	 * <MethodName>: <One-line summary>.
	 *
	 * <Optional longer description.>
	 *
	 * Behavior:
	 * - Validates persistence-relevant input early.
	 * - Uses the narrowest appropriate Db helper.
	 * - Normalizes the returned row or scalar contract before returning.
	 *
	 * @param <type> $<param> <Constraints>.
	 * @return <type> <Return contract>.
	 *
	 * @throws \InvalidArgumentException On invalid input.
	 * @throws \CitOmni\Infrastructure\Exception\DbConnectException On connection/session init failure.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException On SQL prepare/bind/execute failure.
	 */
	public function <methodName>(<type> $<param>): <type> {
		// 1) Validate input early.
		// 2) Use fetchValue/fetchRow/fetchAll/exists/insert/update/delete/transaction as appropriate.
		// 3) Normalize the result shape before returning.
	}

}

```

---

## 14) Input validation in Repository

Repositories should validate persistence-relevant method inputs early and cheaply.

Examples of appropriate validation:

* non-empty email/string key
* positive integer ids
* non-empty data arrays for inserts
* required row keys before insert/update
* sane limits for pagination

Examples:

```php
if ($userId < 1) {
	throw new \InvalidArgumentException('User id must be >= 1.');
}
```

```php
$email = \trim($email);
if ($email === '') {
	throw new \InvalidArgumentException('Email cannot be empty.');
}
```

This is not "business workflow validation". It is contract validation for the persistence API.

A Repository should fail fast when the caller provides impossible persistence input.

---

## 15) Config usage in Repository

Repositories may read persistence-related config from:

```php
$this->app->cfg
````

Typical cases:

* table prefixes if you standardize them
* shard/tenant selection policy if storage-related
* pagination defaults tied to persistence behavior
* cleanup retention windows for storage maintenance

Repositories should not become general config-driven policy engines.

### Db config reminder

The `Db` service itself is configured from the `db` config node.

Typical keys include:

* `host`
* `user`
* `pass`
* `name`
* `charset`
* `port`
* `socket`
* `connect_timeout`
* `sql_mode`
* `timezone`
* `statement_cache_limit`

Repository code should normally not re-read these values unless the method truly needs persistence-related policy from config. In most cases, the Repository should simply rely on `$this->app->db` having already normalized and validated its own DB configuration.


Good:

```php
$days = (int)($this->app->cfg->auth->reset_token_retention_days ?? 30);
```

Less good:

* reading UI config,
* reading mail/template/locale text policy,
* reading route behavior,
* using config to hide unclear responsibility boundaries.

Config should support persistence behavior, not blur layers.

---

## 16) Transactions: When to use them

Transactions belong close to persistence, but their ownership depends on scope.

### Repository-managed transaction is acceptable when

* the transaction wraps a purely persistence-local sequence,
* the sequence belongs to one Repository responsibility,
* no broader workflow decisions are mixed in.

Example:

```php
public function replaceUserRoles(int $userId, array $roleIds): void {
	$this->app->db->transaction(function(): void use ($userId, $roleIds) {
		$this->app->db->delete('user_role', 'user_id = ?', [$userId]);

		$rows = [];
		foreach ($roleIds as $roleId) {
			$rows[] = [
				'user_id' => $userId,
				'role_id' => $roleId,
			];
		}

		if ($rows !== []) {
			$this->app->db->insertBatch('user_role', $rows);
		}
	});
}
```

### Operation should own the transaction when

* multiple repositories are involved,
* the sequence is part of a broader business workflow,
* the transaction boundary is business-significant rather than storage-local.

That keeps orchestration where it belongs.

---

## 17) Normalization: Repositories should return clean shapes

Raw DB results often contain mixed scalar types or driver-specific values. Repositories should normalize these into stable shapes before returning.

Examples:

```php
return [
	'id'         => (int)$row['id'],
	'email'      => (string)$row['email'],
	'is_active'  => ((int)$row['is_active'] === 1),
	'created_at' => (string)$row['created_at'],
];
```

This gives callers a better contract and prevents type surprises from leaking upward.

Do not over-normalize into elaborate DTO hierarchies unless there is a very clear, measurable reason.

CitOmni prefers predictable arrays.

---

## 18) Naming guidance

Repository names should reflect persistence ownership clearly.

Good names:

* `UserRepository`
* `InvoiceRepository`
* `SubscriptionRepository`
* `AuctionBidRepository`
* `PasswordResetRepository`

Good method names:

* `findById`
* `findAuthRowByEmail`
* `existsByEmail`
* `insertToken`
* `deleteExpired`
* `updateStatus`
* `listRecent`

Avoid vague names:

* `Manager`
* `Helper`
* `Handler`
* `Common`
* `DataStuff`
* `doWork`

Avoid method names that hide side effects:

* `process()`
* `handle()`
* `run()`

A method name should tell the reviewer what storage action happens.

---

## 19) Performance guidance

Repository code often sits on hot paths. Keep it lean.

Recommendations:

* Prefer narrow SELECT lists over `SELECT *`.
* Prefer narrow Db helpers (`fetchValue()`, `fetchRow()`, `exists()`) when sufficient.
* Normalize only what you actually return.
* Avoid repeated config reads when a local scalar is enough.
* Keep query text explicit and reviewable.
* Avoid building giant dynamic query abstractions unless they are measurably useful.
* Use `insertBatch()` and `executeMany()` for real batch workloads.
* Let the `Db` service's prepared-statement cache do its job.

CitOmni favors explicit SQL over abstraction-heavy cleverness.

---

## 20) Error handling philosophy

Repositories should fail fast.

* Let `DbQueryException` and `DbConnectException` bubble.
* Throw `InvalidArgumentException` for invalid method inputs.
* Avoid catch-all blocks.
* Catch only when there is a truly recoverable persistence-specific fallback.

Usually, the correct pattern is:

* validate input,
* execute query,
* normalize result,
* return.

Not:

* catch everything,
* translate into ambiguous booleans,
* hide real failure surfaces.

A swallowed SQL failure is not robustness. It is delayed pain.

---

## 21) Example usage from Controller and Operation

For simple reads and writes, Controller -> Repository is the default path.
Introduce an Operation only when the use case becomes orchestration-heavy.

### Controller -> Repository (trivial CRUD default)

```php
<?php
declare(strict_types=1);

namespace App\Http\Controller;

use App\Repository\UserRepository;
use CitOmni\Http\Controller\BaseController;

final class UserController extends BaseController {
	public function show(): void {
		$userId = (int)($this->app->request->getQuery('id') ?? 0);

		$repo = new UserRepository($this->app);
		$user = $repo->findById($userId);

		if ($user === null) {
			throw new \RuntimeException('User not found.');
		}

		$this->app->response->setBody(
			$this->app->tplEngine->render('member/user/show.html', ['user' => $user])
		);
	}
}
```

### Operation -> Repository (non-trivial orchestration)

```php
<?php
declare(strict_types=1);

namespace App\Operation;

use App\Repository\UserRepository;
use CitOmni\Kernel\Operation\BaseOperation;

final class ResetPassword extends BaseOperation {
	public function run(int $userId, string $passwordHash): array {
		$repo = new UserRepository($this->app);
		$repo->updatePasswordHash($userId, $passwordHash);

		return [
			'user_id' => $userId,
			'updated' => true,
		];
	}
}
```

---

## 22) Testing a Repository

Repositories are straightforward to test because they use an explicit constructor and a narrow dependency model.

Example:

```php
public function testFindAuthRowByEmailReturnsRow(): void {
	$app  = new \CitOmni\Kernel\App(__DIR__ . '/../_fixtures/config', \CitOmni\Kernel\Mode::HTTP);
	$repo = new \Vendor\Package\Repository\UserRepository($app);

	$row = $repo->findAuthRowByEmail('alice@example.com');

	$this->assertSame('alice@example.com', $row['email']);
}
```

Repository tests should focus on:

* correct SQL behavior,
* stable return shapes,
* boundary conditions,
* invalid input handling.

They should not test transport behavior.

---

## 23) FAQ

**Q: Should a Repository ever be registered as a service?**  
A: No. In CitOmni, Repositories are instantiated explicitly. Service-map registration is for Services, not Repositories.

**Q: Can a Repository call another Repository?**  
A: It technically can, but this is usually a smell. If multiple persistence units must be coordinated, that often belongs in an Operation.

**Q: Can a Repository use transactions directly?**  
A: Yes, when the transaction boundary is still purely persistence-local. For broader workflows, let an Operation own the transaction.

**Q: May a Repository call `$this->app->mailer`, `$this->app->txt`, or `$this->app->log`?**  
A: As a rule, no. Those dependencies usually indicate orchestration or cross-cutting infrastructure work rather than persistence. Keep Repository focused on storage, and move broader coordination into an Operation or a suitable Service.

**Q: Should a Repository return domain objects?**
A: CitOmni generally prefers stable arrays and scalars. Keep contracts explicit and lightweight.

**Q: Can a Repository use raw SQL via `queryRaw()`?**
A: Yes, but only when appropriate. Prefer prepared statements and the narrow `Db` helpers for normal application queries.

---

## 24) Authoring checklist

* [ ] Class extends `BaseRepository`
* [ ] Class is `final` by default
* [ ] Lives in `src/Repository/`
* [ ] Constructor contract is `__construct(App $app)` via base class
* [ ] Owns SQL and datastore IO only
* [ ] Does not perform transport work
* [ ] Does not perform workflow orchestration
* [ ] Uses `$this->app->db` rather than ad hoc MySQLi code
* [ ] Validates method inputs early
* [ ] Returns stable, documented array/scalar shapes
* [ ] Uses explicit, reviewable SQL
* [ ] Throws clearly on invalid input or query failure
* [ ] Keeps methods small, boring, and persistence-focused

---

### Closing note

Keep Repositories **small, explicit, and storage-minded**. If Services are your tools and Operations are your decision graphs, Repositories are your SQL border guards: They should know exactly who gets in, who gets out, and never start running the rest of the country.
