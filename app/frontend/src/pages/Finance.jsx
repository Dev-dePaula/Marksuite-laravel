import React, { useState } from 'react';
import { Bar, BarChart, CartesianGrid, Cell, Legend, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { A, Ac, FormModal, KpiRow, Loader, PageHead, Tag, can, fdate, useAction, useApi, useSave } from '../ui.jsx';
import { api } from '../api.js';

const MON = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
const CATS = ['Recebimentos', 'Pessoal', 'Fornecedores', 'Estrutura', 'Investimento'];

export default function Finance({ user, go }) {
  const dash = useApi('/dashboard');
  const inv = useApi('/invoices?open=1');
  const movs = useApi('/movements');
  const comps = useApi('/companies');
  const [tab, setTab] = useState('fluxo');
  const [form, setForm] = useState(null); // 'mov' | 'inv'
  const act = useAction();
  const save = useSave();
  const w = can(user, 'invoices');
  const reload = () => { dash.reload(); inv.reload(); movs.reload(); };
  const compOpts = (comps.data || []).map((c) => ({ v: c.id, l: c.name }));

  return (
    <>
      <PageHead title="Financeiro" sub="Caixa, recebimentos, margens e cobranças">
        {w && <button className="btn ghost" onClick={() => go('fat')}>+ Nova factura (Faturação)</button>}
        {can(user, 'movements') && <button className="btn" onClick={() => setForm('mov')}>+ Novo lançamento</button>}
      </PageHead>
      <Loader state={dash}>{(d) => {
        return (
          <>
            <KpiRow items={[['Caixa', Ac(d.cash), '#0EA5E9', 'money'], ['A receber (30 dias)', Ac(d.in30), '#16A34A', 'money'], ['Vencido', Ac(d.overdueTotal), '#DC2626', 'money'],
              ['Margem média', `${Math.round(d.companies.filter((c) => c.margin != null).reduce((a, c) => a + c.margin, 0) / (d.companies.filter((c) => c.margin != null).length || 1))}%`, '#7C3AED', 'money']]} />
            <div className="tabs">{[['fluxo', 'Fluxo de caixa'], ['cob', 'Facturas em aberto'], ['mov', 'Movimentos']].map(([k, l]) => (
              <button key={k} className={tab === k ? 'active' : ''} onClick={() => setTab(k)}>{l}</button>))}</div>

            {tab === 'fluxo' && (
              <div className="grid g2">
                <div className="panel"><div className="hd"><h3>Entradas vs saídas</h3></div><div className="bd"><div className="chart-wrap">
                  <ResponsiveContainer><BarChart data={d.cashFlow.map((m) => ({ name: MON[+m.month.slice(5) - 1], Entradas: Math.round(m.in / 1e6), 'Saídas': Math.round(m.out / 1e6) }))}>
                    <CartesianGrid vertical={false} stroke="#E5E9F0" /><XAxis dataKey="name" tickLine={false} /><YAxis tickFormatter={(v) => `${v}M`} /><Tooltip formatter={(v) => `${v}M AOA`} /><Legend />
                    <Bar dataKey="Entradas" fill="#16A34A" radius={[4, 4, 0, 0]} /><Bar dataKey="Saídas" fill="#F87171" radius={[4, 4, 0, 0]} /></BarChart></ResponsiveContainer></div></div></div>
                <div className="panel"><div className="hd"><h3>Receita por empresa (YTD)</h3></div><div className="bd"><div className="chart-wrap">
                  <ResponsiveContainer><PieChart><Pie data={d.companies.map((c) => ({ name: c.name, v: Math.round(c.revenue / 1e6), color: c.color }))} dataKey="v" nameKey="name" innerRadius="55%">
                    {d.companies.map((c) => <Cell key={c.id} fill={c.color} />)}</Pie><Tooltip formatter={(v) => `${v}M AOA`} /><Legend layout="vertical" align="right" verticalAlign="middle" /></PieChart></ResponsiveContainer></div></div></div>
              </div>
            )}

            {tab === 'cob' && (
              <Loader state={inv}>{() => (
                <div className="panel tablewrap"><table><thead><tr><th>Parte</th><th>Documento</th><th>Empresa</th><th>Tipo</th><th>Vencimento</th><th>Atraso</th><th className="amt">Valor</th><th>Estado</th><th /></tr></thead><tbody>
                  {inv.data.map((i) => (
                    <tr key={i.id}><td><strong>{i.counterparty}</strong></td><td>{i.docNumber}</td><td>{i.company.name}</td><td>{i.kind === 'RECEBER' ? 'A receber' : 'A pagar'}</td><td>{fdate(i.dueDate)}</td>
                      <td className={i.overdueDays ? 'dn' : ''}>{i.overdueDays ? `${i.overdueDays} dias` : '—'}</td><td className="amt">{A(i.amount)}</td><td><Tag>{i.status}</Tag></td>
                      <td>{w && <div className="actions">
                        <button className="btn sm ok" onClick={() => act(() => api.post(`/invoices/${i.id}/pay`), i.kind === 'RECEBER' ? 'Recebimento registado.' : 'Pagamento registado.', reload)}>{i.kind === 'RECEBER' ? 'Recebido' : 'Pago'}</button>
                        {i.kind === 'RECEBER' && <button className="btn sm ghost" onClick={() => act(() => api.post(`/invoices/${i.id}/notify`), 'Notificação registada.', inv.reload)}>Notificar</button>}</div>}</td></tr>
                  ))}{!inv.data.length && <tr><td colSpan="9"><div className="empty"><strong>Sem facturas em aberto</strong></div></td></tr>}</tbody></table></div>
              )}</Loader>
            )}

            {tab === 'mov' && (
              <Loader state={movs}>{(rows) => (
                <div className="panel tablewrap"><table><thead><tr><th>Data</th><th>Descrição</th><th>Empresa</th><th>Categoria</th><th className="amt">Valor (AOA)</th></tr></thead><tbody>
                  {rows.map((m) => (<tr key={m.id}><td>{fdate(m.date)}</td><td>{m.description}</td><td>{m.company?.name || 'Grupo'}</td><td><Tag>{m.category}</Tag></td>
                    <td className={`amt num ${m.amount < 0 ? 'dn' : 'up'}`}>{m.amount.toLocaleString('pt-PT')}</td></tr>))}</tbody></table></div>
              )}</Loader>
            )}
          </>
        );
      }}</Loader>

      {form === 'mov' && (
        <FormModal title="Novo lançamento" onClose={() => setForm(null)}
          fields={[{ k: 'date', l: 'Data', type: 'date', req: true, def: new Date().toISOString().slice(0, 10) },
            { k: 'companyId', l: 'Empresa', type: 'select', num: true, nullable: true, opts: compOpts },
            { k: 'description', l: 'Descrição', req: true, wide: true }, { k: 'category', l: 'Categoria', type: 'select', req: true, opts: CATS, def: CATS[0] },
            { k: 'amount', l: 'Valor (AOA; negativo = saída)', type: 'number', req: true }]}
          onSubmit={(v) => save(() => api.post('/movements', v), 'Lançamento registado.', reload)} />
      )}
      {form === 'inv' && compOpts.length > 0 && (
        <FormModal title="Nova factura" onClose={() => setForm(null)}
          fields={[{ k: 'kind', l: 'Tipo', type: 'select', req: true, opts: [{ v: 'RECEBER', l: 'A receber' }, { v: 'PAGAR', l: 'A pagar' }], def: 'RECEBER' },
            { k: 'counterparty', l: 'Cliente / Fornecedor', req: true }, { k: 'docNumber', l: 'Documento', req: true, ph: 'FT 2026/0001' },
            { k: 'companyId', l: 'Empresa', type: 'select', req: true, num: true, opts: compOpts, def: compOpts[0].v },
            { k: 'dueDate', l: 'Vencimento', type: 'date', req: true }, { k: 'amount', l: 'Valor (AOA)', type: 'number', req: true, min: 0 }]}
          onSubmit={(v) => save(() => api.post('/invoices', v), 'Factura registada.', reload)} />
      )}
    </>
  );
}
