<?php
// Public/modules/mat/m_data_editing.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/auth.php';
require_login();

$page_title = '苗栗區處・材料管理科｜資料編輯';

// cache-busting for CSS
$cssFs  = __DIR__ . '/../../assets/css/m_data_editing.css';
$cssVer = is_file($cssFs) ? (string)filemtime($cssFs) : (string)time();

// favicon（作法A：在 include header.php 前先給定路徑與版本）
$page_favicon = '/tpc_data_system/Public/assets/img/tpc_logo.png';
$page_favicon_ver = is_file(__DIR__ . '/../../assets/img/tpc_logo.png')
  ? (string)filemtime(__DIR__ . '/../../assets/img/tpc_logo.png')
  : (string)time();

include __DIR__ . '/../../partials/header.php';
?>
<link rel="stylesheet" href="<?= public_base() ?>/assets/css/m_data_editing.css?v=<?= htmlspecialchars($cssVer, ENT_QUOTES) ?>">

<div class="container-xxl py-3">
  <div class="row g-3">

    <!-- 左欄（1/3）：上傳檔案、承攬商選擇/編輯、提領日期 -->
    <div class="col-12 col-lg-4">

      <!-- 上傳檔案 -->
      <section class="card mb-3">
        <div class="card-body">
          <h6 class="card-title mb-3">上傳檔案</h6>
          <div class="vstack gap-2">
            <input type="file" id="upload_files_input" class="form-control" multiple>
            <button id="upload_files_btn" class="btn btn-primary" type="button">上傳</button>
            <p class="text-muted small mb-0">先選擇提領日期及承攬商再上傳；支援多檔上傳。</p>
          </div>
        </div>
      </section>

      <!-- 承攬商選擇（右側有「編輯承攬商」） -->
      <section class="card mb-3">
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <h6 class="card-title mb-0">承攬商</h6>
            <button id="edit_contractor_btn" class="btn btn-sm btn-outline-secondary" type="button">編輯承攬商</button>
          </div>
          <select id="contractor_select" class="form-select">
            <option value=""></option>
            <!-- 後續由你載入清單 -->
          </select>
          <p class="text-muted small mb-0 mt-2">若承攬商未在選單內，請先編輯承攬商。</p>
        </div>
      </section>

      <!-- 提領日期 -->
      <section class="card mb-3">
        <div class="card-body">
          <h6 class="card-title mb-3">提領日期</h6>
          <div class="d-flex flex-wrap gap-2 align-items-center">
            <input type="date" id="withdraw_date" class="form-control">
          </div>
          <!-- <p class="text-muted small mb-0 mt-2">此日期將作為上傳與排序的參照條件。</p> -->
        </div>
      </section>

      <!-- 近一個月提領概覽（僅顯示） -->
      <section class="card mb-3" id="withdraw_overview_card">
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <h6 class="card-title mb-0">近一個月提領概覽</h6>
            <span id="withdraw_overview_range" class="text-muted small">—</span>
          </div>

          <p class="text-muted small mb-2" id="withdraw_overview_hint">載入中…</p>

          <!-- 日期→承攬商網格 -->
          <div id="withdraw_overview_grid" class="row row-cols-1 row-cols-sm-2 g-2 withdraw-overview-grid">
            <!-- 動態載入 -->
          </div>
        </div>
      </section>


    </div>

    <!-- 右欄（2/3）：材料位置排序（簡化版：拖曳＋即時搜尋＋儲存） -->
    <div class="col-12 col-lg-8">

      <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
        <h5 class="mb-0">材料位置排序</h5>
        <div class="d-flex gap-2 align-items-center">
          <!-- 即時搜尋：小尺寸，高度正常 -->
          <input id="matSearch" class="form-control form-control-sm" placeholder="搜尋材料編號/名稱" style="height:auto;">
          <!-- 一鍵儲存 -->
          <button id="btnSaveOrder" class="btn btn-sm btn-primary" type="button">儲存排序</button>
          <button id="btnToggleEdit" class="btn btn-outline-secondary btn-sm ms-2">編輯</button>
        </div>
      </div>

      <div class="table-responsive" style="max-height:70vh;overflow:auto;">
        <table id="materialsSortTable" class="table table-sm table-hover table-bordered align-middle">
          <thead class="table-light text-center">
            <tr>
              <th style="width:68px;">項次</th>
              <th style="width:120px;">材料編號</th>
              <th>材料名稱</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td colspan="3" class="text-center text-muted">載入中…</td>
            </tr>
          </tbody>
        </table>
      </div>

      <small class="text-muted d-block mt-1">提示：用滑鼠拖曳整列改變順序，完成後按「儲存排序」。</small>
    </div>


  </div>
  <!-- 承攬商編輯 Modal -->
  <div class="modal fade" id="contractorModal" tabindex="-1" aria-labelledby="contractorModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="contractorModalLabel">編輯承攬商</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body">
          <div class="table-responsive" style="max-height:60vh; overflow:auto;">
            <table class="table table-sm table-bordered align-middle" id="contractorTable">
              <thead class="table-light text-center">
                <tr>
                  <th style="width:70px;">項次</th>
                  <th style="width:160px;">承攬商代碼</th>
                  <th>承攬商名稱</th>
                  <th style="width:100px;">啟用</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td colspan="4" class="text-center text-muted">載入中…</td>
                </tr>
              </tbody>
            </table>
            <div class="border-top pt-3 mt-3">
              <div class="row g-2 align-items-end">
                <div class="col-sm-4">
                  <label class="form-label form-label-sm">承攬商代碼</label>
                  <input id="newContractorCode" class="form-control form-control-sm" placeholder="例：T16班">
                </div>
                <div class="col-sm-6">
                  <label class="form-label form-label-sm">承攬商名稱</label>
                  <input id="newContractorName" class="form-control form-control-sm" placeholder="例：境宏工程有限公司">
                </div>
                <div class="col-sm-2 d-grid">
                  <button id="addContractorBtn" class="btn btn-sm btn-outline-primary" type="button">新增</button>
                </div>
              </div>
            </div>

          </div>
        </div>

        <div class="modal-footer">
          <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">關閉</button>
          <button class="btn btn-primary" type="button" id="saveContractorsBtn">儲存</button>
        </div>
      </div>
    </div>
  </div>

  <script>
    document.title = '苗栗區處・材料管理科｜資料編輯';
  </script>
  <script>
    window.PUBLIC_BASE = "<?= public_base() ?>";
  </script>
  <script src="<?= public_base() ?>/assets/js/m_data_editing.js?v=<?= time() ?>"></script>
  <script src="<?= public_base() ?>/assets/js/m_upload_handler.js?v=<?= time() ?>"></script>


  <?php include __DIR__ . '/../../partials/footer.php'; ?>