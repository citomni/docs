# CitOmni + LiteMySQLi Cheat Sheet (Ground Truth)

Formål: Dette dokument er en **hallucination-killer** til nye ChatGPT-tråde. Alt herunder er baseret på den konkrete `BaseModelLiteMySQLi.php` og `LiteMySQLi.php` pr. 2026-03-02.

## 1) Standard usage i CitOmni

### BaseModelLiteMySQLi: Hvordan `$this->db` virker

* Modeller **extender** typisk `CitOmni\Infrastructure\Model\BaseModelLiteMySQLi`.
* Klassen holder en lazily-instantiated `LiteMySQLi` connection i `$conn`.
* Connection etableres først, når du tilgår magic property `__get('db')`.

**Kontrakt:**

* `$this->db` er en `LiteMySQLi` instans.
* Kun property-navnet `'db'` er tilladt. Alt andet kaster `\OutOfBoundsException`.

### Mini-eksempel (model)

```php
<?php
declare(strict_types=1);

namespace App\Model;

use CitOmni\Infrastructure\Model\BaseModelLiteMySQLi;

final class UserModel extends BaseModelLiteMySQLi {
	public function findById(int $id): ?array {
		return $this->db->fetchRow(
			'SELECT * FROM users WHERE id = ? LIMIT 1',
			[$id]
		);
	}
}
```

## 2) Connection setup (vigtige guarantees)

Når `LiteMySQLi` konstrueres:

* Den slår mysqli strict errors til: `\mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);`
* Opretter `new \mysqli($host, $username, $password, $database)`
* Sætter charset: `$this->connection->set_charset($charset)` (default `utf8mb4`)
* Sætter MySQL session `time_zone` ud fra PHP default timezone (offset beregnes og sendes som `SET time_zone = '+HH:MM'`).

**Praktisk:** DB-sessions kører med samme offset som din PHP runtime’s timezone.

## 3) Query counter (perf / debugging)

LiteMySQLi tracker antal queries i `$queryCount`.

* `countQueries(bool $reset = false): int`

  * Returnerer count
  * Hvis `$reset = true`, nulstiller den til 0.

## 4) SELECT helpers

### fetchValue(string $sql, array $params = [])

* Kører `select()`, tager **første kolonne i første række** (via `fetch_row()`), ellers `null`.
* Free’r result altid.

**Return:** string|null (typisk). Bemærk: mysqli returnerer som udgangspunkt numeriske værdier som strings, medmindre native typing er aktiveret (det er ikke tilfældet her).

Eksempel:

```php
$cnt = $this->db->fetchValue('SELECT COUNT(*) FROM users WHERE active = ?', [1]);
```

### fetchRow(string $sql, array $params = []): ?array

* Kører `select()`, returnerer `fetch_assoc()` eller `null`.
* Free’r result altid.

**Return:** `?array` (assoc)

### fetchAll(string $sql, array $params = []): array

* Kører `select()`, returnerer `fetch_all(MYSQLI_ASSOC)` (tom array muligt).
* Free’r result altid.

**Return:** `array<int, array<string,mixed>>`

### select(string $sql, array $params = []): \mysqli_result

* Prepared statement (med statement cache).
* Binder params (typed), `execute()`, `get_result()`.
* **Kræver mysqlnd** (fordi `get_result()` bruges).
  * Uden mysqlnd vil `select()` kaste:
    `\mysqli_sql_exception('Expected a result set from SELECT, but none was produced.')`
  * Uden mysqlnd vil **alle** `fetchValue()`, `fetchRow()`, `fetchAll()` også fejle,
    fordi de internt kalder `select()`.

**Vigtigt:**

* Når du selv bruger `select()`, skal du selv kalde `$result->free()`.
* Uden mysqlnd: Brug `selectNoMysqlnd()`.


Eksempel:

```php
$res = $this->db->select('SELECT id FROM users WHERE email = ?', [$email]);
try {
	while ($row = $res->fetch_assoc()) {
		// ...
	}
} finally {
	$res->free();
}
```

### selectNoMysqlnd(string $sql, array $params = []): \Generator
Denne findes specifikt for setups uden mysqlnd og er fallback til `select()`/`fetch*()`:

