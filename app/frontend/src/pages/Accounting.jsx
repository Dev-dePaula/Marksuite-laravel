import React, { useState } from 'react';
import { A, Ac, Confirm, FormModal, KpiRow, Loader, Modal, PageHead, Tag, can, csv, fdate, useAction, useApi, useSave } from '../ui.jsx';
import { api } from '../api.js';

const CLASS_L = { ATIVO: 'Activo', PASSIVO: 'Passivo', PATRIMONIO: 'Património', RECEITA: 'Receita', DESPESA: 'Despesa' };
const TABS = [['res', 'Resumo'], ['lanc', 'Lançamentos'], ['razao', 'Razão'], ['plano', 'Plano de contas'], ['bal', 'Balancete'], ['dre', 'DRE'], ['bp', 'Balanço'], ['imp', 'Impostos']];
const today = () => new Date().toISOString().slice(0, 10);
const money = (n) => (n ?? 0).toLocaleString('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function Accounting({ user }) {
  const [tab, setTab] = useState('res');
  const [companyId, setCompanyId] = useState('');
  const [from, setFrom] = useState(`${new Date().getFullYear()}-01-01`);
  const [to, setTo] = useState(today());
  const [form, setForm] = useState(null);
  const [confirm, setConfirm] = useState(null);
  const comps = useApi('/companies');
  const act = useAction();
  const save = useSave();
  const w = can(user, 'accounting');
  const qc = companyId ? `companyId=${companyId}&` : '';
  const qp = `${qc}from=${from}&to=${to}`;
  const summary = useApi(`/accounting/summary?${qc}`);
  const entries = useApi(`/accounting/entries?${qp}`);
  const accounts = useApi(companyId ? `/accounting/accounts?companyId=${companyId}` : '/status');
  const balancete = useApi(tab === 'bal' ? `/accounting/balancete?${qp}` : '/status');
  const dre = useApi(tab === 'dre' ? `/accounting/dre?${qp}` : '/status');
  const bp = useApi(tab === 'bp' ? `/accounting/balanco?${qc}asOf=${to}` : '/status');
  const taxes = useApi(tab === 'imp' ? `/accounting/taxes?${qp}` : '/status');
  // Razão: contas da empresa escolhida (ou da primeira empresa, no consolidado) e movimento da conta seleccionada.
  const [acct, setAcct] = useState('1.1.2');
  const [detail, setDetail] = useState(null);
  const ledgerCompany = companyId || comps.data?.[0]?.id;
  const ledgerAccounts = useApi(tab === 'razao' && ledgerCompany ? `/accounting/accounts?companyId=${ledgerCompany}` : '/status', [tab, ledgerCompany]);
  const ledger = useApi(tab === 'razao' ? `/accounting/ledger?${qc}code=${encodeURIComponent(acct)}&from=${from}&to=${to}` : '/status', [tab, acct, qc, from, to]);
  const reload = () => { summary.reload(); entries.reload(); accounts.reload(); balancete.reload(); dre.reload(); bp.reload(); taxes.reload(); ledger.reload(); };
  const compOpts = (comps.data || []).map((c) => ({ v: c.id, l: c.name }));
  const analytic = (Array.isArray(accounts.data) ? accounts.data : []).filter((a) => !a.synthetic && a.active).map((a) => ({ v: a.code, l: `${a.code} — ${a.name}` }));

  const Section = ({ title, rows }) => (
    <><tr><td colSpan="3" style={{ background: 'var(--bg, #F4F6FA)', fontWeight: 700 }}>{title}</td></tr>
      {rows.map((r) => <tr key={r.code}><td>{r.code}</td><td>{r.name}</td><td className="amt">{money(r.total)}</td></tr>)}</>
  );

  return (
    <>
      <PageHead title="Contabilidade" sub="Plano de contas, lançamentos a partidas dobradas e demonstrações — alimentados automaticamente pela Faturação">
        {w && companyId && <button className="btn" onClick={() => setForm('entry')}>+ Lançamento manual</button>}
      </PageHead>

      <div className="panel" style={{ padding: 12, marginBottom: 14 }}>
        <div className="frow">
          <div className="field"><label htmlFor="ac_c">Empresa</label>
            <select id="ac_c" value={companyId} onChange={(e) => setCompanyId(e.target.value)}>
              <option value="">Consolidado (todas)</option>{compOpts.map((o) => <option key={o.v} value={o.v}>{o.l}</option>)}</select></div>
          <div className="field"><label htmlFor="ac_f">De</label><input id="ac_f" type="date" value={from} onChange={(e) => setFrom(e.target.value)} /></div>
          <div className="field"><label htmlFor="ac_t">Até</label><input id="ac_t" type="date" value={to} onChange={(e) => setTo(e.target.value)} /></div>
        </div>
      </div>

      <div className="tabs">{TABS.map(([k, l]) => <button key={k} className={tab === k ? 'active' : ''} onClick={() => setTab(k)}>{l}</button>)}</div>

      {tab === 'res' && <Loader state={summary}>{(s) => (
        <KpiRow items={[['Caixa e bancos', Ac(s.saldoCaixaBancos), '#0EA5E9', 'money'], ['Clientes (a receber)', Ac(s.totalAReceber), '#16A34A', 'money'],
          ['Fornecedores (a pagar)', Ac(s.totalAPagar), '#DC2626', 'money'], ['Resultado do mês', Ac(s.resultadoMes), s.resultadoMes < 0 ? '#DC2626' : '#7C3AED', 'chart']]} />
      )}</Loader>}

      {tab === 'lanc' && <Loader state={entries}>{(data) => (
        <div className="panel tablewrap"><table><thead><tr><th>Nº</th><th>Data</th><th>Descrição</th><th>Empresa</th><th>Origem</th><th>Contas</th><th className="amt">Valor</th><th /></tr></thead><tbody>
          {data.map((e) => (
            <tr key={e.id} style={{ cursor: 'pointer', ...(e.reversed ? { opacity: 0.55 } : {}) }} tabIndex={0} title="Ver lançamento" onClick={() => setDetail(e)}
              onKeyDown={(ev) => ev.key === 'Enter' && setDetail(e)}><td><strong>{e.docNumber}</strong></td><td>{fdate(e.entryDate)}</td><td>{e.description}</td><td>{e.company.name}</td>
              <td><Tag kind={e.source === 'manual' ? 'grey' : 'blue'}>{e.source === 'manual' ? 'Manual' : 'Automático'}</Tag>{e.reversed ? <Tag kind="red">Estornado</Tag> : null}</td>
              <td><small>{e.lines.map((l) => `${l.debit > 0 ? 'D' : 'C'} ${l.account.code}`).join(' · ')}</small></td>
              <td className="amt">{money(e.lines.reduce((a, l) => a + l.debit, 0))}</td>
              <td onClick={(ev) => ev.stopPropagation()} onKeyDown={(ev) => ev.stopPropagation()}>{w && !e.reversed && !e.reversedOfId && <button className="btn sm ghost" onClick={() => setConfirm({ msg: `Estornar ${e.docNumber}? Será criado um lançamento inverso.`, fn: () => act(() => api.post(`/accounting/entries/${e.id}/reverse`), 'Lançamento estornado.', reload) })}>Estornar</button>}</td></tr>
          ))}{!data.length && <tr><td colSpan="8"><div className="empty"><strong>Sem lançamentos no período</strong></div></td></tr>}</tbody></table>
          {data.length > 0 && <div style={{ padding: 10 }}><button className="btn sm ghost" onClick={() => csv('lancamentos.csv', ['Nº', 'Data', 'Descrição', 'Empresa', 'Origem', 'Valor'],
            data.map((e) => [e.docNumber, fdate(e.entryDate), e.description, e.company.name, e.source === 'manual' ? 'Manual' : 'Automático', e.lines.reduce((a, l) => a + l.debit, 0)]))}>Exportar CSV</button></div>}</div>
      )}</Loader>}

      {tab === 'razao' && (
        <>
          <div className="panel" style={{ padding: 12, marginBottom: 14 }}><div className="frow">
            <div className="field"><label htmlFor="rz_c">Conta</label>
              <select id="rz_c" value={acct} onChange={(e) => setAcct(e.target.value)}>
                {(Array.isArray(ledgerAccounts.data) ? ledgerAccounts.data : []).filter((a) => !a.synthetic).map((a) => <option key={a.code} value={a.code}>{a.code} — {a.name}</option>)}
                {!Array.isArray(ledgerAccounts.data) && <option value={acct}>{acct}</option>}</select></div>
          </div></div>
          <Loader state={ledger}>{(d) => (d.rows ? (
            <div className="panel tablewrap"><table><thead><tr><th>Lançamento</th><th>Data</th><th>Descrição</th><th className="amt">Débito</th><th className="amt">Crédito</th><th className="amt">Saldo</th></tr></thead><tbody>
              {d.rows.map((r, n) => <tr key={`${r.entryId}-${n}`}><td><strong>{r.docNumber}</strong></td><td>{fdate(r.date)}</td><td>{r.description}</td>
                <td className="amt">{r.debit ? money(r.debit) : ''}</td><td className="amt">{r.credit ? money(r.credit) : ''}</td><td className="amt">{money(r.balance)}</td></tr>)}
              {!d.rows.length && <tr><td colSpan="6"><div className="empty"><strong>Sem movimentos nesta conta no período</strong></div></td></tr>}
              <tr><td colSpan="5"><strong>Saldo final</strong></td><td className="amt"><strong>{money(d.balance)}</strong></td></tr></tbody></table>
              {d.rows.length > 0 && <div style={{ padding: 10 }}><button className="btn sm ghost" onClick={() => csv(`razao-${acct}.csv`, ['Lançamento', 'Data', 'Descrição', 'Débito', 'Crédito', 'Saldo'],
                d.rows.map((r) => [r.docNumber, fdate(r.date), r.description, r.debit, r.credit, r.balance]))}>Exportar CSV</button></div>}</div>
          ) : null)}</Loader>
        </>
      )}

      {tab === 'plano' && (!companyId ? <div className="panel"><div className="empty"><strong>Escolha uma empresa para ver o plano de contas.</strong></div></div> : (
        <Loader state={accounts}>{(data) => (
          <>
            {w && <div style={{ marginBottom: 10 }}><button className="btn ghost" onClick={() => setForm('account')}>+ Nova conta</button></div>}
            <div className="panel tablewrap"><table><thead><tr><th>Código</th><th>Nome</th><th>Classe</th><th>Tipo</th><th>Estado</th></tr></thead><tbody>
              {data.map((a) => (<tr key={a.id}><td style={{ paddingLeft: 8 + (a.code.split('.').length - 1) * 16 }}><strong>{a.code}</strong></td><td>{a.name}</td><td>{CLASS_L[a.class]}</td>
                <td>{a.synthetic ? 'Sintética' : 'Analítica'}</td><td><Tag kind={a.active ? 'ok' : 'grey'}>{a.active ? 'Activa' : 'Inactiva'}</Tag></td></tr>))}</tbody></table></div>
          </>
        )}</Loader>
      ))}

      {tab === 'bal' && <Loader state={balancete}>{(d) => (d.accounts ? (
        <div className="panel tablewrap"><table><thead><tr><th>Código</th><th>Conta</th><th className="amt">Débito</th><th className="amt">Crédito</th><th className="amt">Saldo</th></tr></thead><tbody>
          {d.accounts.map((r) => <tr key={r.code}><td>{r.code}</td><td>{r.name}</td><td className="amt">{money(r.debit)}</td><td className="amt">{money(r.credit)}</td><td className="amt">{money(r.balance)}</td></tr>)}
          <tr><td colSpan="2"><strong>Totais {d.balanced ? '' : '(DESEQUILIBRADO)'}</strong></td><td className="amt"><strong>{money(d.totalDebit)}</strong></td><td className="amt"><strong>{money(d.totalCredit)}</strong></td><td /></tr></tbody></table>
          <div style={{ padding: 10 }}><button className="btn sm ghost" onClick={() => csv('balancete.csv', ['Código', 'Conta', 'Débito', 'Crédito', 'Saldo'], d.accounts.map((r) => [r.code, r.name, r.debit, r.credit, r.balance]))}>Exportar CSV</button></div></div>
      ) : null)}</Loader>}

      {tab === 'dre' && <Loader state={dre}>{(d) => (d.receitas ? (
        <div className="panel tablewrap"><table><thead><tr><th>Código</th><th>Conta</th><th className="amt">Valor (AOA)</th></tr></thead><tbody>
          <Section title="Receitas" rows={d.receitas} /><tr><td colSpan="2"><strong>Total receitas</strong></td><td className="amt"><strong>{money(d.totalReceitas)}</strong></td></tr>
          <Section title="Despesas" rows={d.despesas} /><tr><td colSpan="2"><strong>Total despesas</strong></td><td className="amt"><strong>{money(d.totalDespesas)}</strong></td></tr>
          <tr><td colSpan="2"><strong>Resultado líquido</strong></td><td className={`amt ${d.resultadoLiquido < 0 ? 'dn' : 'up'}`}><strong>{money(d.resultadoLiquido)}</strong></td></tr></tbody></table>
          <div style={{ padding: 10 }}><button className="btn sm ghost" onClick={() => csv('dre.csv', ['Tipo', 'Código', 'Conta', 'Valor'],
            [...d.receitas.map((r) => ['Receita', r.code, r.name, r.total]), ...d.despesas.map((r) => ['Despesa', r.code, r.name, r.total]), ['Resultado', '', 'Resultado líquido', d.resultadoLiquido]])}>Exportar CSV</button></div></div>
      ) : null)}</Loader>}

      {tab === 'bp' && <Loader state={bp}>{(d) => (d.ativo ? (
        <div className="panel tablewrap"><table><thead><tr><th>Código</th><th>Conta</th><th className="amt">Valor (AOA) a {fdate(d.asOf)}</th></tr></thead><tbody>
          <Section title="Activo" rows={d.ativo} /><tr><td colSpan="2"><strong>Total activo</strong></td><td className="amt"><strong>{money(d.totalAtivo)}</strong></td></tr>
          <Section title="Passivo" rows={d.passivo} /><tr><td colSpan="2"><strong>Total passivo</strong></td><td className="amt"><strong>{money(d.totalPassivo)}</strong></td></tr>
          <Section title="Património líquido" rows={d.patrimonio} /><tr><td colSpan="2"><strong>Total património</strong></td><td className="amt"><strong>{money(d.totalPatrimonio)}</strong></td></tr>
          <tr><td colSpan="3"><Tag kind={d.balanceado ? 'ok' : 'red'}>{d.balanceado ? 'Activo = Passivo + Património' : 'Balanço desequilibrado'}</Tag></td></tr></tbody></table>
          <div style={{ padding: 10 }}><button className="btn sm ghost" onClick={() => csv('balanco.csv', ['Grupo', 'Código', 'Conta', 'Valor'],
            [...d.ativo.map((r) => ['Activo', r.code, r.name, r.total]), ...d.passivo.map((r) => ['Passivo', r.code, r.name, r.total]), ...d.patrimonio.map((r) => ['Património', r.code, r.name, r.total])])}>Exportar CSV</button></div></div>
      ) : null)}</Loader>}

      {tab === 'imp' && <Loader state={taxes}>{(d) => (d.iva ? (
        <>
          <div className="grid g2">
            <div className="panel"><div className="hd"><h3>IVA ({d.rates.ivaRate}%)</h3></div><div className="bd"><table><tbody>
              <tr><td>IVA liquidado (vendas)</td><td className="amt">{money(d.iva.liquidado)}</td></tr>
              <tr><td>IVA dedutível (compras)</td><td className="amt">− {money(d.iva.dedutivel)}</td></tr>
              <tr><td><strong>{d.iva.apagar >= 0 ? 'IVA a entregar ao Estado' : 'IVA a recuperar'}</strong></td><td className="amt"><strong>{money(Math.abs(d.iva.apagar))}</strong></td></tr></tbody></table></div></div>
            <div className="panel"><div className="hd"><h3>Imposto Industrial ({d.impostoIndustrial.taxa}%) — estimativa</h3></div><div className="bd"><table><tbody>
              <tr><td>Resultado antes de imposto</td><td className="amt">{money(d.impostoIndustrial.resultadoAntesImposto)}</td></tr>
              <tr><td><strong>Imposto estimado</strong></td><td className="amt"><strong>{money(d.impostoIndustrial.estimado)}</strong></td></tr>
              <tr><td>Já lançado em contas</td><td className="amt">{money(d.impostoIndustrial.jaLancado)}</td></tr></tbody></table></div></div>
          </div>
          <p style={{ color: 'var(--muted)', marginTop: 10 }}>{d.aviso}</p>
          {w && <div style={{ marginTop: 10 }}><button className="btn ghost" onClick={() => setForm('rates')}>Alterar taxas</button></div>}
        </>
      ) : null)}</Loader>}

      {form === 'entry' && (
        <FormModal title="Lançamento manual" onClose={() => setForm(null)}
          fields={[{ k: 'entryDate', l: 'Data', type: 'date', req: true, def: today() }, { k: 'amount', l: 'Valor (AOA)', type: 'number', req: true, min: 0 },
            { k: 'debit', l: 'Conta a débito', type: 'select', req: true, opts: analytic, def: analytic[0]?.v }, { k: 'credit', l: 'Conta a crédito', type: 'select', req: true, opts: analytic, def: analytic[1]?.v },
            { k: 'description', l: 'Descrição', req: true, wide: true }]}
          onSubmit={(v) => save(() => api.post('/accounting/entries', { companyId: Number(companyId), entryDate: v.entryDate, description: v.description,
            lines: [{ code: v.debit, debit: v.amount }, { code: v.credit, credit: v.amount }] }), 'Lançamento registado.', reload)} />
      )}
      {form === 'account' && (
        <FormModal title="Nova conta" onClose={() => setForm(null)}
          fields={[{ k: 'code', l: 'Código', req: true, ph: '1.1.5' }, { k: 'name', l: 'Nome', req: true }, { k: 'class', l: 'Classe', type: 'select', req: true, opts: Object.entries(CLASS_L).map(([v, l]) => ({ v, l })), def: 'ATIVO' }]}
          onSubmit={(v) => save(() => api.post('/accounting/accounts', { ...v, companyId: Number(companyId) }), 'Conta criada.', reload)} />
      )}
      {form === 'rates' && (
        <FormModal title="Taxas de imposto" onClose={() => setForm(null)}
          fields={[{ k: 'ivaRate', l: 'IVA (%)', type: 'number', req: true, min: 0, max: 100, def: taxes.data?.rates.ivaRate }, { k: 'indRate', l: 'Imposto Industrial (%)', type: 'number', req: true, min: 0, max: 100, def: taxes.data?.rates.indRate }]}
          onSubmit={(v) => save(() => api.put('/accounting/tax-settings', v), 'Taxas actualizadas.', reload)} />
      )}
      {detail && (
        <Modal title={`Lançamento ${detail.docNumber}`} onClose={() => setDetail(null)}
          footer={<button className="btn ghost" onClick={() => setDetail(null)}>Fechar</button>}>
          <p style={{ marginBottom: 10 }}>{detail.description}</p>
          <p style={{ color: 'var(--muted)', marginBottom: 10 }}>{detail.company.name} · {fdate(detail.entryDate)} · {detail.source === 'manual' ? 'Manual' : 'Automático'}{detail.createdBy ? ` · ${detail.createdBy}` : ''}</p>
          <div className="tablewrap"><table><thead><tr><th>Conta</th><th className="amt">Débito</th><th className="amt">Crédito</th></tr></thead><tbody>
            {detail.lines.map((l) => <tr key={l.id}><td>{l.account.code} — {l.account.name}</td><td className="amt">{l.debit ? money(l.debit) : ''}</td><td className="amt">{l.credit ? money(l.credit) : ''}</td></tr>)}
            <tr><td><strong>Totais</strong></td><td className="amt"><strong>{money(detail.lines.reduce((a, l) => a + l.debit, 0))}</strong></td><td className="amt"><strong>{money(detail.lines.reduce((a, l) => a + l.credit, 0))}</strong></td></tr></tbody></table></div>
        </Modal>
      )}
      {confirm && <Confirm message={confirm.msg} onOk={confirm.fn} onClose={() => setConfirm(null)} />}
    </>
  );
}
