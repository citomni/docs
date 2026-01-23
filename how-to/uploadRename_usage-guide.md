# `uploadRename()` – Usage Guide

## What it is

`uploadRename()` is a small, deterministic **hook** you can override or call from the upload pipeline to generate a **safe, SEO-friendly** basename for any uploaded file. It does **no I/O** and **does not** decide the extension. This keeps naming stable, predictable, and testable.

## Where it lives

* **Base class:** `CitOmni\Admin\Controller\AdminBaseController`
* **Signature:**

  ```php
  protected function uploadRename(string $column, array $ctx): string
  ```
* **Return:** A sanitized **basename** (no path, no extension).

## When it runs (typical flow)

1. Read POST.
2. `normalize()` the payload (trimming, lowercasing, etc.).
3. `applyCfgTransform()` (e.g., slugify).
4. **Call `uploadRename()`** for each upload field to build the basename.
5. Decide encoding & final extension (e.g., `webp`) and write file(s).
6. Store the **web path** (e.g., `/uploads/news/...`) in the DB.
7. Validate and persist via the model.

> Important: The hook **only** uses the effective POST payload (after normalization/transform). It **never** falls back to DB values from the existing row.

---

## Column configuration (`crudCfg`)

Configure renaming **per column** under `columns[*].upload.rename`. Example:

```php
'columns' => [
	'cover_path' => [
		'type'  => 'image',
		'upload' => [
			'dir'       => '/public/uploads/news/',
			'accept'    => ['image/webp','image/png','image/jpeg'],
			'deleteOld' => true,
			'encoding'  => ['format' => 'webp', 'quality' => 82],
			'thumbnails'=> [
				['w'=>480,'h'=>270,'fit'=>'crop','suffix'=>'_480x270','column'=>'cover_thumb_path','format'=>'webp','quality'=>82],
			],
			'rename' => [
				'pattern' => 'news-{col:slug}-{col:meta_title}',
				'rand'    => true,
				'max'     => 80,
			],
		],
	],
]
```

### Meaning of the `rename` keys

* `pattern` (string): A template with tokens of the form `{col:<name>}`.
  Each token is replaced with the **value from the normalized+transformed POST payload** at `<name>`.
  Missing/empty values → the token is **removed** (not replaced by placeholders).
* `rand` (bool): If `true`, append a uniqueness suffix `-<hex(timestamp)><rand4>` (e.g., `-67cd21fa9b3c4d2a`).
* `max` (int): Max length for the **final basename** (no extension), after substitution, sanitization, and optional `rand`.

> If all tokens vanish after substitution, the hook falls back to the **sanitized original client filename** plus the `rand` suffix (if enabled). If that is empty, it falls back to `"file"`.

---

## Context (`$ctx`) the hook expects

The upload pipeline provides a minimal context:

```php
[
	'rename'       => ['pattern'=>..., 'rand'=>..., 'max'=>...], // from cfg
	'payload'      => [/* normalized+transformed POST */],
	'originalName' => 'IMG_1234.JPG', // raw client filename, for fallback
	'now'          => time(),         // optional; defaults to current time
]
```

* **`$column`** (first argument) is the target column name (e.g., `'cover_path'`). It is not inserted into the output but is useful for logging, tests, or if you override the hook and want per-field behavior.
* **No DB fallback:** Only values present in `payload` are used for `{col:...}` tokens.

---

## Sanitization rules (built in)

After token substitution, the hook:

* Lowercases and performs a light ASCII fold (no `intl` dependency).
* Replaces any non `[a-z0-9]+` with `-`.
* Collapses multiple `-` to a single `-`.
* Trims leading/trailing `-`.
* Applies the `max` cap (keeping the suffix intact when `rand=true`).

This guarantees safe, CDN/FS-friendly names with deterministic behavior.

---

## Why no extension?

