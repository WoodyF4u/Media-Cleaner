# Changelog

Elke regel is ook een test die je na installatie kunt aflopen om te
controleren of een functie daadwerkelijk is meegenomen.

## 2.7.18

- **Na een update (of een herstelde bestand uit "Tijdelijk verwijderd")
  wordt niet meer de oude, verouderde informatie getoond terwijl de
  automatische herscan op de achtergrond nog loopt.** Voorheen bleef
  alles - aantallen, knoppen, de hele bestandenlijst - gewoon zichtbaar
  en klikbaar naast de voortgangsbalk, wat de indruk wekte dat je er al
  mee kon werken. Nu toont de pagina in dat geval alleen:
  1. De titel "Media Cleaner".
  2. De tekst "Scan wordt opnieuw uitgevoerd..." met de voortgangsbalk.

  Pas zodra de herscan echt klaar is en de pagina automatisch ververst,
  verschijnt de rest (aantallen, filters, knoppen, bestandenlijst) weer
  - met de actuele gegevens.
- Hergebruikt het bestaande voortgangsbalkje-mechanisme (dezelfde balk
  die je al zag bij een handmatige "Scan opnieuw uitvoeren" of na het
  wijzigen van Opties) - alleen wordt nu ook de rest van de pagina eronder
  bewust leeggehouden zolang die balk actief is.
- Test: installeer deze update via Extensies → Bijwerken, open daarna
  Media Cleaner - je hoort eerst alleen de titel en de voortgangsbalk te
  zien, pas na afloop de rest. Geen schema-wijziging.

## 2.7.17

- **De "hoort mogelijk bij extensie"-toelichting en de "images"-in-
  extensie-regel (v2.7.13) herkennen nu ook `/media/<extensie>/...` en
  `/administrator/components/<extensie>/...`** - dezelfde uitbreiding
  als v2.7.16, nu ook toegepast op de plek die de toelichtingstekst zelf
  bepaalt (niet alleen de "is deze extensie actief"-check).
- Kleine, bewuste beperking: bij `/media/<x>/` voor een component of
  module wordt de toelichting zelf gewoon getoond, maar onthoudt de
  component dit niet apart in zijn interne register van "eerder gezien,
  inmiddels verwijderde extensies" (dat register kan namelijk niet
  betrouwbaar onderscheiden of `/media/<x>/` bij een component of een
  module hoort). Praktisch gevolg: verwijder je die extensie later
  helemaal, dan verdwijnt de toelichting gewoon weer - geen verkeerd
  onthouden naam die blijft hangen.
- Test na herscan: bestanden onder `/media/mod_x/...`,
  `/media/plg_systeem_x/...` of `/administrator/components/com_x/...`
  horen nu de "hoort mogelijk bij extensie"-toelichting te tonen (en
  eventueel de systeembestand-toelichting, als het ook nog in een
  images/canvas/fonts-map zit of icon/logo in de naam heeft). Geen
  schema-wijziging.

## 2.7.16

- **Bug gefixt: "canvas"/"fonts"/"icon" werden vaak niet herkend als
  systeembestand, ook al hoorden ze bij een actieve extensie.** Oorzaak
  zat dieper dan die drie regels zelf: de onderliggende "hoort bij een
  actieve extensie"-check (`isActiveExtensionAsset`, ook gebruikt door
  de bestaande "hoort mogelijk bij extensie"-hint) herkende alleen de
  klassieke mapstructuur (`/components/`, `/modules/`, `/templates/`,
  `/plugins/<groep>/`). De sinds meerdere Joomla-versies aanbevolen
  moderne opslagplek `/media/<extensie>/...` - en `/administrator/
  components/<extensie>/...` - werden daardoor helemaal niet herkend,
  dus alles wat daar stond viel altijd buiten "canvas"/"fonts"/"icon",
  ongeacht hoe actief de extensie was.
- Deze twee conventies zijn er nu bij: `/media/<extensie>/...`
  (component, module, template via `/media/templates/site|
  administrator/<sjabloon>/...`, en plugin via het samengevoegde
  `/media/plg_<groep>_<element>/...`) en `/administrator/components/
  <extensie>/...`.
- **"logo" toegevoegd aan de bestandsnaam-herkenning**, naast "icon" -
  zelfde regel (alleen bij een actieve extensie).
- Test na herscan: bestanden in bijvoorbeeld `/media/mod_x/fonts/` of
  met "logo" in de naam binnen een actieve extensie horen nu wél de
  systeembestand-toelichting te tonen. Geen schema-wijziging.

## 2.7.15

- **Bestanden met "icon" in de bestandsnaam gelden nu ook als
  systeembestand**, mits ze bij een op dit moment **actieve** component,
  module, plugin of template horen. Dit kijkt naar de bestandsnaam zelf
  (bv. "icon-close.svg", "favicon.png", "app-icons.png"), niet naar de
  map - een extensie kan een icoon net zo goed los in zijn eigen
  hoofdmap zetten als in een aparte "icons"-map.
- Zelfde reden als bij "canvas"/"fonts" (v2.7.14) voor de eis "actief":
  "icon" komt nog vaker toevallig voor in een gewone bestandsnaam (denk
  aan een eigen upload als "site-icon-definitief.png") dan "fonts" als
  mapnaam, dus alleen binnen een bewezen actieve extensie is dat risico
  weg. Bewust beperkt tot component/module/plugin/template
  (`isActiveExtensionAsset`), niet de `/images/<x>/`-mediabibliotheek-
  conventie - laat het weten als je dat toch breder wilt.
- Ook deze slaat de datumcheck over - onvoorwaardelijk systeem, net als
  de vorige uitbreidingen.
- Test na herscan: een bestand met "icon" in de naam bij een actieve
  extensie hoort de systeembestand-toelichting te tonen; hetzelfde
  bestand bij een uitgeschakelde extensie, of een eigen upload met
  "icon" in de naam die niet bij een extensiemap hoort, hoort dat niet
  te krijgen. Geen schema-wijziging.

## 2.7.14

- **"canvas" en "fonts" toegevoegd, maar strenger gescoped dan
  "images".** Op verzoek gelden bestanden in een map "canvas" of
  "fonts" alleen als systeembestand als ze bovendien bij een op dit
  moment **actieve** component, module, plugin of template horen - dus
  net iets strenger dan de "images"-regel uit v2.7.13, die ook meetelt
  als de extensie inmiddels is uitgeschakeld. Dat onderscheid was
  precies waarom "fonts" in eerdere versies bewust nog niet in de lijst
  stond: op zichzelf een te gewone mapnaam om zomaar te vertrouwen,
  maar binnen een bewezen actieve extensie is dat risico weg.
- Intern hergebruikt: `Scanner::applyExtensionImagesSubfolderOverride()`
  is samengevoegd met de nieuwe logica tot één herbruikbare
  `applyExtensionFolderSegmentOverride($items, $mapnamen, $vereistActief)`.
  Gedrag van de bestaande "images"-regel (v2.7.13) blijft ongewijzigd.
- Ook deze twee slaan de datumcheck over - onvoorwaardelijk systeem,
  net als "images".
- Test na herscan: bestanden in een `canvas`- of `fonts`-map bij een
  actieve extensie horen de systeembestand-toelichting te tonen;
  dezelfde mapnamen bij een uitgeschakelde extensie horen dat niet te
  krijgen. Geen schema-wijziging.

## 2.7.13

- **"yootheme" toegevoegd aan de systeembestand-mapnamen**, net als
  eerder `assets`/`vendor`/`webfonts`/`themes`/`thumbs`.
- **Bevestigd (was al zo, geen wijziging nodig): submappen onder
  `assets` matchen al.** De herkenning kijkt naar elk padsegment
  afzonderlijk, dus `.../assets/foo/bar/plaatje.jpg` matcht al net zo
  goed als `.../assets/plaatje.jpg` - hoeveel mappen er nog onder
  `assets` zitten maakt niet uit.
- **Nieuw, voorzichtiger dan een simpele mapnaam-match: een map
  "images" binnen een herkende component/module/plugin/template geldt
  nu altijd als systeembestand.** Dit is bewust NIET toegevoegd als
  gewoon mapsegment "images" aan dezelfde lijst als assets/vendor/etc.
  - dat zou namelijk ook de hoofd-mediabibliotheek zelf raken (alles
  staat daar al onder een map die "images" heet). In plaats daarvan
  wordt dit alleen toegepast op bestanden waarvoor al vaststaat dat ze
  bij een herkende extensie horen (dezelfde `assetDirExtensionName`-
  herkenning die de bestaande "hoort mogelijk bij extensie"-hint al
  gebruikt) - dus specifiek binnen `/components/...`, `/modules/...`,
  `/plugins/.../...` en (ook, hoewel je dat niet noemde maar dezelfde
  conventie geldt) `/templates/...`. Laat het weten als je templates
  daar liever niet bij wilt.
- Deze twee nieuwe categorieën slaan bewust de datum-check over (geen
  "waarschijnlijk later toegevoegd"-uitzondering) - je gaf aan dat dit
  altijd systeembestanden zijn, dus die onzekerheidsmarge is hier niet
  nodig.
- Test na herscan: bestanden in een `yootheme`-map, en bestanden in
  bijvoorbeeld `/components/com_x/images/...` horen nu de
  systeembestand-toelichting te tonen. Geen schema-wijziging.

## 2.7.12

- **Het systeembestand-filter staat nu ook bij "Niet-gekoppelde
  media"**, niet meer alleen bij "Genegeerde media". Zelfde dropdown,
  zelfde werking (ook samen met "Selecteer alle N... alle pagina's").
- **"Toon overige bestanden" is hernoemd naar "Verberg
  systeembestanden"** op beide plekken.
- Label "Alle genegeerde bestanden" is generiek gemaakt naar "Alle
  bestanden", omdat dezelfde dropdown nu op twee verschillende tabs
  voorkomt.
- Test: ga naar "Niet-gekoppelde media" - de dropdown "Filter: Alle
  bestanden / Toon systeembestanden / Verberg systeembestanden" hoort
  daar nu ook te staan, naast de bestaande op "Genegeerde media". Geen
  schema-wijziging.

## 2.7.11

- **UX-fix "Selecteer alle N... (alle pagina's)"**: dat vinkje zette de
  losse selectievakjes eronder voorheen alleen op grijs/uitgeschakeld,
  zonder ze ook echt aan te vinken - functioneel correct (de bulkactie
  werkte al op de volledige set), maar visueel gaf het geen duidelijke
  bevestiging dat alles geselecteerd was. Nu worden het hoofdvinkje én
  alle zichtbare selectievakjes op de pagina ook daadwerkelijk
  aangevinkt zodra je dit vinkje aanzet, en weer leeggemaakt zodra je
  het uitzet.
- Test: vink "Selecteer alle N... (alle pagina's)" aan - alle
  selectievakjes op de pagina (inclusief het hoofdvinkje) horen meteen
  zichtbaar aangevinkt te zijn. Geen schema-wijziging.

## 2.7.10

- **`thumbs` en `themes` toegevoegd aan de systeembestand-herkenning**,
  op verzoek. `themes` is een nieuw, generiek mapsegment (net als
  `assets`/`vendor`/`webfonts`) - kwam ook in eerder onderzocht
  praktijkvoorbeeld voor (`.../themes/base/css`, `.../themes/base/
  vendors/...`), bundelt een sjabloon of plugin's eigen skin-bestanden.
  `thumbs` krijgt er de systeembestand-markering bij, los van zijn
  eigen bestaande, aparte gedrag (automatisch negeren bij scannen,
  sinds v2.7.3) - dat blijft ongewijzigd. Het enige verschil: een
  thumbs-bestand telt nu ook mee onder "Toon systeembestanden" op
  "Genegeerde media", en wordt ook meegenomen door de knop "Zet
  systeembestanden apart" mocht een thumbs-bestand om wat voor reden
  dan ook nog niet automatisch genegeerd zijn.
- Kleine opschoning van een onafgemaakte commentaarregel in
  `Scanner.php` (geen functionele wijziging).
- Test na herscan: bestanden in een `thumbs`- of `themes`-map horen nu
  de systeembestand-toelichting te tonen op "Niet-gekoppeld", en mee te
  tellen in de teller bij "Zet systeembestanden apart". Geen
  schema-wijziging.

## 2.7.9

- **Bug gefixt: "Zet systeembestanden apart" stond ook (nutteloos) op
  "Genegeerde media".** De teller telde daar per ongeluk bestanden die
  al genegeerd waren, dus de knop leek iets te doen maar veranderde
  niets. De knop is nu alleen nog zichtbaar op "Niet-gekoppelde media",
  waar hij hoort.
- **Nieuw op "Genegeerde media": filter "Toon systeembestanden" /
  "Toon overige bestanden"** (naast "Alle genegeerde bestanden"). Zo
  kun je binnen de genegeerde bestanden onderscheid maken tussen wat
  als systeembestand is aangemerkt (via de nieuwe knop, of automatisch
  via de thumbs-herkenning) en de rest die je zelf met een reden hebt
  genegeerd. Werkt ook samen met "Selecteer alle N... (alle pagina's)",
  bijvoorbeeld om alleen de systeembestanden in één keer terug te
  zetten.
- Test: klik op "Niet-gekoppeld" op "Zet systeembestanden apart", ga
  naar "Genegeerde media" → de knop hoort daar nu weg te zijn, en de
  nieuwe dropdown hoort te verschijnen. Kies "Toon systeembestanden" →
  moet alleen de zojuist apart gezette bestanden tonen. Geen
  schema-wijziging.

## 2.7.8

- **Nieuw: systeembestanden van extensies herkennen en in één klik apart
  zetten.** Onderzocht aan de hand van een echte site met veel
  extensies: mappen met het segment `assets`/`asset`, `vendor(s)` of
  `webfonts` blijken structureel, over compleet ongerelateerde
  extensies heen, kleine generiek benoemde UI-iconen en bundel-
  bibliotheken te bevatten (bv. PageBuilder CK's eigen backend-iconen,
  Font Awesome, icomoon) - nooit content die een beheerder zelf zou
  uploaden. Nieuwe, generieke padherkenning
  `Scanner::pathHasSystemAssetSegment()`, zelfde aanpak als de
  bestaande `thumbs`-herkenning. Bewust NIET toegevoegd: kale `system`
  als mapnaam (botst met Joomla's eigen `/plugins/system/`-structuur en
  zou veel te breed matchen).
