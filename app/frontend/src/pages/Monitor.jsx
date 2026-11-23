import React, { useEffect, useState } from 'react';
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { KpiRow, Loader, PageHead, Tag, csv, useApi } from '../ui.jsx';

const TYPE_L = { LOGIN: 'Login', LOGIN_FAIL: 'Login falhado', VISIT: 'Visita', ACTION: 'Acção' };
const TYPE_K = { LOGIN: 'ok', LOGIN_FAIL: 'red', VISIT: 'blue', ACTION: 'amb' };
const fdt = (d) => (d ? new Date(d).toLocaleString('pt-PT', { dateStyle: 'short', timeStyle: 'medium' }) : '—');
const short = (ua = '') => (/mobile|android|iphone/i.test(ua) ? 'Telemóvel' : ua ? 'Computador' : '—');

export default function Monitor({ user }) {
  const [days, setDays] = useState(7);
  const [who, setWho] = useState('');
  const [tab, setTab] = useState('activity');
  const [open, setOpen] = useState(null);
  const q = `days=${days}${who ? `&username=${encodeURIComponent(who)}` : ''}`;
  const summary = useApi(`/monitor/summary?days=${days}`);
  const daily = useApi(`/monitor/daily?${q}`);
  const activity = useApi(`/monitor/activity?${q}`);
  const errors = useApi(`/monitor/errors?${q}`);

  // actualização automática a cada 30s
  useEffect(() => {
    const t = setInterval(() => { summary.reload(); daily.reload(); activity.reload(); errors.reload(); }, 30000);
    return () => clearInterval(t);
  });

  if (user.role !== 'DIRECTOR_GERAL') {
    return <><PageHead title="Monitorização" /><div className="errbox">Apenas o Director-Geral acede a esta página.</div></>;
  }

  const sel = summary.data?.users.find((u) => u.username === who);
  const tot = (summary.data?.users || []).filter((u) => !who || u.username === who)
    .reduce((a, u) => ({ logins: a.logins + u.logins, visits: a.visits + u.visits, actions: a.actions + u.actions, errors: a.errors + u.errors, fails: a.fails + u.loginFails }), { logins: 0, visits: 0, actions: 0, errors: 0, fails: 0 });

  return (
    <>
      <PageHead title="Monitorização" sub={who ? `Utilizador: ${sel?.name || who} (${who})` : 'Todos os utilizadores'}>
        <select value={who} onChange={(e) => setWho(e.target.value)} aria-label="Utilizador">
          <option value="">Todos os utilizadores</option>
          {(summary.data?.users || []).map((u) => <option key={u.username} value={u.username}>{u.username}</option>)}
        </select>
        <select value={days} onChange={(e) => setDays(Number(e.target.value))} aria-label="Período" style={{ marginLeft: 8 }}>
          {[1, 7, 30, 90].map((d) => <option key={d} value={d}>{d === 1 ? 'Últimas 24h' : `Últimos ${d} dias`}</option>)}
        </select>
      </PageHead>

      <KpiRow cols={5} items={[
        ['Logins', tot.logins, '#2563EB', 'people'], ['Visitas (reloads)', tot.visits, '#0E9F6E', 'home'], ['Acções (gravações)', tot.actions, '#F59E0B', 'ops'],
        ['Logins falhados', tot.fails, '#DC2626', 'check'], ['Erros', tot.errors, '#DC2626', 'chart'],
      ]} />

      <div className="grid g-21">
        <div className="panel"><div className="hd"><h3>Acessos por dia</h3></div><div className="bd">
          <Loader state={daily}>{(rows) => (
            <div className="chart-wrap" style={{ height: 220 }}>
              <ResponsiveContainer><BarChart data={rows.map((r) => ({ ...r, name: r.day.slice(5) }))}>
                <CartesianGrid vertical={false} stroke="#E5E9F0" /><XAxis dataKey="name" tickLine={false} fontSize={11} /><YAxis allowDecimals={false} fontSize={11} /><Tooltip /><Legend />
                <Bar dataKey="logins" name="Logins" fill="#2563EB" radius={[4, 4, 0, 0]} /><Bar dataKey="visits" name="Visitas" fill="#0E9F6E" radius={[4, 4, 0, 0]} /><Bar dataKey="actions" name="Acções" fill="#F59E0B" radius={[4, 4, 0, 0]} />
              </BarChart></ResponsiveContainer>
            </div>
          )}</Loader>
        </div></div>

        <div className="panel"><div className="hd"><h3>Por utilizador</h3></div>
          <Loader state={summary}>{(d) => (
            <div className="tablewrap"><table><thead><tr><th>Utilizador</th><th className="amt">Logins</th><th className="amt">Erros</th><th>Último acesso</th></tr></thead><tbody>
              {d.users.map((u) => (
                <tr key={u.username} onClick={() => setWho(u.username === who ? '' : u.username)} style={{ cursor: 'pointer', fontWeight: u.username === who ? 700 : 400 }}>
                  <td>{u.username}</td><td className="amt">{u.logins}</td><td className="amt" style={u.errors ? { color: '#DC2626' } : undefined}>{u.errors}</td><td>{fdt(u.lastSeen)}</td>
                </tr>
              ))}
            </tbody></table></div>
          )}</Loader>
        </div>
      </div>

      <div className="panel" style={{ marginTop: 14 }}>
        <div className="hd">
          <button className={`btn ${tab === 'activity' ? '' : 'ghost'}`} onClick={() => setTab('activity')}>Actividade</button>
          <button className={`btn ${tab === 'errors' ? '' : 'ghost'}`} style={{ marginLeft: 8 }} onClick={() => setTab('errors')}>Erros {errors.data?.length ? `(${errors.data.length})` : ''}</button>
          <div className="spacer" />
          <button className="btn ghost" onClick={() => {
            const rows = tab === 'activity' ? activity.data : errors.data;
            if (!rows) return;
            tab === 'activity'
              ? csv('actividade.csv', ['Data', 'Tipo', 'Utilizador', 'Método', 'Caminho', 'IP', 'Dispositivo'], rows.map((r) => [fdt(r.createdAt), TYPE_L[r.type], r.username, r.method, r.path, r.ip, r.userAgent]))
              : csv('erros.csv', ['Data', 'Origem', 'Utilizador', 'Estado', 'Método', 'Caminho', 'Mensagem', 'IP'], rows.map((r) => [fdt(r.createdAt), r.source, r.username, r.status, r.method, r.path, r.message, r.ip]));
          }}>Exportar CSV</button>
        </div>

        {tab === 'activity' ? (
          <Loader state={activity}>{(rows) => rows.length === 0 ? <div className="bd">Sem actividade neste período.</div> : (
            <div className="tablewrap"><table><thead><tr><th>Data</th><th>Tipo</th><th>Utilizador</th><th>Pedido</th><th>IP</th><th>Dispositivo</th></tr></thead><tbody>
              {rows.map((r) => (
                <tr key={r.id}><td>{fdt(r.createdAt)}</td><td><Tag kind={TYPE_K[r.type]}>{TYPE_L[r.type]}</Tag></td><td>{r.username}</td>
                  <td>{r.type === 'ACTION' ? `${r.method} ${r.path}` : '—'}</td><td>{r.ip || '—'}</td><td title={r.userAgent || ''}>{short(r.userAgent)}</td></tr>
              ))}
            </tbody></table></div>
          )}</Loader>
        ) : (
          <Loader state={errors}>{(rows) => rows.length === 0 ? <div className="bd">Sem erros neste período. 🎉</div> : (
            <div className="tablewrap"><table><thead><tr><th>Data</th><th>Origem</th><th>Utilizador</th><th>Estado</th><th>Pedido</th><th>Mensagem</th></tr></thead><tbody>
              {rows.map((r) => (
                <React.Fragment key={r.id}>
                  <tr onClick={() => setOpen(open === r.id ? null : r.id)} style={{ cursor: r.stack ? 'pointer' : 'default' }}>
                    <td>{fdt(r.createdAt)}</td><td><Tag kind={r.source === 'SERVER' ? 'red' : 'amb'}>{r.source === 'SERVER' ? 'Servidor' : 'Navegador'}</Tag></td>
                    <td>{r.username || '—'}</td><td>{r.status || '—'}</td><td>{r.method ? `${r.method} ${r.path}` : '—'}</td><td>{r.message}</td>
                  </tr>
                  {open === r.id && r.stack && <tr><td colSpan={6}><pre style={{ whiteSpace: 'pre-wrap', fontSize: 11, margin: 0 }}>{r.stack}</pre></td></tr>}
                </React.Fragment>
              ))}
            </tbody></table></div>
          )}</Loader>
        )}
      </div>
    </>
  );
}
