// BuildingSync — main.js

// ── Mobile sidebar ──────────────────────────────────────────────
const sidebar    = document.getElementById('sidebar');
const mobToggle  = document.getElementById('mobToggle');
if (mobToggle) {
  mobToggle.addEventListener('click', () => {
    const open = sidebar?.classList.toggle('open');
    mobToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
}
document.addEventListener('click', e => {
  if (sidebar?.classList.contains('open') &&
      !sidebar.contains(e.target) && e.target !== mobToggle) {
    sidebar.classList.remove('open');
  }
});

// ── Tabs ─────────────────────────────────────────────────────────
function initTabs() {
  document.querySelectorAll('.tabs').forEach(tabsEl => {
    tabsEl.querySelectorAll('.tab[data-tab]').forEach(tab => {
      tab.addEventListener('click', () => {
        tabsEl.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
        tab.classList.add('active');
        document.getElementById(tab.dataset.tab)?.classList.add('active');
      });
    });
  });
}

// ── Modal ────────────────────────────────────────────────────────
// Il body non scorre mentre un modale è aperto: su mobile scorre il modale stesso
function syncBodyLock() {
  document.body.classList.toggle('modal-open', !!document.querySelector('.overlay.open'));
}
function openModal(id) {
  const el = document.getElementById(id);
  if (!el) return;
  // i campi password tornano nascosti a ogni apertura
  el.querySelectorAll('.pw-field input').forEach(i => { i.type = 'password'; });
  el.classList.add('open');
  el.scrollTop = 0;
  el.querySelector('.modal')?.scrollTo?.(0, 0);
  syncBodyLock();
  // su touch non si apre la tastiera automaticamente (coprirebbe metà modale)
  if (!window.matchMedia('(pointer: coarse)').matches) {
    setTimeout(() => el.querySelector('input:not([type=hidden]):not([disabled]),textarea,select')?.focus(), 100);
  }
}
function closeModal(id) {
  document.getElementById(id)?.classList.remove('open');
  syncBodyLock();
}
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.overlay.open').forEach(o => o.classList.remove('open'));
    syncBodyLock();
  }
});
document.querySelectorAll('.overlay').forEach(o => {
  o.addEventListener('click', e => { if (e.target === o) { o.classList.remove('open'); syncBodyLock(); } });
});

// ── Password: genera / mostra / copia ───────────────────────────
// Genera una password casuale di 12 caratteri con maiuscole, minuscole, numeri e simboli
// (esclusi i caratteri ambigui come O/0, l/1/I)
function generatePassword(len) {
  len = len || 12;
  const sets = [
    'ABCDEFGHJKLMNPQRSTUVWXYZ',
    'abcdefghijkmnpqrstuvwxyz',
    '23456789',
    '!@#$%*?-_+=.'
  ];
  const all = sets.join('');
  const rnd = n => { // intero casuale uniforme in [0, n) con crypto
    const max = Math.floor(0x100000000 / n) * n, buf = new Uint32Array(1);
    do { crypto.getRandomValues(buf); } while (buf[0] >= max);
    return buf[0] % n;
  };
  const chars = sets.map(s => s[rnd(s.length)]);           // almeno uno per ogni gruppo
  while (chars.length < len) chars.push(all[rnd(all.length)]);
  for (let i = chars.length - 1; i > 0; i--) { const j = rnd(i + 1); [chars[i], chars[j]] = [chars[j], chars[i]]; }
  return chars.join('');
}

function copyText(text) {
  if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
  return new Promise((resolve, reject) => { // fallback per HTTP / browser datati
    const ta = document.createElement('textarea');
    ta.value = text; ta.setAttribute('readonly', ''); ta.style.cssText = 'position:fixed;top:-1000px;opacity:0';
    document.body.appendChild(ta); ta.select(); ta.setSelectionRange(0, text.length);
    const ok = document.execCommand('copy'); ta.remove();
    ok ? resolve() : reject();
  });
}

function flashBtn(btn, html, cls) {
  const orig = btn.dataset.origHtml || btn.innerHTML;
  btn.dataset.origHtml = orig;
  btn.innerHTML = html; if (cls) btn.classList.add(cls);
  clearTimeout(btn._t);
  btn._t = setTimeout(() => { btn.innerHTML = orig; if (cls) btn.classList.remove(cls); }, 1600);
}

