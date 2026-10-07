/* ═══════════════════════════════════════════════════════════════════════════
   SIOVS Store Management — app.js
   Handles: toast, flash auto-dismiss, table search, dynamic form rows,
            add-form panel toggle, confirm dialogs, PO totals, sidebar state
   ═══════════════════════════════════════════════════════════════════════════ */

'use strict';

const qs  = (sel, ctx = document) => ctx.querySelector(sel);
const qsa = (sel, ctx = document) => [...ctx.querySelectorAll(sel)];

/* ── Toast notification ─────────────────────────────────────────────────── */
const toast = qs('#toast') || (() => {
  const el = document.createElement('div');
  el.id = 'toast';
  el.className = 'toast';
  document.body.appendChild(el);
  return el;
})();

function showToast(message, isError = false) {
  toast.textContent = message;
  toast.className = `toast show${isError ? ' error' : ''}`;
  clearTimeout(toast._timer);
  toast._timer = setTimeout(() => { toast.className = 'toast'; }, 3500);
}

/* ── Auto-dismiss flash messages ────────────────────────────────────────── */
qsa('.flash').forEach(el => {
  el.style.transition = 'opacity .5s';
  setTimeout(() => { el.style.opacity = '0'; }, 4000);
  setTimeout(() => { el.remove(); }, 4600);
});

/* ── Sidebar: mark active link from current URL ─────────────────────────── */
(function markActiveSidebarLink() {
  const url    = new URL(window.location.href);
  const view   = url.searchParams.get('view') || '';
  const isIdx  = url.pathname.endsWith('index.php') || url.pathname.endsWith('/');
  qsa('.sidebar .nav-link').forEach(link => {
    const href    = link.getAttribute('href') || '';
    const linkUrl = new URL(href, window.location.href);
    const lView   = linkUrl.searchParams.get('view') || '';
    const isHome  = linkUrl.pathname.endsWith('index.php') || linkUrl.pathname.endsWith('/');
    const active  = isIdx ? isHome : (view && lView === view);
    link.classList.toggle('active', active);
  });
})();

/* ── Slide-over Modal Drawer System for Form Cards ────────────────────────── */
(function initDrawerSystem() {
  // 1. Ensure shared backdrop exists in DOM
  let backdrop = qs('#drawer-backdrop');
  if (!backdrop) {
    backdrop = document.createElement('div');
    backdrop.id = 'drawer-backdrop';
    backdrop.className = 'drawer-backdrop';
    document.body.appendChild(backdrop);
  }

  const formCards = qsa('.system-grid > .form-card');
  if (!formCards.length) return;

  let activeDrawer = null;

  window.openDrawer = function(targetEl) {
    if (typeof targetEl === 'string') {
      targetEl = qs(targetEl.startsWith('#') ? targetEl : `#${targetEl}`);
    }
    if (!targetEl) return;
    if (activeDrawer && activeDrawer !== targetEl) {
      activeDrawer.classList.remove('drawer-open');
    }
    activeDrawer = targetEl;
    targetEl.classList.add('drawer-open');
    backdrop.classList.add('active');
    document.body.style.overflow = 'hidden';

    setTimeout(() => {
      const firstInput = qs('input:not([type=hidden]):not([disabled]), select:not([disabled]), textarea:not([disabled])', targetEl);
      if (firstInput) firstInput.focus();
    }, 280);
  };

  window.closeDrawer = function() {
    if (activeDrawer) {
      activeDrawer.classList.remove('drawer-open');
      activeDrawer = null;
    }
    backdrop.classList.remove('active');
    document.body.style.overflow = '';
    if (window.location.hash && qs(window.location.hash)) {
      try {
        history.replaceState(null, '', window.location.pathname + window.location.search);
      } catch (err) {}
    }
  };

  // Enhance each form card inside .system-grid
  formCards.forEach(card => {
    if (!qs('.drawer-header', card)) {
      const h2 = qs('h2', card);
      const header = document.createElement('div');
      header.className = 'drawer-header';

      const titleWrap = document.createElement('div');
      titleWrap.className = 'drawer-title-wrap';
      if (h2) {
        titleWrap.appendChild(h2);
      } else {
        const defH2 = document.createElement('h2');
        defH2.textContent = 'Add Entry';
        titleWrap.appendChild(defH2);
      }
      header.appendChild(titleWrap);

      const closeBtn = document.createElement('button');
      closeBtn.type = 'button';
      closeBtn.className = 'drawer-close-btn';
      closeBtn.title = 'Close dialog (Esc)';
      closeBtn.innerHTML = '✕';
      closeBtn.addEventListener('click', window.closeDrawer);
      header.appendChild(closeBtn);

      // Collect all remaining children into drawer-body
      const body = document.createElement('div');
      body.className = 'drawer-body';
      while (card.firstChild) {
        body.appendChild(card.firstChild);
      }

      card.appendChild(header);
      card.appendChild(body);

      // Add a Cancel button next to the primary submit button in the form if present
      const submitBtn = qs('button[type="submit"], input[type="submit"]', body);
      if (submitBtn && submitBtn.parentElement && !qs('.drawer-cancel-btn', submitBtn.parentElement)) {
        const cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'button button-light drawer-cancel-btn';
        cancelBtn.style.cssText = 'padding:6px 14px;font-size:12px;cursor:pointer;margin-left:8px';
        cancelBtn.textContent = 'Cancel';
        cancelBtn.addEventListener('click', window.closeDrawer);
        submitBtn.insertAdjacentElement('afterend', cancelBtn);
      }
    }
  });

  // Backdrop click closes drawer
  backdrop.addEventListener('click', window.closeDrawer);

  // Escape key closes drawer
  window.addEventListener('keydown', e => {
    if (e.key === 'Escape' && activeDrawer) {
      window.closeDrawer();
    }
  });

  // Intercept all links targeting #add-..., #inspect-..., #view-..., or #edit-...
  qsa('a[href^="#add-"], a[href^="#add"], a[href^="#inspect-"], a[href^="#inspect"], a[href^="#view-"], a[href^="#view"], a[href^="#edit-"], a[href^="#edit"]').forEach(link => {
    link.addEventListener('click', e => {
      const hash = link.getAttribute('href');
      const target = qs(hash);
      if (target && target.classList.contains('form-card')) {
        e.preventDefault();
        window.openDrawer(target);
      }
    });
  });

  // Auto-open if URL has hash matching a form-card
  if (window.location.hash) {
    const initialTarget = qs(window.location.hash);
    if (initialTarget && initialTarget.classList.contains('form-card')) {
      setTimeout(() => window.openDrawer(initialTarget), 150);
    }
  }
})();

