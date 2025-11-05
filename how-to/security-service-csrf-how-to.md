# CitOmni Security Service - How-To (PHP 8.2+)

> **Simple, explicit, hard to misuse.**
> The Security service gives you deterministic CSRF protection and helpers that fit CitOmni's controller flow.

This guide explains **how to use the Security service** in everyday controller code and templates, plus the optional "belt-and-braces" patterns we recommend for production. We assume you already dispatch actions via the Router and render with the TemplateEngine. 

---

**Document type:** Technical Guide
**Version:** 1.0
**Applies to:** CitOmni ≥ 8.2 (HTTP mode)
**Audience:** Application and provider developers
**Status:** Stable
**Author:** CitOmni Core Team
**Copyright:** © 2012-present CitOmni

---

## 1) What the Security service does (and doesn't)

The Security service is intentionally small and explicit:

* **CSRF tokens**: create, embed, verify, clear.
* **View helper**: emit a correct `<input type="hidden">` with the configured CSRF field name.
* **Logging**: record failed verifications with useful request context.

It does **not**:

* Auto-wire global middleware magic.
* Guess your intent.
* Hide failures.

This aligns with CitOmni's controller philosophy: **controllers enforce security explicitly, fail fast, and let Response/ErrorHandler do the rest.** 

---

## 2) API overview (tl;dr)

```php
// Service ID: security

string  csrfToken();          // Ensure token exists in session; return it (64-char hex)
string  generateCsrf();       // Alias of csrfToken()

bool    verifyCsrf(?string $token); // Constant-time compare against session token

void    clearCsrf();          // Remove the token from session (rarely needed)
string  csrfHiddenInput();    // <input type="hidden" ...> with correct name+value

string  csrfFieldName();      // The configured field name (default: "csrf_token")

void    logFailedCsrf(string $action, array $extra = []); // Structured log helper
```

**Config keys (read via `$this->app->cfg->security`)**

* `csrf_protection: bool` (default: true)
* `csrf_field_name: string` (default: `"csrf_token"`)

---

## 3) Quick start (the 80% flow)

### 3.1 GET: render a form

In your controller action:

```php
public function create(): void {
	$this->app->response->adminHeaders(); // or memberHeaders(), or your own
	$this->app->tplEngine->render('admin/thing-create.html@your/layer', [
		// Nothing special needed if you use the template helper below
	]);
}
```

In the template:

```html
<form method="post" action="{{ $url('admin/thing-create.html') }}">
	{{{ $csrfField() }}}
	<!-- your inputs -->
	<button type="submit">Save</button>
</form>
```

Why it's nice:

* `{{{ $csrfField() }}}` asks the Security service to **emit the correct field name** and the **current token** in one go.
* If you ever rename the field in config, your templates stay correct.

> Heads-up: The TemplateEngine exposes `$csrfField()` globally (via `view.vars`), so you don't have to pass a token manually from the controller.

### 3.2 POST: verify + PRG

In your POST action:

```php
public function createPost(): void {
	// Read with the configured name to stay future-proof:
	$field = $this->app->security->csrfFieldName();
	$csrf  = (string)($this->app->request->post($field) ?? '');

	if (!$this->app->security->verifyCsrf($csrf)) {
		$this->app->security->logFailedCsrf('thing_create', ['uri' => $this->app->request->uri()]);
		$this->app->flash->error('Security token invalid or missing.');
		$this->app->response->redirect('thing-create.html', 303); // PRG
		return;
	}

	// ... persist, flash success, redirect to GET
	$this->app->flash->success('Created.');
	$this->app->response->redirect('thing-edit.html?id=' . $newId, 303);
}
```

Use **303 See Other** for PRG after POST-this avoids re-POST on refresh and plays nice with caches and intermediaries.

---

## 4) Patterns for real projects

### 4.1 One-liner embed in templates

Prefer the built-in helper:

```html
{{{ $csrfField() }}}
```

You **do not** need to pass `'token' => $this->app->security->generateCsrf()` to templates when using the helper. The helper will both **ensure** the session token exists and **render** the correct hidden input.

### 4.2 Reading the token safely in controllers

Avoid hard-coding `"csrf_token"`. Either:

```php
$token = (string)$this->app->request->post($this->app->security->csrfFieldName());
```

...or adopt a tiny convenience helper in your base controller:

```php
/**
 * Read CSRF token from the current request using the configured field name.
 */
protected function readCsrfFromRequest(): string {
	return (string)($this->app->request->post($this->app->security->csrfFieldName()) ?? '');
}
```

Then:

```php
$csrf = $this->readCsrfFromRequest();
if (!$this->app->security->verifyCsrf($csrf)) { /* handle */ }
```

### 4.3 PRG everywhere you mutate state

* POST: validate/normalize -> **flash** messages/old input -> **redirect (303)** back to a GET.
* GET: render template with `{{{ $csrfField() }}}`.

This mirrors the Controllers guide's emphasis on **explicit, deterministic flows** and keeps navigation + refresh safe. 

### 4.4 Logging failed CSRFs (actionable context)

When verification fails:

```php
$this->app->security->logFailedCsrf('profile_update', [
	'user_id' => (int)($this->app->session->get('user_id') ?? 0),
]);
```

