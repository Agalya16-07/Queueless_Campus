const API = 'api/index.php?action=';
const $ = id => document.getElementById(id);
let chosen = null, timer = null, startAhead = null, notified = false;

async function api(action, body, qs = '') {
  const r = await fetch(API + action + qs, body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) } : {});
  const d = await r.json();
  if (!r.ok) throw new Error(d.error || 'Something went wrong');
  return d;
}

async function loadServices() {
  const list = await api('services');
  $('services').innerHTML = list.map(s => `
    <button class="svc" data-id="${s.id}" data-name="${s.name}">
      <span class="code">${s.code}</span>
      <span><b>${s.name}</b><small>Now serving ${s.serving ? s.code + String(s.serving).padStart(3, '0') : '—'} · ${s.waiting} waiting</small></span>
    </button>`).join('');
}

$('services').addEventListener('click', e => {
  const b = e.target.closest('.svc'); if (!b) return;
  chosen = { id: +b.dataset.id, name: b.dataset.name };
  $('chosen').textContent = chosen.name; $('form').classList.remove('hide'); $('name').focus();
});
$('back').onclick = () => { $('form').classList.add('hide'); chosen = null; };

$('take').onclick = async () => {
  $('err').textContent = '';
  try {
    const { token_id } = await api('take', { service_id: chosen.id, name: $('name').value });
    localStorage.setItem('ql_token', token_id);
    startTracking(token_id);
  } catch (e) { $('err').textContent = e.message; }
};

function startTracking(id) {
  $('pick').classList.add('hide'); $('track').classList.remove('hide');
  startAhead = null; notified = false;
  if ('Notification' in window && Notification.permission === 'default') Notification.requestPermission();
  clearInterval(timer); refresh(id); timer = setInterval(() => refresh(id), 3000);
}

async function refresh(id) {
  let t;
  try { t = await api('status', null, '&token_id=' + id); } catch (e) { return endTracking(); }
  if (startAhead === null) startAhead = Math.max(t.ahead, 1);
  $('tsvc').textContent = t.service; $('tnum').textContent = t.label;
  $('tserv').textContent = t.serving || '—'; $('tahead').textContent = t.ahead;
  $('tbar').style.width = (t.status === 'waiting' ? 100 - (t.ahead / startAhead) * 100 : 100) + '%';
  const tk = $('ticket'); tk.classList.toggle('turn', t.status === 'serving');
  const m = { waiting: t.ahead ? `About ${t.eta_min} min to go. Stay nearby.` : 'You are next. Head to the counter.',
    serving: "It's your turn! Please go to the counter now.", done: 'All done. Thank you!',
    skipped: 'Your token was skipped because you were not present. Please take a new one.', cancelled: 'Token cancelled.' }[t.status];
  $('tmsg').textContent = m;
  if (t.status === 'serving' && !notified) { notified = true; alertTurn(t.label); }
  if (t.status === 'waiting' && t.ahead <= 2 && t.ahead > 0 && !tk.dataset.warn) { tk.dataset.warn = 1; beep(1); }
  if (t.status !== 'waiting' && t.status !== 'serving') { clearInterval(timer); $('cancel').classList.add('hide'); $('newtok').classList.remove('hide'); localStorage.removeItem('ql_token'); }
  document.title = t.status === 'serving' ? '🔔 Your turn! ' + t.label : t.label + ' · QueueLess';
}

function beep(n = 3) {
  try { const c = new (window.AudioContext || window.webkitAudioContext)(); for (let i = 0; i < n; i++) { const o = c.createOscillator(); o.connect(c.destination); o.frequency.value = 880; o.start(c.currentTime + i * .35); o.stop(c.currentTime + i * .35 + .2); } } catch (e) {}
  if (navigator.vibrate) navigator.vibrate([200, 100, 200]);
}
function alertTurn(label) {
  beep(3);
  if ('Notification' in window && Notification.permission === 'granted') new Notification("It's your turn!", { body: 'Token ' + label + ' — please go to the counter.' });
}

function endTracking() { clearInterval(timer); localStorage.removeItem('ql_token'); location.reload(); }
$('cancel').onclick = async () => { await api('cancel', { token_id: +localStorage.getItem('ql_token') }); refresh(localStorage.getItem('ql_token')); };
$('newtok').onclick = () => location.reload();

// resume an active token after refresh, otherwise show live service board
const saved = localStorage.getItem('ql_token');
if (saved) startTracking(saved); else { loadServices(); setInterval(() => { if (!chosen) loadServices(); }, 5000); }
