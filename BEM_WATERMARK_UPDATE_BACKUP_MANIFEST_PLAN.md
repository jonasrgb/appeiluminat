# BEM Watermark Update Backup Manifest Plan

## Scop

Plan pentru refacerea flow-ului de `products/update`, astfel incat:

- magazinul de backup sa ramana sursa curata pentru imagini originale
- magazinele reale sa nu primeasca niciodata poze cu watermark-ul altui magazin
- imaginile noi/stergerea/reordonarea sa fie detectate corect
- produsele sa nu ramana fara imagini in cazul unei erori
- erorile sa fie logate si trimise pe email

Acest document descrie implementarea propusa. Nu reprezinta in sine implementarea finala.

## Problema Curenta

Flow-ul actual de update foloseste imaginile direct din payload-ul magazinului sursa.

Dupa introducerea watermark-ului pe magazinul sursa, payload-ul poate contine imagini deja watermark-uite cu `eiluminat`.

Riscul principal:

- `lustreled`, `powerleds` sau alte magazine pot primi poze cu watermark `eiluminat`
- magazinul de backup poate fi contaminat cu poze watermark-uite
- cand se sterg/adauga poze, nu mai exista o corelare clara intre poza watermark-uita din source si poza originala din backup

## Principiu Nou

Pentru produsele BEM eligibile:

- backup-ul este singura sursa curata de imagini originale
- source si target stores primesc doar poze generate din backup sau din poze noi curate detectate pe source
- fiecare imagine are un identificator stabil, numit `bem_image_uuid`
- istoricul complet de corelare se pastreaza pe produsul din magazinul de backup
- magazinele reale pot pastra `prod.watermarked` in forma actuala, cu adaugiri compatibile daca este util

## Unde Se Pastreaza Istoricul

Istoricul principal se pastreaza doar pe produsul din magazinul de backup, intr-un metafield JSON nou:

```text
namespace: prod
key: watermark_manifest
type: json
```

`prod.watermarked` ramane folosit pe magazinele reale pentru functionalitatea existenta: audit, link-uri originale, link-uri watermark-uite si rollback.

Pe magazinele reale putem adauga optional `image_uuid` in fiecare intrare din `prod.watermarked.images`, dar fara sa schimbam campurile existente.

## Structura Manifest Backup

Exemplu:

```json
{
  "version": 1,
  "source_shop": "eiluminat.myshopify.com",
  "source_product_id": 123,
  "source_product_gid": "gid://shopify/Product/123",
  "backup_shop": "eiluminatbackup.myshopify.com",
  "backup_product_id": 456,
  "backup_product_gid": "gid://shopify/Product/456",
  "updated_at": "2026-05-31T00:00:00+00:00",
  "images": [
    {
      "image_uuid": "bem_01hzxexample",
      "status": "active",
      "position": 1,
      "original_hash": "sha256:...",
      "original_extension": "webp",
      "source_original_url": "https://...",
      "backup_media_gid": "gid://shopify/MediaImage/...",
      "backup_image_gid": "gid://shopify/ProductImage/...",
      "backup_url": "https://...",
      "source_watermarked_media_gid": "gid://shopify/MediaImage/...",
      "source_watermarked_url": "https://...",
      "source_watermarked_filename": "eiluminat_bem_01hzxexample_w_p_1.webp",
      "target_images": {
        "lustreled.myshopify.com": {
          "media_gid": "gid://shopify/MediaImage/...",
          "url": "https://...",
          "filename": "lustreled_bem_01hzxexample_w_p_1.webp",
          "status": "active"
        }
      },
      "created_at": "2026-05-31T00:00:00+00:00",
      "updated_at": "2026-05-31T00:00:00+00:00"
    }
  ],
  "history": [
    {
      "event": "image_deleted",
      "image_uuid": "bem_01hzxexample",
      "at": "2026-05-31T00:00:00+00:00",
      "reason": "missing_from_source_update"
    }
  ]
}
```

## Reguli Filename

Backup:

```text
bem_{image_uuid}_original_p_{position}.{extensie}
```

Source:

```text
eiluminat_bem_{image_uuid}_w_p_{position}.{extensie}
```

Targets:

```text
{target_alias}_bem_{image_uuid}_w_p_{position}.{extensie}
```

