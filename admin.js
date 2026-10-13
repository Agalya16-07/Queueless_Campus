const API = 'api/index.php?action=';
const $ = id => document.getElementById(id);
let services = [], sid = null, timer = null;

async function api(action, body, qs = '') {
  const r = await fetch(API + action + qs, body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) } : {});
  const d = await r.json();
  if (!r.ok) throw new Error(d.error || 'Something went wrong');
  return d;
}
const label = n => { const s = services.find(x => x.id == sid); return s.code + String(n).padStart(3, '0'); };

async function show() {
  $('login').classList.add('hide'); $('dash').classList.remove('hide');
  services = await api('services'); sid = sid || services[0].id;
  $('tabs').innerHTML = services.map(s => `<button class="btn alt ${s.id == sid ? 'on' : ''}" data-id="${s.id}">${s.name} (${s.waiting})</button>`).join('');
  load(); clearInterval(timer); timer = setInterval(refreshAll, 3000);
}
async function refreshAll() { services = await api('services'); $('tabs').querySelectorAll('button').forEach((b, i) => { b.textContent = `${services[i].name} (${services[i].waiting})`; }); load(); }

async function load() {
  const d = await api('admin_queue', null, '&service_id=' + sid);
  $('now').textContent = d.current ? label(d.current.number) : '—';
  $('nowname').textContent = d.current ? d.current.name : 'No one at the counter';
  $('wait').innerHTML = d.waiting.map(w => `<tr><td><b>${label(w.number)}</b></td><td>${w.name.replace(/</g, '&lt;')}</td><td>${w.created_at.slice(11, 16)}</td></tr>`).join('');
  $('empty').classList.toggle('hide', d.waiting.length > 0);
  const s = d.stats;
  $('stats').innerHTML = `<span>Served: ${s.done || 0}</span><span>Skipped: ${s.skipped || 0}</span><span>Cancelled: ${s.cancelled || 0}</span><span>Waiting: ${d.waiting.length}</span>`;
}

const act = (a) => async () => { await api(a, { service_id: sid }); refreshAll(); };
$('next').onclick = act('admin_next'); $('skip').onclick = act('admin_skip'); $('done').onclick = act('admin_done');
$('reset').onclick = async () => { if (confirm("Delete all of today's tokens for this service?")) act('admin_reset')(); };
$('tabs').onclick = e => { const b = e.target.closest('button'); if (!b) return; sid = +b.dataset.id; $('tabs').querySelectorAll('button').forEach(x => x.classList.toggle('on', x == b)); load(); };
$('add').onclick = async () => { try { await api('admin_service_add', { name: $('newname').value, avg_minutes: +$('newavg').value || 5 }); $('newname').value = ''; show(); } catch (e) { alert(e.message); } };
$('go').onclick = async () => { try { await api('login', { password: $('pw').value }); show(); } catch (e) { $('err').textContent = e.message; } };
$('pw').onkeydown = e => { if (e.key === 'Enter') $('go').click(); };
$('out').onclick = async () => { await api('logout', {}); location.reload(); };
api('whoami').then(d => d.admin && show());
