# CitOmni – Maintenance Mode: Arkitektur, performance og begrundelse

**Formål:** Denne rapport dokumenterer, hvorfor CitOmni anvender en lille *flag-fil* (`maintenance.php` der returnerer et array) som sandhedskilde for maintenance mode, indlæst én gang pr. request via OPcache. Rapporten tjener både som intern hukommelse (når tvivlen opstår igen) og som teknisk baggrund, der kan deles.

---

## TL;DR
- **Valgt model:** Lille PHP-flagfil, som kernel inkluderer og lægger i `$app->cfg->maintenance`. Ved toggling skrives filen atomisk + `opcache_invalidate()`.
- **Performance (målt):** ~**0,000011–0,000013 s** pr. request (≈ **11–13 µs**) på shared hosting m. OPcache (one.com/simply.com). p95 ~11–19 µs. Sporadiske outliers er OS-jitter og irrelevante for steady-state.
- **Konklusion:** Overhead er reelt **“gratis”**. En konstant i `cfg.php` ville kun være teoretisk hurtigere og praktisk dårligere (toggle-friktion, større blast radius).
- **Drift:** Toggling er sikker (atomisk write, optional backup, OPcache-invalidate). Fungerer på almindeligt shared hosting uden særkonfiguration.
- **Alternativer:** APCu kan bruges opportunistisk som bonus (hvis til stede) men er *ikke* et krav.

---

## 1) Baggrund og krav
**Krav til CitOmni:**
- Skal fungere på **shared hosting** uden root eller særlige PHP-udvidelser.
- **Maintenance OFF** må i praksis ikke koste noget.
- **Toggling** skal være robust, idempotent og hurtigt synlig (uden genstart af PHP-FPM/Apache).
- **Audit & driftssikkerhed:** Mulighed for logning, simple backups, deterministisk adfærd.
- **PHP 7.4+** kompatibilitet, PSR-1/4 m.fl.

---

## 2) Den valgte arkitektur (kort)
1. **Flag-fil** (fx `.../var/flags/maintenance.php`) returnerer et array: `['enabled'=>bool,'allowed_ips'=>[],'retry_after'=>int]`.
2. **Kernel** indlæser flagfilen via `include` → OPcache leverer opcodes fra shared memory.
3. **Guard** kaldes meget tidligt: Hvis `enabled===true` og klient-IP ikke er whitelisted → send 503 + Retry‑After og exit. Ellers normal boot.
4. **Toggling** (enable/disable): Skriver ny flagfil **atomisk** (tmp+rename), optional backup-rotation, kalder `opcache_invalidate()` for immediate visibility.

**Egenskaber:**
- Normal drift (OFF): én `include` af tiny fil (OPcache‑hit) + boolean‑tjek.
- Maintenance ON: Samme check + lille `in_array()` på kort whitelist.
- Toggling: begrænset og kontrolleret disk‑I/O.

---

## 3) Performance-model (hvad koster det i teorien?)
- **OPcache** betyder: Flagfilen parses/kompileres **én gang**. Derefter hentes bytecode fra shared memory.
- **Stat‑checks**: Med default `opcache.revalidate_freq` (typisk 2 s) sker der *ikke* en `stat()` på hver eneste request.
- **Inkluderings‑overhead** reduceres til en hash‑lookup + pointer til eksisterende opcodes.
- **Forventning:** 5–20 µs pr. include på moderne shared hosting.

---

## 4) Benchmarks (målt i produktion)
**Setup:** Standalone micro-benchmark (`bench_maint_include.php`) der:
- Genererer en minimal flagfil som i CitOmni.
- Måler `include $flagPath;` *N* gange og udskriver mean/median/p95/min/max.
- *Normal mode* (typisk i produktion): OPcache er varm, **ingen** invalidation i loopet.

**Resultater (uddrag):**
- **Mean:** 0,000011–0,000013 s (11–13 µs)
- **Median:** ~0,000011 s
- **p95:** 0,000011–0,000019 s
- **Max:** Lejlighedsvise spikes (0,00016–0,00065 s) — forklares af OS‑scheduling/jitter/hypervisor og påvirker ikke steady‑state.

**Tolkning:** Tallene matcher forventningen. I praksis er overhead **uafhængig af app‑logikken** og langt under støjniveauet fra fx database, netværk, templating osv.

---

## 5) Hvorfor ikke en konstant i `cfg.php`?
**Teoretisk** lidt billigere (eliminerer ét `include`), men **praktisk dårligere**:
- **Toggle‑friktion:** Du skal skrive til `cfg.php` (større fil, større blast radius, risiko for VCS‑støj/konflikter).
- **Samme krav til OPcache:** Du skal stadig `opcache_invalidate(cfg.php)` for øjeblikkelig synlighed.
- **Minimal gevinst:** Forskellen mellem ~11 µs og “~0 µs” er ikke målbar i den virkelige verden.

