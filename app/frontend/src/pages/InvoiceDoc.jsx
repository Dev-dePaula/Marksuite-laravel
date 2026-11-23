import React, { useEffect, useState } from 'react';
import QRCode from 'qrcode';
import { Confirm, FormModal, Loader, Modal, Tag, useApi, useAction, useSave, useToast } from '../ui.jsx';
import { api } from '../api.js';
import { issuerFor, kwanzasPorExtenso } from '../issuers.js';
import { EVENT_L, METHODS, NSTATUS, STATE_TAG, STATE_TXT, TYPES, brDate, money, today } from './billing/lib.js';

const CSS = `
.invdoc-bg{position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:40;overflow:auto;padding:20px}
.invdoc-wrap{max-width:900px;margin:0 auto}
.invdoc-bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;margin-bottom:10px}
.invdoc-bar .l,.invdoc-bar .r{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.invdoc{background:#fff;color:#111;border-radius:6px;padding:40px 44px;font-size:12.5px;line-height:1.45;box-shadow:0 30px 80px -20px rgba(0,0,0,.5)}
.invdoc h2{font-size:14px;margin:0}
.invdoc .top{display:grid;grid-template-columns:1fr 1fr;gap:24px}
.invdoc .logo{width:200px;height:110px;border-radius:6px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:22px;letter-spacing:.04em;margin-bottom:14px;text-align:center}
.invdoc .rule{border:0;border-top:2px solid #111;margin:14px 0 0}
.invdoc table{width:100%;border-collapse:collapse}
.invdoc th{text-align:left;font-weight:700;padding:6px 6px 6px 0;border-bottom:1px solid #111}
.invdoc td{padding:6px 6px 6px 0;vertical-align:top}
.invdoc .r{text-align:right}
.invdoc .meta th{border:0;font-weight:400;font-size:12px}
.invdoc .meta td{border-bottom:1px solid #111;padding-bottom:8px}
.invdoc .bottom{display:grid;grid-template-columns:1.1fr 1fr;gap:36px;border-top:2px solid #111;margin-top:34px;padding-top:6px}
.invdoc .sum td{padding:1px 0}
.invdoc .sum .tot td{border-top:1px solid #111;padding-top:8px}
.invdoc .blk{border-bottom:1px solid #111;padding:5px 0}
.invdoc .blk.h{font-weight:700;border-bottom:2px solid #111}
.invdoc .foot{margin-top:40px;font-size:11.5px;display:flex;gap:16px;align-items:center;flex-wrap:wrap}
.invdoc .foot small{color:#777;display:block;margin-top:6px;word-break:break-all}
.invdoc .pay{margin-top:22px;border:1px solid #cbd5e1;border-radius:8px;padding:12px 14px;background:#f8fafc}
.invdoc .cat{color:#7a4b00}
@media (max-width:700px){.invdoc{padding:20px}.invdoc .top,.invdoc .bottom{grid-template-columns:1fr}}
@media print{
  body *{visibility:hidden}
  .invdoc,.invdoc *{visibility:visible}
  .invdoc{position:absolute;left:0;top:0;width:100%;box-shadow:none;border-radius:0;padding:0}
  .invdoc-bg{background:none;overflow:visible;padding:0}
}
`;
const NOTE = 'Programa ainda não validado pela AGT. Sem validade fiscal até à certificação do software.';