Exemple:

```text
bem_01hzxexample_original_p_1.webp
eiluminat_bem_01hzxexample_w_p_1.webp
lustreled_bem_01hzxexample_w_p_1.webp
powerleds_bem_01hzxexample_w_p_1.webp
```

Filename-ul este fallback vizual si operational. Sursa principala de adevar ramane manifestul din backup.

## Cum Detectam Pozele Sterse

La `products/update`, magazinul sursa poate contine:

- poze vechi deja watermark-uite
- poze noi curate
- poze sterse de colaboratori
- poze reordonate

Algoritm:

1. Se incarca manifestul din backup.
2. Se citesc imaginile curente din source payload sau prin GraphQL source product media.
3. Pentru fiecare imagine curenta din source:
   - daca URL/GID apare in manifest ca `source_watermarked_*`, imaginea exista in continuare
   - daca filename-ul contine `bem_{image_uuid}`, imaginea se coreleaza cu manifestul
   - daca nu apare in manifest si nu are marker `_w_`, este imagine noua curata
   - daca are watermark dar nu poate fi corelata, se marcheaza ca eroare si nu se propaga catre backup/targets
4. Orice imagine din manifest care nu mai apare in lista curenta source devine `deleted`.
5. In backup se sterg doar imaginile `deleted`.
6. In backup se adauga doar imaginile noi curate.
7. In backup se reordoneaza imaginile active dupa ordinea din source.

Astfel backup-ul stie ce poza a fost stearsa prin mapping-ul:

```text
source_watermarked_media_gid -> image_uuid -> backup_media_gid
```

## Workflow Product Update

### 1. Webhook Source Update

Fisier existent:

- `app/Jobs/ProcessShopifyWebhook.php`

Schimbari propuse:

- pentru produse BEM eligibile, nu mai salvam orbeste imaginile din payload in `bkp.old_images`
- se declanseaza un job BEM nou pentru sincronizarea manifestului backup
- flow-ul vechi de update catre target-uri poate rula pentru date non-media, dar media BEM trebuie tratata separat

Job nou:

- `app/Jobs/BemSyncBackupManifestFromSourceUpdate.php`

Coada:

```text
watermarks
```

Status implementare: inceput. A fost adaugat flag-ul:

```env
BEM_WATERMARK_UPDATE_MANIFEST_ENABLED=false
```

Cand flag-ul este activ si produsul este eligibil, `ProcessShopifyWebhook` pune in coada `BemSyncBackupManifestFromSourceUpdate`. In stadiul curent job-ul incarca manifestul si clasifica imaginile, dar nu modifica media din backup pana la etapa `Backup Sync`.

### 2. Sync Backup Manifest

Job nou:

- `app/Jobs/BemSyncBackupManifestFromSourceUpdate.php`

Responsabilitati:

- ia lock pe produs
- incarca manifestul din backup
- citeste imaginile source curente
- identifica imagini active, sterse, noi si reordonate
- blocheaza propagarea imaginilor cu watermark necunoscut
- actualizeaza backup-ul cu append/confirm/delete/reorder
- actualizeaza `prod.watermark_manifest` pe produsul backup
- declanseaza joburi pentru source watermark update si target watermark update

Servicii noi:

- `app/Services/Shopify/BemWatermark/BemBackupManifestService.php`
- `app/Services/Shopify/BemWatermark/BemImageIdentityService.php`
- `app/Services/Shopify/BemWatermark/BemSourceUpdateImageClassifier.php`
- `app/Services/Shopify/BemWatermark/BemBackupImageSyncService.php`

### 3. Update Source Watermark

Job nou sau extensie a jobului existent:

- `app/Jobs/BemApplySourceProductUpdateWatermark.php`

Responsabilitati:

- primeste lista imaginilor noi curate din manifest
- aplica watermark doar pentru imaginile noi
- adauga imaginile watermark-uite pe source
- confirma upload-ul
- sterge doar imaginile curate nou adaugate pe source
- nu regenereaza imaginile vechi deja watermark-uite
- actualizeaza manifestul backup cu `source_watermarked_*`
- optional actualizeaza `prod.watermarked` pe source

### 4. Update Target Stores

Job nou:

