If you experience this:

**Fatal error:**
`Fatal error: Uncaught RuntimeException: Provider class not found: CitOmni\Infrastructure\Provider\Services in C:\xampp\htdocs\citomni\kernel\src\App.php:137`

This means that **`CitOmni\Infrastructure\Provider\Services` cannot be autoloaded**.
It’s usually caused by one of the following issues — go through them in order:

---

# Quick Fix Checklist

1. **Is the package installed?**

   Run this in your app root:

   ```
   composer show citomni/common
   ```

   If it’s missing:

   ```
   composer require citomni/common:*@dev
   ```

   *(or whichever version you’re using)*

---

2. **Correct PSR-4 in `citomni/common`?**

   In `citomni/common/composer.json`, you should have:

   ```json
   "autoload": { "psr-4": { "CitOmni\\Common\\": "src/" } }
   ```

   And the file should be located at:
   `vendor/citomni/common/src/Provider/Services.php`
   with this **namespace**:

   ```php
   namespace CitOmni\Infrastructure\Provider;

   final class Services {
       public const MAP_HTTP = [/* ... */];
       public const MAP_CLI  = self::MAP_HTTP;
       public const CFG_HTTP = [/* ... */];
       public const CFG_CLI  = self::CFG_HTTP;
   }
   ```

---

3. **Is the whitelist in your app correct?**

   `/config/providers.php` must return a list of FQCNs:

   ```php
   <?php
   return [
       \CitOmni\Infrastructure\Provider\Services::class,
   ];
   ```

   (Always prefer `::class` over raw strings.)

---

4. **Is the autoloader updated?**

   After adding or moving files:

   ```
   composer dump-autoload -o
   ```

---

5. **Monorepo or path repository?**

   If you’re working in a monorepo and have *not* published `citomni/common`,
   your app’s `composer.json` must include a **repositories** entry:

   ```json
   "repositories": [
     { "type": "path", "url": "../common", "options": { "symlink": true } }
   ]
   ```

   then run:

   ```
   composer require citomni/common:*@dev
   ```

---

6. **Does the case/namespace match exactly?**

   PSR-4 is case-sensitive.
   Verify that folder names, filenames, and namespaces match **1:1**.

---

# Quick “Can Start Now” Workaround

If you need to continue **right now**,
temporarily comment out the common provider in `/config/providers.php`.
The app will boot, and you can manually register `db` / `log` in `/config/services.php`.
Once autoloading for `citomni/common` works, put the provider line back.

---

# Example of a Correct Provider (copy/paste)

**`citomni/common/src/Provider/Services.php`**

```php
<?php
declare(strict_types=1);

namespace CitOmni\Infrastructure\Provider;

final class Services {
	public const MAP_HTTP = [
		'db'    => \CitOmni\Infrastructure\Service\DbConnection::class,
		'log'   => \CitOmni\Infrastructure\Service\Log::class,
		'txt'   => \CitOmni\Infrastructure\Service\Txt::class,
		'cache' => \CitOmni\Infrastructure\Service\FileCache::class,
	];
	public const MAP_CLI  = self::MAP_HTTP;

	public const CFG_HTTP = [
		'db' => ['host' => 'localhost','user' => 'root','pass' => '','name' => 'citomni','charset' => 'utf8mb4'],
		'log' => ['dir' => '%var%/logs','level' => 'info','format' => 'json'],
	];
	public const CFG_CLI = self::CFG_HTTP;
}
```

---

If you’ve done all the above and it **still** fails, please the following to support@citomni.com:

* The contents of `/config/providers.php`
* Your app’s `composer.json` (autoload + any `repositories` section)
* The exact path + namespace of `Services.php` in `citomni/common`

...and we will try to pinpoint exactly what’s missing.
