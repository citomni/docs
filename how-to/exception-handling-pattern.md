# CitOmni: Exception Handling Pattern (Controller -> Model -> Util -> Flash -> Template)

## Why this pattern exists

* **Util/Model/Service** know *what is wrong* (validation/normalization) -> they throw a **precise exception** with a localized message from `$app->txt->get(...)`.
* **Controller** knows *what the user should see and where to go next* -> it catches **expected** exceptions, stores flash + old input, and redirects.
* **ErrorHandler** catches everything else (unexpected exceptions) and renders/logs safely.

This keeps code strict, fast, and predictable.

---

## 1) Util: Strict normalizer that throws localized exceptions

**File:** `src/Util/InputNormalizer.php`

```php
<?php
declare(strict_types=1);

namespace App\Util;

use CitOmni\Kernel\App;

final class InputNormalizer {

	/**
	 * Normalize a UI decimal string to a DB dot-decimal string (DECIMAL-safe).
	 *
	 * @param App $app The app instance (needed for txt service).
	 * @param mixed $value Typical UI input (string).
	 * @param int $precision DECIMAL precision.
	 * @param int $scale DECIMAL scale.
	 * @return string|null DB-ready decimal string or null when empty.
	 *
	 * @throws \InvalidArgumentException When input is invalid.
	 */
	public static function decimalToDb(App $app, mixed $value, int $precision, int $scale): ?string {
		if ($value === null) {
			return null;
		}

		$raw = \trim((string)$value);
		if ($raw === '') {
			return null;
		}

		$first = $raw[0] ?? '';
		if ($first === '-') {
			throw new \InvalidArgumentException(
				$app->txt->get(
					'err_negative_not_allowed',
					'input_normalizer',
					'app',
					'Invalid value: Negative numbers are not allowed.'
				)
			);
		}

		// Delegate strict parsing to a service that already throws i18n messages.
		// This may throw \InvalidArgumentException (expected for user input errors).
		return $app->formatNumber->toDb($raw, $precision, $scale);
	}

	/**
	 * Normalize a required string field (trim + non-empty).
	 *
	 * @param App $app The app instance (needed for txt service).
	 * @param mixed $value Typical UI input.
	 * @param int $maxLen Max length (UTF-8 safe).
	 * @return string Normalized string.
	 *
	 * @throws \InvalidArgumentException When empty.
	 */
	public static function requiredString(App $app, mixed $value, int $maxLen = 255): string {
		$str = \trim((string)$value);
		if ($str === '') {
			throw new \InvalidArgumentException(
				$app->txt->get(
					'err_required_field',
					'input_normalizer',
					'app',
					'Invalid value: This field is required.'
				)
			);
		}

		if (\mb_strlen($str, 'UTF-8') > $maxLen) {
			$str = \mb_substr($str, 0, $maxLen, 'UTF-8');
		}

		return $str;
	}
}
```

**Design notes:**

* Util is strict and fail-fast.
* Util does not do flash/redirect/render.
* We pass `$app` explicitly so Util can call `$app->txt->get(...)`.

---

## 2) Model: Calls Util and throws localized exceptions too (when Model-level rules fail)

**File:** `src/Model/ExampleModel.php`

```php
<?php
declare(strict_types=1);

namespace App\Model;

use CitOmni\Infrastructure\Model\BaseModelLiteMySQLi;
use App\Util\InputNormalizer;

final class ExampleModel extends BaseModelLiteMySQLi {

	private const TABLE = 'example_table';

	/**
	 * Save normalized fields.
	 *
	 * @param int $id Entity ID.
	 * @param array $fields Whitelisted UI fields.
	 * @param int|null $userId Actor.
	 * @return bool True on success or idempotent no-change.
	 *
	 * @throws \InvalidArgumentException For user-fixable validation errors.
	 */
	public function save(int $id, array $fields, ?int $userId = null): bool {
		if ($id <= 0) {
			throw new \InvalidArgumentException(
				$this->app->txt->get(
					'err_invalid_id',
					'example_model',
					'app',
					'Invalid id.'
				)
			);
		}

		// Model-level normalization (delegates to Util).
		$title = InputNormalizer::requiredString($this->app, $fields['title'] ?? null, 120);
		$amount = InputNormalizer::decimalToDb($this->app, $fields['amount'] ?? null, 14, 2);

		// Model-level rule example (beyond formatting):
		// "amount" must be provided in this use-case.
		if ($amount === null) {
			throw new \InvalidArgumentException(
				$this->app->txt->get(
					'err_amount_required',
					'example_model',
					'app',
					'Invalid value: Amount is required.'
				)
			);
		}

		$now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');

		$data = [
			'title'      => $title,
			'amount'     => $amount,
			'updated_at' => $now,
			'updated_by' => $userId,
		];

		$affected = $this->db->update(self::TABLE, $data, 'id = ?', [$id]);
		if ($affected > 0) {
			return true;
		}

		// Idempotent: Consider it ok if row exists.
		return $this->db->exists(self::TABLE, 'id = ?', [$id]);
	}
}
```

**Design notes:**

* The Model can throw its own localized exceptions too.
* It's fine that Util throws localized exceptions: They bubble unchanged.
* Still no flash/redirect/render here.

---

## 3) Controller: Catch expected exceptions, set flash + old, redirect (PRG)

**File:** `src/Http/Controller/ExampleController.php`

