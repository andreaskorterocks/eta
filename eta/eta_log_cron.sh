#!/bin/bash
# ETA Pellet Tracker - Cronjob Script
# Ruft alle Variablen (Hero, Tiles, Solar) aus der config.json ab und loggt sie.
# Einrichtung im Synology Aufgabenplaner:
#   Systemsteuerung > Aufgabenplaner > Erstellen > Geplante Aufgabe > Benutzerdefiniertes Skript
#   Zeitplan: Stündlich (jede Stunde)
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
LOGGED_URIS=""

fetch_and_log() {
    local URI="$1"
    local NAME="$2"

    XML=$(curl -s -m 10 "http://${ETA_IP}:${ETA_PORT}/user/var${URI}" 2>/dev/null)
    if [ -z "$XML" ]; then
        return
    fi

    STR_VALUE=$(echo "$XML" | sed -n 's/.*strValue="\([^"]*\)".*/\1/p')
    UNIT=$(echo "$XML" | sed -n 's/.*unit="\([^"]*\)".*/\1/p')
    RAW_VALUE=$(echo "$XML" | sed -n 's/.*<value[^>]*>\([^<]*\)<\/value>.*/\1/p')

    if [ -n "$STR_VALUE" ]; then
        echo -e "${TIMESTAMP}\t${NAME}\t${STR_VALUE}\t${UNIT}\t${RAW_VALUE}\t${URI}\tcron" >> "$LOG_FILE"
        COUNT=$((COUNT + 1))
        LOGGED_URIS="${LOGGED_URIS}|${URI}"
    fi
}

already_logged() {
    echo "$LOGGED_URIS" | grep -qF "|$1"
}