/* ── Universal table search (instant filter from 1st letter) ─────────────── */
function attachTableSearch(inputSel, tableSel) {
  const inp = qs(inputSel);
  if (!inp) return;
  inp.addEventListener('input', () => {
    const q = inp.value.toLowerCase().trim();
    qsa(`${tableSel} tbody tr`).forEach(row => {
      row.hidden = q !== '' && !row.textContent.toLowerCase().includes(q);
    });
    const vis = qsa(`${tableSel} tbody tr:not([hidden])`).length;
    let noRow = qs(`${tableSel} .no-results`);
    if (!vis && q) {
      if (!noRow) {
        noRow = document.createElement('tr');
        noRow.className = 'no-results';
        noRow.innerHTML = `<td colspan="20" style="text-align:center;padding:1.5rem;color:#9eb0ce">No results matching "<strong>${q}</strong>"</td>`;
        qs(`${tableSel} tbody`).appendChild(noRow);
      }
    } else if (noRow) {
      noRow.remove();
    }
  });
}
attachTableSearch('#item-search',  '#item-table');
attachTableSearch('#table-search', '#module-table');

// Attach instant search to all table panels on the page (fires on 1st character)
qsa('.table-wrap table').forEach(tbl => {
  if (tbl.id === 'item-table' || tbl.id === 'module-table') return;
  const rows = qsa('tbody tr', tbl);
  if (rows.length < 2) return;
  const wrap = tbl.closest('.panel');
  if (!wrap || qs('.auto-search', wrap)) return;
  const inp = document.createElement('input');
  inp.type = 'search';
  inp.placeholder = 'Type to search records…';
  inp.className = 'auto-search';
  inp.style.cssText = 'width:220px;border:1px solid #dfe6f0;border-radius:7px;padding:6px 12px;font-size:11px;margin-left:auto;display:block;margin-bottom:8px';
  const heading = qs('.panel-heading', wrap);
  if (heading) heading.appendChild(inp);
  inp.addEventListener('input', () => {
    const q = inp.value.toLowerCase().trim();
    rows.forEach(r => { r.hidden = q !== '' && !r.textContent.toLowerCase().includes(q); });
  });
});

/* ── Dynamic line-item rows (PO / GRN / Requisition / Consumption) ──────── */
function makeAddRowButton(containerId, templateFn, label = '＋ Add row') {
  const container = qs(`#${containerId}`);
  if (!container) return;
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'button button-light';
  btn.style.cssText = 'margin-top:6px;font-size:11px;padding:6px 14px';
  btn.textContent = label;
  container.insertAdjacentElement('afterend', btn);
  btn.addEventListener('click', () => {
    const row = document.createElement('div');
    row.className = 'line-item-row';
    row.innerHTML = templateFn();
    container.appendChild(row);
    // Add remove button
    const rm = document.createElement('button');
    rm.type = 'button';
    rm.title = 'Remove row';
    rm.textContent = '×';
    rm.style.cssText = 'flex:none;width:24px;height:24px;border:none;background:#ffeaed;color:#b43a4d;border-radius:50%;cursor:pointer;font-size:14px;line-height:1';
    rm.addEventListener('click', () => row.remove());
    row.appendChild(rm);
  });
}

// Instant barcode / item code resolver (triggers on 1st letter or character match)
function resolveScannedItem(input) {
  const row = input.closest('.line-item-row');
  const select = row && qs('select[name="item_id[]"]', row);
  if (!select) return;
  const code = input.value.trim().toLowerCase();
  if (!code) return;
  const option = [...select.options].find(opt => {
    const optCode = (opt.dataset.code || '').toLowerCase();
    const optName = (opt.dataset.name || opt.textContent || '').toLowerCase();
    return optCode === code || optCode.startsWith(code) || optName.includes(code);
  });
  if (!option || !option.value) {
    select.value = '';
    return;
  }
  input.setCustomValidity('');
  select.value = option.value;
  const unit = qs('[name="unit[]"]', row);
  if (unit && !unit.value) unit.value = option.dataset.unit || '';
}

document.addEventListener('input', event => {
  if (event.target.matches('.item-barcode')) resolveScannedItem(event.target);
});
document.addEventListener('keydown', event => {
  if (event.key === 'Enter' && event.target.matches('.item-barcode')) {
    event.preventDefault();
    resolveScannedItem(event.target);
  }
});

// ── PO line items ──────────────────────────────────────────────────────────
makeAddRowButton('po-items', () => {
  const opts = poItemOptions();
  return `<input class="item-barcode" name="item_code[]" placeholder="Scan item code" autocomplete="off" style="width:125px">
          <select name="item_id[]"><option value="">— Select item —</option>${opts}</select>
          <input name="qty[]"  type="number" min="1" placeholder="Qty"  style="width:70px">
          <input name="unit[]" placeholder="Unit" style="width:60px">
          <input name="rate[]" type="number" step="0.01" placeholder="Rate" style="width:90px">
          <input name="tax[]"  type="number" step="0.01" placeholder="Tax"  style="width:70px">
          <label style="display:inline-flex;align-items:center;gap:3px;font-size:11px;margin:0"><input type="checkbox" name="warranty[]" value="1"> Warranty</label>`;
});

