import React, { useState } from 'react';
import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { Ac, Loader, PageHead, Tag, csv, useApi, useToast } from '../ui.jsx';
import { api } from '../api.js';

const PERIODS = [[7, '7 dias'], [30, '30 dias'], [90, '90 dias']];
const COL = { melhor: '#16A34A', pior: '#DC2626', neutro: '#64748B', igual: '#64748B' };

const fv = (v, unit) => (v == null ? '—' : unit === 'AOA' ? Ac(v) : unit === '%' ? `${Math.round(v)}%` : String(Math.round(v * 10) / 10).replace('.', ','));
const fdt = (d) => new Date(d).toLocaleString('pt-PT', { dateStyle: 'short', timeStyle: 'short' });

function Delta({ k }) {
  if (k.previous == null) return <small style={{ color: 'var(--muted)' }}>sem histórico</small>;
  if (k.delta === 0) return <small style={{ color: COL.igual }}>= sem alteração</small>;
  const up = k.delta > 0;
  const txt = k.unit === 'pts' || k.unit === '%' || k.deltaPct == null
    ? `${up ? '+' : '−'}${fv(Math.abs(k.delta), k.unit === 'AOA' ? 'AOA' : 'n')}${k.unit === 'pts' ? ' pts' : k.unit === '%' ? ' p.p.' : ''}`
    : `${up ? '+' : '−'}${Math.abs(k.deltaPct).toFixed(1).replace('.', ',')}%`;
  return <small style={{ color: COL[k.status], fontWeight: 700 }}>{up ? '▲' : '▼'} {txt}</small>;
}

function Spark({ data, color }) {
  if (!data || data.length < 2) return <div style={{ height: 38 }} />;
  return (
    <div style={{ height: 38 }}>
      <ResponsiveContainer><LineChart data={data}><Line dataKey="value" stroke={color} strokeWidth={2} dot={false} isAnimationActive={false} /></LineChart></ResponsiveContainer>
    </div>
  );
}

