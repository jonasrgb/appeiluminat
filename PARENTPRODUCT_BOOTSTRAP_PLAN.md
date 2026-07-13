# Parent Product Bootstrap Plan

## Scop

Produsele vechi sau produsele create prin duplicate pot ajunge pe magazinele target fara SKU, fara media si cu titlu/handle temporar. Dupa ce produsul sursa este completat si actualizat, mapping-ul dupa handle/SKU poate deveni imposibil sau ambiguu.

Solutia propusa este folosirea metafield-ului `custom.parentproduct` pe fiecare produs target. Valoarea trebuie sa fie id-ul numeric al produsului din magazinul sursa.

Exemplu:

```json
{
  "namespace": "custom",
  "key": "parentproduct",
  "type": "number_integer",
  "value": "10057038561626"
}
```

## Problema Curenta

Flow-ul de update foloseste `ProductMirror` ca sursa principala de adevar. Daca lipseste mapping-ul, sistemul incearca bootstrap dupa:

1. handle exact;
2. SKU unic.

Acest lucru pica in scenarii precum:

- produsul sursa a fost creat prin duplicate si initial avea titlu/handle cu `(Copy)`;
- produsul sursa a fost creat fara SKU;
- SKU-ul a fost adaugat ulterior;
- handle-ul s-a schimbat dupa update-ul titlului;
- in target exista deja produse `(Copy)` cu acelasi SKU;
- cautarea dupa SKU devine ambigua si sistemul se opreste ca sa nu lege produsul gresit.

## Reguli De Siguranta

- `ProductMirror` ramane prima sursa de adevar.
- `custom.parentproduct` se foloseste doar ca fallback cand lipseste `ProductMirror`.
- Daca se gasesc mai multe produse target cu acelasi `custom.parentproduct`, update-ul se opreste si logheaza ambiguitatea.
- Valoarea metafield-ului trebuie sa fie strict integer: id-ul produsului sursa.
- Nu folosim `parentproduct` pentru a rescrie mapping-uri existente decat prin comanda explicita de repair/backfill.
- Nu se aleg automat produse dupa SKU ambiguu daca nu exista `parentproduct` unic.
- Flow-ul BEM/watermark nu trebuie sa foloseasca imagini din target sau imagini watermark-uite ca sursa originala.

## Workflow Propus

### Product Create

Cand `products/create` vine din magazinul sursa:

1. se creeaza produsul target prin flow-ul existent;
2. dupa ce Shopify intoarce `target_product_gid`, se seteaza metafield-ul:
   - namespace: `custom`;
   - key: `parentproduct`;
   - type: `number_integer`;
   - value: `source_product_id`;
3. se salveaza in continuare `ProductMirror`;
4. pentru produse fara media, flow-ul BEM ramane protejat: nu porneste watermark pana la update-ul cu imagini.

### Product Update

Cand `products/update` vine din magazinul sursa:

1. se cauta `ProductMirror`;
2. daca exista, update-ul continua normal;
3. daca lipseste, se cauta in target produs cu `custom.parentproduct = source_product_id`;
4. daca exista exact un produs:
   - se creeaza `ProductMirror`;
   - se creeaza/actualizeaza `VariantMirror` pe baza variantelor curente;
   - update-ul continua cu titlu, handle, variante, metafield-uri si imagini conform flow-ului curent;
5. daca nu exista produs dupa `parentproduct`, continua fallback-ul actual dupa handle/SKU;
6. daca exista mai multe produse dupa `parentproduct`, se opreste cu log/email.

### BEM Watermark Update Bootstrap

Bootstrap-ul BEM trebuie sa foloseasca aceeasi ordine:

1. `ProductMirror`;
2. `custom.parentproduct`;
3. handle;
4. SKU unic;
5. stop pe ambiguitate.

Astfel, SKU-uri duplicate precum `DF1055-3` nu mai blocheaza flow-ul daca produsul target are `parentproduct` corect.

## Feature Flags Recomandate

Adaugam flag-uri ca implementarea sa poata fi activata gradual:

```env
PARENTPRODUCT_WRITE_ON_CREATE=false
PARENTPRODUCT_BOOTSTRAP_ON_UPDATE=false
PARENTPRODUCT_BACKFILL_ENABLED=false
```

Ordinea activarii:

1. `PARENTPRODUCT_WRITE_ON_CREATE=true`
2. test produs nou creat prin duplicate fara SKU/media;
3. `PARENTPRODUCT_BOOTSTRAP_ON_UPDATE=true`
4. test update titlu/SKU/media;
5. backfill separat pentru produse vechi.

## Fisiere De Modificat

- `config/features.php`
  - adauga flag-uri pentru `parentproduct`.

- `app/Jobs/ReplicateProductCreateToShop.php`
  - seteaza `custom.parentproduct` dupa creare produs target;
  - trebuie sa functioneze si pentru create normal, si pentru BEM direct create;
  - trebuie sa nu porneasca flow-uri suplimentare daca create-ul vine fara media.

- `app/Jobs/ReplicateProductUpdateToShop.php`
  - cand lipseste `ProductMirror`, cauta intai dupa `custom.parentproduct`;
  - daca gaseste exact un produs, creeaza mapping-ul si continua update-ul;
  - daca gaseste mai multe, logheaza ambiguu si opreste.

- `app/Services/Shopify/BemWatermark/BemWatermarkUpdateBootstrapService.php`
  - adauga cautarea dupa `custom.parentproduct` inainte de handle/SKU.

- `BEM_WATERMARK_IMPLEMENTATION.md`
  - documenteaza interactiunea cu BEM.

## Fisiere Noi Recomandate

