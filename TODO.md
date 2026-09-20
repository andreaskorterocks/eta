# TODO

Stand: 2026-09-20 — gegen den Code-Stand verifiziert, nicht blind abgehakt.

## Offen

- [ ] **Kalibrierfaktor für den Zähler — beim nächsten Tanken.** Die Entnahme des Kessels weicht
      geschätzt um rund 100 kg pro Heizperiode ab (Kalibrierung der Förderschnecke). Der Vergleichswert
      fällt beim nächsten Befüllen an, wenn das Lager wieder leergefahren ist:

      0. **Früherer Messpunkt: der erste Sack.** Wenn die Schnecke nichts mehr fördert, ist das Lager
         leergefahren. Was die Bilanz dann noch anzeigt, ist der aufgelaufene Zählerfehler seit dem
         24.07. Der Zählerstand dazu wird beim Drücken von „+ 15 kg Sack" automatisch in
         `pellet_events.txt` mitgeschrieben — also beim *ersten* Sack drücken, nicht erst beim dritten.
         Zusätzlich „Lager befüllt auf 0 kg" (oder die geschätzte Restmenge) eintragen, dann steht die
         Bilanz wieder auf der Wahrheit. Einschränkung: eine Restmenge in den Ecken erreicht die
         Schnecke nie (grob 50–150 kg), der Punkt ist also nicht exakt 0 kg.
      1. **Vor dem Befüllen** den Zählerstand notieren (Kachel „Gesamtverbrauch", oder letzte Zeile zu
         `/40/10021/0/0/12016` in `pellet_verbrauch.txt`).
      2. **Gelieferte Menge** vom Lieferschein festhalten.
      3. Rechnung: verbrannt laut Zähler seit der letzten Befüllung (24.07.2026, Stand 63865 kg)
         gegen *gelieferte Menge der letzten Ladung (3600 kg) + zwischendurch nachgetragene Säcke*.
         Beide Male muss das Lager leer gewesen sein, sonst passt der Vergleich nicht.
      4. Faktor = tatsächlich verbraucht / laut Zähler. Erst dann als Korrektur einbauen — vorher
         nicht raten.

      **Bekannte Eckdaten (Stand 20.09.2026):** ETA PU 15, Baujahr 2014, im Kessel auf **11 kW**
      konfiguriert (Typenschild 14,9 kW); Wirkungsgrad **92–94 %**, zuletzt vom Kaminfeger bestätigt;
      Pellets-Zwischenbehälter 30 kg (Datenblatt). Gemessen Feb–Sep 2026: 612,8 Volllaststunden,
      1.478 kg → **2,412 kg je Volllaststunde**. Erwartung bei 11 kW: 2,60 (4,6 kWh/kg, 92 %) bis
      2,34 (5,0 kWh/kg, 94 %) — der Zähler liegt damit zwischen 7 % zu niedrig und 3 % zu hoch,
      in kg: **−44 bis +115 kg** auf den Zeitraum. Die vermuteten ~100 kg liegen am Ende mit
      niedrigem Heizwert und sind damit plausibel, aber nicht belegt.

      **Nebenprodukt der Kalibrierung:** Da Leistung und Wirkungsgrad feststehen, liefert die
      Massenbilanz über eine volle Lagerfüllung zusätzlich den tatsächlichen **Heizwert** der
      gelieferten Pellets — die einzige noch offene Größe in der Rechnung.

      **Geklärt:** Der Februar-Ausreißer (2,723 kg/Vh gegenüber 2,19–2,37 in den übrigen Monaten) ist
      die dynamische Leistungsanpassung des Kessels. Aus den Zählern abgeleitet: Februar 12,16 kW,
      März 10,60, April 10,11, Mai 10,15, September 10,13 — kalter Monat, höhere Leistung.

      **Grenze der Methode (wichtig):** Die effektive Leistung wird aus demselben Verhältnis
      abgeleitet, das geprüft werden soll — eine Gleichung mit zwei Unbekannten. Nimmt man die
      Dynamik als gegeben, liegt der Periodenschnitt bei 10,76 kW und der Zähler stimmt auf 0,3 %.
      Setzt man fest 12 kW an, fehlen 170 kg. Beides passt zu denselben Messwerten. Empfindlichkeit:
      jedes halbe kW verschiebt das Ergebnis um rund 70 kg (11,0 kW → +33 kg, 11,5 → +102, 12,0 → +170,
      12,5 → +239). Nur eine Masse von außen (Lieferschein, gewogener Sack) löst das auf.

