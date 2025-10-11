Hvis du oplever det her:

"Fatal error: Uncaught RuntimeException: Provider class not found: CitOmni\Infrastructure\Provider\Services in C:\xampp\htdocs\citomni\kernel\src\App.php:137"

Så betyder det, at **`CitOmni\Infrastructure\Provider\Services` ikke kan autoloades**. Typisk skyldes det én af disse årsager. Gå dem igennem i rækkefølge:


# Quick fix-tjekliste

1. **Er pakken installeret?**

   * Kør i app-roden:
     `composer show citomni/common`
     Hvis den ikke findes:
     `composer require citomni/common:*@dev` *(eller den version du har)*

2. **Korrekt PSR-4 i `citomni/common`?**
   I `citomni/common/composer.json` skal der være:

   ```json
   "autoload": { "psr-4": { "CitOmni\\Common\\": "src/" } }
   ```

   Og filen skal ligge her:
   `vendor/citomni/common/src/Provider/Services.php`
   med **namespace**:

   ```php
   namespace CitOmni\Infrastructure\Provider;

   final class Services {
       public const MAP_HTTP = [/* ... */];
       public const MAP_CLI  = self::MAP_HTTP;
       public const CFG_HTTP = [/* ... */];
       public const CFG_CLI  = self::CFG_HTTP;
   }
   ```

3. **Whitelisten i app’en er korrekt?**
   `/config/providers.php` skal returnere en liste af FQCNs:

   ```php
   <?php
   return [
       \CitOmni\Infrastructure\Provider\Services::class,
   ];
   ```

   (Brug gerne `::class` fremfor rå streng.)

4. **Autoloader er opdateret?**
   Efter at have tilføjet/movet filer:
   `composer dump-autoload -o`

5. **Monorepo / path-repo?**
   Hvis du kører monorepo og *ikke* har publiceret `citomni/common`, så skal app’ens `composer.json` have en **repositories**-entry:

   ```json
   "repositories": [
     { "type": "path", "url": "../common", "options": { "symlink": true } }
   ]
   ```

   og derefter: `composer require citomni/common:*@dev`

6. **Case/namespace matcher?**
   PSR-4 er case-sensitivt i mapping. Tjek at mappenavne, filnavn og namespace stemmer 1:1.

---

# Hurtig “kan starte nu”-workaround

Hvis du vil videre **med det samme**, så kommentér midlertidigt common-provider’en ud i `/config/providers.php`. App’en booter, og du kan i stedet registrere `db`/`log` manuelt i `/config/services.php`. Når autoload af `citomni/common` er på plads, sætter du provider-linjen ind igen.

---

# Eksempel på korrekt provider (copy/paste)

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

Hvis du har gjort ovenstående og den **stadig** fejler, så send følgende til support@citomni.com:

* indholdet af `/config/providers.php`
* din app’s `composer.json` (autoload + evtl. repositories)
* stien + namespace af `Services.php` i `citomni/common`

...så vil vi forsøge at udpeger præcist det, der mangler.
