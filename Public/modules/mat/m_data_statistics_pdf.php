<?php
// Public/modules/mat/m_data_statistics_pdf.php
// 單日承攬商材料統計（B 班版型 + 材料編號欄）PDF 輸出

declare(strict_types=1);
require_once __DIR__ . '/../../../config/auth.php';
require_login();
require_once __DIR__ . '/../../../config/db_connection.php'; // 提供 $conn (PDO)
require_once __DIR__ . '/../../TCPDF/tcpdf.php';             // TCPDF 路徑（相對於本檔）

// 讀取參數（用 GET，與前端列印按鈕一致）
$contractor_code = trim((string)($_GET['contractor_code'] ?? ''));
$withdraw_date   = trim((string)($_GET['withdraw_date']   ?? ''));

if ($contractor_code === '' || $withdraw_date === '') {
  header('Content-Type: text/plain; charset=utf-8');
  http_response_code(400);
  echo "缺少參數：contractor_code 或 withdraw_date";
  exit;
}

// ===== 資料查詢：沿用你現有的口徑（名稱 = mat_materials_list.name_specification；排序 = 依 location 回退）=====
function table_exists(PDO $conn, string $t): bool {
  $q="SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1";
  $s=$conn->prepare($q); $s->execute([':t'=>$t]); return (bool)$s->fetchColumn();
}
function column_exists(PDO $conn, string $t, string $c): bool {
  $q="SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1";
  $s=$conn->prepare($q); $s->execute([':t'=>$t,':c'=>$c]); return (bool)$s->fetchColumn();
}

$opt = [];
foreach (['scrap_new','scrap_old','footprint_new','footprint_old'] as $c)
  if (column_exists($conn,'mat_material_number',$c)) $opt[$c]=true;

$hasMl = table_exists($conn,'mat_materials_list');
$hasMs = table_exists($conn,'mat_materials_sorting');

$nameExpr = $hasMl && column_exists($conn,'mat_materials_list','name_specification')
  ? "COALESCE(ml.name_specification, x.material_number) AS material_name"
  : "x.material_number AS material_name";

$joinMl  = $hasMl ? "LEFT JOIN mat_materials_list ml ON ml.material_number = x.material_number" : "";
$joinMs1 = $hasMs ? "LEFT JOIN mat_materials_sorting ms1 ON ms1.material_number = x.material_number AND ms1.material_location = COALESCE(ml.material_location,'')" : "";
$joinMs0 = $hasMs ? "LEFT JOIN mat_materials_sorting ms0 ON ms0.material_number = x.material_number AND ms0.material_location = ''" : "";

$sortExpr = $hasMs && column_exists($conn,'mat_materials_sorting','sort_order')
  ? "COALESCE(ms1.sort_order, ms0.sort_order, 999999) AS sort_order"
  : "999999 AS sort_order";

$sub = "
  SELECT
    mn.material_number,
    COALESCE(mn.collar_new,0)  AS lead_new_item,
    COALESCE(mn.collar_old,0)  AS lead_old_item,
    (COALESCE(mn.recede_new,0) ".(!empty($opt['scrap_new'])?"+COALESCE(mn.scrap_new,0)":"").(!empty($opt['footprint_new'])?"+COALESCE(mn.footprint_new,0)":"").") AS return_new_item,
    (COALESCE(mn.recede_old,0) ".(!empty($opt['scrap_old'])?"+COALESCE(mn.scrap_old,0)":"").(!empty($opt['footprint_old'])?"+COALESCE(mn.footfootprint_old,0)":"").") AS return_old_item
  FROM mat_material_number mn
  WHERE mn.contractor_code = :code AND mn.withdraw_date = :wd
";

