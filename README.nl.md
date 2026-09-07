# Media Cleaner

Een Joomla 6-beheercomponent die ongebruikte mediabestanden op je site
opspoort, precies laat zien waar de bestanden die nog wél in gebruik zijn
worden aangeroepen, en je veilige, herstelbare manieren geeft om de rest
op te ruimen.

Maker: **Wouter** ([WoodyF4u](https://github.com/WoodyF4u)) · [CompactWeb](https://compactweb.nl)
Broncode & releases: **https://github.com/WoodyF4u/Media-Cleaner**
Vragen of problemen: open gerust een [GitHub-issue](https://github.com/WoodyF4u/Media-Cleaner/issues) op bovenstaande repository.

*[This README is also available in English: README.md](README.md)*

---

## Wat het doet

Media Cleaner doorzoekt het bestandssysteem en de database van je site,
en laat vervolgens voor elke afbeelding, elk document, elke video en elk
ander mediabestand zien:

- of het **gekoppeld** is (nog ergens naartoe verwezen) of
  **niet-gekoppeld** (niets verwijst er meer naartoe),
- precies **waar** het wordt gebruikt, als het gekoppeld is, met een
  directe link naar de beheerpagina,
- en biedt veilige, herstelbare manieren om af te rekenen met de rest.

Er wordt nooit iets definitief verwijderd zonder een expliciete, aparte
bevestigingsstap.

## Hoe de koppelingscontrole werkt

Bij elke scan wordt elk gevonden bestand gecontroleerd tegen:

1. Joomla's eigen content — artikelen, modules, categorieën,
   menu-items, aangepaste velden, contactpersonen en banners.
2. **Alle overige databasetabellen op de site**, inclusief algemene
   tekst-/varchar-kolommen — waardoor ook extensies van derden
   (agenda's, downloadbeheer, galerijen, enzovoort) automatisch worden
   herkend, zonder dat Media Cleaner hun tabelnamen vooraf hoeft te
   kennen.
3. De **broncode** (`.php`, `.css`, `.js`, `.xml`) van alles onder
   `templates/`, `plugins/`, `components/` en `modules/` — zo worden
   ook bestanden gevonden die worden aangeroepen vanuit de eigen
   lay-out van een template, of de meegeleverde bestanden van een
   plugin of component.

Een bestand wordt gemarkeerd als:

- **Gekoppeld** (groen) — het volledige pad is ergens teruggevonden.
- **Waarschijnlijk gekoppeld** (oranje) — alleen de exacte bestandsnaam
  is teruggevonden (sommige extensies bewaren alleen een kale
  bestandsnaam, niet de map). Vrijwel altijd een echte koppeling, maar
  iets minder zeker dan een volledige-padmatch — een korte handmatige
  controle voor het verwijderen is aan te raden.
- **Niet-gekoppeld** — er is niets gevonden. Ook dan is dit geen absolute
  garantie dat verwijderen veilig is (zie de beperkingen hieronder), maar
  wel een sterke aanwijzing.

### Beperkingen

Media Cleaner kan een bestand niet herkennen als het uitsluitend buiten
bovenstaande gebieden wordt aangeroepen — bijvoorbeeld vanuit een
handmatig bewerkt serverconfiguratiebestand, of code buiten de vier
gescande extensiemappen. Kijk daarom altijd even goed voordat je een als
"Niet-gekoppeld" gemarkeerd bestand verwijdert, zeker als het groot is of
belangrijk lijkt.

## Fout-positieven verminderen: het Opties-scherm

Sommige bestanden die volgens bovenstaande controle technisch gezien
"niet-gekoppeld" zijn, horen toch gewoon bij de site — ze horen bij een
extensie die ze simpelweg nog niet heeft aangeroepen, of het zijn
gegenereerde caches. Het Opties-scherm (**Opties → tabblad
Instellingen**) heeft drie schakelaars, die elk onafhankelijk bepalen of
die categorie wordt verborgen in de lijst "Niet-gekoppelde media" en de
bijbehorende telling (de bestanden zelf worden door deze schakelaars
**nooit** aangeraakt — ze blijven altijd zichtbaar onder "Alle media" en
"Gekoppelde media", en verschijnen direct weer als niet-gekoppeld zodra
je de schakelaar terugzet):

| Schakelaar | Verbergt uit Niet-gekoppeld... |
|---|---|
| 'thumbs'-mappen in de map 'images' | Bestanden in elke map die letterlijk `thumbs` heet (bijv. `/images/icagenda/thumbs/...`) - meestal automatisch gegenereerde thumbnail-caches waarvan de bestandsnaam door de eigen extensie wordt afgeleid, waardoor ze nooit letterlijk als match in de database voorkomen. |
| Bestanden van actieve componenten/modules/plugins/templates | Bestanden onder `/components/<element>/...`, `/modules/<element>/...`, `/plugins/<groep>/<element>/...` of `/templates/<element>/...`, maar **alleen** zolang die extensie nog geïnstalleerd en actief is. |
| Media-bestanden van actieve extensies | Bestanden onder `/images/<element>/...` (herkend op zowel het volledige element, bijv. `com_jdownloads`, als de kale naam, bijv. `icagenda`) - diverse extensies maken hun eigen upload-/thumbnailmap direct onder `/images/` aan. Ook hier alleen zolang dat component nog actief is. |

Alle drie staan standaard op **Ja**. Na het opslaan van Opties wordt
automatisch een nieuwe scan op de achtergrond gestart (met een lopend
voortgangsbalkje), zodat wijzigingen direct effect hebben zonder een
extra handeling.

Voor bestanden die na dit alles nog steeds echt niet-gekoppeld zijn, en
in een `components/`, `modules/`, `plugins/` of `templates/`-map zitten
die **niet** bij een momenteel actieve extensie hoort, toont het
overzicht een kleine opmerking onder de locatie: *"Hoort mogelijk bij een
niet meer geïnstalleerde Joomla-extensie"* - een sterke aanwijzing dat
het gaat om achtergebleven data van iets dat later is uitgeschakeld of
verwijderd.

## Het hoofdoverzicht

**Filterbalk:** Alle media / Gekoppelde media / Niet-gekoppelde media,
plus nog twee: Toon genegeerde media, en Toon verwijderde media (opent
het quarantaine-overzicht - zie hieronder).

**Kolommen:** Voorbeeldthumbnail, Bestandsnaam, Bestandsgrootte, Locatie,
Type, Gekoppeld-status, en een Actie-kolom met onderstaande knoppen. Bij
gekoppelde bestanden klapt de bestandsnaam-rij ook uit met elke plek waar
het bestand wordt gebruikt, elk met een directe link naar de betreffende
beheerpagina.

**Acties per bestand / in bulk** (in bulk via de keuzelijst "Actie" boven
de tabel, voor één of meer geselecteerde niet-gekoppelde bestanden):

- **Negeren** — markeert een bestand als bewust behouden, zodat het niet
  langer als aandachtspunt wordt getoond. Er wordt niets verplaatst.
  **Herstel genegeerd** maakt dit ongedaan.
- **Verwijderen (tijdelijk)** — verplaatst het bestand naar een
  beveiligde quarantainemap, niet bereikbaar vanaf de live website, maar
  nog niet definitief weg.
- **Comprimeer naar WebP** (alleen gekoppelde bestanden) — maakt een
  nieuwe, gecomprimeerde WebP-kopie naast de originele afbeelding; er
  wordt niets overschreven. De verwijzing moet je zelf nog handmatig
  aanpassen naar het nieuwe bestand.

**Quarantaine** ("Toon verwijderde media"): zet een bestand terug naar de
oorspronkelijke locatie, of verwijder het definitief - een echte,
onomkeerbare verwijdering, bewust als aparte, tweede stap gehouden ten
opzichte van de tijdelijke verwijdering hierboven.

## Vereisten

- Joomla 6.x
- MySQL of MariaDB (gebruikt `information_schema` voor een paar interne
  zelfcontroles)

## Installatie & updates

Installeren gaat zoals bij elke Joomla-extensie: **Systeem → Installeren
→ Extensies**, upload de zip van de
[releasepagina](https://github.com/WoodyF4u/Media-Cleaner/releases).
Media Cleaner registreert zijn eigen updateserver, dus nieuwe versies
verschijnen ook vanzelf onder **Systeem → Bijwerken → Extensies**.

Bezoek na het installeren of bijwerken één keer de hoofdpagina van Media
Cleaner en voer een scan uit — een aantal functies steunt op gegevens die
tijdens het scannen worden berekend, dus een verse scan na een update
zorgt dat het overzicht de nieuwste logica weerspiegelt.

## Rechten

Het tabblad **Rechten** op het Opties-scherm bepaalt wie dit component
mag beheren, via Joomla's standaard rechtensysteem.

## Ondersteuning

Dit is een onafhankelijk onderhouden, gratis en open-source project.
Voor bugs, functieverzoeken of vragen: gebruik
[GitHub Issues](https://github.com/WoodyF4u/Media-Cleaner/issues) op de
repository bovenaan dit bestand.

## Changelog

Zie [CHANGELOG.md](CHANGELOG.md) voor de volledige, versie-voor-versie
geschiedenis.

## Licentie

GNU General Public License versie 2 of later. Zie [LICENSE.txt](LICENSE.txt).