```php
<?php
declare(strict_types=1);

namespace App\Http\Controller;

use CitOmni\Kernel\Controller\BaseController;

final class ExampleController extends BaseController {

	public function edit(): void {
		// GET page: pull flash and render template.
		$flash = $this->app->flash->pullAll();

		$msg = (array)($flash['msg'] ?? []);
		$old = (array)($flash['old'] ?? []);

		// errors: normalize to array<string>
		$errors = $msg['error'] ?? [];
		if (\is_string($errors)) {
			$errors = \trim($errors) !== '' ? [$errors] : [];
		} elseif (!\is_array($errors)) {
			$errors = [];
		}

		// success/info: normalize to string (keep it simple for the template)
		$flashSuccess = $msg['success'] ?? '';
		if (\is_array($flashSuccess)) {
			$flashSuccess = \implode(' ', \array_map('strval', $flashSuccess));
		} else {
			$flashSuccess = (string)$flashSuccess;
		}

		$flashInfo = $msg['info'] ?? '';
		if (\is_array($flashInfo)) {
			$flashInfo = \implode(' ', \array_map('strval', $flashInfo));
		} else {
			$flashInfo = (string)$flashInfo;
		}

		$this->app->tplEngine->render('example/edit.html@app', [
			'language'      => $this->app->cfg->locale->language ?? 'en',
			'flash_success' => $flashSuccess,
			'flash_info'    => $flashInfo,
			'errors'        => $errors,
			'old'           => $old,
		]);
	}

	public function save(): void {
		if ($this->app->request->method() !== 'POST') {
			$this->app->response->redirect('/example/edit.html');
			return;
		}

		$request = $this->app->request;

		$id = (int)($request->post('id') ?? 0);
		if ($id <= 0) {
			$this->app->flash->error(
				$this->app->txt->get('err_invalid_id', 'example_controller', 'app', 'Invalid id.')
			);
			$this->app->response->redirect('/example/edit.html');
			return;
		}

		$fields = $request->only([
			'title',
			'amount',
		], 'post');

		$model = new \App\Model\ExampleModel($this->app);

		try {
			$ok = $model->save($id, $fields, null);
		} catch (\InvalidArgumentException $e) {
			// Expected: User-fixable input issues from Util/Model/services.
			$this->app->flash->old($fields);
			$this->app->flash->error($e->getMessage());
			$this->app->response->redirect('/example/edit.html?id=' . $id);
			return;
		}

		if ($ok) {
			$this->app->flash->success(
				$this->app->txt->get('ok_saved', 'example_controller', 'app', 'Saved.')
			);
		} else {
			$this->app->flash->old($fields);
			$this->app->flash->error(
				$this->app->txt->get('err_save_failed', 'example_controller', 'app', 'Could not save. Please try again.')
			);
		}

		$this->app->response->redirect('/example/edit.html?id=' . $id);
	}
}
```

**Rules of thumb for Controllers:**

* Catch **only** the exceptions you expect and can convert to UX.
* Do **not** catch `\Throwable` broadly just to show a flash message; unexpected errors belong to `ErrorHandler`.

---

## 4) Template: Render flash like your real templates

**File:** `templates/example/edit.html`

This matches the pattern you already use: `flash_success`, `flash_info`, `errors`, and `old`.

```html
{% if !empty($flash_success) %}
	<div class="alert alert--success" role="status">{{ $flash_success }}</div>
{% endif %}

{% if !empty($flash_info) %}
	<div class="alert" role="status">{{ $flash_info }}</div>
{% endif %}

{% if !empty($errors) %}
	<div class="alert alert--error" role="alert" aria-live="assertive">
		<ul style="margin:0; padding-left: 18px;">
			{% foreach ($errors as $e) %}<li>{{ $e }}</li>{% endforeach %}
		</ul>
	</div>
{% endif %}

<form action="{{ $url("example/save") }}" method="post" novalidate>
	{{{ $csrfField() }}}

	<div class="field">
		<label class="label" for="title">{{ $txt('title_label', 'example', 'app', 'Title') }}</label>
		<input class="input" type="text" id="title" name="title" value="{{ $old['title'] ?? '' }}">
	</div>

	<div class="field">
		<label class="label" for="amount">{{ $txt('amount_label', 'example', 'app', 'Amount') }}</label>
		<input class="input" type="text" id="amount" name="amount" value="{{ $old['amount'] ?? '' }}">
	</div>

	<button class="btn btn--primary" type="submit">{{ $txt('save', 'example', 'app', 'Save') }}</button>
</form>
```

---

## 5) What ErrorHandler does (and what it should not do)

* Your Controller catches predictable, user-fixable errors and shows them via flash.
* Anything else (uncaught exceptions, PHP errors, fatal shutdown errors) will bubble up to the HTTP `ErrorHandler`, which:

  * Logs safely (JSONL)
  * Renders a safe HTML/JSON response
  * Exits

**Do not** route "normal validation" through `ErrorHandler`. That will turn a user typo into a 500-style error flow, and it breaks PRG/flash UX.

---

## Recommended localizable message strategy (pragmatic)

Because Util/Model now call `txt->get(...)` directly, the simplest stable approach is:

* **Util/Model throw already-final user messages** (`$e->getMessage()` is safe to show).
* Controller does not need to map codes -> it just flashes the message.

If later you want "structured errors" (field errors, error codes, etc.), you can still keep the same architecture, but then you'd throw a richer domain exception (still caught in Controller).

---

## Minimal checklist

* Util throws `InvalidArgumentException` with `$app->txt->get(...)`.
* Model throws `InvalidArgumentException` with `$this->app->txt->get(...)`.
* Controller catches `InvalidArgumentException`, sets `flash->old()` and `flash->error()`, redirects.
* GET action pulls flash and passes `flash_success`, `flash_info`, `errors`, `old` into template.
* Template displays flash and uses `old` for inputs.
* Everything not caught: `ErrorHandler`.