- [ ] **Solarstatistik auf Pumpenlaufzeit umstellen.** Seit 20.09.2026 werden `Kollektorpumpe` (%) und
      `Speicher 1 unten` mitgeloggt. Sobald ein paar Wochen Historie da sind, kann die Statistik von den
      Sonnenstunden (Kollektor über Schwelle) auf die tatsächliche Förderzeit umgestellt werden — das ist
      der echte Betrieb statt nur „Sonne war da". Auflösung bleibt durch das stündliche Logging bei ±1 h
      pro Tag; feiner ginge nur mit häufigerem Cronjob.

## Bewusst entschieden

- **Der gerechnete Behälterinhalt von 30 kg stimmt** — geprüft am 20.09.2026 mit einem manuellen
  Saugvorgang und 15-Sekunden-Protokoll. Ablauf: „Nicht voll" → *Saugen* (Turbine ein, 20:16:35) →
  *Saugturbine Nachlauf* → **„Voll"** (20:17:06, Code 2045). Inhalt blieb bei 30 kg, Lager bei
  3.593 kg, Zähler unverändert — es wurde also weniger als 1 kg nachgefördert, der Behälter war
  tatsächlich voll. Das vorherige „Nicht voll" (Code 2040) war der Zustand seit dem letzten
  Saugzyklus, kein Hinweis auf einen zu hoch gebuchten Inhalt. Der Startbestand der Bilanz
  (3.600 + 30 kg) braucht keine Korrektur.

- **Datendateien bleiben im Web-Dokumentenstamm** (`/volume1/web/eta/`): `pellet_verbrauch.txt`,
  `pellet_events.txt` und `config.json` sind damit per HTTP abrufbar. Entscheidung vom 20.09.2026:
  bleibt so, weil das Dashboard nur im lokalen Netz läuft und keine Passwörter in den Dateien stehen —
  lediglich IP und Port des Kessels. Die Web-Station-Regel in `~/dev/CLAUDE.md` zielt auf Secrets;
  sollte das Dashboard je von außen erreichbar werden, ist das hier der erste Punkt, der nachzuziehen
  ist (Daten nach `/volume1/homes/andreas/private-data/eta/`, `DATA_DIR`-Konstante in der PHP-Datei).

## Erledigt (v0.6)

- [x] Verbrauch aus dem Zähler `Gesamtverbrauch` statt aus dem Rückgang des Lagerwerts
- [x] Vorratsbilanz aus Füllmenge + Säcke − verbrannte kg, aufgeteilt in Lager und Behälter
- [x] Nachtragen von Säcken und Lieferungen über das Dashboard, Einträge einzeln löschbar
- [x] Zähler und Behälter werden immer geloggt, auch ohne eigene Kachel (PHP und Cronjob)
- [x] Deploy auf das NAS am 20.09.2026 — zog v0.41 (IP/Port aus `config.json`) und v0.5 (Solar-Tab) mit,
      die dort noch fehlten. Backup der alten Dateien: `/volume1/homes/andreas/eta-backup/20260920-175204/`
- [x] Basis-Eintrag in `pellet_events.txt` angelegt: Befüllung 24.07.2026, 3600 kg, Zählerstand 63865,
      Behälter 30 kg (aus den Logdaten rekonstruiert)
