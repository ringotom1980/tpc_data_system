<?php
// Public/modules/mat/m_upload_handler.php
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/////////////////////////////
// Bootstrap & Dependencies
/////////////////////////////
require_once __DIR__ . '/../../../config/auth.php';
require_login();

require_once __DIR__ . '/../../../config/db_connection.php'; // 提供 PDO $conn

// ===== Autoload（依你的實際結構優先找 Public/vendor，再找根目錄 vendor）=====
$autoloads = [
    __DIR__ . '/../../vendor/autoload.php',   // tpc_data_system/Public/vendor/autoload.php
    __DIR__ . '/../../../vendor/autoload.php' // tpc_data_system/vendor/autoload.php
];
$loaded = false;
foreach ($autoloads as $p) {
    if (is_file($p)) {
        require_once $p;
        $loaded = true;
        break;
    }
}
// 後備：若你自有 Autoloader（放 Public/ 或根目錄）
if (!$loaded) {
    $alts = [
        __DIR__ . '/../../Autoloader.php',    // tpc_data_system/Public/Autoloader.php
        __DIR__ . '/../../../Autoloader.php', // tpc_data_system/Autoloader.php
    ];
    foreach ($alts as $p) {
        if (is_file($p)) {
            require_once $p;
            $loaded = true;
            break;
        }
    }
}
if (!$loaded) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '找不到 PhpSpreadsheet autoload。請確認 Public/vendor/ 或 專案根目錄/vendor/ 存在 autoload.php。'], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

