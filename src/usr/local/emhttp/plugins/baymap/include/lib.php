<?php
// Baymap data layer.
// Reads only what Unraid already keeps (disks.ini, devs.ini and the SMART reports Unraid saves
// each time a disk is awake). It never sends a command to a disk. The only exceptions are the
// Locate and Benchmark buttons, which run only when clicked (see action.php).

const BM_CFG_DIR = '/boot/config/plugins/baymap';
const BM_DL_DIR  = '/boot/config/plugins/disklocation';

function bm_path($p) { return (getenv('BAYMAP_ROOT') ?: '') . $p; }   // test prefix
function bm_ini($file) { $f = bm_path($file); return is_file($f) ? (@parse_ini_file($f, true) ?: []) : []; }
function bm_json_read($file, $default) {
    $f = bm_path($file);
    if (!is_file($f)) return $default;
    $d = json_decode((string)file_get_contents($f), true);
    return is_array($d) ? $d : $default;
}
function bm_json_write($file, $data) {
    $f = bm_path($file);
    @mkdir(dirname($f), 0755, true);
    $tmp = "$f.tmp";
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    return rename($tmp, $f);
}
function bm_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

// ---------- config ----------
function bm_default_colors() {   // same palette as Disk Location's defaults
    return ['parity' => '#AA2D2F', 'data' => '#BE7317', 'pool' => '#5B7845', 'other' => '#7C7C7C', 'empty' => '#303030'];
}
function bm_layout() {
    $l = bm_json_read(BM_CFG_DIR . '/layout.json', []);
    $l += ['groups' => [], 'trays' => [], 'colors' => []];
    $l['colors'] += bm_default_colors();
    return $l;
}
function bm_save_layout($l) { return bm_json_write(BM_CFG_DIR . '/layout.json', $l); }
function bm_records() { return bm_json_read(BM_CFG_DIR . '/records.json', []); }
function bm_save_records($r) { return bm_json_write(BM_CFG_DIR . '/records.json', $r); }

// ---------- devices ----------
// Serial = the part of Unraid's id after the last underscore (id is "MODEL_SERIAL").
function bm_serial_from_id($id) { $id = (string)$id; $p = strrpos($id, '_'); return $p === false ? $id : substr($id, $p + 1); }
function bm_model_from_id($id) { $id = (string)$id; $p = strrpos($id, '_'); return str_replace('_', ' ', $p === false ? $id : substr($id, 0, $p)); }

function bm_devices() {
    $out = [];
    foreach (bm_ini('/var/local/emhttp/disks.ini') as $sec => $d) {
        $dev = $d['device'] ?? '';
        if ($dev === '' || ($d['type'] ?? '') === 'Flash' || ($d['name'] ?? '') === 'flash') continue;
        $type = $d['type'] ?? '';
        $role = $type === 'Parity' ? 'parity' : ($type === 'Data' ? 'data' : 'pool');
        $out[] = bm_device_entry($d['name'] ?? $sec, $dev, $d['id'] ?? '', $role, $d);
    }
    foreach (bm_ini('/var/local/emhttp/devs.ini') as $sec => $d) {   // unassigned devices
        $dev = $d['device'] ?? '';
        if ($dev === '') continue;
        $out[] = bm_device_entry($d['name'] ?? $sec, $dev, $d['id'] ?? $sec, 'other', $d);
    }
    return $out;
}

function bm_device_entry($name, $dev, $id, $role, $d) {
    $smart = bm_smart($name);
    $asleep = ($d['spundown'] ?? '0') === '1';
    $t = $d['temp'] ?? '*';
    $temp = is_numeric($t) ? (int)$t : null;
    $tempNote = 'now';
    if ($temp === null && $smart['temp'] !== null) { $temp = $smart['temp']; $tempNote = 'last known'; }
    $rot = $d['rotational'] ?? '';
    $kind = str_starts_with($dev, 'nvme') ? 'NVMe' : ($rot === '0' ? 'SSD' : ($rot === '1' ? 'HDD' : ''));
    return [
        'name' => $name, 'device' => $dev, 'id' => $id,
        'serial' => bm_serial_from_id($id), 'model' => bm_model_from_id($id),
        'role' => $role, 'kind' => $kind,
        'size_bytes' => isset($d['size']) && is_numeric($d['size']) ? (int)$d['size'] * 1024 : null,
        'asleep' => $asleep, 'temp' => $temp, 'temp_note' => $tempNote,
        'color' => $d['color'] ?? '', 'errors' => (int)($d['numErrors'] ?? 0),
        'smart' => $smart, 'health' => bm_health($smart, $d),
    ];
}