// ── GRN items ──────────────────────────────────────────────────────────────
makeAddRowButton('grn-items', () => {
  const opts = poItemOptions();
  const currentYear = new Date().getFullYear();
  const dMfg = new Date(); dMfg.setMonth(dMfg.getMonth() - 1);
  const pad = n => String(n).padStart(2, '0');
  const fmtDate = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  const defaultMfg = fmtDate(dMfg);
  const dExp = new Date(); dExp.setFullYear(dExp.getFullYear() + 1);
  const defaultExp = fmtDate(dExp);
  const batchNum = `B-${currentYear}-${Math.floor(100 + Math.random() * 900)}`;

  return `
    <div class="grn-item-card" style="background:#ffffff;border:1px solid #cbd5e1;border-radius:8px;padding:12px 14px;margin-bottom:12px;box-shadow:0 1px 3px rgba(0,0,0,0.04)">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;padding-bottom:8px;border-bottom:1px solid #f1f5f9;gap:8px">
        <div style="display:flex;align-items:center;gap:8px;flex:1">
          <span style="background:#e0f2fe;color:#0369a1;font-weight:700;font-size:11px;padding:3px 8px;border-radius:4px">ITEM</span>
          <input class="item-barcode" name="item_code[]" placeholder="Scan / Code" autocomplete="off" style="width:110px;font-weight:600">
          <select name="item_id[]" style="flex:1"><option value="">— Select item —</option>${opts}</select>
        </div>
        <button type="button" class="btn-remove-row" style="background:#fee2e2;color:#b91c1c;border:none;border-radius:4px;padding:2px 7px;font-size:12px;cursor:pointer;font-weight:700" title="Remove item">✕</button>
      </div>
      <div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:8px;margin-bottom:10px">
        <div>
          <label style="display:block;font-size:11px;font-weight:600;color:#64748b;margin-bottom:3px">📋 Ordered Qty</label>
          <input name="qty_ordered[]" type="number" min="0" placeholder="0" style="width:100%;background:#f1f5f9;font-weight:600;text-align:center">
        </div>
        <div>
          <label style="display:block;font-size:11px;font-weight:700;color:#0284c7;margin-bottom:3px">📦 Received Qty</label>
          <input name="qty_received[]" type="number" min="0" placeholder="0" required style="width:100%;font-weight:700;text-align:center;border-color:#38bdf8">
        </div>
        <div>
          <label style="display:block;font-size:11px;font-weight:700;color:#16a34a;margin-bottom:3px">✅ Accepted (Stock)</label>
          <input name="qty_accepted[]" type="number" min="0" placeholder="0" required style="width:100%;font-weight:700;text-align:center;border-color:#4ade80;background:#f0fdf4">
        </div>
        <div>
          <label style="display:block;font-size:11px;font-weight:700;color:#dc2626;margin-bottom:3px">❌ Rejected / Damage</label>
          <input name="qty_rejected[]" type="number" min="0" value="0" style="width:100%;font-weight:600;text-align:center">
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1.3fr 1fr 1fr 1fr;gap:8px">
        <div>
          <label style="display:block;font-size:11px;font-weight:600;color:#334155;margin-bottom:3px">🏷️ Batch Number</label>
          <input name="batch[]" value="${batchNum}" placeholder="Batch No." required style="width:100%;font-weight:600">
        </div>
        <div>
          <label style="display:block;font-size:11px;font-weight:600;color:#64748b;margin-bottom:3px">📅 Mfg Date</label>
          <input name="mfg_date[]" type="date" value="${defaultMfg}" style="width:100%">
        </div>
        <div>
          <label style="display:block;font-size:11px;font-weight:700;color:#b45309;margin-bottom:3px">⏳ Expiry Date (FEFO)</label>
          <input name="exp_date[]" type="date" value="${defaultExp}" required style="width:100%;font-weight:600;border-color:#fbbf24;background:#fffbeb">
        </div>
        <div>
          <label style="display:block;font-size:11px;font-weight:600;color:#334155;margin-bottom:3px">💰 Rate (PKR)</label>
          <input name="rate[]" type="number" step="0.01" placeholder="0.00" style="width:100%">
        </div>
      </div>
    </div>`;
}, '＋ Add another item to GRN');

// ── Requisition items ──────────────────────────────────────────────────────
makeAddRowButton('req-items', () => {
  const opts = poItemOptions();
  return `<select name="item_id[]"><option value="">— Select item —</option>${opts}</select>
          <input name="required_qty[]" type="number" min="1" placeholder="Qty required" style="width:120px">`;
});

// ── Consumption items (generic for all consumption forms) ──────────────────
['opd-items','ipd-items','ot-items','emg-items'].forEach(id => {
  makeAddRowButton(id, () => {
    const opts = poItemOptions();
    return `<select name="item_id[]"><option value="">— Select item —</option>${opts}</select>
            <input name="batch[]"    placeholder="Batch" style="width:100px">
            <input name="quantity[]" type="number" min="1" placeholder="Qty" style="width:70px">`;
  });
});

