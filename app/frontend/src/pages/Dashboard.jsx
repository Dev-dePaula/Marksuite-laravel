import React, { useState } from 'react';
import { Bar, CartesianGrid, BarChart, ResponsiveContainer, Tooltip, XAxis, YAxis, Legend } from 'recharts';
import { Ac, A, Bar as ProgressBar, Icon, Loader, Tag, FormModal, hcol, useApi, useSave, fdate } from '../ui.jsx';
import { api } from '../api.js';

const MON = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
const monthLabel = (k) => MON[Number(k.slice(5, 7)) - 1];
const PRIO_COL = { Alta: '#DC2626', 'Média': '#F59E0B' };
const GO = { opportunity: 'crm', project: 'proj', invoice: 'fin', decisions: 'decisoes', employees: 'pes', companies: 'emp' };

export default function Dashboard({ user, go, greet, now, weekday }) {
  const dash = useApi('/dashboard');
  const projects = useApi('/projects');
  const decisions = useApi('/decisions');
  const people = useApi('/employees');
  const [delegate, setDelegate] = useState(null);
  const save = useSave();

  return (
    <Loader state={dash}>{(d) => (
      <>
        <div className="hero">
          <div className="bgimg" style={{ backgroundImage: "url('/bay.jpg')" }} />
          <div className="logo-plate hero-logo"><img src="/logo.png" alt="Marksuite" /></div>
          <div className="date"><span style={{ textTransform: 'capitalize' }}>{weekday}</span><b>{now.getDate()}</b><span>{now.toLocaleDateString('pt-PT', { month: 'long', year: 'numeric' })}</span><br /><span>{now.toTimeString().slice(0, 5)}</span></div>
          <h2>{greet}, <span>{user.name}</span></h2>
          <p>Aqui está o que precisa de saber hoje.</p>
          <div className="segs" />
        </div>

        <div className="grid g6" style={{ marginTop: 14 }}>
          <div className="panel kpi" style={{ display: 'flex', gap: 14, alignItems: 'center' }}>
            <div className="ring" style={{ '--p': d.groupHealth, '--c': hcol(d.groupHealth) }}><b>{d.groupHealth}<i>/100</i></b></div>
            <div><div style={{ fontSize: 13.5, fontWeight: 600 }}>Saúde do Grupo</div>
              {d.healthDelta != null && <div className={d.healthDelta >= 0 ? 'up' : 'dn'} style={{ fontSize: 13, fontWeight: 600 }}>{d.healthDelta >= 0 ? '+' : ''}{d.healthDelta}%</div>}
              <small style={{ color: 'var(--muted)' }}>vs. mês anterior</small></div>
          </div>
          {[
            ['Receita (YTD)', Ac(d.revenueYtd), null, '#16A34A', 'money'],
            ['Caixa', Ac(d.cash), null, '#0EA5E9', 'money'],
            ['Projectos', `${d.projects.active} activos`, `${d.projects.onTime} no prazo · ${d.projects.atRisk} em risco · ${d.projects.late} atrasados`, '#2563EB', 'proj'],
            ['Comercial', `${d.openOpportunities} oportunidades`, `${Ac(d.pipeline)} em pipeline`, '#F59E0B', 'crm'],
            ['Operações', `${d.opsAvg}%`, 'Execução média dos processos', '#7C3AED', 'ops'],
          ].map(([l, v, f, col, ic]) => (
            <div className="panel kpi" key={l}>
              <div className="top"><i style={{ background: col }}><Icon n={ic} size={16} /></i><b>{l}</b></div>
              <div className="val num">{v}</div>{f && <div className="foot">{f}</div>}
            </div>
          ))}
        </div>

        <div className="grid g-121" style={{ marginTop: 14 }}>
          <div className="prio">
            <div className="hd"><h3>Prioridades de hoje</h3><span className="cnt">{d.priorities.length}</span></div>
            {d.priorities.length === 0 && <div className="it"><small>Nada de crítico. Tudo em dia.</small></div>}
            {d.priorities.map((p, i) => (
              <div className="it" key={p.key}>
                <span className="n" style={{ background: PRIO_COL[p.level] || '#2563EB' }}>{i + 1}</span>
                <div style={{ flex: 1 }}>
                  <small style={{ color: '#9FB3CE', fontWeight: 600 }}>{p.area}</small>
                  <b>{p.title}</b><small>{p.detail}</small>
                  {p.hint && <div className="rec">{p.hint}</div>}
                  <div className="acts">
                    <button onClick={() => go(GO[p.ref.type])}>Abrir</button>
                    <button onClick={() => setDelegate(p)}>Delegar</button>
                  </div>
                </div>
                <div className="meta"><Tag>{p.level}</Tag></div>
              </div>
            ))}
          </div>

          <div className="panel">
            <div className="hd"><h3>Desempenho das empresas</h3></div>
            <div className="tablewrap"><table><thead><tr><th>Empresa</th><th>Saúde</th><th className="amt">Receita (YTD)</th><th>Margem</th></tr></thead><tbody>
              {d.companies.map((c) => (
                <tr key={c.id}><td><div className="hcell"><span className="company-ic" style={{ background: c.color }}>{c.abbr}</span><strong>{c.name}</strong></div></td>
                  <td><span className="score" style={{ background: hcol(c.health) }}>{c.health}</span></td>
                  <td className="amt">{Ac(c.revenue)}</td><td>{c.margin == null ? '—' : `${c.margin}%`}</td></tr>
              ))}</tbody></table></div>
          </div>

          <div className="panel">
            <div className="hd"><h3>Comercial — Pipeline</h3><div className="spacer" /><span className="lnk" onClick={() => go('crm')}>Ver pipeline →</span></div>
            <div className="bd"><div className="num" style={{ fontSize: 26 }}>{Ac(d.pipeline)}</div><small style={{ color: 'var(--muted)' }}>ponderado: {Ac(d.weighted)}</small>
              <div className="chart-wrap" style={{ height: 170, marginTop: 8 }}>
                <ResponsiveContainer><BarChart data={d.byStage.map((s) => ({ ...s, name: s.stage.slice(0, 1) + s.stage.slice(1).toLowerCase(), M: Math.round(s.value / 1e6) }))}>
                  <XAxis dataKey="name" tickLine={false} fontSize={11} /><YAxis hide /><Tooltip formatter={(v) => `${v}M AOA`} />
                  <Bar dataKey="M" fill="#2563EB" radius={[5, 5, 0, 0]} /></BarChart></ResponsiveContainer>
              </div></div>
          </div>
        </div>

        <div className="grid g-121" style={{ marginTop: 14 }}>
          <div className="panel">
            <div className="hd"><h3>Projectos em destaque</h3><div className="spacer" /><span className="lnk" onClick={() => go('proj')}>Ver todos →</span></div>
            <Loader state={projects}>{(rows) => (
              <div className="tablewrap"><table><thead><tr><th>Projecto</th><th>Progresso</th><th>Prazo</th><th>Estado</th></tr></thead><tbody>
                {rows.filter((p) => p.status !== 'Concluído').sort((a, b) => b.risk - a.risk).slice(0, 4).map((p) => (
                  <tr key={p.id}><td>{p.name}</td><td style={{ width: 110 }}><strong>{p.progress}%</strong><ProgressBar value={p.progress} tone={p.status === 'Atrasado' ? 'red' : p.status === 'Em risco' ? 'amb' : ''} /></td>
                    <td>{fdate(p.dueDate)}</td><td><Tag>{p.status}</Tag></td></tr>
                ))}</tbody></table></div>)}</Loader>
          </div>

          <div className="panel">
            <div className="hd"><h3>Fluxo de caixa</h3><div className="spacer" /><span className="lnk" onClick={() => go('fin')}>Ver detalhes →</span></div>
            <div className="bd">
              <div className="grid g2" style={{ marginBottom: 10 }}>
                <div><small style={{ color: 'var(--muted)' }}>↑ A receber (30 dias)</small><div className="num up" style={{ fontSize: 19 }}>{Ac(d.in30)}</div></div>
                <div><small style={{ color: 'var(--muted)' }}>↓ A pagar (30 dias)</small><div className="num dn" style={{ fontSize: 19 }}>{Ac(d.out30)}</div></div>
              </div>
              <div className="chart-wrap" style={{ height: 170 }}>
                <ResponsiveContainer><BarChart data={d.cashFlow.map((m) => ({ name: monthLabel(m.month), Entradas: Math.round(m.in / 1e6), 'Saídas': Math.round(m.out / 1e6) }))}>
                  <CartesianGrid vertical={false} stroke="#E5E9F0" /><XAxis dataKey="name" tickLine={false} fontSize={11} /><YAxis fontSize={11} tickFormatter={(v) => `${v}M`} /><Tooltip formatter={(v) => `${v}M AOA`} /><Legend />
                  <Bar dataKey="Entradas" fill="#16A34A" radius={[4, 4, 0, 0]} /><Bar dataKey="Saídas" fill="#F87171" radius={[4, 4, 0, 0]} /></BarChart></ResponsiveContainer>
              </div>
            </div>
          </div>

          <div className="panel">
            <div className="hd"><h3>Briefing Executivo</h3><div className="spacer" /><span className="lnk" onClick={() => go('ia')}>Perguntar à IA →</span></div>
            <div className="brief"><b>✦ Aqui está o seu briefing de hoje.</b><ol>{d.briefing.map((b) => <li key={b}>{b}</li>)}</ol></div>
          </div>
        </div>

        <div className="decisions">
          <div className="bgimg" style={{ backgroundImage: "url('/bay.jpg')" }} />
          <h3>Decisões que precisam de si</h3>
          <Loader state={decisions}>{(rows) => (
            <div className="dgrid">
              {rows.length === 0 && <small style={{ color: '#9FB3CE' }}>Sem decisões pendentes.</small>}
              {rows.slice(0, 4).map((x) => (
                <button className="dcard" key={x.id} onClick={() => go('decisoes')}>
                  <i style={{ background: '#2563EB' }}><Icon n="doc" size={17} /></i>
                  <div style={{ flex: 1 }}><b>{x.title}</b><small>{x.subtitle}</small>{x.amount ? <small style={{ color: '#fff', fontWeight: 600 }}>{A(x.amount)}</small> : null}</div><span className="ch">›</span>
                </button>
              ))}
            </div>)}</Loader>
        </div>

        {delegate && people.data && (
          <FormModal title="Delegar assunto" okLabel="Delegar" onClose={() => setDelegate(null)}
            fields={[{ k: 'assigneeId', l: 'Delegar a', type: 'select', req: true, num: true, wide: true, opts: people.data.map((p) => ({ v: p.id, l: p.name })), def: people.data[0]?.id },
              { k: 'notes', l: 'Instruções', type: 'textarea', wide: true }, { k: 'dueDate', l: 'Prazo', type: 'date' }]}
            onSubmit={(v) => save(() => api.post('/tasks', { ...v, title: `${delegate.title} — ${delegate.detail}`.slice(0, 200), source: delegate.key }), 'Assunto delegado.')} />
        )}
      </>
    )}</Loader>
  );
}
