// Public/assets/js/m_data_statistics.js
// 去除 <tfoot> 依賴；按鈕啟用/停用不再受合計列影響；其他邏輯不變

(function () {
  const BASE = (window.PUBLIC_BASE || '').replace(/\/+$/, '');
  const API = BASE + '/modules/mat/m_data_statistics_backend.php';

  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => Array.from(el.querySelectorAll(s));

  // 概覽
  const grid = $('#stats_overview_grid');
  const hint = $('#stats_overview_hint');
  const range = $('#stats_overview_range');

  // 結果
  const card = $('#stats_result_card');
  const title = $('#stats_result_title');
  const sub = $('#stats_result_sub');
  const btnPdf = $('#btnPrintPdf');

  const tbody = $('#stats_detail_table tbody');

  let current = { code: '', name: '', date: '' };

  // 轉整數字串；0 -> 空字串
  const toIntOrBlank = (n) => {
    const v = Number(n);
    if (!isFinite(v)) return '';
    const i = Math.round(v);
    return i === 0 ? '' : String(i);
  };

  const escapeHtml = (s) =>
    String(s ?? '').replace(
      /[&<>"']/g,
      (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[m],
    );

  async function post(body) {
    const r = await fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: new URLSearchParams(body),
    });
    return await r.json();
  }

  /* 概覽 */
  async function loadOverview() {
    hint.textContent = '載入中…';
    grid.innerHTML = '';
    const data = await post({ action: 'stats_overview_last_month' });
    if (!data?.success) {
      hint.textContent = data?.message || '載入失敗';
      return;
    }
    range.textContent = `${data.start_date} ～ ${data.end_date}`;
    const days = (data.days || []).sort((a, b) => b.date.localeCompare(a.date));
    if (!days.length) {
      hint.textContent = '近一個月沒有任何提/退料紀錄';
      return;
    }
    hint.textContent = '點選承攬商以查看該日統計';

    const frag = document.createDocumentFragment();
    days.forEach((d) => {
      const col = document.createElement('div');
      col.className = 'col';
      const tile = document.createElement('div');
      tile.className = 'border rounded p-2 h-100';
      tile.innerHTML = `
        <div class="d-flex align-items-center justify-content-between mb-2">
          <strong>${d.date}</strong>
          <span class="badge text-bg-secondary">${d.total_contractors}</span>
        </div>
        <div class="d-flex flex-wrap gap-1"></div>
      `;
      const wrap = tile.querySelector('div.d-flex.flex-wrap');
      (d.contractors || []).forEach((c) => {
        const chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'btn btn-sm btn-outline-primary chip';
        chip.dataset.code = c.contractor_code || '';
        chip.dataset.name = c.contractor_name || c.contractor_code || '';
        chip.dataset.date = d.date;
        chip.textContent = c.contractor_code || '';
        chip.title = c.contractor_name
          ? `${c.contractor_code} - ${c.contractor_name}`
          : c.contractor_code || '';
        wrap.appendChild(chip);
      });
      col.appendChild(tile);
      frag.appendChild(col);
    });
    grid.appendChild(frag);
  }

  /* 單日承攬商統計 */
  async function loadContractorDayStats(code, date) {
    $$('.chip', grid).forEach((el) => el.classList.remove('active'));
    grid
      .querySelector(`.chip[data-code="${CSS.escape(code)}"][data-date="${CSS.escape(date)}"]`)
      ?.classList.add('active');

    tbody.innerHTML = `<tr><td colspan="13" class="text-center text-muted">載入中…</td></tr>`;
    card.style.display = '';
    // 先鎖按鈕，避免在資料載入中被點擊
    if (btnPdf) btnPdf.disabled = true;

    const data = await post({
      action: 'contractor_day_stats',
      contractor_code: code,
      withdraw_date: date,
    });
    if (!data?.success) {
      tbody.innerHTML = `<tr><td colspan="13" class="text-center text-danger">${escapeHtml(data?.message || '載入失敗')}</td></tr>`;
      title.textContent = '—';
      sub.textContent = '—';
      return;
    }

    current = {
      code: data.contractor_code || code,
      name: data.contractor_name || code,
      date: data.withdraw_date || date,
    };
    title.textContent = `${current.code} - ${current.name}`;
    sub.textContent = current.date;

    const rows = data.rows || [];
    if (!rows.length) {
      tbody.innerHTML = `<tr><td colspan="13" class="text-center text-muted">無資料</td></tr>`;
    } else {
      const frag = document.createDocumentFragment();
      rows.forEach((r, i) => {
        const tr = document.createElement('tr');

        const leadNew = toIntOrBlank(r.lead_new);
        const leadOld = toIntOrBlank(r.lead_old);
        const retNew = toIntOrBlank(r.return_new);
        const retOld = toIntOrBlank(r.return_old);
        const sumNewV = Math.round(Number(r.total_new || 0));
        const sumOldV = Math.round(Number(r.total_old || 0));

        tr.innerHTML = `
          <td class="text-center">${i + 1}</td>
          <td>${escapeHtml(r.material_number || '')}</td>
          <td>${escapeHtml(r.material_name || '')}</td>

          <td class="text-center">${leadNew}</td>
          <td class="text-center">${decorateBreakdown(r.lead_new_breakdown)}</td>

          <td class="text-center">${leadOld}</td>
          <td class="text-center">${decorateBreakdown(r.lead_old_breakdown)}</td>

          <td class="text-center">${retNew}</td>
          <td class="text-center">${decorateBreakdown(r.return_new_breakdown)}</td>

          <td class="text-center">${retOld}</td>
          <td class="text-center">${decorateBreakdown(r.return_old_breakdown)}</td>
        `;

        // 合計(新/舊) 欄（置中 + 依規則上色）
        const td_sum_new = document.createElement('td');
        const td_sum_old = document.createElement('td');
        td_sum_new.className = 'text-center sum-new';
        td_sum_old.className = 'text-center sum-old';
        td_sum_new.textContent = toIntOrBlank(sumNewV);
        td_sum_old.textContent = toIntOrBlank(sumOldV);
        applySumColor(td_sum_new, 'new', sumNewV);
        applySumColor(td_sum_old, 'old', sumOldV);

        tr.appendChild(td_sum_new);
        tr.appendChild(td_sum_old);

        frag.appendChild(tr);
      });
      tbody.innerHTML = '';
      tbody.appendChild(frag);
    }

    // 成功載入後，解鎖列印按鈕（不再依賴 <tfoot>）
    if (btnPdf) {
      btnPdf.disabled = false;
      btnPdf.removeAttribute('disabled');
    }
  }

  // 明細：把每段數字轉整數、移除 0，再以 + 串回；全為 0 則回空字串
  function decorateBreakdown(s) {
    const t = (s || '').trim();
    if (!t) return '';
    const parts = t
      .split('+')
      .map((x) => x.trim())
      .filter(Boolean)
      .map((x) => Math.round(Number(x) || 0))
      .filter((v) => v !== 0);
    if (!parts.length) return '';
    return `<span class="text-mono">${escapeHtml(parts.join('+'))}</span>`;
  }

  // 顏色規則：新(>0藍、<0紅)、舊(>0黑、<0紅)；0 不上色且顯示空白
  function applySumColor(el, type, val) {
    el.classList.remove('text-primary', 'text-danger', 'text-body');
    if (val === 0) return;
    if (type === 'new') {
      if (val > 0) el.classList.add('text-primary');
      else el.classList.add('text-danger');
    } else {
      if (val > 0) el.classList.add('text-body');
      else el.classList.add('text-danger');
    }
  }

  // 事件
  document.addEventListener('click', (e) => {
    const chip = e.target.closest('.chip');
    if (chip && grid.contains(chip)) {
      const code = chip.dataset.code || '';
      const date = chip.dataset.date || '';
      if (code && date) loadContractorDayStats(code, date);
    }
  });

  $('#btnRefresh')?.addEventListener('click', () => {
    current = { code: '', name: '', date: '' };
    card.style.display = 'none';
    loadOverview();
  });

  btnPdf?.addEventListener('click', (e) => {
    e.preventDefault();
    if (!current.code || !current.date) return;
    const url = `${BASE}/modules/mat/m_data_statistics_pdf.php?contractor_code=${encodeURIComponent(current.code)}&withdraw_date=${encodeURIComponent(current.date)}`;
    window.open(url, '_blank');
  });

  // init
  loadOverview();
})();
