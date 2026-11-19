<?php

declare(strict_types=1);

/**
 * Trafo-Monitor
 *
 * Wirkleistung (live + Verlauf) und Wirkarbeit mehrerer Trafos als Kachel für die Tile-Visualisierung.
 * Die Kachel fordert die Daten für den gewählten Zeitraum per requestAction('Load', …) an;
 * Live-Werte werden bei jeder Änderung einer Leistungsvariable gesendet (gedrosselt auf 1 × je Sekunde).
 * Alle Daten stammen ausschließlich aus dem lokalen Symcon-Archiv – keine Online-Abfragen.
 */
class TrafoMonitor extends IPSModule
{
    private const ARCHIVE_GUID = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';

    private const AGG_HOUR = 0;
    private const AGG_DAY = 1;
    private const AGG_MONTH = 3;
    private const AGG_5MIN = 5;

    private const RANGES = ['day', 'week', 'month', 'year'];
    private const DEFAULT_COLORS = ['#2a78d6', '#1baf7a', '#eda100', '#6250d6', '#e34948', '#008300', '#e87ba4', '#eb6834'];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Trafos', '[]');
        $this->RegisterPropertyInteger('DefaultRange', 0);
        $this->RegisterPropertyBoolean('ShowSum', true);
        $this->RegisterPropertyBoolean('Live', true);

        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // alte Registrierungen entfernen
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        $trafos = $this->getTrafos();
        if (count($trafos) === 0) {
            $this->SetStatus(104);
            return;
        }

        if ($this->ReadPropertyBoolean('Live')) {
            foreach ($trafos as $t) {
                if ($t['PowerVariable'] > 0 && IPS_VariableExists($t['PowerVariable'])) {
                    $this->RegisterMessage($t['PowerVariable'], VM_UPDATE);
                }
            }
        }

