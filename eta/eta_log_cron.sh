#!/bin/bash
# ETA Pellet Tracker - Cronjob Script
# Ruft alle Variablen aus der config.json ab und loggt sie in die TXT-Datei.
# Einrichtung im Synology Aufgabenplaner:
#   Systemsteuerung > Aufgabenplaner > Erstellen > Geplante Aufgabe > Benutzerdefiniertes Skript
#   Zeitplan: Taeglich um 06:00, 14:00, 22:00
#   Skript: bash /volume1/web/eta/eta_log_cron.sh

BASE_DIR="/volume1/web/eta"
LOG_FILE="$BASE_DIR/pellet_verbrauch.txt"
CONFIG_FILE="$BASE_DIR/config.json"

# IP/Port aus config.json lesen, Fallback auf Defaults
if [ -f "$CONFIG_FILE" ]; then
    ETA_IP=$(python3 -c "import sys,json;c=json.load(open('$CONFIG_FILE'));print(c.get('eta_ip','192.168.88.36'))" 2>/dev/null)
    ETA_PORT=$(python3 -c "import sys,json;c=json.load(open('$CONFIG_FILE'));print(c.get('eta_port',8080))" 2>/dev/null)
fi
ETA_IP="${ETA_IP:-192.168.88.36}"
ETA_PORT="${ETA_PORT:-8080}"

TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')
COUNT=0

# Funktion: Variable von ETA API abrufen und loggen
fetch_and_log() {
    local URI="$1"
    local NAME="$2"

    XML=$(curl -s -m 10 "http://${ETA_IP}:${ETA_PORT}/user/var${URI}" 2>/dev/null)
    if [ -z "$XML" ]; then
        return
    fi

    # strValue extrahieren
    STR_VALUE=$(echo "$XML" | sed -n 's/.*strValue="\([^"]*\)".*/\1/p')
    # unit extrahieren
    UNIT=$(echo "$XML" | sed -n 's/.*unit="\([^"]*\)".*/\1/p')
    # Rohwert extrahieren (Inhalt zwischen >...</value>)
    RAW_VALUE=$(echo "$XML" | sed -n 's/.*<value[^>]*>\([^<]*\)<\/value>.*/\1/p')

    if [ -n "$STR_VALUE" ]; then
        echo -e "${TIMESTAMP}\t${NAME}\t${STR_VALUE}\t${UNIT}\t${RAW_VALUE}\t${URI}\tcron" >> "$LOG_FILE"
        COUNT=$((COUNT + 1))
    fi
}

# Config lesen (falls vorhanden, sonst Fallback auf Defaults)
if [ -f "$CONFIG_FILE" ]; then
    # Hero-Variable auslesen
    HERO_URI=$(cat "$CONFIG_FILE" | python3 -c "import sys,json;c=json.load(sys.stdin);print(c['hero']['uri'])" 2>/dev/null)
    HERO_NAME=$(cat "$CONFIG_FILE" | python3 -c "import sys,json;c=json.load(sys.stdin);print(c['hero']['name'])" 2>/dev/null)

    if [ -n "$HERO_URI" ] && [ -n "$HERO_NAME" ]; then
        fetch_and_log "$HERO_URI" "$HERO_NAME"
    fi

    # Tiles auslesen
    TILE_COUNT=$(cat "$CONFIG_FILE" | python3 -c "import sys,json;c=json.load(sys.stdin);print(len(c['tiles']))" 2>/dev/null)
    if [ -n "$TILE_COUNT" ]; then
        for i in $(seq 0 $((TILE_COUNT - 1))); do
            TILE_URI=$(cat "$CONFIG_FILE" | python3 -c "import sys,json;c=json.load(sys.stdin);print(c['tiles'][$i]['uri'])" 2>/dev/null)
            TILE_NAME=$(cat "$CONFIG_FILE" | python3 -c "import sys,json;c=json.load(sys.stdin);print(c['tiles'][$i]['name'])" 2>/dev/null)
            if [ -n "$TILE_URI" ] && [ -n "$TILE_NAME" ]; then
                fetch_and_log "$TILE_URI" "$TILE_NAME"
            fi
        done
    fi
else
    # Fallback: Hardcoded Defaults (falls config.json nicht existiert)
    VARS=(
        "/40/10201/0/0/12015|Lager Vorrat"
        "/40/10021/0/0/12016|Gesamtverbrauch"
        "/40/10021/0/0/12011|Inhalt Pelletsbehälter"
        "/40/10021/0/0/12014|Verbrauch seit Wartung"
        "/40/10021/0/0/12012|Verbrauch seit Entaschung"
        "/40/10021/0/0/12013|Verbrauch seit Aschebox leeren"
        "/40/10021/0/0/12153|Volllaststunden"
        "/120/10221/0/0/12197|Aussentemperatur (Solar)"
    )

    for entry in "${VARS[@]}"; do
        URI="${entry%%|*}"
        NAME="${entry##*|}"
        fetch_and_log "$URI" "$NAME"
    done
fi

logger "ETA Pellet Tracker: ${COUNT} Variablen geloggt"
