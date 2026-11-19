# Benutzungsstunden (IP-Symcon)

Berechnet die **Benutzungsstunden pro Jahr** je Energieart des Gebäudes und zeigt sie als Kachel in der Tile-Visualisierung.

    Benutzungsstunden [h/a] = Jahresenergie [kWh] / Bezugsleistung [kW]

## Installation
1. Ordner `SymconBenutzungsstunden` nach `…/symcon/modules/` kopieren (oder als Git-Repo über die Modulverwaltung einbinden).
2. Instanz **Benutzungsstunden** anlegen.
3. Energiearten in der Liste eintragen und übernehmen.
4. In der Tile-Visu die Instanz als Kachel hinzufügen.

## Voraussetzungen
- Energievariable: im Archiv geloggt, Aggregationstyp **Zähler**.
- Leistungsvariable (nur bei Bezugsleistung „Leistungsvariable“): geloggt, Aggregationstyp **Standard**.

## Mehrere Zähler (z. B. 5 Trafos)
Pro Energieart können bis zu 5 Zähler eingetragen werden (Zähler 1–5). Das Modul addiert die Verbräuche je Viertelstunde;
die Bezugsleistung ist damit die **zeitgleiche** Summenspitze, nicht die Summe der Einzelspitzen. Alle Zähler einer Zeile
brauchen dieselbe Einheit (Faktor gilt für alle) und müssen als „Zähler“ geloggt sein.

**Plausibilitätsgrenze [kW]:** Viertelstunden mit höherer Summenleistung werden bei der Spitze ignoriert (z. B. Nachholsprung
nach einem Zählerausfall). Empfehlung: etwas über der Summe der Trafo-Nennleistungen. 0 = aus. Ignorierte Intervalle
werden in der Kachel angezeigt und im Debug-Fenster mit Zeitpunkt protokolliert.

## Bezugsleistung
| Option | Berechnung |
|---|---|
| 15-min-Maximum aus Zähler | höchster 15-min-Mittelwert aus den 5-min-Aggregaten des Zählers (wie die RLM-Abrechnung). Gibt es keine 5-min-Daten, wird mit Stundenmitteln gerechnet. |
| Maximum Summe Leistung 1–5 | bis zu 5 Wirkleistungen (z. B. je Trafo, Aggregationstyp „Standard“) werden je Minute addiert (1-min-Mittel); die höchste zeitgleiche Summe ist die Bezugsleistung. Ohne 1-min-Daten: 5-min- bzw. Stundenmittel. |
| Nennleistung | fester Wert, z. B. Kessel- oder Anschlussleistung (→ Vollbenutzungsstunden) |

## Faktoren
- Zähler in Wh → 0,001 · in MWh → 1000 · Gas in m³ → Brennwert × Zustandszahl (z. B. 10,4)
- Leistung in W → 0,001

## Zeitraum
Die Auswertung läuft fortlaufend ab dem **Startjahr** (Standard 2025) bis zum laufenden Jahr – jedes Jahr kommt automatisch ein Kalenderjahr (Jan–Dez) hinzu.

## Unvollständige Jahre
Das Modul prüft je Zähler, ob das **Logging** schon vor dem 1. Januar lief (Standby-Zeiten ohne Zähleränderung zählen als Daten).
Beginnt das Logging eines Zählers erst im Laufe des Jahres (z. B. seit 28.12.2025),
wird das Jahr als **unvollständig** gekennzeichnet (grau, Hinweis mit Datenbeginn) und nicht in die Vorjahres-Variable geschrieben.
Fehlen am Jahresanfang nur wenige Tage (Einstellung **Toleranz**, Standard 14 Tage – z. B. Stillstand ohne Archivwerte), erscheint nur ein grauer Hinweis „Daten ab …“.
Ab 14 Tagen Daten wird zusätzlich auf das Gesamtjahr hochgerechnet. Abschaltbar über „Unvollständige Jahre kennzeichnen“.
Bekannte Lücken zu Jahresbeginn (z. B. vor Produktionsstart) lassen sich über **„Datenlücke zu Jahresbeginn ignorieren bis“** ausblenden (voreingestellt: 06.01.2026; gilt nur für das Jahr des Datums).

## Monatsansicht
Umschalter „Jahre / Monate“ in der Kachel: Energie je Monat und Leistungsspitze je Monat; der Monat der Jahresspitze ist hervorgehoben.
Der Jahreswert bleibt die maßgebliche Kennzahl (Jahresenergie ÷ höchste Spitze des Jahres).

## Aufteilung der Spitze
Bei mehreren Zählern bzw. Leistungen speichert das Modul für jede Spitze (Jahr und Monat) den Anteil jeder Variable.
- Kachel → Bezugsleistung → „Aufteilung anzeigen“ bzw. Monatsansicht → Klick auf einen Spitzen-Balken: Popup mit Anteilen je Trafo.
- Tooltip der Monatsspitzen zeigt die Anteile direkt.
- Debug-Fenster: „Spitze JJJJ: … kW am … = T1 + T2 + …“.
So lässt sich ein Fehlwert sofort einem Trafo zuordnen.

## Datenlücke ignorieren bis
Optionales Datum (z. B. Produktionsstart). Fehlen Archivdaten nur bis zu diesem Tag, gilt das Jahr als vollständig.

## Info-Button
Das „i“ in der Kachel öffnet ein Popup, das Benutzungsstunden, typische Werte, die Bedeutung für Netzentgelte und die Kachelinhalte für Laien erklärt.

## Nur lokal
Das Modul macht zur Laufzeit keinerlei Online-Abfragen: Daten kommen ausschließlich aus dem lokalen Symcon-Archiv, die Kachel lädt keine externen Schriften, Skripte oder Bilder.

## Ergebnis
- Kachel: Wert je Energieart, Auslastung bezogen auf 8.760 (8.784) h, Energie, Leistungsspitze mit Zeitpunkt, Jahresvergleich, Hochrechnung für das laufende Jahr (ab 14 Tagen Daten).
- Variablen: Benutzungsstunden Vorjahr und laufendes Jahr (bisher) je Energieart, z. B. für Meldungen oder Ereignisse.
- Abgeschlossene Jahre werden zwischengespeichert. Nach Archiv-Korrekturen: **Alle Jahre neu berechnen**.

## PHP-Funktionen
- `BNS_Update(int $InstanzID)` – neu berechnen (laufendes Jahr)
- `BNS_RecalculateAll(int $InstanzID)` – Cache löschen, alle Jahre neu berechnen
