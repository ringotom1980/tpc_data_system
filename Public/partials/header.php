<?php
declare(strict_types=1);

/**
 * 公用頁首（使用 auth.php 的 current_user()）
 * - 導覽列：資料編輯 / 統計報表（自動 active）
 * - 右上角顯示登入者 display_name（優先 current_user()['display_name']，退回 username）
 */

if (!function_exists('public_base')) {
    function public_base(): string
    {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $script = str_replace('\\', '/', $script);
        $pos = stripos($script, '/Public/');
        if ($pos !== false) return rtrim(substr($script, 0, $pos + 7), '/');
        return '/Public';
    }
}

// ---- 頁面標題 / 路徑 ----
$title = isset($page_title) && $page_title !== '' ? $page_title : '台電材料管理系統';
$BASE  = public_base();

// ---- Favicon ----
$__favicon = isset($page_favicon) && $page_favicon !== ''
    ? $page_favicon
    : $BASE . '/assets/img/tpc_logo.png';
$__faviconFs = realpath(__DIR__ . '/../assets/img/tpc_logo.png');
$__favver = $page_favicon_ver
    ?? ($__faviconFs && is_file($__faviconFs) ? (string)filemtime($__faviconFs) : (string)time());
$__fv_qs  = $__favver !== '' ? ('?v=' . rawurlencode((string)$__favver)) : '';

// ---- 目前頁面（決定 active）----
$script = $_SERVER['SCRIPT_NAME'] ?? '';
$is_edit = str_contains($script, '/modules/mat/m_data_editing.php');
$is_stat = str_contains($script, '/modules/mat/m_data_statistics.php');

// 目標連結
$url_edit = $BASE . '/modules/mat/m_data_editing.php';
$url_stat = $BASE . '/modules/mat/m_data_statistics.php';

// ---- 取得登入者 display_name（優先使用 auth.php 的 current_user()）----
$displayName = 'User';
try {
    // 若頁面已經 `require_once config/auth.php` 就會有 current_user()
    if (function_exists('current_user')) {
        $u = current_user();
        if (is_array($u)) {
            if (!empty($u['display_name'])) {
                $displayName = (string)$u['display_name'];
            } elseif (!empty($u['username'])) {
                $displayName = (string)$u['username'];
            }
        }
    } else {
        // 保險：沒載入 auth.php 時，直接讀 session
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $u = $_SESSION['user'] ?? null;
        if (is_array($u)) {
            if (!empty($u['display_name'])) {
                $displayName = (string)$u['display_name'];
            } elseif (!empty($u['username'])) {
                $displayName = (string)$u['username'];
            }
        }
    }
} catch (Throwable $e) {
    // 靜默忽略
}
?>
<!doctype html>
<html lang="zh-Hant-TW">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= htmlspecialchars($title, ENT_QUOTES) ?></title>

  <!-- Bootstrap CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet" crossorigin="anonymous">

  <!-- Header 專屬 CSS -->
  <link rel="stylesheet" href="<?= $BASE ?>/assets/css/header.css">

  <!-- Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

  <!-- Favicon -->
  <link rel="icon" href="<?= htmlspecialchars($__favicon . $__fv_qs, ENT_QUOTES) ?>" type="image/png">
  <link rel="apple-touch-icon" href="<?= htmlspecialchars($__favicon . $__fv_qs, ENT_QUOTES) ?>">
  <link rel="shortcut icon" href="<?= htmlspecialchars($__favicon . $__fv_qs, ENT_QUOTES) ?>" type="image/png">
</head>

<body data-public-base="<?= htmlspecialchars($BASE, ENT_QUOTES) ?>">

  <nav class="navbar navbar-expand-lg bg-body-tertiary border-bottom">
  <div class="container-fluid">
    <!-- 這行加了 me-4：品牌右側空隙更大 -->
    <a class="navbar-brand d-flex align-items-center me-4" href="<?= $BASE ?>/index.php">
      <img class="brand-logo me-2" src="<?= $BASE ?>/assets/img/tpc_logo.png" alt="Logo" style="height:28px;">
      <span>台電苗栗區處材料管理系統</span>
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav"
            aria-controls="mainNav" aria-expanded="false" aria-label="切換導覽">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="mainNav">
      <!-- 這行加了 ms-lg-2 與 gap-2：導覽群組與品牌拉開、項目之間留白 -->
      <ul class="navbar-nav ms-lg-3 me-auto mb-2 mb-lg-0 gap-2">
        <li class="nav-item">
          <a class="nav-link px-3 py-2 rounded-2 <?= $is_edit ? 'active' : '' ?>" href="<?= $url_edit ?>">
            <i class="bi bi-pencil-square me-1"></i> 資料編輯
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link px-3 py-2 rounded-2 <?= $is_stat ? 'active' : '' ?>" href="<?= $url_stat ?>">
            <i class="bi bi-bar-chart-line me-1"></i> 統計報表
          </a>
        </li>
      </ul>

      <div class="d-flex align-items-center gap-2">
        <span class="text-muted small">Hi, <?= htmlspecialchars($displayName, ENT_QUOTES) ?></span>
        <a class="btn btn-sm btn-outline-secondary" href="<?= $BASE ?>/auth/logout.php">登出</a>
      </div>
    </div>
  </div>
</nav>


  <script src="<?= $BASE ?>/assets/js/header.js"></script>