/** Vista completa de um documento (qualquer tipo), com as acções permitidas pelo seu estado. */
export default function InvoiceDoc({ id, canWrite, onClose, onEdit, onChanged, onOpen, onNew }) {
  const doc = useApi(`/documents/${id}`);
  const notes = useApi(`/documents/${id}/notifications`);
  const [form, setForm] = useState(null);
  const [confirm, setConfirm] = useState(null);
  const [qr, setQr] = useState('');
  const act = useAction();
  const save = useSave();
  const d = doc.data;
  useEffect(() => { if (d?.qr) QRCode.toDataURL(d.qr, { margin: 0, width: 160, errorCorrectionLevel: 'M' }).then(setQr).catch(() => setQr('')); else setQr(''); }, [d?.qr]);
  const reload = () => { doc.reload(); notes.reload(); onChanged?.(); };

  return (
    <div className="invdoc-bg" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <style>{CSS}</style>
      <div className="invdoc-wrap" role="dialog" aria-modal="true" aria-label="Documento">
        <Loader state={doc}>{(x) => {
          const T = TYPES[x.docType] || { n: x.typeName };
          const iss = { ...issuerFor(x.company.name), ...Object.fromEntries(Object.entries({ name: x.company.name, nif: x.company.nif, phone: x.company.phone, email: x.company.email, web: x.company.website }).filter(([, v]) => v)) };
          const addrLines = x.company.address ? [x.company.address] : issuerFor(x.company.name).address;
          const bank = x.company.iban ? [['IBAN', x.company.iban]] : issuerFor(x.company.name).bank;
          const sg = x.sg; const v = x.view; const out = x.outstanding;
          const draft = x.draft; const emitted = !draft && !x.cancelledAt;
          const canPay = canWrite && emitted && out > 0 && !['NC', 'PF', 'GT', 'EN'].includes(x.docType);
          const recs = x.receipts || [];
          const print = async () => { if (T.fisc && x.hash) { try { await api.post(`/documents/${x.id}/printed`); } catch { /* segue a impressão */ } } window.print(); setTimeout(reload, 800); };
          return (<>
            <div className="invdoc-bar">
              <div className="l"><Tag kind={STATE_TAG[x.state]}>{STATE_TXT[x.state]}</Tag>{x.paidAmount > 0 && !x.paidAt && <small style={{ color: '#fff' }}>Pago: {money(x.paidAmount)} · em aberto: {money(out)}</small>}</div>
              <div className="r">
                {draft && canWrite && <><button className="btn ghost" style={{ background: '#fff' }} onClick={() => onEdit(x.id)}>Editar</button>
                  <button className="btn" onClick={() => setConfirm({ msg: 'Emitir este documento? Depois de emitido não pode ser editado.', label: 'Emitir', fn: () => act(() => api.post(`/documents/${x.id}/emit`), 'Documento emitido.', reload) })}>Emitir</button>
                  <button className="btn danger" onClick={() => setConfirm({ msg: 'Apagar este rascunho?', label: 'Apagar', fn: () => act(() => api.del(`/documents/${x.id}`), 'Rascunho apagado.', () => { onChanged?.(); onClose(); }) })}>Apagar</button></>}
                {canPay && <button className="btn ok" onClick={() => setForm('pay')}>Registar pagamento</button>}
                {canWrite && emitted && ['PF', 'EN'].includes(x.docType) && !x.convertedTo && <button className="btn" onClick={() => act(() => api.post(`/documents/${x.id}/convert`), 'Rascunho de factura criado.', (r) => { onChanged?.(); onEdit(r.id); })}>Converter em factura</button>}
                {canWrite && emitted && x.kind === 'RECEBER' && T.fisc && x.docType !== 'NC' && x.hash && <button className="btn ghost" style={{ background: '#fff' }} onClick={() => setForm('send')}>Enviar ao cliente</button>}
                {canWrite && emitted && x.docType !== 'NC' && !(x.paidAmount > 0) && <button className="btn ghost" style={{ background: '#fff', color: '#B91C1C' }} onClick={() => setConfirm({ msg: T.fisc ? `Anular ${x.docNumber}? Será emitida uma nota de crédito e o lançamento contabilístico será estornado.` : `Cancelar ${x.docNumber}?`, label: 'Anular', fn: () => act(() => api.post(`/documents/${x.id}/cancel`), 'Documento anulado.', reload) })}>{T.fisc ? 'Anular (nota de crédito)' : 'Cancelar'}</button>}
                {canWrite && x.docType !== 'NC' && <button className="btn ghost" style={{ background: '#fff' }} onClick={() => onNew(x)}>Duplicar</button>}
                <button className="btn ghost" style={{ background: '#fff' }} onClick={print}>Imprimir / PDF</button>
                <button className="btn ghost" style={{ background: '#fff' }} onClick={onClose}>Fechar</button>
              </div>
            </div>

            <div className="invdoc">
              <div className="top">
                <div>
                  <div className="logo" style={{ background: x.company.color || '#F97316' }}>{iss.name.toUpperCase()}</div>
                  <strong>{iss.name}</strong><br />
                  {addrLines?.map((l) => <React.Fragment key={l}>{l}<br /></React.Fragment>)}
                  {iss.phone && <>Tel: {iss.phone}<br /></>}{iss.web && <>Web: {iss.web}<br /></>}{iss.email && <>E-mail: {iss.email}<br /></>}{iss.nif && <>Contribuinte: {iss.nif}</>}
                </div>
                <div style={{ paddingTop: 124 }}>Exmo.(s) Sr(s)<br /><strong style={{ fontSize: 14 }}>{x.counterparty}</strong>{x.clientNif && <><br />NIF {x.clientNif}</>}{x.contact?.address && <><br />{x.contact.address}</>}</div>
              </div>

              <div style={{ marginTop: 22 }}>{T.fisc && x.hash ? (x.printCount > 0 ? '2.ª via, em conformidade com a original' : 'Original') : ''}</div>
              <h2>{T.n} n.º {draft ? 'Rascunho' : x.docNumber}</h2>
              <hr className="rule" />
              <table className="meta"><thead><tr><th>Data do Documento</th><th>{x.docType === 'PF' ? 'Válido até' : 'Data Vencimento'}</th><th>Contribuinte</th><th>V/ Ref.</th>{x.docType === 'NC' && <th>Anula</th>}</tr></thead>
                <tbody><tr><td>{brDate(x.issueDate)}</td><td>{x.docType === 'GT' ? '—' : brDate(x.dueDate)}</td><td>{x.clientNif || '—'}</td><td>{x.vref || '—'}</td>{x.docType === 'NC' && <td>{x.refNumber}</td>}</tr></tbody></table>
              {x.docType === 'GT' && x.transport && <p style={{ margin: '8px 0' }}><b>Matrícula:</b> {x.transport.plate || '—'} · <b>Data de carga:</b> {brDate(x.transport.loadDate)}<br /><b>Carga:</b> {x.transport.from?.addr} {x.transport.from?.city} · <b>Entrega:</b> {x.transport.to?.addr} {x.transport.to?.city}</p>}
              {x.docType === 'EN' && x.delivery && <p style={{ margin: '8px 0' }}><b>Data de entrega:</b> {brDate(x.delivery.date)} {x.delivery.place && <>· <b>Local:</b> {x.delivery.place}</>}</p>}
              {x.notes && <div style={{ fontSize: 12, padding: '4px 0', borderBottom: '1px solid #111' }}><b>Observações:</b> {x.notes}</div>}

              <hr className="rule" style={{ marginTop: 14 }} />
              <table style={{ minHeight: 100 }}><thead><tr><th>Código</th><th>Descrição</th><th className="r">Preço Uni.</th><th className="r">Qtd.</th><th className="r">IVA %</th><th className="r">Desc. %</th><th className="r">Total</th></tr></thead><tbody>
                {x.lines.map((l, i) => <tr key={l.id || i}><td>{l.code || ''}</td><td style={{ maxWidth: 260 }}>{l.description}</td><td className="r">{money(l.price)}</td><td className="r">{+l.qty}</td><td className="r">{+l.ivaRate ? (+l.ivaRate).toFixed(2) : 'Isento'}</td><td className="r">{(+l.discPct || 0).toFixed(2)}</td><td className="r">{money(sg * (l.qty * l.price * (1 - (l.discPct || 0) / 100)))}</td></tr>)}</tbody></table>

              <div className="bottom">
                <div>
                  <table><thead><tr><th>Imposto/IVA %</th><th className="r">Incidência</th><th className="r">Valor</th></tr></thead><tbody>
                    {Object.keys(v.by).sort((a, b) => b - a).map((k) => <tr key={k}><td>{+k ? `IVA - ${k}` : 'Isento - 0'}</td><td className="r">{money(sg * v.by[k].base)}</td><td className="r">{money(sg * v.by[k].tax)}</td></tr>)}</tbody></table>
                  {x.company.ivaRegime !== 'geral' && T.fisc && <div className="blk h" style={{ marginTop: 14 }}>IVA — {x.company.ivaRegime === 'exclusao' ? 'Regime de Exclusão' : 'Regime Simplificado'}</div>}
                  {x.docType === 'AF' && <div className="blk h" style={{ marginTop: 14 }}>Auto-Facturação</div>}
                  {bank?.length > 0 && <div style={{ marginTop: 14 }}><div className="blk h">Dados Bancários</div>{bank.map(([k, val]) => <div className="blk" key={k}>{k} {val}</div>)}</div>}
                </div>
                <div>
                  <table className="sum"><tbody>
                    {x.gdiscPct > 0 && <tr><td>Desconto global ({+x.gdiscPct}%)</td><td className="r">incluído</td></tr>}
                    <tr><td>Total sem IVA:</td><td className="r">{money(sg * v.net)}</td></tr><tr><td>Total de Impostos:</td><td className="r">{money(sg * v.tax)}</td></tr>
                    {v.ret > 0 && <tr><td>Retenção ({+x.retPct}%):</td><td className="r">-{money(sg * v.ret)}</td></tr>}
                    <tr className="tot"><td style={{ paddingTop: 10 }}>Total:</td><td className="r" style={{ paddingTop: 10 }}><strong>{money(sg * v.total)}</strong></td></tr>
                    {v.cativo > 0 && <><tr className="cat"><td>IVA cativo retido pelo cliente ({+x.cativoPct}%):</td><td className="r">-{money(sg * v.cativo)}</td></tr><tr className="tot"><td style={{ paddingTop: 8 }}>Valor a receber:</td><td className="r" style={{ paddingTop: 8 }}><strong>{money(sg * v.recv)}</strong></td></tr></>}
                  </tbody></table>
                  <div style={{ fontSize: 11, marginTop: 14, fontStyle: 'italic' }}>{sg < 0 ? 'Crédito de ' : ''}{kwanzasPorExtenso(v.total)}</div>
                </div>
              </div>

              {x.late?.total > 0 && <div className="pay" style={{ background: '#FFF8E6', borderColor: '#F2D48A' }}><strong>Documento vencido há {x.late.days} dias</strong><br />Encargos por atraso até hoje: multa {money(x.late.fee)} + juros de mora {money(x.late.interest)} = <strong>{money(x.late.total)}</strong>. Valor em aberto com encargos: <strong>{money(x.late.base + x.late.total)}</strong>.<br /><small style={{ color: '#6b5b2a' }}>Os encargos são cobrados à parte e não estão sujeitos a IVA; o IVA do documento fica fixado na emissão.</small></div>}

              {recs.length > 0 && <div className="pay"><strong>Pagamentos registados</strong><table style={{ marginTop: 6 }}><tbody>{recs.map((r) => <tr key={r.id}><td>{r.docNumber}</td><td>{brDate(r.paymentDate)}</td><td>{r.paymentMethod}</td><td className="r">{money(r.amount)}</td></tr>)}</tbody></table></div>}

              <div className="foot">
                {qr && <img src={qr} width="92" height="92" alt="QR code do documento" />}
                <div style={{ flex: 1, minWidth: 200 }}>
                  {T.fisc ? (x.hash ? <>Hash: {x.hash.slice(0, 16)}…{x.hash.slice(-8)}<br />{NOTE}</> : 'Rascunho: número e hash gerados na emissão.') : 'Documento sem valor fiscal.'}
                  <small>Processado pelo MarkSuite</small>
                </div>
              </div>
            </div>

            {canWrite && x.link && (
              <div className="panel" style={{ marginTop: 12 }}>
                <b>Notificações ao cliente</b>
                <Loader state={notes}>{(list) => (list.length ? (
                  <div className="tablewrap"><table><thead><tr><th>Quando</th><th>Canal</th><th>Evento</th><th>Para</th><th>Estado</th></tr></thead><tbody>
                    {list.map((n) => <tr key={n.id}><td>{new Date(n.createdAt).toLocaleString('pt-PT')}</td><td>{n.channel === 'whatsapp' ? 'WhatsApp' : 'Email'}</td><td>{EVENT_L[n.event] || n.event}{n.manual ? ' (manual)' : ''}</td><td>{n.toAddress}</td><td><Tag kind={NSTATUS[n.status]}>{n.status}</Tag>{n.error ? <small style={{ display: 'block', color: 'var(--muted)' }}>{n.error}</small> : null}</td></tr>)}</tbody></table></div>
                ) : <small style={{ color: 'var(--muted)' }}>Ainda não foi enviada nenhuma mensagem sobre este documento.</small>)}</Loader>
              </div>)}

            {form === 'pay' && (
              <FormModal title={`Registar pagamento — ${x.docNumber}`} onClose={() => setForm(null)} okLabel="Registar"
                fields={[{ k: 'amount', l: `Valor (em aberto: ${money(out)})`, type: 'number', req: true, min: 0, def: +out.toFixed(2) }, { k: 'paymentDate', l: 'Data', type: 'date', req: true, def: today() }, { k: 'paymentMethod', l: 'Método', type: 'select', req: true, opts: METHODS, def: 'transferencia' },
                  ...(x.late?.total > 0 ? [{ k: 'chargeLate', l: `Cobrar multa e juros de mora (≈ ${money(x.late.total)} sobre o valor em aberto; calculados à data do pagamento)`, type: 'select', req: true, def: 'true', opts: [{ v: 'true', l: 'Sim, cobrar' }, { v: 'false', l: 'Não, perdoar' }], wide: true }] : [])]}
                onSubmit={(val) => save(() => api.post(`/invoices/${x.id}/receipts`, { ...val, chargeLate: val.chargeLate === 'true' }), 'Recibo emitido e contabilizado.', reload)} />)}
            {form === 'send' && <SendModal doc={x} onClose={() => setForm(null)} onSent={reload} />}
            {confirm && <Confirm message={confirm.msg} label={confirm.label} onOk={confirm.fn} onClose={() => setConfirm(null)} />}
          </>);
        }}</Loader>
      </div>
    </div>
  );
}

