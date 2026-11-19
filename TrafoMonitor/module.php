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
        $this->RegisterPropertyBoolean('InvertReactive', false);

        $this->RegisterTimer('Prepare', 0, 'TRM_Prepare($_IPS[\'TARGET\']);');

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

        // Zwischenspeicher verwerfen – Konfiguration hat sich geändert
        foreach (json_decode($this->GetBuffer('CacheKeys'), true) ?: [] as $k) {
            $this->SetBuffer($k, '');
        }
        $this->SetBuffer('CacheKeys', '[]');

        $trafos = $this->getTrafos();
        if (count($trafos) === 0) {
            $this->SetTimerInterval('Prepare', 0);
            $this->SetStatus(104);
            return;
        }
        // Startansicht jede Minute im Hintergrund vorbereiten, damit die Kachel sofort Daten hat
        $this->SetTimerInterval('Prepare', 60 * 1000);

        if ($this->ReadPropertyBoolean('Live')) {
            foreach ($trafos as $t) {
                foreach (['PowerVariable', 'ApparentVariable', 'ReactiveVariable'] as $col) {
                    if ($t[$col] > 0 && IPS_VariableExists($t[$col])) {
                        $this->RegisterMessage($t[$col], VM_UPDATE);
                    }
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
        $this->UpdateVisualizationValue($this->toJson(array_merge(['type' => 'live', 'ts' => time()], $this->liveAll())));
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Load':
                $req = json_decode((string) $Value, true) ?: [];
                $range = in_array($req['range'] ?? '', self::RANGES, true) ? $req['range'] : $this->defaultRange();
                $offset = max(-500, min(0, (int) ($req['offset'] ?? 0)));
                $reqId = (string) ($req['req'] ?? '');
                try {
                    $data = $this->getData($range, $offset, !empty($req['force']));
                    $data['req'] = $reqId;
                    $this->UpdateVisualizationValue($this->toJson($data));
                } catch (Throwable $e) {
                    $this->LogMessage('Trafo-Monitor: ' . $e->getMessage(), KL_ERROR);
                    $this->UpdateVisualizationValue($this->toJson(['type' => 'error', 'req' => $reqId, 'message' => $e->getMessage()]));
                }
                break;
            default:
                throw new Exception('Ungültiger Ident: ' . $Ident);
        }
    }

    /** Sendet die aktuelle Startansicht (neu berechnet) an alle offenen Kacheln. */
    public function Refresh()
    {
        $data = $this->getData($this->defaultRange(), 0, true);
        $data['req'] = 'push';
        $this->UpdateVisualizationValue($this->toJson($data));
    }

    /** Timer: Startansicht im Hintergrund vorbereiten (Zwischenspeicher). */
    public function Prepare()
    {
        try {
            $this->getData($this->defaultRange(), 0, true);
        } catch (Throwable $e) {
            $this->SendDebug('Prepare', $e->getMessage(), 0);
        }
    }

    /**
     * Liefert die Kachel sofort aus – ohne auf das Archiv zu warten. Liegt eine vorbereitete
     * Startansicht im Zwischenspeicher, wird sie mitgegeben; sonst lädt die Kachel selbst nach.
     */
    public function GetVisualizationTile()
    {
        $file = __DIR__ . '/module.html';
        if (!is_file($file)) {
            return '<div style="font:15px sans-serif;padding:4em 1.5em;color:#d93a3a">Trafo-Monitor: Die Datei <b>module.html</b> fehlt im Modulordner „TrafoMonitor“. Bitte im Repository ergänzen und das Modul aktualisieren.</div>';
        }
        $html = file_get_contents($file);
        $cached = $this->readCache($this->defaultRange(), 0, 86400);
        if ($cached === null) {
            return $html;
        }
        $cached = array_merge($cached, $this->liveFields());
        $cached['req'] = 'init';
        return $html . '<script>handleMessage(' . json_encode($this->toJson($cached)) . ');</script>';
    }

    // ------------------------------------------------------------------ Zwischenspeicher

    private function defaultRange(): string
    {
        return self::RANGES[$this->ReadPropertyInteger('DefaultRange')] ?? 'day';
    }

    /**
     * Daten aus dem Zwischenspeicher oder neu berechnen.
     * Laufender Zeitraum: max. 75 s alt · vergangene Zeiträume: max. 6 h alt.
     */
    private function getData(string $range, int $offset, bool $force = false): array
    {
        $maxAge = $offset === 0 ? 75 : 6 * 3600;
        $data = $force ? null : $this->readCache($range, $offset, $maxAge);
        if ($data === null) {
            $t = microtime(true);
            $data = $this->buildData($range, $offset);
            $data['built'] = time();
            $data['buildSeconds'] = round(microtime(true) - $t, 2);
            $this->SendDebug('Archiv', sprintf('%s/%d berechnet in %.2f s', $range, $offset, $data['buildSeconds']), 0);
            $this->writeCache($range, $offset, $data);
        }
        return array_merge($data, $this->liveFields());
    }

    private function readCache(string $range, int $offset, int $maxAge): ?array
    {
        $raw = $this->GetBuffer('C_' . $range . '_' . $offset);
        if ($raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || (int) ($data['built'] ?? 0) < time() - $maxAge) {
            return null;
        }
        return $data;
    }

    private function writeCache(string $range, int $offset, array $data): void
    {
        $key = 'C_' . $range . '_' . $offset;
        $keys = json_decode($this->GetBuffer('CacheKeys'), true) ?: [];
        $keys = array_values(array_diff($keys, [$key]));
        $keys[] = $key;
        while (count($keys) > 12) {                    // höchstens 12 Zeiträume merken
            $this->SetBuffer(array_shift($keys), '');
        }
        $this->SetBuffer('CacheKeys', json_encode($keys));
        $this->SetBuffer($key, $this->toJson($data));
    }

    /** Live-Werte werden nie zwischengespeichert. */
    private function liveFields(): array
    {
        $l = $this->liveAll();
        return ['live' => $l['values'], 'liveS' => $l['apparent'], 'liveQ' => $l['reactive'], 'now' => time()];
    }

    private function toJson(array $data): string
    {
        return (string) json_encode($data, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    // ------------------------------------------------------------------ Daten

    /** Aktuelle Werte je Trafo: Wirkleistung kW, Scheinleistung kVA, Blindleistung kvar (null = nicht konfiguriert). */
    private function liveAll(): array
    {
        $p = $s = $q = [];
        foreach ($this->getTrafos() as $t) {
            $p[] = $this->current($t['PowerVariable'], $t['PowerFactor']);
            $s[] = $this->current($t['ApparentVariable'], $t['ApparentFactor']);
            $qv = $this->current($t['ReactiveVariable'], $t['ReactiveFactor']);
            $q[] = $qv !== null && $this->ReadPropertyBoolean('InvertReactive') ? -$qv : $qv;
        }
        return ['values' => $p, 'apparent' => $s, 'reactive' => $q];
    }

    private function current(int $id, float $factor): ?float
    {
        if ($id <= 0 || !IPS_VariableExists($id)) {
            return null;
        }
        return round((float) GetValue($id) * $factor, 2);
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
            'live'     => null,
            'error'    => $archive === 0 ? 'Kein Archiv gefunden.' : ''
        ];

        $liveAll = $this->liveAll();
        $out['live'] = $liveAll['values'];
        $out['liveS'] = $liveAll['apparent'];
        $out['liveQ'] = $liveAll['reactive'];
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
                'meter'   => null,
                'hasS'    => $t['ApparentVariable'] > 0,
                'sline'   => [],     // [ts, kVA]
                'smax'    => null,
                'smaxTime' => 0,
                'savg'    => null,
                'pf'      => null,   // mittlerer Leistungsfaktor im Zeitraum (Ø P ÷ Ø S)
                'hasQ'    => $t['ReactiveVariable'] > 0,
                'qavg'    => null,   // mittlere Blindleistung mit Vorzeichen (kvar)
                'hasQE'   => $t['QEnergyVariable'] > 0 || $t['QEnergyCapVariable'] > 0,
                'qbars'   => [],     // [ts, kvarh induktiv]
                'qcbars'  => [],     // [ts, kvarh kapazitiv]
                'qenergy' => 0.0,
                'qcenergy' => 0.0
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

            // Scheinleistung (für Auslastung und cos φ)
            if ($t['ApparentVariable'] > 0) {
                $sSum = 0.0;
                $sn = 0;
                foreach ($this->agg($archive, $t['ApparentVariable'], $lineLevel, $start, $until) as $row) {
                    $ts = (int) $row['TimeStamp'];
                    $kva = round((float) $row['Avg'] * $t['ApparentFactor'], 2);
                    $entry['sline'][] = [$ts, $kva];
                    $sSum += $kva;
                    $sn++;
                    $mx = (float) $row['Max'] * $t['ApparentFactor'];
                    if ($entry['smax'] === null || $mx > $entry['smax']) {
                        $entry['smax'] = round($mx, 2);
                        $entry['smaxTime'] = (int) ($row['MaxTime'] ?? $ts);
                    }
                }
                usort($entry['sline'], function ($a, $b) {
                    return $a[0] <=> $b[0];
                });
                $entry['savg'] = $sn > 0 ? round($sSum / $sn, 2) : null;
                if ($entry['savg'] > 0 && $entry['avg'] !== null) {
                    $entry['pf'] = round(min(1, $entry['avg'] / $entry['savg']), 3);
                }
            }

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

            // mittlere Blindleistung mit Vorzeichen (für vektoriellen Gesamt-cos φ)
            if ($t['ReactiveVariable'] > 0) {
                $qs = 0.0;
                $qn = 0;
                foreach ($this->agg($archive, $t['ReactiveVariable'], $barLevel, $start, $until) as $row) {
                    $qs += (float) $row['Avg'] * $t['ReactiveFactor'];
                    $qn++;
                }
                if ($qn > 0) {
                    $entry['qavg'] = round(($this->ReadPropertyBoolean('InvertReactive') ? -1 : 1) * $qs / $qn, 2);
                }
            }
            // Blindarbeit je Balkenintervall
            foreach (['QEnergyVariable' => ['qbars', 'qenergy'], 'QEnergyCapVariable' => ['qcbars', 'qcenergy']] as $col => [$barsKey, $sumKey]) {
                if ($t[$col] > 0) {
                    foreach ($this->agg($archive, $t[$col], $barLevel, $start, $until) as $row) {
                        $kvarh = round((float) $row['Avg'] * $t['QEnergyFactor'], 2);
                        $entry[$barsKey][] = [(int) $row['TimeStamp'], $kvarh];
                        $entry[$sumKey] += $kvarh;
                    }
                    usort($entry[$barsKey], function ($a, $b) {
                        return $a[0] <=> $b[0];
                    });
                    $entry[$sumKey] = round($entry[$sumKey], 2);
                }
            }
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
        // 5-min-Werte berechnet Symcon aus den Rohdaten (max. 200.000 je Abfrage) – bei Bedarf halbieren
        $rows = @AC_GetAggregatedValues($archive, $varID, $level, $start, $end - 1, 0);
        if (!is_array($rows) && $level === self::AGG_5MIN && $end - $start > 3600) {
            $mid = $start + intdiv($end - $start, 2);
            return array_merge($this->agg($archive, $varID, $level, $start, $mid), $this->agg($archive, $varID, $level, $mid, $end));
        }
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
                'ApparentVariable' => (int) ($row['ApparentVariable'] ?? 0),
                'ApparentFactor' => (float) (($row['ApparentFactor'] ?? 0.001) ?: 0.001),
                'ReactiveVariable' => (int) ($row['ReactiveVariable'] ?? 0),
                'ReactiveFactor' => (float) (($row['ReactiveFactor'] ?? 0.001) ?: 0.001),
                'QEnergyVariable' => (int) ($row['QEnergyVariable'] ?? 0),
                'QEnergyCapVariable' => (int) ($row['QEnergyCapVariable'] ?? 0),
                'QEnergyFactor'  => (float) (($row['QEnergyFactor'] ?? 0.001) ?: 0.001),
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
        foreach (['ApparentVariable' => 'Scheinleistung', 'ReactiveVariable' => 'Blindleistung'] as $col => $label) {
            $id = $t[$col];
            if ($id > 0) {
                if (!IPS_VariableExists($id)) {
                    return $label . '-Variable existiert nicht.';
                }
                if (!AC_GetLoggingStatus($archive, $id)) {
                    return $label . ' wird nicht geloggt.';
                }
                if (AC_GetAggregationType($archive, $id) !== 0) {
                    return $label . ' muss mit Aggregationstyp „Standard“ geloggt werden.';
                }
            }
        }
        foreach (['QEnergyVariable' => 'Blindarbeit induktiv', 'QEnergyCapVariable' => 'Blindarbeit kapazitiv'] as $col => $label) {
            $id = $t[$col];
            if ($id > 0) {
                if (!IPS_VariableExists($id)) {
                    return $label . ': Variable existiert nicht.';
                }
                if (!AC_GetLoggingStatus($archive, $id)) {
                    return $label . ' wird nicht geloggt.';
                }
                if (AC_GetAggregationType($archive, $id) !== 1) {
                    return $label . ' muss mit Aggregationstyp „Zähler“ geloggt werden.';
                }
            }
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
