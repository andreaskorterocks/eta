# ETA Pellet Dashboard
<img width="949" height="734" alt="image" src="https://github.com/user-attachments/assets/9a432950-1ebf-42a4-b700-865ac7815ef4" />

Web-Dashboard zur Überwachung und Protokollierung von Pelletverbrauch und Solaranlage für ETA Pelletheizungen via ETAtouch RESTful API.

![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?logo=php&logoColor=white)
![License](https://img.shields.io/badge/License-MIT-green)

## Features

- **Live-Dashboard** mit Hero-Anzeige (Lager Vorrat) und konfigurierbaren Kacheln
- **Bestandsverlauf** als Linienchart — zeigt wie der Pelletvorrat über die Tage fällt
- **Verbrauchsstatistik** mit Chart.js Balkendiagrammen (täglich / wöchentlich / monatlich / jährlich)
- **Solar-Tab** mit Linien-Chart für Kollektor, Puffer oben/unten und Außentemperatur (24h / 48h / 7 Tage)
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
| `hero` | Hero-Variable (groß angezeigt, z.B. Lager Vorrat) |
| `tiles` | Dashboard-Kacheln mit Name und URI-Pfad |
| `solar` | Solar-Variablen für den Solar-Tab (Kurven-Logging) |

Kacheln können direkt aus dem **Menubaum** per Klick hinzugefügt (+) oder als Hero gesetzt (★) werden.

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
│   └── pellet_verbrauch.example.txt # Beispiel-Logdatei
└── REST_API_DOC/
    └── ETA-RESTful-v1.2.pdf         # API-Dokumentation
```

Laufzeitdateien (werden automatisch erstellt, nicht ins Git einchecken):
- `config.json` — Dashboard-Konfiguration
- `pellet_verbrauch.txt` — Verbrauchslog (Tab-separiert, alle Variablen)

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

## Changelog

Siehe [CHANGELOG.md](CHANGELOG.md).
