/* Public/assets/js/m_data_editing.js */
/* 材料排序：拖曳 + 即時搜尋（前端），SweetAlert2 提示，無微調、無位置欄位 */

(function () {
  'use strict';
  if (window.__MAT_SORT_INIT__) return;
  window.__MAT_SORT_INIT__ = true;
  const BASE = (window.PUBLIC_BASE || '').replace(/\/+$/, '');
  const API = BASE + '/modules/mat/m_data_editing_backend.php';

  const table = document.getElementById('materialsSortTable');
  if (!table) return;
  const tbody = table.tBodies[0];
  const inputQ = document.getElementById('matSearch');
  const btnSave = document.getElementById('btnSaveOrder');

  let allItems = []; // 全集
  let viewItems = []; // 當前顯示（搜尋後）

  const h = (s) =>
    String(s ?? '').replace(
      /[&<>"']/g,
      (m) =>
        ({
          '&': '&amp;',
          '<': '&lt;',
          '>': '&gt;',
          '"': '&quot;',
          "'": '&#39;',
        })[m],
    );

  function rowTpl(item, idx) {
    return `
      <tr draggable="true" data-mat="${h(item.material_number)}">
        <td class="text-center seq">${idx + 1}</td>
        <td class="text-center">${h(item.material_number)}</td>
        <td>${h(item.name_specification)}</td>
      </tr>
    `;
  }

  function render(list) {
    if (!list || list.length === 0) {
      tbody.innerHTML = `<tr><td colspan="3" class="text-center text-muted">無資料</td></tr>`;
      return;
    }
    tbody.innerHTML = list.map(rowTpl).join('');
    bindDrag();
  }

  function renumber() {
    [...tbody.querySelectorAll('tr')].forEach((tr, i) => {
      const seq = tr.querySelector('.seq');
      if (seq) seq.textContent = String(i + 1);
    });
  }

  // 只保留拖曳
  function bindDrag() {
    let dragging = null;

    tbody.querySelectorAll('tr').forEach((tr) => {
      tr.addEventListener('dragstart', (e) => {
        dragging = tr;
        tr.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
      });

      tr.addEventListener('dragend', () => {
        dragging?.classList.remove('dragging');
        dragging = null;
        renumber();
      });

      tr.addEventListener('dragover', (e) => {
        e.preventDefault();
        const target = e.currentTarget;
        if (!dragging || dragging === target) return;
        const rect = target.getBoundingClientRect();
        const halfway = rect.top + rect.height / 2;
        if (e.clientY < halfway) {
          tbody.insertBefore(dragging, target);
        } else {
          tbody.insertBefore(dragging, target.nextSibling);
        }
      });
    });
  }

  /* ===== 近一個月提領概覽（日期 → 承攬商 chips）===== */
  (function () {
    const grid = document.getElementById('withdraw_overview_grid');
    const hint = document.getElementById('withdraw_overview_hint');
    const range = document.getElementById('withdraw_overview_range');

    // 若這張卡片不在頁面上就跳出，不影響其他功能
    if (!grid || !hint || !range) return;

    const WEEK = ['日', '一', '二', '三', '四', '五', '六'];

    function fmtDateLabel(iso) {
      // 'YYYY-MM-DD' -> { label: 'M/D', wk: '週X' }
      const d = new Date(iso + 'T00:00:00');
      return { label: `${d.getMonth() + 1}/${d.getDate()}`, wk: `週${WEEK[d.getDay()]}` };
    }

    function renderDays(days) {
      const frag = document.createDocumentFragment();

      days.forEach(({ date, total_contractors, contractors }) => {
        const { label, wk } = fmtDateLabel(date);

        const col = document.createElement('div');
        col.className = 'col';
        col.innerHTML = `
          <div class="withdraw-day">
            <div class="day-head">
              <div>
                <span class="date-pill">${label}</span>
                <span class="weekday">${wk}</span>
              </div>
              <span class="badge text-bg-secondary">${total_contractors} 家</span>
            </div>
            <div class="chips">
              ${(contractors || [])
            .map(
              (c) => `
            <span class="chip" data-date="${date}" data-contractor="${c.contractor_code ?? ''}">
              <span class="code fw-semibold">${c.contractor_code ?? ''}</span>
              <span class="cnt text-muted ms-1">(${c.cnt ?? 0})</span>
            </span>
              `,
            )
            .join('')}
            </div>
          </div>
        `;
        frag.appendChild(col);
      });

      grid.innerHTML = '';
      grid.appendChild(frag);
    }

    async function loadOverview() {
      hint.textContent = '載入中…';
      grid.innerHTML = '';
      range.textContent = '—';

      try {
        const url = new URL(API, location.origin);
        url.searchParams.set('action', 'withdraw_overview_last_month');

        const res = await fetch(url.toString(), {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
        });
        const data = await res.json();

        if (!data?.success) {
          hint.textContent = data?.message || '讀取失敗';
          return;
        }

        range.textContent = `${data.start_date} ～ ${data.end_date}`;
        const days = Array.isArray(data.days) ? data.days : [];
        if (days.length === 0) {
          hint.textContent = '近一個月內沒有提領紀錄。';
          grid.innerHTML = '';
          return;
        }

        hint.textContent = `有紀錄的日期：${data.total_days} 天(點擊承攬商可選擇刪除單號)`;
        renderDays(days);
      } catch (err) {
        console.error(err);
        hint.textContent = '讀取失敗，請稍後再試';
      }
    }
    // ===== 明細 Modal（點 chip 開啟）=====
    const modalEl = document.getElementById('withdrawVouchersModal');
    const tbodyDetail = document.getElementById('withdrawVouchersTbody');
    const metaEl = document.getElementById('withdrawVouchersMeta');
    const countEl = document.getElementById('withdrawVouchersCount');

    let currentDetail = { date: '', contractor: '' };

    function escHtml(s) {
      return String(s ?? '').replace(/[&<>"']/g, (m) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
      })[m]);
    }

    function openDetailModal(date, contractor) {
      if (!modalEl || !tbodyDetail || !metaEl || !countEl) return;

      currentDetail.date = date;
      currentDetail.contractor = contractor;

      metaEl.textContent = `${date}｜${contractor}`;
      countEl.textContent = '—';
      tbodyDetail.innerHTML = `<tr><td colspan="3" class="text-center text-muted">載入中…</td></tr>`;

      bootstrap.Modal.getOrCreateInstance(modalEl).show();
      loadDetail();
    }

    async function loadDetail() {
      try {
        const url = new URL(API, location.origin);
        url.searchParams.set('action', 'withdraw_overview_detail');
        url.searchParams.set('withdraw_date', currentDetail.date);
        url.searchParams.set('contractor_code', currentDetail.contractor);

        const res = await fetch(url.toString(), {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
        });
        const data = await res.json();
        if (!data?.success) throw new Error(data?.message || '讀取失敗');

        const items = Array.isArray(data.items) ? data.items : [];
        countEl.textContent = `共 ${items.length} 筆`;

        if (items.length === 0) {
          tbodyDetail.innerHTML = `<tr><td colspan="3" class="text-center text-muted">無資料</td></tr>`;
          return;
        }

        tbodyDetail.innerHTML = items.map((it, i) => `
          <tr>
            <td class="text-center">${i + 1}</td>
            <td class="text-center fw-semibold">${escHtml(it.voucher_base)}</td>
            <td class="text-center">
              <button type="button" class="btn btn-sm btn-outline-danger btn-withdraw-del"
                data-base="${escHtml(it.voucher_base)}">刪除</button>
            </td>
          </tr>
        `).join('');
      } catch (err) {
        console.error(err);
        tbodyDetail.innerHTML = `<tr><td colspan="3" class="text-center text-danger">讀取失敗</td></tr>`;
      }
    }

    async function deleteBase(base) {
      // 不 confirm、不提示；失敗只 console
      try {
        const res = await fetch(`${API}?action=withdraw_overview_delete`, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            withdraw_date: currentDetail.date,
            contractor_code: currentDetail.contractor,
            voucher_base: base,
          }),
        });
        const data = await res.json();
        if (!data?.success) throw new Error(data?.message || '刪除失敗');

        // ① 重新載入明細
        await loadDetail();
        // ② 刷新概覽（chip cnt 會跟著變）
        await loadOverview();
        // ✅ 刪除成功提示（保留你要的訊息）
        Swal.fire({
          icon: 'success',
          title: `單號:${base} 已刪除`,
          timer: 900,
          showConfirmButton: false
        });
      } catch (err) {
        console.error(err);
      }
    }

    // 點 chip：開明細
    grid.addEventListener('click', (e) => {
      const chip = e.target.closest('.chip');
      if (!chip) return;
      const date = chip.getAttribute('data-date') || '';
      const contractor = chip.getAttribute('data-contractor') || '';
      if (!date || !contractor) return;
      openDetailModal(date, contractor);
    });

    // 點明細刪除：直接刪
    document.addEventListener('click', (e) => {
      const btn = e.target.closest('.btn-withdraw-del');
      if (!btn) return;
      const base = btn.getAttribute('data-base') || '';
      if (!base) return;
      deleteBase(base);
    });

    // 初始化
    loadOverview();

    // 匯出給外部呼叫 + 監聽自訂事件
    window.MAT = window.MAT || {};
    window.MAT.refreshWithdrawOverview = loadOverview;
    document.addEventListener('mat:uploaded', loadOverview);
  })();

  async function fetchAll() {
    tbody.innerHTML = `<tr><td colspan="3" class="text-center text-muted">載入中…</td></tr>`;
    const url = new URL(API, location.origin);
    url.searchParams.set('action', 'list_for_sort');

    try {
      const res = await fetch(url.toString(), { credentials: 'same-origin' });
      const data = await res.json();
      if (!data?.success) throw new Error('load fail');
      allItems = data.items || [];
      viewItems = allItems.slice();
      render(viewItems);
    } catch (err) {
      tbody.innerHTML = `<tr><td colspan="3" class="text-danger text-center">載入失敗</td></tr>`;
      Swal.fire({ icon: 'error', title: '載入失敗', text: err.message || '' });
    }
  }

  function applyFilter(q) {
    q = (q || '').trim().toLowerCase();
    if (!q) {
      viewItems = allItems.slice();
      render(viewItems);
      return;
    }
    viewItems = allItems.filter((it) => {
      const no = (it.material_number || '').toString().toLowerCase();
      const nm = (it.name_specification || '').toString().toLowerCase();
      return no.includes(q) || nm.includes(q);
    });
    render(viewItems);
  }

  // 只貼需要改的 saveOrder；其餘維持你現有版本
  async function saveOrder() {
    const items = [...tbody.querySelectorAll('tr')]
      .map((tr) => tr.getAttribute('data-mat'))
      .filter(Boolean);

    if (items.length === 0) {
      Swal.fire({ icon: 'info', title: '沒有可儲存的項目' });
      return;
    }

    // 先開一個「loading」彈窗
    Swal.fire({
      title: '儲存中…',
      allowOutsideClick: false,
      showConfirmButton: false,
      didOpen: () => Swal.showLoading(),
    });

    try {
      const url = `${API}?action=save_order`;
      const res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ items }),
      });
      const data = await res.json();
      if (!data?.success) throw new Error(data?.message || '儲存失敗');
      // ✅ 通知概覽刷新
      document.dispatchEvent(new CustomEvent('mat:uploaded'));
      await fetchAll();

      // 同一個視窗改成成功狀態（不要再開新的 Swal.fire）
      // 成功
      Swal.hideLoading();
      Swal.stopTimer?.(); // 若之前有設 timer，先停掉
      Swal.update({
        icon: 'success',
        title: '已儲存',
        text: '',
        showConfirmButton: true,
        confirmButtonText: '關閉',
        showCloseButton: true, // 右上角「X」
        allowOutsideClick: true, // 點外面可關
        allowEscapeKey: true, // ESC 可關
        // 不要再設 timer，改成手動關
      });
    } catch (err) {
      // 失敗
      Swal.hideLoading();
      Swal.stopTimer?.();
      Swal.update({
        icon: 'error',
        title: '儲存失敗',
        text: err?.message || '',
        showConfirmButton: true,
        confirmButtonText: '關閉',
        showCloseButton: true,
        allowOutsideClick: true,
        allowEscapeKey: true,
      });
    }
  }

  // 即時搜尋
  if (inputQ) {
    let t;
    inputQ.addEventListener('input', () => {
      clearTimeout(t);
      t = setTimeout(() => applyFilter(inputQ.value), 120);
    });
  }
  if (btnSave) btnSave.addEventListener('click', saveOrder);

  /* ===== 承攬商：動態下拉 + 編輯彈窗 ===== */
  (function () {
    const sel = document.getElementById('contractor_select');
    const btn = document.getElementById('edit_contractor_btn');
    if (!sel && !btn) return;

    async function loadContractorSelect() {
      try {
        const res = await fetch(`${API}?action=contractors_list`, {
          credentials: 'same-origin',
        });
        const data = await res.json();
        if (!data?.success) throw new Error(data?.message || '載入失敗');

        // 保留第一個「全部承攬商」
        const keepFirst = sel && sel.options.length ? sel.options[0] : null;
        if (sel) sel.innerHTML = '';
        if (sel && keepFirst) sel.appendChild(keepFirst);

        (data.items || []).forEach((c) => {
          const opt = document.createElement('option');
          const code = (c.contractor_code || '').trim();
          const name = (c.contractor_name || '').trim();
          opt.value = c.contractor_id;
          // ★ 加上 data-code，供上傳模組穩定讀取代碼
          opt.dataset.code = code;
          opt.textContent = (code ? `${code}-` : '') + name; // 例如：T16班-境宏工程有限公司
          sel?.appendChild(opt);
        });
      } catch (err) {
        Swal.fire({ icon: 'error', title: '承攬商清單載入失敗', text: err?.message || '' });
      }
    }

    async function openContractorEditor() {
      const tbody = document.querySelector('#contractorTable tbody');
      if (tbody)
        tbody.innerHTML = `<tr><td colspan="4" class="text-center text-muted">載入中…</td></tr>`;

      try {
        const res = await fetch(`${API}?action=contractors_get_all`, {
          credentials: 'same-origin',
        });
        const data = await res.json();
        if (!data?.success) throw new Error(data?.message || '載入失敗');

        const rows = (data.items || [])
          .map(
            (c, i) => `
        <tr data-id="${c.contractor_id}">
          <td class="text-center">${i + 1}</td>
          <td><input class="form-control form-control-sm code" value="${h(c.contractor_code || '')}" placeholder="例：T16班"></td>
          <td><input class="form-control form-control-sm name" value="${h(c.contractor_name || '')}" required></td>
          <td class="text-center">
            <input class="form-check-input active" type="checkbox" ${c.is_active ? 'checked' : ''}>
          </td>
        </tr>
      `,
          )
          .join('');
        if (tbody)
          tbody.innerHTML =
            rows || `<tr><td colspan="4" class="text-center text-muted">無資料</td></tr>`;
      } catch (err) {
        if (tbody)
          tbody.innerHTML = `<tr><td colspan="4" class="text-danger text-center">載入失敗</td></tr>`;
        Swal.fire({ icon: 'error', title: '載入失敗', text: err?.message || '' });
      }

      const modalEl = document.getElementById('contractorModal');
      if (modalEl) bootstrap.Modal.getOrCreateInstance(modalEl).show();

      const saveBtn = document.getElementById('saveContractorsBtn');
      if (saveBtn) {
        saveBtn.onclick = saveContractors; // 每次開啟時掛一次最新的
      }
    }

    async function saveContractors() {
      const rows = Array.from(document.querySelectorAll('#contractorTable tbody tr'));
      const items = rows.map((tr) => ({
        contractor_id: parseInt(tr.getAttribute('data-id'), 10) || 0,
        contractor_code: tr.querySelector('.code')?.value.trim() || null,
        contractor_name: tr.querySelector('.name')?.value.trim() || '',
        is_active: tr.querySelector('.active')?.checked ? 1 : 0,
      }));

      if (items.some((x) => !x.contractor_name)) {
        Swal.fire({ icon: 'warning', title: '有「承攬商名稱」未填' });
        return;
      }

      Swal.fire({
        title: '儲存中…',
        allowOutsideClick: false,
        showConfirmButton: false,
        didOpen: () => Swal.showLoading(),
      });
      try {
        const res = await fetch(`${API}?action=contractors_save`, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ items }),
        });
        const data = await res.json();
        if (!data?.success) throw new Error(data?.message || '儲存失敗');

        await loadContractorSelect(); // 更新下拉
        Swal.hideLoading();
        Swal.update({
          icon: 'success',
          title: '已儲存',
          showConfirmButton: true,
          confirmButtonText: '關閉',
          showCloseButton: true,
          allowOutsideClick: true,
        });
      } catch (err) {
        Swal.hideLoading();
        Swal.update({
          icon: 'error',
          title: '儲存失敗',
          text: err?.message || '',
          showConfirmButton: true,
          showCloseButton: true,
          allowOutsideClick: true,
        });
      }
    }

    // 初始化
    loadContractorSelect();
    btn?.addEventListener('click', openContractorEditor);
  })();

  /* ===== 承攬商：新增／排序／儲存後關閉 ===== */
  (function () {
    const sel = document.getElementById('contractor_select');
    const btn = document.getElementById('edit_contractor_btn');
    const modalEl = document.getElementById('contractorModal');
    const tbody = document.querySelector('#contractorTable tbody');

    if (!sel && !btn) return;

    // 依「承攬商代碼」排序（英→數→其他），同時二次鍵：代碼再名稱
    function sortByCode(items) {
      const group = (code) => {
        if (!code) return 2;
        if (/^[A-Za-z]/.test(code)) return 0;
        if (/^[0-9]/.test(code)) return 1;
        return 2;
      };
      return items.sort((a, b) => {
        const ca = (a.contractor_code || '') + '';
        const cb = (b.contractor_code || '') + '';
        const ga = group(ca),
          gb = group(cb);
        if (ga !== gb) return ga - gb;
        const p = ca.localeCompare(cb, undefined, { numeric: true, sensitivity: 'base' });
        if (p !== 0) return p;
        return (a.contractor_name || '').localeCompare(b.contractor_name || '', 'zh-Hant');
      });
    }

    async function loadContractorSelect() {
      const res = await fetch(`${API}?action=contractors_list`, { credentials: 'same-origin' });
      const data = await res.json();
      if (!data?.success) throw new Error(data?.message || '載入失敗');

      if (sel) {
        const keepFirst = sel.options.length ? sel.options[0] : null;
        sel.innerHTML = '';
        if (keepFirst) sel.appendChild(keepFirst);

        // 伺服器已排序過，這裡再穩妥一次
        sortByCode(data.items || []).forEach((c) => {
          const opt = document.createElement('option');
          const code = (c.contractor_code || '').trim();
          const name = (c.contractor_name || '').trim();
          opt.value = c.contractor_id;
          // ★ 加上 data-code，供上傳模組穩定讀取代碼
          opt.dataset.code = code;
          opt.textContent = (code ? `${code}-` : '') + name; // T16班-境宏工程有限公司
          sel.appendChild(opt);
        });
      }
    }

    async function openContractorEditor() {
      if (tbody)
        tbody.innerHTML = `<tr><td colspan="4" class="text-center text-muted">載入中…</td></tr>`;

      try {
        const res = await fetch(`${API}?action=contractors_get_all`, {
          credentials: 'same-origin',
        });
        const data = await res.json();
        if (!data?.success) throw new Error(data?.message || '載入失敗');

        const items = sortByCode(data.items || []);
        const rows = items
          .map(
            (c, i) => `
        <tr data-id="${c.contractor_id}">
          <td class="text-center">${i + 1}</td>
          <td><input class="form-control form-control-sm code" value="${h(c.contractor_code || '')}" placeholder="例：T16班"></td>
          <td><input class="form-control form-control-sm name" value="${h(c.contractor_name || '')}" required></td>
          <td class="text-center"><input class="form-check-input active" type="checkbox" ${c.is_active ? 'checked' : ''}></td>
        </tr>
      `,
          )
          .join('');
        if (tbody)
          tbody.innerHTML =
            rows || `<tr><td colspan="4" class="text-center text-muted">無資料</td></tr>`;
      } catch (err) {
        if (tbody)
          tbody.innerHTML = `<tr><td colspan="4" class="text-danger text-center">載入失敗</td></tr>`;
        Swal.fire({ icon: 'error', title: '載入失敗', text: err?.message || '' });
      }

      // 先顯示 Modal
      if (modalEl) bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    // 新增：讀取下方兩欄，呼叫後端插入，插入後重新拉一次列表並重排
    async function addContractor() {
      const codeInput = document.getElementById('newContractorCode');
      const nameInput = document.getElementById('newContractorName');
      const code = (codeInput?.value || '').trim();
      const name = (nameInput?.value || '').trim();

      if (!name) {
        Swal.fire({ icon: 'warning', title: '請輸入承攬商名稱' });
        return;
      }

      try {
        const res = await fetch(`${API}?action=contractors_add`, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ contractor_code: code, contractor_name: name, is_active: 1 }),
        });
        const data = await res.json();
        if (!data?.success) throw new Error(data?.message || '新增失敗');

        // 清空輸入框
        if (codeInput) codeInput.value = '';
        if (nameInput) nameInput.value = '';

        // 重新載入表格與下拉（都會依代碼排序）
        await openContractorEditor(); // 會刷新表格
        await loadContractorSelect(); // 會重建下拉
        Swal.fire({ icon: 'success', title: '已新增', timer: 1000, showConfirmButton: false });
      } catch (err) {
        Swal.fire({ icon: 'error', title: '新增失敗', text: err?.message || '' });
      }
    }

    async function saveContractors() {
      const rows = Array.from(document.querySelectorAll('#contractorTable tbody tr'));
      const items = rows.map((tr) => ({
        contractor_id: parseInt(tr.getAttribute('data-id'), 10) || 0,
        contractor_code: tr.querySelector('.code')?.value.trim() || null,
        contractor_name: tr.querySelector('.name')?.value.trim() || '',
        is_active: tr.querySelector('.active')?.checked ? 1 : 0,
      }));

      if (items.some((x) => !x.contractor_name)) {
        Swal.fire({ icon: 'warning', title: '有「承攬商名稱」未填' });
        return;
      }

      Swal.fire({
        title: '儲存中…',
        allowOutsideClick: false,
        showConfirmButton: false,
        didOpen: () => Swal.showLoading(),
      });
      try {
        const res = await fetch(`${API}?action=contractors_save`, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ items }),
        });
        const data = await res.json();
        if (!data?.success) throw new Error(data?.message || '儲存失敗');

        await loadContractorSelect(); // 更新下拉
        // 成功就關閉 Modal
        const m = bootstrap.Modal.getOrCreateInstance(modalEl);
        m.hide();

        Swal.hideLoading();
        Swal.update({
          icon: 'success',
          title: '已儲存',
          showConfirmButton: false,
          timer: 1200,
          allowOutsideClick: true,
        });
      } catch (err) {
        Swal.hideLoading();
        Swal.update({
          icon: 'error',
          title: '儲存失敗',
          text: err?.message || '',
          showConfirmButton: true,
        });
      }
    }

    // 綁定
    document.getElementById('addContractorBtn')?.addEventListener('click', addContractor);
    document.getElementById('saveContractorsBtn')?.addEventListener('click', saveContractors);
    btn?.addEventListener('click', openContractorEditor);

    // 初始：建好下拉
    loadContractorSelect();
  })();

  /* === 新增：編輯模式（切換「編輯／完成編輯」、每列刪除按鈕）=== */

  // 既有節點（沿用你現有的 table/tbody/btnSave 參照）
  const btnEdit = document.getElementById('btnToggleEdit');
  let editMode = false;

  // 幫「表頭」動態加/移除「操作」欄
  function ensureHeaderActionCol() {
    const thead = table.tHead;
    if (!thead || !thead.rows.length) return;
    const headRow = thead.rows[0];

    const has = !!table.querySelector('th.actions');
    if (editMode && !has) {
      const th = document.createElement('th');
      th.className = 'text-center actions';
      th.textContent = '操作';
      headRow.appendChild(th);
    } else if (!editMode && has) {
      const th = table.querySelector('th.actions');
      th?.remove();
    }
  }

  // 生成每列 HTML（覆寫你的 rowTpl，保留原 3 欄，編輯模式多一個刪除鈕欄位）
  const _origRowTpl = rowTpl; // 若前面定義過 rowTpl，先引用
  rowTpl = function (item, idx) {
    const base = `
    <tr draggable="true" data-mat="${(item.material_number ?? '').toString().replace(/["'&<>]/g, (s) => ({ '"': '&#39;', '"': '&quot;', '&': '&amp;', '<': '&lt;', '>': '&gt;' })[s])}">
      <td class="text-center seq">${idx + 1}</td>
      <td class="text-center">${item.material_number ?? ''}</td>
      <td>${item.name_specification ?? ''}</td>
      ${editMode
        ? `<td class="text-center">
          <button type="button" class="btn btn-sm btn-outline-danger btn-del" data-mat="${item.material_number ?? ''}">
            刪除
          </button>
        </td>`
        : ``
      }
    </tr>
  `;
    return base;
  };

  // 覆寫 render：依 editMode 調整 colspan，並在渲染後掛上刪除事件
  const _origRender = render;
  render = function (list) {
    ensureHeaderActionCol();

    if (!list || list.length === 0) {
      const colspan = 3 + (editMode ? 1 : 0);
      tbody.innerHTML = `<tr><td colspan="${colspan}" class="text-center text-muted">無資料</td></tr>`;
      return;
    }

    tbody.innerHTML = list.map(rowTpl).join('');

    // 原本就有的拖曳綁定
    bindDrag();

    // 編輯模式才綁定刪除鈕
    if (editMode) bindRowActions();
  };

  // 綁定每列刪除事件
  function bindRowActions() {
    tbody.querySelectorAll('.btn-del').forEach((btn) => {
      btn.addEventListener('click', onDeleteClick);
    });
  }

  // 刪除處理：呼叫後端刪除此材料在排序清單中的項目
  async function onDeleteClick(e) {
    const mat = e.currentTarget.getAttribute('data-mat');
    if (!mat) return;

    const ok = await Swal.fire({
      icon: 'warning',
      title: `刪除此列？`,
      text: `材料編號：${mat}`,
      showCancelButton: true,
      confirmButtonText: '刪除',
      cancelButtonText: '取消',
      confirmButtonColor: '#d33',
    }).then((r) => r.isConfirmed);

    if (!ok) return;

    // 開啟 loading
    Swal.fire({
      title: '刪除中…',
      allowOutsideClick: false,
      showConfirmButton: false,
      didOpen: () => Swal.showLoading(),
    });

    try {
      // 後端建議新增一個 action：delete_sort_item
      // 輸入：JSON { material_number: '...' }
      const res = await fetch(`${API}?action=delete_sort_item`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ material_number: mat }),
      });
      const data = await res.json();
      if (!data?.success) throw new Error(data?.message || '刪除失敗');

      // 前端移除當前集合中的該項，重新渲染
      allItems = allItems.filter((x) => String(x.material_number) !== String(mat));
      viewItems = viewItems.filter((x) => String(x.material_number) !== String(mat));
      render(viewItems);
      renumber();

      Swal.hideLoading();
      Swal.update({
        icon: 'success',
        title: '已刪除',
        showConfirmButton: true,
        confirmButtonText: '關閉',
        showCloseButton: true,
        allowOutsideClick: true,
      });
    } catch (err) {
      Swal.hideLoading();
      Swal.update({
        icon: 'error',
        title: '刪除失敗',
        text: err?.message || '',
        showConfirmButton: true,
      });
    }
  }

  // 切換編輯模式（按鈕文字：編輯 ↔ 完成編輯）
  function toggleEditMode() {
    editMode = !editMode;
    if (btnEdit) btnEdit.textContent = editMode ? '完成編輯' : '編輯';
    render(viewItems); // 重新渲染以顯示/隱藏刪除鈕＆表頭欄
  }

  // 綁定切換按鈕
  if (btnEdit) btnEdit.addEventListener('click', toggleEditMode);

  // 讓 fetchAll() 在載入空畫面時也有正確 colspan
  const _origFetchAll = fetchAll;
  fetchAll = async function () {
    const colspan = 3 + (editMode ? 1 : 0);
    tbody.innerHTML = `<tr><td colspan="${colspan}" class="text-center text-muted">載入中…</td></tr>`;
    await _origFetchAll();
  };

  // ★ 監聽上傳完成事件：刷新材料排序清單（並保留搜尋框的關鍵字）
  document.addEventListener('mat:uploaded', async () => {
    await fetchAll();
    if (inputQ && inputQ.value.trim()) {
      // 重新套用目前的搜尋字串，讓畫面保持一致
      applyFilter(inputQ.value);
    }
  });

  // 初次載入
  fetchAll();
})();