// ---------- SMART (from Unraid's saved report, never from the disk) ----------
function bm_smart($name) {
    $f = bm_path("/var/local/emhttp/smart/$name");
    $r = ['as_of' => null, 'attrs' => [], 'nvme' => [], 'temp' => null, 'poh' => null, 'wear_used' => null];
    if (!is_file($f)) return $r;
    $r['as_of'] = filemtime($f);
    foreach (file($f, FILE_IGNORE_NEW_LINES) as $l) {
        if (preg_match('/^\s*(\d+)\s+(\S+)\s+0x[0-9a-fA-F]+\s+(\d+)\s+(\d+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(.*)$/', $l, $m)) {
            $r['attrs'][(int)$m[1]] = ['name' => $m[2], 'value' => (int)$m[3], 'worst' => (int)$m[4], 'thresh' => $m[5],
                'failed' => $m[8], 'raw' => trim($m[9]), 'rawn' => (int)preg_replace('/\D.*$/', '', trim($m[9]))];
        } elseif (preg_match('/^([A-Z][A-Za-z0-9 \/\-]+?):\s+(.+)$/', $l, $m) && !str_starts_with($l, 'Copyright')) {
            $r['nvme'][trim($m[1])] = trim($m[2]);
        }
    }
    $a = $r['attrs']; $n = $r['nvme'];
    foreach ([194, 190] as $id) if (isset($a[$id])) { $r['temp'] = $a[$id]['rawn']; break; }
    if ($r['temp'] === null && isset($n['Temperature'])) $r['temp'] = (int)$n['Temperature'];
    if (isset($a[9])) $r['poh'] = $a[9]['rawn'];
    elseif (isset($n['Power On Hours'])) $r['poh'] = (int)str_replace(',', '', $n['Power On Hours']);
    if (isset($n['Percentage Used'])) $r['wear_used'] = (int)$n['Percentage Used'];
    elseif (isset($a[202])) $r['wear_used'] = $a[202]['rawn'];                        // Crucial: raw = % used
    else foreach ([177, 233, 231, 169] as $id) if (isset($a[$id])) { $r['wear_used'] = max(0, 100 - $a[$id]['value']); break; }
    return $r;
}

// level: ok | info | warn | fail, with reasons
function bm_health($s, $d) {
    $lvl = 0; $why = [];
    $bump = function ($l, $txt) use (&$lvl, &$why) { $lvl = max($lvl, $l); $why[] = $txt; };
    $a = $s['attrs']; $n = $s['nvme'];
    foreach ($a as $id => $x) if ($x['failed'] !== '-' && $x['failed'] !== '') $bump(3, "SMART attribute $id {$x['name']} failed ({$x['failed']})");
    foreach ([5 => 'reallocated sectors', 197 => 'pending sectors', 198 => 'uncorrectable sectors', 187 => 'reported uncorrectable errors'] as $id => $txt)
        if (isset($a[$id]) && $a[$id]['rawn'] > 0) $bump(2, "{$a[$id]['rawn']} $txt");
    if (isset($a[199]) && $a[199]['rawn'] > 0) $bump(1, "{$a[199]['rawn']} CRC errors (cable or connector)");
    if (isset($n['Critical Warning']) && !preg_match('/^0x0+$/', $n['Critical Warning'])) $bump(3, "NVMe critical warning {$n['Critical Warning']}");
    if (isset($n['Media and Data Integrity Errors']) && (int)$n['Media and Data Integrity Errors'] > 0) $bump(2, "{$n['Media and Data Integrity Errors']} NVMe media errors");
    if ($s['wear_used'] !== null && $s['wear_used'] >= 100) $bump(2, "rated SSD life used up ({$s['wear_used']}%)");
    elseif ($s['wear_used'] !== null && $s['wear_used'] >= 90) $bump(1, "SSD life {$s['wear_used']}% used");
    if ((int)($d['numErrors'] ?? 0) > 0) $bump(2, "{$d['numErrors']} read/write errors (Unraid)");
    $c = $d['color'] ?? '';
    if (str_starts_with($c, 'red')) $bump(3, 'disabled or failed in Unraid');
    elseif (str_starts_with($c, 'yellow')) $bump(2, 'Unraid shows a warning (emulated or being rebuilt)');
    if ($s['as_of'] === null) $bump(1, 'no SMART report from Unraid yet (appears after the disk is next awake)');
    return ['level' => ['ok', 'info', 'warn', 'fail'][$lvl], 'why' => $why];
}

// ---------- formatting ----------
function bm_size($b) {
    if (!$b) return '';
    foreach (['TB' => 1e12, 'GB' => 1e9] as $u => $f) if ($b >= $f) { $v = $b / $f; return ($v >= 10 ? round($v) : round($v, 1)) . " $u"; }
    return round($b / 1e6) . ' MB';
}
function bm_ago($t) {
    if (!$t) return 'never';
    $s = time() - $t;
    if ($s < 90) return 'just now';
    if ($s < 5400) return round($s / 60) . ' min ago';
    if ($s < 172800) return round($s / 3600) . ' h ago';
    return round($s / 86400) . ' days ago';
}
function bm_years($h) { return $h === null ? '' : number_format($h) . ' h (' . round($h / 8766, 1) . ' y)'; }

