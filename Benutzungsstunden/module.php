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
 * Abgeschlossene Jahre werden zwischengespeichert, nur das laufende Jahr wird zyklisch neu berechnet.
 */
class Benutzungsstunden extends IPSModule
{
    private const ARCHIVE_GUID = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
    private const PROFILE = 'BNS.Hours';
    private const MAX_SOURCES = 50;

    // Aggregationsstufen AC_GetAggregatedValues
    private const AGG_HOUR = 0;
    private const AGG_DAY = 1;
    private const AGG_5MIN = 5;

    private const DEFAULT_COLORS = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Sources', '[]');
        $this->RegisterPropertyInteger('Years', 5);
        $this->RegisterPropertyInteger('UpdateInterval', 60);
        $this->RegisterPropertyInteger('Theme', 0);
        $this->RegisterPropertyBoolean('ShowForecast', true);

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

        $this->SetTimerInterval('Update', max(0, $this->ReadPropertyInteger('UpdateInterval')) * 60 * 1000);

        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->Update();
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELMESSAGE && $Data[0] === KR_READY) {
            $this->Update();
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

    /** Löscht den Cache und berechnet alle Jahre neu. */
    public function RecalculateAll()
    {
        $this->WriteAttributeString('Cache', '{}');
        $this->Update();
    }

    /** Berechnet alle Kennzahlen und aktualisiert Variablen + Kachel. */
    public function Update()
    {
        @set_time_limit(600);

        $sources = $this->getSources();
        if (count($sources) === 0) {
            $this->SetStatus(104);
            return;
        }

        $archiveIDs = IPS_GetInstanceListByModuleID(self::ARCHIVE_GUID);
        if (count($archiveIDs) === 0) {
            $this->SetStatus(201);
            return;
        }
        $archiveID = $archiveIDs[0];

        $cache = json_decode($this->ReadAttributeString('Cache'), true) ?: [];
        $newCache = [];

        $currentYear = (int) date('Y');
        $years = range($currentYear - max(1, $this->ReadPropertyInteger('Years')) + 1, $currentYear);
        $hasError = false;
        $resultSources = [];

        foreach ($sources as $i => $src) {
            $key = $this->sourceKey($src);
            $entry = [
                'name'  => $src['Name'],
                'color' => $this->colorFor($src, $i),
                'mode'  => (int) $src['PeakMode'],
                'meters' => count($src['Meters']),
                'error' => '',
                'years' => []
            ];

            $error = $this->validateSource($archiveID, $src);
            if ($error !== '') {
                $entry['error'] = $error;
                $this->SendDebug($src['Name'], $error, 0);
                $this->LogMessage($this->Translate('Benutzungsstunden') . ' – ' . $src['Name'] . ': ' . $error, KL_WARNING);
                $hasError = true;
                $resultSources[] = $entry;
                continue;
            }

            foreach ($years as $year) {
                $cached = $cache[$key][(string) $year] ?? null;
                if ($year < $currentYear && is_array($cached) && !empty($cached['final'])) {
                    $data = $cached;
                } else {
                    $data = $this->calculateYear($archiveID, $src, $year);
                }
                $newCache[$key][(string) $year] = $data;
                if ($data['energy'] > 0 || $year === $currentYear) {
                    $entry['years'][] = array_merge(['year' => $year], $data);
                }
            }

            // Variablen schreiben
            foreach ($entry['years'] as $y) {
                if ($y['year'] === $currentYear - 1) {
                    $this->SetValue('H' . $i, round((float) $y['hours'], 1));
                }
                if ($y['year'] === $currentYear) {
                    $this->SetValue('HC' . $i, round((float) $y['hours'], 1));
                }
            }

            $resultSources[] = $entry;
        }

        $this->WriteAttributeString('Cache', json_encode($newCache));

        $result = [
            'theme'        => $this->ReadPropertyInteger('Theme'),
            'showForecast' => $this->ReadPropertyBoolean('ShowForecast'),
            'currentYear'  => $currentYear,
            'years'        => $years,
            'updated'      => time(),
            'sources'      => $resultSources
        ];
        $json = json_encode($result);
        $this->WriteAttributeString('Result', $json);
        $this->UpdateVisualizationValue($json);

        $this->SetStatus($hasError ? 202 : 102);
    }

    // ------------------------------------------------------------------ Berechnung

    private function calculateYear(int $archiveID, array $src, int $year): array
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

        $mode = (int) $src['PeakMode'];
        $ef = (float) $src['EnergyFactor'];

        if ($mode === 0) {
            // Energie + 15-min-Spitze aus 5-min-Aggregaten des Zählers (monatsweise wegen Abfragelimit)
            [$energy, $peak, $peakTime, $resolution, $ignored] = $this->energyAndPeakFromCounters($archiveID, $src['Meters'], $ef, (float) $src['PeakLimit'], $start, $end);
        } else {
            $energy = $this->energyFromCounters($archiveID, $src['Meters'], $ef, $start, $end);
            if ($mode === 1) {
                [$peak, $peakTime] = $this->peakFromPowerVariable($archiveID, (int) $src['PowerVariable'], (float) $src['PowerFactor'], $start, $end);
                $resolution = 'Messwert';
            } else {
                $peak = (float) $src['NominalPower'];
                $resolution = 'Nennleistung';
            }
        }

        $hours = $peak > 0 ? $energy / $peak : 0.0;

        // Hochrechnung laufendes Jahr (frühestens nach 14 Tagen sinnvoll)
        $forecastEnergy = null;
        $forecastHours = null;
        if (!$final && $elapsedHours >= 14 * 24 && $peak > 0) {
            $forecastEnergy = $energy * $hoursInYear / $elapsedHours;
            $forecastHours = $forecastEnergy / $peak;
        }

        return [
            'energy'         => round($energy, 3),
            'peak'           => round($peak, 3),
            'peakTime'       => $peakTime,
            'hours'          => round($hours, 1),
            'hoursInYear'    => $hoursInYear,
            'elapsedHours'   => round($elapsedHours, 1),
            'utilization'    => $hoursInYear > 0 ? round($hours / $hoursInYear, 4) : 0,
            'forecastEnergy' => $forecastEnergy !== null ? round($forecastEnergy, 1) : null,
            'forecastHours'  => $forecastHours !== null ? round($forecastHours, 1) : null,
            'resolution'     => $resolution,
            'ignored'        => $ignored,
            'final'          => $final
        ];
    }

    /** Summe des Verbrauchs aller Zähler über Tagesaggregate (Avg = Verbrauch im Intervall). */
    private function energyFromCounters(int $archiveID, array $meters, float $factor, int $start, int $end): float
    {
        $sum = 0.0;
        foreach ($meters as $varID) {
            foreach ($this->getAggregated($archiveID, $varID, self::AGG_DAY, $start, $end) as $row) {
                $sum += (float) $row['Avg'];
            }
        }
        return $sum * $factor;
    }

    /**
     * Energie und höchster 15-min-Mittelwert der Summenleistung aller Zähler.
     * Die 5-min-Aggregate jedes Zählers werden je Viertelstunde addiert, die Spitze ist
     * damit die zeitgleiche Summe (nicht die Summe der Einzelspitzen).
     * Fallback auf Stundenaggregate, falls keine 5-min-Daten vorhanden sind.
     * Viertelstunden über der Plausibilitätsgrenze [kW] werden bei der Spitze übersprungen.
     *
     * @return array [Energie kWh, Spitze kW, Zeitpunkt, Auflösung, Anzahl ignorierter Intervalle]
     */
    private function energyAndPeakFromCounters(int $archiveID, array $meters, float $factor, float $limitKW, int $start, int $end): array
    {
        $buckets = [];
        $energy = 0.0;
        $found = false;

        foreach ($meters as $varID) {
            $month = (int) date('n', $start);
            $year = (int) date('Y', $start);
            $from = $start;
            while ($from < $end) {
                $month++;
                $to = min($end, mktime(0, 0, 0, $month, 1, $year));
                foreach ($this->getAggregated($archiveID, $varID, self::AGG_5MIN, $from, $to) as $row) {
                    $found = true;
                    $v = (float) $row['Avg'];
                    $energy += $v;
                    $b = intdiv((int) $row['TimeStamp'], 900);
                    $buckets[$b] = ($buckets[$b] ?? 0.0) + $v;
                }
                $from = $to;
            }
        }
        $resolution = '15 min';
        $toKW = 4 * $factor;           // kWh je 15 min → kW

        if (!$found) {
            // Fallback: Stundenwerte
            $resolution = '60 min';
            $toKW = $factor;
            foreach ($meters as $varID) {
                foreach ($this->getAggregated($archiveID, $varID, self::AGG_HOUR, $start, $end) as $row) {
                    $v = (float) $row['Avg'];
                    $energy += $v;
                    $b = intdiv((int) $row['TimeStamp'], 3600);
                    $buckets[$b] = ($buckets[$b] ?? 0.0) + $v;
                }
            }
        }

        $bucketLen = $resolution === '15 min' ? 900 : 3600;
        $peak = 0.0;
        $peakTime = 0;
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
            }
        }
        return [$energy * $factor, $peak, $peakTime, $resolution, $ignored];
    }

    /** Maximalwert einer Leistungsvariable (Max der Tagesaggregate = höchster Rohwert). */
    private function peakFromPowerVariable(int $archiveID, int $varID, float $factor, int $start, int $end): array
    {
        $peak = 0.0;
        $peakTime = 0;
        foreach ($this->getAggregated($archiveID, $varID, self::AGG_DAY, $start, $end) as $row) {
            $v = (float) $row['Max'];
            if ($v > $peak) {
                $peak = $v;
                $peakTime = (int) ($row['MaxTime'] ?? $row['TimeStamp']);
            }
        }
        return [$peak * $factor, $peakTime];
    }

    private function getAggregated(int $archiveID, int $varID, int $level, int $start, int $end): array
    {
        if ($end <= $start) {
            return [];
        }
        $rows = @AC_GetAggregatedValues($archiveID, $varID, $level, $start, $end - 1, 0);
        return is_array($rows) ? $rows : [];
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
                'PowerVariable'  => (int) ($row['PowerVariable'] ?? 0),
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
        $ids = [];
        foreach (['EnergyVariable', 'EnergyVariable2', 'EnergyVariable3', 'EnergyVariable4', 'EnergyVariable5'] as $col) {
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
            $pv = $src['PowerVariable'];
            if ($pv <= 0 || !IPS_VariableExists($pv)) {
                return 'Bezugsleistung „Leistungsvariable“ gewählt, aber keine gültige Leistungsvariable angegeben.';
            }
            if (!AC_GetLoggingStatus($archiveID, $pv)) {
                return 'Leistungsvariable wird nicht geloggt.';
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
        return md5(implode('|', [
            implode(',', $src['Meters']), $src['EnergyFactor'], $src['PeakMode'],
            $src['PowerVariable'], $src['PowerFactor'], $src['NominalPower'], $src['PeakLimit']
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
