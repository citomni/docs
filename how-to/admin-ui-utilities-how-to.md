# CitOmni Admin UI Utilities - How-To (JS, zero-deps)

> **Small, explicit, deterministic.**
> Admin UI utilities provide interaction primitives for CitOmni admin pages with *no dependencies* and *near-zero overhead*.

This document explains **what's included**, how to **use** and **configure** each utility, recommended **accessibility patterns**, and **recipes** for common admin tasks.

---

**Document type:** Technical Guide
**Version:** 1.0
**Applies to:** CitOmni ≥ 8.2 (Admin UI)
**Audience:** Application and provider developers
**Status:** Stable
**Package:** citomni/admin
**Author:** CitOmni Core Team
**Copyright:** © 2012-present CitOmni

---

## 1) What is it?

A tiny bundle of DOM utilities exposed as:

```js
window.CitOmniUI = { Toasts, Modal, Alerts };
```

They live directly in the admin base layout for **fast first paint**, no runtime imports, and minimal bytes over the wire. Scope is intentionally **narrow** (no framework, no polyfills):

* **Toasts** - transient notifications.
* **Modal** - accessible, lock-scroll dialogs (confirmations, long text).
* **Alerts** - inline, dismissible status blocks.

> Not included by design: table and form helpers (moved to per-page scripts), date/currency parsing, or animation libraries. Your pages remain lean; add extras only where needed.

---

## 2) Installation & exposure

Utilities are embedded in the admin layout and exported globally:

```html
<script>
  // ... definitions ...
  window.CitOmniUI = { Toasts, Modal, Alerts };
</script>
```

There's nothing to import on pages that inherit the layout. Use them directly in `{% yield page_content %}` and `{% yield page_scripts %}` blocks.

---

## 3) Toasts

### 3.1 API

```js
CitOmniUI.Toasts.push(message, kind?)
```

* `message` (string): Plain text shown in the toast.
* `kind` ('' | 'ok' | 'warn' | 'danger' | 'info'): Maps to CSS variants.
* Lifetime: 8 s (auto-removes).

### 3.2 Usage

```js
CitOmniUI.Toasts.push('Saved successfully', 'ok');
CitOmniUI.Toasts.push('Heads up: draft only', 'warn');
CitOmniUI.Toasts.push('Oops, something failed', 'danger');
```

### 3.3 Notes

* Toast container is `#toast`.
* Multiple toasts stack (grid gap).
* Keep messages short; toasts are for **ephemeral** info. Use **Alerts** for persistent content.

---

## 4) Modal

A deterministic dialog with scroll-lock, keyboard semantics, up to three buttons, and HTML support when needed.

### 4.1 API (options)

```ts
type ModalVariant = 'info'|'ok'|'warn'|'err'|'ghost'|'outline';

type ModalButton = {
  id: string;                    // unique key (e.g. 'confirm', 'cancel')
  label: string;                 // plain text
  variant?: ModalVariant;        // maps to .btn classes
  autofocus?: boolean;           // focus this button when opened
  onClick?: (ev: MouseEvent) => any;  // return value piped to resolver
};

type ModalOptions = {
  title?: string;                // default 'Confirm'
  body?: string;                 // default 'Are you sure?'
  titleHtml?: boolean;           // allow HTML in title (off by default)
  bodyHtml?: boolean;            // allow HTML in body  (off by default)
  buttons?: ModalButton[];       // 0..3; visual order
  enterAction?: string;          // button id triggered by Enter

  // Dismiss behavior
  dismissible?: boolean;         // master switch; default false
  backdropClose?: boolean;       // override master for backdrop
  escapeClose?: boolean;         // override master for ESC
};
```

### 4.2 Methods

```js
// Open a modal; resolves to boolean (default buttons)
// or { id, value } when custom buttons are used.
const result = await CitOmniUI.Modal.open(options);

// Programmatically close (rarely needed; buttons resolve naturally).
CitOmniUI.Modal.close(result?);
```

### 4.3 Behavior & contract

* **Scroll-lock:** `body.modal-lock` is toggled on open/close.
* **Focus:** First `autofocus` button or the first button is focused.
* **Keyboard:**

  * `Escape` closes **only** when `escapeClose === true` (or `dismissible === true`).
  * `Enter` triggers `enterAction` when provided.
