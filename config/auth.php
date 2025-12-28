<?php
// tpc_data_system/config/auth.php
declare(strict_types=1);

/**
 * 啟動安全的 Session、提供 CSRF、登入/登出、取得使用者、與 require_login()
 * 其他頁面引入本檔後，可直接呼叫 require_login();
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
  // 安全的 session cookie 設定
  $secure   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
  $httponly = true;
  $samesite = 'Lax';
  // PHP 7.3+ 支援 samesite 參數
  session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $secure,
    'httponly' => $httponly,
    'samesite' => $samesite,
  ]);
  session_name('tpc_session');
  session_start();
}

// ===== CSRF =====
function csrf_token(): string {
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf_token'];
}
function verify_csrf(?string $token): bool {
  return is_string($token) && isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// ===== 使用者 / 登入 / 登出 =====
function login_user(int $user_id, string $username, string $role, ?string $display_name = null): void {
  session_regenerate_id(true);
  $_SESSION['user'] = [
    'id'           => $user_id,
    'username'     => $username,
    'role'         => $role,
    'display_name' => $display_name ?? $username,
    'logged_in_at' => time(),
  ];
}

function logout_user(): void {
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'] ?? false, $params['httponly'] ?? true);
  }
  session_destroy();
}

function current_user(): ?array {
  return $_SESSION['user'] ?? null;
}

function require_login(): void {
  if (!isset($_SESSION['user'])) {
    // 導回登入頁（相對於 /Public/ 下的其他頁面）
    header('Location: /tpc_data_system/Public/auth/login.php');
    exit;
  }
}
