<?php

declare(strict_types=1);

/**
 * Benutzungsstunden (Vollbenutzungsstunden) je Energieart und Jahr
 *
 *   h/a = Jahresenergie [kWh] / Bezugsleistung [kW]
 *
 * Bezugsleistung wahlweise:
 *   0 = höchster 15-min-Mittelwert, gebildet aus 5-min-Aggregaten des Zählers (RLM-Logik)
 *   1 = Maximum einer geloggten Leistungsvariable
 *   2 = feste Nennleistung (z. B. Kesselleistung, Anschlussleistung)
 *
 * Abgeschlossene Monate werden zwischengespeichert. Die Erstberechnung läuft portionsweise
 * (max. TIME_BUDGET Sekunden je Durchlauf), damit keine Skript-Zeitüberschreitung auftritt.
 */
class Benutzungsstunden extends IPSModule
{
    private const ARCHIVE_GUID = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
    private const PROFILE = 'BNS.Hours';
    private const MAX_SOURCES = 50;
    private const TIME_BUDGET = 8.0;   // Sekunden Rechenzeit je Durchlauf

    // Aggregationsstufen AC_GetAggregatedValues
    private const AGG_HOUR = 0;
    private const AGG_DAY = 1;
    private const AGG_MONTH = 3;
    private const AGG_5MIN = 5;
    private const AGG_1MIN = 6;

    private const DEFAULT_COLORS = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Sources', '[]');
        $this->RegisterPropertyInteger('StartYear', 2025);
        $this->RegisterPropertyInteger('UpdateInterval', 60);
        $this->RegisterPropertyBoolean('ShowForecast', true);
        $this->RegisterPropertyBoolean('DetectIncomplete', true);
        $this->RegisterPropertyInteger('IncompleteTolerance', 14);
        // Datenlücke zu Jahresbeginn bis zu diesem Tag ignorieren (z. B. Produktionsstart 06.01.2026)
        $this->RegisterPropertyString('IgnoreGapUntil', '{"year":2026,"month":1,"day":6}');

        $this->RegisterAttributeString('Cache', '{}');
        $this->RegisterAttributeString('Result', '{}');

        $this->RegisterTimer('Update', 0, 'BNS_Update($_IPS[\'TARGET\']);');

        // Kachel-Visualisierung über HTML-SDK
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        if (!IPS_VariableProfileExists(self::PROFILE)) {
            IPS_CreateVariableProfile(self::PROFILE, VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileDigits(self::PROFILE, 0);
            IPS_SetVariableProfileText(self::PROFILE, '', ' h/a');
            IPS_SetVariableProfileIcon(self::PROFILE, 'Clock');
        }

        $sources = $this->getSources();
        $currentYear = (int) date('Y');
        foreach ($sources as $i => $src) {
            $this->MaintainVariable('H' . $i, $src['Name'] . ' – Benutzungsstunden ' . ($currentYear - 1), VARIABLETYPE_FLOAT, self::PROFILE, $i * 10 + 1, true);
            $this->MaintainVariable('HC' . $i, $src['Name'] . ' – Benutzungsstunden lfd. Jahr (bisher)', VARIABLETYPE_FLOAT, self::PROFILE, $i * 10 + 2, true);
        }
        for ($i = count($sources); $i < self::MAX_SOURCES; $i++) {
            $this->MaintainVariable('H' . $i, '', VARIABLETYPE_FLOAT, '', 0, false);
            $this->MaintainVariable('HC' . $i, '', VARIABLETYPE_FLOAT, '', 0, false);
        }

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);

        if (count($sources) === 0) {
            $this->SetTimerInterval('Update', 0);
            $this->SetStatus(104);
            return;
        }

