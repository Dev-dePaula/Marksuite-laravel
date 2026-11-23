import React, { useState } from 'react';
import { A, Ac, Confirm, FormModal, KpiRow, Loader, PageHead, Tag, can, fdate, useAction, useApi, useSave } from '../ui.jsx';
import { api } from '../api.js';
import InvoiceDoc from './InvoiceDoc.jsx';
import DocList from './billing/DocList.jsx';
import DocEditor from './billing/DocEditor.jsx';
import { Contacts, Items } from './billing/Catalog.jsx';
import { Compliance, Vat } from './billing/Fiscal.jsx';
import Config from './billing/Config.jsx';
import { METHODS, MODS } from './billing/lib.js';

const today = () => new Date().toISOString().slice(0, 10);
const EXP_TAG = { PENDENTE: 'amb', APROVADA: 'blue', REJEITADA: 'red', PAGA: 'ok' };
const EXP_L = { PENDENTE: 'Pendente', APROVADA: 'Aprovada', REJEITADA: 'Rejeitada', PAGA: 'Paga' };
const TABS = [['fat', 'Facturas'], ['pf', 'Pró-formas'], ['gt', 'Guias'], ['en', 'Encomendas'], ['rec', 'Recibos'], ['pag', 'A pagar'], ['exp', 'Despesas'], ['sup', 'Fornecedores'], ['con', 'Contactos'], ['ite', 'Itens'], ['iva', 'IVA'], ['conf', 'Conformidade AGT'], ['cfg', 'Configuração']];

/** Pré-preenchimento ao duplicar um documento. */
const dupInitial = (x) => ({
  companyId: x.companyId, contactId: x.contactId || null, customerName: x.counterparty === '—' ? '' : x.counterparty, vref: x.vref || '', place: x.place || '', notes: x.notes || '', terms: x.terms || '45 dias',
  gdiscPct: String(+x.gdiscPct || ''), retPct: String(+x.retPct || ''), transport: x.transport || undefined, delivery: x.delivery || undefined,
  lines: x.lines.map((l) => ({ code: l.code || '', description: l.description, qty: String(+l.qty), price: String(+l.price), discPct: String(+l.discPct || 0), ivaRate: String(+l.ivaRate), itemId: l.itemId || null, newItem: false })),
});

