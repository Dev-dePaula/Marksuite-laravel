import React, { useState } from 'react';
import { Area, AreaChart, Bar, CartesianGrid, ComposedChart, Legend, Line, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { Ac, Bar as PBar, Confirm, FormModal, KpiRow, Loader, PageHead, can, csv, hcol, useAction, useApi, useSave, useToast } from '../ui.jsx';
import { api } from '../api.js';

export function Okr({ user }) {
  const st = useApi('/okrs');
  const [add, setAdd] = useState(false);
  const [upd, setUpd] = useState(null);
  const [del, setDel] = useState(null);
  const act = useAction();
  const save = useSave();
  const w = can(user, 'okrs');
  return (
    <>
      <PageHead title="Estratégia — OKR" sub="Objectivos, resultados-chave e ligação à execução">{w && <button className="btn" onClick={() => setAdd(true)}>+ Novo objectivo</button>}</PageHead>
      <Loader state={st}>{(rows) => rows.map((o, i) => (
        <div className="panel" style={{ padding: 18, marginBottom: 14 }} key={o.id}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
            <div style={{ flex: 1 }}><small style={{ color: 'var(--muted)', fontWeight: 600 }}>OBJECTIVO {i + 1}</small><h3 style={{ fontSize: 18 }}>{o.title}</h3></div>
            <div className="ring sm" style={{ '--p': o.progress, '--c': hcol(o.progress + 20) }}><b>{o.progress}%</b></div></div>
          <div style={{ marginTop: 14 }}>{o.keyResults.map((k, j) => (
            <div className="prog" key={k.id}><div className="t"><span>KR{j + 1} — {k.title}</span><strong>{k.progress}%</strong></div><PBar value={k.progress} tone={k.progress < 50 ? 'amb' : 'green'} /></div>))}</div>
          {w && <div className="actions" style={{ marginTop: 6 }}><button className="btn sm ghost" onClick={() => setUpd(o)}>Actualizar progresso</button>
            <button className="btn sm ghost" onClick={() => setDel(o)}>Eliminar</button></div>}
        </div>))}</Loader>
      {add && <FormModal title="Novo objectivo" onClose={() => setAdd(false)}
        fields={[{ k: 'title', l: 'Objectivo', req: true, wide: true }, { k: 'k1', l: 'Resultado-chave 1', wide: true }, { k: 'k2', l: 'Resultado-chave 2', wide: true }, { k: 'k3', l: 'Resultado-chave 3', wide: true }]}
        onSubmit={({ title, k1, k2, k3 }) => save(() => api.post('/okrs', { title, keyResults: [k1, k2, k3].filter(Boolean) }), 'Objectivo criado.', st.reload)} />}
      {upd && <FormModal title={`Actualizar — ${upd.title}`} onClose={() => setUpd(null)}
        fields={upd.keyResults.map((k) => ({ k: `kr${k.id}`, l: `${k.title} (%)`, type: 'number', min: 0, max: 100, wide: true, req: true }))}
        initial={Object.fromEntries(upd.keyResults.map((k) => [`kr${k.id}`, k.progress]))}
        onSubmit={(v) => save(() => Promise.all(upd.keyResults.map((k) => api.put(`/key-results/${k.id}`, { progress: Math.max(0, Math.min(100, v[`kr${k.id}`])) }))), 'Progresso actualizado.', st.reload)} />}
      {del && <Confirm message={`Eliminar o objectivo "${del.title}"?`} label="Eliminar" onClose={() => setDel(null)} onOk={() => act(() => api.del(`/okrs/${del.id}`), 'Eliminado.', st.reload)} />}
    </>
  );
}

const MON = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
const REPORTS = [['Relatório executivo do Grupo', 'Consolidado de todas as dimensões'], ['Relatório comercial', 'Pipeline, conversão e previsão'], ['Relatório financeiro', 'Caixa, margens e cobranças'],
  ['Relatório de projectos', 'Execução, prazos e riscos'], ['Relatório de pessoas', 'Desempenho e capacidade'], ['Relatório de decisões', 'Memória Corporativa do período']];

export function Bi() {
  const st = useApi('/dashboard');
  const projects = useApi('/projects');
  const opps = useApi('/opportunities');
  const emps = useApi('/employees');
  const mem = useApi('/memory');
  const toast = useToast();

  // Cada relatório exporta dados reais em CSV
  const gen = (name) => {
    const d = st.data;
    if (name.includes('executivo')) return csv('executivo.csv', ['Empresa', 'Saúde', 'Receita YTD', 'Margem %'], d.companies.map((c) => [c.name, c.health, c.revenue, c.margin ?? '']));
    if (name.includes('comercial') && opps.data) return csv('comercial.csv', ['Cliente', 'Oportunidade', 'Empresa', 'Valor', 'Fase', 'Prob %'], opps.data.map((o) => [o.client, o.title, o.company.name, o.value, o.stage, o.probability]));
    if (name.includes('financeiro')) return csv('financeiro.csv', ['Mês', 'Entradas', 'Saídas'], d.cashFlow.map((m) => [m.month, m.in, m.out]));
    if (name.includes('projectos') && projects.data) return csv('projectos.csv', ['Projecto', 'Empresa', 'Responsável', 'Progresso', 'Prazo', 'Risco %', 'Estado'], projects.data.map((p) => [p.name, p.company.name, p.owner.name, p.progress, p.dueDate.slice(0, 10), p.risk, p.status]));
    if (name.includes('pessoas') && emps.data) return csv('pessoas.csv', ['Nome', 'Função', 'Empresa', 'Desempenho', 'Capacidade'], emps.data.map((e) => [e.name, e.jobTitle, e.company.name, e.performance, e.capacity]));
    if (name.includes('decisões') && mem.data) return csv('decisoes.csv', ['Data', 'Decisão', 'Responsável', 'Razão', 'Resultado'], mem.data.items.map((i) => [i.date.slice(0, 10), i.title, i.responsible, i.reason, i.outcome]));
    toast('Dados ainda a carregar, tente novamente.', true);
  };

  return (
    <>
      <PageHead title="Relatórios (BI)" sub="Indicadores consolidados do grupo" />
      <Loader state={st}>{(d) => (
        <>
          <KpiRow items={[['Saúde do grupo', d.groupHealth, '#16A34A', 'chart'], ['Receita YTD', Ac(d.revenueYtd), '#2563EB', 'chart'], ['Pipeline', Ac(d.pipeline), '#F59E0B', 'chart'],
            ['Projectos em risco', d.projects.atRisk + d.projects.late, '#DC2626', 'chart']]} />
          <div className="grid g2">
            <div className="panel"><div className="hd"><h3>Saúde do grupo (meses)</h3></div><div className="bd"><div className="chart-wrap">
              <ResponsiveContainer><AreaChart data={d.healthTrend.map((h) => ({ name: MON[+h.month.slice(5) - 1], Saúde: h.score }))}>
                <CartesianGrid vertical={false} stroke="#E5E9F0" /><XAxis dataKey="name" tickLine={false} /><YAxis domain={[50, 100]} /><Tooltip />
                <Area dataKey="Saúde" stroke="#2563EB" fill="rgba(37,99,235,.12)" /></AreaChart></ResponsiveContainer></div></div></div>
            <div className="panel"><div className="hd"><h3>Receita vs margem por empresa</h3></div><div className="bd"><div className="chart-wrap">
              <ResponsiveContainer><ComposedChart data={d.companies.map((c) => ({ name: c.abbr, 'Receita (M AOA)': Math.round(c.revenue / 1e6), 'Margem (%)': c.margin }))}>
                <CartesianGrid vertical={false} stroke="#E5E9F0" /><XAxis dataKey="name" tickLine={false} /><YAxis yAxisId="l" /><YAxis yAxisId="r" orientation="right" /><Tooltip /><Legend />
                <Bar yAxisId="l" dataKey="Receita (M AOA)" fill="#2563EB" radius={[4, 4, 0, 0]} /><Line yAxisId="r" dataKey="Margem (%)" stroke="#F59E0B" /></ComposedChart></ResponsiveContainer></div></div></div>
          </div>
          <div className="panel" style={{ marginTop: 14 }}><div className="hd"><h3>Relatórios disponíveis (exportação CSV)</h3></div><div className="bd">
            {REPORTS.map(([t, s]) => (<div key={t} style={{ display: 'flex', gap: 12, alignItems: 'center', padding: '10px 0', borderBottom: '1px solid var(--line)' }}>
              <div style={{ flex: 1 }}><strong>{t}</strong><br /><small style={{ color: 'var(--muted)' }}>{s}</small></div><button className="btn sm ghost" onClick={() => gen(t.toLowerCase())}>Exportar</button></div>))}</div></div>
        </>
      )}</Loader>
    </>
  );
}
