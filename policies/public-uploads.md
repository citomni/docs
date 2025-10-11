# CitOmni Public Uploads — `/uploads/` & `user_uploadids`

**Policy, schema, and hands-on usage guide**

> **Baseline:** `/uploads/` is **public** by design (web-reachable). Only store files here that are safe to share publicly (e.g., avatars, web images meant for open access).
> Sensitive documents (receipts, invoices, IDs, contracts, etc.) belong **outside** webroot (e.g., `storage/private/`) and must be served via a PHP controller with access control (ideally using X-Sendfile/X-Accel-Redirect).

---

## 1) TL;DR

* **Public area:** `/uploads/`
* **Per-user root:** `/uploads/u/{token}/…` where `{token}` is an opaque, random, unique folder ID.
* **DB mapping:** `user_uploadids` holds **one row per user** (1:1) with the user’s current `{token}`.
* **Security:** no script execution, no directory listing, strict MIME validation at upload time.
* **Rotation:** rare; if needed, rename the folder and update the token in DB atomically.

---

## 2) Scope & definitions

* **Public uploads**: Files intentionally visible to anyone with the URL (e.g., profile pictures, CMS web images).
* **Private files**: Anything confidential. Store at `storage/private/...` and gate through a controller.
* **Token**: A random, non-guessable string (e.g., ULID/base32/base62) that identifies a user’s public upload folder without leaking internal IDs.

---

## 3) Directory layout (public)

```
public/
└─ uploads/
   └─ u/
      └─ {token}/
         ├─ profile/                 # user-visible public assets (e.g., avatars)
         ├─ media/images/YYYY/MM/    # optional editorial web assets
         ├─ _cache/                  # generated thumbnails/derivatives
         └─ tmp/                     # transient uploads; cleaned regularly
```

> You can add date-based folders (YYYY/MM) where appropriate to keep things tidy.
> Avoid personally identifiable information (PII) in file or folder names.

---

## 4) What belongs in `/uploads/` vs private storage

* **Good fit for `/uploads/`**
  Avatars, generic web images, decorative media, and other assets intended for public pages.
* **Keep OUT of `/uploads/`**
  Accounting documents, receipts, invoices, IDs, contracts, customer exports, anything confidential.
  Put these under `storage/private/...` and serve via a controller (authz + X-Sendfile/X-Accel-Redirect).

---

## 5) Database schema — `user_uploadids` (simple 1:1)

**MySQL/MariaDB DDL (portable to Postgres/SQL Server with minor syntax changes):**

```sql
CREATE TABLE `user_uploadids` (
  `user_id`   INT(11) NOT NULL,                               -- FK → user_account.id
  `token`     CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),                                     -- exactly one row per user
  UNIQUE KEY `uq_uploadids_token` (`token`),
  CONSTRAINT `fk_uploadids_user`
    FOREIGN KEY (`user_id`) REFERENCES `user_account`(`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

**Notes**

* **Token format**: ULID (26 chars) is a great default. Base32/base62 (22–26 chars) also works.
* **Case handling**: Use ASCII + binary collation to keep comparisons small and exact.
* **NULLs**: There’s **no row** until the user first uploads; no NULL/UNIQUE corner cases.

---

## 6) URL & path conventions

* **User public root**: `/uploads/u/{token}/`
* **Avatar variants** (example):

  * `/uploads/u/{token}/profile/avatar-orig.jpg`
  * `/uploads/u/{token}/profile/avatar-w200.webp`
  * `/uploads/u/{token}/profile/avatar-w800.webp`

**File naming**

* Prefer stable logical names + variant suffixes (`-orig`, `-w200`, `-w800`, `-square`…), not user-provided file names.
* No PII in names.
* Use WebP/AVIF for scaled variants; keep an original (JPG/PNG) if you need to re-render.

---

## 7) Allocation & rotation flows

### First upload (lazy allocation)

1. Generate an opaque token (ULID/base32/base62 with ≥128 bits of entropy).
2. Insert if absent:

   ```sql
   INSERT INTO user_uploadids (user_id, token)
   VALUES (:uid, :tok)
   ON DUPLICATE KEY UPDATE token = token; -- no change if row already exists
   ```
3. Build the folder path `/uploads/u/{token}/...` and store the file(s).

### Lookup

```sql
SELECT token FROM user_uploadids WHERE user_id = :uid;
```

### Rotation (rare)

If you need to change the visible path (e.g., to break stale/hotlinked URLs):

1. Generate a new token.
2. **Filesystem rename**: `/uploads/u/{old}` → `/uploads/u/{new}` (atomic on same filesystem).
3. Update DB:

   ```sql
   UPDATE user_uploadids SET token = :new WHERE user_id = :uid;
   ```
4. (Optional) Clear related caches/CDN where necessary.