// Helper: build <option> list from items already in the DOM (first select found)
function poItemOptions() {
  const ref = qs('.line-item-row select[name="item_id[]"]');
  if (ref) return ref.innerHTML;
  // fallback: build from window.SIOVS_ITEMS if injected
  if (window.SIOVS_ITEMS) {
    return window.SIOVS_ITEMS.map(i =>
      `<option value="${i.id}">${escHtml(i.code)} — ${escHtml(i.name)} (${i.quantity})</option>`
    ).join('');
  }
  return '';
}
function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ── PO: live total calculation ─────────────────────────────────────────── */
function recalcPOTotal() {
  let total = 0;
  qsa('#po-items .line-item-row').forEach(row => {
    const qty  = parseFloat(qs('[name="qty[]"]',  row)?.value  || 0);
    const rate = parseFloat(qs('[name="rate[]"]', row)?.value || 0);
    const tax  = parseFloat(qs('[name="tax[]"]',  row)?.value  || 0);
    total += qty * rate + tax;
  });
  const lbl = qs('#po-total-label');
  if (lbl) lbl.textContent = 'Rs. ' + total.toLocaleString('en-PK', { minimumFractionDigits: 2 });
}
const poItems = qs('#po-items');
if (poItems) {
  poItems.addEventListener('input', recalcPOTotal);
  // Inject total label
  const totalDiv = document.createElement('div');
  totalDiv.style.cssText = 'text-align:right;font-size:13px;font-weight:700;color:var(--ink);margin-top:8px;padding:6px 8px;background:#f8fafc;border-radius:6px;border:1px solid var(--line)';
  totalDiv.innerHTML = 'Estimated total: <span id="po-total-label">Rs. 0.00</span>';
  poItems.insertAdjacentElement('afterend', totalDiv);
  recalcPOTotal();
}

/* ── GRN: PO Cards, Supplier Auto-Fill & Items Auto-Load ──────────── */
const grnPoSel = qs('select[name="po_id"]');
const grnSupSel = qs('form [name="supplier_id"]');
const grnItemsWrap = qs('#grn-items');
const grnBanner = qs('#grn-po-banner');
const grnBannerText = qs('#grn-po-banner-text');
const btnDirectReceipt = qs('#btn-direct-receipt');
const poCards = qsa('.po-inward-card');

