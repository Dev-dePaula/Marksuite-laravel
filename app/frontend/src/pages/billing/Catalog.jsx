import React, { useState } from 'react';
import { Confirm, FormModal, Loader, Tag, useAction, useApi, useSave } from '../../ui.jsx';
import { api } from '../../api.js';
import { money } from './lib.js';

const SearchBar = ({ value, onChange, ph }) => {
  const [v, setV] = useState(value);
  return (
    <form className="panel" style={{ display: 'flex', gap: 8, marginBottom: 14 }} onSubmit={(e) => { e.preventDefault(); onChange(v); }}>
      <input style={{ flex: 1 }} aria-label="Pesquisa" placeholder={ph} value={v} onChange={(e) => setV(e.target.value)} /><button className="btn">Pesquisar</button>
    </form>
  );
};

export function Contacts({ canWrite, companies }) {
  const [q, setQ] = useState('');
  const [form, setForm] = useState(null);
  const [confirm, setConfirm] = useState(null);
  const list = useApi(`/contacts?q=${encodeURIComponent(q)}`);
  const act = useAction(); const save = useSave();
  const co = companies.map((c) => ({ v: c.id, l: c.name }));
  const fields = [
    { k: 'companyId', l: 'Empresa', type: 'select', req: true, num: true, opts: co, def: co[0]?.v }, { k: 'name', l: 'Nome', req: true }, { k: 'nif', l: 'NIF' },
    { k: 'whatsapp', l: 'WhatsApp (ex.: 923 456 789)', ph: '9XX XXX XXX' }, { k: 'phone', l: 'Telefone' }, { k: 'email', l: 'Email', type: 'email' }, { k: 'address', l: 'Morada', wide: true },
    { k: 'kind', l: 'Tipo', type: 'select', req: true, opts: ['Cliente', 'Fornecedor', 'Cliente e fornecedor'], def: 'Cliente' },
    { k: 'cativoPct', l: 'Retenção de IVA (IVA cativo)', type: 'select', req: true, num: true, def: 0, opts: [{ v: 0, l: 'Não retém' }, { v: 50, l: 'Retém 50% (banco, seguradora, telecom)' }, { v: 100, l: 'Retém 100% (Estado, petrolífera)' }] },
    { k: 'notifyWhatsapp', l: 'Notificar por WhatsApp', type: 'select', req: true, def: 'true', opts: [{ v: 'true', l: 'Sim' }, { v: 'false', l: 'Não' }] },
    { k: 'notifyEmail', l: 'Notificar por email', type: 'select', req: true, def: 'true', opts: [{ v: 'true', l: 'Sim' }, { v: 'false', l: 'Não' }] },
  ];
  const norm = (v) => ({ ...v, notifyWhatsapp: v.notifyWhatsapp === 'true' || v.notifyWhatsapp === true, notifyEmail: v.notifyEmail === 'true' || v.notifyEmail === true });
  const initial = (c) => ({ ...c, notifyWhatsapp: String(c.notifyWhatsapp), notifyEmail: String(c.notifyEmail), cativoPct: +c.cativoPct });
  return (<>
    <SearchBar value={q} onChange={setQ} ph="Pesquisar por nome, NIF, email ou telefone" />
    {canWrite && <div style={{ marginBottom: 10 }}><button className="btn" onClick={() => setForm({})}>+ Novo contacto</button></div>}
    <Loader state={list}>{(rows) => (
      <div className="panel tablewrap"><table><thead><tr><th>Nome</th><th>NIF</th><th>WhatsApp</th><th>Email</th><th>IVA cativo</th><th>Notificações</th><th className="amt">Em dívida</th><th /></tr></thead><tbody>
        {rows.map((c) => (
          <tr key={c.id}><td><strong>{c.name}</strong><small style={{ display: 'block', color: 'var(--muted)' }}>{c.kind}</small></td><td>{c.nif || '—'}</td><td>{c.whatsapp || c.phone || '—'}</td><td>{c.email || '—'}</td>
            <td>{+c.cativoPct ? <Tag kind="amb">{+c.cativoPct}%</Tag> : '—'}</td>
            <td>{[c.notifyWhatsapp && 'WhatsApp', c.notifyEmail && 'Email'].filter(Boolean).join(' · ') || 'Desligadas'}</td><td className="amt">{money(c.debt)}</td>
            <td>{canWrite && <div className="actions"><button className="btn sm ghost" onClick={() => setForm(c)}>Editar</button>
              <button className="btn sm ghost" onClick={() => setConfirm({ msg: `Apagar ${c.name}?`, fn: () => act(() => api.del(`/contacts/${c.id}`), 'Contacto apagado.', list.reload) })}>Apagar</button></div>}</td></tr>))}
        {!rows.length && <tr><td colSpan="8"><div className="empty"><strong>Nenhum contacto</strong></div></td></tr>}</tbody></table></div>)}</Loader>
    {form && <FormModal title={form.id ? 'Editar contacto' : 'Novo contacto'} fields={fields} initial={form.id ? initial(form) : {}} onClose={() => setForm(null)}
      onSubmit={(v) => save(() => (form.id ? api.put(`/contacts/${form.id}`, norm(v)) : api.post('/contacts', norm(v))), 'Contacto guardado.', list.reload)} />}
    {confirm && <Confirm message={confirm.msg} onOk={confirm.fn} onClose={() => setConfirm(null)} />}
  </>);
}