document.addEventListener('click', e => {
  const tgl = e.target.closest('[data-pw-toggle]');
  if (tgl) {
    const inp = document.getElementById(tgl.dataset.pwToggle);
    if (inp) inp.type = inp.type === 'password' ? 'text' : 'password';
    return;
  }
  const cp = e.target.closest('[data-pw-copy]');
  if (cp) {
    const inp = document.getElementById(cp.dataset.pwCopy);
    if (!inp || !inp.value) { flashBtn(cp, '&#x2716;', 'pw-btn-err'); return; }
    copyText(inp.value).then(() => flashBtn(cp, '&#x2714;', 'pw-btn-ok'), () => flashBtn(cp, '&#x2716;', 'pw-btn-err'));
    return;
  }
  const gen = e.target.closest('[data-pw-gen]');
  if (gen) {
    const ids = gen.dataset.pwGen.split(',');
    const pwd = generatePassword(12);
    ids.forEach(id => {
      const inp = document.getElementById(id.trim());
      if (!inp) return;
      inp.value = pwd;
      inp.type = 'text'; // mostrata, così l'admin può leggerla o copiarla
      inp.dispatchEvent(new Event('input', { bubbles: true }));
    });
    flashBtn(gen, '&#x2714; Password generata', null);
  }
});

// ── Confirm delete ───────────────────────────────────────────────
function confirmDelete(msg) {
  return confirm(msg || 'Confermi l\'eliminazione?');
}

// ── AJAX: segna attività completata (pallino) ───────────────────
// Unico gestore (in precedenza ne esisteva un secondo in stabile.php e il click veniva inviato due volte)
document.addEventListener('click', e => {
  const btn = e.target.closest('[data-complete-activity]');
  if (!btn || !btn.dataset.completeActivity || btn.dataset.busy) return;
  const actId = btn.dataset.completeActivity;
  const csrf  = (document.getElementById('csrfToken') || document.querySelector('input[name="_t"]'))?.value || '';
  const url   = new URL(window.location.href);
  url.hash = '';
  url.searchParams.set('ajax', 'complete_activity');

  btn.dataset.busy = '1';
  btn.style.opacity = '.4';
  fetch(url.toString(), {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
      'X-Requested-With': 'XMLHttpRequest'
    },
    body: 'id=' + encodeURIComponent(actId) + '&_t=' + encodeURIComponent(csrf)
  })
  .then(r => r.json())
  .then(data => {
    if (!data.ok) { if (data.error === 'csrf') location.reload(); return; }
    const row  = btn.closest('.act-row');
    const done = !!data.done;
    btn.classList.toggle('done', done);
    btn.innerHTML = done ? '&#x2713;' : '';
    btn.title = done ? 'Segna come da fare' : 'Segna come completata';
    row?.querySelector('.act-title')?.classList.toggle('done', done);
    const meta = row?.querySelector('.act-meta');
    let span = meta?.querySelector('.completed-at');
    if (done) {
      if (!span && meta) { span = document.createElement('span'); span.className = 'completed-at'; meta.appendChild(span); }
      if (span) span.textContent = 'Completata ' + data.completed_at + (data.completed_by ? ' da ' + data.completed_by : '');
    } else {
      span?.remove();
    }
    // Aggiorna anche il pulsante testuale Completa/Riapri della stessa riga
    const formBtn = row?.querySelector('input[value="complete_activity_form"]')?.form?.querySelector('button');
    if (formBtn) {
      formBtn.textContent = done ? 'Riapri' : 'Completa';
      formBtn.classList.toggle('btn-warn', done);
      formBtn.classList.toggle('btn-success', !done);
    }
  })
  .catch(() => {})
  .finally(() => { delete btn.dataset.busy; btn.style.opacity = ''; });
});

// ── Alert auto-dismiss ───────────────────────────────────────────
setTimeout(() => {
  document.querySelectorAll('.alert-dismissible').forEach(a => {
    a.style.transition = 'opacity .4s';
    a.style.opacity    = '0';
    setTimeout(() => a.remove(), 400);
  });
}, 5000);

// ── Inizializzazione ─────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', initTabs);
