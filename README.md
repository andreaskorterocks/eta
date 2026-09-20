# ETA Pellet Dashboard
<img width="949" height="734" alt="image" src="https://github.com/user-attachments/assets/9a432950-1ebf-42a4-b700-865ac7815ef4" />

Web-Dashboard zur Überwachung und Protokollierung von Pelletverbrauch und Solaranlage für ETA Pelletheizungen via ETAtouch RESTful API.

![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?logo=php&logoColor=white)
![License](https://img.shields.io/badge/License-MIT-green)

## Features

- **Live-Dashboard** mit Vorratsbilanz (Lager + Behälter) und konfigurierbaren Kacheln
- **Eigene Vorratsbilanz** statt des Lagerwerts des Kessels — inklusive Nachtragen von Säcken und Lieferungen
- **Bestandsverlauf** als Linienchart — zeigt wie der Pelletvorrat über die Tage fällt
- **Verbrauchsstatistik** aus dem Zähler der tatsächlich verbrannten kg (täglich / wöchentlich / monatlich / jährlich)
- **Solar-Tab** mit Linien-Chart für Kollektor, Puffer oben/unten und Außentemperatur
  (24 h / 48 h / Woche als Stundenwerte, Monat / Jahr als Tageshöchstwerte)
- **Solarstatistik** mit Sonnenstunden je Tag / Woche / Monat / Jahr und Spitzentemperatur je Tag
- **Menubaum-Browser** zum Durchsuchen aller Kessel-Variablen
- **Konfigurierbares Dashboard** — Kacheln und Hero-Variable über Web-UI anpassen, Solar-Variablen editierbar
- **Kessel IP/Port konfigurierbar** über die Einstellungen (kein Editieren der PHP-Datei nötig)
- **Stündliches Logging** in Tab-separierte Textdatei via Cronjob
- **Responsive Design** für Desktop, Tablet und Smartphone

## Voraussetzungen

- ETA Pelletheizung mit ETAtouch RESTful API (Port 8080)
- Webserver mit PHP 8.2+ und curl-Extension
- Synology NAS (oder beliebiger Linux-Webserver) für Hosting und Cronjob

## Installation

1. Dateien aus `eta/` in das Web-Verzeichnis kopieren:
   ```
   pellet_tracker.php   # Haupt-Dashboard
   index.html           # Redirect auf pellet_tracker.php
   eta_log_cron.sh      # Cronjob-Script
   ```

2. `config.example.json` nach `config.json` kopieren und IP/Port des Kessels anpassen:
   ```json
   {
       "eta_ip": "192.168.88.36",
       "eta_port": 8080,
       ...
   }
   ```
   Alternativ: IP und Port direkt über die Web-Oberfläche unter **Einstellungen** konfigurieren.

3. Schreibrechte für das Web-Verzeichnis sicherstellen (für `config.json` und `pellet_verbrauch.txt`).

4. Cronjob einrichten — im Synology Aufgabenplaner:
   ```
   bash /volume1/web/eta/eta_log_cron.sh
   ```
   Empfohlen: **stündlich**, damit Solar-Kurven ausreichend Auflösung haben.

## Konfiguration

Die Konfiguration wird in `config.json` gespeichert und ist vollständig über die Web-Oberfläche unter **Einstellungen** bearbeitbar:

| Abschnitt | Beschreibung |
|-----------|-------------|
| `eta_ip` / `eta_port` | IP-Adresse und Port des ETA Kessels |
| `hero` | Vergleichsvariable des Kessels (wird geloggt und unter der Bilanz als „Kessel meldet" angezeigt) |
| `counter` | Zähler der verbrannten kg — Basis für Verbrauch und Bilanz (Standard `/40/10021/0/0/12016`) |
| `hopper` | Vorratsbehälter im Kessel (Standard `/40/10021/0/0/12011`) |
| `sack_kg` | Gebindegröße für den Sack-Button auf dem Dashboard (Standard 15) |
| `solar_stats` | Kollektor-Variable, Schwelle für Sonnenstunden (Standard 40 °C) sowie Pumpe und Speicherfühler fürs Logging |
| `tiles` | Dashboard-Kacheln mit Name und URI-Pfad |
| `solar` | Solar-Variablen für den Solar-Tab (Kurven-Logging) |

Kacheln können direkt aus dem **Menubaum** per Klick hinzugefügt (+) oder als Hero gesetzt (★) werden.

### Vorratsbilanz — warum nicht der Lagerwert des Kessels?

Der ETA misst den Lagerbestand nicht, er bucht ihn nur: eingetragene Füllmenge minus verbrannte kg.
Zwei Dinge laufen dadurch aus dem Ruder:

- **Säcke, die bei einem Klemmer direkt in den Behälter gekippt werden**, kennt diese Buchhaltung nicht.
  Der Lagerwert wird pro Sack zu niedrig und kann negativ werden (im Testzeitraum bis −14 kg).
- **Verbrannt wird aus dem 30-kg-Behälter**, der schubweise aus dem Lager nachgesaugt wird. Der Rückgang
  des Lagerwerts zeigt also den Saugzeitpunkt, nicht das Verbrennen.

Das Dashboard rechnet deshalb selbst:

```
Vorrat gesamt = (Lager + Behälter beim letzten Bestandseintrag)
                + nachgetragene Säcke
                - verbrannte kg laut Zähler
Lager         = Vorrat gesamt - aktueller Behälterinhalt
```

Gepflegt wird das auf dem Dashboard unter **Vorrat nachtragen**:

| Eintrag | Wann | Wirkung |
|---------|------|---------|
| `+ 15 kg Sack` | Sack von Hand in den Behälter gekippt | erhöht die Bilanz um die Gebindegröße |
| `Lager befüllt auf … kg` | nach einer Lieferung | setzt die Bilanz neu auf diesen Lagerstand |

Jeder Eintrag speichert den Zählerstand und den Behälterinhalt des Zeitpunkts mit — nur so lässt sich
der Verlauf später zurückrechnen. Fehleingaben lassen sich in der Liste darunter wieder entfernen.

**Was die Bilanz nicht kann:** Die Kalibrierung der Förderschnecke steckt im Zähler des Kessels. Weicht
sie ab (geschätzt rund 100 kg pro Heizperiode), weicht auch die Bilanz ab. Sichtbar wird das, wenn das
Lager leergefahren ist — also beim ersten Sack, den die Schnecke nicht mehr ersetzen kann: was die Bilanz
dann noch anzeigt, ist der aufgelaufene Fehler (abzüglich der Restmenge, die die Schnecke nie erreicht).

### Solarstatistik — Sonnenstunden statt kWh

Der Kessel hat **keinen Ertragszähler**: der Solar-Funktionsblock (`/120/10221`) kennt nur Zustand,
Kollektor, Kollektor Min, Außentemperatur, Startfunktion, Kollektorpumpe und Speicher 1 unten. Ein
Ertrag in kWh lässt sich daraus nicht ableiten.

Gezählt wird deshalb die **Zeit oberhalb der Kollektor-Starttemperatur** (Standard 40 °C, im Kessel
als „Kollektor Min" hinterlegt) — die Zeit, in der die Anlage liefern konnte. Jeder Messpunkt zählt
mit dem Abstand zum nächsten, gedeckelt auf zwei Stunden, damit ein ausgefallener Cronjob keine
Stunden erfindet.

**Was die Zahl nicht ist:** kein Ertrag. Steht der Puffer voll, schaltet die Pumpe ab — die Stunde
zählt trotzdem. Als Vergleich zwischen Tagen, Wochen und Monaten ist sie trotzdem aussagekräftig, weil
die Bedingung immer dieselbe ist.

**Der Temperatur-Graph** darüber zeigt 24 Stunden, 48 Stunden und eine Woche als stündliche Rohwerte;
für Monat und Jahr das **Tagesmaximum je Variable**. Über ein Jahr wären Stundenwerte mehr als 8000
Punkte — unlesbar und unnötig groß. Das Tagesmittel wäre beim Kollektor vom Nachtwert erschlagen, die
Tagesspitze zeigt den Verlauf über die Jahreszeiten dagegen sauber.

Seit v0.7 werden zusätzlich **Kollektorpumpe** (in %) und **Speicher 1 unten** geloggt. Sobald davon
genug Historie vorliegt, kann die Statistik auf die tatsächliche Pumpenlaufzeit umgestellt werden —
das ist dann der echte Förderbetrieb statt nur „Sonne war da".

### Solar-Variablen (Standardkonfiguration)

| Name | URI |
|------|-----|
| Kollektor | `/120/10221/0/0/12275` |
| Außentemperatur | `/120/10221/0/0/12197` |
| Puffer oben | `/120/10251/0/0/12242` |
| Puffer unten | `/120/10251/0/0/12244` |

URI-Pfade können je nach Kessel-Konfiguration abweichen — im Menubaum nachschlagen.

## Dateistruktur

```
ETA/
├── README.md
├── CHANGELOG.md
├── LICENSE
├── .gitignore
├── eta/                             # Web-Dateien (auf Webserver deployen)
│   ├── pellet_tracker.php           # Dashboard + API + Logging
│   ├── index.html                   # Redirect
│   ├── eta_log_cron.sh              # Cronjob-Script (stündlich)
│   ├── config.example.json          # Beispiel-Konfiguration
│   ├── pellet_verbrauch.example.txt # Beispiel-Logdatei
│   └── pellet_events.example.txt    # Beispiel-Ereignisdatei (Lieferungen/Säcke)
└── REST_API_DOC/
    └── ETA-RESTful-v1.2.pdf         # API-Dokumentation
```

Laufzeitdateien (werden automatisch erstellt, nicht ins Git einchecken):
- `config.json` — Dashboard-Konfiguration
- `pellet_verbrauch.txt` — Verbrauchslog (Tab-separiert, alle Variablen)
- `pellet_events.txt` — Bestands-Ereignisse (Lieferungen und Säcke)

## API

Nutzt die ETAtouch RESTful API v1.1/v1.2:

| Endpoint | Beschreibung |
|----------|-------------|
| `GET /user/menu` | Menubaum aller verfügbaren Variablen |
| `GET /user/var/{uri}` | Einzelne Variable auslesen |

Vollständige Dokumentation: `REST_API_DOC/`

## Log-Format

Die Datei `pellet_verbrauch.txt` ist tab-separiert:

```
Timestamp          Name          strValue  Unit  rawValue  URI                        Source
2026-05-27 10:00   Kollektor     42        °C    420       /120/10221/0/0/12275       cron
2026-05-27 10:00   Puffer oben   55        °C    552       /120/10251/0/0/12242       cron
```

`pellet_events.txt` hält die Bestands-Ereignisse, ebenfalls tab-separiert:

```
Timestamp             Typ       kg    Zählerstand  Behälter  Notiz
2026-07-24 22:00:01   bestand   3600  63865        30        Lieferung, Lager war leer
2026-08-19 07:30:00   sack      15    63870        22        Klemmer, Sack in den Behälter
```

`bestand` setzt den Lagerstand neu, `sack` trägt eine Menge nach, die am Lager vorbei in den
Behälter gegangen ist.

## Changelog

Siehe [CHANGELOG.md](CHANGELOG.md).
