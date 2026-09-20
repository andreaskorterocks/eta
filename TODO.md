# TODO

Stand: 2026-09-20 — gegen den Code-Stand verifiziert, nicht blind abgehakt.

## Offen

- [ ] **Deploy auf das NAS.** Die Version unter `/volume1/web/eta/` ist der Stand vom 27.05. 08:40 und
      damit hinter dem Repo: v0.41 (IP/Port aus `config.json`), v0.5 (Solar-Tab) und v0.6 (Zähler-Verbrauch,
      Vorratsbilanz) sind dort noch nicht drauf. Ein Deploy zieht alle drei mit.
- [ ] **Basis-Eintrag anlegen**, sobald v0.6 live ist: `pellet_events.txt` mit der Befüllung vom
      24.07.2026 (3600 kg, Zählerstand 63865, Behälter 30 kg). Ohne diesen Eintrag zeigt die Hero-Karte
      „Noch kein Bestand eingetragen".
- [ ] **Kalibrierfaktor für den Zähler — beim nächsten Tanken.** Die Entnahme des Kessels weicht
      geschätzt um rund 100 kg pro Heizperiode ab (Kalibrierung der Förderschnecke). Der Vergleichswert
      fällt beim nächsten Befüllen an, wenn das Lager wieder leergefahren ist:

      1. **Vor dem Befüllen** den Zählerstand notieren (Kachel „Gesamtverbrauch", oder letzte Zeile zu
         `/40/10021/0/0/12016` in `pellet_verbrauch.txt`).
      2. **Gelieferte Menge** vom Lieferschein festhalten.
      3. Rechnung: verbrannt laut Zähler seit der letzten Befüllung (24.07.2026, Stand 63865 kg)
         gegen *gelieferte Menge der letzten Ladung (3600 kg) + zwischendurch nachgetragene Säcke*.
         Beide Male muss das Lager leer gewesen sein, sonst passt der Vergleich nicht.
      4. Faktor = tatsächlich verbraucht / laut Zähler. Erst dann als Korrektur einbauen — vorher
         nicht raten.
- [ ] **Datendateien liegen im Web-Dokumentenstamm** (`/volume1/web/eta/`): `pellet_verbrauch.txt`,
      `pellet_events.txt` und `config.json` sind damit per HTTP abrufbar. Keine Secrets darin, aber
      `config.json` verrät IP und Port des Kessels. Verschieben nach `/volume1/homes/andreas/private-data/eta/`
      wäre die saubere Variante (siehe Web-Station-Regel in `~/dev/CLAUDE.md`).

## Erledigt (v0.6)

- [x] Verbrauch aus dem Zähler `Gesamtverbrauch` statt aus dem Rückgang des Lagerwerts
- [x] Vorratsbilanz aus Füllmenge + Säcke − verbrannte kg, aufgeteilt in Lager und Behälter
- [x] Nachtragen von Säcken und Lieferungen über das Dashboard, Einträge einzeln löschbar
- [x] Zähler und Behälter werden immer geloggt, auch ohne eigene Kachel (PHP und Cronjob)