$sql = "
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
$st = $conn->prepare($sql);
$st->execute([':code'=>$contractor_code, ':wd'=>$withdraw_date]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// 承攬商名稱
$contractor_name = $contractor_code;
if (table_exists($conn,'mat_contractors') && column_exists($conn,'mat_contractors','contractor_name')) {
  $cs=$conn->prepare("SELECT contractor_name FROM mat_contractors WHERE contractor_code=:c LIMIT 1");
  $cs->execute([':c'=>$contractor_code]);
  $nm=$cs->fetchColumn();
  if (is_string($nm) && $nm!=='') $contractor_name=$nm;
}

/* ================================
   自訂 Header / Footer（只印置中標題、頁碼；不印 LOGO）
   ================================ */
class TpcPdf extends TCPDF {
  public string $headerTitle = '台電苗栗區處材料管理科-領退料統計';
  public string $fontname = '';

  public function Header(): void {
    // 置中標題（每頁都會有）
    $this->SetY(8);
    $this->SetFont($this->fontname ?: 'cid0ct', 'B', 14);
    $this->SetTextColor(0,0,0);
    $this->SetXY($this->lMargin, 8);
    $this->Cell(0, 8, $this->headerTitle, 0, 1, 'C', 0);
    $this->Ln(1);
  }

  public function Footer(): void {
    // 頁尾：第?頁（置中）
    $this->SetY(-12);
    $this->SetFont($this->fontname ?: 'cid0ct', '', 9);
    $this->SetTextColor(100,100,100);
    $this->Cell(0, 10, '第'.$this->getAliasNumPage().'頁', 0, 0, 'C');
  }
}

/* ===== PDF 基本設定 ===== */
$pdf = new TpcPdf('L', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('TPC Data System');
$pdf->SetAuthor('TPC Data System');
$baseTitle = $withdraw_date . '-' . $contractor_code . '領退統計';
$filename  = $baseTitle . '.pdf'; // 頁籤(文件標題)：日期-承攬商代碼領退統計
$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);
$pdf->SetMargins(8, 20, 8);   // 上緣留給 Header
$pdf->SetHeaderMargin(6);
$pdf->SetFooterMargin(10);
$pdf->SetAutoPageBreak(true, 12);

/* ★ 字型（要在 AddPage 之前設定，第一頁才會套用） */
$pdf->setFontSubsetting(true);
$fontPath = __DIR__ . '/../../TCPDF/fonts/TaipeiSansTCBeta-Regular.ttf';
$fontname = TCPDF_FONTS::addTTFfont($fontPath, 'TrueTypeUnicode', '', 96) ?: 'cid0ct';
$pdf->fontname    = $fontname;
$pdf->headerTitle = '台電苗栗區處材料管理科-領退料統計';

$pdf->AddPage('L', 'A4');  // 設好 headerTitle/font 後再開頁


/* ===== 每頁子標題（左：代碼-名稱｜右：日期） ===== */
$pdf->SetFillColor(255,255,255);
$pdf->SetTextColor(0,0,0);
$pdf->SetFont($fontname, 'B', 12);
$leftTitle  = $contractor_code . ' - ' . $contractor_name;
$rightTitle = '日期：' . $withdraw_date;
$pdf->Cell(200, 8, $leftTitle, 0, 0, 'L', 0);
$pdf->SetFont($fontname, '', 10);
$pdf->Cell(80, 8, $rightTitle, 0, 1, 'R', 0);
$pdf->Ln(2);

/* ===== 欄寬（沿用你原本的設定，不變） ===== */
$w = [
  'idx'=>10,
  'matno'=>20,
  'name'=>90,
  'lead_grp'=>72, 'col'=>18,
  'ret_grp'=>72,
  'col_1'=>11,
  'col_2'=>25,
  'sum_grp'=>20, 'sumcol'=>10,
];

/* ===== 表頭（第一、二列）抽成函式，跨頁重畫 ===== */
$drawTableHeader = function() use ($pdf,$w,$fontname) {
  $pdf->SetFillColor(238,238,238);
  $pdf->SetTextColor(0,0,0);
  $pdf->SetDrawColor(200,200,200);
  $pdf->SetLineWidth(0.2);
  $pdf->SetFont($fontname, '', 10);

  // 第一列
  $pdf->Cell($w['idx'],   14, '項次',     1, 0, 'C', 1);
  $pdf->Cell($w['matno'], 14, '材料編號', 1, 0, 'C', 1);
  $pdf->Cell($w['name'],  14, '材料名稱', 1, 0, 'C', 1);
  $pdf->Cell($w['lead_grp'], 7, '領料', 1, 0, 'C', 1);
  $pdf->Cell($w['ret_grp'],  7, '退料', 1, 0, 'C', 1);
  $pdf->Cell($w['sum_grp'],  7, '領退合計', 1, 1, 'C', 1);

  // 第二列
  $pdf->Cell($w['idx'],   0, '', 0, 0);
  $pdf->Cell($w['matno'], 0, '', 0, 0);
  $pdf->Cell($w['name'],  0, '', 0, 0);
  $pdf->Cell($w['col']*2, 7, '新', 1, 0, 'C', 1);
  $pdf->Cell($w['col']*2, 7, '舊', 1, 0, 'C', 1);
  $pdf->Cell($w['col']*2, 7, '新', 1, 0, 'C', 1);
  $pdf->Cell($w['col']*2, 7, '舊', 1, 0, 'C', 1);
  $pdf->Cell($w['sumcol'], 7, '新', 1, 0, 'C', 1);
  $pdf->Cell($w['sumcol'], 7, '舊', 1, 1, 'C', 1);

  // ★ 表頭畫完之後，立刻把字型切回一般體，避免後續資料列變粗體
  $pdf->SetFont($fontname, '', 9);
};

/* 先畫一次表頭 */
$drawTableHeader();

/* ===== 跨頁檢查：不足一列高度就換頁，並重畫子標題 + 表頭 ===== */
$rowH = 7;
$ensureSpace = function(int $needH) use ($pdf, $drawTableHeader, $fontname, $leftTitle, $rightTitle) {
  $breakY = $pdf->getPageHeight() - $pdf->getBreakMargin();
  if ($pdf->GetY() + $needH <= $breakY) return;

  $pdf->AddPage('L', 'A4');

  // 每頁子標題（和第一頁一致）
  $pdf->SetFont($fontname, 'B', 12);
  $pdf->SetTextColor(0,0,0);
  $pdf->Cell(200, 8, $leftTitle, 0, 0, 'L', 0);
  $pdf->SetFont($fontname, '', 10);
  $pdf->Cell(80, 8, $rightTitle, 0, 1, 'R', 0);
  $pdf->Ln(2);

  // 重畫表頭（兩列），並把字型切回一般體
  $drawTableHeader();
};

/* ===== 內容列（斑馬線） ===== */
$fmtInt = function($v): string { $i=(int)round((float)$v); return $i===0? '' : (string)$i; };
$fmtBreakdown = function($s) use ($fmtInt): string {
  $t = trim((string)$s); if ($t==='') return '';
  $ints=[]; foreach (array_filter(array_map('trim', explode('+',$t))) as $p) { $i=(int)round((float)$p); if ($i!==0) $ints[]=(string)$i; }
  return implode('+',$ints);
};
$applySumColor = function(TCPDF $pdf, string $type, float $val): void {
  $i=(int)round($val);
  if ($i===0){ $pdf->SetTextColor(0,0,0); return; }
  if ($type==='new') { $i>0 ? $pdf->SetTextColor(0,70,180) : $pdf->SetTextColor(200,0,0); }
  else { $i>0 ? $pdf->SetTextColor(0,0,0) : $pdf->SetTextColor(200,0,0); }
};

$fill = false;
$white = [255,255,255];
$gray  = [245,245,245];

$pdf->SetFont($fontname, '', 9);
$idx = 0;
foreach ($rows as $r) {
  $idx++;
  $ensureSpace($rowH);

  $lead_new  = $fmtInt($r['lead_new']);
  $lead_old  = $fmtInt($r['lead_old']);
  $ret_new   = $fmtInt($r['return_new']);
  $ret_old   = $fmtInt($r['return_old']);

  $bd_lead_new = $fmtBreakdown($r['lead_new_breakdown']);
  $bd_lead_old = $fmtBreakdown($r['lead_old_breakdown']);
  $bd_ret_new  = $fmtBreakdown($r['return_new_breakdown']);
  $bd_ret_old  = $fmtBreakdown($r['return_old_breakdown']);

  $sum_new = (float)($r['lead_new'] ?? 0) - (float)($r['return_new'] ?? 0);
  $sum_old = (float)($r['lead_old'] ?? 0) - (float)($r['return_old'] ?? 0);

  // 斑馬線底色
  $pdf->SetFillColor(...($fill ? $gray : $white));

  // 列
  $pdf->SetTextColor(0,0,0);
  $pdf->Cell($w['idx'],   $rowH, (string)$idx,                  1, 0, 'C', 1);
  $pdf->Cell($w['matno'], $rowH, (string)$r['material_number'], 1, 0, 'C', 1);
  $pdf->Cell($w['name'],  $rowH, (string)$r['material_name'],   1, 0, 'L', 1);

  $pdf->Cell($w['col_1'], $rowH, $lead_new,     1, 0, 'C', 1);
  $pdf->Cell($w['col_2'], $rowH, $bd_lead_new,  1, 0, 'C', 1);

  $pdf->Cell($w['col_1'], $rowH, $lead_old,     1, 0, 'C', 1);
  $pdf->Cell($w['col_2'], $rowH, $bd_lead_old,  1, 0, 'C', 1);

  $pdf->Cell($w['col_1'], $rowH, $ret_new,      1, 0, 'C', 1);
  $pdf->Cell($w['col_2'], $rowH, $bd_ret_new,   1, 0, 'C', 1);

  $pdf->Cell($w['col_1'], $rowH, $ret_old,      1, 0, 'C', 1);
  $pdf->Cell($w['col_2'], $rowH, $bd_ret_old,   1, 0, 'C', 1);

  $applySumColor($pdf, 'new', $sum_new);
  $pdf->Cell($w['sumcol'], $rowH, $fmtInt($sum_new), 1, 0, 'C', 1);

  $applySumColor($pdf, 'old', $sum_old);
  $pdf->Cell($w['sumcol'], $rowH, $fmtInt($sum_old), 1, 1, 'C', 1);

  $fill = !$fill;
}

// $pdf->SetTitle($filename);                          // 頁籤顯示使用同一字串
$pdf->setViewerPreferences(['DisplayDocTitle'=>true]); // 要求以 Title 為主（多數檢視器會遵守）
$pdf->Output($filename, 'I');                       // 下載/另存的預設檔名

