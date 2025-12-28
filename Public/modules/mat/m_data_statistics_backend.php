<?php
// Public/modules/mat/m_data_statistics_backend.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/auth.php';
require_login();
require_once __DIR__ . '/../../../config/db_connection.php'; // PDO $conn

header('Content-Type: application/json; charset=utf-8');

$raw  = file_get_contents('php://input');
$json = json_decode($raw ?: '[]', true);
$action = $_GET['action'] ?? ($_POST['action'] ?? ($json['action'] ?? ''));

try {
  if (!$action) { echo json_encode(['success' => false, 'message' => 'Unknown action']); exit; }
  switch ($action) {
    case 'stats_overview_last_month': stats_overview_last_month($conn); break;
    case 'contractor_day_stats':      contractor_day_stats($conn);      break;
    default: echo json_encode(['success' => false, 'message' => 'Unknown action']);
  }
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['success' => false, 'message' => 'Server error', 'error' => $e->getMessage()]);
}

/* utils */
function table_exists(PDO $conn, string $t): bool {
  $q="SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1";
  $s=$conn->prepare($q); $s->execute([':t'=>$t]); return (bool)$s->fetchColumn();
}
function column_exists(PDO $conn, string $t, string $c): bool {
  $q="SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1";
  $s=$conn->prepare($q); $s->execute([':t'=>$t,':c'=>$c]); return (bool)$s->fetchColumn();
}

/* A. 近一個月概覽 */
function stats_overview_last_month(PDO $conn): void {
  $end=new DateTime('today'); $start=(clone $end)->modify('-29 days');
  $hasC = table_exists($conn,'mat_contractors');
  $sql="
    SELECT mn.withdraw_date d, mn.contractor_code code
         , ".($hasC?"COALESCE(c.contractor_name, mn.contractor_code)":"mn.contractor_code")." name
    FROM mat_material_number mn
    ".($hasC?"LEFT JOIN mat_contractors c ON c.contractor_code = mn.contractor_code":"")."
    WHERE mn.withdraw_date BETWEEN :s AND :e
    GROUP BY mn.withdraw_date, mn.contractor_code
    ORDER BY mn.withdraw_date DESC, mn.contractor_code ASC";
  $st=$conn->prepare($sql); $st->execute([':s'=>$start->format('Y-m-d'),':e'=>$end->format('Y-m-d')]);
  $rows=$st->fetchAll(PDO::FETCH_ASSOC);

  $byDate=[]; foreach($rows as $r){ $d=$r['d']; $byDate[$d][]=['contractor_code'=>$r['code'],'contractor_name'=>$r['name']]; }
  $days=[]; $period=new DatePeriod($start,new DateInterval('P1D'),(clone $end)->modify('+1 day'));
  foreach($period as $day){ $ds=$day->format('Y-m-d'); if(!empty($byDate[$ds])) $days[]=['date'=>$ds,'total_contractors'=>count($byDate[$ds]),'contractors'=>$byDate[$ds]]; }

  echo json_encode(['success'=>true,'start_date'=>$start->format('Y-m-d'),'end_date'=>$end->format('Y-m-d'),'days'=>$days]);
}