/////////////////////////////
// Utilities
/////////////////////////////
function jsend_success(array $data = []): void
{
    echo json_encode(array_merge(['success' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}
function jsend_error(string $message, int $http = 400, array $extra = []): void
{
    http_response_code($http);
    echo json_encode(array_merge(['success' => false, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}
function _trim($v): string
{
    return trim((string)$v);
}
function _n($v): float
{
    return is_numeric($v) ? (float)$v : 0.0;
}
function tmp_token_path(string $token): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mat_' . preg_replace('/[^a-f0-9]/i', '', $token) . '.json';
}
function now_ts(): string
{
    return date('Y-m-d H:i:s');
}

// 將憑證編號/領料批號做正規化（去空白含全形）
function normalize_docno(string $s): string
{
    return preg_replace('/[\x{00A0}\x{3000}\s]+/u', '', trim($s));
}

// 檢查資料表是否存在某欄位（用 INFORMATION_SCHEMA；可綁參數，不會踩 1064）
function table_has_column(PDO $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '|' . $column;
    if (isset($cache[$key])) return $cache[$key];
    $sql = "
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = :t
          AND COLUMN_NAME  = :c
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':t' => $table, ':c' => $column]);
    $cache[$key] = (bool)$stmt->fetchColumn();
    return $cache[$key];
}

// 文字標頭清理：去空白（含全形）、去括號、全形數字轉半形
function clean_header(string $s): string
{
    $s = preg_replace('/[\x{00A0}\x{3000}\s]+/u', '', $s);
    $s = preg_replace('/[()（）]/u', '', $s);
    // 全形數字 -> 半形
    $s = preg_replace_callback('/[０-９]/u', fn($m) => (string)(mb_ord($m[0], 'UTF-8') - 65296), $s);
    return $s;
}

/////////////////////////////
// Header alias maps（同義詞）
/////////////////////////////
function aliases_LKW(): array
{
    return [
        'voucher'         => ['領料批號'],
        'material_number' => ['材料編號'],
        'material_name'   => ['材料名稱','材料名稱(中文)'],
        'collar_New'      => ['新料數量','供料數量(新料)'],
        'collar_Old'      => ['舊料數量','供料數量(舊料)'],
    ];
}
function aliases_T(): array
{
    return [
        'voucher'               => ['憑證批號'],
        'rm_mat_no'             => ['拆除原材料編號'],
        'rm_mat_name'           => ['拆除原材料名稱'],
        'recede_Old'            => ['拆除良數量', '舊料數量'],
        'material_number'       => ['材料編號'],
        'material_name'         => ['材料名稱及規範'],
        'scrap'                 => ['廢料數量', '廢料'],
        'footprint'             => ['下腳數量', '下腳'],
    ];
}
function aliases_S(): array
{
    return [
        'voucher'         => ['憑證編號','料單'],
        'material_number' => ['材料編號'],
        'material_name'   => ['材料名稱及規範','材料名稱(中文)'],
        'recede_New'      => ['新料','供料數量(新料)'],
        'recede_Old'      => ['舊料','供料數量(舊料)'],
        'scrap'           => ['廢料','廢料數量'],
        'footprint'       => ['下腳','下腳數量'],
    ];
}

// 把原始標題文字映射成「標準鍵」
function match_header_key(string $raw, array $aliasMap): ?string
{
    $raw = clean_header($raw);
    if ($raw === '') return null;
    foreach ($aliasMap as $key => $arr) {
        foreach ($arr as $alias) {
            if ($raw === clean_header($alias)) return $key;
        }
    }
    return null;
}

/////////////////////////////
// Header scanners（跨工作表、支援多列表頭）
/////////////////////////////
function scan_sheet_for_aliases(Worksheet $ws, array $aliasMap): array
{
    $found = ['headers' => [], 'hrow' => null, 'score' => 0];

    foreach ($ws->getRowIterator() as $row) {
        $mapThisRow = [];
        $iter = $row->getCellIterator();
        $iter->setIterateOnlyExistingCells(false);
        foreach ($iter as $idx => $cell) {
            $k = match_header_key((string)$cell->getValue(), $aliasMap);
            if ($k) $mapThisRow[$idx] = $k;
        }
        if ($mapThisRow) {
            $found['headers'] = $mapThisRow;
            $found['hrow'] = $row->getRowIndex();

            // 再掃 2 列，補足分裂表頭
            $maxRow = min($ws->getHighestRow(), $found['hrow'] + 2);
            for ($r = $found['hrow'] + 1; $r <= $maxRow; $r++) {
                $iter2 = $ws->getRowIterator($r, $r)->current()->getCellIterator();
                $iter2->setIterateOnlyExistingCells(false);
                foreach ($iter2 as $idx2 => $cell2) {
                    if (isset($found['headers'][$idx2])) continue;
                    $k2 = match_header_key((string)$cell2->getValue(), $aliasMap);
                    if ($k2) $found['headers'][$idx2] = $k2;
                }
            }

            $keys = array_unique(array_values($found['headers']));
            $found['score'] = count($keys);
            break;
        }
    }
    return $found;
}

/////////////////////////////
// Parsers（直接在定位到的 Worksheet 上解析）
/////////////////////////////
function parse_LKW(Worksheet $ws, array $headers, int $hrow, string $voucherPrefix, string $docFallback): array
{
    $rows = [];
    $seq = 1;
    foreach ($ws->getRowIterator($hrow + 1) as $row) {
        $iter = $row->getCellIterator();
        $iter->setIterateOnlyExistingCells(false);
        $r = [];
        $docno = '';
        foreach ($iter as $idx => $cell) {
            if (!isset($headers[$idx])) continue;
            $k = $headers[$idx];
            $v = _trim((string)$cell->getValue());
            switch ($k) {
                case 'voucher':
                    $docno = normalize_docno($v);
                    break; // 領料批號
                case 'material_number':
                    $r['material_number'] = $v;
                    break;
                case 'material_name':
                    $r['material_name']   = $v;
                    break;
                case 'collar_New':
                    $r['collar_New']      = _n($v);
                    break;
                case 'collar_Old':
                    $r['collar_Old']      = _n($v);
                    break;
            }
        }
        if (!empty($r['material_number'])) {
            $doc = $docno !== '' ? $docno : $docFallback; // 沒批號才用日期兜底
            $r['voucher'] = $voucherPrefix . '_' . $doc . '_' . ($seq++); // 承攬商_批號_序號
            $rows[] = $r;
        }
    }
    return $rows;
}
function parse_T(Worksheet $ws, array $headers, int $hrow, string $voucherPrefix, string $docFallback): array
{
    $rows = [];
    $seq = 1;
    foreach ($ws->getRowIterator($hrow + 1) as $row) {
        $iter = $row->getCellIterator();
        $iter->setIterateOnlyExistingCells(false);
        $tmp = [];
        $docno = '';
        foreach ($iter as $idx => $cell) {
            if (!isset($headers[$idx])) continue;
            $k = $headers[$idx];
            $v = _trim((string)$cell->getValue());
            if ($k === 'voucher') $docno = normalize_docno($v); // 憑證批號
            if (in_array($k, ['recede_Old', 'scrap', 'footprint'], true)) $tmp[$k] = _n($v);
            else $tmp[$k] = $v;
        }
        $doc = $docno !== '' ? $docno : $docFallback;

        // (1) 拆除良 -> recede_Old（舊料）
        $qty = (float)($tmp['recede_Old'] ?? 0);
        if ($qty != 0.0) {
            $rows[] = [
                'voucher'         => $voucherPrefix . '_' . $doc . '_' . ($seq++),
                'material_number' => (string)($tmp['rm_mat_no']   ?? ''),
                'material_name'   => (string)($tmp['rm_mat_name'] ?? ''),
                'recede_Old'      => $qty,
            ];
        }
        // (2) 廢料 / 下腳
        $scrap = (float)($tmp['scrap']     ?? 0);
        $foot  = (float)($tmp['footprint'] ?? 0);
        if ($scrap != 0.0 || $foot != 0.0) {
            $rows[] = [
                'voucher'         => $voucherPrefix . '_' . $doc . '_' . ($seq++),
                'material_number' => (string)($tmp['material_number'] ?? ''),
                'material_name'   => (string)($tmp['material_name']   ?? ''),
                'scrap'           => $scrap,
                'footprint'       => $foot,
            ];
        }
    }
    return $rows;
}
function parse_S(Worksheet $ws, array $headers, int $hrow, string $voucherPrefix, string $docFallback): array
{
    $rows = [];
    $seq = 1;
    foreach ($ws->getRowIterator($hrow + 1) as $row) {
        $iter = $row->getCellIterator();
        $iter->setIterateOnlyExistingCells(false);
        $r = [];
        $has = false;
        $docno = '';
        foreach ($iter as $idx => $cell) {
            if (!isset($headers[$idx])) continue;
            $k = $headers[$idx];
            $v = _trim((string)$cell->getValue());
            switch ($k) {
                case 'voucher':
                    $docno = normalize_docno($v);
                    $has = true;
                    break; // 憑證編號
                case 'material_number':
                    $r['material_number'] = $v;
                    $has = true;
                    break;
                case 'material_name':
                    $r['material_name']   = $v;
                    break;
                case 'recede_New':
                    $r['recede_New']      = _n($v);
                    break;
                case 'recede_Old':
                    $r['recede_Old']      = _n($v);
                    break;
                case 'scrap':
                    $r['scrap']           = _n($v);
                    break;
                case 'footprint':
                    $r['footprint']       = _n($v);
                    break;
            }
        }
        if ($has && !empty($r['material_number'])) {
            $doc = $docno !== '' ? $docno : $docFallback;
            $r['voucher'] = $voucherPrefix . '_' . $doc . '_' . ($seq++);
            $rows[] = $r;
        }
    }
    return $rows;
}

/////////////////////////////
// Detect & Parse（單次載入活頁簿，跨表偵測）
/////////////////////////////
function detect_type_and_parse(string $fileTmpPath, string $voucherPrefix, string $docFallback, array &$debug = []): array
{
    $ss = IOFactory::load($fileTmpPath);

    $candidates = []; // ['type','ws','headers','hrow','score','order']
    $sheetOrder = 0;

    foreach ($ss->getWorksheetIterator() as $ws) {
        // L/K/W
        $lkw = scan_sheet_for_aliases($ws, aliases_LKW());
        if ($lkw['hrow'] !== null) {
            $keys = array_unique(array_values($lkw['headers']));
            if (in_array('material_number', $keys, true) && (in_array('collar_New', $keys, true) || in_array('collar_Old', $keys, true))) {
                $candidates[] = ['type' => 'LKW', 'ws' => $ws, 'headers' => $lkw['headers'], 'hrow' => $lkw['hrow'], 'score' => $lkw['score'], 'order' => $sheetOrder];
            }
        }
        // T
        $t = scan_sheet_for_aliases($ws, aliases_T());
        if ($t['hrow'] !== null) {
            $keys = array_unique(array_values($t['headers']));
            $ok1 = in_array('recede_Old', $keys, true) && in_array('rm_mat_no', $keys, true);
            $ok2 = in_array('material_number', $keys, true) && (in_array('scrap', $keys, true) || in_array('footprint', $keys, true));
            if ($ok1 || $ok2) {
                $candidates[] = ['type' => 'T', 'ws' => $ws, 'headers' => $t['headers'], 'hrow' => $t['hrow'], 'score' => $t['score'], 'order' => $sheetOrder];
            }
        }
        // S
        $s = scan_sheet_for_aliases($ws, aliases_S());
        if ($s['hrow'] !== null) {
            $keys = array_unique(array_values($s['headers']));
            $ok = in_array('material_number', $keys, true) && (
                in_array('recede_New', $keys, true) ||
                in_array('recede_Old', $keys, true) ||
                in_array('scrap', $keys, true) ||
                in_array('footprint', $keys, true)
            );
            if ($ok) {
                $candidates[] = ['type' => 'S', 'ws' => $ws, 'headers' => $s['headers'], 'hrow' => $s['hrow'], 'score' => $s['score'], 'order' => $sheetOrder];
            }
        }
        $sheetOrder++;
    }

    if (!$candidates) throw new Exception('無法辨識檔案格式（未找到可識別的標題列）');

    // 依 score 降冪，再依 hrow 升冪，再依 sheet 順序
    usort($candidates, function ($a, $b) {
        if ($a['score'] !== $b['score']) return $b['score'] <=> $a['score'];
        if ($a['hrow']  !== $b['hrow'])  return $a['hrow']  <=> $b['hrow'];
        return $a['order'] <=> $b['order'];
    });

    $best = $candidates[0];
    $debug = [
        'type' => $best['type'],
        'sheet' => $best['ws']->getTitle(),
        'header_row' => $best['hrow'],
        'matched_keys' => array_values(array_unique(array_values($best['headers']))),
    ];

    switch ($best['type']) {
        case 'LKW':
            return parse_LKW($best['ws'], $best['headers'], $best['hrow'], $voucherPrefix, $docFallback);
        case 'T':
            return parse_T($best['ws'], $best['headers'], $best['hrow'], $voucherPrefix, $docFallback);
        case 'S':
            return parse_S($best['ws'], $best['headers'], $best['hrow'], $voucherPrefix, $docFallback);
    }
    throw new Exception('未知的檔案型別');
}

/////////////////////////////
// Unknown numbers helper
/////////////////////////////
function collect_unknown_numbers(PDO $conn, array $rows): array
{
    $map = []; // mn => name
    foreach ($rows as $r) {
        $mn = (string)($r['material_number'] ?? '');
        if ($mn === '') continue;
        if (!isset($map[$mn])) $map[$mn] = (string)($r['material_name'] ?? '');
    }
    if (!$map) return [];

    $nums = array_keys($map);
    $exists = [];
    $chunk = 500;
    for ($i = 0; $i < count($nums); $i += $chunk) {
        $slice = array_slice($nums, $i, $chunk);
        $in = implode(',', array_fill(0, count($slice), '?'));
        $stmt = $conn->prepare("SELECT material_number FROM mat_materials_list WHERE material_number IN ($in)");
        $stmt->execute($slice);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) as $got) {
            $exists[(string)$got] = true;
        }
    }

    $unknown = [];
    foreach ($map as $mn => $name) {
        if (!isset($exists[$mn])) {
            $unknown[] = ['material_number' => $mn, 'name_specification' => $name];
        }
    }
    return $unknown;
}

/////////////////////////////
// ACTION: analyze
/////////////////////////////
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'analyze') {
    $contractor_code = _trim($_POST['contractor_code'] ?? '');
    $withdraw_date   = _trim($_POST['withdraw_date'] ?? '');

    if ($contractor_code === '' && $withdraw_date === '') jsend_error('請先選擇承攬商及提領日期', 400);
    if ($contractor_code === '') jsend_error('請先選擇承攬商', 400);
    if ($withdraw_date   === '') jsend_error('請先選擇提領日期', 400);

    if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        jsend_error('未收到檔案', 400);
    }

    $tmpPath = $_FILES['file']['tmp_name'];
    $origName = (string)($_FILES['file']['name'] ?? '未命名');

    // voucherPrefix 僅保留承攬商代碼；docFallback 用提領日期（若檔內沒批號/憑證才會用到）
    $voucherPrefix = $contractor_code;
    $docFallback   = str_replace('-', '', $withdraw_date);

    try {
        $debug = [];
        $rows = detect_type_and_parse($tmpPath, $voucherPrefix, $docFallback, $debug);

        // 比對未知料號
        $unknown = collect_unknown_numbers($conn, $rows);

        // 暫存 rows（給 confirm_batch 匯入）
        $token = bin2hex(random_bytes(16));
        $payload = [
            'contractor_code' => $contractor_code,
            'withdraw_date'   => $withdraw_date,
            'created_at'      => now_ts(),
            'rows'            => $rows,
            'file_name'       => $origName,
        ];
        file_put_contents(tmp_token_path($token), json_encode($payload, JSON_UNESCAPED_UNICODE));

        jsend_success([
            'token'           => $token,
            'unknown_numbers' => $unknown,
            'preview_count'   => count($rows),
            'file'            => $origName,
            'detected'        => $debug, // 方便你排錯
        ]);
    } catch (Throwable $e) {
        jsend_error($e->getMessage(), 400);
    }
}


