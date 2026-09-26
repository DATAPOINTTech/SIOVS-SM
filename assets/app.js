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

/* ── Add-form panel: smooth scroll and highlight when ＋ button clicked ─── */
qsa('a[href^="#add-"], a[href^="#add"]').forEach(btn => {
  btn.addEventListener('click', e => {
    e.preventDefault();
    const target = qs(btn.getAttribute('href'));
    if (!target) return;
    // Remove prior highlights
    qsa('.form-card.form-open').forEach(el => el.classList.remove('form-open'));
    target.classList.add('form-open');
    setTimeout(() => target.scrollIntoView({ behavior: 'smooth', block: 'nearest' }), 50);
    // Focus first input in the form
    setTimeout(() => { const inp = target.querySelector('input:not([type=hidden]),select'); if(inp) inp.focus(); }, 300);
  });
});

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
          <input name="tax[]"  type="number" step="0.01" placeholder="Tax"  style="width:70px">`;
});

// ── GRN items ──────────────────────────────────────────────────────────────
makeAddRowButton('grn-items', () => {
  const opts = poItemOptions();
  return `<input class="item-barcode" name="item_code[]" placeholder="Scan item code" autocomplete="off" style="width:125px">
          <select name="item_id[]" style="min-width:160px"><option value="">— Item —</option>${opts}</select>
          <input name="qty_ordered[]"  type="number" min="0" placeholder="Ordered"  style="width:70px">
          <input name="qty_received[]" type="number" min="0" placeholder="Received" style="width:70px">
          <input name="qty_accepted[]" type="number" min="0" placeholder="Accepted" style="width:70px">
          <input name="qty_rejected[]" type="number" min="0" placeholder="Rejected" style="width:70px">
          <input name="batch[]"        placeholder="Batch" style="width:90px">
          <input name="mfg_date[]"     type="date" title="Mfg date">
          <input name="exp_date[]"     type="date" title="Expiry">
          <input name="rate[]"         type="number" step="0.01" placeholder="Rate" style="width:75px">`;
});

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
  if (lbl) lbl.textContent = '₹ ' + total.toLocaleString('en-IN', { minimumFractionDigits: 2 });
}
const poItems = qs('#po-items');
if (poItems) {
  poItems.addEventListener('input', recalcPOTotal);
  // Inject total label
  const totalDiv = document.createElement('div');
  totalDiv.style.cssText = 'text-align:right;font-size:13px;font-weight:700;color:var(--ink);margin-top:8px;padding:6px 8px;background:#f8fafc;border-radius:6px;border:1px solid var(--line)';
  totalDiv.innerHTML = 'Estimated total: <span id="po-total-label">₹ 0.00</span>';
  poItems.insertAdjacentElement('afterend', totalDiv);
}

/* ── GRN: auto-fill supplier from selected PO ──────────────────────────── */
const grnPoSel = qs('select[name="po_id"]');
const grnSupSel = qs('form [name="supplier_id"]');
if (grnPoSel && grnSupSel) {
  // Store PO→supplier map from option text
  const map = {};
  qsa('option', grnPoSel).forEach(o => {
    const m = o.textContent.match(/—\s*(.+)/);
    if (m && o.value) map[o.value] = m[1].trim();
  });
  grnPoSel.addEventListener('change', () => {
    const sup = map[grnPoSel.value];
    if (sup) {
      qsa('option', grnSupSel).forEach(o => {
        o.selected = o.textContent.trim() === sup;
      });
    }
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
    const expiry = window.SIOVS_EXPIRY?.[e.target.value];
    if (expiry && expiry < new Date().toISOString().slice(0, 10)) {
      alert(`EXPIRED ITEM\n${e.target.selectedOptions[0].textContent.trim()}\nExpiry date: ${expiry}\nThis item cannot be issued.`);
      e.target.value = '';
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