/* B. 承攬商單日統計（B 班：合計｜明細；名稱= name_specification；排序=地點+料號） */
function contractor_day_stats(PDO $conn): void {
  $code=trim((string)($_GET['contractor_code']??$_POST['contractor_code']??''));
  $date=trim((string)($_GET['withdraw_date']  ??$_POST['withdraw_date']  ??''));
  if($code===''||$date===''){ echo json_encode(['success'=>false,'message'=>'缺少參數']); return; }

  // 退料加總併 scrap/footprint（如果存在）
  $opt=[]; foreach(['scrap_new','scrap_old','footprint_new','footprint_old'] as $c)
    if(column_exists($conn,'mat_material_number',$c)) $opt[$c]=true;

  // 以「單筆」為粒度，先把各筆的數量算好
  $sub="
    SELECT
      mn.material_number,
      COALESCE(mn.collar_new,0)  AS lead_new_item,
      COALESCE(mn.collar_old,0)  AS lead_old_item,
      (COALESCE(mn.recede_new,0) ".(!empty($opt['scrap_new'])?"+COALESCE(mn.scrap_new,0)":"").(!empty($opt['footprint_new'])?"+COALESCE(mn.footprint_new,0)":"").") AS return_new_item,
      (COALESCE(mn.recede_old,0) ".(!empty($opt['scrap_old'])?"+COALESCE(mn.scrap_old,0)":"").(!empty($opt['footprint_old'])?"+COALESCE(mn.footprint_old,0)":"").") AS return_old_item
    FROM mat_material_number mn
    WHERE mn.contractor_code = :code AND mn.withdraw_date = :wd
  ";

  // 名稱：name_specification；排序：先以地點匹配，再回退空地點
  $hasMl = table_exists($conn,'mat_materials_list');
  $hasMs = table_exists($conn,'mat_materials_sorting');

  $joinMl = $hasMl ? "LEFT JOIN mat_materials_list ml ON ml.material_number = x.material_number" : "";
  $nameExpr = $hasMl && column_exists($conn,'mat_materials_list','name_specification')
            ? "COALESCE(ml.name_specification, x.material_number) AS material_name"
            : "x.material_number AS material_name";

  $joinMs1 = $hasMs ? "LEFT JOIN mat_materials_sorting ms1 ON ms1.material_number = x.material_number AND ms1.material_location = COALESCE(ml.material_location,'')" : "";
  $joinMs0 = $hasMs ? "LEFT JOIN mat_materials_sorting ms0 ON ms0.material_number = x.material_number AND ms0.material_location = ''" : "";
  $sortExpr = $hasMs && column_exists($conn,'mat_materials_sorting','sort_order')
           ? "COALESCE(ms1.sort_order, ms0.sort_order, 999999) AS sort_order"
           : "999999 AS sort_order";

  $sql="
    SELECT
      x.material_number,
      $nameExpr,
      $sortExpr,

      SUM(x.lead_new_item)    AS lead_new,
      GROUP_CONCAT(NULLIF(x.lead_new_item,0)   SEPARATOR '+') AS lead_new_breakdown,

      SUM(x.lead_old_item)    AS lead_old,
      GROUP_CONCAT(NULLIF(x.lead_old_item,0)   SEPARATOR '+') AS lead_old_breakdown,

      SUM(x.return_new_item)  AS return_new,
      GROUP_CONCAT(NULLIF(x.return_new_item,0) SEPARATOR '+') AS return_new_breakdown,

      SUM(x.return_old_item)  AS return_old,
      GROUP_CONCAT(NULLIF(x.return_old_item,0) SEPARATOR '+') AS return_old_breakdown

    FROM ($sub) x
    $joinMl
    $joinMs1
    $joinMs0
    GROUP BY x.material_number, material_name, sort_order
    ORDER BY sort_order ASC, x.material_number ASC
  ";
  $st=$conn->prepare($sql); $st->execute([':code'=>$code,':wd'=>$date]);
  $rows=$st->fetchAll(PDO::FETCH_ASSOC);

  // 承攬商名稱（若表存在）
  $contractor_name=$code;
  if(table_exists($conn,'mat_contractors') && column_exists($conn,'mat_contractors','contractor_name')){
    $cs=$conn->prepare("SELECT contractor_name FROM mat_contractors WHERE contractor_code=:c LIMIT 1");
    $cs->execute([':c'=>$code]); $nm=$cs->fetchColumn(); if(is_string($nm)&&$nm!=='') $contractor_name=$nm;
  }

  // 小計/總合
  $sum=['lead_new_total'=>0,'lead_old_total'=>0,'return_new_total'=>0,'return_old_total'=>0,'total_new'=>0,'total_old'=>0];
  foreach($rows as &$r){
    $r['lead_new']   = (float)$r['lead_new'];
    $r['lead_old']   = (float)$r['lead_old'];
    $r['return_new'] = (float)$r['return_new'];
    $r['return_old'] = (float)$r['return_old'];
    $r['total_new']  = $r['lead_new'] - $r['return_new'];
    $r['total_old']  = $r['lead_old'] - $r['return_old'];

    $sum['lead_new_total']   += $r['lead_new'];
    $sum['lead_old_total']   += $r['lead_old'];
    $sum['return_new_total'] += $r['return_new'];
    $sum['return_old_total'] += $r['return_old'];
    $sum['total_new']        += $r['total_new'];
    $sum['total_old']        += $r['total_old'];
  } unset($r);

  echo json_encode([
    'success'=>true,
    'contractor_code'=>$code,
    'contractor_name'=>$contractor_name,
    'withdraw_date'=>$date,
    'rows'=>$rows,
    'sum'=>$sum,
  ]);
}
