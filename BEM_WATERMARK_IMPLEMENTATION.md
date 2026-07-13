# BEM Watermark Implementation

## Scop

Flow nou, separat, pentru aplicarea watermark-ului pe imaginile produselor create prin sincronizarea Shopify.

Implementarea este izolata de flow-ul vechi de watermark si foloseste prefixul `BemWatermark` / `BEM_`.

## Activare

Feature flag-urile sunt in `config/features.php`, sectiunea `bem_watermark_sync`.

Variabile `.env`:

```env
BEM_WATERMARK_SYNC_ENABLED=false
BEM_WATERMARK_SYNC_DRY_RUN=true
BEM_WATERMARK_SYNC_REQUIRED_TAG=wm_test
BEM_WATERMARK_BACKUP_SHOP_DOMAIN=eiluminatbackup.myshopify.com
BEM_WATERMARK_TARGET_SHOP_DOMAINS=
BEM_WATERMARK_NOTIFICATION_EMAIL=mitnickoff121@gmail.com
BEM_WATERMARK_WIDTH_RATIO=0.25
BEM_WATERMARK_OPACITY=15
```

Default-ul este sigur:

- flow-ul este oprit (`BEM_WATERMARK_SYNC_ENABLED=false`)
- dry-run este pornit (`BEM_WATERMARK_SYNC_DRY_RUN=true`)
- doar produsele cu tag-ul `wm_test` sunt eligibile

## Integrare

Singurul punct de integrare in flow-ul existent este in `ReplicateProductCreateToShop`.

Dupa ce produsul target este creat si mirror-ul este salvat, se verifica eligibilitatea. Daca produsul nu are tag-ul cerut sau feature flag-ul este oprit, flow-ul vechi continua fara modificari.

Job nou dispatch-uit:

```php
App\Jobs\BemApplyProductWatermark
```

Coada:

```text
watermarks
```

## Componente Noi

Job:

- `app/Jobs/BemApplyProductWatermark.php`
- `app/Jobs/BemApplySourceProductWatermark.php`

Servicii:

- `app/Services/Shopify/BemWatermark/BemWatermarkEligibilityService.php`
- `app/Services/Shopify/BemWatermark/BemBackupProductImageResolver.php`
- `app/Services/Shopify/BemWatermark/BemBackupProductImageResult.php`
- `app/Services/Shopify/BemWatermark/BemWatermarkImageProcessor.php`
- `app/Services/Shopify/BemWatermark/BemShopifyStagedUploadService.php`
- `app/Services/Shopify/BemWatermark/BemProductWatermarkMetafieldService.php`
- `app/Services/Shopify/BemWatermark/BemShopifyGraphqlClient.php`

Email eroare:

- `app/Mail/BemWatermarkFailedMail.php`
- `resources/views/emails/bem-watermark-failed.blade.php`

Test:

- `tests/Feature/BemWatermarkFlowTest.php`

## Flow Direct Create

1. Produsul se creeaza pe magazinul sursa.
2. Produsul se creeaza pe magazinul backup (`eiluminatbackup.myshopify.com`) prin flow-ul existent.
3. Pentru target shop-uri eligibile, job-ul asteapta backup-ul inainte sa creeze produsul target.
4. Daca backup-ul nu este gata, job-ul target face `release(60)` si nu creeaza inca produsul.
5. Cand backup-ul este gata, job-ul citeste imaginile curate din produsul backup, pastrand ordinea.
6. Imaginile sunt descarcate temporar in `storage/app/watermark/bem_tmp`.
7. Se aplica watermark-ul PNG specific magazinului target.
8. Fisierele watermark-uite sunt uploadate prin Shopify staged uploads.
9. Produsul target se creeaza cu imaginile watermark-uite deja pregatite.
10. Dupa create, flow-ul verifica numarul real de imagini de pe produsul Shopify.
11. Daca Shopify a atasat mai putine imagini decat au fost pregatite, flow-ul mai asteapta si reataseaza imaginile lipsa.
12. Se scrie metafield-ul `prod.watermarked`.
13. Fisierele temporare sunt sterse in `finally`.

