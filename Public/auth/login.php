<?php
// tpc_data_system/Public/auth/login.php
declare(strict_types=1);

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db_connection.php'; // 提供 $conn = PDO

// === POST：AJAX 登入 ===
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  header('Content-Type: application/json; charset=utf-8');

  $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
  $username = trim((string)($data['username'] ?? ''));
  $password = (string)($data['password'] ?? '');
  $token    = (string)($data['csrf_token'] ?? '');

  if (!verify_csrf($token)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'CSRF 驗證失敗']);
    exit;
  }
  if ($username === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '請輸入帳號與密碼']);
    exit;
  }

  try {
        $stmt = $conn->prepare('SELECT user_id, username, password_hash, display_name, role, is_active FROM users WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => $username]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // === 將原本合併的 401 拆開，便於定位 ===
    if (!$row) {
      http_response_code(401);
      echo json_encode(['success' => false, 'message' => '使用者不存在']);
      exit;
    }
    if (!$row['is_active']) {
      http_response_code(401);
      echo json_encode(['success' => false, 'message' => '帳號未啟用']);
      exit;
    }
    if (!password_verify($password, (string)$row['password_hash'])) {
      http_response_code(401);
      echo json_encode(['success' => false, 'message' => '密碼錯誤']);
      exit;
    }


    login_user((int)$row['user_id'], (string)$row['username'], (string)$row['role'], (string)$row['display_name']);

    try {
      $upd = $conn->prepare('UPDATE users SET last_login = NOW() WHERE user_id = :id');
      $upd->execute([':id' => (int)$row['user_id']]);
    } catch (\Throwable $e) { /* ignore */ }

    echo json_encode([
      'success'  => true,
      'message'  => '登入成功',
      'redirect' => '/tpc_data_system/Public/modules/mat/m_data_editing.php'
    ]);
    exit;

  } catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '伺服器錯誤，請稍後再試']);
    exit;
  }
}

// === GET：顯示登入頁 ===
$csrf = csrf_token();
?>
<!doctype html>
<html lang="zh-Hant">
<head>
  <meta charset="utf-8">
  <title>登入｜苗栗區處材料科管理系統</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#0a4da2">
  <meta name="color-scheme" content="light only">

  <!-- 頁籤 LOGO（favicon） -->
  <link rel="icon" href="../assets/img/tpc_logo.png" type="image/png">
  <link rel="apple-touch-icon" href="../assets/img/tpc_logo.png">

  <!-- 登入頁樣式 -->
  <link rel="stylesheet" href="../assets/css/login.css?v=<?= time() ?>">
</head>
<body>
  <div class="login-wrap">
    <div class="login-card">
      <div class="brand">
        <img src="../assets/img/tpc_logo.png" alt="TPC LOGO" class="logo" width="96" height="96">
        <h1>苗栗區處材料科管理系統</h1>
        <p class="subtitle">TPC Data System</p>
      </div>

      <form id="loginForm" autocomplete="off" class="login-form">
        <input type="hidden" name="csrf_token" id="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">

        <div class="form-group">
          <label for="username">帳號</label>
          <input type="text" id="username" name="username" placeholder="輸入帳號" required>
        </div>

        <div class="form-group">
          <label for="password">密碼</label>
          <input type="password" id="password" name="password" placeholder="輸入密碼" required>
        </div>

        <button type="submit" id="btnLogin" class="btn-primary">登入</button>
        <div class="error" id="loginError" role="alert" aria-live="polite"></div>
      </form>

      
    </div>

    <footer class="login-footer">
      <span>© <?= date('Y') ?> Taiwan Power Company · Miaoli District</span>
    </footer>
  </div>

  <script>
    window.LOGIN_ENDPOINT = 'login.php';
  </script>
  <script src="../assets/js/login.js?v=<?= time() ?>"></script>
</body>
</html>
