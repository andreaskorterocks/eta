# Changelog

## v0.82 — Behälter-Status im Log (2026-09-20)

### Neu
- Die Statusvariable `Pelletsbehälter` (`/40/10021/0/0/12005`, „Voll" / „Nicht voll") wird stündlich
  mitgeloggt (`hopper_status`). Hintergrund: `Inhalt Pelletsbehälter` ist ein gerechneter Nennwert —
  er steht nach dem Saugen auf 30 kg, während der Status zeitgleich „Nicht voll" melden kann.
- **Nachtrag vom selben Tag:** ein manueller Saugvorgang mit 15-Sekunden-Protokoll hat die Frage
  beantwortet — der Status durchläuft „Nicht voll" → „Saugen" → „Saugturbine Nachlauf" → „Voll",
  Inhalt und Lager blieben dabei unverändert. Der gerechnete Nennwert von 30 kg stimmt also, die
  Bilanz braucht keine Korrektur. Die Variable bleibt im Log, weil sie den Saugzyklus sichtbar macht.

---

## v0.81 — Durchgehende Zeitachse (2026-09-20)

### Behoben
- **„Jahr" zeigte nicht ein Jahr.** Die Tagesachse enthielt nur Tage mit Daten — beim Pelletvorrat
  waren das 59 (erst ab dem Bestands-Eintrag vom 24.07.), bei Solar 223. Der Jahresbereich sah damit
  aus wie der Monatsbereich. Die Achse läuft jetzt auf beiden Seiten durchgehend über den vollen
  Zeitraum: Jahr = 365 Tage, Monat = 30 Tage. Tage ohne Messwerte bleiben leer, statt den Zeitraum
  zusammenzuschieben.
- **Vorratsverlauf vor dem ersten Bestands-Eintrag.** Für diese Zeit gibt es keine Bilanz; der Graph
  zeigt dort jetzt den Lagerwert, den der Kessel selbst geführt hat (inklusive der Phase, in der er
  ins Minus lief). Zurückrechnen lässt sich der Zeitraum nicht — die damaligen Lieferungen sind
  nirgends protokolliert. Die Quelle ist in der Karte unter „Woher kommen die Werte?" erklärt.

---

## v0.8 — Verbrauchsseite im Solar-Layout (2026-09-20)

### Geändert
- Die Seite **Verbrauch** ist jetzt genauso aufgebaut wie **Solar**: oben die Karte „Pelletvorrat" mit
  vier Live-Kacheln (Vorrat gesamt, Lager, Behälter, Gesamtverbrauch) und dem Verlaufs-Graphen mit den
  Zeitbereichen 24 h / 48 h / Woche / Monat / Jahr, darunter die Karte „Verbrauchsstatistik" mit den
  Balken-Tabs.
- Der Graph zeigt **drei Kurven**: Vorrat gesamt, Lager und Behälter. Damit ist das schubweise
  Nachsaugen in den 30-kg-Behälter direkt sichtbar.
- Für Monat und Jahr zeigt der Graph **Tageswerte** (Stand am Tagesende) statt Stundenwerte.
- Der bisherige Tab „Bestandsverlauf" in der Statistik entfällt — den Verlauf zeigt jetzt der Graph
  darüber, mit mehr Zeitbereichen und feinerer Auflösung.
- Der Erklärtext zur Zählerbasis ist eingeklappt („Was wird hier gezählt?"), wie auf der Solarseite.

### Intern
- Gemeinsame CSS-Bausteine für beide Seiten (`live-summary`, `live-card`, `live-chart-wrap`,
  `range-tabs`) statt solar-spezifischer Klassen.
- `calc_stock_series()` entfällt, ersetzt durch `stock_rows()` mit `calc_stock_timeseries()` und
  `calc_stock_daily()` — dieselbe Rechnung wie die Hero-Bilanz, nur über die Zeit.

---

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
- **Temperatur-Graph mit langen Zeiträumen**: neben 24 Stunden, 48 Stunden und Woche jetzt auch Monat
  und Jahr. Für die beiden langen Bereiche zeigt der Graph das Tagesmaximum je Variable — stündliche
  Rohwerte wären über ein Jahr über 8000 Punkte und unlesbar; beim Kollektor ist die Tagesspitze
  ohnehin das Signal, das über lange Zeiträume zählt.

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