if (grnPoSel && grnItemsWrap) {
  // Store PO→supplier map from option text
  const poSupplierMap = {};
  qsa('option', grnPoSel).forEach(o => {
    const m = o.textContent.match(/—\s*(.+)/);
    if (m && o.value) poSupplierMap[o.value] = m[1].trim();
  });

  const pad = n => String(n).padStart(2, '0');
  const fmtDate = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

  function defaultMfgDate() {
    const d = new Date(); d.setMonth(d.getMonth() - 1);
    return fmtDate(d);
  }
  function defaultExpDate() {
    const d = new Date(); d.setFullYear(d.getFullYear() + 1);
    return fmtDate(d);
  }

  function getBlankGrnRowsHtml() {
    const opts = poItemOptions();
    const currentYear = new Date().getFullYear();
    const defaultMfg = defaultMfgDate();
    const defaultExp = defaultExpDate();
    return Array.from({length: 2}, () => `
      <div class="grn-item-card" style="background:#ffffff;border:1px solid #cbd5e1;border-radius:8px;padding:12px 14px;margin-bottom:12px;box-shadow:0 1px 3px rgba(0,0,0,0.04)">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;padding-bottom:8px;border-bottom:1px solid #f1f5f9;gap:8px">
          <div style="display:flex;align-items:center;gap:8px;flex:1">
            <span style="background:#e0f2fe;color:#0369a1;font-weight:700;font-size:11px;padding:3px 8px;border-radius:4px">ITEM</span>
            <input class="item-barcode" name="item_code[]" placeholder="Scan / Code" autocomplete="off" style="width:110px;font-weight:600">
            <select name="item_id[]" style="flex:1"><option value="">— Select item —</option>${opts}</select>
          </div>
          <button type="button" class="btn-remove-row" style="background:#fee2e2;color:#b91c1c;border:none;border-radius:4px;padding:2px 7px;font-size:12px;cursor:pointer;font-weight:700" title="Remove item">✕</button>
        </div>
        <div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:8px;margin-bottom:10px">
          <div>
            <label style="display:block;font-size:11px;font-weight:600;color:#64748b;margin-bottom:3px">📋 Ordered Qty</label>
            <input name="qty_ordered[]" type="number" min="0" placeholder="0" style="width:100%;background:#f1f5f9;font-weight:600;text-align:center">
          </div>
          <div>
            <label style="display:block;font-size:11px;font-weight:700;color:#0284c7;margin-bottom:3px">📦 Received Qty</label>
            <input name="qty_received[]" type="number" min="0" placeholder="0" required style="width:100%;font-weight:700;text-align:center;border-color:#38bdf8">
          </div>
          <div>
            <label style="display:block;font-size:11px;font-weight:700;color:#16a34a;margin-bottom:3px">✅ Accepted (Stock)</label>
            <input name="qty_accepted[]" type="number" min="0" placeholder="0" required style="width:100%;font-weight:700;text-align:center;border-color:#4ade80;background:#f0fdf4">
          </div>
          <div>
            <label style="display:block;font-size:11px;font-weight:700;color:#dc2626;margin-bottom:3px">❌ Rejected / Damage</label>
            <input name="qty_rejected[]" type="number" min="0" value="0" style="width:100%;font-weight:600;text-align:center">
          </div>
        </div>
        <div style="display:grid;grid-template-columns:1.3fr 1fr 1fr 1fr;gap:8px">
          <div>
            <label style="display:block;font-size:11px;font-weight:600;color:#334155;margin-bottom:3px">🏷️ Batch Number</label>
            <input name="batch[]" value="B-${currentYear}-${Math.floor(100 + Math.random() * 900)}" placeholder="Batch No." required style="width:100%;font-weight:600">
          </div>
          <div>
            <label style="display:block;font-size:11px;font-weight:600;color:#64748b;margin-bottom:3px">📅 Mfg Date</label>
            <input name="mfg_date[]" type="date" value="${defaultMfg}" style="width:100%">
          </div>
          <div>
            <label style="display:block;font-size:11px;font-weight:700;color:#b45309;margin-bottom:3px">⏳ Expiry Date (FEFO)</label>
            <input name="exp_date[]" type="date" value="${defaultExp}" required style="width:100%;font-weight:600;border-color:#fbbf24;background:#fffbeb">
          </div>
          <div>
            <label style="display:block;font-size:11px;font-weight:600;color:#334155;margin-bottom:3px">💰 Rate (PKR)</label>
            <input name="rate[]" type="number" step="0.01" placeholder="0.00" style="width:100%">
          </div>
        </div>
      </div>
    `).join('');
  }

  function updateGrnRowAccepted(card) {
    const recvInp = qs('input[name="qty_received[]"]', card);
    const rejInp = qs('input[name="qty_rejected[]"]', card);
    const accInp = qs('input[name="qty_accepted[]"]', card);
    if (!recvInp || !accInp) return;
    const recv = parseInt(recvInp.value, 10) || 0;
    const rej = parseInt(rejInp ? rejInp.value : 0, 10) || 0;
    const acc = Math.max(0, recv - rej);
    accInp.value = acc;
    if (rejInp) {
      rejInp.style.borderColor = rej > 0 ? '#ef4444' : '#dfe6f0';
      rejInp.style.backgroundColor = rej > 0 ? '#fef2f2' : '#fff';
    }
  }

  grnItemsWrap.addEventListener('input', e => {
    if (e.target.matches('input[name="qty_received[]"]') || e.target.matches('input[name="qty_rejected[]"]')) {
      const card = e.target.closest('.grn-item-card') || e.target.closest('.line-item-row');
      if (card) updateGrnRowAccepted(card);
    }
  });

  grnItemsWrap.addEventListener('click', e => {
    if (e.target.matches('.btn-remove-row') || e.target.closest('.btn-remove-row')) {
      const card = e.target.closest('.grn-item-card') || e.target.closest('.line-item-row');
      if (card) {
        if (qsa('.grn-item-card, .line-item-row', grnItemsWrap).length > 1) {
          card.remove();
        } else {
          alert('At least one item is required in the GRN.');
        }
      }
    }
  });

  function selectPOForGRN(poId, scrollToForm = false) {
    grnPoSel.value = poId || '';

    // 1. Sync cards visually
    poCards.forEach(card => {
      const isCardSelected = String(card.dataset.poId) === String(poId);
      card.classList.toggle('is-selected', isCardSelected);
      card.style.background = isCardSelected ? '#eff6ff' : '#fff';
      card.style.borderColor = isCardSelected ? '#2563eb' : '#e2e8f0';
      card.style.boxShadow = isCardSelected ? '0 4px 12px rgba(37,99,235,0.18)' : 'none';
      const btn = qs('.btn-select-po', card);
      if (btn) {
        btn.textContent = isCardSelected ? '✓ Selected for GRN' : '📦 Receive This PO';
        btn.className = `button ${isCardSelected ? 'button-primary' : 'button-light'} btn-select-po`;
      }
    });

    // 2. Auto-select supplier
    if (grnSupSel && poId && poSupplierMap[poId]) {
      const sup = poSupplierMap[poId];
      qsa('option', grnSupSel).forEach(o => {
        o.selected = o.textContent.trim() === sup;
      });
    }

    // 3. Populate items from window.SIOVS_PO_ITEMS
    const poItems = window.SIOVS_PO_ITEMS && window.SIOVS_PO_ITEMS[poId];
    if (poId && poItems && poItems.length > 0) {
      grnItemsWrap.innerHTML = '';
      if (grnBanner && grnBannerText) {
        const poText = grnPoSel.options[grnPoSel.selectedIndex]?.textContent || `PO #${poId}`;
        grnBannerText.innerHTML = `<strong>Receiving delivery for ${escHtml(poText.split('—')[0].trim())}</strong>. ${poItems.length} item(s) auto-loaded. Please inspect physical boxes and verify batch numbers &amp; expiry dates.`;
        grnBanner.style.display = 'flex';
      }

      const defaultMfg = defaultMfgDate();
      const defaultExp = defaultExpDate();
      const currentYear = new Date().getFullYear();

      poItems.forEach(item => {
        const card = document.createElement('div');
        card.className = 'grn-item-card';
        card.style.cssText = 'background:#ffffff;border:1px solid #cbd5e1;border-radius:8px;padding:12px 14px;margin-bottom:12px;box-shadow:0 1px 3px rgba(0,0,0,0.04)';
        const batchNum = `B-${currentYear}-${Math.floor(100 + Math.random() * 900)}`;
        card.innerHTML = `
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;padding-bottom:8px;border-bottom:1px solid #f1f5f9;gap:8px">
            <div style="display:flex;align-items:center;gap:8px;flex:1">
              <span style="background:#e0f2fe;color:#0369a1;font-weight:700;font-size:11px;padding:3px 8px;border-radius:4px">ITEM</span>
              <input class="item-barcode" name="item_code[]" value="${escHtml(item.code)}" readonly style="width:110px;background:#f8fafc;font-weight:700" placeholder="Code">
              <select name="item_id[]" style="flex:1;background:#f8fafc;pointer-events:none">
                <option value="${item.item_id}" selected>${escHtml(item.code)} — ${escHtml(item.name)} (${escHtml(item.unit || item.item_unit || 'Units')})</option>
              </select>
            </div>
            <button type="button" class="btn-remove-row" style="background:#fee2e2;color:#b91c1c;border:none;border-radius:4px;padding:2px 7px;font-size:12px;cursor:pointer;font-weight:700" title="Remove item">✕</button>
          </div>
          <div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:8px;margin-bottom:10px">
            <div>
              <label style="display:block;font-size:11px;font-weight:600;color:#64748b;margin-bottom:3px">📋 Ordered Qty</label>
              <input name="qty_ordered[]" type="number" min="0" value="${item.quantity_ordered}" readonly style="width:100%;background:#f1f5f9;font-weight:600;text-align:center">
            </div>
            <div>
              <label style="display:block;font-size:11px;font-weight:700;color:#0284c7;margin-bottom:3px">📦 Received Qty</label>
              <input name="qty_received[]" type="number" min="0" value="${item.quantity_ordered}" required style="width:100%;font-weight:700;text-align:center;border-color:#38bdf8">
            </div>
            <div>
              <label style="display:block;font-size:11px;font-weight:700;color:#16a34a;margin-bottom:3px">✅ Accepted (Stock)</label>
              <input name="qty_accepted[]" type="number" min="0" value="${item.quantity_ordered}" required style="width:100%;font-weight:700;text-align:center;border-color:#4ade80;background:#f0fdf4">
            </div>
            <div>
              <label style="display:block;font-size:11px;font-weight:700;color:#dc2626;margin-bottom:3px">❌ Rejected / Damage</label>
              <input name="qty_rejected[]" type="number" min="0" value="0" style="width:100%;font-weight:600;text-align:center">
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1.3fr 1fr 1fr 1fr;gap:8px">
            <div>
              <label style="display:block;font-size:11px;font-weight:600;color:#334155;margin-bottom:3px">🏷️ Batch Number</label>
              <input name="batch[]" value="${batchNum}" placeholder="Batch No." required style="width:100%;font-weight:600">
            </div>
            <div>
              <label style="display:block;font-size:11px;font-weight:600;color:#64748b;margin-bottom:3px">📅 Mfg Date</label>
              <input name="mfg_date[]" type="date" value="${defaultMfg}" style="width:100%">
            </div>
            <div>
              <label style="display:block;font-size:11px;font-weight:700;color:#b45309;margin-bottom:3px">⏳ Expiry Date (FEFO)</label>
              <input name="exp_date[]" type="date" value="${defaultExp}" required style="width:100%;font-weight:600;border-color:#fbbf24;background:#fffbeb">
            </div>
            <div>
              <label style="display:block;font-size:11px;font-weight:600;color:#334155;margin-bottom:3px">💰 Rate (PKR)</label>
              <input name="rate[]" type="number" step="0.01" value="${item.rate}" placeholder="Rate" style="width:100%">
            </div>
          </div>
        `;
        grnItemsWrap.appendChild(card);
      });
    } else {
      if (grnBanner) {
        if (poId) {
          grnBannerText.innerHTML = `<strong>Notice:</strong> Selected PO has no item lines recorded. Please add items manually below.`;
          grnBanner.style.display = 'flex';
        } else {
          grnBanner.style.display = 'none';
        }
      }
      grnItemsWrap.innerHTML = getBlankGrnRowsHtml();
    }

    if (scrollToForm) {
      const addGrnCard = qs('#add-grn');
      if (addGrnCard) {
        if (window.openDrawer) {
          window.openDrawer(addGrnCard);
        } else {
          addGrnCard.scrollIntoView({ behavior: 'smooth' });
        }
        const challanInput = qs('#grn-challan-number');
        if (challanInput) setTimeout(() => challanInput.focus(), 320);
      }
    }
  }

  // Card click event listeners
  poCards.forEach(card => {
    card.addEventListener('click', () => {
      selectPOForGRN(card.dataset.poId, true);
    });
  });

  // Direct receipt button listener
  if (btnDirectReceipt) {
    btnDirectReceipt.addEventListener('click', () => {
      selectPOForGRN('', true);
    });
  }

  // Dropdown change listener
  grnPoSel.addEventListener('change', () => {
    selectPOForGRN(grnPoSel.value, false);
  });
}