> Because `/uploads/` is public-only by policy, rotations should be rare. Prefer immutable filenames for variants to leverage caching.

---

## 8) Security hardening (public area)

### Apache (`/uploads/.htaccess`)

```apache
# Disable script execution
Options -ExecCGI
AddType text/plain .php .phtml .pht .phar .shtml .cgi .pl .py .sh .bash
RemoveHandler .php .phtml .pht .phar .shtml .cgi .pl .py .sh .bash
php_flag engine off

# No directory listing
Options -Indexes

# Optional: block access to internal folders
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule ^(?:_cache/file/|tmp/|\.|_private/|_quarantine/) - [F,L]
</IfModule>
```

### Nginx (snippet)

```nginx
location ^~ /uploads/ {
    autoindex off;
    location ~ \.(php|phtml|phar|pl|py|cgi|sh|bash)$ { return 403; }
}
```

**Upload-time checks (in PHP)**

* Validate MIME via `finfo` (not just extension).
* Enforce a whitelist of types (e.g., `image/jpeg`, `image/png`, `image/webp`, `application/pdf` if appropriate).
* Normalize/sanitize final filenames (lowercase, `[a-z0-9._-]`).
* Strip metadata if needed (e.g., EXIF) for privacy.

---

## 9) Caching & CDN

* Treat public files as **immutable** by naming convention (new content → new filename).
* Send `Cache-Control: public, max-age=31536000, immutable` on static variants.
* Use ETags or strong caching only when filenames are stable; don’t rely on query strings for busting.
* For HTML templates, always reference the latest variant names.

---

## 10) Scaling & sharding

* With per-user folders, you rarely need sharding inside each user’s folder.
* If a single folder grows extremely large (tens of thousands of files), add a trivial shard layer:

  ```
  /uploads/u/{token}/images/ab/cd/…   # where abcd are the first 4 chars of a file hash
  ```
* Keep directory counts reasonable for backup/antivirus/rsync tools.

---

## 11) Operational guidelines

* **Backups**: Include `/uploads/` in backups; test restore.
* **Housekeeping**: Periodically clear `tmp/` and regenerate/remove stale `_cache/` variants.
* **Logging**: Record upload events (user, file size, MIME, path) for auditability.
* **Multi-environment**: Never share `/uploads/` across environments. Keep per-env roots (dev/stage/prod).
* **Tenant isolation** (if multi-tenant): Token already prevents ID leakage; do not mix tenants under the same token space if they share a DB.

---

## 12) Example snippets

**Allocate token on first upload (SQL only):**

```sql
-- assume :uid is the user id and :tok is a freshly generated token
INSERT INTO user_uploadids (user_id, token)
VALUES (:uid, :tok)
ON DUPLICATE KEY UPDATE token = token;
```

**Fetch URL base in PHP (illustrative):**

```php
// Pseudocode: get {token} then build URLs
$token = $db->fetchOne('SELECT token FROM user_uploadids WHERE user_id = ?', [$userId]);
$base  = '/uploads/u/' . $token;

// Variants:
$avatarOrig = $base . '/profile/avatar-orig.jpg';
$avatarW200 = $base . '/profile/avatar-w200.webp';
```

**Rotate (rare):**

```php
// 1) generate $newToken
// 2) rename on disk from /uploads/u/$old → /uploads/u/$new (same filesystem)
// 3) UPDATE user_uploadids SET token = :new WHERE user_id = :uid
```

---

## 13) FAQ

**Q: Can we store confidential files in `/uploads/` if we obfuscate paths?**
A: No. Obfuscation is not access control. Confidential files belong under `storage/private/` and must be gated by a controller.

**Q: Can `token` be NULL until first upload?**
A: In this 1:1 design, there is **no row** until first upload; no NULL values involved.

**Q: What if two users collide on the same token?**
A: With ≥128-bit random tokens this is astronomically unlikely. The unique index guards against it. If it happens, generate a new token and retry.

**Q: SQL Server compatibility?**
A: This table design works fine on SQL Server. Adjust types/collations as needed. (The “no unique-with-NULL” caveat does not apply here because there are no NULLs in `token` and we use `PRIMARY KEY (user_id)`.)

---

## 14) Quick checklist

* [ ] `/uploads/` exists and is web-reachable.
* [ ] `.htaccess`/Nginx rules block script execution & directory listing.
* [ ] `user_uploadids` created with PK(user\_id) + UNIQUE(token).
* [ ] Upload flow lazily creates row with new token if absent.
* [ ] File names are sanitized and contain no PII.
* [ ] Private files never touch `/uploads/`; they live under `storage/private/` behind an access-checked controller.
* [ ] Backups & cleanup jobs scheduled (`tmp/`, `_cache/`).

---

**That’s it.**
This setup keeps the public surface clean and safe, avoids ID leakage, scales well, and remains simple to operate.