        // Status sofort setzen; die (ggf. lange) Berechnung läuft danach portionsweise über den Timer
        $this->SetStatus(102);
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->SetTimerInterval('Update', 1000);
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELMESSAGE && $Data[0] === KR_READY && count($this->getSources()) > 0) {
            $this->SetTimerInterval('Update', 1000);
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Refresh':
                $this->Update();
                break;
            case 'RecalculateAll':
                $this->RecalculateAll();
                break;
            default:
                throw new Exception('Ungültiger Ident: ' . $Ident);
        }
    }

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        $initial = '<script>handleMessage(' . json_encode($this->ReadAttributeString('Result')) . ');</script>';
        return $html . $initial;
    }

    /** Löscht den Cache und berechnet alle Jahre neu (portionsweise im Hintergrund). */
    public function RecalculateAll()
    {
        $this->WriteAttributeString('Cache', '{}');
        $this->Update();
    }

    /**
     * Berechnet alle Kennzahlen und aktualisiert Variablen + Kachel.
     * Pro Aufruf wird höchstens TIME_BUDGET Sekunden gerechnet. Abgeschlossene Monate werden
     * gespeichert; ist noch etwas offen, startet der Timer den nächsten Durchlauf nach 2 s.
     */
    public function Update()
    {
        $sem = 'BNS_Update_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($sem, 0)) {
            return; // läuft bereits
        }
        try {
            $this->doUpdate();
        } finally {
            IPS_SemaphoreLeave($sem);
        }
    }

    private function doUpdate(): void
    {
        $sources = $this->getSources();
        if (count($sources) === 0) {
            $this->SetTimerInterval('Update', 0);
            $this->SetStatus(104);
            return;
        }

        $archiveIDs = IPS_GetInstanceListByModuleID(self::ARCHIVE_GUID);
        if (count($archiveIDs) === 0) {
            $this->SetStatus(201);
            return;
        }
        $archiveID = $archiveIDs[0];

        $deadline = microtime(true) + self::TIME_BUDGET;
        $pending = 0;

        $cache = json_decode($this->ReadAttributeString('Cache'), true) ?: [];
        $newCache = [];

        $currentYear = (int) date('Y');
        // fortlaufend ab Startjahr: 2025, 2026, 2027 …
        $startYear = max(2000, min($currentYear, $this->ReadPropertyInteger('StartYear')));
        $years = range($startYear, $currentYear);
        $hasError = false;
        $resultSources = [];

        foreach ($sources as $i => $src) {
            $key = $this->sourceKey($src);
            $entry = [
                'name'   => $src['Name'],
                'color'  => $this->colorFor($src, $i),
                'mode'   => (int) $src['PeakMode'],
                'meters' => count($src['Meters']),
                'powers' => count($src['PowerVars']),
                'parts'  => $this->partLabels((int) $src['PeakMode'] === 1 ? $src['PowerVars'] : $src['Meters']),
                'error'  => '',
                'years'  => []
            ];

            $error = $this->validateSource($archiveID, $src);
            if ($error !== '') {
                $entry['error'] = $error;
                $this->SendDebug($src['Name'], $error, 0);
                $this->LogMessage('Benutzungsstunden – ' . $src['Name'] . ': ' . $error, KL_WARNING);
                $hasError = true;
                $resultSources[] = $entry;
                continue;
            }

            $months = is_array($cache[$key] ?? null) ? $cache[$key] : [];
            $byYear = [];
            // laufendes Jahr zuerst, dann rückwärts
            foreach (array_reverse($years) as $year) {
                $byYear[$year] = $this->calculateYear($archiveID, $src, $year, $months, $deadline, $pending);
            }
            // nur Monate im gewählten Zeitraum behalten
            $first = sprintf('%04d-01', $years[0]);
            $newCache[$key] = array_filter($months, function ($k) use ($first) {
                return strcmp((string) $k, $first) >= 0;
            }, ARRAY_FILTER_USE_KEY);

            foreach ($years as $year) {
                $data = $byYear[$year];
                if (!empty($data['busy'])) {
                    $entry['years'][] = ['year' => $year, 'busy' => true];
                    continue;
                }
                if ($data['energy'] > 0 || $year === $currentYear) {
                    $entry['years'][] = array_merge(['year' => $year], $data);
                }
                if ($year === $currentYear - 1 && empty($data['incomplete'])) {
                    $this->SetValue('H' . $i, round((float) $data['hours'], 1));
                }
                if ($year === $currentYear) {
                    $this->SetValue('HC' . $i, round((float) $data['hours'], 1));
                }
            }

            $resultSources[] = $entry;
        }

        $this->WriteAttributeString('Cache', json_encode($newCache));

        $result = [
            'showForecast' => $this->ReadPropertyBoolean('ShowForecast'),
            'currentYear'  => $currentYear,
            'years'        => $years,
            'updated'      => time(),
            'pending'      => $pending,
            'sources'      => $resultSources
        ];
        $json = json_encode($result);
        $this->WriteAttributeString('Result', $json);
        $this->UpdateVisualizationValue($json);

        $this->SendDebug('Update', sprintf('Fertig, noch offene Monate: %d', $pending), 0);
        $this->SetStatus($hasError ? 202 : 102);

        if ($pending > 0) {
            $this->SetTimerInterval('Update', 2000);
        } else {
            $this->SetTimerInterval('Update', max(0, $this->ReadPropertyInteger('UpdateInterval')) * 60 * 1000);
        }
    }

    // ------------------------------------------------------------------ Berechnung

    /**
     * Jahreswerte aus Monatswerten. Abgeschlossene Monate kommen aus dem Cache ($months),
     * fehlende werden berechnet, solange das Zeitbudget reicht.
     * Liefert ['busy' => true], wenn noch Monate fehlen.
     */
    private function calculateYear(int $archiveID, array $src, int $year, array &$months, float $deadline, int &$pending): array
    {
        $start = mktime(0, 0, 0, 1, 1, $year);
        $yearEnd = mktime(0, 0, 0, 1, 1, $year + 1);
        $now = time();
        $end = min($yearEnd, $now);
        $final = $yearEnd <= $now;

        $hoursInYear = ($yearEnd - $start) / 3600;   // 8760 bzw. 8784
        $elapsedHours = max(0, ($end - $start) / 3600);

        $energy = 0.0;
        $peak = 0.0;
        $peakTime = 0;
        $resolution = '';
        $ignored = 0;
        $complete = true;
        $monthList = [];
        $peakParts = [];

        for ($m = 1; $m <= 12; $m++) {
            $mStart = mktime(0, 0, 0, $m, 1, $year);
            if ($mStart >= $end) {
                break;
            }
            $mNext = mktime(0, 0, 0, $m + 1, 1, $year);
            $monthDone = $mNext <= $now;
            $id = sprintf('%04d-%02d', $year, $m);

            if ($monthDone && isset($months[$id])) {
                $d = $months[$id];
            } else {
                if (microtime(true) > $deadline) {
                    $pending++;
                    $complete = false;
                    continue;
                }
                $d = $this->calculateMonth($archiveID, $src, $mStart, min($mNext, $end));
                if ($monthDone) {
                    $months[$id] = $d;
                }
            }

            $energy += $d['e'];
            $ignored += $d['i'];
            $monthList[] = [
                'm' => $m,
                'e' => round($d['e'], 1),
                'p' => round($d['p'], 1),
                't' => $d['t'],
                'h' => $d['p'] > 0 ? round($d['e'] / $d['p'], 1) : 0,
                'd' => true,
                'pc' => $d['pc'] ?? []
            ];
            if ($d['p'] > $peak) {
                $peak = $d['p'];
                $peakTime = $d['t'];
                $resolution = $d['r'];
                $peakParts = $d['pc'] ?? [];
            }
        }

        if (!$complete) {
            return ['busy' => true];
        }

        if ((int) $src['PeakMode'] === 2) {
            $peak = (float) $src['NominalPower'];
            $peakTime = 0;
            $resolution = 'Nennleistung';
            $peakParts = [];
        }
        if ($peakTime > 0 && count($peakParts) > 1) {
            $this->SendDebug('Spitze ' . $year, sprintf('%.1f kW am %s = %s kW', $peak, date('d.m.Y H:i', $peakTime), implode(' + ', $peakParts)), 0);
        }

        $hours = $peak > 0 ? $energy / $peak : 0.0;

        // Unvollständig: Logging mindestens eines Zählers begann erst mehr als einen Tag nach Jahresbeginn.
        // Maßgeblich ist der Logging-Beginn, nicht der erste Verbrauch (Standby ohne Zähleränderung zählt als Daten).
        $dataStart = $this->ReadPropertyBoolean('DetectIncomplete') ? $this->loggingStartInYear($archiveID, $src['Meters'], $start, $end) : null;
        $ignoreUntil = $this->ignoreGapUntil();
        if ($dataStart !== null && $ignoreUntil >= $start && $ignoreUntil < $yearEnd && $dataStart < $ignoreUntil + 86400) {
            $dataStart = null;   // bekannte Lücke (z. B. vor Produktionsstart) – Jahr gilt als vollständig
        }
        // Kleine Lücken am Jahresanfang (z. B. Stillstand ohne Archivwerte) bis zur Toleranz nur als Hinweis
        $toleranceSec = max(0, $this->ReadPropertyInteger('IncompleteTolerance')) * 86400;
        $incomplete = $dataStart !== null && $dataStart > $start + max(86400, $toleranceSec);
        if ($incomplete) {
            foreach ($monthList as &$ml) {
                $ml['d'] = mktime(0, 0, 0, $ml['m'] + 1, 1, $year) > $dataStart && mktime(0, 0, 0, $ml['m'], 1, $year) + 86400 >= $dataStart;
            }
            unset($ml);
        }
        $coveredHours = max(0, ($end - max($start, $dataStart ?? $start)) / 3600);

        // Hochrechnung (laufendes Jahr bzw. unvollständiges Jahr) auf Basis der Stunden mit Daten, frühestens nach 14 Tagen
        $forecastEnergy = null;
        $forecastHours = null;
        if ((!$final || $incomplete) && $coveredHours >= 14 * 24 && $peak > 0) {
            $forecastEnergy = $energy * $hoursInYear / $coveredHours;
            $forecastHours = $forecastEnergy / $peak;
        }

        return [
            'busy'           => false,
            'energy'         => round($energy, 3),
            'peak'           => round($peak, 3),
            'peakTime'       => $peakTime,
            'peakParts'      => $peakParts,
            'hours'          => round($hours, 1),
            'hoursInYear'    => $hoursInYear,
            'elapsedHours'   => round($elapsedHours, 1),
            'utilization'    => $hoursInYear > 0 ? round($hours / $hoursInYear, 4) : 0,
            'forecastEnergy' => $forecastEnergy !== null ? round($forecastEnergy, 1) : null,
            'forecastHours'  => $forecastHours !== null ? round($forecastHours, 1) : null,
            'resolution'     => $resolution,
            'ignored'        => $ignored,
            'final'          => $final,
            'dataStart'      => $dataStart ?? 0,
            'incomplete'     => $incomplete,
            'coveredHours'   => round($coveredHours, 1),
            'months'         => $monthList
        ];
    }

    /**
     * Werte eines Monats: e = Energie kWh, p = Spitze kW, t = Zeitpunkt, r = Auflösung, i = ignorierte Intervalle
     */
    private function calculateMonth(int $archiveID, array $src, int $from, int $to): array
    {
        $mode = (int) $src['PeakMode'];
        $ef = (float) $src['EnergyFactor'];

        if ($mode === 0) {
            [$e, $p, $t, $r, $i, $f, $pc] = $this->energyAndPeakFromCounters($archiveID, $src['Meters'], $ef, (float) $src['PeakLimit'], $from, $to);
            return ['e' => $e, 'p' => $p, 't' => $t, 'r' => $r, 'i' => $i, 'f' => $f, 'pc' => $pc];
        }

        [$e, $f] = $this->energyFromCounters($archiveID, $src['Meters'], $ef, $from, $to);
        if ($mode === 1) {
            [$p, $t, $r, $i, $pc] = $this->peakFromPowerVariables($archiveID, $src['PowerVars'], (float) $src['PowerFactor'], (float) $src['PeakLimit'], $from, $to);
            return ['e' => $e, 'p' => $p, 't' => $t, 'r' => $r, 'i' => $i, 'f' => $f, 'pc' => $pc];
        }
        return ['e' => $e, 'p' => 0.0, 't' => 0, 'r' => 'Nennleistung', 'i' => 0, 'f' => $f, 'pc' => []];
    }

    /**
     * Summe des Verbrauchs aller Zähler über Tagesaggregate (Avg = Verbrauch im Intervall).
     * @return array [Energie kWh, Datenbeginn (Zeitpunkt ab dem alle Zähler Daten haben) oder null]
     */
    private function energyFromCounters(int $archiveID, array $meters, float $factor, int $start, int $end): array
    {
        $sum = 0.0;
        $first = [];
        foreach ($meters as $varID) {
            foreach ($this->getAggregated($archiveID, $varID, self::AGG_DAY, $start, $end) as $row) {
                $sum += (float) $row['Avg'];
                $ts = (int) $row['TimeStamp'];
                $first[$varID] = min($first[$varID] ?? PHP_INT_MAX, $ts);
            }
        }
        return [$sum * $factor, $this->coverageStart($first, $meters)];
    }

    /**
     * Beginn des Loggings im Jahr: Für jeden Zähler wird geprüft, ob es vor Jahresbeginn bereits einen
     * geloggten Wert gibt. Wenn ja, deckt das Logging das ganze Jahr ab. Wenn nein, gilt der erste Tag
     * mit Archivdaten im Jahr. Ergebnis ist der späteste Beginn aller Zähler (null = ganzes Jahr abgedeckt
     * bzw. nicht bestimmbar).
     */
    private function loggingStartInYear(int $archiveID, array $meters, int $start, int $end): ?int
    {
        $latest = null;
        foreach ($meters as $varID) {
            $before = @AC_GetLoggedValues($archiveID, $varID, 0, $start - 1, 1);
            if (is_array($before) && count($before) > 0) {
                continue;   // Logging lief schon vor Jahresbeginn
            }
            $firstMonth = null;
            foreach ($this->getAggregated($archiveID, $varID, self::AGG_MONTH, $start, $end) as $row) {
                $firstMonth = min($firstMonth ?? PHP_INT_MAX, (int) $row['TimeStamp']);
            }
            if ($firstMonth === null) {
                continue;   // im Jahr keine Daten – nicht bestimmbar, keine Meldung
            }
            $monthEnd = mktime(0, 0, 0, (int) date('n', $firstMonth) + 1, 1, (int) date('Y', $firstMonth));
            $firstDay = null;
            foreach ($this->getAggregated($archiveID, $varID, self::AGG_DAY, $firstMonth, min($monthEnd, $end)) as $row) {
                $firstDay = min($firstDay ?? PHP_INT_MAX, (int) $row['TimeStamp']);
            }
            $first = $firstDay ?? $firstMonth;
            $latest = $latest === null ? $first : max($latest, $first);
        }
        return $latest;
    }

    /** Tag aus der Einstellung „Datenlücke ignorieren bis“ (0 = nicht gesetzt). */
    private function ignoreGapUntil(): int
    {
        $d = json_decode($this->ReadPropertyString('IgnoreGapUntil'), true);
        if (!is_array($d) || (int) ($d['year'] ?? 0) < 2000) {
            return 0;
        }
        return mktime(0, 0, 0, (int) $d['month'], (int) $d['day'], (int) $d['year']);
    }

    /** Anteile der einzelnen Variablen (kW) im Intervall der Spitze, in Reihenfolge der Konfiguration. */
    private function partsAt(array $per, array $ids, ?int $bucket, float $toKW): array
    {
        if ($bucket === null) {
            return [];
        }
        $out = [];
        foreach ($ids as $id) {
            $out[] = round(($per[$id][$bucket] ?? 0.0) * $toKW, 1);
        }
        return $out;
    }

    /** Lesbare Namen der Variablen für die Aufteilung (bei gleichen Namen wird der Name des übergeordneten Objekts verwendet). */
    private function partLabels(array $ids): array
    {
        $names = array_map(function ($id) {
            return IPS_VariableExists($id) ? IPS_GetName($id) : ('#' . $id);
        }, $ids);
        if (count(array_unique($names)) < count($names)) {
            $names = array_map(function ($id) {
                if (!IPS_VariableExists($id)) {
                    return '#' . $id;
                }
                $parent = IPS_GetParent($id);
                return $parent > 0 ? IPS_GetName($parent) : IPS_GetName($id);
            }, $ids);
        }
        if (count(array_unique($names)) < count($names)) {
            foreach ($names as $i => $n) {
                $names[$i] = $n . ' (#' . $ids[$i] . ')';
            }
        }
        return array_values($names);
    }

    /** Zeitpunkt, ab dem alle Zähler Daten liefern (null, wenn ein Zähler im Zeitraum gar keine Daten hat). */
    private function coverageStart(array $first, array $meters): ?int
    {
        if (count($first) < count($meters)) {
            return null;
        }
        return max($first);
    }

    /**
     * Energie und höchster 15-min-Mittelwert der Summenleistung aller Zähler (Zeitraum max. 1 Monat).
     * Die 5-min-Aggregate jedes Zählers werden je Viertelstunde addiert, die Spitze ist
     * damit die zeitgleiche Summe (nicht die Summe der Einzelspitzen).
     * Fallback auf Stundenaggregate, falls keine 5-min-Daten vorhanden sind.
     * Viertelstunden über der Plausibilitätsgrenze [kW] werden bei der Spitze übersprungen.
     *
     * @return array [Energie kWh, Spitze kW, Zeitpunkt, Auflösung, Anzahl ignorierter Intervalle, Datenbeginn|null, Anteile kW je Zähler]
     */
    private function energyAndPeakFromCounters(int $archiveID, array $meters, float $factor, float $limitKW, int $start, int $end): array
    {
        $buckets = [];
        $per = [];              // Werte je Zähler und Intervall – für die Aufteilung der Spitze
        $energy = 0.0;
        $found = false;
        $first = [];

        foreach ($meters as $varID) {
            foreach ($this->getAggregated($archiveID, $varID, self::AGG_5MIN, $start, $end) as $row) {
                $found = true;
                $first[$varID] = min($first[$varID] ?? PHP_INT_MAX, (int) $row['TimeStamp']);
                $v = (float) $row['Avg'];
                $energy += $v;
                $b = intdiv((int) $row['TimeStamp'], 900);
                $buckets[$b] = ($buckets[$b] ?? 0.0) + $v;
                $per[$varID][$b] = ($per[$varID][$b] ?? 0.0) + $v;
            }
        }
        $resolution = '15 min';
        $bucketLen = 900;
        $toKW = 4 * $factor;           // kWh je 15 min → kW

        if (!$found) {
            // Fallback: Stundenwerte
            $resolution = '60 min';
            $bucketLen = 3600;
            $toKW = $factor;
            foreach ($meters as $varID) {
                foreach ($this->getAggregated($archiveID, $varID, self::AGG_HOUR, $start, $end) as $row) {
                    $first[$varID] = min($first[$varID] ?? PHP_INT_MAX, (int) $row['TimeStamp']);
                    $v = (float) $row['Avg'];
                    $energy += $v;
                    $b = intdiv((int) $row['TimeStamp'], 3600);
                    $buckets[$b] = ($buckets[$b] ?? 0.0) + $v;
                    $per[$varID][$b] = ($per[$varID][$b] ?? 0.0) + $v;
                }
            }
        }

        $peak = 0.0;
        $peakTime = 0;
        $peakB = null;
        $ignored = 0;
        foreach ($buckets as $b => $raw) {
            $kw = $raw * $toKW;
            if ($limitKW > 0 && $kw > $limitKW) {
                $ignored++;
                $this->SendDebug('Plausibilität', sprintf('%s: %.1f kW > %.1f kW ignoriert', date('d.m.Y H:i', $b * $bucketLen), $kw, $limitKW), 0);
                continue;
            }
            if ($kw > $peak) {
                $peak = $kw;
                $peakTime = $b * $bucketLen;
                $peakB = $b;
            }
        }
        $parts = $this->partsAt($per, $meters, $peakB, $toKW);
        return [$energy * $factor, $peak, $peakTime, $resolution, $ignored, $this->coverageStart($first, $meters), $parts];
    }

    /**
     * Höchste zeitgleiche Summenleistung mehrerer Leistungsvariablen (z. B. 5 Trafos).
     * Je Variable werden die 1-min-Mittelwerte gelesen und je Minute addiert – so entsteht die echte
     * Summenspitze, nicht die Summe der Einzelspitzen. Fehlen 1-min-Aggregate, wird auf 5-min- bzw.
     * Stundenmittel ausgewichen. Werte über der Plausibilitätsgrenze [kW] werden übersprungen.
     *
     * @return array [Spitze kW, Zeitpunkt, Auflösung, Anzahl ignorierter Intervalle, Anteile kW je Variable]
     */
    private function peakFromPowerVariables(int $archiveID, array $vars, float $factor, float $limitKW, int $start, int $end): array
    {
        foreach ([self::AGG_1MIN => [60, '1 min'], self::AGG_5MIN => [300, '5 min'], self::AGG_HOUR => [3600, '60 min']] as $level => [$len, $label]) {
            $sum = [];
            $per = [];
            $found = false;
            // 1-min-Werte in 6-Tage-Blöcken abfragen (Abfragelimit)
            $chunk = 31 * 86400;   // Aufteilung für Minutenwerte übernimmt getAggregated()
            foreach ($vars as $varID) {
                for ($from = $start; $from < $end; $from += $chunk) {
                    foreach ($this->getAggregated($archiveID, $varID, $level, $from, min($from + $chunk, $end)) as $row) {
                        $found = true;
                        $b = intdiv((int) $row['TimeStamp'], $len);
                        $sum[$b] = ($sum[$b] ?? 0.0) + (float) $row['Avg'];
                        $per[$varID][$b] = ($per[$varID][$b] ?? 0.0) + (float) $row['Avg'];
                    }
                }
            }
            if (!$found) {
                continue;
            }
            $peak = 0.0;
            $peakTime = 0;
            $peakB = null;
            $ignored = 0;
            foreach ($sum as $b => $v) {
                $kw = $v * $factor;
                if ($limitKW > 0 && $kw > $limitKW) {
                    $ignored++;
                    $this->SendDebug('Plausibilität', sprintf('%s: %.1f kW > %.1f kW ignoriert', date('d.m.Y H:i', $b * $len), $kw, $limitKW), 0);
                    continue;
                }
                if ($kw > $peak) {
                    $peak = $kw;
                    $peakTime = $b * $len;
                    $peakB = $b;
                }
            }
            return [$peak, $peakTime, $label, $ignored, $this->partsAt($per, $vars, $peakB, $factor)];
        }
        return [0.0, 0, '', 0, []];
    }

    /**
     * Aggregierte Werte lesen. 1- und 5-min-Werte berechnet Symcon bei jeder Abfrage aus den Rohdaten
     * und liest dafür höchstens 200.000 Rohwerte – bei dichtem Logging scheitern daher große Zeiträume.
     * Minutenwerte werden deshalb tageweise abgefragt; scheitert eine Abfrage trotzdem, wird der
     * Zeitraum halbiert (bis min. 1 h).
     */
    private function getAggregated(int $archiveID, int $varID, int $level, int $start, int $end): array
    {
        if ($end <= $start) {
            return [];
        }
        if ($level === self::AGG_1MIN || $level === self::AGG_5MIN) {
            $step = $level === self::AGG_1MIN ? 6 * 3600 : 86400;
            $out = [];
            for ($a = $start; $a < $end; $a += $step) {
                $out = array_merge($out, $this->aggregatedSplit($archiveID, $varID, $level, $a, min($a + $step, $end)));
            }
            return $out;
        }
        $rows = @AC_GetAggregatedValues($archiveID, $varID, $level, $start, $end - 1, 0);
        return is_array($rows) ? $rows : [];
    }

    private function aggregatedSplit(int $archiveID, int $varID, int $level, int $start, int $end): array
    {
        $rows = @AC_GetAggregatedValues($archiveID, $varID, $level, $start, $end - 1, 0);
        if (is_array($rows)) {
            return $rows;
        }
        if ($end - $start <= 3600) {
            $this->SendDebug('Archiv', sprintf('#%d: Minutenwerte %s nicht abrufbar', $varID, date('d.m.Y H:i', $start)), 0);
            return [];
        }
        $mid = $start + intdiv($end - $start, 2);
        return array_merge($this->aggregatedSplit($archiveID, $varID, $level, $start, $mid),
                           $this->aggregatedSplit($archiveID, $varID, $level, $mid, $end));
    }

    // ------------------------------------------------------------------ Hilfsfunktionen

    private function getSources(): array
    {
        $list = json_decode($this->ReadPropertyString('Sources'), true);
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $row) {
            $out[] = [
                'Name'           => trim((string) ($row['Name'] ?? '')) ?: 'Energie',
                'Meters'         => $this->collectMeters($row),
                'EnergyFactor'   => (float) (($row['EnergyFactor'] ?? 1) ?: 1),
                'PeakMode'       => (int) ($row['PeakMode'] ?? 0),
                'PowerVars'      => $this->collectIds($row, ['PowerVariable', 'PowerVariable2', 'PowerVariable3', 'PowerVariable4', 'PowerVariable5']),
                'PowerFactor'    => (float) (($row['PowerFactor'] ?? 1) ?: 1),
                'NominalPower'   => (float) ($row['NominalPower'] ?? 0),
                'PeakLimit'      => (float) ($row['PeakLimit'] ?? 0),
                'Color'          => (int) ($row['Color'] ?? -1)
            ];
        }
        return $out;
    }

    /** Zähler 1–5 einer Zeile, ohne leere Felder und doppelte Einträge. */
    private function collectMeters(array $row): array
    {
        return $this->collectIds($row, ['EnergyVariable', 'EnergyVariable2', 'EnergyVariable3', 'EnergyVariable4', 'EnergyVariable5']);
    }

    private function collectIds(array $row, array $cols): array
    {
        $ids = [];
        foreach ($cols as $col) {
            $id = (int) ($row[$col] ?? 0);
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    private function validateSource(int $archiveID, array $src): string
    {
        if (count($src['Meters']) === 0) {
            return 'Keine gültige Energievariable gewählt.';
        }
        foreach ($src['Meters'] as $n => $ev) {
            $label = count($src['Meters']) > 1 ? 'Zähler ' . ($n + 1) . ' (#' . $ev . ')' : 'Energievariable';
            if (!IPS_VariableExists($ev)) {
                return $label . ' existiert nicht.';
            }
            if (!AC_GetLoggingStatus($archiveID, $ev)) {
                return $label . ' wird nicht geloggt.';
            }
            if (AC_GetAggregationType($archiveID, $ev) !== 1) {
                return $label . ' muss mit Aggregationstyp „Zähler“ geloggt werden.';
            }
        }
        if ($src['PeakMode'] === 1) {
            if (count($src['PowerVars']) === 0) {
                return 'Bezugsleistung „Leistungsmessung“ gewählt, aber keine Leistungsvariable angegeben.';
            }
            foreach ($src['PowerVars'] as $n => $pv) {
                $label = count($src['PowerVars']) > 1 ? 'Leistung ' . ($n + 1) . ' (#' . $pv . ')' : 'Leistungsvariable';
                if (!IPS_VariableExists($pv)) {
                    return $label . ' existiert nicht.';
                }
                if (!AC_GetLoggingStatus($archiveID, $pv)) {
                    return $label . ' wird nicht geloggt.';
                }
                if (AC_GetAggregationType($archiveID, $pv) !== 0) {
                    return $label . ' muss mit Aggregationstyp „Standard“ geloggt werden.';
                }
            }
        }
        if ($src['PeakMode'] === 2 && $src['NominalPower'] <= 0) {
            return 'Bezugsleistung „Nennleistung“ gewählt, aber keine Nennleistung > 0 kW eingetragen.';
        }
        return '';
    }

    /** Schlüssel für den Cache – ändert sich die Konfiguration einer Messstelle, wird neu berechnet. */
    private function sourceKey(array $src): string
    {
        return md5('v5|' . implode('|', [
            implode(',', $src['Meters']), $src['EnergyFactor'], $src['PeakMode'],
            implode(',', $src['PowerVars']), $src['PowerFactor'], $src['NominalPower'], $src['PeakLimit']
        ]));
    }

    private function colorFor(array $src, int $index): string
    {
        if ($src['Color'] >= 0) {
            return sprintf('#%06x', $src['Color']);
        }
        return self::DEFAULT_COLORS[$index % count(self::DEFAULT_COLORS)];
    }
}