if [ -f "$CONFIG_FILE" ]; then
    # Hero
    HERO_URI=$(python3 -c "import sys,json;c=json.load(open('$CONFIG_FILE'));print(c['hero']['uri'])" 2>/dev/null)
    HERO_NAME=$(python3 -c "import sys,json;c=json.load(open('$CONFIG_FILE'));print(c['hero']['name'])" 2>/dev/null)
    if [ -n "$HERO_URI" ] && [ -n "$HERO_NAME" ]; then
        fetch_and_log "$HERO_URI" "$HERO_NAME"
    fi

    # Zaehler (verbrannte kg) und Vorratsbehaelter sind die Basis der Bilanz im
    # Dashboard -- immer loggen, auch wenn sie nicht als Kachel konfiguriert sind.
    COUNTER_URI=$(python3 -c "import json;c=json.load(open('$CONFIG_FILE'));print(c.get('counter',{}).get('uri',''))" 2>/dev/null)
    COUNTER_NAME=$(python3 -c "import json;c=json.load(open('$CONFIG_FILE'));print(c.get('counter',{}).get('name',''))" 2>/dev/null)
    COUNTER_URI="${COUNTER_URI:-/40/10021/0/0/12016}"
    COUNTER_NAME="${COUNTER_NAME:-Gesamtverbrauch}"
    already_logged "$COUNTER_URI" || fetch_and_log "$COUNTER_URI" "$COUNTER_NAME"

    HOPPER_URI=$(python3 -c "import json;c=json.load(open('$CONFIG_FILE'));print(c.get('hopper',{}).get('uri',''))" 2>/dev/null)
    HOPPER_NAME=$(python3 -c "import json;c=json.load(open('$CONFIG_FILE'));print(c.get('hopper',{}).get('name',''))" 2>/dev/null)
    HOPPER_URI="${HOPPER_URI:-/40/10021/0/0/12011}"
    HOPPER_NAME="${HOPPER_NAME:-Inhalt Pelletsbehälter}"
    already_logged "$HOPPER_URI" || fetch_and_log "$HOPPER_URI" "$HOPPER_NAME"

    STATUS_URI=$(python3 -c "import json;c=json.load(open('$CONFIG_FILE'));print(c.get('hopper_status',{}).get('uri',''))" 2>/dev/null)
    STATUS_NAME=$(python3 -c "import json;c=json.load(open('$CONFIG_FILE'));print(c.get('hopper_status',{}).get('name',''))" 2>/dev/null)
    STATUS_URI="${STATUS_URI:-/40/10021/0/0/12005}"
    STATUS_NAME="${STATUS_NAME:-Pelletsbehälter Status}"
    already_logged "$STATUS_URI" || fetch_and_log "$STATUS_URI" "$STATUS_NAME"

    # Solarstatistik: Pumpe und Speicherfuehler mitloggen (Basis fuer die spaetere
    # Umstellung der Statistik auf echte Pumpenlaufzeit).
    PUMP_URI=$(python3 -c "import json;c=json.load(open('$CONFIG_FILE'));print(c.get('solar_stats',{}).get('pump',{}).get('uri',''))" 2>/dev/null)
    PUMP_NAME=$(python3 -c "import json;c=json.load(open('$CONFIG_FILE'));print(c.get('solar_stats',{}).get('pump',{}).get('name',''))" 2>/dev/null)
    PUMP_URI="${PUMP_URI:-/120/10221/0/0/12278}"
    PUMP_NAME="${PUMP_NAME:-Kollektorpumpe}"
    already_logged "$PUMP_URI" || fetch_and_log "$PUMP_URI" "$PUMP_NAME"

    STORE_URI=$(python3 -c "import json;c=json.load(open('$CONFIG_FILE'));print(c.get('solar_stats',{}).get('store',{}).get('uri',''))" 2>/dev/null)
    STORE_NAME=$(python3 -c "import json;c=json.load(open('$CONFIG_FILE'));print(c.get('solar_stats',{}).get('store',{}).get('name',''))" 2>/dev/null)
    STORE_URI="${STORE_URI:-/120/10221/0/0/12781}"
    STORE_NAME="${STORE_NAME:-Speicher 1 unten}"
    already_logged "$STORE_URI" || fetch_and_log "$STORE_URI" "$STORE_NAME"

    # Tiles
    TILE_COUNT=$(python3 -c "import sys,json;c=json.load(open('$CONFIG_FILE'));print(len(c['tiles']))" 2>/dev/null)
    if [ -n "$TILE_COUNT" ]; then
        for i in $(seq 0 $((TILE_COUNT - 1))); do
            TILE_URI=$(python3 -c "import sys,json;c=json.load(open('$CONFIG_FILE'));print(c['tiles'][$i]['uri'])" 2>/dev/null)
            TILE_NAME=$(python3 -c "import sys,json;c=json.load(open('$CONFIG_FILE'));print(c['tiles'][$i]['name'])" 2>/dev/null)
            if [ -n "$TILE_URI" ] && [ -n "$TILE_NAME" ] && ! already_logged "$TILE_URI"; then
                fetch_and_log "$TILE_URI" "$TILE_NAME"
            fi
        done
    fi

    # Solar-Variablen
    SOLAR_COUNT=$(python3 -c "import sys,json;c=json.load(open('$CONFIG_FILE'));print(len(c.get('solar',[])))" 2>/dev/null)
    if [ -n "$SOLAR_COUNT" ] && [ "$SOLAR_COUNT" -gt 0 ]; then
        for i in $(seq 0 $((SOLAR_COUNT - 1))); do
            SOLAR_URI=$(python3 -c "import sys,json;c=json.load(open('$CONFIG_FILE'));print(c['solar'][$i]['uri'])" 2>/dev/null)
            SOLAR_NAME=$(python3 -c "import sys,json;c=json.load(open('$CONFIG_FILE'));print(c['solar'][$i]['name'])" 2>/dev/null)
            if [ -n "$SOLAR_URI" ] && [ -n "$SOLAR_NAME" ] && ! already_logged "$SOLAR_URI"; then
                fetch_and_log "$SOLAR_URI" "$SOLAR_NAME"
            fi
        done
    fi
else
    # Fallback: Hardcoded Defaults
    VARS=(
        "/40/10201/0/0/12015|Lager Vorrat"
        "/40/10021/0/0/12016|Gesamtverbrauch"
        "/40/10021/0/0/12011|Inhalt Pelletsbehälter"
        "/40/10021/0/0/12005|Pelletsbehälter Status"
        "/40/10021/0/0/12014|Verbrauch seit Wartung"
        "/40/10021/0/0/12012|Verbrauch seit Entaschung"
        "/40/10021/0/0/12013|Verbrauch seit Aschebox leeren"
        "/40/10021/0/0/12153|Volllaststunden"
        "/120/10221/0/0/12275|Kollektor"
        "/120/10221/0/0/12197|Außentemperatur"
        "/120/10251/0/0/12242|Puffer oben"
        "/120/10251/0/0/12244|Puffer unten"
        "/120/10221/0/0/12278|Kollektorpumpe"
        "/120/10221/0/0/12781|Speicher 1 unten"
    )
    for entry in "${VARS[@]}"; do
        URI="${entry%%|*}"
        NAME="${entry##*|}"
        fetch_and_log "$URI" "$NAME"
    done
fi

logger "ETA Pellet Tracker: ${COUNT} Variablen geloggt"
