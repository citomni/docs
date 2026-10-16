# Cfg Access - CitOmni Kernel

*What `$app->cfg` does, and how code reads it. This is the reference; other documents summarize it.*

---

## Document metadata

* **Package:** `citomni/kernel`
* **Key types:** `CitOmni\Kernel\Cfg`, `CitOmni\Kernel\App`
* **PHP version:** >= 8.5
* **Audience:** Application developers, provider authors, and framework developers
* **Status:** Stable, authoritative
* **Document type:** Reference
* **Verified against:** `citomni/kernel` at `959f8a4` (`src/Cfg.php`, `src/App.php`) on PHP 8.5.9

---

## 1. Scope

This document covers reading the merged configuration through `$app->cfg`: what a read returns, when it throws, how the guard operators behave, what happens on a write, and which read form code must use for which path.

It does not cover:

* How the merged tree is built: sources, order, and list replacement. See [Runtime modes, 5.2 Configuration](../concepts/runtime-modes.md#52-configuration).
* What an individual cfg key means. A key is documented where it is defined, in the Registry baseline or config file that ships it.
* Routes, commands, and services. They are separate maps and never part of cfg.

Every statement is numbered. Behavior statements (B1-B22) state what the code does. Rules (R1-R8) state what code must do. Cite them by number, for example "cfg-access B11".

If this document and `src/Cfg.php` disagree, the source shows what happens. Report the difference so this document is corrected.

---

## 2. Terms

| Term | Meaning |
|---|---|
| Path | A sequence of keys from the root, written with dots: `security.csrf.enabled`. |
| List | A non-empty array whose keys are `0, 1, 2, ...` in that order, as `array_is_list()` defines it. |
| Map | A non-empty array that is not a list. |
| Node | A `Cfg` object. `$app->cfg` is the root node. |
| Direct read | A read outside `??`, `isset()` and `empty()`, with no `?->`: `$this->app->cfg->a->b` or `$this->app->cfg['a']['b']`. |
| Guarded read | A read inside `??`, `isset()` or `empty()`. |
| Own path | A path that the reading code's own source defines and loads in every mode and environment the reading code runs in. See 4.1. |
| Foreign path | Every path that is not an own path. |

---

## 3. Behavior

Property syntax (`$cfg->a`) and array syntax (`$cfg['a']`) behave the same for every read of a node in this section.

Where a statement names a PHP warning, notice, or deprecation, it states what PHP raises. What the active error handler then does with it is outside this document, except where B21 says otherwise.

### 3.1 The root

**B1.** `App` exposes the merged configuration as `public readonly Cfg $cfg`.

### 3.2 What a read returns

**B2.** A map is returned as a node.

**B3.** A list is returned as the plain PHP array.

**B4.** An array with integer keys that are not `0, 1, 2, ...` in that order is a map, so it is returned as a node. Its keys are read like any other key: `$node->{'10'}` or `$node[10]`.

**B5.** An empty array is returned as an empty node, not as `[]`. This holds for every key, also a key that normally holds a list, because an empty list and an empty map are the same value.

**B6.** Every value that is not an array is returned unchanged: string, int, float, bool, `null`, and enum case.

**B7.** Repeated reads of the same key return the same node instance, whichever syntax is used.

### 3.3 Missing keys

**B8.** A direct read of a missing key throws `\OutOfBoundsException` with the message `Unknown cfg key: '<key>'`. The exception is thrown at the first missing key on the path, at any depth, and names that key.

**B9.** A node governs only its own keys. When a read has returned something that is not a node, the next step follows plain PHP rules, and `Cfg` throws nothing:

* A property read on a scalar or on `null` raises the PHP warning `Attempt to read property "<key>" on <type>`. When the error handler returns, the result is `null`.
* A missing index in a list raises the PHP warning `Undefined array key <index>`. When the error handler returns, the result is `null`.

### 3.4 Guards

For `??`, `isset()` and `empty()`, PHP asks each node whether the next key exists before it reads that key. A node answers with `array_key_exists()`, which is true for a key whose value is `null`.

**B10.** `??` never throws and raises no notice on a missing key, at any depth, also when the path runs through a scalar, `null`, or a missing list index. It yields its right operand when the path is missing or the value is `null`. It yields the value when the value is `false`, `0`, `''`, or an empty node.

**B11.** `isset()` never throws on a missing key. It is `false` when the path is missing. It is `true` when the last key exists, also when its value is `null`, because `isset()` asks only whether the last key exists and does not read its value.

**B12.** `empty()` never throws on a missing key. It is `true` when the path is missing or the value is `false`, `0`, `null`, or another value PHP treats as empty.

**B13.** `??` guards only its left operand. The right operand is an ordinary expression: a direct read there throws on a missing key (B8). The right operand is evaluated only when the left operand is missing or `null`; any other left operand wins, an empty string included.

**B14.** `?->` guards no key. It short-circuits only when its left side is `null`; a missing key behind it still throws (B8).

### 3.5 Converting a node

**B15.** `toArray()` returns the node's stored array unchanged: nested maps are arrays, not nodes. On an empty node it returns `[]`.

**B16.** `foreach` over a node yields the stored values unchanged, nested maps as arrays. `count()` returns the number of keys in the node.

**B17.** A node is an object, not an array:

* `(array)` on a node returns the object's internal properties, not the stored data. On an empty node the result has two entries.
* Array functions such as `\in_array()` and `\array_map()` throw `\TypeError` when given a node.

### 3.6 Writes

**B18.** Assigning `$app->cfg` from outside `App` throws `\Error`, because the property is readonly.

**B19.** A write or `unset()` with array syntax throws `\LogicException` with the message `Cfg is read-only.`

**B20.** Changing a list obtained through a read, such as `$cfg->a->list[] = 'x'`, changes nothing. PHP raises the notice `Indirect modification of overloaded property`.

**B21.** Known defect: a write with property syntax, such as `$cfg->a->key = 'x'`, is not refused.

* PHP creates a dynamic property on the node and raises `E_DEPRECATED`: `Creation of dynamic property CitOmni\Kernel\Cfg::$key is deprecated`.
* From then on, a property-syntax read of that key yields the written value, while an array-syntax read yields the stored value.
* When the error handler turns the deprecation into an exception, the exception propagates and the write has still taken effect.

**B22.** An `unset()` with property syntax, such as `unset($cfg->a->key)`, changes nothing and raises no notice. The key stays readable.

---

## 4. Rules

### 4.1 Own and foreign paths

The read form depends on whether the path is own or foreign for the code that reads it.

The reading code's own source is:

* For code in a package: the package's Registry baselines `CFG_COMMON`, `CFG_HTTP`, and `CFG_CLI`.
* For code in the app: the app's config files `config/citomni_cfg.php`, `config/citomni_http_cfg.php`, `config/citomni_cli_cfg.php`, and their environment overlays `config/citomni_cfg.{ENV}.php`, `config/citomni_http_cfg.{ENV}.php`, and `config/citomni_cli_cfg.{ENV}.php`, where `{ENV}` is the value of `CITOMNI_ENVIRONMENT`, or `prod` when that constant is not defined.

A path from the own source is an own path only for code that runs exclusively where the constant or file that defines the path is loaded:

| The path is defined in | It is an own path for code that runs only in |
|---|---|
| `CFG_COMMON` | HTTP and CLI |
| `CFG_HTTP` | HTTP |
| `CFG_CLI` | CLI |
| `config/citomni_cfg.php` | HTTP and CLI, every environment |
| `config/citomni_http_cfg.php` | HTTP, every environment |
| `config/citomni_cli_cfg.php` | CLI, every environment |
| `config/citomni_cfg.{ENV}.php` | Environment `{ENV}`, HTTP and CLI |
| `config/citomni_http_cfg.{ENV}.php` | Environment `{ENV}`, HTTP |
| `config/citomni_cli_cfg.{ENV}.php` | Environment `{ENV}`, CLI |

Every other path is foreign. Foreign paths include another package's cfg, the app's cfg read by a package, a package's cfg read by the app when the app's own config files do not set it, and a path that the reading code's source defines for one mode or environment only while the code also runs elsewhere.

The classification follows the defining file, not the key name: a package key that the app sets in `config/citomni_cfg.php` is an own path for app code; a package key that the app does not set is foreign for app code.

### 4.2 Read forms

**R1.** Read an own path directly, with no `??`, `isset()`, `empty()`, or `?->`. When the read throws, the source lacks the path: add it to the baseline or config file. Never add a guard at the call site instead.

```php
// citomni/demo-provider ships demo_provider.notices.max_visible in CFG_COMMON.
$maxVisible = $this->app->cfg->demo_provider->notices->max_visible;
```

**R2.** Read an option over an own path as the option, `??`, and a direct read of the own path. Never append `?? <default>`. A set option wins; otherwise the own path is read and throws when it is missing (B13).

```php
$this->prefix = (string)($this->options['prefix'] ?? $this->app->cfg->service_name->prefix);
```

**R3.** Read a foreign path with `?? <default>`.

```php
// Inside a package that does not ship locale.timezone:
$timezone = (string)($this->app->cfg->locale->timezone ?? 'UTC');
```

**R4.** Use `isset()` only to branch on whether a path exists, for example before converting a foreign node with `toArray()`. Never use it to guard a direct read of an own path.

**R5.** For a key chosen at runtime in an own map, such as a profile or storage name: read the map directly, look the key up with `?? null`, and throw a specific exception when the result is `null`. Read the map in its own statement; inside `??`, the map read itself would be guarded (B10).

```php
$profiles = $this->app->cfg->service_name->profiles;
$profile = $profiles->{$name} ?? null;
if ($profile === null) {
	throw new \InvalidArgumentException("Unknown profile: {$name}");
}
```

**R6.** Convert a key that holds a list and may be empty before using it as an array. Never cast it with `(array)`, and never pass the read result to an array function directly (B5, B17).

```php
use CitOmni\Kernel\Cfg;

$value = $this->app->cfg->service_name->allowed_hosts;
$allowedHosts = $value instanceof Cfg ? $value->toArray() : $value;
```

**R7.** A package ships a baseline for every path it reads: in `CFG_COMMON`, or in `CFG_HTTP` or `CFG_CLI` when only that mode reads it. A missing baseline is fixed in the Registry (R1).

**R8.** Never write to cfg. B18-B22 describe what happens when code tries; B21 is not refused, so the rule is the only protection.

### 4.3 Decision table

| Situation | Form | Rule |
|---|---|---|
| Own path | Direct read | R1 |
| Option over an own path | `$this->options['key'] ?? <direct read>` | R2 |
| Foreign path | `<read> ?? <default>` | R3 |
| Branch on whether a path exists | `isset(<path>)` | R4 |
| Runtime-chosen key in an own map | Direct map read, then `?? null` lookup, then a specific exception | R5 |
| List that may be empty | `$v instanceof Cfg ? $v->toArray() : $v` | R6 |

---

## 5. Why the rules have this shape

* A guard on an own path turns a missing baseline or config key into a silent default (B10-B12). The code then runs with a value nobody declared, and no error points at the cause. A direct read fails at the first missing key and names it (B8).
* A foreign path can be absent for reasons the reading code does not control: another package's version, the app's config, the mode, or the environment. A default is the correct behavior there.
* `??` falls back on `null` (B10), but `isset()` is `true` for `null` (B11). The two are not interchangeable, so `isset()` is reserved for branching on existence (R4).
* An empty list reads as a node (B5), and a node is not an array (B17). Without the conversion in R6, code that works with a non-empty list fails once the list becomes empty.

---

## 6. Verification

Every behavior statement B1-B22 and the code forms of R2, R5, and R6 were executed against `citomni/kernel` at `959f8a4`, `src/Cfg.php` and `src/App.php`, on PHP 8.5.9. Verify them again after any change to `src/Cfg.php` or to `App::$cfg`.

---

## Related documents

* [Runtime modes, 5.2 Configuration](../concepts/runtime-modes.md#52-configuration): How the merged tree is built.
* [Reading cfg](../how-to/services-authoring-registration-usage.md#reading-cfg): Service-authoring summary of these rules.
* [Build a provider](../how-to/providers-build-a-provider.md): Shipping Registry baselines.
* [CitOmni Coding & Documentation Conventions](../contribute/CONVENTIONS.md): Organization-wide summary.