**Konklusion:** Flagfilen er det bedste kompromis mellem hastighed, isolering og driftsikkerhed.

---

## 6) Alternative modeller (vurdering)
**APCu (userland cache i RAM)**
- **Pro:** Nanosekund/mikrosekund‑opslag, nul disk‑I/O pr. request.
- **Con:** Ikke garanteret på shared hosting; tømmes ved proces‑restart; adskilt mellem web/CLI; kræver stadig en persistent “truth” (filen).
- **Anbefaling:** Brug kun **opportunistisk** hvis tilgængelig, som read‑through cache. *Ikke et krav*.

**ENV/ini/Feature‑flag i serverkonfig**
- Kræver ofte genindlæsning/restart for at slå igennem → **dårlig DX** ved hyppige deploys.

**Database**
- Unødigt tungt og gør maintenance‑check afhængigt af DB‑tilgængelighed tidligt i boot.

**.htaccess / webserver‑switch**
- Kan være hurtigt, men less portable på tværs af hosts og sværere at orkestrere atomisk fra PHP.

---

## 7) Driftsegenskaber og robusthed
**Atomisk write:**
- Skriv til `*.tmp` + `rename()` ind på plads (fallback med `unlink()` på Windows).
- Sikrer konsistente reads – enten gammel eller ny fil, aldrig halvskrevet.

**OPcache invalidering:**
- `opcache_invalidate($flagPath, true)` kaldes ved toggling → **øjeblikkelig synlighed** på alle workers, uden at hæve `revalidate_freq` eller kræve server‑reload.

**Backups & retention:**
- Valgfri rotation (fx behold sidste N versioner), nyttigt til audit/rollback.

**Fejltolerance:**
- Manglende eller korrupt flagfil → sikre defaults (`enabled=false`, tom whitelist, retry_after fra cfg).
- Exceptions kastes kun ved programmerings-/deployfejl under toggling (så global error‑handler kan logge korrekt).

**Sikkerhed:**
- Whitelist af IP’er (og i dev/stage accepteres evt. literal "unknown" for lokale setups).
- 503‑svar inkluderer `Retry‑After`, `Cache‑Control: no-store`, `X‑Robots‑Tag: noindex`.

---

## 8) Shared hosting-kompatibilitet
- **Ingen** afhængighed af APCu/Redis/daemoner.
- Kun krav: Standard OPcache (som er normal konfiguration).
- `opcache_invalidate()` virker på både one.com og simply.com (verificeret).
- Ingen særlige ini‑krav; default indstillinger er fine.

---

## 9) Best practices (prod)
- Hold flagfilen **minimal** (som nu).
- Brug **absolut sti** til filen.
- Fortsæt med **tidlig guard** i boot.
- Ved toggling: atomisk write → `opcache_invalidate()`.
- (Valgfrit) `opcache_compile_file($flagPath)` efter write for ekstra varme opcodes.
- Overvej en **opportunistisk APCu‑read‑through** (auto‑detekteret, ingen hard dependency) hvis du vil barbere mikrosekunder af – det ændrer ikke arkitekturen.

---

## 10) Beslutning (forankring)
Vi fastholder **flagfil + OPcache** som den officielle CitOmni‑model for maintenance mode.
- Begrundelse: **målt** overhead ~**11–13 µs** pr. request; robust toggling; lav kompleksitet; shared hosting‑venlig; fin audit‑historik.
- Eventuel APCu‑integration er *bonus* og skal være non‑intrusive og selv‑deaktiverende hvis ikke tilgængelig.

---

## 11) Fremtidige forbedringer (optionelle)
- Lille CLI‑kommando til toggling med menneskevenlig output og automatisk log/audit.
- Mulighed for kort "reason"‑streng i flagfilen → vises i template (kommunikation under længere maintenance).
- Små health‑checks der advarer, hvis flagfilen er ældre end X dage (glemt ON).

---

## Appendix A – Micro‑benchmark (standalone)
> Kan uploades som `bench_maint_include.php`.

```php
<?php
declare(strict_types=1);
/**
 * CitOmni — Maintenance flag include micro-benchmark (standalone).
 * (Forkortet her for overskuelighed – brug versionen fra tråden ved behov)
 */
// ... fuld version findes i projektets samtalehistorik.
```

---

## Appendix B – Eksempel på flagfil
```php
<?php
// Generated by Maintenance service. Do not edit manually.
return [
	'enabled' => false,
	'allowed_ips' => [],
	'retry_after' => 600
];
```

---

## Korte noter til commit / changelog
- **Maintenance mode – dokumentation & benchmarks:** Tilføjet intern rapport som begrunder flagfil+OPcache‑modellen. Reelle målinger på shared hosting viser ~11–13 µs pr. include (p95 ≤ ~19 µs). Toggling sker atomisk med `opcache_invalidate()` for øjeblikkelig synlighed. Løsningen er platform‑agnostisk og kræver ingen særkonfiguration.

