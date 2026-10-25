<?php
// Baymap actions (POST from the plugin pages; Unraid checks the csrf_token before this runs).
// Locate and Benchmark are the only actions that touch a disk, and only the one disk asked for.
require_once __DIR__ . '/lib.php';
header('Content-Type: application/json');

function out($ok, $msg, $extra = []) { echo json_encode(['ok' => $ok, 'msg' => $msg] + $extra); exit; }
function known_device($dev) {   // only devices Unraid lists; never a path from the request
    foreach (bm_devices() as $d) if ($d['device'] === $dev) return $d;
    return null;
}

$a = $_POST['action'] ?? '';
switch ($a) {
    case 'save_tray': {
        $serial = (string)($_POST['serial'] ?? ''); $group = (string)($_POST['group'] ?? ''); $tray = (int)($_POST['tray'] ?? 0);
        if ($serial === '') out(false, 'no serial');
        $l = bm_layout();
        if ($group === '' || $tray <= 0) { unset($l['trays'][$serial]); bm_save_layout($l); out(true, 'removed from map'); }
        $g = null; foreach ($l['groups'] as $x) if ($x['id'] === $group) $g = $x;
        if (!$g) out(false, 'unknown group');
        $max = (int)$g['start'] + (int)$g['cols'] * (int)$g['rows'] - 1;
        if ($tray < (int)$g['start'] || $tray > $max) out(false, "tray must be {$g['start']}-$max");
        if (bm_is_blank($g, $tray)) out(false, "tray $tray is set as blank in this group");
        foreach ($l['trays'] as $s => $t) if ($s !== $serial && $t['group'] === $group && (int)$t['tray'] === $tray) unset($l['trays'][$s]);   // previous occupant becomes unplaced
        $l['trays'][$serial] = ['group' => $group, 'tray' => $tray];
        bm_save_layout($l); out(true, "placed in {$g['name']} tray $tray");
    }
    case 'save_record': {
        $serial = (string)($_POST['serial'] ?? ''); if ($serial === '') out(false, 'no serial');
        $rec = bm_records(); $r = $rec[$serial] ?? [];
        foreach (['purchased', 'manufactured', 'warranty_end', 'comment'] as $k) {
            $v = trim((string)($_POST[$k] ?? ''));
            if ($k !== 'comment' && $v !== '' && !preg_match('/^\d{4}-\d{2}(-\d{2})?$/', $v)) out(false, "$k: use YYYY-MM-DD");
            if ($v === '') unset($r[$k]); else $r[$k] = mb_substr($v, 0, 500);
        }
        $rec[$serial] = $r; bm_save_records($rec); out(true, 'saved');
    }
    case 'save_groups': {
        $in = json_decode((string)($_POST['groups'] ?? ''), true);
        if (!is_array($in)) out(false, 'bad data');
        $groups = []; $ids = [];
        foreach ($in as $g) {
            $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($g['id'] ?? '')) ?: (string)(count($groups) + 1);
            while (in_array($id, $ids, true)) $id .= 'x';
            $ids[] = $id;
            $groups[] = ['id' => $id, 'name' => mb_substr(trim((string)($g['name'] ?? 'Group')), 0, 40) ?: 'Group',
                'cols' => max(1, min(24, (int)($g['cols'] ?? 1))), 'rows' => max(1, min(24, (int)($g['rows'] ?? 1))),
                'start' => max(0, (int)($g['start'] ?? 1)), 'order' => ($g['order'] ?? 'row') === 'column' ? 'column' : 'row',
                'color' => preg_match('/^#[0-9A-Fa-f]{6}$/', (string)($g['color'] ?? '')) ? $g['color'] : '',
                'orient' => ($g['orient'] ?? 'h') === 'v' ? 'v' : 'h',
                'place' => ($g['place'] ?? 'below') === 'beside' ? 'beside' : 'below',
                'corner' => in_array($g['corner'] ?? 'tl', ['tl', 'tr', 'bl', 'br'], true) ? $g['corner'] : 'tl',
                'blank' => bm_parse_list($g['blank'] ?? '')];
        }
        $l = bm_layout(); $l['groups'] = $groups;
        foreach ($l['trays'] as $s => $t) if (!in_array($t['group'], $ids, true)) unset($l['trays'][$s]);   // drop placements in deleted groups
        bm_save_layout($l); out(true, count($groups) . ' groups saved');
    }
    case 'import_dl': {
        [$ok, $msg] = bm_import_disklocation(); out($ok, $msg);
    }
    case 'locate': {
        $d = known_device((string)($_POST['device'] ?? '')); if (!$d) out(false, 'unknown device');
        $sec = max(5, min(120, (int)($_POST['seconds'] ?? 30)));
        // Read from the disk for a while so its activity light blinks. Read-only; wakes this one disk.
        $cmd = 'timeout ' . $sec . ' dd if=' . escapeshellarg('/dev/' . $d['device']) . ' of=/dev/null bs=1M iflag=direct status=none';
        exec('nohup setsid ' . $cmd . ' >/dev/null 2>&1 &');
        out(true, "{$d['name']} ({$d['device']}): activity light blinking for $sec s");
    }
    case 'benchmark': {
        $d = known_device((string)($_POST['device'] ?? '')); if (!$d) out(false, 'unknown device');
        // Sequential read speed. Read-only; wakes this one disk; takes about 10 seconds.
        $o = (string)shell_exec('timeout 60 hdparm -t --direct ' . escapeshellarg('/dev/' . $d['device']) . ' 2>&1');
        if (!preg_match('/=\s*([\d.]+)\s*MB\/sec/', $o, $m)) out(false, 'benchmark failed: ' . trim(substr($o, -200)));
        $mbs = (float)$m[1];
        $rec = bm_records(); $r = $rec[$d['serial']] ?? [];
        $r['bench'] = array_slice(array_merge($r['bench'] ?? [], [['date' => date('Y-m-d H:i'), 'mbs' => $mbs]]), -10);
        $rec[$d['serial']] = $r; bm_save_records($rec);
        out(true, "{$d['name']}: $mbs MB/s", ['mbs' => $mbs]);
    }
}
out(false, 'unknown action');
