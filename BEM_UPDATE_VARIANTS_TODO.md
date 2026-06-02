# BEM Update Variants TODO

## Context

In flow-ul de `products/update`, update-ul produselor target se face prin `ReplicateProductUpdateToShop`.

Flow-ul principal nu cauta produsul target dupa SKU sau handle. El foloseste `product_mirrors`, pe baza:

- `source_shop_id`
- `target_shop_id`
- `source_product_id`

Cautarea dupa `handle` si apoi dupa SKU exista doar ca fallback de auto-bootstrap cand lipseste mapping-ul din `product_mirrors`.

## Problema de analizat

Exista un caz fragil cand produsul din magazinul sursa are variante reale, iar produsul din celelalte magazine are inca doar varianta default `Default Title`.

In prezent, jobul incearca sa:

1. actualizeze optiunile produsului target;
2. sincronizeze `VariantMirror`;
3. calculeze diferenta de variante;
4. stearga variantele care nu mai exista;
5. creeze variantele lipsa;
6. actualizeze pret, SKU, barcode si inventar.

Riscul este ca varianta default sa fie tratata ca `toDelete` prea devreme, inainte ca variantele reale sa fie create si mapate corect.

## De implementat mai tarziu

1. Adauga un detector explicit pentru tranzitia:

   - sursa are variante reale;
   - target are doar `Default Title`.

2. Pentru acest caz, foloseste un flow separat:

   - creeaza/intareste schema de optiuni pe target;
   - creeaza variantele reale cu `productVariantsBulkCreate`;
   - foloseste strategia Shopify potrivita pentru a elimina varianta standalone doar dupa ce variantele reale sunt create;
   - refa `VariantMirror` dupa starea reala din Shopify;
   - abia apoi ruleaza update-ul economic, SKU/barcode si inventar.

3. Nu sterge variante inainte de bootstrap-ul complet al variantelor reale.

4. Adauga loguri clare:

   - `variant_bootstrap_started`
   - `variant_bootstrap_options_created`
   - `variant_bootstrap_variants_created`
   - `variant_bootstrap_mirrors_refreshed`
   - `variant_bootstrap_completed`
   - `variant_bootstrap_failed`

5. Adauga teste automate pentru:

   - sursa fara variante -> target fara variante;
   - sursa cu variante -> target fara variante;
   - sursa cu variante -> target cu aceleasi variante;
   - sursa sterge o varianta;
   - sursa adauga o varianta;
   - SKU gol sau duplicat;
   - handle schimbat, dar `ProductMirror` valid.

## Reguli importante

- Update-ul normal trebuie sa ramana bazat pe `ProductMirror`, nu pe handle.
- SKU/handle trebuie pastrate doar ca fallback pentru auto-bootstrap cand lipseste mapping-ul.
- Nu trebuie atinsa logica de watermark cand se lucreaza la acest TODO.
- Nu trebuie ca target-ul sa ramana fara variante daca apare o eroare la mijloc.
- Orice flow nou trebuie sa fie idempotent, pentru ca Shopify poate trimite webhook-uri duplicate.

## Fisiere probabile de modificat

- `app/Jobs/ReplicateProductUpdateToShop.php`
- teste noi in `tests/Feature` sau `tests/Unit`
- optional: un service separat pentru bootstrap variante, ca sa nu mai creasca jobul principal

## Recomandare de implementare

Cel mai curat ar fi un service nou, de exemplu:

`App\Services\Shopify\ProductVariantBootstrapService`

Acesta ar prelua strict cazul:

`source has real variants` + `target has Default Title only`

si ar returna un rezultat clar catre `ReplicateProductUpdateToShop`, fara sa schimbe flow-ul normal pentru produsele care sunt deja mapate corect.