/////////////////////////////
// ACTION: confirm_batch
/////////////////////////////
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw ?: '[]', true);

    if (($body['action'] ?? '') === 'confirm_batch') {
        $tokens = $body['tokens'] ?? [];
        $add_numbers = $body['add_numbers'] ?? []; // 勾選要加入 master 的新料號

        if (!is_array($tokens) || count($tokens) === 0) jsend_error('缺少 tokens', 400);
        if (!is_array($add_numbers)) $add_numbers = [];

        // 收集暫存 rows
        $allRows = [];
        $contractor_code = '';
        $withdraw_date = '';
        $fileNames = [];
        foreach ($tokens as $tk) {
            $p = tmp_token_path($tk);
            if (!is_file($p)) continue;
            $data = json_decode((string)file_get_contents($p), true);
            if (!$data || !isset($data['rows'])) continue;
            if ($contractor_code === '') $contractor_code = (string)($data['contractor_code'] ?? '');
            if ($withdraw_date   === '') $withdraw_date   = (string)($data['withdraw_date'] ?? '');
            $fileNames[] = (string)($data['file_name'] ?? '');
            foreach ($data['rows'] as $r) $allRows[] = $r;
            @unlink($p);
        }

        // 清掉空料號/空 voucher
        $allRows = array_values(array_filter($allRows, function ($r) {
            $no = trim((string)($r['material_number'] ?? ''));
            $voucher = trim((string)($r['voucher'] ?? ''));
            return $no !== '' && $voucher !== '';
        }));

        // 無可匯入列 → 回 success（不擋流程）
        if (!$allRows) {
            echo json_encode([
                'success'           => true,
                'added_to_master'   => 0,
                'inserted_rows'     => 0,
                'skipped_due_to_fk' => 0,
                'files'             => $fileNames,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        try {
            $conn->beginTransaction();

            // ===== 欄位偵測 =====
            $mm_list_has_created_at   = table_has_column($conn, 'mat_materials_list',   'created_at');
            $mm_list_has_sort_order   = table_has_column($conn, 'mat_materials_list',   'sort_order');
            $mm_detail_has_created_at = table_has_column($conn, 'mat_material_number',  'created_at');

            // ===== 建立「允許的料號集合」：現有於 master 的 ∪ 勾選 add_numbers =====
            $nums = array_values(array_unique(array_map(fn($r) => (string)$r['material_number'], $allRows)));

            $exists = [];
            $chunk = 500;
            for ($i = 0; $i < count($nums); $i += $chunk) {
                $slice = array_slice($nums, $i, $chunk);
                $in = implode(',', array_fill(0, count($slice), '?'));
                $stmt = $conn->prepare("SELECT material_number FROM mat_materials_list WHERE material_number IN ($in)");
                $stmt->execute($slice);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) as $got) {
                    $exists[(string)$got] = true;
                }
            }

            // (1) 先補 master（僅把「勾選」的料號加入）
            $addedToMaster = 0;
            if (!empty($add_numbers)) {
                $nameMap = [];
                foreach ($allRows as $r) {
                    $mn = (string)$r['material_number'];
                    if ($mn !== '' && !isset($nameMap[$mn])) $nameMap[$mn] = (string)($r['material_name'] ?? '');
                }

                if ($mm_list_has_created_at && $mm_list_has_sort_order) {
                    $sql = "
                        INSERT INTO mat_materials_list (material_number, name_specification, sort_order, created_at)
                        VALUES (:no, :name, :sort, :ts)
                        ON DUPLICATE KEY UPDATE name_specification = VALUES(name_specification)
                    ";
                    $stmtIns = $conn->prepare($sql);
                    foreach ($add_numbers as $mn) {
                        $mn = (string)$mn;
                        if ($mn === '') continue;
                        $stmtIns->execute([
                            ':no'   => $mn,
                            ':name' => $nameMap[$mn] ?? '',
                            ':sort' => 9999,
                            ':ts'   => now_ts(),
                        ]);
                        if ($stmtIns->rowCount() > 0) $addedToMaster++;
                        $exists[$mn] = true;
                    }
                } elseif ($mm_list_has_created_at) {
                    $sql = "
                        INSERT INTO mat_materials_list (material_number, name_specification, created_at)
                        VALUES (:no, :name, :ts)
                        ON DUPLICATE KEY UPDATE name_specification = VALUES(name_specification)
                    ";
                    $stmtIns = $conn->prepare($sql);
                    foreach ($add_numbers as $mn) {
                        $mn = (string)$mn;
                        if ($mn === '') continue;
                        $stmtIns->execute([
                            ':no'   => $mn,
                            ':name' => $nameMap[$mn] ?? '',
                            ':ts'   => now_ts(),
                        ]);
                        if ($stmtIns->rowCount() > 0) $addedToMaster++;
                        $exists[$mn] = true;
                    }
                } elseif ($mm_list_has_sort_order) {
                    $sql = "
                        INSERT INTO mat_materials_list (material_number, name_specification, sort_order)
                        VALUES (:no, :name, :sort)
                        ON DUPLICATE KEY UPDATE name_specification = VALUES(name_specification)
                    ";
                    $stmtIns = $conn->prepare($sql);
                    foreach ($add_numbers as $mn) {
                        $mn = (string)$mn;
                        if ($mn === '') continue;
                        $stmtIns->execute([
                            ':no'   => $mn,
                            ':name' => $nameMap[$mn] ?? '',
                            ':sort' => 9999,
                        ]);
                        if ($stmtIns->rowCount() > 0) $addedToMaster++;
                        $exists[$mn] = true;
                    }
                } else {
                    $sql = "
                        INSERT INTO mat_materials_list (material_number, name_specification)
                        VALUES (:no, :name)
                        ON DUPLICATE KEY UPDATE name_specification = VALUES(name_specification)
                    ";
                    $stmtIns = $conn->prepare($sql);
                    foreach ($add_numbers as $mn) {
                        $mn = (string)$mn;
                        if ($mn === '') continue;
                        $stmtIns->execute([
                            ':no'   => $mn,
                            ':name' => $nameMap[$mn] ?? '',
                        ]);
                        if ($stmtIns->rowCount() > 0) $addedToMaster++;
                        $exists[$mn] = true;
                    }
                }
            }

            // (2) 只保留允許集合中的明細，避免 FK 1452
            $allowed = $exists; // 已存在 + 剛新增
            $filteredRows = [];
            $skippedFK = 0;
            foreach ($allRows as $r) {
                $mn = (string)$r['material_number'];
                if (isset($allowed[$mn])) $filteredRows[] = $r;
                else $skippedFK++;
            }

            // 若全部被略過，也回 success
            if (!$filteredRows) {
                $conn->commit();
                echo json_encode([
                    'success'           => true,
                    'added_to_master'   => $addedToMaster,
                    'inserted_rows'     => 0,
                    'skipped_due_to_fk' => $skippedFK,
                    'files'             => $fileNames,
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // (3) 寫入明細
            if ($mm_detail_has_created_at) {
                $stmtDet = $conn->prepare("
                    INSERT INTO mat_material_number
                    (voucher, material_number, material_name, collar_New, collar_Old, recede_New, recede_Old, scrap, footprint, contractor_code, withdraw_date, created_at)
                    VALUES
                    (:voucher, :no, :name, :cnew, :cold, :rnew, :rold, :scrap, :foot, :cc, :wd, :ts)
                ");
            } else {
                $stmtDet = $conn->prepare("
                    INSERT INTO mat_material_number
                    (voucher, material_number, material_name, collar_New, collar_Old, recede_New, recede_Old, scrap, footprint, contractor_code, withdraw_date)
                    VALUES
                    (:voucher, :no, :name, :cnew, :cold, :rnew, :rold, :scrap, :foot, :cc, :wd)
                ");
            }

            $insertedRows = 0;
            foreach ($filteredRows as $r) {
                $params = [
                    ':voucher' => (string)($r['voucher'] ?? ''),
                    ':no'      => (string)($r['material_number'] ?? ''),
                    ':name'    => (string)($r['material_name'] ?? ''),
                    ':cnew'    => (float)($r['collar_New']   ?? 0),
                    ':cold'    => (float)($r['collar_Old']   ?? 0),
                    ':rnew'    => (float)($r['recede_New']   ?? 0),
                    ':rold'    => (float)($r['recede_Old']   ?? 0),
                    ':scrap'   => (float)($r['scrap']        ?? 0),
                    ':foot'    => (float)($r['footprint']    ?? 0),
                    ':cc'      => (string)$contractor_code,
                    ':wd'      => (string)$withdraw_date,
                ];
                if ($mm_detail_has_created_at) $params[':ts'] = now_ts();
                $stmtDet->execute($params);
                $insertedRows += $stmtDet->rowCount();
            }

            $conn->commit();

            // 刪除 1 個月以前（以今日為基準）
            $conn->exec("DELETE FROM mat_material_number WHERE withdraw_date < CURDATE() - INTERVAL 1 MONTH");
            // 重新編號 id
            $conn->exec("SET @count = 0");
            $conn->exec("UPDATE mat_material_number SET id = (@count := @count + 1) ORDER BY id");
            $conn->exec("ALTER TABLE mat_material_number AUTO_INCREMENT = 1");

            echo json_encode([
                'success'           => true,
                'added_to_master'   => $addedToMaster,
                'inserted_rows'     => $insertedRows,
                'skipped_due_to_fk' => $skippedFK,
                'files'             => $fileNames,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Throwable $e) {
            $conn->rollBack();
            echo json_encode(['success' => false, 'message' => '寫入失敗：' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

/////////////////////////////
// Fallback
/////////////////////////////
jsend_error('Unknown action', 400);