* Bruger `prepareUncached()`
* Bruger `bind_result()` og `fetch()` og `yield`-er rows som assoc arrays.
* Lukker statement i `finally`.

**Return:** `\Generator<array<string,mixed>>`

Eksempel:

```php
foreach ($this->db->selectNoMysqlnd('SELECT id,email FROM users WHERE active = ?', [1]) as $row) {
	// $row is detached per iteration
}
```

## 5) COUNT / EXISTS helpers

### countRows($resultOrSql, array $params = []): int

* Hvis input er `\mysqli_result`, returnerer `$result->num_rows`.
* Ellers kører `select($sql, $params)`, tager `num_rows`, free’r result.

Eksempel:

```php
$n = $this->db->countRows('SELECT id FROM users WHERE active = ?', [1]);
```

Når countRows() kaldes med en eksisterende \mysqli_result: $this->db->countRows($result);

Wrapperen:

* Returnerer kun $result->num_rows
* Free’r ikke resultset

Det er korrekt, da wrapperen ikke “ejer” resultset i dette tilfælde. 
Kaldende kode er fortsat ansvarlig for $result->free().


### exists(string $table, string $where, array $params = []): bool

* Bygger: `SELECT 1 FROM `table` WHERE $where LIMIT 1`
* `table` bliver quoted via `quoteIdentifierPath()` (tillader `db.table` form).
* `where` indsættes raw (du skal selv bruge `?` placeholders korrekt).
* Free’r result.

Eksempel:

```php
$exists = $this->db->exists('users', 'email = ?', [$email]);
```

## 6) INSERT helpers

### insert(string $table, array $data): int

* `data` skal være non-empty assoc array.
* Bygger `INSERT INTO `table` (`col1`,`col2`) VALUES (?,?)`
* Params er `array_values($data)`
* Returnerer `$this->connection->insert_id`

**Return:** `int` (insert id)

Eksempel:

```php
$userId = $this->db->insert('users', [
	'email' => $email,
	'active' => 1,
]);
```

### insertBatch(string $table, array $data): int

* `data` skal være non-empty.
* Første row skal være non-empty assoc array.
* Alle rows skal have **samme kolonne-sæt** som første row, ellers exception.
* Estimerer bytes og vælger strategi:

  * Hvis `count <= 1000` og est. bytes <= 4MB: **én multi-row INSERT**
  * Ellers: chunker i 1000 rows og bruger `executeMany()` i en transaction.

**Return-værdi (meget vigtig detalje):**

* I “lille batch”-vejen returnerer den `execute(...)` return value.
* `execute()` returnerer **affected rows** (altså antal indsatte rækker).
* I “stor batch”-vejen returnerer den `$total` (sum af affected rows).

**Så:** `insertBatch()` returnerer **antal indsatte rows**, ikke insert-id.

Eksempel:

```php
$inserted = $this->db->insertBatch('audit_log', [
	['user_id' => 1, 'msg' => 'A'],
	['user_id' => 2, 'msg' => 'B'],
]);
```

## 7) UPDATE / DELETE

### update(string $table, array $data, string $where, array $params = []): int

* `data` skal være non-empty.
* Bygger `SET col1 = ?, col2 = ?`
* Samler params: `array_values($data)` først, derefter `$params`.
* Returnerer `execute()` => **affected rows**.

Eksempel:

```php
$affected = $this->db->update('users', ['active' => 0], 'last_login < ?', [$cutoff]);
```

### delete(string $table, string $where, array $params = []): int

* Bygger `DELETE FROM `table` WHERE $where`
* Returnerer affected rows.

## 8) Transactions

### beginTransaction(), commit(), rollback()

Direkte wrappers.

### easyTransaction(callable $callback): void

* Starter transaction
* Kalder `$callback($this)` hvor `$this` er `LiteMySQLi`
* Commit hvis ok, rollback + rethrow ved exception

Eksempel:

```php
$this->db->easyTransaction(function (\LiteMySQLi\LiteMySQLi $db): void {
	$db->execute('UPDATE accounts SET balance = balance - ? WHERE id = ?', [$amt, $fromId]);
	$db->execute('UPDATE accounts SET balance = balance + ? WHERE id = ?', [$amt, $toId]);
});
```

