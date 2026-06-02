# Product Parent Backfill Dashboard Plan

## Scop

Modul separat pentru identificarea si corelarea produselor vechi dintre magazinul sursa si magazinele target, astfel incat produsele target sa poata primi metafield-ul `custom.parentproduct` cu ID-ul produsului din magazinul sursa.

Acest modul nu modifica flow-urile curente de product/create, product/update sau watermark. El functioneaza separat printr-o comanda Artisan care face snapshot, match si optional aplicare de metafield.

## Problema acoperita

Produsele vechi sau produsele create prin duplicare pot ajunge in target fara SKU, cu titlu/handle schimbat ulterior sau fara legatura clara in baza de date. Cand lipseste corelarea, update-ul poate rata produsul target sau poate cauta doar dupa handle/SKU, ceea ce nu este suficient in toate cazurile.

`custom.parentproduct` devine ancora stabila: pe fiecare produs target se salveaza ID-ul numeric al produsului din magazinul sursa.

## Fisiere noi

- `database/migrations/2026_06_02_150000_create_product_parent_backfill_candidates_table.php`
- `app/Models/ProductParentBackfillCandidate.php`
- `app/Services/Shopify/ProductParentBackfillService.php`
- `app/Console/Commands/ProductParentBackfillScanCommand.php`
- `app/Http/Controllers/ProductParentBackfillController.php`
- `resources/views/product-parent-backfill/index.blade.php`
- `resources/views/product-parent-backfill/unmatched.blade.php`
- `resources/views/product-parent-backfill/partials/filters.blade.php`
- `resources/views/product-parent-backfill/partials/table.blade.php`

## Fisiere existente modificate

- `routes/web.php` - adauga rute protejate cu `auth` pentru dashboard.
- `resources/views/layouts/navigation.blade.php` - adauga link catre pagina noua.

## Workflow

1. Comanda Artisan citeste produsele din magazinul sursa si din magazinele target prin Shopify GraphQL.
2. Pentru fiecare produs target incearca sa gaseasca produsul sursa prin:
   - `custom.parentproduct`, daca exista deja;
   - tabela `product_mirrors`, daca produsul a fost creat prin flow-ul nou;
   - handle exact;
   - SKU unic.
3. Rezultatul se salveaza in tabela `product_parent_backfill_candidates`.
4. Dashboard-ul afiseaza doua pagini:
   - produse corelate/gasite;
   - produse necorelate, ambigue sau fara corespondent.
5. Optional, comanda poate aplica `custom.parentproduct` doar pentru randurile corelate clar.

## Campuri afisate in dashboard

- ID produs sursa si target
- titlu
- handle
- SKU-uri
- status Shopify
- numar imagini
- nume magazin
- strategie de match
- status corelare
- check verde pentru produsele unde `custom.parentproduct` este deja aplicat corect
- data ultimei scanari

## Reguli de siguranta

- Modulul este separat de watermark si nu declanseaza upload-uri de imagini.
- Scanarea fara `--apply` nu modifica Shopify, doar salveaza snapshot in DB.
- Aplicarea metafield-ului se face doar pentru match-uri clare.
- Produsele ambigue raman in pagina de negasite si trebuie verificate manual.
- `custom.parentproduct` se scrie ca `number_integer`, fara prefixuri sau texte.

## Comenzi planificate

Scanare fara modificari in Shopify:

```bash
php artisan product-parent:scan
```

Scanare pentru un singur magazin target:

```bash
php artisan product-parent:scan --target-shop=lustreled.myshopify.com
```

Aplicare `custom.parentproduct` pentru produsele corelate clar:

```bash
php artisan product-parent:scan --apply
```

Limitare pentru test:

```bash
php artisan product-parent:scan --limit=50
```

## TODO dupa validare

- Test pe un target cu produse vechi fara SKU.
- Test pe cazuri ambigue unde acelasi SKU apare la mai multe produse.
- Dupa ce dashboard-ul confirma corelarile, se poate adauga in flow-ul de update o cautare suplimentara dupa `custom.parentproduct`.
