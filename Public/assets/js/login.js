// tpc_data_system/Public/assets/js/login.js
(function () {
  'use strict';

  const form = document.getElementById('loginForm');
  const btn  = document.getElementById('btnLogin');
  const err  = document.getElementById('loginError');

  async function postJSON(url, payload) {
    const res = await fetch(url, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(payload),
      credentials: 'same-origin',
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data?.success === false) {
      const msg = data?.message || `登入失敗（HTTP ${res.status}）`;
      throw new Error(msg);
    }
    return data;
  }

  form?.addEventListener('submit', async (e) => {
    e.preventDefault();
    err.textContent = '';
    btn.disabled = true;

    const payload = {
      username: form.username.value.trim(),
      password: form.password.value,
      csrf_token: document.getElementById('csrf_token').value
    };

    try {
      const out = await postJSON((window.LOGIN_ENDPOINT || 'login.php'), payload);
      // 成功 → 導頁
      window.location.href = out.redirect || '/tpc_data_system/Public/index.php';
    } catch (ex) {
      err.textContent = ex.message || '登入失敗，請稍後再試';
    } finally {
      btn.disabled = false;
    }
  });
})();
