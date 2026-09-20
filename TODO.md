# TODO

Stand: 2026-09-20 — gegen den Code-Stand verifiziert, nicht blind abgehakt.

## Offen

- [ ] **Deploy auf das NAS.** Die Version unter `/volume1/web/eta/` ist der Stand vom 27.05. 08:40 und
      damit hinter dem Repo: v0.41 (IP/Port aus `config.json`), v0.5 (Solar-Tab) und v0.6 (Zähler-Verbrauch,
      Vorratsbilanz) sind dort noch nicht drauf. Ein Deploy zieht alle drei mit.
- [ ] **Basis-Eintrag anlegen**, sobald v0.6 live ist: `pellet_events.txt` mit der Befüllung vom
      24.07.2026 (3600 kg, Zählerstand 63865, Behälter 30 kg). Ohne diesen Eintrag zeigt die Hero-Karte
      „Noch kein Bestand eingetragen".
- [ ] **Kalibrierfaktor für den Zähler.** Die Entnahme des Kessels weicht geschätzt um rund 100 kg pro
      Heizperiode ab (Kalibrierung der Förderschnecke). Messbar erst, wenn ein Lager von leer bis leer
      durchgelaufen ist — dann kann ein Faktor auf den Zählerverbrauch gelegt werden. Vorher nicht raten.
- [ ] **Datendateien liegen im Web-Dokumentenstamm** (`/volume1/web/eta/`): `pellet_verbrauch.txt`,
      `pellet_events.txt` und `config.json` sind damit per HTTP abrufbar. Keine Secrets darin, aber
      `config.json` verrät IP und Port des Kessels. Verschieben nach `/volume1/homes/andreas/private-data/eta/`
      wäre die saubere Variante (siehe Web-Station-Regel in `~/dev/CLAUDE.md`).

## Erledigt (v0.6)

- [x] Verbrauch aus dem Zähler `Gesamtverbrauch` statt aus dem Rückgang des Lagerwerts
- [x] Vorratsbilanz aus Füllmenge + Säcke − verbrannte kg, aufgeteilt in Lager und Behälter
- [x] Nachtragen von Säcken und Lieferungen über das Dashboard, Einträge einzeln löschbar
- [x] Zähler und Behälter werden immer geloggt, auch ohne eigene Kachel (PHP und Cronjob)