- `app/Services/Shopify/ParentProductMetafieldService.php`
  - `setParentProduct(Shop $shop, string $productGid, int $sourceProductId): void`
  - `findProductByParentProduct(Shop $shop, int $sourceProductId): ?array`
  - returneaza explicit statusuri pentru: not found, unique match, ambiguous.

- `app/Console/Commands/BackfillParentProductMetafieldsCommand.php`
  - pentru produse deja existente.

- `tests/Feature/ParentProductBootstrapTest.php`
  - teste dedicate pentru create/update/bootstrap.

## Backfill Produse Vechi

Comanda propusa:

```bash
php artisan parentproduct:backfill --dry-run
php artisan parentproduct:backfill --source-product-id=10057038561626 --dry-run
php artisan parentproduct:backfill --source-product-id=10057038561626 --shop=lustreled.myshopify.com --target-product-id=15242401284441
```

Strategie:

1. pentru mapping-urile existente in `ProductMirror`, scrie `custom.parentproduct` pe target;
2. pentru produse fara mapping, cauta dupa handle;
3. apoi dupa SKU unic;
4. daca SKU-ul este ambiguu, listeaza candidatii;
5. permite mapping manual cu `--target-product-id`;
6. creeaza `ProductMirror` si `VariantMirror` dupa confirmare;
7. nu face mutatii pe Shopify in `--dry-run`.

## Teste Necesare

- create produs target seteaza `custom.parentproduct`;
- create fara media seteaza `parentproduct`, dar nu porneste watermark async;
- update cu `ProductMirror` existent ramane neschimbat;
- update fara `ProductMirror`, dar cu `parentproduct` unic, creeaza mapping si actualizeaza titlul;
- update fara `ProductMirror`, fara `parentproduct`, fallback dupa handle functioneaza;
- update fara `ProductMirror`, SKU ambiguu si fara `parentproduct`, se opreste;
- BEM bootstrap foloseste `parentproduct` inainte de SKU;
- doua produse target cu acelasi `parentproduct` produc eroare controlata;
- backfill `--dry-run` nu scrie nimic;
- backfill manual creeaza mapping corect.

## Riscuri

- Daca `parentproduct` este scris gresit, update-urile viitoare pot merge pe produs gresit.
- Daca un produs target este duplicat manual si pastreaza acelasi `parentproduct`, cautarea devine ambigua.
- Pentru produsele vechi fara `parentproduct`, problema nu se rezolva pana la backfill.
- Daca implementarea este activata in timp ce exista multe job-uri in coada, pot exista produse create partial cu si fara `parentproduct`.
- Trebuie evitat deploy-ul in timpul unei perioade active de create/update/watermark.

## Plan De Implementare

1. Creeaza service-ul `ParentProductMetafieldService`.
2. Adauga flag-urile in `config/features.php`.
3. Scrie `custom.parentproduct` la `product/create`, dar doar daca `PARENTPRODUCT_WRITE_ON_CREATE=true`.
4. Adauga fallback-ul de update dupa `parentproduct`, dar doar daca `PARENTPRODUCT_BOOTSTRAP_ON_UPDATE=true`.
5. Adauga aceeasi cautare in `BemWatermarkUpdateBootstrapService`.
6. Adauga teste feature.
7. Ruleaza testele:

```bash
php artisan test --filter=ParentProductBootstrapTest
php artisan test --filter=BemWatermark
```

8. Ruleaza `php artisan optimize:clear`.
9. Reporneste `laravel-queue` doar intr-o fereastra linistita.
10. Activeaza gradual flag-urile.

## Checklist Pentru Reluare

- [ ] Verifica daca exista job-uri BEM/watermark in coada.
- [ ] Verifica daca echipa creeaza/actualizeaza produse in momentul deploy-ului.
- [ ] Implementeaza service-ul generic pentru `custom.parentproduct`.
- [ ] Activeaza intai doar scrierea pe create.
- [ ] Testeaza produs duplicat fara SKU si fara media.
- [ ] Testeaza update cu SKU/titlu/handle schimbat.
- [ ] Activeaza fallback-ul pe update.
- [ ] Testeaza SKU ambiguu cu `parentproduct` unic.
- [ ] Scrie comanda de backfill pentru produse vechi.
- [ ] Ruleaza backfill dry-run si analizeaza ambiguitatile.

## Implementat Pe 2026-06-02

- Backfill/dashboard separat pentru `custom.parentproduct`.
- `ReplicateProductUpdateToShop` cauta acum produsul target dupa snapshot-ul local de backfill `custom.parentproduct` inainte de handle/SKU cand lipseste `ProductMirror` si `MIRROR_BOOTSTRAP_ENABLED=true`.
- `BemWatermarkUpdateBootstrapService` foloseste aceeasi ordine: `ProductMirror`, snapshot/backfill `custom.parentproduct`, Shopify `custom.parentproduct`, handle, SKU.
- Snapshot-ul local este folosit primul deoarece Shopify product search poate intoarce produse care au metafield-ul, fara sa garanteze ca primele rezultate includ valoarea exacta cautata.
- Cazurile de ambiguitate trimit email `Parentproduct bootstrap issue` catre `PARENTPRODUCT_NOTIFICATION_EMAIL` sau, daca nu este setat, catre `BEM_WATERMARK_NOTIFICATION_EMAIL`.
- Emailul include magazinul target, produsul sursa, criteriul cautat si lista de candidati gasiti.
- `ReplicateProductCreateToShop` si `ReplicateProductUpdateToShop` sincronizeaza acum si `handle` din magazinul sursa, nu doar titlul.
- Daca sunt mai multe produse target cu acelasi `custom.parentproduct`, flow-ul se opreste si logheaza ambiguitatea.