* **Backdrop click:**

  * Closes **only** when `backdropClose === true` (or `dismissible === true`).
* **Buttons:**

  * 0 buttons -> default **Cancel/Confirm** (resolves `false`/`true`).
  * Custom buttons -> resolves `{ id, value }` where `value` is whatever `onClick` returns.

### 4.4 Examples

**Confirm (safe defaults, no accidental dismiss):**

```js
const ok = await CitOmniUI.Modal.open({
  title: 'Delete item',
  body: 'This cannot be undone.',
  // dismissible: false (default)
  enterAction: 'confirm'
});
if (ok === true) { /* proceed */ }
```

**Three buttons + Enter on "publish":**

```js
const res = await CitOmniUI.Modal.open({
  title: 'Publish changes?',
  body: 'Choose how to proceed.',
  buttons: [
    { id: 'cancel',  label: 'Cancel',  variant: 'ghost', autofocus: true },
    { id: 'draft',   label: 'Save as draft', variant: 'warn',
      onClick: () => ({ mode: 'draft' }) },
    { id: 'publish', label: 'Publish', variant: 'ok',
      onClick: () => ({ mode: 'publish' }) }
  ],
  enterAction: 'publish'
});
// res = { id: 'draft'|'publish'|'cancel', value: any }
```

**Long HTML body (scroll inside dialog, not page):**

```js
await CitOmniUI.Modal.open({
  title: 'License Terms',
  titleHtml: true,
  bodyHtml: true,
  body: `<h3>Terms</h3><p>Lots of <strong>HTML</strong>...</p>`,
  buttons: [
    { id: 'agree', label: 'Agree', variant: 'ok' },
    { id: 'decline', label: 'Decline', variant: 'err' }
  ],
  enterAction: 'agree'
});
```

### 4.5 Accessibility

* Markup uses `role="dialog"` + `aria-modal="true"` and `aria-labelledby="modalTitle"`.
* Consider adding `aria-describedby="modalBody"` on the `.panel`.
* Escape/backdrop behavior is **opt-in**, default **false**, to prevent accidental loss of user decisions.

---

## 5) Alerts

Inline, dismissible status elements (often used for flash messages, validation output, or persistent notices).

### 5.1 API

```js
// Create & append new alert to #alerts container.
const el = CitOmniUI.Alerts.add(type, message, opts?)
```

* `type`: `'info' | 'ok' | 'warn' | 'err'`
* `message`: string (plain by default; set `opts.raw` to allow HTML you trust)
* `opts`:

  * `raw?: boolean` - set `innerHTML` instead of `textContent`.
  * `timeoutMs?: number` - auto-dismiss after N ms.
  * `prepend?: boolean` - place at top instead of bottom.

### 5.2 Usage

```js
CitOmniUI.Alerts.add('ok', 'Profile updated.');
CitOmniUI.Alerts.add('warn', 'Draft only. Publish to go live.', { timeoutMs: 7000 });
CitOmniUI.Alerts.add('err', '<strong>Failed</strong> to save.', { raw: true, prepend: true });
```

### 5.3 Dismiss UI

* Each alert includes a close button (`×`).
* Keyboard focus lands on the close button naturally when tabbing through the page.
* Exiting animation adds `is-leaving` and removes the element after ~160 ms.

---

## 6) Extra utilities wired in the layout

While not exported on `CitOmniUI`, these behaviors are provided by the base layout:

* **Global Search**
  `Enter` inside the top-bar search navigates to `/admin/search?q=...`. `Escape` clears the field.
* **Responsive Sidebar (off-canvas)**
  Burger toggles the sidebar on small screens; body scroll locks; `Escape` closes when open; clicking a link auto-closes.
* **Button ripple**
  Lightweight ripple effect for `.btn` (pointer & keyboard). Disable per element with `data-ripple="off"`.

These keep navigation predictable and "quietly helpful." If you build a page without the base layout, you won't get these helpers.

---

## 7) Styling & theming

The utilities rely on your existing CSS variables:

* Status colors: `--brand`, `--ok`, `--warn`, `--danger`.
* Surfaces: `--panel`, `--border`, `--shadow*`.
* Modal scroll area is enforced with:

  * `.modal .panel { max-height: 90vh; display: grid; grid-template-rows: auto 1fr auto; }`
  * `.modal .panel .body { overflow: auto; }`
  * Body lock via `body.modal-lock { overflow: hidden; }`