        $archive = $this->archiveID();
        if ($archive === 0) {
            $this->SetStatus(201);
            return;
        }
        $hasError = false;
        foreach ($trafos as $t) {
            if ($this->validate($archive, $t) !== '') {
                $hasError = true;
            }
        }
        $this->SetStatus($hasError ? 202 : 102);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message !== VM_UPDATE) {
            return;
        }
        // höchstens einmal pro Sekunde senden
        $now = microtime(true);
        $last = (float) $this->GetBuffer('LastLive');
        if ($now - $last < 1.0) {
            return;
        }
        $this->SetBuffer('LastLive', (string) $now);
        $this->UpdateVisualizationValue(json_encode(['type' => 'live', 'ts' => time(), 'values' => $this->liveValues()]));
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Load':
                $req = json_decode((string) $Value, true) ?: [];
                $range = in_array($req['range'] ?? '', self::RANGES, true) ? $req['range'] : 'day';
                $offset = max(-500, min(0, (int) ($req['offset'] ?? 0)));
                $data = $this->buildData($range, $offset);
                $data['req'] = (string) ($req['req'] ?? '');
                $this->UpdateVisualizationValue(json_encode($data));
                break;
            default:
                throw new Exception('Ungültiger Ident: ' . $Ident);
        }
    }

    /** Sendet die aktuelle Tagesansicht an alle offenen Kacheln. */
    public function Refresh()
    {
        $data = $this->buildData(self::RANGES[$this->ReadPropertyInteger('DefaultRange')] ?? 'day', 0);
        $data['req'] = 'push';
        $this->UpdateVisualizationValue(json_encode($data));
    }

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        $data = $this->buildData(self::RANGES[$this->ReadPropertyInteger('DefaultRange')] ?? 'day', 0);
        $data['req'] = 'init';
        return $html . '<script>handleMessage(' . json_encode(json_encode($data)) . ');</script>';
    }

    // ------------------------------------------------------------------ Daten

    private function liveValues(): array
    {
        $out = [];
        foreach ($this->getTrafos() as $t) {
            $v = null;
            if ($t['PowerVariable'] > 0 && IPS_VariableExists($t['PowerVariable'])) {
                $v = round((float) GetValue($t['PowerVariable']) * $t['PowerFactor'], 2);
            }
            $out[] = $v;
        }
        return $out;
    }

    /**
     * Zeitraum + Auflösungen:
     *   day   → Leistung 5 min,  Arbeit je Stunde
     *   week  → Leistung 1 h,    Arbeit je Tag
     *   month → Leistung 1 h,    Arbeit je Tag
     *   year  → Leistung 1 Tag,  Arbeit je Monat
     */
    private function period(string $range, int $offset): array
    {
        $now = time();
        $y = (int) date('Y', $now);
        $m = (int) date('n', $now);
        $d = (int) date('j', $now);
        switch ($range) {
            case 'week':
                $dow = (int) date('N', $now);
                $start = mktime(0, 0, 0, $m, $d - ($dow - 1) + 7 * $offset, $y);
                $end = mktime(0, 0, 0, (int) date('n', $start), (int) date('j', $start) + 7, (int) date('Y', $start));
                $label = 'KW ' . (int) date('W', $start) . ' · ' . date('d.m.', $start) . '–' . date('d.m.Y', $end - 1);
                return [$start, $end, $label, self::AGG_HOUR, 3600, self::AGG_DAY, 'day'];
            case 'month':
                $start = mktime(0, 0, 0, $m + $offset, 1, $y);
                $end = mktime(0, 0, 0, (int) date('n', $start) + 1, 1, (int) date('Y', $start));
                $months = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
                $label = $months[(int) date('n', $start) - 1] . ' ' . date('Y', $start);
                return [$start, $end, $label, self::AGG_HOUR, 3600, self::AGG_DAY, 'day'];
            case 'year':
                $start = mktime(0, 0, 0, 1, 1, $y + $offset);
                $end = mktime(0, 0, 0, 1, 1, $y + $offset + 1);
                return [$start, $end, date('Y', $start), self::AGG_DAY, 86400, self::AGG_MONTH, 'month'];
            default:
                $start = mktime(0, 0, 0, $m, $d + $offset, $y);
                $end = mktime(0, 0, 0, (int) date('n', $start), (int) date('j', $start) + 1, (int) date('Y', $start));
                $days = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
                $label = ($offset === 0 ? 'Heute, ' : $days[(int) date('w', $start)] . ', ') . date('d.m.Y', $start);
                return [$start, $end, $label, self::AGG_5MIN, 300, self::AGG_HOUR, 'hour'];
        }
    }

    private function buildData(string $range, int $offset): array
    {
        $trafos = $this->getTrafos();
        $archive = $this->archiveID();
        [$start, $end, $label, $lineLevel, $lineStep, $barLevel, $barUnit] = $this->period($range, $offset);
        $until = min($end, time());

        $out = [
            'type'     => 'data',
            'range'    => $range,
            'offset'   => $offset,
            'label'    => $label,
            'start'    => $start,
            'end'      => $end,
            'now'      => time(),
            'lineStep' => $lineStep,
            'barUnit'  => $barUnit,
            'showSum'  => $this->ReadPropertyBoolean('ShowSum'),
            'trafos'   => [],
            'live'     => $this->liveValues(),
            'error'    => $archive === 0 ? 'Kein Archiv gefunden.' : ''
        ];

        $sumLine = [];
        $barKeys = [];
        foreach ($trafos as $i => $t) {
            $entry = [
                'name'    => $t['Name'],
                'color'   => $t['Color'] >= 0 ? sprintf('#%06x', $t['Color']) : self::DEFAULT_COLORS[$i % count(self::DEFAULT_COLORS)],
                'nominal' => $t['Nominal'],
                'error'   => $archive ? $this->validate($archive, $t) : '',
                'line'    => [],     // [ts, kW]
                'bars'    => [],     // [ts, kWh]
                'max'     => null,
                'maxTime' => 0,
                'avg'     => null,
                'energy'  => 0.0,
                'energyFromPower' => false,
                'meter'   => null
            ];
            if ($archive === 0 || $entry['error'] !== '' || $until <= $start) {
                $out['trafos'][] = $entry;
                continue;
            }

            // Leistungsverlauf
            $sumAvg = 0.0;
            $n = 0;
            foreach ($this->agg($archive, $t['PowerVariable'], $lineLevel, $start, $until) as $row) {
                $ts = (int) $row['TimeStamp'];
                $kw = round((float) $row['Avg'] * $t['PowerFactor'], 2);
                $entry['line'][] = [$ts, $kw];
                $sumLine[$ts] = ($sumLine[$ts] ?? 0.0) + $kw;
                $sumAvg += $kw;
                $n++;
                $mx = (float) $row['Max'] * $t['PowerFactor'];
                if ($entry['max'] === null || $mx > $entry['max']) {
                    $entry['max'] = round($mx, 2);
                    $entry['maxTime'] = (int) ($row['MaxTime'] ?? $ts);
                }
            }
            usort($entry['line'], function ($a, $b) {
                return $a[0] <=> $b[0];
            });
            $entry['avg'] = $n > 0 ? round($sumAvg / $n, 2) : null;

            // Wirkarbeit je Balkenintervall – aus Zähler oder aus der Leistung integriert
            if ($t['EnergyVariable'] > 0) {
                foreach ($this->agg($archive, $t['EnergyVariable'], $barLevel, $start, $until) as $row) {
                    $ts = (int) $row['TimeStamp'];
                    $kwh = round((float) $row['Avg'] * $t['EnergyFactor'], 2);
                    $entry['bars'][] = [$ts, $kwh];
                    $entry['energy'] += $kwh;
                    $barKeys[$ts] = true;
                }
                $entry['meter'] = IPS_VariableExists($t['EnergyVariable']) ? round((float) GetValue($t['EnergyVariable']) * $t['EnergyFactor'], 1) : null;
            } else {
                $entry['energyFromPower'] = true;
                foreach ($this->agg($archive, $t['PowerVariable'], $barLevel, $start, $until) as $row) {
                    $ts = (int) $row['TimeStamp'];
                    $hours = ($this->barEnd($ts, $barUnit) - $ts) / 3600;
                    if ($ts + $hours * 3600 > $until) {
                        $hours = max(0, ($until - $ts) / 3600);
                    }
                    $kwh = round((float) $row['Avg'] * $t['PowerFactor'] * $hours, 2);
                    $entry['bars'][] = [$ts, $kwh];
                    $entry['energy'] += $kwh;
                    $barKeys[$ts] = true;
                }
            }
            usort($entry['bars'], function ($a, $b) {
                return $a[0] <=> $b[0];
            });
            $entry['energy'] = round($entry['energy'], 2);
            $out['trafos'][] = $entry;
        }

        // Summe: höchste zeitgleiche Summe in der Auflösung des Verlaufs
        ksort($sumLine);
        $peak = null;
        $peakTime = 0;
        foreach ($sumLine as $ts => $v) {
            if ($peak === null || $v > $peak) {
                $peak = $v;
                $peakTime = $ts;
            }
        }
        $out['sum'] = [
            'line'     => array_map(null, array_keys($sumLine), array_map(function ($v) {
                return round($v, 2);
            }, array_values($sumLine))),
            'peak'     => $peak !== null ? round($peak, 2) : null,
            'peakTime' => $peakTime
        ];
        $out['barTimes'] = array_keys($barKeys);
        sort($out['barTimes']);
        return $out;
    }

    private function barEnd(int $ts, string $unit): int
    {
        switch ($unit) {
            case 'hour':
                return $ts + 3600;
            case 'day':
                return mktime(0, 0, 0, (int) date('n', $ts), (int) date('j', $ts) + 1, (int) date('Y', $ts));
            default:
                return mktime(0, 0, 0, (int) date('n', $ts) + 1, 1, (int) date('Y', $ts));
        }
    }

    private function agg(int $archive, int $varID, int $level, int $start, int $end): array
    {
        if ($varID <= 0 || $end <= $start) {
            return [];
        }
        $rows = @AC_GetAggregatedValues($archive, $varID, $level, $start, $end - 1, 0);
        return is_array($rows) ? $rows : [];
    }

    // ------------------------------------------------------------------ Hilfsfunktionen

    private function archiveID(): int
    {
        $ids = IPS_GetInstanceListByModuleID(self::ARCHIVE_GUID);
        return count($ids) > 0 ? $ids[0] : 0;
    }

    private function getTrafos(): array
    {
        $list = json_decode($this->ReadPropertyString('Trafos'), true);
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $i => $row) {
            $out[] = [
                'Name'           => trim((string) ($row['Name'] ?? '')) ?: ('Trafo ' . ($i + 1)),
                'PowerVariable'  => (int) ($row['PowerVariable'] ?? 0),
                'PowerFactor'    => (float) (($row['PowerFactor'] ?? 1) ?: 1),
                'EnergyVariable' => (int) ($row['EnergyVariable'] ?? 0),
                'EnergyFactor'   => (float) (($row['EnergyFactor'] ?? 1) ?: 1),
                'Nominal'        => (float) ($row['Nominal'] ?? 0),
                'Color'          => (int) ($row['Color'] ?? -1)
            ];
        }
        return $out;
    }

    private function validate(int $archive, array $t): string
    {
        $pv = $t['PowerVariable'];
        if ($pv <= 0 || !IPS_VariableExists($pv)) {
            return 'Keine gültige Wirkleistungs-Variable gewählt.';
        }
        if (!AC_GetLoggingStatus($archive, $pv)) {
            return 'Wirkleistung wird nicht geloggt.';
        }
        if (AC_GetAggregationType($archive, $pv) !== 0) {
            return 'Wirkleistung muss mit Aggregationstyp „Standard“ geloggt werden.';
        }
        $ev = $t['EnergyVariable'];
        if ($ev > 0) {
            if (!IPS_VariableExists($ev)) {
                return 'Wirkarbeits-Variable existiert nicht.';
            }
            if (!AC_GetLoggingStatus($archive, $ev)) {
                return 'Wirkarbeit wird nicht geloggt.';
            }
            if (AC_GetAggregationType($archive, $ev) !== 1) {
                return 'Wirkarbeit muss mit Aggregationstyp „Zähler“ geloggt werden.';
            }
        }
        return '';
    }
}
