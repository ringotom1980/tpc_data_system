<?php
// Public/modules/mat/m_data_statistics.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/auth.php';
require_login();

$page_title = '苗栗區處・材料管理科｜材料統計';

$cssFs = __DIR__ . '/../../assets/css/m_data_statistics.css';
$cssVer = is_file($cssFs) ? (string)filemtime($cssFs) : (string)time();
$jsFs  = __DIR__ . '/../../assets/js/m_data_statistics.js';
$jsVer = is_file($jsFs) ? (string)filemtime($jsFs) : (string)time();

include __DIR__ . '/../../partials/header.php';
?>
<script>
  window.PUBLIC_BASE = "<?= public_base() ?>";
</script>
<link rel="stylesheet" href="<?= public_base() ?>/assets/css/m_data_statistics.css?v=<?= htmlspecialchars($cssVer, ENT_QUOTES) ?>">

<!-- 滿版 -->
<div class="container-fluid py-3" data-page="mat-statistics">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h5 class="m-0">材料統計</h5>
    <div class="d-flex gap-2">
      <button id="btnRefresh" class="btn btn-outline-primary btn-sm" type="button">重新整理</button>
    </div>
  </div>

  <!-- 近一個月提領概覽 -->
  <section class="card mb-3" id="stats_overview_card">
    <div class="card-body">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <h6 class="card-title mb-0">近一個月提領概覽</h6>
        <span id="stats_overview_range" class="text-muted small">—</span>
      </div>
      <p class="text-muted small mb-2" id="stats_overview_hint">載入中…</p>
      <div id="stats_overview_grid"
           class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 row-cols-xxl-4 g-2"
           style="max-height: 360px; overflow:auto;"></div>
    </div>
  </section>

  <!-- 統計結果 -->
  <section class="card" id="stats_result_card" style="display:none;">
    <div class="card-header py-2">
      <div class="d-flex align-items-center justify-content-between">
        <div>
          <strong id="stats_result_title" class="small">—</strong>
          <span id="stats_result_sub" class="text-muted small ms-2">—</span>
        </div>
        <div class="d-flex align-items-center gap-2">
          <button id="btnPrintPdf" type="button" class="btn btn-outline-secondary btn-sm">列印 PDF</button>
        </div>
      </div>
    </div>

    <div class="card-body p-0">
      <div class="table-responsive" style="max-height: 70vh;">
        <table id="stats_detail_table" class="table table-sm align-middle mb-0">
          <thead class="table-light sticky-top">
            <tr>
              <th style="width:3%;"  rowspan="2" class="text-center">項次</th>
              <th style="width:6%;"  rowspan="2" class="text-center">材料編號</th>
              <th style="width:31%;" rowspan="2">材料名稱</th>

              <th style="width:26%;" colspan="4" class="text-center">領料</th>
              <th style="width:26%;" colspan="4" class="text-center">退料</th>
              <th style="width:8%;"  colspan="2" class="text-center">領、退料合計</th>
            </tr>
            <tr>
              <th style="width:13%;" colspan="2" class="text-center">新</th>
              <th style="width:13%;" colspan="2" class="text-center">舊</th>

              <th style="width:13%;" colspan="2" class="text-center">新</th>
              <th style="width:13%;" colspan="2" class="text-center">舊</th>

              <th style="width:4%;"  class="text-center">新</th>
              <th style="width:4%;"  class="text-center">舊</th>
            </tr>
          </thead>
          <tbody>
            <tr><td colspan="13" class="text-center text-muted">請從上方概覽選擇「日期＋承攬商」</td></tr>
          </tbody>
          <!-- 不要最底下合計列（tfoot 已移除） -->
        </table>
      </div>
    </div>

    <div class="card-footer small text-muted py-2">
      材料名稱順序依【資料編輯】頁面的【材料位置排序】決定，若要變更順序可至該頁面先做順序調整。
    </div>
  </section>
</div>

<script src="<?= public_base() ?>/assets/js/m_data_statistics.js?v=<?= htmlspecialchars($jsVer, ENT_QUOTES) ?>"></script>
<?php include __DIR__ . '/../../partials/footer.php'; ?>
