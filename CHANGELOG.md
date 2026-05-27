# Changelog

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