## 9) Raw queries

### queryRaw(string $sql)

* Kører direkte `$this->connection->query($sql)`
* Inkrementerer queryCount
* Return type afhænger af mysqli (result eller bool)

Brug kun når du **bevidst** accepterer at det er raw SQL uden parameter-binding.

### queryRawMulti(string $sql): array

* Returnerer liste af results fra multi_query:

  * Hvert element er enten `\mysqli_result` eller `true`
* Hvis fejl undervejs:

  * Den forsøger at dræne resterende results og free dem
  * Kaster `\mysqli_sql_exception(...)`

## 10) Prepared statements + caching (perf og faldgruber)

### Statement cache

* Cache: `$statementCache` keyed by **SQL string**
* Default limit: `128`
* Hvis limit nås: `array_shift()` (FIFO) og `->close()`

### setStatementCacheLimit(int $limit)

* `0` betyder: ingen cache, og den rydder cache.
* Ellers trimmes cache ned til limit.

### clearStatementCache()

* Close’r alle cached stmts.

**Vigtigt:** Statement cache matcher kun på præcis SQL string. Små string-forskelle = ny stmt.

## 11) Parameter binding: Typer og gotchas

`bindParams()` vælger typer pr parameter:

* `null`:

  * type `'s'`
  * param forbliver `null`
* `int`:

  * type `'i'`
* `float`:

  * type `'d'`
* `bool`:

  * coerces til `1/0`
  * type `'i'`
* alt andet:

  * type `'s'`

**Praktisk konsekvens:**

* Send booleans som `true/false` hvis du vil have auto 1/0.
* Send ints som int, ikke string, hvis du vil have `'i'`.

Vigtigt (sideeffekt): bindParams() itererer parametre via reference: foreach ($params as &$p)

Det betyder:

* bool konverteres til 1/0
* null forbliver null
* Parametre kan blive ændret i det array, der blev sendt ind

Hvis kaldende kode genbruger $params efterfølgende, kan værdierne altså være modificeret.
Dette er bevidst design (lav overhead, ingen kopiering), men vigtigt at være klar over.

Der findes også `bindParamsDownAndDirty()` men den bruges ikke af de offentlige metoder her (den binder alt som `'s'`).


## 12) Identifier quoting (sikkerhed)

### quoteIdentifier(string $identifier)

* Tillader kun: `[A-Za-z0-9_\$]+`
* Wraps i backticks.

### quoteIdentifierPath(string $path)

* Split på `.`
* Hvert segment valideres med samme regex
* Wraps hvert segment i backticks og joiner med `.`

**Så:** Table-navne kan være `"table"` eller `"db.table"`, men ikke fancy SQL.

## 13) Error handling / exceptions

* Mysqli er sat til strict error mode.
* Prepare failures kaster `\mysqli_sql_exception("Prepare failed: ...")`
* `select()` kaster hvis `get_result()` ikke giver result set (typisk mysqlnd issue).
* `queryRawMulti()` kaster ved multi_query fejl.

Der er ingen “catch-all” bortset fra i transaction helpers og cleanup.

## 14) Resource cleanup (meget vigtigt)

* `fetchValue/fetchRow/fetchAll/countRows(except result input)/exists` free’r altid results selv.
* `select()` returnerer `\mysqli_result` som **du** skal free.
* `selectNoMysqlnd()` håndterer cleanup internt (statement close i finally).
* `close()` lukker statements og connection og ignorerer errors.
* `__destruct()` kalder `close()` defensivt.

## 15) “Do / Don’t” i CitOmni-stil

### Do

* Brug `fetchRow/fetchAll/fetchValue` til de fleste cases (de håndterer free()).
* Brug `selectNoMysqlnd()` hvis serveren ikke har mysqlnd.
* Brug `exists()` for hurtige checks (og `LIMIT 1`).
* Brug `easyTransaction()` eller eksplicit begin/commit ved multi-step writes.

### Don’t

* Glem ikke `$result->free()` hvis du bruger `select()` direkte.
* Put ikke user input direkte ind i `$where` eller raw SQL; brug placeholders.
* Antag ikke at `insertBatch()` giver insert-id: Den giver affected rows.

