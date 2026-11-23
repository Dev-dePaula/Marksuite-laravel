import React, { useMemo, useState } from 'react';
import { Confirm, Loader, useApi, useToast } from '../../ui.jsx';
import { api } from '../../api.js';
import { TYPES, TERMS, calcPreview, dueOf, num, today, workDays, money, brDate } from './lib.js';

const RATES = [[14, 'IVA 14% (taxa geral)'], [7, 'IVA 7%'], [5, 'IVA 5% (bens essenciais)'], [1, 'IVA 1% (Cabinda)'], [0, 'Isento']];
const blank = () => ({ code: '', description: '', qty: '1', price: '', discPct: '0', ivaRate: '14', itemId: null, newItem: false });
const NAMES = { FT: 'factura', FR: 'factura-recibo', FA: 'factura de adiantamento', ND: 'nota de débito', AF: 'autofacturação', FG: 'factura global', PF: 'pró-forma', GT: 'guia de transporte', EN: 'nota de encomenda' };

// Fora de Form: um componente definido dentro seria recriado a cada tecla e os campos perderiam o foco.
const F = ({ id, label, req, children, wide }) => (<div className="field" style={wide ? { gridColumn: '1/-1' } : undefined}><label htmlFor={id}>{label}{req && <span style={{ color: '#DC2626' }}> *</span>}</label>{children}</div>);

function fromDoc(d) {
  return {
    id: d.id, docType: d.docType, companyId: d.companyId, contactId: d.contactId || null, customerName: d.counterparty === '—' ? '' : d.counterparty, newClient: { name: '', nif: '', email: '', phone: '', whatsapp: '', address: '' },
    issueDate: String(d.issueDate).slice(0, 10), terms: d.terms || '45 dias', serie: d.serie || '', vref: d.vref || '', place: d.place || '', notes: d.notes || '', gdiscPct: String(+d.gdiscPct || ''), retPct: String(+d.retPct || ''),
    transport: { plate: '', loadDate: today(), from: {}, to: {}, ...(d.transport || {}) }, delivery: { date: today(), place: '', ...(d.delivery || {}) }, originId: d.originId || null,
    lines: d.lines.length ? d.lines.map((l) => ({ code: l.code || '', description: l.description, qty: String(+l.qty), price: String(+l.price), discPct: String(+l.discPct || 0), ivaRate: String(+l.ivaRate), itemId: l.itemId || null, newItem: false })) : [blank()],
  };
}

export default function DocEditor({ type, docId, initial, onDone, onCancel }) {
  const doc = useApi(docId ? `/documents/${docId}` : '/status');
  if (docId) return <Loader state={doc}>{(d) => <Form key={d.id} init={fromDoc(d)} onDone={onDone} onCancel={onCancel} />}</Loader>;
  return <Form init={{
    id: null, docType: type, companyId: '', contactId: null, customerName: '', newClient: { name: '', nif: '', email: '', phone: '', whatsapp: '', address: '' }, issueDate: today(), terms: type === 'PF' ? 'Pronto pagamento' : '45 dias',
    serie: String(new Date().getFullYear()), vref: '', place: '', notes: '', gdiscPct: '', retPct: '', transport: { plate: '', loadDate: today(), from: {}, to: {} }, delivery: { date: today(), place: '' }, originId: null, lines: [blank()], ...(initial || {}),
  }} onDone={onDone} onCancel={onCancel} />;
}