Nota: fisierele temporare live sunt izolate in subdirectoare per rulare sub `storage/app/watermark/bem_tmp/jobs/...`. Testele folosesc doar `storage/app/watermark/bem_tmp/tests/...`, ca sa nu poata sterge fisiere temporare ale joburilor live.

Pentru produse fara tag-ul `wm_test`, flow-ul ramane cel existent.

Pentru `BEM_WATERMARK_SYNC_DRY_RUN=true`, produsul target se creeaza prin flow-ul vechi si job-ul BEM doar logheaza ce ar face, fara upload/metafield.

## Flow Source Product Create

Acest flow ruleaza pentru magazinul sursa cand webhook-ul `products/create` vine deja cu imagini in payload.

1. `ProcessShopifyWebhook` detecteaza imaginile din payload.
2. Daca produsul are tag-ul cerut (`wm_test`) si BEM este activ, se pune in coada job-ul `BemApplySourceProductWatermark`.
3. Job-ul asteapta pana cand produsul de backup exista si are cel putin acelasi numar de imagini originale.
4. Daca backup-ul nu este gata, job-ul face `release(60)` si nu modifica produsul sursa.
5. Cand backup-ul este confirmat, job-ul citeste imaginile originale din payload-ul sursa.
6. Imaginile sunt procesate local cu watermark-ul magazinului sursa.
7. Job-ul face upload si adauga imaginile watermark-uite pe produsul sursa, fara sa stearga inca imaginile originale.
8. Job-ul verifica faptul ca produsul are imaginile noi atasate.
9. Abia dupa confirmarea upload-ului, job-ul sterge media originale de tip imagine de pe produsul sursa.
10. Se scrie metafield-ul `prod.watermarked` pe produsul sursa.
11. Fisierele temporare sunt sterse in `finally`.

Regula de siguranta: produsul sursa nu este lasat fara imagini. Originalele se sterg doar dupa ce backup-ul si imaginile watermark-uite sunt confirmate. Media non-image nu este stearsa de flow-ul BEM source.

Pentru produsele create prin duplicate fara media, acest flow nu ruleaza la `products/create`; scenariul ramane pentru implementarea urmatoare pe `products/update`.

## Watermark Assets

Watermark-urile folosite sunt din:

```text
storage/app/watermark
```

Mapare:

- `eiluminat.myshopify.com` -> `watermark_eiluminat.png`
- `lustreled.myshopify.com` -> `watermark_lustreled.png`
- `powerleds-ro.myshopify.com` -> `watermark_power.png`
- `iluminat-industrial.myshopify.com` -> `watermark_industrial.png`

## Reguli Fisiere

Extensia imaginii nu se schimba.

Extensii acceptate:

- `jpg`
- `jpeg`
- `png`
- `webp`

Nu se face fallback la `jpg`, nu se face downscale si nu se reduce calitatea. Pentru formatele lossy se salveaza cu `quality: 100`.

Watermark-ul este scalat proportional cu imaginea produsului, default la `25%` din latimea imaginii, si aplicat alb transparent cu opacitate `15`.
Pentru PNG-urile cu fundal transparent, alpha-ul imaginii originale este pastrat; watermark-ul nu trebuie sa creeze dreptunghi de fundal.

Format filename:

```text
{domeniu-alias}_{titlu-slug}_w_p_{position}.{extensie}
```

Exemplu:

```text
lustreled_lustra-led-moderna_w_p_1.png
```

Alias-uri domeniu:

- `eiluminat.myshopify.com` -> `eiluminat`
- `eiluminatbackup.myshopify.com` -> `eiluminat`
- `powerleds-ro.myshopify.com` -> `powerleds`
- `lustreled.myshopify.com` -> `lustreled`
- `iluminat-industrial.myshopify.com` -> `iluminat-industrial`

## Metafield

Metafield-ul scris pe produsul target:

```text
namespace: prod
key: watermarked
type: json
```

Continut JSON:

```json
{
  "source_shop": "eiluminatbackup.myshopify.com",
  "source_product_id": 666,
  "source_product_gid": "gid://shopify/Product/666",
  "target_shop": "lustreled.myshopify.com",
  "target_product_id": 444,
  "target_product_gid": "gid://shopify/Product/444",
  "updated_at": "2026-05-31T00:00:00+00:00",
  "dry_run": false,
  "images": [
    {
      "position": 1,
      "source_url": "https://...",
      "watermarked_url": "https://...",
      "filename": "lustreled_lustra-led-moderna_w_p_1.png",
      "original_extension": "png",
      "status": "completed"
    }
  ]
}
```

## Siguranta Productie

Flow-ul nu afecteaza functionalitatile curente daca:

- `BEM_WATERMARK_SYNC_ENABLED=false`
- produsul nu are tag-ul `wm_test`
- target shop-ul este backup shop
- target shop-ul nu este in allowlist, daca `BEM_WATERMARK_TARGET_SHOP_DOMAINS` este setat

In dry-run:

- nu sterge imagini din Shopify
- nu face upload
- nu scrie metafield
- doar proceseaza local si logheaza ce ar face
- nu apare watermark vizibil in Shopify cat timp `BEM_WATERMARK_SYNC_DRY_RUN=true`

Inainte de pasul destructiv de inlocuire media, job-ul verifica din nou eligibilitatea.

`ReplicateProductCreateToShop` are guard de idempotency: daca exista deja `ProductMirror` pentru acelasi `source_shop_id`, `source_product_id` si `target_shop_id`, job-ul nu mai creeaza inca o data produsul target. Asta previne duplicatele in cazul in care Laravel retry-uieste job-ul dupa o eroare aparuta dupa crearea produsului.

## Erori

Job-ul logheaza fiecare etapa importanta.

Daca job-ul esueaza definitiv, trimite email prin:

```php
App\Mail\BemWatermarkFailedMail
```

Email-ul include contextul:

- target shop
- source product
- target product
- eroarea

## Queue

Job-ul ruleaza pe:

```text
watermarks
```

Worker recomandat pentru productie controlata:

```bash
php artisan queue:work database --queue=watermarks --sleep=3 --tries=2 --timeout=900
```

La momentul implementarii, `laravel-queue` din PM2 a fost restartat.

Pentru productie cu multe update-uri simultane, joburile BEM care asteapta produsul de backup au fereastra lunga de retry:

- `BemSyncBackupManifestFromSourceUpdate`
- `BemApplyProductWatermark`
- `BemApplySourceProductWatermark`

Aceste joburi pot reincerca pana la 120 de ori, cu `retryUntil` de 6 ore. Asta evita erorile false de tip `attempted too many times` cand webhook-ul de update ajunge inainte ca produsul de backup si `ProductMirror` sa fie create.

## Testare

Test e2e local:

```bash
php artisan test --filter=BemWatermarkFlowTest
```

Testul verifica:

- eligibilitatea cu `wm_test`
- procesare reala imagine PNG cu watermark
- pastrarea extensiei `.png`
- filename corect
- staged upload fake
- replace media fake
- scriere `prod.watermarked`
- stergerea fisierelor temporare

Rezultat ultima rulare:

```text
PASS Tests\Feature\BemWatermarkFlowTest
1 passed, 11 assertions
```

## Verificare Executie

Verificare in log:

```bash
rg "BEM watermark" storage/logs/laravel.log
```

Semnale asteptate:

```text
BEM watermark job queued
BEM watermark job started
BEM watermark dry-run completed without Shopify writes
BEM watermark job completed
BEM prod.watermarked metafield updated
```

In `dry-run=true`, semnalul corect este:

```text
BEM watermark dry-run completed without Shopify writes
```

In `dry-run=false`, se verifica in Shopify:

- imaginile produsului target au watermark
- metafield-ul produsului target exista: `prod.watermarked`
- JSON-ul contine `source_shop`, `target_shop`, `images`, `filename`, `watermarked_url`

## Rollback

Comanda Artisan:

```bash
php artisan bem-watermark:rollback {product_id}
```

`product_id` poate fi:

- id produs sursa: rollback pentru toate target-urile gasite in `ProductMirror`
- id produs target, daca folosesti si `--shop`
- Shopify GID complet, daca folosesti si `--shop`

Exemple:

Rollback pentru toate target-urile unui produs sursa:

```bash
php artisan bem-watermark:rollback 10869000470874
```

Rollback pentru un produs target specific:

```bash
php artisan bem-watermark:rollback 16332174328153 --shop=lustreled.myshopify.com
```

Dry-run rollback:

```bash
php artisan bem-watermark:rollback 10869000470874 --dry-run
```

Comanda citeste `prod.watermarked.images[*].source_url` si inlocuieste imaginile produsului target cu imaginile originale. Dupa rollback, acelasi metafield este actualizat cu:

```json
{
  "rolled_back_at": "...",
  "rollback_status": "original_images_restored"
}
```

Rollback-ul restaureaza imaginile originale pe produsul target. Pentru cazul in care update-ul vechi a contaminat magazinele target cu watermark-ul magazinului sursa, se foloseste comanda de repair de mai jos, nu rollback-ul simplu.

## Repair Dupa Contaminare Update

Comanda Artisan:

```bash
php artisan bem-watermark:repair-from-source-history {source_product_id}
```

Ce face:

- citeste istoricul din `prod.watermarked` de pe produsul sursa
- pastreaza doar imaginile care inca exista pe produsul sursa
- rescrie produsul din backup cu imaginile originale curate
- regenereaza watermark-ul corect pentru fiecare magazin target
- actualizeaza `prod.watermarked` si snapshot-ul din `ProductMirror`

Dry-run:

```bash
php artisan bem-watermark:repair-from-source-history 10869099168090 --dry-run
```

Repair live pentru toate target-urile BEM ale produsului:

```bash
php artisan bem-watermark:repair-from-source-history 10869099168090
```

Repair live pentru un singur target:

```bash
php artisan bem-watermark:repair-from-source-history 10869099168090 --shop=powerleds-ro.myshopify.com
```

Aceasta comanda nu foloseste imaginile contaminate din target-uri. Mapping-ul se face din fisierele/URL-urile watermark-uite de pe source catre `prod.watermarked.images[*].source_url`.

## Product Update Media

Flow-ul automat pentru update de imagini este controlat de:

```env
BEM_WATERMARK_UPDATE_MANIFEST_ENABLED=true
```

Cand un produs BEM primeste `products/update`:

- update-ul vechi continua pentru campurile non-media
- update-ul vechi nu mai sterge si nu mai copiaza imagini din source in target
- job-ul `BemSyncBackupManifestFromSourceUpdate` face sync-ul media separat

Pasii media:

- citeste imaginile curente din source
- mapeaza imaginile watermark-uite prin `prod.watermarked` de pe source
- detecteaza imaginile sterse prin diferenta fata de istoricul `prod.watermarked`
- trateaza imaginile noi curate ca sursa originala pentru backup
- pentru produse vechi fara istoric BEM valid, daca imaginile curente din source sunt curate, backup-ul este reconciliat din source in ordinea curenta, chiar daca backup-ul avea deja imagini vechi
- daca produsul backup exista dar are 0 imagini, iar `prod.watermarked` de pe source are istoric curat, seed-uieste backup-ul din acel istoric inainte de sync
- rescrie backup-ul cu lista curenta de originale curate
- regenereaza source cu watermark `eiluminat`
- regenereaza target-urile din backup cu watermark-ul fiecarui magazin
- actualizeaza `prod.watermarked`, `prod.watermark_manifest` pe backup si `ProductMirror.last_snapshot`

Regula pentru produse vechi:

- daca `prod.watermarked` lipseste sau nu are imagini, flow-ul nu mai mapeaza source-ul cu backup-ul doar dupa pozitie
- daca source-ul are imagini curate, source-ul devine sursa de adevar si backup-ul este rescris cu acele originale
- daca source-ul are imagini watermark-uite si nu exista istoric BEM sigur, bootstrap-ul se opreste si logheaza motivul; nu se ghiceste dupa pozitie
- dupa reconcilierea backup-ului, job-ul continua normal si regenereaza watermark-ul pe source si target-uri

Semnale in log:

```text
BEM update media sync started
BEM update media sync completed
BEM update media sync no-op: source images already match prod.watermarked
BEM update image sync skipped: source payload images may be watermarked
```