// Texto da IA: listas com "- " e **negrito**
function AiText({ text }) {
  const bold = (s) => s.split(/(\*\*[^*]+\*\*)/g).map((p, i) => (p.startsWith('**') ? <strong key={i}>{p.slice(2, -2)}</strong> : p));
  return (
    <div style={{ lineHeight: 1.55 }}>
      {text.split('\n').filter((l) => l.trim()).map((l, i) => {
        const m = l.match(/^\s*[-*•]\s+(.*)/);
        return m ? <div key={i} style={{ display: 'flex', gap: 8, margin: '4px 0' }}><span>•</span><span>{bold(m[1])}</span></div>
          : <div key={i} style={{ margin: '10px 0 4px', fontWeight: 600 }}>{bold(l.replace(/^#+\s*/, ''))}</div>;
      })}
    </div>
  );
}

export default function Analytics({ user }) {
  const [days, setDays] = useState(30);
  const [scope, setScope] = useState(0);
  const [sel, setSel] = useState('overdueTotal');
  const [busy, setBusy] = useState(false);
  const toast = useToast();
  const ov = useApi(`/analytics/overview?days=${days}&company=${scope}`);
  const al = useApi(`/analytics/alerts?days=${days}`);
  const br = useApi(`/analytics/breakdown?key=${sel}&days=${days}`);
  const ins = useApi('/analytics/insights');
  const canGen = ['DIRECTOR_GERAL', 'CFO'].includes(user.role);

  const generate = async () => {
    setBusy(true);
    try { await api.post('/analytics/insights', { days }); toast('Análise gerada.'); ins.reload(); } catch (e) { toast(e.message, true); } finally { setBusy(false); }
  };
  const scopeName = ov.data?.scopes.find((s) => s.id === scope)?.name || 'Grupo';
  const selKpi = ov.data?.kpis.find((k) => k.key === sel);

  const expCompare = () => ov.data && csv(`comparacao-${scopeName}-${days}d.csv`,
    ['Indicador', 'Âmbito', 'Período (dias)', 'Atual', 'Anterior', 'Variação', 'Variação %', 'Leitura'],
    ov.data.kpis.map((k) => [k.label, scopeName, days, k.current, k.previous ?? '', k.delta ?? '', k.deltaPct == null ? '' : k.deltaPct.toFixed(1), k.status ?? '']));
  const expHistory = async () => {
    try {
      const rows = await api.get('/analytics/history?days=180');
      csv('historico-kpis.csv', ['Dia', 'Âmbito', 'Indicador', 'Unidade', 'Valor'], rows.map((r) => [r.day, r.scope, r.kpi, r.unit, r.value]));
    } catch (e) { toast(e.message, true); }
  };

  return (
    <>
      <PageHead title="Central de Análise" sub="Evolução dos indicadores, comparação entre períodos, alertas e análise por IA">
        <select value={scope} onChange={(e) => setScope(Number(e.target.value))} aria-label="Âmbito">
          {(ov.data?.scopes || [{ id: 0, name: 'Grupo' }]).map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
        </select>
        {PERIODS.map(([d, l]) => <button key={d} className={`btn sm ${days === d ? '' : 'ghost'}`} style={{ marginLeft: 6 }} onClick={() => setDays(d)}>{l}</button>)}
      </PageHead>

      <div className="grid g-21">
        <div className="panel">
          <div className="hd"><h3>Análise por IA</h3><div className="spacer" />
            {canGen && <button className="btn sm" disabled={busy} onClick={generate}>{busy ? 'A analisar…' : 'Gerar nova análise'}</button>}
          </div>
          <div className="bd">
            <Loader state={ins}>{(d) => d.insight ? (
              <>
                <AiText text={d.insight.content} />
                <small style={{ color: 'var(--muted)' }}>Gerada em {fdt(d.insight.createdAt)} · comparação de {d.insight.days} dias</small>
              </>
            ) : (
              <p style={{ color: 'var(--muted)' }}>
                {d.aiConfigured ? 'Ainda não há análise. É gerada automaticamente uma vez por dia assim que houver histórico para comparar' : 'O assistente de IA não está configurado no servidor'}
                {canGen && d.aiConfigured ? ', ou pode gerá-la agora no botão acima.' : '.'}
              </p>
            )}</Loader>
          </div>
        </div>

        <div className="panel">
          <div className="hd"><h3>Alertas ({days} dias)</h3></div>
          <Loader state={al}>{(d) => d.alerts.length === 0 ? <div className="bd">Nenhum indicador piorou de forma relevante. ✔</div> : (
            <div className="bd" style={{ maxHeight: 330, overflowY: 'auto' }}>
              {d.alerts.map((a, i) => (
                <div key={i} style={{ display: 'flex', gap: 8, padding: '8px 0', borderBottom: '1px solid var(--line)', cursor: 'pointer' }}
                  onClick={() => { setSel(a.key); setScope(a.scope); }}>
                  <Tag kind={a.level === 'Alta' ? 'red' : 'amb'}>{a.level}</Tag><span style={{ fontSize: 13 }}>{a.text}</span>
                </div>
              ))}
            </div>
          )}</Loader>
        </div>
      </div>

      <div style={{ display: 'flex', alignItems: 'center', margin: '18px 0 10px' }}>
        <h3 style={{ margin: 0 }}>Indicadores — {scopeName}</h3><div className="spacer" />
        <button className="btn sm ghost" onClick={expCompare}>Exportar comparação (CSV)</button>
        <button className="btn sm ghost" style={{ marginLeft: 6 }} onClick={expHistory}>Exportar histórico (CSV)</button>
      </div>

      <Loader state={ov}>{(d) => (
        <>
          {!d.hasComparison && (
            <div className="panel" style={{ marginBottom: 12 }}><div className="bd">
              O histórico começou a ser registado em <b>{d.firstDay || 'hoje'}</b>. Há indicadores que ainda não têm um ponto de comparação de {days} dias; as variações aparecem à medida que o histórico cresce.
            </div></div>
          )}
          <div className="grid g4">
            {d.kpis.map((k) => (
              <div key={k.key} className="panel" onClick={() => setSel(k.key)}
                style={{ cursor: 'pointer', padding: 14, outline: k.key === sel ? '2px solid #2563EB' : 'none' }}>
                <small style={{ color: 'var(--muted)', fontWeight: 600 }}>{k.label}</small>
                <div className="num" style={{ fontSize: 22, fontWeight: 700, margin: '4px 0' }}>{fv(k.current, k.unit)}</div>
                <Delta k={k} />
                <Spark data={k.series} color={COL[k.status] || '#2563EB'} />
              </div>
            ))}
          </div>

          {selKpi && (
            <div className="grid g-21" style={{ marginTop: 14 }}>
              <div className="panel"><div className="hd"><h3>{selKpi.label} — {scopeName}</h3></div><div className="bd">
                {selKpi.series.length < 2 ? <p style={{ color: 'var(--muted)' }}>Ainda só há um ponto de dados deste indicador.</p> : (
                  <div className="chart-wrap" style={{ height: 240 }}>
                    <ResponsiveContainer><LineChart data={selKpi.series.map((p) => ({ name: p.day.slice(5), value: p.value }))}>
                      <CartesianGrid vertical={false} stroke="#E5E9F0" /><XAxis dataKey="name" tickLine={false} fontSize={11} />
                      <YAxis fontSize={11} width={60} tickFormatter={(v) => (selKpi.unit === 'AOA' ? `${(v / 1e6).toFixed(1)}M` : v)} />
                      <Tooltip formatter={(v) => fv(v, selKpi.unit)} /><Line dataKey="value" name={selKpi.label} stroke="#2563EB" strokeWidth={2} dot={false} />
                    </LineChart></ResponsiveContainer>
                  </div>
                )}
              </div></div>

              <div className="panel"><div className="hd"><h3>Por empresa — {selKpi.label}</h3></div>
                <Loader state={br}>{(b) => (
                  <div className="tablewrap"><table><thead><tr><th>Âmbito</th><th className="amt">Atual</th><th className="amt">Anterior</th><th className="amt">Var.</th></tr></thead><tbody>
                    {b.rows.map((r) => (
                      <tr key={r.scope} onClick={() => setScope(r.scope)} style={{ cursor: 'pointer', fontWeight: r.scope === scope ? 700 : 400 }}>
                        <td>{r.name}</td><td className="amt">{fv(r.current, b.unit)}</td><td className="amt">{fv(r.previous, b.unit)}</td>
                        <td className="amt" style={{ color: COL[r.status] }}>{r.previous == null ? '—' : r.deltaPct != null && b.unit !== 'pts' && b.unit !== '%' ? `${r.delta > 0 ? '+' : ''}${r.deltaPct.toFixed(0)}%` : `${r.delta > 0 ? '+' : ''}${fv(r.delta, b.unit === 'AOA' ? 'AOA' : 'n')}`}</td>
                      </tr>
                    ))}
                  </tbody></table></div>
                )}</Loader>
              </div>
            </div>
          )}
        </>
      )}</Loader>
    </>
  );
}