export function Items({ canWrite, companies }) {
  const [q, setQ] = useState('');
  const [form, setForm] = useState(null);
  const [confirm, setConfirm] = useState(null);
  const list = useApi(`/items?q=${encodeURIComponent(q)}`);
  const act = useAction(); const save = useSave();
  const co = companies.map((c) => ({ v: c.id, l: c.name }));
  const fields = [
    { k: 'companyId', l: 'Empresa', type: 'select', req: true, num: true, opts: co, def: co[0]?.v }, { k: 'description', l: 'Descrição', req: true }, { k: 'ref', l: 'Referência (vazio = automática)' },
    { k: 'kind', l: 'Tipo', type: 'select', req: true, opts: ['Serviço', 'Produto'], def: 'Serviço' }, { k: 'unit', l: 'Unidade', def: 'un' },
    { k: 'price', l: 'Preço (Kz)', type: 'number', req: true, min: 0, def: 0 }, { k: 'ivaRate', l: 'IVA (%)', type: 'select', req: true, num: true, def: 14, opts: [{ v: 14, l: '14% (taxa geral)' }, { v: 7, l: '7%' }, { v: 5, l: '5% (bens essenciais)' }, { v: 1, l: '1% (Cabinda)' }, { v: 0, l: 'Isento' }] },
  ];
  return (<>
    <SearchBar value={q} onChange={setQ} ph="Pesquisar por descrição ou referência" />
    {canWrite && <div style={{ marginBottom: 10 }}><button className="btn" onClick={() => setForm({})}>+ Novo item</button></div>}
    <Loader state={list}>{(rows) => (
      <div className="panel tablewrap"><table><thead><tr><th>Ref.</th><th>Descrição</th><th>Tipo</th><th>Un.</th><th className="amt">Preço</th><th className="amt">IVA</th><th /></tr></thead><tbody>
        {rows.map((i) => (
          <tr key={i.id}><td>{i.ref}</td><td><strong>{i.description}</strong></td><td>{i.kind}</td><td>{i.unit}</td><td className="amt">{money(i.price)}</td><td className="amt">{+i.ivaRate ? `${+i.ivaRate}%` : 'Isento'}</td>
            <td>{canWrite && <div className="actions"><button className="btn sm ghost" onClick={() => setForm(i)}>Editar</button>
              <button className="btn sm ghost" onClick={() => setConfirm({ msg: `Apagar o item ${i.ref}?`, fn: () => act(() => api.del(`/items/${i.id}`), 'Item apagado.', list.reload) })}>Apagar</button></div>}</td></tr>))}
        {!rows.length && <tr><td colSpan="7"><div className="empty"><strong>Nenhum item</strong></div></td></tr>}</tbody></table></div>)}</Loader>
    {form && <FormModal title={form.id ? 'Editar item' : 'Novo item'} fields={fields} initial={form.id ? { ...form, price: +form.price, ivaRate: +form.ivaRate } : {}} onClose={() => setForm(null)}
      onSubmit={(v) => save(() => (form.id ? api.put(`/items/${form.id}`, v) : api.post('/items', v)), 'Item guardado.', list.reload)} />}
    {confirm && <Confirm message={confirm.msg} onOk={confirm.fn} onClose={() => setConfirm(null)} />}
  </>);
}
