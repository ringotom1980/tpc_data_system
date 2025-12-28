// Public/assets/js/m_upload_handler.js
/* global Swal, bootstrap */
(function () {
  'use strict';

  const BASE = (window.PUBLIC_BASE || '').replace(/\/+$/, '');
  const API = BASE + '/modules/mat/m_upload_handler.php';

  const $file =
    document.getElementById('upload_files_input') || document.getElementById('upload_file');
  const $btn =
    document.getElementById('upload_files_btn') || document.getElementById('btnUpload');
  const $contractor = document.getElementById('contractor_select');
  const $date = document.getElementById('withdraw_date');

  if (!$btn) return;

  function esc(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  // ★ 修正：優先讀取 data-code，若沒有再回退文字切割
  function getContractorCode() {
    const opt = $contractor?.selectedOptions?.[0];
    if (!opt) return '';
    const dc = (opt.dataset?.code || '').trim();
    if (dc) return dc;
    const txt = (opt.textContent || '').trim();
    return (txt.split('-')[0] || '').trim();
  }

  function ensureUnknownModal() {
    if (document.getElementById('unknownModal')) return;
    document.body.insertAdjacentHTML(
      'beforeend',
      `
<div class="modal fade" id="unknownModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title">發現新材料編號（請勾選要加入材料清單）</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="關閉"></button>
    </div>
    <div class="modal-body">
      <p class="text-muted small mb-2">取消勾選或刪除＝此次不上到材料清單（但上傳明細仍會寫入）。</p>
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead><tr>
            <th style="width:56px;" class="text-center"><input id="chkAllUnknown" class="form-check-input" type="checkbox" checked></th>
            <th style="width:200px;">材料編號</th>
            <th>名稱/規格</th>
            <th style="width:56px;"></th>
          </tr></thead>
          <tbody id="unknownTbody"></tbody>
        </table>
      </div>
    </div>
    <div class="modal-footer">
      <button id="unknownCancel" type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">取消</button>
      <button id="unknownConfirm" type="button" class="btn btn-primary">確定新增並完成匯入</button>
    </div>
  </div></div>
</div>`,
    );
  }

  function presentUnknownModal(unknownMap) {
    // unknownMap: {material_number => name_spec}
    ensureUnknownModal();
    const modalEl = document.getElementById('unknownModal');
    const tbody = document.getElementById('unknownTbody');
    const chkAll = document.getElementById('chkAllUnknown');
    const btnOk = document.getElementById('unknownConfirm');

    const entries = Object.entries(unknownMap); // [mn, name]
    tbody.innerHTML = entries
      .map(
        ([mn, name]) => `
      <tr data-no="${esc(mn)}">
        <td class="text-center"><input class="form-check-input chkRow" type="checkbox" checked></td>
        <td><code>${esc(mn)}</code></td>
        <td>${esc(name || '')}</td>
        <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger btnDel">刪除</button></td>
      </tr>`,
      )
      .join('');

    chkAll.onchange = () =>
      tbody.querySelectorAll('.chkRow').forEach((c) => (c.checked = chkAll.checked));
    tbody.onclick = (ev) => {
      const b = ev.target.closest('.btnDel');
      if (!b) return;
      b.closest('tr')?.remove();
    };

    return new Promise((resolve) => {
      let ok = false;
      btnOk.onclick = () => {
        const addList = Array.from(tbody.querySelectorAll('tr'))
          .filter((tr) => tr.querySelector('.chkRow')?.checked)
          .map((tr) => tr.getAttribute('data-no'))
          .filter(Boolean);
        if (!addList.length) {
          Swal.fire({
            icon: 'question',
            title: '不新增任何新料號到材料清單？',
            text: '按「是」僅寫入上傳明細（不補材料清單）。',
            showCancelButton: true,
            confirmButtonText: '是，直接匯入',
            cancelButtonText: '返回選擇',
          }).then((ans) => {
            if (ans.isConfirmed) {
              ok = true;
              bootstrap.Modal.getOrCreateInstance(modalEl).hide();
              resolve([]);
            }
          });
          return;
        }
        ok = true;
        bootstrap.Modal.getOrCreateInstance(modalEl).hide();
        resolve(addList);
      };
      modalEl.addEventListener(
        'hidden.bs.modal',
        () => {
          if (!ok) resolve(null);
        },
        { once: true },
      );

      Swal.close();
      bootstrap.Modal.getOrCreateInstance(modalEl).show();
    });
  }

  async function analyzeFile(file, contractorCode, date) {
    const fd = new FormData();
    fd.append('action', 'analyze');
    fd.append('file', file);
    fd.append('contractor_code', contractorCode);
    fd.append('withdraw_date', date);
    const res = await fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' });
    if (!res.ok) throw new Error(`分析失敗（${res.status}）`);
    const data = await res.json();
    if (!data?.success) throw new Error(data?.message || '分析失敗');
    return data; // { token, unknown_numbers[], preview_count, file }
  }

  async function confirmBatch(tokens, addList) {
    const res = await fetch(API, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'confirm_batch', tokens, add_numbers: addList || [] }),
    });
    if (!res.ok) throw new Error(`寫入失敗（${res.status}）`);
    const data = await res.json();
    if (!data?.success) throw new Error(data?.message || '寫入失敗');
    return data; // { added_to_master, inserted_rows }
  }

  $btn.addEventListener('click', async (e) => {
    e.preventDefault();

    const files = Array.from($file?.files || []);
    const code = getContractorCode();
    const date = ($date?.value || '').trim();

    if (!files.length) {
      Swal.fire({ icon: 'warning', title: '請先選擇檔案' });
      return;
    }
    if (!code && !date) {
      Swal.fire({ icon: 'warning', title: '請先選擇承攬商及提領日期' });
      return;
    }
    if (!code) {
      Swal.fire({ icon: 'warning', title: '請先選擇承攬商' });
      return;
    }
    if (!date) {
      Swal.fire({ icon: 'warning', title: '請先選擇提領日期' });
      return;
    }

    Swal.fire({
      title: '分析中…',
      html: `0 / ${files.length}`,
      allowOutsideClick: false,
      showConfirmButton: false,
      didOpen: () => Swal.showLoading(),
    });

    try {
      const tokens = [];
      const unknownMap = {}; // mn => name
      for (let i = 0; i < files.length; i++) {
        const f = files[i];
        Swal.update({ html: `${i + 1} / ${files.length}<br><small>${esc(f.name)}</small>` });
        const r = await analyzeFile(f, code, date);
        tokens.push(r.token);
        if (Array.isArray(r.unknown_numbers)) {
          for (const u of r.unknown_numbers) {
            const mn = String(u.material_number ?? '');
            if (!mn) continue;
            if (!(mn in unknownMap)) unknownMap[mn] = u.name_specification || '';
          }
        }
      }

      // 若有新料號 → 讓使用者一次勾選
      let addList = [];
      if (Object.keys(unknownMap).length) {
        const picked = await presentUnknownModal(unknownMap);
        if (picked === null) throw new Error('已取消上傳流程');
        addList = picked; // 可能是空陣列（代表不上材料清單）
      }

      // 開始寫入
      Swal.fire({
        title: '寫入中…',
        allowOutsideClick: false,
        showConfirmButton: false,
        didOpen: () => Swal.showLoading(),
      });
      const res = await confirmBatch(tokens, addList);

      // ✅ 寫入成功 → 通知左欄概覽刷新（m_data_editing.js 會收到 mat:uploaded 並呼叫 loadOverview）
      document.dispatchEvent(new CustomEvent('mat:uploaded'));

      // ✅ 清空承攬商與提領日期
      if ($contractor) {
        $contractor.selectedIndex = 0;
        $contractor.dispatchEvent(new Event('change', { bubbles: true }));
      }
      if ($date) {
        $date.value = '';
        $date.dispatchEvent(new Event('change', { bubbles: true }));
      }

      Swal.update({
        icon: 'success',
        title: '完成',
        html: `材料清單新增：<b>${res.added_to_master}</b> 筆<br>明細寫入：<b>${res.inserted_rows}</b> 筆`,
        showConfirmButton: true,
      });
      if ($file) $file.value = '';
    } catch (err) {
      Swal.update({
        icon: 'error',
        title: '上傳中止',
        text: err?.message || '發生錯誤',
        showConfirmButton: true,
      });
    }
  });
})();
