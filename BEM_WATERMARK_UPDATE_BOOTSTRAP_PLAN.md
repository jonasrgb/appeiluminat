# BEM Watermark Update Bootstrap Plan

## Scop

Cand vine un webhook `products/update` pentru un produs care nu are in baza de date mapping complet pentru flow-ul de watermark, sistemul trebuie sa incerce sa repare controlat situatia inainte sa abandoneze sync-ul.

Primul caz implementat trebuie sa acopere scenariul sigur:

- produsul exista in magazinul sursa;
- produsul exista deja in `eiluminatbackup`;
- lipseste mapping-ul din `product_mirrors`;
- produsul sursa nu are `prod.watermarked` sau are imagini curate;
- backup-ul are imaginile curate.

## Reguli de siguranta

- Nu folosim niciodata o imagine watermarkuita ca original pentru backup.
- Nu cream automat produse in magazinele reale pe `products/update` in prima versiune.
- Daca gasim mai multe produse candidate dupa handle/SKU, oprim bootstrap-ul si logam eroare.
- Daca backup-ul are imagini cu watermark, oprim bootstrap-ul.
- Daca sursa are imagini watermarkuite si nu exista `prod.watermarked`, bootstrap-ul poate continua doar daca backup-ul are imagini curate si numarul de imagini este compatibil.
- Daca backup-ul exista dar nu are imagini, poate fi seed-uit o singura data din imaginile curate venite in payload-ul webhook-ului, cu verificare ca numele/URL-urile nu sunt watermarkuite.
- Daca sursa este deja watermarkuita, backup-ul nu se seed-uieste din payload-ul sursei. Se incearca mai intai istoricul `prod.watermarked`, apoi `ProductMirror.last_snapshot` pentru backup. Daca nu exista nicio sursa curata, jobul face skip controlat ca sa nu produca retry/fail inutil.
- Flow-ul trebuie sa fie idempotent pentru webhook-uri duplicate.

## Workflow

1. `products/update` porneste jobul `BemSyncBackupManifestFromSourceUpdate`.
2. Inainte de resolver-ul actual de backup, rulam bootstrap-ul.
3. Bootstrap-ul verifica daca exista deja `ProductMirror` catre backup.
4. Daca nu exista, cauta in backup:
   - prima data dupa `handle`;
   - apoi dupa SKU-urile variantelor.
5. Daca gaseste exact un produs, creeaza `ProductMirror`.
6. Citeste imaginile live din sursa si backup. Daca Shopify nu intoarce imaginile live din sursa, foloseste imaginile din payload-ul webhook-ului ca fallback.
7. Daca backup-ul exista dar nu are imagini, il seed-uieste din payload-ul curat al webhook-ului si foloseste URL-urile noi din backup ca sursa de adevar.
8. Daca payload-ul sursei contine deja imagini watermarkuite, foloseste istoricul/snapshot-ul curat in loc sa copieze watermark-ul in backup.
9. Daca sursa nu are `prod.watermarked`, initializeaza un payload `prod.watermarked` pe sursa folosind imaginile curate din backup ca `source_url`.
10. Initializeaza sau actualizeaza `prod.watermark_manifest` pe backup.
11. Jobul normal de update continua si aplica watermark-ul prin flow-ul existent:
   - sursa primeste watermark `eiluminat`;
   - target-urile primesc watermark-ul magazinului lor;
   - backup-ul ramane curat.

## Fisiere noi

- `app/Services/Shopify/BemWatermark/BemWatermarkUpdateBootstrapService.php`
- `app/Services/Shopify/BemWatermark/BemWatermarkUpdateBootstrapResult.php`
- teste dedicate pentru bootstrap.

## Fisiere modificate

- `app/Jobs/BemSyncBackupManifestFromSourceUpdate.php`
  - ruleaza bootstrap-ul inainte de `BemBackupProductImageResolver`.
- `app/Services/Shopify/BemWatermark/BemShopifyStagedUploadService.php`
  - pentru backup, nu mai paseaza URL-ul sursa direct catre Shopify; descarca imaginea originala pe server si o urca prin staged upload, ca sa evite erorile intermitente `Media processing failed`.
  - asteapta mai mult ca Shopify sa finalizeze URL-urile dupa replace media si logheaza explicit statusurile media daca nu sunt gata.
- `app/Jobs/ReplicateProductCreateToShop.php`
  - dupa `BEM direct create`, salveaza URL-urile finale watermarkuite in `ProductMirror.last_snapshot.images`, ca update-urile urmatoare sa aiba stare interna corecta si pentru produsele duplicate initial fara media.
  - daca `product/create` vine fara media, sare peste `BEM direct create` si peste dispatch-ul async `BemApplyProductWatermark`, apoi creeaza produsul target gol normal; primul `product/update` cu media va porni flow-ul de backup/watermark.

## Teste necesare

- creeaza mirror catre backup cand produsul exista dupa handle;
- creeaza mirror catre backup cand produsul exista dupa SKU;
- opreste bootstrap-ul daca exista mai multi candidati;
- initializeaza `prod.watermarked` cand lipseste si backup-ul este curat;
- nu initializeaza daca backup-ul contine imagini watermarkuite;
- flow-ul este no-op cand mirror/metafield exista deja.

## Limitari intentionate in prima versiune

- Nu cream automat produsul in backup daca lipseste complet; seed-uim doar imaginile pentru produsul backup deja gasit.
- Nu cream automat produse in magazinele reale daca lipsesc.
- Nu incercam reconstructia originalelor din imagini watermarkuite fara backup curat.