Comportament asteptat:

- daca nu s-au schimbat imaginile: job-ul BEM este no-op, iar non-media sync continua
- daca s-au sters imagini: backup/source/target-uri raman cu lista redusa
- daca s-au adaugat imagini curate: backup primeste originalele curate, target-urile primesc watermark-ul lor
- daca apare o imagine watermark-uita necunoscuta: flow-ul se opreste si trimite eroare/log/email

## Duplicate Cu Media Watermark-uita

La duplicarea unui produs cu media bifata, Shopify poate copia imaginile deja watermark-uite si metafield-ul `prod.watermarked` de pe produsul original.

Protectii implementate:

- `BemApplySourceProductWatermark` nu mai considera `prod.watermarked` valid daca ID-ul/GID-ul din metafield nu apartine produsului nou.
- Daca imaginile din payload sunt deja watermark-uite, jobul sursa foloseste URL-urile originale curate din istoricul `prod.watermarked` mostenit, cand acestea exista.
- Daca nu exista istoric curat, jobul refuza sa aplice watermark peste watermark si trimite eroare controlata.
- `BemBackupProductImageResolver` refuza backup-uri care contin imagini watermark-uite, ca sa nu fie folosite drept originale.
- `ReplicateProductCreateToShop` inlocuieste imaginile watermark-uite cu originale curate cand creeaza produsul in magazinul de backup.
- `ReplicateProductCreateToShop` seteaza `custom.parentproduct` pe produsele create in target/backup.

## Comenzi Rulate

```bash
composer dump-autoload
CACHE_DRIVER=array php artisan optimize:clear
TELESCOPE_ENABLED=false CACHE_DRIVER=array php artisan optimize:clear
pm2 restart laravel-queue
php artisan test --filter=BemWatermarkFlowTest
php artisan test --filter=BemWatermarkUpdateManifestTest
php artisan test --filter=BemWatermark
php -l app/Jobs/BemSyncBackupManifestFromSourceUpdate.php
php -l app/Services/Shopify/BemWatermark/BemWatermarkUpdateBootstrapService.php
```

Nota: o rulare `php artisan optimize:clear` fara `CACHE_DRIVER=array` a incercat sa foloseasca MySQL pentru cache/Telescope si a scris o eroare in `laravel.log`. Comanda a fost rerulata corect cu `TELESCOPE_ENABLED=false CACHE_DRIVER=array`.

Nota incident test: o versiune initiala a job-ului `BemApplyProductWatermark` declara proprietatea `$queue`, care intra in conflict cu trait-ul Laravel `Queueable`. Eroarea a aparut dupa crearea produsului target, iar retry-ul Laravel a putut crea duplicate. Proprietatea a fost eliminata, coada ramane setata prin `->onQueue('watermarks')`, iar `ReplicateProductCreateToShop` are acum guard de idempotency.

## Activare Test Controlat

1. Pune produsului tag-ul `wm_test`.
2. Activeaza dry-run:

```env
BEM_WATERMARK_SYNC_ENABLED=true
BEM_WATERMARK_SYNC_DRY_RUN=true
```

3. Creeaza produsul si verifica logurile `BEM watermark`.
4. Pentru un target specific:

```env
BEM_WATERMARK_TARGET_SHOP_DOMAINS=lustreled.myshopify.com
```

5. Cand dry-run arata corect, activeaza scrierea:

```env
BEM_WATERMARK_SYNC_DRY_RUN=false
```

6. Ruleaza:

```bash
TELESCOPE_ENABLED=false CACHE_DRIVER=array php artisan optimize:clear
pm2 restart laravel-queue
```

## Rollback

Rollback operational:

```env
BEM_WATERMARK_SYNC_ENABLED=false
```

Apoi:

```bash
TELESCOPE_ENABLED=false CACHE_DRIVER=array php artisan optimize:clear
pm2 restart laravel-queue
```

Rollback cod:

- elimina hook-ul `dispatchBemWatermarkIfEligible` din `ReplicateProductCreateToShop`
- elimina fisierele `BemWatermark`
- elimina config-ul `bem_watermark_sync`