// ---------- trays ----------
// Group defaults for fields added after the first release.
function bm_group($g) {
    return $g + ['order' => 'row', 'corner' => 'tl', 'orient' => 'h', 'place' => 'below', 'blank' => [], 'start' => 1, 'color' => ''];
}
// Tray number -> [row, col] (1-based): counting along rows or down columns, starting from a corner.
function bm_tray_pos($g, $tray) {
    $g = bm_group($g);
    $cols = max(1, (int)$g['cols']); $rows = max(1, (int)$g['rows']); $i = $tray - (int)$g['start'];
    [$r, $c] = $g['order'] === 'column' ? [$i % $rows + 1, intdiv($i, $rows) + 1] : [intdiv($i, $cols) + 1, $i % $cols + 1];
    if ($g['corner'][1] === 'r') $c = $cols + 1 - $c;
    if ($g['corner'][0] === 'b') $r = $rows + 1 - $r;
    return [$r, $c];
}
function bm_is_blank($g, $tray) { return in_array((int)$tray, array_map('intval', bm_group($g)['blank']), true); }
// Groups in display rows: a group set to "beside" joins the row of the group before it.
function bm_group_rows($groups) {
    $rows = [];
    foreach ($groups as $g) { $g = bm_group($g);
        if (!$rows || $g['place'] !== 'beside') $rows[] = [];
        $rows[count($rows) - 1][] = $g; }
    return $rows;
}
// "3, 7-9" -> [3,7,8,9]
function bm_parse_list($s) {
    $out = [];
    foreach (preg_split('/[\s,;]+/', (string)$s) as $p) {
        if (preg_match('/^(\d+)-(\d+)$/', $p, $m)) { for ($i = (int)$m[1]; $i <= min((int)$m[2], (int)$m[1] + 600); $i++) $out[] = $i; }
        elseif (ctype_digit($p)) $out[] = (int)$p;
    }
    return array_values(array_unique($out));
}

// ---------- import from Disk Location ----------
function bm_import_disklocation() {
    $groups = bm_json_read(BM_DL_DIR . '/groups.json', null);
    $locs = bm_json_read(BM_DL_DIR . '/locations.json', null);
    $devs = bm_json_read(BM_DL_DIR . '/devices.json', null);
    if (!$groups || !$locs || !$devs) return [false, 'Disk Location data not found in ' . BM_DL_DIR];
    $l = bm_layout(); $l['groups'] = []; $l['trays'] = [];
    foreach ($groups as $gid => $g) {
        $l['groups'][] = ['id' => (string)$gid, 'name' => $g['group_name'] ?? "Group $gid",
            'cols' => (int)($g['grid_columns'] ?? 4), 'rows' => (int)($g['grid_rows'] ?? 1), 'start' => (int)($g['tray_start_num'] ?? 1),
            'order' => ($g['grid_count'] ?? 'row') === 'column' ? 'column' : 'row', 'color' => !empty($g['group_color']) ? '#' . ltrim($g['group_color'], '#') : '',
            'orient' => ($g['disk_tray_direction'] ?? 'h') === 'v' ? 'v' : 'h', 'corner' => 'tl',
            'place' => (!$l['groups'] || ($g['tray_pos'] ?? 'left') === 'break') ? 'below' : 'beside', 'blank' => []];
    }
    $n = 0;
    foreach ($locs as $hash => $loc) {
        $serial = $devs[$hash]['smart_serialnumber'] ?? '';
        if ($serial === '' || empty($loc['groupid']) || empty($loc['tray'])) continue;
        $l['trays'][$serial] = ['group' => (string)$loc['groupid'], 'tray' => (int)$loc['tray']]; $n++;
    }
    $rec = bm_records(); $m = 0;
    foreach ($devs as $d) {
        $serial = $d['smart_serialnumber'] ?? ''; if ($serial === '') continue;
        $r = $rec[$serial] ?? [];
        foreach (['purchased' => 'purchased', 'manufactured' => 'manufactured', 'warranty' => 'warranty_months', 'comment' => 'comment', 'removed' => 'removed'] as $from => $to)
            if (!empty($d[$from]) && empty($r[$to])) $r[$to] = (string)$d[$from];
        if (!empty($d['model_name'])) $r['model_seen'] = $d['model_name'];
        if ($r) { $rec[$serial] = $r; $m++; }
    }
    bm_save_layout($l); bm_save_records($rec);
    return [true, count($l['groups']) . " groups, $n tray placements and $m disk records imported"];
}