export default function Billing({ user }) {
  const [tab, setTab] = useState('fat');
  const [view, setView] = useState(null); // { k:'edit', type?, docId?, initial? } | { k:'doc', id }
  const [rk, setRk] = useState(0); // força o refresh das listas
  const [form, setForm] = useState(null);
  const [confirm, setConfirm] = useState(null);
  const comps = useApi('/companies');
  const inv = useApi('/invoices');
  const exps = useApi('/expenses');
  const sups = useApi('/suppliers');
  const recs = useApi('/receipts');
  const tax = useApi('/accounting/taxes');
  const act = useAction();
  const save = useSave();
  const w = can(user, 'billing');
  const approver = can(user, 'approveExpense');
  const companies = comps.data || [];
  const reload = () => { inv.reload(); exps.reload(); sups.reload(); recs.reload(); tax.reload(); setRk((n) => n + 1); };
  const compOpts = companies.map((c) => ({ v: c.id, l: c.name }));
  const ready = compOpts.length > 0;
  const supOpts = (sups.data || []).filter((s) => s.active).map((s) => ({ v: s.id, l: s.name }));
  const ivaDefault = tax.data?.rates?.ivaRate ?? 14;

  const rows = inv.data || [];
  const open = rows.filter((i) => !i.paidAt);
  const receber = open.filter((i) => i.kind === 'RECEBER').reduce((a, i) => a + i.amount - i.paidAmount, 0);
  const pagar = open.filter((i) => i.kind === 'PAGAR').reduce((a, i) => a + i.amount - i.paidAmount, 0);
  const newDoc = (type) => setView({ k: 'edit', type, key: Date.now() });

  if (view?.k === 'edit') {
    return (
      <>
        <PageHead title="Faturação" sub="Editor de documento" />
        <DocEditor key={view.key || view.docId} type={view.type} docId={view.docId} initial={view.initial}
          onCancel={() => setView(view.docId ? { k: 'doc', id: view.docId } : null)} onDone={(id) => { reload(); setView({ k: 'doc', id }); }} />
      </>
    );
  }

  return (
    <>
      <PageHead title="Faturação" sub="Documentos fiscais, recibos, despesas e fornecedores — cada operação gera o lançamento contabilístico, o IVA e avisa o cliente por WhatsApp e email">
        {w && ready && <button className="btn" onClick={() => newDoc('FT')}>+ Nova factura</button>}
      </PageHead>

      <KpiRow items={[['A receber (em aberto)', Ac(receber), '#16A34A', 'money'], ['A pagar (em aberto)', Ac(pagar), '#DC2626', 'money'],
        ['IVA a entregar', Ac(tax.data?.iva.apagar), '#7C3AED', 'money'], ['Despesas pendentes', String((exps.data || []).filter((e) => e.status === 'PENDENTE').length), '#F59E0B', 'chart']]} />

      <div className="tabs" style={{ flexWrap: 'wrap' }}>{TABS.map(([k, l]) => (<button key={k} className={tab === k ? 'active' : ''} onClick={() => setTab(k)}>{l}</button>))}</div>

      {MODS[tab] && <DocList key={tab} tab={tab} canWrite={w && ready} refreshKey={rk} onNew={newDoc} onOpen={(id) => setView({ k: 'doc', id })} />}
      {tab === 'con' && <Contacts canWrite={w && ready} companies={companies} />}
      {tab === 'ite' && <Items canWrite={w && ready} companies={companies} />}
      {tab === 'iva' && <Vat companies={companies} />}
      {tab === 'conf' && <Compliance canWrite={w} companies={companies} onGoto={setTab} />}
      {tab === 'cfg' && <Config companies={companies} canWrite={w} reloadCompanies={comps.reload} />}

      {tab === 'rec' && (
        <Loader state={recs}>{(data) => (
          <div className="panel tablewrap"><table><thead><tr><th>Recibo</th><th>Factura</th><th>Parte</th><th>Data</th><th>Método</th><th className="amt">Valor</th></tr></thead><tbody>
            {data.map((r) => (<tr key={r.id}><td><strong>{r.docNumber}</strong></td><td>{r.invoice.docNumber}</td><td>{r.invoice.counterparty}</td><td>{fdate(r.paymentDate)}</td><td>{r.paymentMethod}</td><td className="amt">{A(r.amount)}</td></tr>))}
            {!data.length && <tr><td colSpan="6"><div className="empty"><strong>Sem recibos</strong></div></td></tr>}</tbody></table></div>
        )}</Loader>
      )}

      {tab === 'pag' && (
        <Loader state={inv}>{(data) => (<>
          {w && ready && <div style={{ marginBottom: 10 }}><button className="btn" onClick={() => setForm({ type: 'inv' })}>+ Factura de fornecedor</button></div>}
          <div className="panel tablewrap"><table><thead><tr><th>Documento</th><th>Fornecedor</th><th>Empresa</th><th>Emissão</th><th>Vencimento</th><th className="amt">Subtotal</th><th className="amt">IVA</th><th className="amt">Total</th><th className="amt">Pago</th><th>Estado</th><th /></tr></thead><tbody>
            {data.filter((i) => i.kind === 'PAGAR').map((i) => (
              <tr key={i.id}><td><strong>{i.docNumber}</strong></td><td>{i.counterparty}</td><td>{i.company.name}</td><td>{fdate(i.issueDate)}</td><td>{fdate(i.dueDate)}</td>
                <td className="amt">{A(i.subtotal ?? i.amount)}</td><td className="amt">{A(i.ivaAmount)}</td><td className="amt"><strong>{A(i.amount)}</strong></td><td className="amt">{A(i.paidAmount)}</td><td><Tag>{i.status}</Tag></td>
                <td>{w && !i.paidAt && <div className="actions"><button className="btn sm ok" onClick={() => setForm({ type: 'pay', row: i })}>Pagar</button>
                  {!(i.paidAmount > 0) && <button className="btn sm ghost" onClick={() => setConfirm({ msg: `Anular a factura ${i.docNumber}? O lançamento contabilístico será estornado.`, fn: () => act(() => api.post(`/invoices/${i.id}/cancel`), 'Factura anulada.', reload) })}>Anular</button>}</div>}</td></tr>))}
            {!data.some((i) => i.kind === 'PAGAR') && <tr><td colSpan="11"><div className="empty"><strong>Sem facturas de fornecedores</strong></div></td></tr>}</tbody></table></div>
        </>)}</Loader>
      )}

      {tab === 'exp' && (
        <Loader state={exps}>{(data) => (<>
          {w && ready && <div style={{ marginBottom: 10 }}><button className="btn" onClick={() => setForm({ type: 'exp' })}>+ Despesa</button></div>}
          <div className="panel tablewrap"><table><thead><tr><th>Descrição</th><th>Fornecedor</th><th>Empresa</th><th>Pedido por</th><th>Vencimento</th><th className="amt">Total (c/ IVA)</th><th>Estado</th><th /></tr></thead><tbody>
            {data.map((e) => (
              <tr key={e.id}><td><strong>{e.description}</strong>{e.approvalNote ? <small style={{ display: 'block', color: 'var(--muted)' }}>{e.approvalNote}</small> : null}</td>
                <td>{e.supplier?.name || '—'}</td><td>{e.company.name}</td><td>{e.requestedBy}</td><td>{fdate(e.dueDate)}</td><td className="amt">{A(e.amount)}</td>
                <td><Tag kind={EXP_TAG[e.status]}>{EXP_L[e.status]}</Tag></td>
                <td><div className="actions">
                  {e.status === 'PENDENTE' && approver && <>
                    <button className="btn sm ok" onClick={() => act(() => api.patch(`/expenses/${e.id}/approve`, {}), 'Despesa aprovada e lançada.', reload)}>Aprovar</button>
                    <button className="btn sm ghost" onClick={() => setForm({ type: 'reject', row: e })}>Rejeitar</button></>}
                  {e.status === 'APROVADA' && w && <button className="btn sm ok" onClick={() => setForm({ type: 'exppay', row: e })}>Pagar</button>}
                  {['PENDENTE', 'REJEITADA'].includes(e.status) && w && <button className="btn sm ghost" onClick={() => setConfirm({ msg: 'Apagar esta despesa?', fn: () => act(() => api.del(`/expenses/${e.id}`), 'Despesa apagada.', reload) })}>Apagar</button>}
                </div></td></tr>
            ))}{!data.length && <tr><td colSpan="8"><div className="empty"><strong>Sem despesas</strong></div></td></tr>}</tbody></table></div>
        </>)}</Loader>
      )}

      {tab === 'sup' && (
        <Loader state={sups}>{(data) => (<>
          {w && ready && <div style={{ marginBottom: 10 }}><button className="btn" onClick={() => setForm({ type: 'sup' })}>+ Fornecedor</button></div>}
          <div className="panel tablewrap"><table><thead><tr><th>Nome</th><th>NIF</th><th>Categoria</th><th>Contacto</th><th>Estado</th><th /></tr></thead><tbody>
            {data.map((s) => (
              <tr key={s.id}><td><strong>{s.name}</strong></td><td>{s.nif || '—'}</td><td>{s.category || '—'}</td><td>{[s.email, s.phone].filter(Boolean).join(' · ') || '—'}</td>
                <td><Tag kind={s.active ? 'ok' : 'grey'}>{s.active ? 'Activo' : 'Inactivo'}</Tag></td>
                <td>{w && <div className="actions"><button className="btn sm ghost" onClick={() => act(() => api.put(`/suppliers/${s.id}`, { active: !s.active }), 'Fornecedor actualizado.', sups.reload)}>{s.active ? 'Desactivar' : 'Activar'}</button></div>}</td></tr>
            ))}{!data.length && <tr><td colSpan="6"><div className="empty"><strong>Sem fornecedores</strong></div></td></tr>}</tbody></table></div>
        </>)}</Loader>
      )}

      {view?.k === 'doc' && (
        <InvoiceDoc id={view.id} canWrite={w} onClose={() => setView(null)} onChanged={reload}
          onEdit={(id) => setView({ k: 'edit', docId: id })} onOpen={(id) => setView({ k: 'doc', id })}
          onNew={(x) => setView({ k: 'edit', type: x.docType, initial: dupInitial(x), key: Date.now() })} />
      )}
      {form?.type === 'inv' && (
        <FormModal title="Nova factura de fornecedor" onClose={() => setForm(null)}
          fields={[{ k: 'kind', l: 'Tipo', type: 'select', req: true, opts: [{ v: 'PAGAR', l: 'A pagar (fornecedor)' }], def: 'PAGAR' },
            { k: 'companyId', l: 'Empresa', type: 'select', req: true, num: true, opts: compOpts, def: compOpts[0].v },
            { k: 'counterparty', l: 'Fornecedor', req: true }, { k: 'clientNif', l: 'NIF' },
            { k: 'docNumber', l: 'Nº documento (vazio = automático)', ph: 'FC 2026/0001' }, { k: 'issueDate', l: 'Emissão', type: 'date', req: true, def: today() },
            { k: 'dueDate', l: 'Vencimento', type: 'date', req: true }, { k: 'subtotal', l: 'Subtotal sem IVA (AOA)', type: 'number', req: true, min: 0 },
            { k: 'ivaRate', l: 'Taxa de IVA (%)', type: 'number', req: true, min: 0, max: 100, def: ivaDefault },
            { k: 'description', l: 'Descrição', type: 'textarea', wide: true }]}
          onSubmit={(v) => save(() => api.post('/invoices', v), 'Factura registada e contabilizada.', reload)} />
      )}
      {form?.type === 'pay' && (
        <FormModal title={`${form.row.kind === 'RECEBER' ? 'Receber' : 'Pagar'} — ${form.row.docNumber}`} onClose={() => setForm(null)} okLabel="Registar"
          fields={[{ k: 'amount', l: `Valor (em aberto: ${A(form.row.amount - form.row.paidAmount)})`, type: 'number', req: true, min: 0, def: +(form.row.amount - form.row.paidAmount).toFixed(2) },
            { k: 'paymentDate', l: 'Data', type: 'date', req: true, def: today() }, { k: 'paymentMethod', l: 'Método', type: 'select', req: true, opts: METHODS, def: 'transferencia' }]}
          onSubmit={(v) => save(() => api.post(`/invoices/${form.row.id}/receipts`, v), 'Pagamento registado e contabilizado.', reload)} />
      )}
      {form?.type === 'exp' && (
        <FormModal title="Nova despesa" onClose={() => setForm(null)}
          fields={[{ k: 'companyId', l: 'Empresa', type: 'select', req: true, num: true, opts: compOpts, def: compOpts[0].v },
            { k: 'supplierId', l: 'Fornecedor', type: 'select', num: true, nullable: true, opts: supOpts },
            { k: 'description', l: 'Descrição', req: true, wide: true }, { k: 'category', l: 'Categoria' }, { k: 'dueDate', l: 'Vencimento', type: 'date' },
            { k: 'subtotal', l: 'Subtotal sem IVA (AOA)', type: 'number', req: true, min: 0 }, { k: 'ivaRate', l: 'Taxa de IVA (%)', type: 'number', req: true, min: 0, max: 100, def: ivaDefault }]}
          onSubmit={(v) => save(() => api.post('/expenses', v), 'Despesa submetida para aprovação.', reload)} />
      )}
      {form?.type === 'sup' && (
        <FormModal title="Novo fornecedor" onClose={() => setForm(null)}
          fields={[{ k: 'companyId', l: 'Empresa', type: 'select', req: true, num: true, opts: compOpts, def: compOpts[0].v }, { k: 'name', l: 'Nome', req: true }, { k: 'nif', l: 'NIF' },
            { k: 'category', l: 'Categoria' }, { k: 'email', l: 'Email', type: 'email' }, { k: 'phone', l: 'Telefone' }, { k: 'address', l: 'Morada', type: 'textarea', wide: true }]}
          onSubmit={(v) => save(() => api.post('/suppliers', v), 'Fornecedor registado.', reload)} />
      )}
      {form?.type === 'reject' && (
        <FormModal title="Rejeitar despesa" onClose={() => setForm(null)} okLabel="Rejeitar"
          fields={[{ k: 'note', l: 'Motivo', type: 'textarea', req: true, wide: true }]}
          onSubmit={(v) => save(() => api.patch(`/expenses/${form.row.id}/reject`, v), 'Despesa rejeitada.', reload)} />
      )}
      {form?.type === 'exppay' && (
        <FormModal title={`Pagar despesa — ${form.row.description}`} onClose={() => setForm(null)} okLabel="Pagar"
          fields={[{ k: 'paymentMethod', l: 'Método', type: 'select', req: true, opts: METHODS, def: 'transferencia' }]}
          onSubmit={(v) => save(() => api.patch(`/expenses/${form.row.id}/pay`, v), 'Pagamento registado e contabilizado.', reload)} />
      )}
      {confirm && <Confirm message={confirm.msg} onOk={confirm.fn} onClose={() => setConfirm(null)} />}
    </>
  );
}