Your structured log will include IP, method, URI, referer, user-agent, timestamp, the configured field name, and any extras you pass. This helps distinguish genuine user/session issues from bot noise.

### 4.5 Clearing/rotating tokens (rare)

* `clearCsrf()` removes the field from the session.
  You typically **don't** need this, but it's handy if you purposely reset session state after sensitive flows.

> Token **creation is idempotent** via `csrfToken()/generateCsrf()`-it returns the existing token or creates one.

---

## 5) End-to-end example (Create + Edit + Delete)

All three use the same rhythm:

**Templates** (excerpt):

```html
<form method="post" action="{{ $url('admin/featured-video-edit.html', { id: row.id }) }}">
	{{{ $csrfField() }}}
	<!-- inputs -->
	<button type="submit">Save</button>
</form>

<form method="post" action="{{ $url('admin/featured-video-delete.html', { id: row.id }) }}">
	{{{ $csrfField() }}}
	<button type="submit">Delete</button>
</form>
```

**Controllers** (excerpt):

```php
public function editPost(): void {
	$csrf = $this->readCsrfFromRequest();
	if (!$this->app->security->verifyCsrf($csrf)) {
		$this->app->flash->error('Security token invalid or missing.');
		$this->app->response->redirect('featured-video-edit.html?id=' . $id, 303);
		return;
	}
	// ... do update, then PRG
	$this->app->flash->success('Saved.');
	$this->app->response->redirect('featured-video-edit.html?id=' . $id, 303);
}

public function deletePost(): void {
	$csrf = $this->readCsrfFromRequest();
	if (!$this->app->security->verifyCsrf($csrf)) {
		$this->app->flash->error('Security token invalid or missing.');
		$this->app->response->redirect('featured-video-list.html', 303);
		return;
	}
	// ... delete, then PRG
	$this->app->flash->success('Deleted.');
	$this->app->response->redirect('featured-video-list.html', 303);
}
```

---

## 6) Frequently used snippets

### 6.1 Emitting a token without the helper (if you really must)

```php
$csrfName  = $this->app->security->csrfFieldName();
$csrfValue = $this->app->security->csrfToken();
echo '<input type="hidden" name="' . htmlspecialchars($csrfName, ENT_QUOTES, 'UTF-8') .
     '" value="' . htmlspecialchars($csrfValue, ENT_QUOTES, 'UTF-8') . '">';
```

### 6.2 Feature-flagged CSRF for JSON APIs or internal tools

```php
if ($this->app->cfg->security->csrf_protection ?? true) {
	$token = $this->readCsrfFromRequest();
	if (!$this->app->security->verifyCsrf($token)) {
		$this->app->response->jsonProblem('Invalid CSRF token.', 400);
	}
}
```

### 6.3 Combine with role gating and headers

```php
protected function init(): void {
	if (!$this->app->role->atLeast('operator')) {
		$this->app->response->redirect('../login.html');
	}
}
public function get(): void {
	$this->app->response->adminHeaders();
	$this->app->tplEngine->render('admin/page.html@your/layer');
}
```

(Keep concerns separate: **access control** in `init()`, **headers** per view, **CSRF** per POST.) 

---

## 7) Troubleshooting

| Symptom                                            | Likely cause                                        | Fix                                                                                                                                                              |
| -------------------------------------------------- | --------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| "Security token invalid or missing." on first load | Form rendered without session being started? (rare) | The Security service ensures a session on `csrfToken()`; use the helper `{{{ $csrfField() }}}` or call `generateCsrf()` before rendering.                        |
| Works locally, fails behind a proxy                | Cookie/session issues                               | Ensure your session and cookie config match your deployment (SameSite, domain, secure flags). The Security service itself is stateless beyond the session value. |
| CSRF passes but POST re-submits on refresh         | Using 302 or returning HTML after POST              | Use **303 See Other** redirect (PRG).                                                                                                                            |
| Random bot noise in logs                           | Internet                                            | That one's on the robots. Use `logFailedCsrf()` to keep an audit trail, and consider rate-limiting at your edge if needed.                                       |

---

## 8) Best practices

* **Always embed via `{{{ $csrfField() }}}`.** It's concise and future-proof.
* **Read the token via `csrfFieldName()`**, not a hard-coded string.
* **Verify before doing anything** that mutates state.
* **PRG after every POST** (use 303).
* **Log verification failures** with context-future-you will thank present-you.
* **Don't clear tokens** unless you have a specific reason; idempotent creation is cheap.

---

## 9) Checklist

* [ ] Templates use `{{{ $csrfField() }}}` in every form.
* [ ] Controllers read the token with `csrfFieldName()` (or `readCsrfFromRequest()`).
* [ ] `verifyCsrf()` guards every POST/PUT/PATCH/DELETE action.
* [ ] PRG is enforced with `redirect(..., 303)`.
* [ ] Failures call `logFailedCsrf()` with a meaningful action label.
* [ ] Role gating and headers are declared explicitly and separately. 

---

## 10) Closing note

Security should be **boringly correct**. Keep it explicit, keep it visible, and make the safe path the easy path. The Security service exists so you can write the same three lines-**embed, read, verify**-with confidence, every time.
