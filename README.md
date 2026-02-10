# ETA Pellet Dashboard

Web-Dashboard zur Ueberwachung und Protokollierung des Pelletverbrauchs fuer ETA Pelletheizungen via ETAtouch RESTful API.

![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?logo=php&logoColor=white)
![License](https://img.shields.io/badge/License-MIT-green)

## Features

- **Live-Dashboard** mit Hero-Anzeige (Lager Vorrat) und konfigurierbaren Kacheln
- **Verbrauchsstatistik** mit Chart.js Diagrammen (taeglich/woechentlich/monatlich/jaehrlich)
- **Menubaum-Browser** zum Durchsuchen aller Kessel-Variablen
- **Konfigurierbares Dashboard** - Kacheln aus dem Menubaum hinzufuegen/entfernen, Hero-Variable aendern, URI-Pfade bearbeiten
- **Logging** in Tab-separierte Textdatei (manuell + Cronjob)
- **Responsive Design** fuer Desktop, Tablet und Smartphone
- **Cronjob-Script** fuer automatische Datenerfassung via Synology Aufgabenplaner

## Voraussetzungen

- ETA Pelletheizung mit ETAtouch RESTful API (Port 8080)
- Webserver mit PHP 8.2+ und curl-Extension
- (Optional) Synology NAS fuer Hosting und Cronjob

## Installation

1. Dateien aus `eta/` in das Web-Verzeichnis kopieren:
   ```
   pellet_tracker.php   # Haupt-Dashboard
   index.html           # Redirect auf pellet_tracker.php
   eta_log_cron.sh      # Cronjob-Script
   ```

2. IP-Adresse des Kessels in `pellet_tracker.php` anpassen:
   ```php
   define('ETA_IP', '192.168.88.36');
   define('ETA_PORT', 8080);
   ```

3. Schreibrechte fuer das Web-Verzeichnis sicherstellen (fuer `config.json` und `pellet_verbrauch.txt`)

4. (Optional) Cronjob einrichten - z.B. im Synology Aufgabenplaner:
   ```
   bash /volume1/web/eta/eta_log_cron.sh
   ```
   Empfohlen: 3x taeglich (06:00, 14:00, 22:00)

## Konfiguration

Die Dashboard-Konfiguration wird in `config.json` gespeichert und kann ueber die Web-Oberflaeche unter **Einstellungen** bearbeitet werden:

- Hero-Variable und Kacheln mit Name + URI-Pfad
- Kacheln aus dem Menubaum per Klick hinzufuegen (+) oder als Hero setzen (★)
- Kacheln auf dem Dashboard per X entfernen
- Reset auf Standardkonfiguration

Bei einer neuen Heizung koennen alle URI-Pfade (`/node/fub/fkt/io/var`) ueber die Einstellungsseite angepasst werden.

## Dateistruktur

```
ETA/
├── README.md
├── .gitignore
├── eta/                        # Web-Dateien (auf Webserver deployen)
│   ├── pellet_tracker.php      # Dashboard + API
│   ├── index.html              # Redirect
│   └── eta_log_cron.sh         # Cronjob-Script
└── REST_API_DOC/
    └── ETA-RESTful-v1.2.pdf    # API-Dokumentation
```

Laufzeitdateien (werden automatisch erstellt):
- `config.json` - Dashboard-Konfiguration
- `pellet_verbrauch.txt` - Verbrauchslog (Tab-separiert)

## API

Nutzt die ETAtouch RESTful API v1.1/v1.2:
- `GET /user/menu` - Menubaum aller Variablen
- `GET /user/var/{uri}` - Einzelne Variable auslesen

Dokumentation: siehe `REST_API_DOC/`

## Changelog

### v0.4 - ETA Dashboard
- Konfigurierbares Dashboard mit `config.json`
- Kacheln hinzufuegen/entfernen ueber Menubaum und Dashboard
- Hero-Variable aus Menubaum setzbar
- Einstellungsseite zum Bearbeiten aller URI-Pfade
- Reset auf Standardkonfiguration
- Cronjob liest Variablen aus `config.json`

### v0.1 - Initial
- Grundlegendes Dashboard mit Live-Werten
- Verbrauchsstatistik mit Charts
- Log-Ansicht und Menubaum-Browser
- Responsive Design
- Cronjob-Script
