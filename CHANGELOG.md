# Changelog

## v0.7 — Solarstatistik (2026-09-20)

### Neu
- **Solarstatistik** im Solar-Tab, gleiche Darstellung wie der Pelletverbrauch: Summenzeile
  (heute / Woche / Monat / Jahr) und Balken für täglich, wöchentlich, monatlich, jährlich, dazu die
  Spitzentemperatur je Tag als Linie.
- Kennzahl sind **Sonnenstunden**: Zeit, in der der Kollektor über der Starttemperatur lag
  (Standard 40 °C, konfigurierbar als `solar_stats.threshold`). Rückwirkend über den gesamten Log
  verfügbar — Juni 280 h, Juli 329 h, August 281 h.
- **Kollektorpumpe** (in %) und **Speicher 1 unten** werden ab sofort mitgeloggt, damit die Statistik
  später auf die tatsächliche Pumpenlaufzeit umgestellt werden kann.
- Neuer Konfigurationsabschnitt `solar_stats`.

### Geändert
- Die Karte **Vorrat nachtragen** sitzt jetzt unter den Detail-Kacheln am Seitenende statt direkt unter
  der Hero-Anzeige — auf dem Handy standen Vorrat und Kacheln sonst zu weit auseinander.
- Der Erklärtext dazu ist eingeklappt („Was ist das?") und stört die Ansicht nicht mehr.

### Hinweis
- Ein Solarertrag in kWh ist nicht möglich: der Kessel hat keinen Ertragszähler (im Menübaum geprüft).

---

## v0.6 — Verbrauch aus dem Zähler, eigene Vorratsbilanz (2026-09-20)

### Behoben
- **Verbrauchsberechnung lag systematisch falsch.** Sie nutzte den Rückgang des Lagerwerts. Der Kessel
  verbrennt aber aus dem 30-kg-Vorratsbehälter, der schubweise nachgesaugt wird — gemessen wurde der
  Saugzeitpunkt, nicht das Verbrennen. Belege aus den Logdaten (Abgleich gegen den Zähler):
  18.09. wurden 4 kg verbrannt, angezeigt wurden 0 kg; am 19.09. 3 kg verbrannt, angezeigt 7 kg.
  An Liefertagen (07.03., 26.03., 13.04.) fiel der Tagesverbrauch komplett auf 0, und als die
  Lagerbuchhaltung am 04.03. auf 0 lief, wurden aus 20 kg angezeigte 46 kg.
- Verbrauch wird jetzt aus dem Zähler `Gesamtverbrauch` (`/40/10021/0/0/12016`) berechnet — die
  tatsächlich verbrannten kg. Rückwärtssprünge (Ausreißer, Zählerwechsel) werden verworfen.

### Neu
- **Eigene Vorratsbilanz** statt des Lagerwerts des Kessels: letzte eingetragene Füllmenge + nachgetragene
  Säcke − verbrannte kg, aufgeteilt in Lager und Behälter. Der Wert des Kessels wird zum Vergleich
  daneben angezeigt, inklusive Abweichung.
- **Vorrat nachtragen** auf dem Dashboard: Button für einen Sack (Gebindegröße über `sack_kg`, Standard
  15 kg) und Eingabefeld für eine Lieferung. Einträge landen in `pellet_events.txt` und lassen sich
  einzeln wieder entfernen.
- Neue Konfigurationsschlüssel `counter`, `hopper` und `sack_kg`, editierbar unter Einstellungen.

### Geändert
- Zähler und Vorratsbehälter werden immer geloggt (Dashboard und Cronjob), auch ohne eigene Kachel —
  sie sind die Basis der Rechnung.
- Bestandsverlauf zeigt den berechneten Gesamtvorrat (Lager + Behälter) statt des Kessel-Lagerwerts.
- Eintragen von Ereignissen läuft über POST mit Redirect, damit ein Reload nichts verdoppelt.

---

## v0.5 — Solar-Tab (2026-05-27)

### Neu
- **Solar-Tab** mit Live-Anzeige von Kollektor-, Puffer- und Außentemperatur
- **Linien-Chart** mit umschaltbarem Zeitraum: 24 Stunden / 48 Stunden / 7 Tage
- 4 Solar-Variablen werden stündlich geloggt: Kollektor, Außentemperatur, Puffer oben, Puffer unten
- `config.json` um einen `solar`-Abschnitt erweitert (editierbar unter Einstellungen)
- Cronjob auf stündlichen Abruf umgestellt (war: 3x täglich)

### Geändert
- `fetchall` loggt jetzt auch Solar-Variablen, Duplikate werden übersprungen
- Cronjob liest Solar-Variablen aus `config.json` und dedupliziert per URI
- Einstellungsseite zeigt separaten Bereich für Solar-Variablen

---

## v0.41 — Kleine Korrekturen

### Neu
- **Bestandsverlauf** als Linienchart im Verbrauchsbereich (Lager Vorrat über die Tage)
- **Kessel IP/Port** über Einstellungen konfigurierbar — kein Editieren der PHP-Datei mehr nötig
- `config.example.json` und `pellet_verbrauch.example.txt` als Referenz beigefügt
- MIT-Lizenz hinzugefügt

### Geändert
- Verbrauchsberechnung nutzt jetzt `strValue` statt `rawValue` (korrektes Ergebnis in kg)
- Cronjob liest IP/Port aus `config.json`

---

## v0.4 — Konfigurierbares Dashboard (2026-02-10)

### Neu
- Konfigurierbares Dashboard: Kacheln und Hero-Variable über Web-UI änderbar
- Konfiguration wird in `config.json` gespeichert
- Kacheln aus dem Menubaum per Klick hinzufügen (+) oder als Hero setzen (★)
- Kacheln auf dem Dashboard per × entfernen
- Einstellungsseite zum direkten Bearbeiten aller URI-Pfade und Namen
- Reset auf Standardkonfiguration
- Cronjob (`eta_log_cron.sh`) liest Hero und Tiles dynamisch aus `config.json`

---

## v0.1 — Initial Release

### Neu
- Live-Dashboard mit Hero-Anzeige (Lager Vorrat) und Detail-Kacheln
- Verbrauchsstatistik mit Chart.js (täglich / wöchentlich / monatlich / jährlich)
- Menubaum-Browser zum Durchsuchen aller Kessel-Variablen
- Log-Ansicht (letzte 200 Einträge)
- Logging in Tab-separierte Textdatei (`pellet_verbrauch.txt`)
- Manuelles Abrufen einzelner oder aller Variablen
- Cronjob-Script für automatische Datenerfassung
- Responsive Design (Mobile / Tablet / Desktop)