- **Onderscheid systeembestand vs. later handmatig toegevoegd**: de
  bestandsdatum (nieuw vastgelegd, `file_modified_at`) wordt per
  systeem-assetmap vergeleken. Bestanden die op dezelfde dag zijn
  aangemaakt als de meerderheid van hun map gelden als "hoort bij de
  extensie"; bestanden met een duidelijk afwijkende datum worden apart
  gehouden als "waarschijnlijk later toegevoegd" en dus niet meegenomen
  in de voorselectie. Mappen met minder dan 3 bestanden worden niet
  beoordeeld (te weinig om een meerderheid te bepalen).
- **Nieuwe knop "Zet systeembestanden apart (negeren)"** op het
  overzicht, met het aantal erbij. Negeert in één keer alle bestanden
  die aan bovenstaande criteria voldoen - bewust alleen negeren, nooit
  verwijderen, want dit blijft een inschatting op basis van mapnaam en
  datum, geen zekerheid.
- Twee nieuwe kolommen (`file_modified_at`, `is_system_asset_dir`,
  `likely_manual_upload`) via `2.7.8.sql` (alleen ADD, geen DROP).
  Vereist een herscan om effect te hebben op bestaande data.
- Test na herscan: bekijk een bekende `assets`/`vendor`/`webfonts`-map
  in "Niet-gekoppeld" - bestanden daar horen een toelichting te tonen
  ("systeembestand" of, bij afwijkende datum, "mogelijk later
  toegevoegd"). De knop bovenaan hoort het juiste aantal te tonen en na
  bevestigen die bestanden naar "Genegeerde media" te verplaatsen.

## 2.7.7

- **Bug gefixt: extensiefilter bleef onzichtbaar hangen en filterde
  alles weg.** Op cvzutphen.nl toonde "Alle media" 0 resultaten terwijl
  de teller op de knop zelf gewoon 1546 liet zien, en de dropdown leek
  "Alle extensies" te tonen. Oorzaak: de v2.7.5-logica die het
  extensiefilter moest resetten zodra je van hoofdtab wisselt, las de
  "vorige" tab-waarde pas nadat die al was overschreven door dezelfde
  aanroep die 'm net had ververst - de vergelijking zag dus altijd twee
  gelijke waarden en de reset kwam nooit in actie. Een eerder gekozen
  extensiefilter bleef zo onzichtbaar actief hangen bij het wisselen van
  tab (de dropdown-optie "Alle extensies" krijgt dan geen `selected`,
  maar de browser toont 'm toch als eerste in de lijst, wat het extra
  verwarrend maakte).
- Twee dingen gefixt: (1) de vorige tab-waarde wordt nu wél echt vóór
  de ververs-aanroep gelezen, dus de reset werkt weer bij het wisselen
  van tab; (2) als extra vangnet wordt een bewaard extensiefilter dat
  niet meer voorkomt in de hints van de huidige tab (bv. na een herscan)
  nu ook stilzwijgend teruggezet op "Alle extensies", in plaats van tot
  een leeg overzicht te leiden.
- Test: filter op een extensie op bijvoorbeeld "Niet-gekoppeld", wissel
  daarna naar "Alle media" → moet gewoon alle 1546 tonen, dropdown moet
  echt op "Alle extensies" staan. Geen schema-wijziging.

## 2.7.6

- **Volgorde "Actie voor geselecteerde items" aangepast**: Negeren staat
  nu eerst, dan Verwijderen, dan Herstel genegeerd - was Verwijderen,
  Negeren, Herstel genegeerd. Puur volgorde; werking ongewijzigd.
- Test: open de dropdown bij "Actie voor geselecteerde items" - Negeren
  hoort bovenaan te staan en standaard geselecteerd te zijn (herken je
  ook aan de knopkleur, die meteen bij "Negeren" past). Geen
  schema-wijziging.

## 2.7.5

- **Grote herstructurering van de bulkacties**, op Wouters verzoek.
  De losse "Bulkselectie voor negeren/verwijderen"-balk (met zijn eigen
  extensie-dropdown en eigen actieknop) is volledig verdwenen.
- **Nieuw: filter op extensie onder alle vier de hoofdknoppen** (Alle/
  Gekoppeld/Niet-gekoppeld/Genegeerd). Een dropdown-blokje toont elke
  gevonden extensie-hint met aantal; kiezen ervoor toont alleen nog de
  bestanden van die extensie binnen de actieve tab. Wisselen van
  hoofdtab zet dit filter automatisch terug op "Alle extensies".
- **"Actie voor geselecteerde items" kan nu ook over alle pagina's
  tegelijk werken.** Zodra een filter meer dan één pagina beslaat,
  verschijnt de optie "Selecteer alle N bestanden uit dit overzicht
  (alle pagina's)". Aangevinkt negeert de losse selectievakjes en past
  de gekozen actie toe op de volledige, actuele set die bij het huidige
  filter hoort - dus ook op bijvoorbeeld alle 1137 jDownloads-bestanden
  in één klik, ongeacht paginagrootte.
- **De actieve-extensie-bescherming werkt onveranderd door**, nu op dit
  ene, generieke mechanisme: bulk-Verwijderen (via checkboxes of via
  "alle pagina's") sluit bestanden van nog actieve extensies nog altijd
  uit; bulk-Negeren niet. Levert dit niets op om te verwijderen, dan
  krijg je een duidelijke melding in plaats van een lege actie.
- Interne opschoning: `getItems()`, `getFilteredCount()` en
  `getFilteredTotalSizeKB()` delen nu dezelfde filter-opbouw
  (`applyLinkedIgnoredWhere()`/`applyExtensionHintWhere()`), zodat
  schermtelling en daadwerkelijke inhoud nooit uit de pas kunnen lopen.
- Test na deze update (geen rescan nodig, puur query-/UI-laag):
  1. Op elke van de vier hoofdtabs hoort een extensiefilter-dropdown te
     verschijnen zodra er hints zijn; een extensie kiezen filtert de
     lijst en past de paginering aan.
  2. Van tab wisselen hoort het extensiefilter terug te zetten op "Alle
     extensies".
  3. Filter op een extensie met meer bestanden dan één pagina, vink
     "Selecteer alle N ... (alle pagina's)" aan, kies Negeren → hoort
     alle bestanden van die extensie te negeren, niet alleen de
     zichtbare pagina.
  4. Herhaal met Verwijderen op een extensie die (deels) actief is →
     hoort alleen het onbeschermde deel te verwijderen, met een
     passende melding.
  Geen schema-wijziging.

## 2.7.4

- **Bulk "Negeren" op "Niet gekoppelde media" werkt nu ook op bestanden
  van actieve extensies** (bv. de systeemplaatjes/iconen die jDownloads,
  comprofiler of Kunena zelf meeleveren) - voorheen werden die volledig
  buiten de hint-bulkbalk gehouden, nu verschijnen ze gewoon in de
  dropdown en kun je ze in één keer negeren.
- **Bulk "Verwijderen" blijft wél geblokkeerd voor diezelfde bestanden.**
  Kies je "Verwijderen" voor een hint waarvan alle bestanden bij een
  actieve extensie horen, dan krijg je nu een duidelijke melding in
  plaats van een bevestigingsvraag die alsnog niks zou verwijderen.
  Bevat een hint zowel beschermde als onbeschermde bestanden, dan raakt
  Verwijderen alleen de onbeschermde - het bevestigingsscherm toont voor
  Verwijderen daarom ook een apart (kleiner of gelijk) aantal dan voor
  Negeren.
- De twee Opties-instellingen "Bestanden van actieve componenten/
  modules/plugins/templates" en "Media-bestanden van actieve
  extensies" beschermen dus voortaan alleen nog tegen bulk-Verwijderen,
  niet meer tegen bulk-Negeren - tekst in Opties bijgewerkt.
- Test na deze update (geen rescan nodig, puur query-laag): kies op
  "Niet gekoppeld" een hint van een actieve extensie → Negeren moet nu
  gewoon alle bestanden van die hint negeren. Kies daarna dezelfde hint
  met Verwijderen → hoort een melding te tonen dat er niets te
  verwijderen valt (of alleen het onbeschermde deel, als dat er is).
  Geen schema-wijziging.

## 2.7.3

- **Nieuw: unlinked bestanden in een 'thumbs'-map worden nu automatisch
  genegeerd bij het scannen**, in plaats van in "Niet gekoppelde media"
  te blijven staan met alleen een toelichtingstekstje. Ze verschijnen nu
  direct onder "Genegeerde media" - niet uit beeld verdwenen, maar ook
  niet meer tussen de bestanden die je nog moet beoordelen. Hergebruikt
  de bestaande Opties-toggle "'thumbs'-mappen in de map 'images'"
  (standaard aan); zet je 'm uit, dan blijft het oude gedrag (gewoon
  zichtbaar onder Niet-gekoppeld, met toelichting).
- **Belangrijk:** zet je een automatisch genegeerd thumbnail-bestand
  handmatig terug (Genegeerde media → terugzetten), dan onthoudt de
  component dat blijvend - een latere scan negeert dat specifieke
  bestand niet nog een keer automatisch. Nieuwe `#__mediacleaner_ignored`-
  kolommen `source` ('manual'/'auto_thumbs') en `suppressed` maken dit
  onderscheid mogelijk.
- Test na herscan: een nog-niet-gekoppeld thumbnail-bestand (bv. onder
  `/images/.../thumbs/...`) hoort te verdwijnen uit "Niet gekoppelde
  media" en op te duiken onder "Genegeerde media". Zet 'm daar handmatig
  terug, scan nogmaals, en controleer dat hij dit keer níét opnieuw
  automatisch genegeerd wordt.
- Nieuw SQL-updatebestand `2.7.3.sql` (alleen ADD COLUMN, geen DROP).

## 2.7.2

- **Bug gefixt: case-only bestandsnaam-duplicaten in dezelfde map konden
  een echte koppeling verbergen.** Gevonden op bmwcruiser.nl:
  `Luxemburg-oude-binnenstad-van-de-stad.jpg` (met hoofdletter L, echt
  gebruikt in een iCagenda-evenement) en
  `luxemburg-oude-binnenstad-van-de-stad.jpg` (kleine l, ongebruikte
  duplicaat) stonden allebei in dezelfde map. `Scanner::
  buildFileLookupIndex()` bouwt de padindex hoofdletterongevoelig (nodig
  omdat matching zelf ook hoofdletterongevoelig is), maar sloeg per pad
  maar één bestandsindex op - het tweede bestand met dezelfde naam in
  een andere hoofdlettering overschreef stilletjes het eerste. Een
  treffer op dat pad kon dus maar één van de twee bestanden als
  "gekoppeld" markeren; welke, was afhankelijk van de scanvolgorde.
  Fix: `byPath` slaat nu alle botsende bestanden per pad op (net als
  `byName` al deed) en een treffer markeert ze allemaal als gekoppeld -
  de veilige kant, want ten onrechte "Niet-gekoppeld" tonen is
  gevaarlijker dan een duplicaat ten onrechte als gekoppeld laten zien.
  Test: na een herscan hoort `Luxemburg-oude-binnenstad-van-de-stad.jpg`
  (hoofdletter L) "Gekoppeld" te blijven zoals voorheen; de kleine-l
  variant kan nu ook als gekoppeld getoond worden totdat je zelf hebt
  gecontroleerd of het echt een ongebruikt duplicaat is. Geen
  schema-wijziging.

## 2.7.1

- **Bug gefixt: paginering deed niets.** Op het overzicht (bv. 20 items
  per pagina) veranderde het paginanummer wel van kleur/actief-status
  bij een klik op pagina 2/3/4, maar de inhoud bleef exact hetzelfde.
  Oorzaak: `admin/tmpl/files/default.php`'s `adminForm` miste het hidden
  veld `limitstart` dat Joomla's eigen `Pagination`-klasse nodig heeft
  om de paginanavigatie te versturen (`document.adminForm.limitstart
  .value = X; document.adminForm.submit();` faalde stil omdat dat veld
  niet bestond, waardoor de submit nooit werd bereikt). Test: klik op
  pagina 2 en controleer dat er echt andere bestanden verschijnen.
- **Verbeterd: uitleg wanneer de "Bulkselectie voor negeren/verwijderen"
  balk leeg lijkt.** Bij "Niet-gekoppelde media" verdween die balk
  voorheen stilletjes zodra ALLE gehinte niet-gekoppelde bestanden bij
  actieve extensies horen en dus door de Opties-bescherming
  (`applyBulkHintProtectionExclusions()`) worden uitgesloten - dat
  gedrag zelf is niet veranderd (blijft de standaardbeveiliging tegen
  per ongeluk bulk-verwijderen van bestanden die een actieve extensie
  nog gebruikt), maar in plaats van een lege balk staat er nu een korte
  toelichting met het aantal betrokken bestanden en een verwijzing naar
  de twee Opties-instellingen die dit veroorzaken. Nieuw:
  `FilesModel::getProtectedHintFileCount()`. Test: op een site waar dit
  speelt zie je nu een tekstregel i.p.v. niets; na het uitzetten van een
  van beide Opties-instellingen verschijnt de bulkbalk zelf weer. Geen
  schema-wijziging.

## 2.7.0

- **De bulkselectie-op-extensie-hint ("Bulkselectie voor negeren/
  verwijderen") werkt nu ook bij "Gekoppelde media"**, niet meer alleen
  bij "Niet-gekoppelde media". Aanleiding: Wouter's Jindi-site had
  tientallen bestanden onder `/templates/yootheme/...`, allemaal
  "Waarschijnlijk gekoppeld", zonder enige manier om die in één keer te
  negeren - de hele hint+bulkactie-machinerie bleek alleen voor het
  Niet-gekoppeld-overzicht gebouwd te zijn.
- **Gewijzigd:** `getUnlinkedExtensionHintCounts()` /
  `getIdsForExtensionHint()` / `ignoreByExtensionHint()` in
  `FilesModel.php` accepteren nu een `$filterLinked`
  ('unlinked'/'linked') parameter (hernoemd naar
  `getExtensionHintCounts()` voor de eerste). De naam-hint zelf
  (`assetDirExtensionName`/`imagesDirExtensionName`, "Hoort mogelijk bij
  extensie: X") wordt nu ook getoond bij Gekoppelde/Waarschijnlijk-
  gekoppelde bestanden, niet meer alleen bij Niet-gekoppelde - die
  berekening liep al onafhankelijk van gekoppeld-status, alleen de
  template liet het niet zien.
- **Veiligheid:** bij "Gekoppelde media" biedt de bulkbalk uitsluitend
  "Negeren" aan, nooit "Verwijderen" - `deleteByExtensionHint()` heeft
  bewust geen `$filterLinked`-parameter gekregen en blijft
  onbereikbaar voor gekoppelde bestanden. Voor "Niet-gekoppelde media"
  blijft de bestaande bescherming tegen actieve-extensie-bestanden
  (`applyBulkHintProtectionExclusions()`) ongewijzigd van kracht; voor
  "Gekoppelde media" is die uitsluiting niet van toepassing, want daar
  zijn de bestanden van een actieve extensie juist precies waar deze
  actie voor bedoeld is.
- Nieuwe taalstring `COM_MEDIACLEANER_HINT_BULK_LABEL_LINKED`
  ("Bulkselectie voor negeren:") voor het label op de Gekoppeld-tab.
  Help-pagina en Opties-teksten bijgewerkt.
- Geen databasewijziging - alleen queries en presentatie.

## 2.6.0

- **De som "Alle media = Gekoppeld + Niet-gekoppeld" klopt nu altijd.**
  Wouter merkte op dat 206 (Alle media) niet gelijk was aan 204
  (Gekoppeld) + 1 (Niet-gekoppeld) - er ontbrak 1 bestand. Oorzaak: de
  drie "verberg van Niet-gekoppeld"-opties (thumbs-mappen, bestanden van
  actieve componenten/modules/plugins/templates, media-bestanden van
  actieve extensies) sloten bestanden met `linked = 0` volledig uit van
  zowel de lijst als de telling van "Niet-gekoppelde media" - zonder dat
  ze ergens anders meetelden. Zo'n bestand was dus wel `linked = 0`
  (feitelijk niet-gekoppeld), maar nergens zichtbaar.
- **Gewijzigd:** `getItems()`, `getFilteredCount()`,
  `getFilteredTotalSizeKB()` en `getUnlinkedCount()` in `FilesModel.php`
  passen geen enkele uitsluiting meer toe - een bestand met
  `linked = 0` staat nu altijd in "Niet-gekoppelde media" en telt altijd
  mee, ook als het bij een actieve extensie hoort of in een
  'thumbs'-map zit. De al bestaande naam-hint ("hoort mogelijk bij
  extensie X") verschijnt daardoor automatisch ook bij deze eerder
  verborgen bestanden - die berekening liep al onafhankelijk van de
  verberg-optie. Nieuwe hint toegevoegd voor 'thumbs'-mappen
  (`COM_MEDIACLEANER_THUMBS_DIR_HINT`), die had nog geen toelichting.
- **Veiligheid behouden:** de drie Opties-schakelaars doen niet meer
  niks - ze sluiten deze bestanden nu uit van de **bulk**-hint-actie
  ("Bulkselectie voor negeren/verwijderen"), zodat je nooit per ongeluk
  een lading bestanden van een nog actieve extensie in één klik
  meeverwijdert samen met écht overbodige bestanden. Nieuwe, hernoemde
  methode `applyBulkHintProtectionExclusions()` (voorheen
  `applyUnlinkedViewExclusions()`) past dit alleen nog toe in
  `getUnlinkedExtensionHintCounts()`/`getIdsForExtensionHint()`. Los
  negeren/verwijderen per bestand blijft altijd mogelijk, zoals bij elk
  ander niet-gekoppeld bestand.
- Opties-scherm (`config.xml`-teksten) en Help-pagina
  (`COM_MEDIACLEANER_HELP_OPTIONS_BODY`) tekstueel bijgewerkt zodat ze
  het nieuwe gedrag beschrijven in plaats van het oude "verbergen".
  Dezelfde optie-sleutels (params) zijn gebruikt, dus bestaande
  instellingen blijven gewoon behouden na de update.
- Geen databasewijziging (alleen queries/presentatie aangepast, geen
  nieuwe kolom) - geen nieuw SQL-updatebestand.

## 2.5.2

- **Handmatig een bestand terugzetten vanuit "Verwijderde media" (Quarantine)
  start nu automatisch een nieuwe scan op de achtergrond.** Voorheen bleef
  het bestand na het terugzetten onzichtbaar in het Niet-gekoppeld/
  Gekoppeld-overzicht totdat er handmatig opnieuw werd gescand - `#__mediacleaner_files`
  had nog geen rij voor dat bestand, omdat die rij bij het verwijderen zelf
  al was gewist (zie `QuarantineManager::quarantine()`). Daardoor leek het
  net alsof er niets was teruggezet.
- **Gewijzigd:** `QuarantineModel::restore()` zet nu, na een geslaagd
  terugzetten, dezelfde eenmalige "moet opnieuw scannen"-marker die
  `script.php` al gebruikte na een update (`_needs_rescan_after_update` in
  de eigen extension-params). Zodra je daarna weer op de Files-pagina komt
  (bijvoorbeeld via "Terug naar overzicht"), start de bestaande
  achtergrond-scan-met-voortgangsbalk automatisch - geen nieuwe UI nodig,
  hergebruikt de bestaande `mc_auto_rescan`-flow volledig.
- Geen databasewijziging (params-marker, geen kolom) - geen nieuw
  SQL-updatebestand.

## 2.5.1

- **Alle CSS uit de code gehaald.** De vier inline `<style>`-blokken (in
  `admin/tmpl/files/default.php`, `admin/tmpl/quarantine/default.php`,
  `admin/tmpl/help/default.php` en `site/tmpl/files/default.php`) zijn
  verplaatst naar twee losse bestanden: `media/css/admin.css` (Files,
  Quarantine en Help samen - veel overlappende classes zoals knoppen,
  badges en checkboxes) en `media/css/site.css` (de frontend-view). Geen
  CSS meer hardcoded in de PHP-bestanden, en beide bestanden zijn na
  installatie gewoon handmatig aan te passen op
  `.../media/com_mediacleaner/css/admin.css` en `.../site.css`.
- Geen databasewijziging in deze versie (puur bestanden/assets) - geen
  nieuw SQL-updatebestand.
- Twee kleine, bewust behouden verschillen tussen de vroegere Files- en
  Quarantine-stijlblokken zijn opgelost zonder het uiterlijk te
  veranderen: Quarantine's losstaande samenvattingsregel kreeg een
  eigen `mc-summary-standalone`-klasse (voorkomt dubbele marge t.o.v.
  Files' `.mc-summary-row`), en de bulkactiebalk laat nu overal
  consistent wrappen op smalle schermen.
- **Let op:** de kleine `<style>`-hack die de Opties-koppen op het
  Instellingen-tabblad breed maakt (`COM_MEDIACLEANER_CONFIG_HEADING_STYLE_HACK`
  in de taalbestanden) is bewust NIET meeverhuisd - die wordt gerenderd
  door Joomla's eigen com_config-formulier, dat dit component-eigen
  stylesheet niet laadt.

## 2.5.0

- **De "hoort mogelijk bij extensie..."-herkenning die eerder alleen
  voor `/images/<x>` bestond, is uitgebreid naar `/components/<x>`,
  `/modules/<x>`, `/templates/<x>` en `/plugins/<groep>/<element>`.**
  Reden: dezelfde soort mismatch die bij Balbooa (2.4.1.2) en JCH
  Optimize (2.2.1/2.2.2) voor `/images/` is opgelost - een mapnaam op
  schijf die niet letterlijk overeenkomt met het Joomla-element - bleek
  ook voor te komen onder `/templates/`.
- **Gewijzigd:** `pathBelongsToActiveExtension()` (bepaalt
  `is_active_extension_asset`, de "verberg bestanden van actieve
  extensies"-uitsluiting) gebruikt nu dezelfde normalisatie- en
  woordgrens-prefixmatching als de `/images/`-hint
  (`matchFolderSegmentToExtension()`, hernoemd vanuit
  `matchImagesFolderNameToExtension()`), in plaats van een kale exacte
  vergelijking. Bij plugins blijft de groep (bv. `content`, `system`)
  exact gematcht; alleen het element-segment wordt losser vergeleken.
- **Nieuw:** de "mogelijk wees-extensie"-hint voor deze vier mappen
  noemt nu net als bij `/images/` de extensienaam zelf
  (`asset_dir_extension_name`/`asset_dir_extension_removed`, nieuwe
  kolommen), in plaats van alleen een naamloze "hoort mogelijk bij een
  niet meer geïnstalleerde extensie"-melding. Nieuw register
  `#__mediacleaner_known_asset_extensions` (met `folder_type` erbij,
  zodat een component/module/plugin/template met dezelfde naam elkaar
  niet in de weg zitten) onthoudt ook hier een eerder herkende, inmiddels
  verwijderde extensie.
- **Gewijzigd:** de bulkselectie-dropdown "Bulkselectie voor
  negeren/verwijderen" (v2.3.0-2.3.2) leest nu zowel
  `images_dir_extension_name` als `asset_dir_extension_name`, dus
  hints uit deze vier mappen verschijnen daar automatisch ook in - geen
  aparte aanpassing nodig.

## 2.4.1.2

- **Grondoorzaak gevonden en opgelost: bestanden onder `/images/bagallery/...`
  (Balbooa Joomla Gallery) werden nooit herkend als horend bij een
  actieve extensie, noch bij de "verberg bestanden van actieve
  extensies"-optie, noch bij de informatieve "hoort mogelijk bij..."-hint.**
  Oorzaak: Balbooa registreert zichzelf in `#__extensions` met
  element `bagallery` onder `type = 'file'` (geen component/module/
  plugin/template), en dat type werd nergens meegenomen in de
  matching-logica - ook niet nadat het front-end component zelf was
  hernoemd naar `com_gallery` (waardoor de bestaande component-matching
  sowieso niet meer aansloot op de mapnaam `bagallery`).
- **Gewijzigd:** `getAllInstalledExtensionNamesByImagesFolder()` neemt
  nu ook `type = 'file'`-rijen mee (naast component/module/plugin/
  template), zodat de "hoort mogelijk bij..."-hint dit soort pakketten
  herkent.
- **Nieuw:** `getEnabledFileTypeElements()` - lijst van elementnamen van
  alle ingeschakelde `type = 'file'`-extensies. `pathBelongsToActiveExtensionImagesFolder()`
  gebruikt deze lijst nu als derde matchbron, zodat de "verberg
  bestanden van actieve extensies"-optie in "Niet-gekoppeld" ook echte
  `file`-type pakketten zoals Balbooa Joomla Gallery herkent - niet
  alleen deze ene extensie, maar elk pakket dat op dezelfde manier via
  een `file`-rij is geregistreerd. Geen schemawijziging nodig.

## 2.0.4

- **Gewijzigd:** de lege-staat-melding ("geen bestanden gevonden") op
  het front-end-overzicht had dezelfde soort leesbaarheidsprobleem als
  v2.0.3 - `color: #666` op een permanent donkere achtergrond. Nu
  `#d7dade`, gelijk aan de kolom "Locatie".

## 2.0.3

- **Gewijzigd:** de kolomkoppenbalk van het front-end-overzicht had een
  lichte achtergrond (`#f4f4f4`) zonder expliciete tekstkleur, waardoor
  de (witte, geërfde) tekst onleesbaar werd op het permanent donkere
  sitesjabloon. Achtergrond nu donker (`#2b2f33`) met expliciet lichte
  tekst (`#e8e8e8`).
- **Gewijzigd:** de tekst in de kolom "Locatie" (het bestandspad) was
  `#555` (donkergrijs) - onleesbaar op een zwarte achtergrond. Nu
  `#d7dade` (lichtgrijs, bijna wit).

## 2.0.2

- **Grondoorzaak gevonden en opgelost: de v2.0.1-fix deed niets, omdat
  het nieuwe bestand nooit werd geïnstalleerd.** `site/metadata.xml` zelf
  was correct (klopte al met Joomla's eigen verwachte schema), maar
  stond niet vermeld in de bestandenlijst van het manifest
  (`<files folder="site">` noemde alleen de mappen `services`/`src`/
  `tmpl`/`language`, geen los bestand). Joomla installeert uitsluitend
  wat daar expliciet staat, dus het bestand belandde bij v2.0.1 gewoon
  nooit op de server, ondanks dat het in de zip zat.
- **Gewijzigd:** `<file>metadata.xml</file>` toegevoegd aan die lijst.
  Na installatie van deze versie zou "Media Cleaner" nu daadwerkelijk
  moeten verschijnen bij het aanmaken van een nieuw menu-item.

## 2.0.1

- **Grondoorzaak gevonden en opgelost: Media Cleaner verscheen nergens in
  de lijst bij een nieuw menu-item op de voorkant.** Joomla's eigen
  `MenutypesModel::getTypeOptionsByComponent()` controleert eerst op een
  `metadata.xml` in de root van het front-end-gedeelte van de
  component, en valt anders terug op een scan naar mappen die letterlijk
  `view`/`views` heten - de oude, niet-namespaced MVC-conventie. Ons
  front-end-gedeelte gebruikt overal de moderne, namespaced structuur
  (`site/src/View/...`), dus die terugval vond niets; en het alternatief
  daarvan (een `default.xml` naast `site/tmpl/files/default.php`)
  ontbrak ook. Het resultaat: Media Cleaner registreerde helemaal geen
  menu-itemtype bij Joomla, en dook dus nooit op in de lijst - dit heeft
  er dus nog nooit ingezeten, in plaats van dat het kapot is gegaan.
- **Toegevoegd:** `site/metadata.xml`, exact volgens het schema dat
  Joomla's eigen `getTypeOptionsFromXml()` verwacht. Na installatie van
  deze versie verschijnt "Media Cleaner" bij het aanmaken van een nieuw
  menu-item.

## 2.0.0

- **Versienummer bewust gereset naar 2.0.0** als vers startpunt op
  GitHub, na de reeks structurele fixes in v1.36.0 t/m v1.37.5 (manifest-
  bestandsnaam, `#__schemas`-zelftegenspraak, installatiesnelheid,
  opties-migratie bij verse installatie, ontbrekende indexen bij verse
  installatie, en de FilesModel/Scanner/QuarantineManager/WebpConverter-
  herstructurering). Geen functionele wijzigingen ten opzichte van
  v1.37.5 - dezelfde code, alleen het versienummer en de releasedatum
  zijn aangepast.

## 1.37.5

- **Opgeruimd:** de twee tijdelijke debug-hulpmiddelen die expliciet
  gekoppeld waren aan een op dat moment nog onopgelost probleem, zijn nu
  verwijderd omdat beide problemen daadwerkelijk gevonden en opgelost
  zijn:
  - `writeManifestDebugLog()` (Files/HtmlView.php, sinds v1.35.0) - hoorde
    bij de manifest_cache-puzzel, opgelost in v1.36.0 (verkeerde
    bestandsnaam).
  - `writeTimingLog()` (script.php, sinds v1.37.2) - hoorde bij de
    installatietraagheid, die uiteindelijk hosting-specifiek bleek te
    zijn (bevestigd: dezelfde installatie duurde op een andere host maar
    5-6 seconden), niet iets in deze component zelf.
  Beide waren in hun eigen documentatie al gemarkeerd als "veilig te
  verwijderen zodra de oorzaak gevonden en opgelost is".
- **Blijven bewust wél staan** (expliciet gedocumenteerd als permanent,
  niet tijdelijk): de crash-logger in Scanner.php (schrijft alleen iets
  bij een fatale PHP-fout tijdens een scan) en de scan-samenvatting-log
  in Scanner.php (één beknopte regel per scan). Beide zijn laagdrempelige,
  altijd-nuttige diagnostische hulpmiddelen, geen resten van een
  specifieke bugjacht.
- `administrator/logs/mediacleaner_manifest_debug.log` wordt door dit
  component niet langer beschreven; het bestand zelf blijft staan tot je
  het handmatig opruimt.

## 1.37.4

- **Grondoorzaak gevonden en opgelost: een verse installatie miste 23
  indexen die Systeeminformatie → Database als "probleem" meldde.**
  `admin/sql/install.mysql.utf8.sql` (het basis-installatiescript) was
  sinds ergens rond v1.9.0 niet meer bijgewerkt met alle indexen die
  sindsdien via losse bestanden in `admin/sql/updates/mysql/` zijn
  toegevoegd. Die updatebestanden draaien alléén bij het bijwerken van
  een bestaande installatie - nooit bij een verse installatie, die enkel
  dit ene basisscript uitvoert. Op elke site die ooit is bijgewerkt
  (zoals injekracht.com) accumuleerden deze indexen dus gewoon vanzelf;
  op een gloednieuwe installatie (zoals bij Biljartvereniging De
  Voorstad) ontbraken ze stelselmatig.
- **Gewijzigd:** alle 23 ontbrekende indexen zijn teruggeport naar het
  basisscript - 19 op `mediacleaner_files`, 2 op `mediacleaner_
  quarantine`, 1 op `mediacleaner_ignored`, 1 op `mediacleaner_
  references` - inclusief de drie functioneel overbodige "_repair"-
  indexen uit v1.5.2, omdat Systeeminformatie's controle op indexnaam
  checkt, niet op of een gelijkwaardige index al bestaat. Een verse
  installatie krijgt hiermee precies hetzelfde schema als een volledig
  bijgewerkte bestaande site. Bestaande sites zijn niet geraakt: alleen
  het installatiescript is aangepast, geen enkel updatebestand.

## 1.37.3

- **Grondoorzaak gevonden en opgelost: bestanden van actieve extensies
  werden na een verse installatie niet meer uitgesloten van "Niet-
  gekoppeld", ondanks dat de drie schakelaars op het Opties-scherm
  standaard op "Ja" horen te staan.** `config.xml` declareert
  `default="1"` voor alle drie, maar Joomla's `ComponentHelper::
  getParams()` vult die XML-default nooit automatisch in de opgeslagen
  `params`-kolom - bij een verse installatie is die kolom leeg, en de
  PHP-fallback in dit component (`->get($sleutel, 0)`) leest een
  ontbrekende waarde dan als "uit". De eenmalige migratie die dit al
  sinds v1.31.0 rechtzet (`migrateUnlinkedViewDefaultsToOn()`) draaide
  alléén in de `update()`-hook, die Joomla nooit aanroept bij een verse
  installatie - alleen bij het bijwerken van een bestaande. Dat had tot
  nu toe geen zichtbaar effect, omdat elke eerdere test een update van
  een bestaande installatie was; pas door een echte "verwijderen +
  opnieuw installeren" kwam dit voor het eerst aan het licht.
- **Gewijzigd:** de migratie draait nu vanuit `postflight()`, die voor
  zowel `install` als `update` wordt aangeroepen - een verse installatie
  krijgt daarmee dezelfde correcte standaardwaarden als een update altijd
  al kreeg. Installeer deze versie eenmalig en de drie schakelaars staan
  weer goed, zonder dat je ze handmatig hoeft om te zetten.

## 1.37.2

- **Diagnostische tijdmeting toegevoegd (geen fix op zichzelf).** v1.37.1
  bracht de installatie/update-tijd terug van enkele minuten naar zo'n 50
  seconden, maar dat is nog steeds veel meer dan de paar seconden die dit
  zou moeten kosten. In plaats van opnieuw te gokken naar de resterende
  oorzaak, schrijft `postflight()` nu een tijdsduur per fase
  (`removeStaleLegacyManifestFile`, `repairManifestCache`,
  `unlinkedViewIndexLooksHealthy`, en de officiële Fix-aanroep indien die
  draait) naar hetzelfde debuglogbestand als eerder
  (`administrator/logs/mediacleaner_manifest_debug.log`), plus de totale
  postflight-duur. Na installatie van deze versie kan het logbestand
  precies aanwijzen welke fase de tijd kost.
- Veilig te verwijderen zodra de resterende traagheid gevonden en
  opgelost is.

## 1.37.1

- **De v1.37.0-fix voor de installatieduur was onvolledig, nu echt
  opgelost.** De voorwaarde `$versionWasStale || ...` zorgde ervoor dat
  de zware officiële "Fix Structure"-aanroep tóch bij elke normale
  versie-update bleef draaien: `repairManifestCache()` meldt altijd "was
  verouderd" zodra het versienummer op schijf daadwerkelijk verschilt van
  wat er nog gecachet stond - en dat is exact wat er gebeurt bij élke
  echte nieuwe release. De voorwaarde raakte dus nooit uitgeschakeld voor
  het meest voorkomende geval: een nieuwe versie over een oudere heen
  installeren.
  Inzicht dat ontbrak: nu de manifest-bestandsnaam correct is (sinds
  v1.36.0), voert Joomla's **eigen normale** install/update-proces -
  niet deze postflight-hook, niet de officiële Fix-routine - `manifest_
  cache` en eventuele nieuwe SQL-updatebestanden al vanzelf correct uit,
  gewoon als standaardgedrag. Dat was nooit stuk; de verkeerde
  bestandsnaam verborg het alleen. De zware Fix-aanroep draait nu **alleen
  nog** op basis van de snelle, gerichte indexcontrole - nooit meer
  simpelweg omdat het versienummer is gewijzigd.

## 1.37.0

- **Grondoorzaak gevonden en opgelost: installatie/update duurde soms
  enkele minuten.** `postflight()` riep sinds v1.35.0 bij élke install/
  update onvoorwaardelijk Joomla's eigen volledige "Fix Structure"-routine
  aan. Die routine herleest en herchecked bij elke aanroep opnieuw
  letterlijk élk historisch SQL-updatebestand (inmiddels 60 stuks) - één
  controlequery per DDL-statement, niet alleen de nog niet toegepaste. De
  twee oorspronkelijke redenen om dit onvoorwaardelijk te maken (de
  manifest-bestandsnaambug uit v1.36.0 en de `#__schemas`-tegenstrijdigheid
  uit v1.36.1) zijn beide al opgelost, dus is deze zware aanroep nu
  **voorwaardelijk** gemaakt: hij draait alleen nog als de snelle,
  lichtgewicht indexcontrole (dezelfde `information_schema`-query die de
  Files-pagina al gebruikt) daadwerkelijk een probleem signaleert. Een
  installatie/update zou hierdoor weer binnen enkele seconden klaar moeten
  zijn.
- **Herstructurering (geen gedragswijziging):** `FilesModel.php` was
  gegroeid tot ruim 2700 regels. Opgesplitst in vier bestanden met elk één
  duidelijke verantwoordelijkheid:
  - `admin/src/Service/Scanner.php` - de bestandssysteem-scan en de
    koppelingsdetectie (was ± 1300 regels van het oude model).
  - `admin/src/Service/QuarantineManager.php` - verplaatsen naar/uit
    quarantaine.
  - `admin/src/Service/WebpConverter.php` - de WebP-conversie.
  - `admin/src/Model/FilesModel.php` - blijft over als dunne laag: lijst
    ophalen/filteren, de Opties-schermuitsluitingen, en
    rechten-gecontroleerde toegangspunten die doorverwijzen naar
    bovenstaande drie klassen.
  Elke methode is letterlijk verplaatst, niet herschreven - er is
  bewust geen gedrag veranderd. Wel gecorrigeerd tijdens het verplaatsen:
  het frontend-model (`site/src/Model/FilesModel.php`) verwees nog naar de
  oude, inmiddels verplaatste `doQuarantine()`-methode; dat is bijgewerkt
  naar de nieuwe `QuarantineManager`.

## 1.36.2

- **Gewijzigd:** `<creationDate>` in de manifest bijgewerkt naar de
  daadwerkelijke releasedatum (was sinds de allereerste versie statisch
  blijven staan op "September 2026"). De kolom "Datum" bij Extensies:
  Beheren toont deze waarde rechtstreeks (via `manifest_cache`) en niet
  een echt install-/updatetijdstip, dus stond al die tijd vast op
  01-09-2026 ongeacht hoe vaak er werd geüpdatet.
- **Afspraak vanaf nu:** `<creationDate>` wordt voortaan bij elke
  versiebump meegewerkt, net als `<version>` zelf.

## 1.36.1

- **Grondoorzaak gevonden en opgelost (nieuw, los van de v1.36.0-fix):**
  na het oplossen van de manifest-bestandsnaam werd voor het eerst
  zichtbaar dat `repairManifestCache()` (script.php) en
  `resetSchemaBookkeeping()` (Files/HtmlView.php) `#__schemas.version_id`
  bij elke install/update én elke paginaweergave forceerden naar de
  manifest-versie zelf (bijv. "1.36.0"), in plaats van naar de hoogst
  aanwezige SQL-updateversie op schijf ("1.27.0" — er is sinds v1.28.0
  geen nieuw databaseschema-bestand meer toegevoegd). Dat botste
  structureel met Joomla's eigen "Fix Structure"-berekening, die
  daardoor telkens meteen weer werd teruggedraaid. Zichtbaar als "Eén
  probleem" bij Extensies: Beheren: "Database versie (1.36.0) komt niet
  overeen met de manifest versie (1.27.0)".
- **Gewijzigd:** de handmatige `#__schemas`-overschrijving is volledig
  verwijderd uit beide plekken. Joomla's eigen officiële "Fix
  Structure"-aanroep (die al bestond, en die wél correct rekent op basis
  van de daadwerkelijke SQL-bestanden) is nu de enige plek die
  `#__schemas` aanpast.
- Eén herinstallatie van deze versie corrigeert de huidige, foutieve
  waarde vanzelf via de postflight-aanroep naar Joomla's eigen fix.

## 1.36.0

- **Grondoorzaak gevonden en opgelost:** de manifest-XML van dit component
  heette `com_mediacleaner.xml`. Joomla's eigen kern-installer kopieert die
  naam letterlijk mee naar
  `administrator/components/com_mediacleaner/com_mediacleaner.xml`, maar
  Joomla's eigen `InstallerHelper::getInstallationXML()` (gebruikt door o.a.
  System Information → Database → "Fix", en dus ook door de eigen
  postflight-reparatie van dit component) zoekt altijd naar
  `mediacleaner.xml` (elementnaam zonder `com_`-voorvoegsel). Doordat dat
  bestand niet bestond, zag Joomla's eigen "Fix"-routine de geïnstalleerde
  versie steeds als leeg, en overschreef daarmee stilzwijgend `manifest_
  cache.version` terug naar leeg — in dezelfde requestcyclus waarin onze
  eigen reparatie het net goed had gezet. Dit verklaart waarom de eerdere
  patches (v1.26.0 t/m v1.35.0) telkens leken te falen ondanks bevestigd
  geslaagde UPDATE-query's.
- **Gewijzigd:** de manifest is hernoemd naar `mediacleaner.xml` (de
  Joomla-conventie), zodat Joomla's eigen kernmechanismen het voortaan
  vanzelf goed doen, zonder enige reparatietruc nodig te hebben.
- **Toegevoegd:** eenmalige opschoonstap bij install/update die het oude,
  verkeerd genoemde `com_mediacleaner.xml`-bestand verwijdert zodra het
  nieuwe, correct genoemde bestand aanwezig is.

## 1.35.0

- **Gewijzigd:** geen databasewijziging in deze versie — documentatie en
  de Help-pagina.
- **Bijgewerkt:** de Help-pagina is grondig nagelopen en geactualiseerd:
  - Overal waar "koppelingscontrole" stond, staat nu "Media Cleaner".
  - De inleidende tekst was verouderd (zei dat CSS/broncode van
    templates/plugins/componenten niét werd doorzocht, terwijl dat sinds
    lang wél gebeurt) - dit is gecorrigeerd naar de daadwerkelijke,
    huidige werking.
  - Twee onderwerpen die nog geheel ontbraken zijn toegevoegd: het
    Opties-scherm (de drie schakelaars) en de "Hoort mogelijk bij een
    niet meer geïnstalleerde Joomla-extensie"-opmerking.
  - De titel "Tijdelijk verwijderd" en de knopnamen bij de filters zijn
    bijgewerkt naar de huidige tekst op de hoofdpagina ("Toon
    genegeerde media", "Toon verwijderde media", "Alle media" in plaats
    van "Alle bestanden").
  - Rechtsboven op de Help-pagina staat nu, net als op de hoofdpagina,
    "Media Cleaner · v<versienummer>".
  - ✅ Test: open Help - rechtsboven staat het versienummer, de
    inleidende tekst noemt "Media Cleaner" (niet "koppelingscontrole"),
    en onderaan staan ook de onderwerpen "Opties" en de
    verweesde-extensie-opmerking.
- **Nieuw:** `README.md` (Engels) en `README.nl.md` (Nederlands)
  toegevoegd aan de zip - een volledig overzicht van alle functies,
  hoe de koppelingscontrole werkt, het Opties-scherm, installatie en
  waar vragen gesteld kunnen worden.
- **Bijgewerkt:** `updates.xml` (buiten de zip, voor de GitHub-
  updateserver) staat nu op versie 1.35.0, en vermeldt dat Wouter
  (WoodyF4u) de maker is en dat vragen via GitHub Issues gesteld
  kunnen worden.

## 1.34.0

- **Gewijzigd:** geen databasewijziging in deze versie — puur lay-out.
- **Opgelost (voor het echt, deze keer geverifieerd tegen Joomla's eigen
  broncode):** Help staat nu écht direct naast Opties. De oorzaak was
  gevonden dankzij de meegestuurde paginabron van een Phoca-extensie:
  Joomla plakt zelf automatisch een Bootstrap "ms-auto"-klasse op elke
  werkbalkknop waarvan de link `option=com_config` bevat (dat is precies
  onze "Opties"-knop), waardoor die knop altijd naar de rechterrand van
  de werkbalk springt - los van de volgorde waarin knoppen worden
  toegevoegd. De vorige aanpak (1.32.0) voegde Help vóór Opties toe,
  waardoor Help gewoon links bleef staan terwijl alleen Opties naar
  rechts sprong. Oplossing: Opties wordt nu vóór Help toegevoegd, zodat
  Help direct meelift naast Opties' automatische rechteruitlijning -
  precies zoals bij de Phoca-extensie, zonder enige CSS- of
  JavaScript-truc.
  - ✅ Test: bovenin staat "Help" nu direct naast "Opties" aan de
    rechterkant, zonder tussenruimte.
- **Gewijzigd:** de tekst in de vijf filterknoppen ("Alle media",
  "Gekoppelde media", "Niet-gekoppelde media", "Toon genegeerde media",
  "Toon verwijderde media") is vergroot van 12 naar 14 pixels.
  - ✅ Test: de tekst in de filterknoppen is merkbaar groter dan
    voorheen.

## 1.33.0

- **Gewijzigd:** geen databasewijziging in deze versie — puur opmaak.
- **Gewijzigd:** de rand van de vijf filterknoppen ("Alle media",
  "Gekoppelde media", "Niet-gekoppelde media", "Toon genegeerde media",
  "Toon verwijderde media") is bij niet-actieve knoppen duidelijker
  zichtbaar: dikker (2px in plaats van 1px) en donkerder grijs, zowel
  in het lichte als het donkere thema.
  - ✅ Test: een niet-actieve filterknop heeft nu een duidelijk
    zichtbaar, iets dikker randje in plaats van een nauwelijks
    zichtbaar lichtgrijs randje.
- **Nog niet opgelost:** Help staat nog niet direct naast Opties. De
  vorige aanpak (alleen de volgorde aanpassen waarin de knoppen worden
  toegevoegd) bleek niet voldoende - er zit blijkbaar iets in Joomla's
  eigen werkbalk-opmaak dat "Opties" los van de toevoegvolgorde naar
  een vaste plek duwt. Om dit deze keer in één keer goed op te lossen
  (in plaats van nóg een aanname te doen die niet blijkt te kloppen),
  is de daadwerkelijke HTML-broncode van die werkbalk nodig - zie het
  bijbehorende gesprek.

## 1.32.0

- **Gewijzigd:** geen databasewijziging in deze versie — puur lay-out.
- **Verplaatst:** de knop "Tijdelijk verwijderd" stond bovenin de
  werkbalk; deze staat nu als **"Toon verwijderde media"** in de balk
  met "Alle media" / "Gekoppelde media" / "Niet-gekoppelde media" /
  "Toon genegeerde media", met hetzelfde aantal-tussen-haakjes als de
  andere knoppen in die balk.
  - ✅ Test: bovenin staat nu alleen nog "Scan opnieuw uitvoeren",
    "Help" en "Opties"; "Toon verwijderde media" staat in de filterbalk
    en linkt nog steeds naar hetzelfde "Tijdelijk verwijderd"-overzicht.
- **Verplaatst:** de knop "Help" staat nu direct naast "Opties" bovenin
  de werkbalk (in plaats van links, naast "Scan opnieuw uitvoeren").
  Dit is opgelost door simpelweg de volgorde aan te passen waarin de
  knoppen worden opgebouwd - een eerdere poging om knoppen met CSS
  (`order` + `margin-left: auto`) naar rechts te forceren bleek
  onbetrouwbaar (kon knoppen buiten beeld duwen) en is destijds al
  teruggedraaid; dit is dus de robuustere aanpak.
  - ✅ Test: bovenin staan "Scan opnieuw uitvoeren" uiterst links, en
    "Help" direct naast "Opties" aan de rechterkant.

## 1.31.0

- **Gewijzigd:** geen structurele databasewijziging in deze versie
  (wel een eenmalige aanpassing van de opgeslagen instellingen zelf,
  zie hieronder) — geen nieuw SQL-updatebestand.
- **Gewijzigd:** het kopje boven de drie opties bleek in 1.30.0 nog
  steeds niet altijd over de volle breedte te lopen. Er is nu
  aanvullende, expliciete opmaak toegevoegd die dit afdwingt,
  onafhankelijk van de precieze weergave-eigenaardigheden van het
  Opties-scherm.
  - ✅ Test: Opties → tabblad "Instellingen" — het kopje "Tonen of
    verbergen in overzicht 'Niet gekoppelde media'" loopt over de volle
    breedte en is duidelijk dikgedrukt.
- **Gewijzigd:** de drie opties staan nu standaard op **"Ja"** in plaats
  van "Nee". Dit geldt niet alleen voor nieuwe installaties, maar is
  ook **eenmalig doorgevoerd op bestaande installaties** (dus ook op
  jouw site) via een kleine, eenmalige migratie die bij het bijwerken
  automatisch draait - een eventuele latere, bewuste keuze om er één
  weer op "Nee" te zetten wordt door toekomstige updates nooit meer
  ongedaan gemaakt.
  - Omdat dit de instellingen verandert (niet alleen de weergave), is
    een **nieuwe scan** nodig voordat dit zichtbaar effect heeft op de
    lijst "Niet gekoppelde media".
  - ✅ Test: installeer 1.31.0, open Opties → tabblad "Instellingen" -
    alle drie de schakelaars staan op "Ja". Klik daarna op "Scan
    opnieuw uitvoeren" om het effect in de lijst te zien.
- **Verwijderd:** de informatiebalk "Instellingen voor hoe grondig een
  scan de site doorzoekt op mediaverwijzingen." boven aan het tabblad
  "Instellingen" is weg.
  - ✅ Test: op het tabblad "Instellingen" staat geen blauwe
    informatiebalk meer boven de velden.

## 1.30.0

- **Gewijzigd:** geen databasewijziging in deze versie — puur opmaak op
  het Opties-scherm.
- **Gewijzigd:** het kopje "Tonen of verbergen in overzicht 'Niet
  gekoppelde media'" (toegevoegd in 1.29.0) stond nog gevangen in de
  linkerkolom, net als een gewoon veld-label. Dit veldtype is vervangen
  door Joomla's "note"-veld, waarmee de tekst nu als een echte,
  dikgedrukte kop (`<h4>`) over de volledige breedte van de pagina
  wordt getoond, los van de kolomindeling van de drie opties eronder.
  - ✅ Test: Opties → tabblad "Instellingen" — het kopje loopt nu over
    de volle breedte en is duidelijk dikgedrukt, in plaats van
    ingeklemd in de linkerkolom.

## 1.29.0

- **Gewijzigd:** geen databasewijziging in deze versie — puur tekst en
  indeling op het Opties-scherm.
- **Nieuw:** kopje **"Tonen of verbergen in overzicht 'Niet gekoppelde
  media'"** boven de drie uitsluitingsopties op het tabblad
  "Instellingen".
- **Gewijzigd:** kortere, duidelijkere namen en teksten voor de drie
  opties:
  - "Mappen 'thumbs' verbergen bij Niet gekoppeld" → **"'thumbs'-mappen
    in de map 'images'"**
  - "Bestanden van actieve componenten/modules/plugins/templates
    verbergen bij Niet gekoppeld" → **"Bestanden van actieve
    componenten/modules/plugins/templates"**
  - "Mappen 'images' van actieve extensies verbergen bij Niet
    gekoppeld" → **"Media-bestanden van actieve extensies"**
  - Bijbehorende toelichtingsteksten zijn ingekort tot de kern: wat er
    wordt getoond/verborgen, en dat bestanden van uitgeschakelde of
    verwijderde extensies gewoon zichtbaar blijven.
  - ✅ Test: open Opties → tabblad "Instellingen" en controleer het
    nieuwe kopje en de kortere namen/teksten van de drie opties.

## 1.28.0

- **Opschoning:** geen databasewijziging in deze versie — puur een
  correctie van de *tekst* van al eerder toegepaste updatebestanden,
  dus zonder nieuw SQL-updatebestand.
- Na 1.27.0 installeerde alles correct, maar Systeeminformatie →
  Database bleef "Eén probleem" tonen: de bestanden 1.22.0.sql,
  1.24.0.sql en 1.26.0.sql verwijzen nog naar de oude indexnaam
  `idx_unlinked_view`, die we sinds 1.27.0 bewust niet meer onderhouden
  (we maken alleen nog `idx_unlinked_view2` aan). Die melding was **niet
  fout** - hij klopte gewoon nog letterlijk met wat die oude bestanden
  beweerden - maar wel verwarrend om te blijven zien.
  - Deze versie werkt de tekst van die drie oude updatebestanden bij,
    zodat ze ook `idx_unlinked_view2` vermelden - dit is veilig, want
    Joomla voert een updatebestand van een versie die al eerder is
    toegepast nooit opnieuw uit; het past alleen aan waar de
    database-checker dat bestand tegenaan controleert.
  - Geen enkele bestaande database-structuur verandert hierdoor - dit
    is puur cosmetisch voor het Systeeminformatie-scherm.
  - ✅ Test: installeer 1.28.0, open de hoofdpagina één keer (voor het
    zelfherstel), en controleer bij Systeeminformatie → Database of
    "Eén probleem" is verdwenen.

## 1.27.0

- **Bugfix (belangrijk):** de installatie van 1.26.0 kon volledig
  afbreken met de foutmelding "Can't DROP INDEX `idx_unlinked_view`;
  check that it exists" / "Extensie installatie afgebroken". Oorzaak:
  1.24.0 probeerde deze index te verbreden door hem eerst te
  verwijderen en daarna opnieuw aan te maken onder dezelfde naam - en
  op jouw site bleek die index op dat moment niet (meer) te bestaan.
  Een mislukte SQL-instructie halverwege een updatebestand blijkt bij
  een normale pakketinstallatie de **hele** installatie af te breken,
  in plaats van alleen die ene instructie over te slaan (dat laatste
  gebeurt wel bij Joomla's eigen "Structuur herstellen"-knop, vandaar
  het verschil met eerdere versies).
  - **Definitieve oplossing:** vanaf nu wordt een bestaande index of
    kolom nooit meer verwijderd via een updatebestand. Deze versie
    voegt de bedoelde 5-kolomsindex toe onder een **nieuwe naam**
    (`idx_unlinked_view2`) - een pure toevoeging kan nooit op deze
    manier mislukken, ongeacht of de oude `idx_unlinked_view` op een
    site wel, niet, of in de oude 4-kolomsvorm bestaat. Die oude index
    wordt met rust gelaten (onschadelijk, ook al wordt hij niet meer
    gebruikt).
  - Het zelfherstel uit 1.26.0 controleert nu ook op `idx_unlinked_view2`
    in plaats van `idx_unlinked_view`.
  - ✅ Test: installeer 1.27.0 via "Extensies: Installeren" — dit zou nu
    gewoon moeten slagen, zonder foutmelding. Controleer daarna bij
    Systeeminformatie → Database of er geen problemen meer worden
    gemeld.

## 1.26.0

- **Bugfix:** op minstens één site bleef Systeeminformatie → Database na
  de upgrade naar 1.24.0/1.25.0 melden dat de index `idx_unlinked_view`
  ontbreekt, ook al leek de rest van die update prima te zijn
  doorgevoerd - de ALTER TABLE-statement die de index verbreedde, is
  daar kennelijk stil blijven steken.
  - Deze versie herhaalt die structuurwijziging (DROP + ADD van
    `idx_unlinked_view`) opnieuw - veilig om te herhalen, ook als de
    index inmiddels al goed staat.
  - Belangrijker: het zelfherstel dat dit component al gebruikte voor
    de `manifest_cache`-controle (elke paginaweergave) controleert nu
    ook direct in `information_schema` of deze index de juiste 5
    kolommen heeft. Als dat niet zo is, wordt Joomla's eigen
    "Structuur herstellen" opnieuw aangeroepen, **ook als
    `manifest_cache` toevallig al up-to-date lijkt** - dat was namelijk
    precies waarom dit probleem eerder onopgemerkt bleef hangen: zodra
    de versie eenmaal was bijgewerkt, had de bestaande controle geen
    enkele reden meer om het nog eens te proberen.
  - ✅ Test: installeer deze versie en open de hoofdpagina één keer.
    Controleer daarna bij Systeeminformatie → Database of de melding
    over `idx_unlinked_view` weg is. Mocht hij toch nog verschijnen,
    dan mag je ook gewoon zelf op "Herstellen" klikken op dat scherm -
    dat doet exact hetzelfde als wat er nu automatisch gebeurt.

## 1.25.0

- **Gewijzigd:** geen databasewijziging in deze versie — puur UI/besturing,
  dus zonder het gebruikelijke SQL-updatebestand.
- **Gewijzigd:** de automatische scan na het opslaan van Opties (zie
  1.23.0) blokkeert niet langer de terugkeer naar de hoofdpagina. Voorheen
  liep de scan af vóórdat de browser werd doorgestuurd, waardoor het
  leek alsof er niets gebeurde. Nu:
  1. Ga je direct terug naar de Media Cleaner-hoofdpagina zodra je op
     "Opslaan" of "Opslaan en sluiten" klikt.
  2. Zie je daar meteen het bekende voortgangsbalkje lopen (hetzelfde
     balkje als bij de handmatige knop "Scan opnieuw uitvoeren"), terwijl
     de scan op de achtergrond draait.
  3. Zodra de scan klaar is, ververst de pagina zichzelf automatisch met
     de nieuwe resultaten en de vertrouwde melding dat de scan is
     uitgevoerd.
  - Technisch: de terugkeer-URL van de Opties-knop draagt nu direct de
    marker die de hoofdpagina zelf herkent; er is geen tussenstap in de
    server meer die op de scan wacht voordat je iets te zien krijgt. De
    scan zelf wordt op de achtergrond gestart via dezelfde actie als de
    handmatige knop.
  - ✅ Test: open Opties, zet een instelling om, klik "Opslaan en
    sluiten" — je zit direct weer op de hoofdpagina met het
    voortgangsbalkje zichtbaar, en na afloop toont de pagina zichzelf
    vernieuwd met de scanmelding, zonder dat je iets hoefde te doen.

## 1.24.0

- **Nieuw:** optie op het Opties-scherm (tabblad "Instellingen"):
  **"Mappen 'images' van actieve extensies verbergen bij Niet
  gekoppeld"**. Staat standaard uit.
  - Bestanden onder `/images/<element>/...` worden bij inschakelen
    weggelaten uit de lijst "Niet gekoppelde media" én uit het
    bijbehorende aantal, mits dat component nog geïnstalleerd én actief
    is. Herkenning werkt op zowel het volledige element
    (`/images/com_jdownloads/...`) als de kale naam zonder `com_`-prefix
    (`/images/icagenda/...`), omdat extensies dit op disk allebei
    gebruiken.
  - Een bestand onder een `/images/<element>/...`-map waarvan het
    component uitgeschakeld of niet meer geïnstalleerd is, wordt **niet**
    uitgesloten en blijft gewoon als niet gekoppeld getoond.
  - De bestanden zelf worden niet aangeraakt en blijven gewoon zichtbaar
    onder "Alle media" en "Gekoppelde media".
  - ✅ Test: zet de optie aan, klik "Scan opnieuw uitvoeren", en
    controleer dat bestanden onder `/images/<element>/...` van een
    actief component niet meer in "Niet gekoppelde media" staan. Schakel
    dat component uit, scan opnieuw, en controleer dat de bestanden dan
    wél weer als niet gekoppeld verschijnen.
- **Nieuw:** in het overzicht van "Niet gekoppelde media" krijgt een
  bestand dat in een `components/`, `modules/`, `plugins/` of
  `templates/`-map zit maar niet bij een momenteel actieve extensie
  hoort, een opmerking onder de locatie: **"Hoort mogelijk bij een niet
  meer geïnstalleerde Joomla-extensie."**
  - Dit is bewust **niet** uitgebreid naar willekeurige mappen onder
    `/images/` (zoals `images/banners` of `images/stories`) - daar is op
    basis van alleen de mapnaam niet betrouwbaar te onderscheiden tussen
    een verweesde extensiemap en een gewone, door de beheerder zelf
    aangemaakte inhoudsmap.
  - ✅ Test: een bestand onder bijv. `/components/com_iets-dat-niet-meer-
    bestaat/...` toont de opmerking onder de locatie in "Niet gekoppelde
    media"; een gewoon bestand in `/images/` zonder extensie-mapstructuur
    toont hem niet.
- **Verwijderd:** de optie "Extra tabellen overslaan" is uit het
  Opties-scherm gehaald. Deze werd steeds minder nodig nu gerichtere
  technieken (thumbs-mappen, actieve componenten/modules/plugins/
  templates, en nu ook actieve `/images/`-mappen) hetzelfde soort
  fout-positieven afvangen.
  - ✅ Test: op het Opties-scherm, tabblad "Instellingen", staat dit veld
    niet meer.

## 1.23.0

- **Gewijzigd:** geen databasewijziging in deze versie — puur UI/besturing,
  dus zonder het gebruikelijke SQL-updatebestand.
- **Gewijzigd:** op het Opties-scherm is het tabblad "Scan" hernoemd naar
  **"Instellingen"** en staat nu **vóór** het tabblad "Rechten" (was
  andersom).
  - ✅ Test: klik op "Opties" — het eerste tabblad heet "Instellingen" en
    bevat de scan-gerelateerde velden; het tweede tabblad heet "Rechten".
- **Nieuw:** na het opslaan van de Opties (zowel "Opslaan" als "Opslaan en
  sluiten") en terugkeer naar de hoofdpagina wordt automatisch een nieuwe
  scan gestart — je hoeft dus niet meer zelf op "Scan opnieuw uitvoeren"
  te klikken om een gewijzigde instelling (bijv. een van de "verbergen bij
  Niet gekoppeld"-opties) direct terug te zien.
  - Werkt via de "return"-URL van de Opties-knop: die krijgt een
    onzichtbare markering mee, die na terugkeer op de hoofdpagina wordt
    herkend, één keer een scan uitvoert en daarna de schone URL toont (een
    pagina-herlading of "Terug" in de browser start dus geen tweede scan).
  - ✅ Test: open Opties, zet een instelling om, klik "Opslaan en
    sluiten" — je komt terug op de hoofdpagina en ziet de melding dat de
    scan is uitgevoerd (hetzelfde bericht als bij de handmatige
    "Scan opnieuw uitvoeren"-knop), zonder zelf iets extra's te hoeven
    doen.

## 1.22.0

- **Uitgebreid:** de optie **"Bestanden van actieve componenten/
  modules/plugins/templates verbergen bij Niet gekoppeld"** (voorheen
  alleen componenten/templates) dekt nu ook **modules** en **plugins**:
  - `/modules/<element>/...` telt mee als dat module actief is
    (`client_id = 0`, dus alleen site-modules — admin-modules worden
    sowieso al niet gescand).
  - `/plugins/<groep>/<element>/...` telt mee als dat specifieke plugin
    (groep + element samen, bijv. `system/cache`, `content/pagebreak`)
    actief is — plugins hebben immers een tweeledige mapstructuur, in
    tegenstelling tot componenten/modules/templates.
  - Bestaat geen nieuwe kolom voor nodig: `is_active_extension_asset`
    dekte de betekenis "hoort bij een actieve extensie" altijd al in
    algemene zin, alleen de herkenningslogica is uitgebreid.
  - Er is wel een nieuwe samengestelde index (`idx_unlinked_view` op
    `linked, ignored, is_thumbs_dir, is_active_extension_asset`)
    toegevoegd die precies de WHERE-vorm van de "Niet gekoppeld"-query
    dekt zodra beide uitsluitingsopties tegelijk aanstaan.
  - Zoals bij elke wijziging aan deze vlag: eenmalig **opnieuw scannen**
    na de upgrade is nodig voordat modules/plugins meetellen.
  - ✅ Test: zet de optie aan, klik "Scan opnieuw uitvoeren", en
    controleer dat bestanden onder `/modules/mod_xxx/...` en
    `/plugins/<groep>/<element>/...` van actieve extensies niet meer in
    "Niet gekoppelde media" staan. Schakel zo'n module/plugin uit, scan
    opnieuw, en controleer dat de bijbehorende bestanden dan wél weer
    als niet gekoppeld verschijnen.

## 1.21.0

- **Nieuw:** optie op het Opties-scherm (tabblad "Scan"): **"Bestanden
  van actieve componenten/templates verbergen bij Niet gekoppeld"**.
  Staat standaard uit.
  - Een bestand onder `/components/<element>/...` of
    `/templates/<element>/...` wordt bij inschakelen weggelaten uit de
    lijst "Niet gekoppelde media" én uit het bijbehorende aantal, maar
    alleen als dat component of template op dat moment nog daadwerkelijk
    geïnstalleerd én actief is (gecontroleerd tegen `#__extensions`).
  - Dit soort mappen bevat vaak meegeleverde lay-outplaatjes die pas
    worden opgehaald zodra een specifieke lay-outkeuze wordt gemaakt —
    ze zijn dan best "niet gekoppeld" volgens de scan, maar horen wel
    degelijk bij een nog actieve extensie.
  - Een bestand onder een uitgeschakeld of verweesd component/template
    wordt **niet** uitgesloten en blijft gewoon als niet gekoppeld
    getoond — de uitsluiting geldt alleen zolang de eigenaar nog actief
    is.
  - De bestanden zelf worden niet aangeraakt en blijven gewoon zichtbaar
    onder "Alle media" en "Gekoppelde media".
  - De vlag wordt per bestand bepaald tijdens het scannen (tegen de
    lijst actieve componenten/templates op dat moment) en opgeslagen in
    een nieuwe, geïndexeerde kolom (`is_active_extension_asset`). Na de
    upgrade moet je dus eenmalig **opnieuw scannen** voordat de optie
    effect heeft op bestaande data.
  - ✅ Test: zet de optie aan, klik "Scan opnieuw uitvoeren", en
    controleer dat bestanden onder `/components/com_xxx/...` of
    `/templates/yyy/...` van een actieve extensie niet meer in "Niet
    gekoppelde media" staan (wel nog in "Alle media"). Schakel diezelfde
    extensie uit in Joomla, scan opnieuw, en controleer dat de bestanden
    dan wél weer als niet gekoppeld verschijnen.

## 1.20.0

- **Nieuw:** optie op het Opties-scherm (tabblad "Scan"): **"Mappen
  'thumbs' verbergen bij Niet gekoppeld"**. Staat standaard uit.
  - Bestanden in een map die ergens in het pad letterlijk "thumbs" heet
    (op elke diepte, bijv. `/images/icagenda/thumbs/themes`) worden bij
    inschakelen weggelaten uit de lijst "Niet gekoppelde media" én uit
    het bijbehorende aantal op de filterknop. Dit soort mappen bevat
    vaak automatisch gegenereerde thumbnail-caches waarvan de
    bestandsnaam door de eigen extensie (bijv. icagenda) wordt afgeleid
    en dus nooit letterlijk in de database staat — ze kunnen daardoor
    onterecht als "niet gekoppeld" ogen.
  - De bestanden zelf worden niet aangeraakt en blijven gewoon zichtbaar
    onder "Alle media" en "Gekoppelde media"; de optie verandert alleen
    wat er in de "Niet gekoppeld"-weergave zit.
  - De vlag wordt per bestand bepaald tijdens het scannen en opgeslagen
    in een nieuwe, geïndexeerde kolom (`is_thumbs_dir`) — geen
    patroon-zoekopdracht bij elke paginaweergave. Na de upgrade moet je
    dus eenmalig **opnieuw scannen** voordat de optie effect heeft op
    bestaande data.
  - ✅ Test: zet de optie aan, klik "Scan opnieuw uitvoeren", en
    controleer dat bestanden in een `.../thumbs/...`-map niet meer in
    "Niet gekoppelde media" staan (wel nog in "Alle media"). Zet de
    optie weer uit en scan opnieuw: ze staan er weer gewoon bij, mits ze
    ook echt niet gekoppeld zijn.

## 1.19.0

- **Nieuw:** de vier filterknoppen tonen nu een aantal, en "Alle
  bestanden"/"Toon genegeerde items" zijn hernoemd:
  - "Alle bestanden" → **"Alle media (3266)"**
  - "Gekoppelde media" → **"Gekoppelde media (491)"**
  - "Niet gekoppelde media" → **"Niet gekoppelde media (2604)"**
  - "Toon genegeerde items" → **"Toon genegeerde media (X)"**
  - De aantallen komen uit dezelfde filterlogica als de knoppen zelf
    gebruiken, dus ze kunnen nooit uit de pas lopen met wat je te zien
    krijgt na een klik.
  - ✅ Test: elke knop toont een getal tussen haakjes; samen met "Toon
    genegeerde media" tellen "Gekoppeld" + "Niet gekoppeld" op tot het
    aantal bij "Alle media".

## 1.18.0

- **Fix (grote impact verwacht):** uit de nieuwe paginabron bleek dat
  ongeveer **80% van de resterende "niet-gekoppeld"-bestanden** in mappen
  stonden die bij extensies horen: JDownloads' eigen bestandstype-iconen
  (`fileimages/flat_1`, `flat_2`), IC Agenda's thema-iconen
  (`icagenda/thumbs/themes`), Community Builder's standaard
  gallery-afbeeldingen, Kunena's forum-iconen. Dit zijn meegeleverde
  extensie-graphics, gebruikt door de PHP/CSS-code van die extensies
  zelf - niet jouw eigen geüploade content.
  - In v1.14.0 waren `components/` en `modules/` bewust uit de
    broncode-scan gehaald omdat dat toen te traag was (elk bestand werd
    vergeleken met elk van de 3269 bestanden). Sinds de nieuwe,
    index-gebaseerde aanpak (v1.17.0) is die reden vervallen: een
    bestand doorzoeken kost nu ongeveer 0,12 ms, ongeacht hoeveel
    bestanden er worden bijgehouden.
  - `components/` en `modules/` zijn daarom weer toegevoegd aan de
    broncode-scan, met een verhoogd eigen tijdsbudget (van 5 naar 10
    seconden) - er is nu ruimte over dankzij de snelheid van de
    tabellenscan.
  - **Nieuw:** de broncode-scan houdt nu ook statistieken bij (welke
    mappen doorzocht zijn, hoeveel bestanden, of de tijd op is geraakt),
    zichtbaar in `mediacleaner_scan_debug.log` - net als bij de
    tabellenscan.
  - **Eerlijke kanttekening:** de zoekkosten zelf zijn verwaarloosbaar,
    maar hoe snel duizenden bestanden van de schijf gelezen kunnen
    worden hangt af van de server, en dat kan ik hier niet simuleren.
    De tijdslimiet van 10 seconden is het vangnet mocht dat toch
    traag blijken.
  - ✅ Test: `mediacleaner_scan_debug.log` toont een regel "code sweep:
    dirs covered=templates,plugins,components,modules, files scanned=X".
  - ✅ Test: bestanden uit `/images/jdownloads/fileimages/flat_1` en
    vergelijkbare extensie-icoonmappen staan niet meer bij
    "Niet-gekoppelde media".

## 1.17.1

- **Fix (regressie uit 1.17.0):** je stuurde de SQL-dump nogmaals ter
  controle, en daaruit bleek dat `BMW folder R 1200 Cruiser.pdf` - een
  JDownloads-bestand dat we al eerder succesvol hadden getest - nu
  weer volledig "niet-gekoppeld" stond. Oorzaak: om bestandsnamen met
  spaties (zoals "Handleiding 1200 C.pdf") te kunnen herkennen, stond de
  zoekpatroon spaties toe. Maar de losse kolommen van een databaserij
  werden vóór het doorzoeken eerst met een spatie aan elkaar geplakt -
  waardoor de zoekopdracht óók over de grens tussen kolommen heen kon
  lopen. Voorbeeld: de kolommen `pdf.png` + `40.5 MB` +
  `BMW folder R 1200 Cruiser.pdf` werden zo tot één brij samengeplakt
  die niet meer overeenkwam met de schone bestandsnaam.
  - **Oplossing:** elke kolom wordt nu apart doorzocht, in plaats van
    eerst alles samen te voegen tot één tekst. Dit voorkomt dat een
    zoekresultaat kolomgrenzen kan overschrijden, terwijl bestandsnamen
    mét spaties binnen één kolom gewoon blijven werken.
  - Opnieuw getest met exact deze database-rij (uit de dump) vóór
    oplevering, plus een snelheidstest om te bevestigen dat de fix de
    snelheidswinst uit 1.17.0 niet tenietdoet (nog steeds ~0,002 sec
    voor 2588 rijen tegen 3269 bestanden).
  - ✅ Test: JDownloads-PDF's met spaties in de bestandsnaam (zoals
    "BMW folder R 1200 Cruiser.pdf") tonen weer "Waarschijnlijk
    gekoppeld" in plaats van "Niet gekoppeld".

## 1.17.0

- **Nieuw ontwerp voor de scan (jouw voorstel):** in plaats van elk
  bestand apart te vergelijken met elke databaserij, wordt nu elke rij
  **één keer** doorzocht op tekst die op een bestandsnaam met een bekende
  extensie lijkt (jpg, png, pdf, mp4, enz.), en dat resultaat direct
  opgezocht in een woordenboek van je eigen bestanden. Dit is
  extensie-onafhankelijk: het maakt niet uit welke Joomla-extensie de
  koppeling opslaat, zolang de bestandsnaam er maar in voorkomt.
  - **Universeel:** de eerder toegevoegde losse ondersteuning voor IC
    Agenda, JDownloads en BA Gallery is verwijderd - de generieke scan
    herkent ze nu vanzelf, net als elke andere (ook toekomstige)
    extensie, zonder dat daar per extensie code voor nodig is.
  - **Drastisch sneller:** een test met dezelfde situatie die eerder op
    `comprofiler_members` vastliep (2588 rijen tegen 3269 bestanden) ging
    van 16-18 seconden naar 0,003 seconden. De generieke scan zou nu alle
    230+ tabellen op tijd moeten doorlopen, niet meer maar een deel ervan.
  - Onderweg twee bugs gevonden en gerepareerd tijdens het testen:
    bestandsnamen met spaties (zoals "Handleiding 1200 C.pdf") werden
    afgekapt, en JSON-escaped schuine strepen (`\/`, zoals in BA
    Gallery's `settings`-kolom) werden verkeerd genormaliseerd naar
    dubbele slashes.
  - ✅ Test: `mediacleaner_scan_debug.log` toont nu ook een regel
    "result: confirmed=X, probable=Y, unlinked=Z" - het totaalresultaat
    in één oogopslag, zonder dat je apart hoeft te melden wat het
    aantal was.
  - ✅ Test: de generieke tabellenscan komt nu door veel meer (idealiter
    alle) tabellen heen binnen de tijdslimiet.
- **Fix (UI):** de bestandsnaam-kolom kon door lange, spatieloze
  bestandsnamen (zoals `05eigenaarcruiserowners13sept2014_1081_720_100.jpg`)
  de hele tabel te breed maken, waardoor bijvoorbeeld het versienummer
  rechtsonder buiten beeld viel. De kolom breekt nu na een vaste
  breedte af naar een nieuwe regel, in zowel het beheer- als het
  frontend-overzicht.
  - ✅ Test: een lange bestandsnaam breekt netjes af binnen de kolom, de
    rest van de pagina (inclusief het versienummer) blijft op zijn
    plek.

## 1.16.1

- **Fix:** BA Gallery-link in "Gekoppelde media" verwees naar een geraden,
  niet-bestaand component (`com_bagallery`). Uit de paginabron van
  bmwcruiser.nl bleek de echte naam: **`com_gallery`**.
- **Uitbreiding:** IC Agenda-scan doorzoekt nu ook de kolommen `params`
  en `version_customfields` van `icagenda_events`, naast `image`/`file`/
  `shortdesc`/`desc`. Bijlagen bij een evenement (bijv. bestanden onder
  `/images/icagenda/frontend/attachments/`) blijken daar te zitten, niet
  in de kolommen die we al doorzochten.
- **Onderzoek naar de resterende "niet-gekoppeld"-bestanden**, op basis
  van een paginabron met de daadwerkelijke lijst:
  - Een deel van de BA Gallery-bestanden in `/images/bagallery/original/`
    lijkt **echt wees te zijn geworden**: BA Gallery kopieert een
    afbeelding naar die map zodra die aan een gallery wordt toegevoegd,
    maar ruimt die kopie kennelijk niet op als het gallery-item later
    wordt verwijderd. Dit is dus vermoedelijk terecht "niet-gekoppeld".
  - Bestanden in `/images/overons/vsig_images/` en `/vsig_thumbs/` lijken
    automatisch gegenereerde thumbnail-varianten van een galerij-plugin
    te zijn. Zulke bestandsnamen worden nergens letterlijk opgeslagen -
    ze worden door de plugin zelf on-the-fly bedacht op basis van de
    originele bestandsnaam. Dat is met tekst-zoeken principieel niet te
    detecteren; dit is een inherente grens van deze aanpak, geen bug.
    Eén concreet voorbeeld bleek alleen nog voor te komen in een oude,
    inmiddels overschreven versie van een artikel (niet de huidige
    inhoud), wat erop wijst dat de koppeling destijds echt is verwijderd.

## 1.16.0

- **Fix (JDownloads "Waarschijnlijk gekoppeld"):** een JDownloads-bestand
  krijgt nu "Gekoppeld" (groen) in plaats van "Waarschijnlijk gekoppeld"
  (oranje) wanneer het bestand zowel voorkomt in de JDownloads-database
  ALS al in een map met "jdownload" in het pad staat - twee onafhankelijke
  aanwijzingen die elkaar bevestigen, in plaats van alleen een
  naamsovereenkomst. Bestanden die alleen op naam matchen (zonder die
  mapaanwijzing) blijven terecht "Waarschijnlijk gekoppeld".
  - ✅ Test: JDownloads-PDF's in de map `/jdownloads/...` tonen nu
    "Gekoppeld".
- **Nieuw: BA Gallery (Balbooa) ondersteuning.** Deze extensie slaat het
  volledige pad op (kolom `path` in `gallery_items`) en is - net als IC
  Agenda/JDownloads - toegevoegd aan de vaste, snelle kern-scan in plaats
  van aan de generieke scan, om hetzelfde "komt nooit aan de beurt"-
  probleem te voorkomen.
  - Let op: de link naar het bewerkscherm in "Gekoppelde media" is voor
    BA Gallery bewust weggelaten (i.t.t. IC Agenda/JDownloads) - het
    exacte Joomla-componentnaam van deze extensie kon ik niet met
    zekerheid vaststellen, en een gegokte, mogelijk kapotte link is
    minder nuttig dan gewoon geen link.
  - ✅ Test: bestanden uit BA Gallery (te herkennen aan `/images/...`-
    paden die in een gallery worden gebruikt) staan niet meer bij
    "Niet-gekoppelde media".

## 1.15.0

- **Fix (definitieve oplossing voor IC Agenda/JDownloads):** het
  diagnosebestand liet zien dat de generieke tabellenscan, ondanks de
  vorige verbetering, met 238 tabellen (waarvan sommige Community
  Builder-tabellen met veel rijen) maar tot 59 tabellen kwam binnen 20
  seconden - alfabetisch blijven steken bij de "c", ver vóór "icagenda"
  en "jdownloads". In plaats van te blijven sleutelen aan tijdslimieten
  voor een scan die toevallig alles op tijd moet doorlopen, zijn IC
  Agenda en JDownloads nu direct toegevoegd aan de vaste, snelle
  kern-scan (dezelfde fase als artikelen/modules/menu's, ~3,4 sec, komt
  altijd volledig af) - net als eerder al gebeurde voor hun weergave in
  "Gekoppelde media".
  - JDownloads slaat alleen de kale bestandsnaam op (geen pad), dus die
    bestanden krijgen het label "Waarschijnlijk gekoppeld"; IC Agenda
    slaat het volledige pad op en krijgt gewoon "Gekoppeld".
  - De generieke tabellenscan blijft daarnaast actief als vangnet voor
    andere/toekomstige extensies.
  - ✅ Test: na een herscan staan de bestanden uit
    `/images/icagenda/files/` en `/jdownloads/...` niet meer bij
    "Niet-gekoppelde media", ook al haalt de generieke scan zelf lang
    niet alle 238 tabellen.
- Kleine aanvulling: Community Builder's eigen privéberichten-tabel
  (`comprofiler_plugin_messages`) wordt nu ook uitgesloten van de
  generieke scan, net als forum-privéberichten - geeft de generieke
  scan iets meer ademruimte voor andere, nog onbekende extensies.

## 1.14.0

- **Fix (nu met harde cijfers bevestigd):** het diagnosebestand van
  bmwcruiser.nl liet zien dat de bestandscode-scan (templates/plugins/
  modules/componenten) **17,2 van de 20 seconden** opsoupeerde, waardoor
  de tabellenscan (die IC Agenda/JDownloads had moeten vinden) maar 2 ms
  overhield - hij begon feitelijk nooit.
  - **Volgorde omgedraaid:** de tabellenscan (het belangrijkste onderdeel
    voor dit probleem) loopt nu vóór de bestandscode-scan, niet erna.
  - **Eigen tijdslimiet voor de bestandscode-scan:** losgekoppeld van het
    totale budget, nu maximaal 5 seconden op zichzelf, zodat hij nooit
    meer de hele tabellenscan kan wegdrukken.
  - **`components/` en `modules/` niet meer meegenomen** in de
    bestandscode-scan: dat is grotendeels Joomla-kernbestanden en
    extensiecode die zelden een hardgecodeerd pad naar jouw eigen
    geüploade bestanden bevat, maar wél het grootste deel van de kosten
    veroorzaakte. Alleen `templates/` en `plugins/` blijven over - de
    plekken waar een hardgecodeerd mediapad realistisch gezien voorkomt.
  - ✅ Test: `mediacleaner_scan_debug.log` toont na een herscan dat de
    "generic table phase" nu een substantiële tijd krijgt (niet meer
    2 ms), en "tables_scanned" is groter dan 0.
  - ✅ Test: het aantal "niet-gekoppeld" bestanden daalt nu wél
    merkbaar, en de IC Agenda/JDownloads-bestanden uit de screenshots
    staan niet langer bij "Niet-gekoppelde media".

## 1.13.1

- **Waarschijnlijke fix + diagnose:** na 1.13.0 daalde het aantal
  "niet-gekoppeld" bestanden nauwelijks (3242 → 3240) - veel te weinig
  gezien hoeveel IC Agenda/JDownloads-bestanden er zijn. Vermoedelijke
  oorzaak: de 20-secondenlimiet gold voor de hele generieke scan in zijn
  geheel, dus een paar grote tabellen vroeg in het alfabet konden het
  hele tijdsbudget opsouperen voordat tabellen als `icagenda_events` en
  `jdownloads_files` (die verderop in het alfabet staan) ooit werden
  bereikt.
  - Elke tabel krijgt nu een eerlijk aandeel van de **resterende** tijd
    in plaats van dat de eerste tabellen alle ruimte kunnen opeisen.
  - **Nieuw diagnosebestand** (blijft voortaan gewoon aanwezig, geen
    aparte "diagnostische versie" meer nodig): na elke scan verschijnt in
    `administrator/logs/mediacleaner_scan_debug.log` een regel met hoeveel
    tabellen zijn bekeken, overgeslagen, en daadwerkelijk doorzocht, hoe
    lang elke fase duurde, en - als de tijd op was - welke tabellen als
    laatste zijn bereikt.
  - ✅ Test: na een herscan bevat `mediacleaner_scan_debug.log` een
    nieuwe regel. Als `icagenda`/`jdownloads` niet voorkomen bij "last
    tables reached" of "sample of excluded tables", weten we gelijk
    waar het nog misgaat, zonder verder te hoeven gokken.

## 1.13.0

- **Fix (de reden dat IC Agenda/JDownloads nog niet herkend werden):**
  de generieke tabellenscan gebruikte `information_schema.COLUMNS` om
  kolommen te ontdekken. Op sommige hostingomgevingen is de toegang
  daartoe beperkt; als die query faalde, stopte de hele generieke scan
  **stilzwijgend** - zonder foutmelding, gewoon met exact hetzelfde
  resultaat als vóór deze functie bestond (en dat is precies wat er op
  bmwcruiser.nl gebeurde: 3242 "niet-gekoppeld" bleef ongewijzigd).
  - Vervangen door `SHOW TABLES` / `SHOW COLUMNS`, die op elke
    MySQL/MariaDB-omgeving altijd beschikbaar zijn.
  - Het uitsluiten van tabellen (sessies, logs, caches, dit component
    zelf) gebeurt nu in PHP met simpele `*`-jokertekens in plaats van
    SQL LIKE-patronen - ook het instelveld "Extra tabellen overslaan"
    gebruikt nu `*` in plaats van `%`.
  - ✅ Test: na een herscan op bmwcruiser.nl dalen de "niet-gekoppeld"-
    aantallen merkbaar, en staan `bmw-minimeeting-...mp4` (IC Agenda) en
    de JDownloads-PDF's niet langer bij "Niet-gekoppelde media".
- **Wijziging:** het statusbalkje tijdens een scan staat niet meer
  vastgeplakt bovenaan het hele browserscherm, maar gewoon in de
  paginainhoud, direct onder de werkbalk (boven "Media Cleaner").
  - ✅ Test: de balk verschijnt op de plek waar voorheen "Media Cleaner"
    direct begon, niet meer los boven de Joomla-header.

## 1.12.1

- **Fix (installatiefout):** v1.12.0 kon niet installeren
  ("Duplicate key name 'idx_name'") omdat de nieuwe index in die versie
  dezelfde naam had als een index die al sinds v1.4.8 bestaat op elke
  bestaande installatie. Hernoemd naar `idx_files_name_lookup`.
  - ✅ Test: installatie rondt af zonder foutmelding.
- **Fix (verborgen, nog niet opgetreden):** bij het controleren hierop
  bleken drie andere updatebestanden (1.5.2.sql, bedoeld als inhaalslag
  voor sites die ooit een kapotte release hadden) dezelfde indexnamen te
  hergebruiken als 1.4.8/1.4.9/1.4.10.sql. Dit was nog nooit misgegaan
  omdat schalkhaar.com en bmwcruiser.nl deze bestanden altijd stap voor
  stap doorlopen hebben, maar zou wél vastlopen voor een site die vanaf
  een oude versie in één keer een grote sprong maakt. Hernoemd naar
  `idx_name_repair`, `idx_path_repair`, `idx_linked_ignored_repair`.
- Bevat verder dezelfde inhoud als 1.12.0 (statusbalkje tijdens scan).

## 1.12.0

- **Nieuw:** een statusbalkje bovenin het scherm dat vult terwijl een scan
  loopt, met een tekst die aangeeft dat er gescand wordt. Het is een
  inschatting op basis van tijd (de scan zelf meldt geen echte
  voortgangspercentages terug), dus het balkje nadert bewust nooit
  helemaal 100% - het verdwijnt vanzelf zodra de pagina na afloop
  herlaadt.
  - ✅ Test: klik op "Scan opnieuw uitvoeren" - bovenin verschijnt direct
    een blauwe balk die geleidelijk vult, met de tekst "Bezig met
    scannen…". De knop is tijdens het scannen niet opnieuw te klikken.

## 1.11.0

- **Fix (de daadwerkelijke oorzaak):** de crash-log van bmwcruiser.nl
  wees de echte oorzaak aan: `Allowed memory size of 134217728 bytes
  exhausted` (PHP-geheugenlimiet van 128 MB) tijdens `implode()` aan het
  einde van de bestandsscan. De vorige twee fixes (1.10.1, 1.10.2)
  probeerden de hoeveelheid verzamelde tekst te *begrenzen*, maar het
  echte probleem was dat de opzet zelf - alles verzamelen in één grote
  tekst-blob, en die pas ná het verzamelen doorzoeken - meerdere
  volledige kopieën van diezelfde grote hoeveelheid tekst gelijktijdig in
  het geheugen zette (de losse stukken tekst, plus de samengevoegde
  string, plus de daaropvolgende samenvoeging in de hoofdfunctie). Geen
  bytelimiet-getal kon dat gezond houden op een server met maar 128 MB
  beschikbaar.
  - **De echte oplossing:** elk stukje tekst (één databaserij, één
    bestand) wordt nu direct na het lezen tegen de bestandenlijst
    gecontroleerd en meteen daarna weggegooid. Er wordt nergens meer een
    grote tekst-blob opgebouwd - het geheugengebruik blijft daardoor
    altijd ongeveer even groot, ongeacht hoeveel er in totaal wordt
    doorzocht.
  - ✅ Test: "Scan opnieuw uitvoeren" op bmwcruiser.nl voltooit zonder
    500-fout.
  - ✅ Test: `administrator/logs/mediacleaner_crash.log` krijgt geen
    nieuwe regel na een scan.
  - Let op: de tijdslimiet (20 sec.) kan er nu voor zorgen dat de
    generieke tabellenscan op een drukke site niet élke tabel volledig
    afrondt binnen die tijd - dat is bewust: dan stopt hij netjes met wat
    hij tot dan toe heeft gevonden, in plaats van door te blijven gaan
    (wat weer tot een crash zou kunnen leiden) of te crashen.

## 1.10.2 (diagnostisch)

- **Geen functionele wijziging aan de scan zelf.** De vorige fix
  (1.10.1) loste de 500-fout op bmwcruiser.nl niet op, en zonder de
  echte foutmelding te zien was verder gokken niet verantwoord.
- **Nieuw:** een tijdelijke crash-logger. Een fatale PHP-fout
  (geheugenlimiet/tijdslimiet) kan niet met `try/catch` worden
  opgevangen - maar met een zogeheten "shutdown function" (die altijd
  draait, ook ná een fatale crash) wordt de exacte foutmelding nu
  weggeschreven naar een gewoon tekstbestand:
  `administrator/logs/mediacleaner_crash.log`
  - ✅ Test: na een crashende "Scan opnieuw uitvoeren" staat er een
    nieuwe regel in dat bestand, met tijdstip, foutmelding en het
    bestand + regelnummer waar het misging. Dat bestand is te lezen via
    gewone FTP/bestandsbeheerder - geen hostingpaneel-toegang nodig.
  - Blijft veilig staan ook als hij niet meer nodig is: doet niets tenzij
    er echt een fatale fout optreedt.

## 1.10.1

- **Fix (kritiek):** de generieke tabellenscan uit 1.10.0 kon een 500-fout
  veroorzaken op sites met grote losse-tekst-tabellen (bijv. Kunena-
  forumberichten, Community Builder-profielvelden), omdat elke tabel in
  één keer volledig werd ingelezen zonder limiet. Ook kon een onleesbare
  map onder `templates/`/`plugins/`/`modules/`/`components/` de hele scan
  laten crashen zonder dat dat werd opgevangen.
  - Tabellen worden nu in blokken van 500 rijen gelezen in plaats van in
    één keer, met een tijdslimiet (20 seconden) én een geheugenlimiet
    naast elkaar.
  - Standaard uitgesloten van de scan: forumberichttekst, privéberichten,
    zoekindex-tabellen, en een aantal Joomla-kerntabellen met grote
    technische inhoud (`#__extensions`, `#__schemas`, `#__assets`, e.d.).
  - Een onleesbare map wordt nu overgeslagen in plaats van de hele scan
    te laten vastlopen.
  - **Belangrijke kanttekening:** dit dekt de meest voorkomende oorzaken
    af, maar een harde PHP-geheugen- of tijdslimiet van de server zelf
    kan in uitzonderlijke gevallen nog steeds niet "afgevangen" worden
    (dat is een PHP-beperking, geen bug die we kunnen wegprogrammeren) -
    vandaar de limieten die de scan zelf ruim vóór zo'n harde grens laat
    stoppen.
  - ✅ Test: "Scan opnieuw uitvoeren" op de bmwcruiser-site voltooit
    zonder 500-fout.

## 1.10.0

- **Nieuw:** de "gekoppeld"-detectie doorzoekt nu, naast Joomla's eigen
  kerntabellen, ook automatisch **elke andere databasetabel** op de site
  (via `information_schema`) en de **broncode** van templates, plugins,
  modules en componenten. Extensies van derden (bijv. IC Agenda,
  JDownloads, en toekomstige extensies) worden hierdoor herkend zonder dat
  daar per extensie code voor geschreven hoeft te worden.
  - ✅ Test: draai "Scan opnieuw uitvoeren" op een site met IC Agenda
    en/of JDownloads. Bestanden die alleen door die extensies gebruikt
    worden, staan niet langer bij "Niet-gekoppelde media".
  - ✅ Test: een bestand dat écht nergens meer gebruikt wordt, staat nog
    steeds gewoon bij "Niet-gekoppelde media" (de scan is grondiger, niet
    losser).
- **Nieuw:** onderscheid tussen **"Gekoppeld"** (groen — het volledige
  bestandspad is gevonden) en **"Waarschijnlijk gekoppeld"** (oranje —
  alleen de bestandsnaam is gevonden, zoals bij JDownloads dat geen map
  opslaat). Bestanden met dit label kunnen, net als "Gekoppeld", niet
  worden genegeerd of verwijderd via de actieknoppen.
  - ✅ Test: op een site met JDownloads-bestanden zie je het oranje label
    "Waarschijnlijk gekoppeld" bij de betreffende bestanden, met een
    tooltip die uitlegt waarom.
- **Nieuw:** optie "Extra tabellen overslaan" (Opties → tabblad "Scan")
  om, per site, extra databasetabellen van de automatische scan uit te
  sluiten (bijv. voor een extensie die veel valse "gekoppeld"-meldingen
  veroorzaakt). Standaard al uitgesloten: sessies, logs, caches en
  back-uptabellen (zoals de `_backup_*`-tabellen die JDownloads na een
  update achterlaat).
  - ✅ Test: Opties → tabblad "Scan" toont een leeg tekstveld
    "Extra tabellen overslaan".
- **Let op (prestatie):** deze scan doorzoekt nu veel meer data dan
  voorheen, dus "Scan opnieuw uitvoeren" kan merkbaar langer duren op een
  site met veel extensies/tabellen (bewuste keuze — grondigheid boven
  snelheid).

## 1.9.1

- **Fix:** de "Help"- en "Opties"-knoppen in de werkbalk van het
  Bestanden-overzicht konden op sommige sites (met een bredere/drukkere
  werkbalk, bijv. door andere extensies) volledig buiten beeld terecht
  komen door een CSS-truc die ze naar de rechterrand duwde. Die truc is
  verwijderd; beide knoppen staan nu gewoon op hun normale plek in de
  werkbalk.
  - ✅ Test: open Componenten → Media Cleaner. "Help" staat na "Tijdelijk
    verwijderd". "Opties" staat - net als bij andere Joomla-componenten -
    aan de rechterkant van de werkbalk (dit is Joomla's eigen standaard-
    gedrag voor de Opties-knop, niet iets wat wij afdwingen).
  - ✅ Test: als je bent ingelogd als een gebruiker zonder het recht
    "Manage" (`core.admin`) op dit component, is "Opties" niet zichtbaar.

## 1.9.0

- **Nieuw:** het component kan nu ook aan de voorkant (website) getoond
  worden via een menu-item.
  - ✅ Test: maak een nieuw menu-item aan met als type "Media Cleaner".
  - ✅ Test: zonder rechten toegekend, geeft de pagina "Je hebt geen
    toegang tot deze pagina" (ingelogde gebruiker) of stuurt door naar de
    inlogpagina (bezoeker die niet is ingelogd).
- **Nieuw:** drie rechten toegevoegd op het tabblad "Machtigingen" van de
  Opties: "Website: Overzicht bekijken", "Website: Bestanden negeren",
  "Website: Bestanden verwijderen". Standaard staat niets hiervan aan.
  - ✅ Test: ken een gebruikersgroep het recht "Website: Overzicht
    bekijken" toe; login als een gebruiker uit die groep en controleer of
    de pagina nu wél toont.
  - ✅ Test: ook mét die rechten toegekend, kan een bezoeker die niet is
    ingelogd nooit bestanden negeren of verwijderen - dit is een harde
    grens, los van de rechteninstelling.

## 1.8.2

- **Fix:** kloof tussen "Help" en "Opties" in de werkbalk, veroorzaakt
  doordat beide knoppen elk hun eigen "duw mij naar rechts"-marge hadden,
  waardoor de browser de vrije ruimte over allebei verdeelde in plaats van
  ze naast elkaar te zetten.

## 1.8.1

- **Fix (onvolledig, zie 1.8.2):** eerste poging om "Opties" direct naast
  "Help" te positioneren.

## 1.8.0

- **Nieuw:** "Opties"-knop toegevoegd aan de werkbalk van het
  Bestanden-overzicht, die het standaard Joomla-configuratiescherm opent
  (rechtenbeheer per gebruikersgroep).
  - ✅ Test: knop is zichtbaar voor gebruikers met het recht "Manage" op
    dit component, en onzichtbaar voor gebruikers zonder dat recht.

## Eerdere versies (1.4.x t/m 1.7.9)

Zie de individuele bestanden in `admin/sql/updates/mysql/` voor de
structurele wijzigingen per versie. Belangrijkste mijlpalen:
schema/versie-mismatch-fix, GitHub-releaseworkflow, dark mode, Help-pagina,
SVG-thumbnail-detectie.