The method **always returns a basename**.
The **final extension** is chosen **later** by the encoding step (e.g., `.webp`), which depends on the actual decoded content, EXIF normalization, and your encoding policy. This avoids mismatches like “.jpg” on a file you encoded to WebP.

---

## Examples

### 1) News cover pattern

* `pattern`: `news-{col:slug}-{col:meta_title}`
* POST payload (after transform):
  `slug="hello-world"`, `meta_title="Big Launch!"`
* `rand=true`, `max=80`
* Result (basename):
  `news-hello-world-big-launch-67cd21fa9b3c4d2a`

If both `slug` and `meta_title` are empty, fallback might be:

* Client filename: `IMG_1234.JPG` → `img-1234-67cd21fa9b3c4d2a`

### 2) Minimal “keep client name but safe”

```php
'rename' => [
	'pattern' => '{col:original_basename}', // provide this in payload if you want a pure original-based pattern,
	'rand'    => true,
	'max'     => 72,
]
```

Alternatively, omit `pattern` entirely and rely on the **fallback** (sanitized original name + `rand`).

### 3) Overriding the hook (domain-specific rule)

If you need custom per-field logic, you can override the hook in your child controller:

```php
/**
 * Example: prefer the provided slug, fallback to title, then to default behavior.
 */
protected function uploadRename(string $column, array $ctx): string {
	$payload = (array)($ctx['payload'] ?? []);
	$slug    = (string)($payload['slug'] ?? '');
	$title   = (string)($payload['title'] ?? '');

	if ($column === 'cover_path') {
		$token = $slug !== '' ? $slug : ($title !== '' ? $this->sanitizeForFilename($title, 60) : '');
		if ($token !== '') {
			$base = 'news-' . $this->sanitizeForFilename($token, 72);
			// emulate the standard rand+max behavior (optional):
			$hexTs = \dechex((int)($ctx['now'] ?? \time()));
			$suf   = '-' . $hexTs . \substr(\bin2hex(\random_bytes(2)), 0, 4);
			$out   = $base . $suf;
			return \mb_substr($out, 0, 80);
		}
	}
	// fallback to base behavior:
	return parent::uploadRename($column, $ctx);
}
```

> Keep it **pure**: no I/O here; let the upload pipeline handle writing files and choosing the final extension.

---

## Edge cases & recommendations

* **Empty tokens:** Intentionally removed. If the whole string empties out, the hook falls back to the sanitized client name.
* **Collisions:** Use `rand=true` unless you are intentionally allowing `overwrite=true`.
* **Max length:** The cap is applied **after** substitution & sanitization. With `rand=true`, the suffix is preserved; the base is trimmed first.
* **Security:** Never insert untrusted path components. The hook removes separators and illegal characters.
* **Thumbnails:** Use the same basename + suffix (e.g., `_480x270`) + the final extension. Ensure `thumbnails[*].column` points to an existing DB column and that `allowedColumns` includes all paths you write.

---

## Testing checklist

* ✅ Pattern with both tokens present → expected composite basename + rand.
* ✅ Pattern with one token missing → result without extra dashes.
* ✅ All tokens missing → sanitized client name + rand fallback.
* ✅ Extremely long inputs → result limited by `max`, suffix preserved.
* ✅ Non-ASCII characters → folded and sanitized into `[a-z0-9-]`.
* ✅ Multiple upload fields in one form → each field handled independently.

---

## FAQ

**Q:** Why not support `{id}` or `{ts}` tokens?
**A:** They add complexity and little value. Uniqueness is better handled by the standard `rand` suffix. Determinism remains, complexity drops.

**Q:** Can I keep the client filename exactly as is?
**A:** No. We always sanitize for safety and consistency. If you want to “preserve” it logically, rely on the fallback (sanitized original name) and disable `rand` only if collisions are acceptable (generally not recommended).

**Q:** Where should sanitization live long-term?
**A:** The helper can remain in the base controller for speed and proximity. If/when you introduce a dedicated `UploadService`, make the hook delegate to it to keep a single source of truth.