function SendModal({ doc, onClose, onSent }) {
  const [ev, setEv] = useState(doc.paidAt ? 'PAGA' : doc.state === 'V' ? 'VENCIDA' : 'EMITIDA');
  const [ch, setCh] = useState({ whatsapp: true, email: true });
  const [busy, setBusy] = useState(false);
  const act = useAction();
  const toast = useToast();
  const c = doc.contact;
  const go = async () => {
    setBusy(true);
    const r = await act(() => api.post(`/documents/${doc.id}/notify`, { event: ev, channels: Object.keys(ch).filter((k) => ch[k]) }), null);
    setBusy(false);
    if (r) { const bad = r.filter((n) => n.status === 'FALHOU').length; const sim = r.filter((n) => n.status === 'SIMULADA').length; onSent(); onClose(); toast(`${r.length} mensagem(ns): ${bad ? `${bad} falhou · ` : ''}${sim ? `${sim} simulada(s) (canal por configurar)` : 'enviada(s)'}`, bad > 0); }
  };
  return (
    <Modal title="Enviar ao cliente" onClose={onClose} footer={<><button className="btn ghost" onClick={onClose}>Cancelar</button><button className="btn" onClick={go} disabled={busy}>Enviar</button></>}>
      <p style={{ marginTop: 0 }}>Cliente: <b>{doc.counterparty}</b><br /><small style={{ color: 'var(--muted)' }}>WhatsApp: {c?.whatsapp || c?.phone || 'sem número'} · Email: {c?.email || 'sem email'}</small></p>
      <div className="field"><label htmlFor="s_ev">Mensagem</label><select id="s_ev" value={ev} onChange={(e) => setEv(e.target.value)}>{Object.entries(EVENT_L).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select></div>
      <div style={{ display: 'flex', gap: 18, marginTop: 10 }}>
        {[['whatsapp', 'WhatsApp'], ['email', 'Email']].map(([k, l]) => <label key={k} style={{ display: 'flex', gap: 6, alignItems: 'center' }}><input type="checkbox" checked={ch[k]} onChange={(e) => setCh({ ...ch, [k]: e.target.checked })} /> {l}</label>)}
      </div>
    </Modal>
  );
}