/* ── Confirm dialogs for approve / issue buttons ────────────────────────── */
qsa('form button[type="submit"]').forEach(btn => {
  const text = btn.textContent.trim().toLowerCase();
  const needsConfirm = text.includes('approve') || text.includes('issue stock') || text.includes('post') || text.includes('delete');
  if (!needsConfirm) return;
  btn.addEventListener('click', e => {
    const msg = text.includes('issue') ? 'Issue stock to this department? This will reduce inventory.' :
                text.includes('approve') ? 'Approve this record?' :
                text.includes('post') ? 'Post this consumption? Stock will be deducted immediately.' :
                'Are you sure?';
    if (!confirm(msg)) e.preventDefault();
  });
});

/* ── Loading state on form submit ───────────────────────────────────────── */
qsa('form').forEach(form => {
  form.addEventListener('submit', () => {
    const btn = qs('button[type="submit"]', form);
    if (btn && !btn.dataset.noLoad) {
      btn.disabled = true;
      btn.dataset.orig = btn.textContent;
      btn.innerHTML = '<span class="spinner"></span> Saving…';
    }
  });
});

/* ── Click row to highlight ─────────────────────────────────────────────── */
qsa('.table-wrap tbody tr').forEach(row => {
  row.style.cursor = 'pointer';
  row.addEventListener('click', e => {
    // Don't interfere with button/link clicks inside the row
    if (e.target.closest('button, a, input, select')) return;
    qsa('.table-wrap tbody tr.row-selected').forEach(r => r.classList.remove('row-selected'));
    row.classList.toggle('row-selected');
  });
});

/* ── Expiry date colour hints in GRN form ───────────────────────────────── */
document.addEventListener('change', e => {
  if (e.target.name === 'exp_date[]') {
    const val = e.target.value;
    if (!val) { e.target.style.borderColor = ''; return; }
    const days = Math.ceil((new Date(val) - new Date()) / 86400000);
    e.target.style.borderColor = days <= 0 ? '#c5293e' : days <= 90 ? '#f59e0b' : '#22c55e';
  }
  if (e.target.matches('select[name="item_id[]"]')) {
    const row = e.target.closest('.line-item-row');
    const opt = e.target.selectedOptions[0];

    const expiry = window.SIOVS_EXPIRY?.[e.target.value];
    if (expiry && expiry < new Date().toISOString().slice(0, 10)) {
      alert(`EXPIRED ITEM\n${opt ? opt.textContent.trim() : ''}\nExpiry date: ${expiry}\nThis item cannot be issued.`);
      e.target.value = '';
      if (row) {
        const codeInp = qs('.item-barcode', row);
        if (codeInp) codeInp.value = '';
        const unitInp = qs('[name="unit[]"]', row);
        if (unitInp) unitInp.value = '';
      }
      return;
    }

    // Auto-sync Item Code and Unit into inputs when selecting from dropdown
    if (row) {
      const codeInp = qs('.item-barcode', row);
      if (codeInp) {
        codeInp.value = (opt && opt.value) ? (opt.dataset.code || opt.textContent.split('—')[0].trim() || '') : '';
      }
      const unitInp = qs('[name="unit[]"]', row);
      if (unitInp) {
        unitInp.value = (opt && opt.value) ? (opt.dataset.unit || '') : '';
      }
    }
  }
});

