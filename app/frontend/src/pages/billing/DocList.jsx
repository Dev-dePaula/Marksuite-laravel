import React, { useMemo, useState } from 'react';
import { Confirm, Loader, Tag, csv, useAction, useApi } from '../../ui.jsx';
import { api } from '../../api.js';
import { MODS, STATE_TAG, STATE_TXT, TYPES, brDate, money } from './lib.js';

/** Lista de um módulo (facturas, pró-formas, guias, encomendas): pesquisa, filtros, selecção em lote e sumário. */
export default function DocList({ tab, canWrite, onNew, onOpen, refreshKey }) {
  const M = MODS[tab]; const single = M.types.length === 1;
  const [f, setF] = useState({ q: '', type: '', state: '', contactId: '', from: '', to: '', arch: false });
  const [applied, setApplied] = useState(f);
  const [adv, setAdv] = useState(false);
  const [sel, setSel] = useState(new Set());
  const [confirm, setConfirm] = useState(null);
  const act = useAction();
  const qs = useMemo(() => { const p = new URLSearchParams({ mod: M.mod }); Object.entries(applied).forEach(([k, v]) => { if (v) p.set(k, v === true ? '1' : v); }); return p.toString(); }, [applied, M.mod]);
  const list = useApi(`/documents?${qs}`, [refreshKey]);
  const contacts = useApi('/contacts');
  const set = (k, v) => setF((s) => ({ ...s, [k]: v }));
  const apply = (e) => { e?.preventDefault(); setApplied(f); setSel(new Set()); };
  const toggle = (id) => setSel((s) => { const n = new Set(s); n.has(id) ? n.delete(id) : n.add(id); return n; });

  const bulk = (op) => {
    const ids = [...sel]; if (!ids.length) return;
    const run = () => act(() => api.post('/documents/bulk', { op, ids }), op === 'del' ? 'Rascunhos apagados.' : op === 'arch' ? 'Arquivado.' : 'Desarquivado.', () => { setSel(new Set()); list.reload(); });
    if (op === 'del') setConfirm({ msg: `Apagar os rascunhos seleccionados? Documentos emitidos não são apagados.`, fn: run }); else run();
  };

  const summary = (rows) => {
    const s = { R: 0, C: 0, D: 0, V: 0, P: 0, net: 0, tax: 0, total: 0 };
    rows.forEach((d) => {
      if (d.state === 'R') { s.R += +d.grossTotal || +d.amount; return; }
      if (d.state === 'C') s.C += +d.grossTotal || +d.amount;
      if (d.state === 'D') s.D += d.outstanding; if (d.state === 'V') s.V += d.outstanding;
      s.P += +d.paidAmount; s.net += d.sg * +d.subtotal; s.tax += d.sg * +d.ivaAmount; s.total += d.sg * (+d.grossTotal || +d.amount);
    });
    return s;
  };
  const nSt = (rows, k) => rows.filter((d) => d.state === k).length;

  return (
    <Loader state={list}>{(rows) => {
      const s = summary(rows);
      return (<>
        <div className="panel" style={{ marginBottom: 14 }}>
          <form onSubmit={apply} style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            <label className="sr" htmlFor="f_q" style={{ position: 'absolute', left: -9999 }}>Pesquisa</label>
            <input id="f_q" style={{ flex: 1, minWidth: 220 }} placeholder="Pesquise por número, cliente, descrição ou observações" value={f.q} onChange={(e) => set('q', e.target.value)} />
            <button type="button" className="btn ghost" onClick={() => setAdv(!adv)}>Filtros {adv ? '▲' : '▼'}</button>
            <button className="btn">Pesquisar</button>
          </form>
          {adv && (
            <div className="frow" style={{ marginTop: 12 }}>
              {!single && <div className="field"><label htmlFor="f_t">Tipo</label><select id="f_t" value={f.type} onChange={(e) => set('type', e.target.value)}><option value="">Todos</option>{M.types.map((t) => <option key={t} value={t}>{TYPES[t].n}</option>)}</select></div>}
              <div className="field"><label htmlFor="f_s">Estado</label><select id="f_s" value={f.state} onChange={(e) => set('state', e.target.value)}><option value="">Todos</option>{Object.entries(STATE_TXT).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select></div>
              <div className="field"><label htmlFor="f_c">Cliente</label><select id="f_c" value={f.contactId} onChange={(e) => set('contactId', e.target.value)}><option value="">Todos</option>{(contacts.data || []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></div>
              <div className="field"><label htmlFor="f_d">Data de</label><input id="f_d" type="date" value={f.from} onChange={(e) => set('from', e.target.value)} /></div>
              <div className="field"><label htmlFor="f_a">Data até</label><input id="f_a" type="date" value={f.to} onChange={(e) => set('to', e.target.value)} /></div>
              <label style={{ display: 'flex', gap: 6, alignItems: 'center', alignSelf: 'end' }}><input type="checkbox" checked={f.arch} onChange={(e) => set('arch', e.target.checked)} /> Incluir arquivados</label>
            </div>)}
        </div>

        <div className="grid g4" style={{ marginBottom: 14 }}>
          {(tab === 'fat' ? [['Rascunhos', money(s.R)], ['Dentro do prazo', money(s.D)], ['Vencido', money(s.V)], ['Total facturado', money(s.total)]]
            : [['Rascunhos', nSt(rows, 'R')], ['Emitidos', nSt(rows, 'E')], ['Convertidos', nSt(rows, 'F')], ['Valor em aberto', money(rows.filter((d) => ['E'].includes(d.state)).reduce((a, d) => a + +d.amount, 0))]]).map(([l, v]) => (
            <div className="panel" key={l}><small style={{ color: 'var(--muted)' }}>{l}</small><div className="num" style={{ fontSize: 18, fontWeight: 700 }}>{v}</div></div>))}
        </div>

        <div className="toolbar" style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 10 }}>
          {canWrite && M.btns.map(([t, l, pri]) => <button key={t} className={`btn ${pri ? '' : 'ghost'}`} onClick={() => onNew(t)}>{l}</button>)}
          <div style={{ flex: 1 }} />
          <button className="btn ghost sm" onClick={() => setSel(new Set(rows.map((r) => r.id)))}>Seleccionar todos</button>
          <button className="btn ghost sm" onClick={() => setSel(new Set())}>Limpar</button>
          {canWrite && <><button className="btn ghost sm" onClick={() => bulk('arch')}>Arquivar</button><button className="btn ghost sm" onClick={() => bulk('unarch')}>Desarquivar</button><button className="btn ghost sm" onClick={() => bulk('del')}>Apagar rascunhos</button></>}
          <button className="btn ghost sm" onClick={() => csv(`${M.mod}.csv`, ['Documento', 'Número', 'Cliente', 'Data', 'Vence', 'Estado', 'S/IVA', 'Total'], rows.map((d) => [d.typeName, d.docNumber, d.counterparty, brDate(d.issueDate), brDate(d.dueDate), STATE_TXT[d.state], d.sg * +d.subtotal, d.sg * (+d.grossTotal || +d.amount)]))}>Exportar CSV</button>
        </div>

        <div className="panel tablewrap">
          <table><thead><tr><th /><th>Est.</th><th>Documento</th><th>Número</th><th>Cliente</th><th>Data</th><th>Vence</th><th className="amt">S/IVA</th><th className="amt">Total</th><th className="amt">Em aberto</th></tr></thead><tbody>
            {rows.map((d) => (
              <tr key={d.id}>
                <td><input type="checkbox" aria-label={`Seleccionar ${d.docNumber}`} checked={sel.has(d.id)} onChange={() => toggle(d.id)} /></td>
                <td><Tag kind={STATE_TAG[d.state]}>{d.state}</Tag>{d.archived && <small style={{ color: 'var(--muted)' }}> arq.</small>}</td>
                <td>{d.typeName}</td>
                <td><button className="link" style={{ background: 'none', border: 0, color: 'var(--accent, #2563EB)', fontWeight: 600, cursor: 'pointer', padding: 0 }} onClick={() => onOpen(d.id)}>{d.draft ? 'Rascunho' : d.docNumber}</button></td>
                <td>{d.counterparty}{d.company?.name && <small style={{ display: 'block', color: 'var(--muted)' }}>{d.company.name}</small>}</td>
                <td>{brDate(d.issueDate)}</td><td>{d.docType === 'GT' ? '—' : brDate(d.dueDate)}</td>
                <td className="amt">{money(d.sg * +d.subtotal)}</td><td className="amt"><strong>{money(d.sg * (+d.grossTotal || +d.amount))}</strong></td><td className="amt">{d.outstanding ? money(d.outstanding) : '—'}{d.late?.total > 0 && <small style={{ display: 'block', color: '#B91C1C' }}>+ {money(d.late.total)} encargos</small>}</td>
              </tr>))}
            {!rows.length && <tr><td colSpan="10"><div className="empty"><strong>Nenhum documento encontrado</strong></div></td></tr>}
          </tbody></table>
        </div>
        {confirm && <Confirm message={confirm.msg} onOk={confirm.fn} onClose={() => setConfirm(null)} />}
      </>);
    }}</Loader>
  );
}
