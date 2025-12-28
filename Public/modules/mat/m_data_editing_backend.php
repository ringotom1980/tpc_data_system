<?php
// Public/modules/mat/m_data_editing_backend.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/auth.php';
require_login();
require_once __DIR__ . '/../../../config/db_connection.php';

header('Content-Type: application/json; charset=utf-8');

// 支援從 JSON body 讀取 action（前端用 fetch POST JSON 時）
$raw  = file_get_contents('php://input');
$json = json_decode($raw ?: '[]', true);

// 路由：GET > POST > JSON，預設回材料清單
$action = $_GET['action'] ?? ($_POST['action'] ?? ($json['action'] ?? 'list_for_sort'));

try {
    /* -------------------- 材料：讀清單（已存排序優先） -------------------- */
    if ($action === 'list_for_sort') {
        $sql = "
      SELECT
        m.reco_reco_id,
        m.material_number,
        COALESCE(m.name_specification,'') AS name_specification
      FROM mat_materials_list m
      LEFT JOIN mat_materials_sorting s
        ON s.material_number = m.material_number
       AND s.material_location = ''   -- 全域排序桶
      ORDER BY
        CASE WHEN s.sort_order IS NULL THEN 1 ELSE 0 END ASC,
        s.sort_order ASC,
        m.reco_reco_id ASC
    ";
        $rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'items' => $rows], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* -------------------- 材料：儲存排序（清空全域桶→重建） -------------------- */
    if ($action === 'save_order') {
        $items = $json['items'] ?? ($_POST['items'] ?? []);
        if (!is_array($items) || empty($items)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '沒有可儲存的排序資料'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        try {
            // ① 刪除全域桶排序（在一個獨立交易中）
            $conn->beginTransaction();
            $conn->exec("DELETE FROM mat_materials_sorting WHERE material_location = ''");
            $conn->commit();

            // ② 交易外重置 AUTO_INCREMENT（避免 implicit commit 造成後續 commit 失敗）
            $conn->exec("ALTER TABLE mat_materials_sorting AUTO_INCREMENT = 1");

            // ③ 重新插入（另一個交易）
            $ins = $conn->prepare("
            INSERT INTO mat_materials_sorting(material_number, material_location, sort_order)
            VALUES(:mat, '', :ord)
        ");
            $chk = $conn->prepare("SELECT 1 FROM mat_materials_list WHERE material_number = :m LIMIT 1");

            $conn->beginTransaction();
            $ord = 1;
            foreach ($items as $mat) {
                $mat = trim((string)$mat);
                if ($mat === '') continue;
                $chk->execute([':m' => $mat]);
                if (!$chk->fetchColumn()) continue;
                $ins->execute([':mat' => $mat, ':ord' => $ord++]);
            }
            $conn->commit();

            echo json_encode(['success' => true, 'message' => '排序已儲存', 'count' => $ord - 1], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => '儲存失敗：' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }


    /* -------------------- 材料：刪除單筆（同時刪兩表） -------------------- */
    /* -------------------- 材料：刪除單筆（排序表＋總表＋子表） -------------------- */
    if ($action === 'delete_sort_item') {
        // 支援 JSON / 表單
        $raw = file_get_contents('php://input');
        $in  = $raw ? json_decode($raw, true) : $_POST;

        $mat = trim((string)($in['material_number'] ?? ''));        // 必填：材料編號
        $loc = isset($in['material_location'])                      // 選填：位置；若不給，排序表刪該料號所有位置列
            ? trim((string)$in['material_location'])
            : null;

        if ($mat === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '缺少材料編號 material_number'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        try {
            $conn->beginTransaction();

            // ① 排序表：先刪（不受 FK 影響）
            if ($loc !== null && $loc !== '') {
                $stmt1 = $conn->prepare(
                    "DELETE FROM mat_materials_sorting WHERE material_number = ? AND material_location = ?"
                );
                $stmt1->execute([$mat, $loc]);
                $deleted_sort = $stmt1->rowCount();
            } else {
                $stmt1 = $conn->prepare(
                    "DELETE FROM mat_materials_sorting WHERE material_number = ?"
                );
                $stmt1->execute([$mat]);
                $deleted_sort = $stmt1->rowCount();
            }

            // ② 子表：先刪 mat_material_number（避免 1451）
            //    ※ 若你還有其他子表也用 material_number 做 FK，照這個模式一起刪。
            $stmtChild = $conn->prepare("DELETE FROM mat_material_number WHERE material_number = ?");
            $stmtChild->execute([$mat]);
            $deleted_child = $stmtChild->rowCount();

            // ③ 總表：最後刪 mat_materials_list（這時已無 FK 參照）
            $stmt2 = $conn->prepare("DELETE FROM mat_materials_list WHERE material_number = ?");
            $stmt2->execute([$mat]);
            $deleted_list = $stmt2->rowCount();

            $conn->commit();

            echo json_encode([
                'success'       => true,
                'deleted_sort'  => (int)$deleted_sort,
                'deleted_child' => (int)$deleted_child,
                'deleted_list'  => (int)$deleted_list,
                'message'       => ($deleted_sort + $deleted_child + $deleted_list) > 0 ? '刪除完成' : '無相符資料',
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => '刪除失敗：' . $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }


    /* -------------------- 承攬商：下拉只給啟用中 -------------------- */
    if ($action === 'contractors_list') {
        $sql = "SELECT contractor_id, contractor_code, contractor_name
            FROM mat_contractors
           WHERE is_active = 1
           ORDER BY
             CASE
               WHEN COALESCE(contractor_code,'') REGEXP '^[A-Za-z]' THEN 0
               WHEN COALESCE(contractor_code,'') REGEXP '^[0-9]'   THEN 1
               ELSE 2
             END,
             contractor_code ASC, contractor_name ASC";
        $rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'items' => $rows], JSON_UNESCAPED_UNICODE);
        exit;
    }


    /* -------------------- 承攬商：編輯視窗載入全部 -------------------- */
    if ($action === 'contractors_get_all') {
        $sql = "SELECT contractor_id, contractor_code, contractor_name, is_active
            FROM mat_contractors
           ORDER BY
             CASE
               WHEN COALESCE(contractor_code,'') REGEXP '^[A-Za-z]' THEN 0
               WHEN COALESCE(contractor_code,'') REGEXP '^[0-9]'   THEN 1
               ELSE 2
             END,
             contractor_code ASC, contractor_name ASC";
        $rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'items' => $rows], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* -------------------- 新增承攬商 -------------------- */
    if ($action === 'contractors_add') {
        $code = trim((string)($json['contractor_code'] ?? $_POST['contractor_code'] ?? ''));
        $name = trim((string)($json['contractor_name'] ?? $_POST['contractor_name'] ?? ''));
        $active = (int)($json['is_active'] ?? $_POST['is_active'] ?? 1);

        if ($name === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '承攬商名稱必填'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $ins = $conn->prepare("INSERT INTO mat_contractors (contractor_code, contractor_name, is_active)
                         VALUES (:code, :name, :active)");
        try {
            $ins->execute([
                ':code'   => ($code === '' ? null : $code),
                ':name'   => $name,
                ':active' => $active,
            ]);
            $id = (int)$conn->lastInsertId();

            // 回傳插入後的完整資料
            $row = $conn->query("SELECT contractor_id, contractor_code, contractor_name, is_active
                           FROM mat_contractors WHERE contractor_id = {$id}")->fetch(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'item' => $row], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '新增失敗：' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    /* -------------------- 承攬商：批次儲存 -------------------- */
    if ($action === 'contractors_save') {
        $items = $json['items'] ?? [];
        if (!is_array($items) || empty($items)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '沒有可儲存的資料'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $conn->beginTransaction();
        $upd = $conn->prepare("
      UPDATE mat_contractors
         SET contractor_code = :code,
             contractor_name = :name,
             is_active = :active
       WHERE contractor_id = :id
    ");

        foreach ($items as $i) {
            $id     = (int)($i['contractor_id'] ?? 0);
            $code   = trim((string)($i['contractor_code'] ?? ''));
            $name   = trim((string)($i['contractor_name'] ?? ''));
            $active = (int) (!empty($i['is_active']));
            if ($id <= 0 || $name === '') {
                throw new RuntimeException("ID {$id} 名稱必填");
            }
            $upd->execute([
                ':code'   => ($code === '' ? null : $code),
                ':name'   => $name,
                ':active' => $active,
                ':id'     => $id,
            ]);
        }
        $conn->commit();

        echo json_encode(['success' => true, 'message' => '已儲存'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* -------------------- 近一個月承攬商提領 -------------------- */
    if ($action === 'withdraw_overview_last_month') {
        // 近 30 天（含今天）
        $today = new DateTimeImmutable('today');
        $start = $today->sub(new DateInterval('P30D'))->format('Y-m-d');
        $end   = $today->format('Y-m-d');

        // 以日期+承攬商聚合
        $sql = <<<SQL
      SELECT withdraw_date, contractor_code, COUNT(*) AS cnt
      FROM mat_material_number
      WHERE withdraw_date BETWEEN ? AND ?
      GROUP BY withdraw_date, contractor_code
      ORDER BY withdraw_date DESC, contractor_code ASC
    SQL;
        $stmt = $conn->prepare($sql);
        $stmt->execute([$start, $end]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // 依日期分組
        $byDate = [];
        foreach ($rows as $r) {
            $d = $r['withdraw_date'];
            if (!isset($byDate[$d])) $byDate[$d] = [];
            $byDate[$d][] = [
                'contractor_code' => (string)$r['contractor_code'],
                'cnt' => (int)$r['cnt'],
            ];
        }

        // 組輸出（依日期 DESC）
        krsort($byDate);
        $days = [];
        foreach ($byDate as $d => $list) {
            $days[] = [
                'date' => $d,
                'total_contractors' => count($list),
                'contractors' => $list,
            ];
        }

        echo json_encode([
            'success'      => true,
            'start_date'   => $start,
            'end_date'     => $end,
            'total_days'   => count($days),
            'days'         => $days,
        ]);
        exit;
    }
    
    /* -------------------- 提領明細：指定日期 + 承攬商，回傳「去尾碼」後的單號清單 -------------------- */
    if ($action === 'withdraw_overview_detail') {
        $d = (string)($_GET['withdraw_date'] ?? $_POST['withdraw_date'] ?? ($json['withdraw_date'] ?? ''));
        $cc = (string)($_GET['contractor_code'] ?? $_POST['contractor_code'] ?? ($json['contractor_code'] ?? ''));

        $d = trim($d);
        $cc = trim($cc);

        if ($d === '' || $cc === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '缺少 withdraw_date 或 contractor_code'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // voucher_base = 去掉最後 _1~2碼（例如 T13_L253946_1 => T13_L253946）
        $sql = <<<SQL
          SELECT
            REGEXP_REPLACE(voucher, '_[0-9]{1,2}$', '') AS voucher_base,
            COUNT(*) AS cnt
          FROM mat_material_number
          WHERE withdraw_date = ?
            AND contractor_code = ?
          GROUP BY voucher_base
          ORDER BY voucher_base ASC
        SQL;

        $stmt = $conn->prepare($sql);
        $stmt->execute([$d, $cc]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'voucher_base' => (string)$r['voucher_base'],
                'cnt' => (int)$r['cnt'],
            ];
        }

        echo json_encode([
            'success' => true,
            'withdraw_date' => $d,
            'contractor_code' => $cc,
            'count' => count($items),
            'items' => $items,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* -------------------- 提領刪除：刪除同一 base 的所有尾碼（不提示） -------------------- */
    if ($action === 'withdraw_overview_delete') {
        $d = (string)($_POST['withdraw_date'] ?? ($json['withdraw_date'] ?? ''));
        $cc = (string)($_POST['contractor_code'] ?? ($json['contractor_code'] ?? ''));
        $base = (string)($_POST['voucher_base'] ?? ($json['voucher_base'] ?? ''));

        $d = trim($d);
        $cc = trim($cc);
        $base = trim($base);

        if ($d === '' || $cc === '' || $base === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '缺少 withdraw_date / contractor_code / voucher_base'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // pattern: ^T13_L253946_[0-9]{1,2}$
        // base 只預期由英數與底線組成；若你未來可能有特殊字元，再做更嚴格 escape。
        $pattern = '^' . $base . '_[0-9]{1,2}$';

        $stmt = $conn->prepare("
          DELETE FROM mat_material_number
          WHERE withdraw_date = ?
            AND contractor_code = ?
            AND voucher REGEXP ?
        ");
        $stmt->execute([$d, $cc, $pattern]);

        echo json_encode([
            'success' => true,
            'deleted' => (int)$stmt->rowCount(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* -------------------- 未匹配任何 action -------------------- */
    echo json_encode(['success' => false, 'message' => 'Unknown action'], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error',
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