const itemCategory = qs('#item-category');
const itemExpiry = qs('#item-expiry-date');
if (itemCategory && itemExpiry) {
  const updateMedicineExpiry = () => {
    const isMedicine = ['Medicines', 'Ophthalmic Medicines'].includes(itemCategory.value);
    itemExpiry.required = isMedicine;
    itemExpiry.title = isMedicine ? 'Required for medicines' : '';
  };
  itemCategory.addEventListener('change', updateMedicineExpiry);
  updateMedicineExpiry();
}

/* ── Keyboard shortcut: / to focus the nearest search box ──────────────── */
document.addEventListener('keydown', e => {
  if (e.key === '/' && !['INPUT','TEXTAREA','SELECT'].includes(document.activeElement.tagName)) {
    e.preventDefault();
    const inp = qs('#item-search, #table-search, .auto-search');
    if (inp) { inp.focus(); inp.select(); }
  }
});

/* ── Sidebar mobile toggle ──────────────────────────────────────────────── */
(function sidebarToggle() {
  if (!qs('.sidebar')) return;
  const btn = document.createElement('button');
  btn.id = 'sidebar-toggle';
  btn.innerHTML = '☰';
  btn.setAttribute('aria-label', 'Toggle menu');
  document.body.appendChild(btn);
  btn.addEventListener('click', () => qs('.sidebar').classList.toggle('sidebar-open'));
  document.addEventListener('click', e => {
    if (!e.target.closest('.sidebar') && !e.target.closest('#sidebar-toggle')) {
      qs('.sidebar')?.classList.remove('sidebar-open');
    }
  });
})();

/* ── Dashboard: make metric cards clickable ─────────────────────────────── */
qsa('.metric-card').forEach(card => {
  const link = card.dataset.link;
  if (link) {
    card.style.cursor = 'pointer';
    card.addEventListener('click', () => window.location.href = link);
  }
});

/* ── Auto-show add-form if URL hash matches ─────────────────────────────── */
if (window.location.hash) {
  const target = qs(window.location.hash);
  if (target && target.classList.contains('form-card')) {
    target.classList.add('form-open');
    target.style.maxHeight = target.scrollHeight + 200 + 'px';
    setTimeout(() => target.scrollIntoView({ behavior: 'smooth', block: 'start' }), 200);
  }
}

/* ── Print CSV export ───────────────────────────────────────────────────── */
qsa('.export-csv, button').forEach(btn => {
  if (btn.textContent.trim() !== 'Export CSV') return;
  btn.addEventListener('click', () => {
    const tbl = qs('.table-wrap table');
    if (!tbl) return;
    const rows = qsa('tr', tbl);
    const csv = rows.map(r =>
      qsa('th,td', r).map(c => `"${c.textContent.replace(/"/g,'""').trim()}"`).join(',')
    ).join('\n');
    const a = document.createElement('a');
    a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
    a.download = 'siovs-export-' + new Date().toISOString().slice(0,10) + '.csv';
    a.click();
  });
});

/* ── Success: flash the saved row green briefly ─────────────────────────── */
if (qs('.flash.success')) {
  showToast(qs('.flash.success').textContent.trim());
}

/* ── Alerts bell dropdown ────────────────────────────────────────────────── */
(function alertsDropdown() {
  const btn  = qs('#alerts-btn');
  const dd   = qs('#alerts-dropdown');
  const wrap = qs('#alerts-wrap');
  if (!btn || !dd) return;

  // Trigger bell shake if there are alerts
  const badge = qs('.alert-badge');
  if (badge && parseInt(badge.textContent) > 0) {
    btn.classList.add('has-alerts');
  }

  function open() {
    dd.classList.add('open');
    btn.setAttribute('aria-expanded', 'true');
  }
  function close() {
    dd.classList.remove('open');
    btn.setAttribute('aria-expanded', 'false');
  }
  function toggle() { dd.classList.contains('open') ? close() : open(); }

  btn.addEventListener('click', e => { e.stopPropagation(); toggle(); });

  // Close when clicking outside
  document.addEventListener('click', e => {
    if (wrap && !wrap.contains(e.target)) close();
  });

  // Close on Escape
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') close();
  });
})();

/* ═══════════════════════════════════════════════════════════════════════════
   DASHBOARD IMPROVEMENTS
   ═══════════════════════════════════════════════════════════════════════════ */

/* ── 1 · Personalised greeting + live clock ─────────────────────────────── */
(function dashboardGreeting() {
  const greetEl  = qs('#hero-greeting');
  const clockEl  = qs('#live-clock');
  if (!greetEl && !clockEl) return;

  const firstName = greetEl ? greetEl.textContent.replace(/^Good \w+,\s*/, '') : '';

  function update() {
    const now  = new Date();
    const h    = now.getHours();
    const part = h < 12 ? 'morning' : h < 17 ? 'afternoon' : 'evening';
    if (greetEl) greetEl.textContent = `Good ${part}, ${firstName}`;
    if (clockEl) {
      clockEl.textContent = now.toLocaleTimeString('en-PK', { hour: '2-digit', minute: '2-digit', hour12: true });
    }
  }
  update();
  setInterval(update, 30000);
})();