function Form({ init, onDone, onCancel }) {
  const toast = useToast();
  const [d, setD] = useState(init);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');
  const [confirm, setConfirm] = useState(null);
  const [pick, setPick] = useState('');
  const comps = useApi('/companies');
  const contacts = useApi('/contacts');
  const items = useApi('/items');
  const T = TYPES[d.docType];
  const set = (k, v) => { setD((s) => ({ ...s, [k]: v })); setErr(''); };
  const nest = (k, k2, v) => setD((s) => ({ ...s, [k]: { ...s[k], [k2]: v } }));
  const nest2 = (k, k2, k3, v) => setD((s) => ({ ...s, [k]: { ...s[k], [k2]: { ...(s[k][k2] || {}), [k3]: v } } }));

  const companyList = comps.data || [];
  const companyId = d.companyId || companyList[0]?.id || '';
  const company = companyList.find((c) => c.id === +companyId);
  const regime = company?.ivaRegime || 'geral';
  const ctList = (contacts.data || []).filter((c) => !companyId || c.companyId === +companyId);
  const contact = ctList.find((c) => c.id === d.contactId);
  const itemList = (items.data || []).filter((i) => i.active && (!companyId || i.companyId === +companyId));

  const c = useMemo(() => calcPreview({ lines: d.lines, gdiscPct: d.gdiscPct, retPct: d.retPct, cativoPct: contact?.cativoPct, regime, type: d.docType }), [d.lines, d.gdiscPct, d.retPct, contact, regime, d.docType]);
  const due = dueOf(d.docType, d.issueDate, d.terms);

  const onCustomer = (name) => {
    const m = ctList.find((x) => x.name.toLowerCase() === name.trim().toLowerCase());
    setD((s) => ({ ...s, customerName: name, contactId: m ? m.id : null, newClient: { ...s.newClient, name: m ? '' : name } })); setErr('');
  };
  const setLine = (i, k, v) => { setD((s) => ({ ...s, lines: s.lines.map((l, j) => (j === i ? { ...l, [k]: v } : l)) })); setErr(''); };
  const addLine = () => {
    const v = pick.trim(); const it = v ? itemList.find((x) => x.ref.toLowerCase() === v.toLowerCase() || x.description.toLowerCase() === v.toLowerCase()) : null;
    const nl = it ? { ...blank(), code: it.ref, description: it.description, price: String(+it.price), ivaRate: String(+it.ivaRate), itemId: it.id } : { ...blank(), code: v, newItem: !!v };
    setD((s) => { const last = s.lines[s.lines.length - 1]; const empty = last && !last.description.trim() && !last.code.trim() && !num(last.price); return { ...s, lines: empty ? [...s.lines.slice(0, -1), nl] : [...s.lines, nl] }; });
    setPick('');
  };

  const payload = (emit) => ({
    docType: d.docType, companyId: +companyId, contactId: d.contactId || null, ...(d.contactId || !d.newClient.name.trim() ? {} : { newClient: d.newClient }),
    issueDate: d.issueDate, terms: d.terms, serie: d.serie || null, vref: d.vref || null, place: d.place || null, notes: d.notes || null,
    gdiscPct: num(d.gdiscPct), retPct: num(d.retPct), originId: d.originId, emit,
    lines: d.lines.map((l) => ({ code: l.code || null, description: l.description, qty: num(l.qty), price: num(l.price), discPct: num(l.discPct), ivaRate: num(l.ivaRate), itemId: l.itemId, newItem: l.newItem })),
    ...(d.docType === 'GT' ? { transport: d.transport } : {}), ...(d.docType === 'EN' ? { delivery: d.delivery } : {}),
  });

  const clientErr = (emit) => {
    if (!companyId) return 'Seleccione a empresa.';
    if (!emit) return '';
    if (!d.contactId && !d.newClient.name.trim()) return 'Escolha ou crie um cliente.';
    const ls = d.lines.filter((l) => l.description.trim());
    if (!ls.length) return 'Adicione pelo menos uma linha com descrição.';
    if (ls.some((l) => !(num(l.qty) > 0) || !(num(l.price) > 0))) return 'Quantidade e preço têm de ser superiores a zero.';
    return '';
  };
  const run = async (emit) => {
    const e = clientErr(emit); if (e) { setErr(e); return; }
    setBusy(true);
    try {
      const r = d.id ? await api.put(`/documents/${d.id}`, payload(emit)) : await api.post('/documents', payload(emit));
      toast(emit ? `Documento emitido: ${r.docNumber}` : 'Rascunho guardado'); onDone(r.id);
    } catch (x) { setErr(x.message); } finally { setBusy(false); }
  };
  const emit = () => {
    const e = clientErr(true); if (e) { setErr(e); return; }
    const late = T.fisc && workDays(d.issueDate, today()) > 5 ? ` Atenção: a data do documento (${brDate(d.issueDate)}) ultrapassa o 5.º dia útil. A emissão fora de prazo pode ser punida com coima de 0,2% do valor (Dec. Pres. 71/25, arts. 8.º e 35.º).` : '';
    setConfirm(`Emitir este documento? Depois de emitido não pode ser editado.${late}`);
  };

  return (
    <>
      <div className="panel" style={{ marginBottom: 14 }}>
        <div style={{ display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap', marginBottom: 12 }}>
          <h2 style={{ margin: 0, fontSize: 20 }}>{d.id ? 'Editar' : 'Nova'} {NAMES[d.docType]}</h2><div className="spacer" style={{ flex: 1 }} />
          <small style={{ color: 'var(--muted)' }}>Campos obrigatórios *</small>
        </div>
        {d.originId && <div className="msg" style={{ marginBottom: 10 }}>Documento criado a partir de outro: {d.notes}</div>}
        <div className="frow">
          {companyList.length > 1 && (
            <F id="e_co" label="Empresa emitente" req><select id="e_co" value={companyId} onChange={(e) => set('companyId', +e.target.value)} disabled={!!d.id}>{companyList.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</select></F>)}
          <F id="e_cust" label="Cliente (pesquisar ou criar novo)" req wide>
            <input id="e_cust" list="dl-cust" value={d.customerName} onChange={(e) => onCustomer(e.target.value)} placeholder="Escreva o nome de um cliente existente ou de um novo" autoComplete="off" />
            <datalist id="dl-cust">{ctList.map((x) => <option key={x.id} value={x.name} />)}</datalist>
          </F>
          {contact && <div style={{ gridColumn: '1/-1', fontSize: 13, color: 'var(--muted)' }}>NIF {contact.nif || '—'} · {contact.address || 'sem morada'} · {contact.email || 'sem email'} · WhatsApp {contact.whatsapp || contact.phone || '—'}{+contact.cativoPct ? <b style={{ color: 'var(--text)' }}> · IVA cativo {+contact.cativoPct}%</b> : null}</div>}
          {!d.contactId && d.newClient.name.trim() && (<>
            <div style={{ gridColumn: '1/-1', fontSize: 13 }}><b>Dados do novo cliente</b> <span style={{ color: 'var(--muted)' }}>(será criado com o documento; o WhatsApp recebe as notificações de factura)</span></div>
            <F id="n_nif" label="NIF"><input id="n_nif" value={d.newClient.nif} onChange={(e) => nest('newClient', 'nif', e.target.value)} /></F>
            <F id="n_wa" label="WhatsApp (ex.: 923 456 789)"><input id="n_wa" value={d.newClient.whatsapp} onChange={(e) => nest('newClient', 'whatsapp', e.target.value)} /></F>
            <F id="n_em" label="Email"><input id="n_em" type="email" value={d.newClient.email} onChange={(e) => nest('newClient', 'email', e.target.value)} /></F>
            <F id="n_ad" label="Morada"><input id="n_ad" value={d.newClient.address} onChange={(e) => nest('newClient', 'address', e.target.value)} /></F>
          </>)}
        </div>
      </div>

      {d.docType === 'GT' && (
        <div className="panel" style={{ marginBottom: 14 }}><h3 style={{ marginTop: 0 }}>Detalhes do transporte</h3>
          <div className="frow">
            <F id="g_pl" label="Matrícula"><input id="g_pl" value={d.transport.plate || ''} onChange={(e) => nest('transport', 'plate', e.target.value)} /></F>
            <F id="g_ld" label="Data de carga" req><input id="g_ld" type="date" value={d.transport.loadDate || ''} onChange={(e) => nest('transport', 'loadDate', e.target.value)} /></F>
            <F id="g_fa" label="Local de carga — endereço" req><input id="g_fa" value={d.transport.from?.addr || ''} onChange={(e) => nest2('transport', 'from', 'addr', e.target.value)} /></F>
            <F id="g_fc" label="Cidade (carga)"><input id="g_fc" value={d.transport.from?.city || ''} onChange={(e) => nest2('transport', 'from', 'city', e.target.value)} /></F>
            <F id="g_ta" label="Local de entrega — endereço" req><input id="g_ta" value={d.transport.to?.addr || ''} onChange={(e) => nest2('transport', 'to', 'addr', e.target.value)} /></F>
            <F id="g_tc" label="Cidade (entrega)"><input id="g_tc" value={d.transport.to?.city || ''} onChange={(e) => nest2('transport', 'to', 'city', e.target.value)} /></F>
          </div></div>)}
      {d.docType === 'EN' && (
        <div className="panel" style={{ marginBottom: 14 }}><h3 style={{ marginTop: 0 }}>Detalhes da encomenda</h3>
          <div className="frow"><F id="d_dt" label="Data de entrega" req><input id="d_dt" type="date" value={d.delivery.date || ''} onChange={(e) => nest('delivery', 'date', e.target.value)} /></F>
            <F id="d_pl" label="Local de entrega"><input id="d_pl" value={d.delivery.place || ''} onChange={(e) => nest('delivery', 'place', e.target.value)} /></F></div></div>)}

      <div className="panel" style={{ marginBottom: 14 }}><h3 style={{ marginTop: 0 }}>Detalhes do documento</h3>
        <div className="frow">
          <F id="e_dt" label="Data"><input id="e_dt" type="date" value={d.issueDate} onChange={(e) => set('issueDate', e.target.value)} /></F>
          {d.docType !== 'GT' && d.docType !== 'FR' && <F id="e_tm" label={d.docType === 'PF' ? 'Válido até' : 'Vencimento'}><select id="e_tm" value={d.terms} onChange={(e) => set('terms', e.target.value)}>{TERMS.map((t) => <option key={t}>{t}</option>)}</select></F>}
          <F id="e_vr" label="V/ Ref."><input id="e_vr" value={d.vref} onChange={(e) => set('vref', e.target.value)} /></F>
          <F id="e_se" label="Série"><select id="e_se" value={d.serie} onChange={(e) => set('serie', e.target.value)}>{[new Date().getFullYear(), new Date().getFullYear() - 1].map((y) => <option key={y}>{y}</option>)}</select></F>
          {d.docType !== 'GT' && <F id="e_pl" label="Local"><input id="e_pl" value={d.place} onChange={(e) => set('place', e.target.value)} /></F>}
          <F id="e_rt" label="Retenção na fonte (%)"><input id="e_rt" type="number" min="0" max="100" step="any" value={d.retPct} onChange={(e) => set('retPct', e.target.value)} /></F>
          <F id="e_gd" label="Desconto global (%)"><input id="e_gd" type="number" min="0" max="100" step="any" value={d.gdiscPct} onChange={(e) => set('gdiscPct', e.target.value)} /></F>
          <F id="e_nt" label="Observações" wide><textarea id="e_nt" rows={2} value={d.notes} onChange={(e) => set('notes', e.target.value)} /></F>
        </div>
        {regime !== 'geral' && <div className="msg" style={{ marginTop: 8 }}>Empresa em <b>{regime === 'exclusao' ? 'Regime de Exclusão' : 'Regime Simplificado'}</b>: as facturas não liquidam IVA (a menção aparece no documento).</div>}
      </div>

      <div className="panel" style={{ marginBottom: 14 }}>
        <h3 style={{ marginTop: 0 }}>Itens *</h3>
        <div className="tablewrap"><table><thead><tr><th>Código</th><th style={{ minWidth: 220 }}>Descrição</th><th>Preço unit. (Kz)</th><th>Qtd.</th><th>IVA</th><th>Desc. %</th><th className="amt">Total linha</th><th /></tr></thead><tbody>
          {d.lines.map((l, i) => {
            const rowTot = c.sg * (num(l.qty) * num(l.price) * (1 - num(l.discPct) / 100));
            return (
              <tr key={i}>
                <td><input aria-label="Código" style={{ width: 90 }} value={l.code} onChange={(e) => setLine(i, 'code', e.target.value)} /></td>
                <td><input aria-label="Descrição" value={l.description} onChange={(e) => setLine(i, 'description', e.target.value)} /></td>
                <td><input aria-label="Preço unitário" type="number" min="0" step="0.01" style={{ width: 120 }} value={l.price} onChange={(e) => setLine(i, 'price', e.target.value)} /></td>
                <td><input aria-label="Quantidade" type="number" min="0" step="any" style={{ width: 80 }} value={l.qty} onChange={(e) => setLine(i, 'qty', e.target.value)} /></td>
                <td><select aria-label="Taxa de IVA" value={l.ivaRate} disabled={regime !== 'geral'} onChange={(e) => setLine(i, 'ivaRate', e.target.value)}>{RATES.map(([v, t]) => <option key={v} value={v}>{t}</option>)}</select></td>
                <td><input aria-label="Desconto" type="number" min="0" max="100" step="any" style={{ width: 70 }} value={l.discPct} onChange={(e) => setLine(i, 'discPct', e.target.value)} /></td>
                <td className="amt">{money(rowTot)}</td>
                <td><button type="button" className="btn sm ghost" aria-label={`Remover linha ${i + 1}`} onClick={() => setD((s) => ({ ...s, lines: s.lines.length > 1 ? s.lines.filter((_, j) => j !== i) : [blank()] }))}>Remover</button></td>
              </tr>);
          })}</tbody></table></div>
        <div style={{ display: 'flex', gap: 8, marginTop: 10, flexWrap: 'wrap' }}>
          <input style={{ flex: 1, minWidth: 220 }} list="dl-items" value={pick} onChange={(e) => setPick(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addLine(); } }} placeholder="Pesquise um item do catálogo ou escreva um código novo para criar" aria-label="Pesquisar ou criar item" autoComplete="off" />
          <datalist id="dl-items">{itemList.map((i) => <option key={i.id} value={i.ref}>{i.description}</option>)}</datalist>
          <button type="button" className="btn" onClick={addLine}>+ Adicionar linha</button>
        </div>
        <div className="grid g2" style={{ marginTop: 18, alignItems: 'start' }}>
          <div className="tablewrap"><table><thead><tr><th>Taxa/IVA</th><th className="amt">Incidência</th><th className="amt">Valor</th></tr></thead><tbody>
            {Object.keys(c.by).sort((a, b) => b - a).map((k) => <tr key={k}><td>{+k ? `${k}%` : 'Isento'}</td><td className="amt">{money(c.sg * c.by[k].base)}</td><td className="amt">{money(c.sg * c.by[k].tax)}</td></tr>)}
            {!Object.keys(c.by).length && <tr><td colSpan="3" style={{ color: 'var(--muted)' }}>Sem linhas.</td></tr>}</tbody></table></div>
          <div className="tablewrap"><table><tbody>
            {[['Soma', c.sum], ['Desconto', c.disc], ['Desconto global', c.gdisc], ['Total sem IVA', c.net], ['IVA', c.tax], ['Retenção', c.ret]].map(([l, v]) => <tr key={l}><td>{l}</td><td className="amt">{money(c.sg * v)}</td></tr>)}
            <tr><td><b>Total</b></td><td className="amt"><b>{money(c.sg * c.total)}</b></td></tr>
            {c.cativo > 0 && <><tr><td>IVA cativo retido pelo cliente ({c.cativoPct}%)</td><td className="amt">-{money(c.sg * c.cativo)}</td></tr><tr><td><b>A receber do cliente</b></td><td className="amt"><b>{money(c.sg * c.recv)}</b></td></tr></>}
          </tbody></table></div>
        </div>
      </div>

      {err && <div className="errbox" role="alert" style={{ marginBottom: 12 }}>{err}</div>}
      <div className="panel" style={{ display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
        <small style={{ color: 'var(--muted)', flex: 1 }}>{T.fisc || d.docType === 'PF' ? <>Pagamento previsto para <b style={{ color: 'var(--text)' }}>{brDate(due)}</b></> : 'Documento sem valor fiscal'}</small>
        <button type="button" className="btn ghost" onClick={onCancel} disabled={busy}>Cancelar</button>
        <button type="button" className="btn ghost" onClick={() => run(false)} disabled={busy}>Guardar como rascunho</button>
        <button type="button" className="btn" onClick={emit} disabled={busy}>Emitir</button>
      </div>
      {confirm && <Confirm message={confirm} label="Emitir" onOk={() => run(true)} onClose={() => setConfirm(null)} />}
    </>
  );
}