- `app/Jobs/BemApplyTargetProductUpdateWatermark.php`

Responsabilitati:

- foloseste doar imaginile curate din backup manifest
- pentru fiecare target, aplica watermark-ul target-ului respectiv
- sterge target images care corespund imaginilor marcate `deleted`
- adauga watermark doar pentru imaginile noi
- reordoneaza media dupa manifest
- actualizeaza `prod.watermarked` pe target
- nu foloseste niciodata imaginile watermark-uite din source

### 5. ReplicateProductUpdateToShop

Fisier existent:

- `app/Jobs/ReplicateProductUpdateToShop.php`

Schimbari propuse:

- pentru produse non-BEM: ramane flow-ul actual
- pentru produse BEM eligibile:
  - nu mai ruleaza `syncImagesReplaceAll()` folosind imaginile din payload source
  - lasa update-ul de media in seama joburilor BEM
  - continua sa actualizeze titlu, descriere, preturi, variante, metafield-uri si inventar
  - pastreaza snapshot-ul existent de imagini in `product_mirrors.last_snapshot`, ca payload-ul source cu watermark `eiluminat` sa nu contamineze snapshot-urile target

## Fisiere Noi Propuse

Joburi:

- `app/Jobs/BemSyncBackupManifestFromSourceUpdate.php`
- `app/Jobs/BemApplySourceProductUpdateWatermark.php`
- `app/Jobs/BemApplyTargetProductUpdateWatermark.php`

Servicii:

- `app/Services/Shopify/BemWatermark/BemBackupManifestService.php`
- `app/Services/Shopify/BemWatermark/BemImageIdentityService.php`
- `app/Services/Shopify/BemWatermark/BemSourceUpdateImageClassifier.php`
- `app/Services/Shopify/BemWatermark/BemBackupImageSyncService.php`
- `app/Services/Shopify/BemWatermark/BemTargetImageSyncService.php`

Teste:

- `tests/Feature/BemWatermarkUpdateManifestTest.php`
- `tests/Feature/BemWatermarkBackupSyncTest.php`
- `tests/Feature/BemWatermarkTargetUpdateTest.php`

Documentatie:

- `BEM_WATERMARK_UPDATE_BACKUP_MANIFEST_PLAN.md`

## Fisiere Existente Care Vor Fi Modificate

- `app/Jobs/ProcessShopifyWebhook.php`
  - dispatch pentru sync manifest pe `products/update`
  - guard ca `bkp.old_images` sa nu fie contaminat cu imagini watermark-uite pentru BEM

- `app/Jobs/ReplicateProductUpdateToShop.php`
  - skip media sync direct din source pentru BEM
  - pastreaza update-urile non-media existente

- `app/Services/Shopify/BemWatermark/BemWatermarkImageProcessor.php`
  - filename cu `image_uuid`
  - pastrare compatibilitate cu filename-ul actual unde este nevoie

- `app/Services/Shopify/BemWatermark/BemShopifyStagedUploadService.php`
  - metode pentru append/confirm/delete/reorder daca nu exista deja

- `app/Services/Shopify/BemWatermark/BemProductWatermarkMetafieldService.php`
  - optional adauga `image_uuid` in `prod.watermarked.images`

- `BEM_WATERMARK_IMPLEMENTATION.md`
  - sumar al noului flow dupa implementare

## Performanta

Reguli:

- nu descarcam/regeneram toate imaginile daca s-au schimbat doar cateva
- procesam doar imaginile noi sau cele care nu au mapping valid
- folosim manifestul backup pentru diff rapid
- folosim lock per produs ca sa evitam doua update-uri simultane
- evitam `delete all + recreate`
- folosim append/confirm/delete/reorder
- limitam request-urile Shopify la batch-uri de maximum 250 imagini

Pentru produse fara manifest existent, primul update poate rula in mod de bootstrap:

- citeste backup-ul curent
- creeaza `image_uuid` pentru imaginile existente
- coreleaza source watermark images dupa filename/pozitie unde este posibil
- marcheaza imaginile incerte ca `needs_review`

## Erori Si Email

Toate joburile BEM update trebuie sa:

- logheze contextul complet: source shop, product id, backup product id, target shop, image_uuid
- trimita email la eroare definitiva folosind `BemWatermarkFailedMail`
- nu stearga imagini daca upload-ul/confirmarea/reorder-ul nu a reusit
- marcheze in manifest statusurile:
  - `active`
  - `new_pending_source_watermark`
  - `new_pending_targets`
  - `deleted`
  - `failed`
  - `needs_review`

Exemple de erori care opresc propagarea:

- imagine source are watermark dar nu poate fi corelata cu manifestul
- backup lipseste sau are imagini insuficiente
- upload Shopify failed
- media noua nu apare dupa confirmare
- exista mismatch intre count-ul manifestului si count-ul produsului backup

## Reguli Anti Watermark Gresit

Reguli obligatorii:

- backup-ul nu accepta imagini cu `_w_` in filename
- backup-ul nu accepta imagini care se potrivesc cu alias de domeniu watermark-uit:
  - `eiluminat_`
  - `lustreled_`
  - `powerleds_`
  - `iluminat-industrial_`
- target stores nu folosesc niciodata URL-uri din source payload pentru imagini BEM
- target stores folosesc doar `backup_url` din manifest
- source watermark foloseste doar imagini noi curate sau backup originals
- daca o imagine pare watermark-uita dar nu exista in manifest, produsul intra in `needs_review`

## Plan De Implementare Incremental

### Etapa 1 - Manifest Backup

- creeaza serviciul `BemBackupManifestService`
- citeste/scrie `prod.watermark_manifest` pe produsul backup
- adauga `image_uuid`
- testeaza read/write JSON si validare structura

Status implementare: inceput. Au fost adaugate:

- `app/Services/Shopify/BemWatermark/BemBackupManifestService.php`
- `app/Services/Shopify/BemWatermark/BemImageIdentityService.php`
- `tests/Feature/BemWatermarkUpdateManifestTest.php`

### Etapa 2 - Classifier Update Source

- creeaza `BemSourceUpdateImageClassifier`
- clasifica imagini: existing, new_clean, deleted, unknown_watermarked, reordered
- teste cu scenariul 11 imagini, 2 sterse, 2 noi

Status implementare: inceput. Au fost adaugate:

- `app/Services/Shopify/BemWatermark/BemSourceUpdateImageClassifier.php`
- test pentru scenariul 11 imagini, 2 sterse, 2 noi curate
- test pentru blocarea unei imagini watermark-uite necunoscute

### Etapa 3 - Backup Sync

- creeaza `BemBackupImageSyncService`
- append/confirm/delete/reorder in backup
- blocheaza orice imagine watermark-uita
- update manifest

### Etapa 4 - Source Update Watermark

- proceseaza doar imaginile noi curate
- adauga watermark source
- confirma
- sterge doar noile originale curate de pe source
- update manifest si `prod.watermarked`

### Etapa 5 - Target Update Watermark

- target update foloseste doar backup manifest
- proceseaza doar imagini noi
- sterge imaginile eliminate
- reordoneaza
- update `prod.watermarked`

### Etapa 6 - Integrare In Update Job

- `ReplicateProductUpdateToShop` sare peste image sync direct pentru BEM
- update-urile non-media raman neschimbate
- `ProcessShopifyWebhook` declanseaza manifest sync pe update

Status implementare: partial. Dispatch-ul catre `BemSyncBackupManifestFromSourceUpdate` exista in spatele flag-ului `BEM_WATERMARK_UPDATE_MANIFEST_ENABLED=false`. `ReplicateProductUpdateToShop` sare acum peste image sync direct pentru BEM si nu mai rescrie snapshot-ul de imagini cu payload-ul watermark-uit din source.

## Criterii De Acceptare

- backup nu contine poze cu watermark
- source poate avea watermark `eiluminat`
- `lustreled` primeste doar watermark `lustreled`
- `powerleds` primeste doar watermark `powerleds`
- stergerea a 2 poze din source sterge corespondentele corecte din backup si target-uri
- adaugarea a 2 poze noi adauga pozele curate in backup si watermark-uite corect in fiecare magazin
- reordonarea in source se reflecta in backup si target-uri
- niciun produs nu ramane gol daca un upload esueaza
- erorile definitive trimit email
- `prod.watermarked` existent ramane compatibil