/* ── 2 · Sparkline bars renderer ────────────────────────────────────────── */
(function renderSparklines() {
  const data = window.SIOVS_SPARK;
  if (!data || !data.length) return;
  const max  = Math.max(...data, 1);
  qsa('.metric-card[data-spark]').forEach(card => {
    const spark = card.querySelector('.sparkline');
    if (!spark) return;
    spark.innerHTML = data.map(v => {
      const pct = Math.round((v / max) * 33);
      return `<i style="height:${Math.max(4, pct)}px"></i>`;
    }).join('');
  });
})();

/* ── 3 · Relative timestamps ────────────────────────────────────────────── */
(function relativeTimestamps() {
  function relTime(ts) {
    const diff = Math.floor((Date.now() - new Date(ts).getTime()) / 1000);
    if (diff < 60)   return 'just now';
    if (diff < 3600) return `${Math.floor(diff / 60)} min ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)} hr ago`;
    return `${Math.floor(diff / 86400)}d ago`;
  }
  function updateAll() {
    qsa('.rel-time[data-ts]').forEach(el => { el.textContent = relTime(el.dataset.ts); });
  }
  updateAll();
  setInterval(updateAll, 60000);
})();

/* ── 4 · Ctrl+K Spotlight search ───────────────────────────────────────── */
(function spotlight() {
  const backdrop = qs('#spotlight');
  const input    = qs('#spotlight-input');
  const list     = qs('#spotlight-list');
  if (!backdrop || !input || !list) return;

  const MODULES = [
    { label: 'Dashboard',              href: 'index.php',                         icon: '◈' },
    { label: 'Reports & analytics',    href: 'page.php?view=reports',             icon: '▥' },
    { label: 'Item master / stock',    href: 'page.php?view=inventory',           icon: '◫' },
    { label: 'Batch & expiry',         href: 'page.php?view=batches',             icon: '◷' },
    { label: 'Stock adjustments',      href: 'page.php?view=adjustments',         icon: '⇌' },
    { label: 'Stock verification',     href: 'page.php?view=verification',        icon: '✓' },
    { label: 'Issue requisition',      href: 'page.php?view=requisitions',        icon: '⌁' },
    { label: 'Suppliers',              href: 'page.php?view=suppliers',           icon: '⊞' },
    { label: 'Purchase orders',        href: 'page.php?view=purchase_orders',     icon: '↗' },
    { label: 'Goods received (GRN)',   href: 'page.php?view=grn',                 icon: '▣' },
    { label: 'OPD consumption',        href: 'page.php?view=opd_consumption',     icon: '✚' },
    { label: 'IPD / Ward',             href: 'page.php?view=ipd_consumption',     icon: '⊕' },
    { label: 'OT / Surgery',           href: 'page.php?view=ot_consumption',      icon: '⊗' },
    { label: 'Emergency',              href: 'page.php?view=emergency',           icon: '!' },
    { label: 'Fixed assets',           href: 'page.php?view=assets',             icon: '▤' },
    { label: 'Departments',            href: 'page.php?view=departments',         icon: '⊟' },
    { label: 'Returns',                href: 'page.php?view=returns',             icon: '↻' },
    { label: 'Users & roles',          href: 'page.php?view=users',              icon: '♙' },
    { label: 'Quick entry',            href: 'page.php?view=quick-entry',         icon: '＋' },
  ];

  function open() {
    backdrop.classList.add('open');
    input.value = '';
    renderList('');
    setTimeout(() => input.focus(), 50);
  }
  function close() { backdrop.classList.remove('open'); }

  function renderList(q) {
    q = q.toLowerCase().trim();
    const filtered = q ? MODULES.filter(m => m.label.toLowerCase().includes(q)) : MODULES;
    list.innerHTML = filtered.map((m, i) =>
      `<li role="option" data-href="${m.href}" class="${i === 0 ? 'active' : ''}">
         <span class="sl-icon">${m.icon}</span>${m.label}
       </li>`
    ).join('');
    list.querySelectorAll('li').forEach(li => {
      li.addEventListener('click', () => { window.location.href = li.dataset.href; });
      li.addEventListener('mouseenter', () => {
        list.querySelectorAll('li').forEach(x => x.classList.remove('active'));
        li.classList.add('active');
      });
    });
  }

  input.addEventListener('input', () => renderList(input.value));
  input.addEventListener('keydown', e => {
    const items = [...list.querySelectorAll('li')];
    const activeIdx = items.findIndex(x => x.classList.contains('active'));
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      const next = (activeIdx + 1) % items.length;
      items.forEach(x => x.classList.remove('active'));
      items[next]?.classList.add('active');
      items[next]?.scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      const prev = (activeIdx - 1 + items.length) % items.length;
      items.forEach(x => x.classList.remove('active'));
      items[prev]?.classList.add('active');
      items[prev]?.scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'Enter') {
      const active = list.querySelector('li.active');
      if (active) window.location.href = active.dataset.href;
    } else if (e.key === 'Escape') {
      close();
    }
  });

  backdrop.addEventListener('click', e => { if (e.target === backdrop) close(); });

  document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') { e.preventDefault(); open(); }
    if (e.key === 'Escape') close();
  });

  const spotBtn = qs('#spotlight-btn');
  if (spotBtn) spotBtn.addEventListener('click', open);
})();

/* ── 5 · Refresh KPI button ─────────────────────────────────────────────── */
(function refreshKPI() {
  const btn = qs('#refresh-kpi');
  if (!btn) return;
  btn.addEventListener('click', () => {
    btn.textContent = '↻ Refreshing…';
    btn.disabled = true;
    window.location.reload();
  });
})();

/* ── 6 · Staggered metric card entrance animation ───────────────────────── */
(function staggerCards() {
  qsa('.metric-card').forEach((card, i) => {
    const delay = card.style.getPropertyValue('--delay') || i;
    card.style.animationDelay = `${delay * 60}ms`;
    card.style.animationFillMode = 'both';
  });
})();