Buttons use variants that map directly to classes:

* `info -> .btn.primary`
* `ok -> .btn.ok`
* `warn -> .btn.warn`
* `err -> .btn.danger`
* plus `ghost`, `outline` for link-like/outlined affordances.

---

## 8) Recipes

### 8.1 "Dangerous delete" (no accidental dismissal)

```js
const ok = await CitOmniUI.Modal.open({
  title: 'Delete this record?',
  body: 'This action cannot be undone.',
  // dismissible: false (default) => backdrop/ESC do nothing
  enterAction: 'confirm'
});
if (ok === true) deleteItem();
```

### 8.2 "Soft" info modal that the user can easily dismiss

```js
await CitOmniUI.Modal.open({
  title: 'Tip',
  body: 'Click outside or press Escape to close.',
  dismissible: true  // enables both backdrop + ESC
});
```

### 8.3 Multi-step confirm with result payload

```js
const { id, value } = await CitOmniUI.Modal.open({
  title: 'Export data',
  body: 'Choose format.',
  buttons: [
    { id:'csv',  label:'CSV',  variant:'info', onClick: () => ({ mime:'text/csv' }) },
    { id:'json', label:'JSON', variant:'ok',   onClick: () => ({ mime:'application/json' }) },
    { id:'cancel', label:'Cancel', variant:'ghost' }
  ],
  enterAction: 'json'
});
if (id !== 'cancel') startExport(value.mime);
```

### 8.4 Show validation summary (alerts)

```js
const list = '<ul><li>Title is required</li><li>Date must be in the future</li></ul>';
CitOmniUI.Alerts.add('err', `<strong>Fix the following:</strong>${list}`, { raw: true, prepend: true });
```

### 8.5 Notify success + fade away

```js
CitOmniUI.Alerts.add('ok', 'Saved', { timeoutMs: 2500 });
```

---

## 9) Accessibility checklist

* [ ] Modal: `aria-labelledby="modalTitle"` and (optional) `aria-describedby="modalBody"`.
* [ ] Provide **visible text** for icon-only buttons via `aria-label` (e.g., logout).
* [ ] Ensure focus lands inside modal on open (handled by utilities) and returns to a sensible place after close (trigger element or body).
* [ ] Alerts use `role="alert"` for SR announcement; don't spam-batch messages when possible.

---

## 10) Performance & safety

* Zero external deps, minimal DOM work, and no timers except toast auto-removal and short exit animations.
* All features are **opt-in**. No background observers or event storms.
* For HTML content in alerts/modals, only use `raw/titleHtml/bodyHtml` with trusted strings.

---

## 11) Troubleshooting

| Symptom                                          | Likely cause                                 | Fix                                                                       |
| ------------------------------------------------ | -------------------------------------------- | ------------------------------------------------------------------------- |
| Modal closes on Escape even when I don't want it | `escapeClose` defaults not set as intended   | Use `dismissible: false` (default) or set `escapeClose: false` explicitly |
| Clicking outside closes the modal                | You enabled `dismissible` or `backdropClose` | Remove `dismissible` or set `backdropClose: false`                        |
| Enter does nothing                               | `enterAction` missing or id mismatch         | Set `enterAction` to the **button id** you expect                         |
| My page scrolls while modal is open              | Body lock class missing                      | Ensure layout CSS includes `body.modal-lock { overflow: hidden; }`        |
| Alerts render HTML as text                       | Using plain mode                             | Pass `{ raw: true }` if you trust the HTML                                |

---

## 12) Extension points

* Add ARIA sort indicators, date/currency comparators, or advanced table behavior **per page** (keep the base clean).
* Wrap `CitOmniUI.Modal.open()` in domain-specific helpers (e.g., `confirmDelete(entityName)`).

---

## 13) Change management

* Utilities are considered **stable**. Additions will be **backwards-compatible** (new options default off).
* Breaking changes (unlikely) would be version-gated in the layout and documented in release notes.

---

## 14) Closing note

> The Admin UI utilities are intentionally small. They cover the 80%-notifications, confirmation, and readable errors-without dragging a framework into every page.
> Keep them sharp; build upwards only where a specific page needs more.

"Small parts, loosely joined." One page at a time. 
