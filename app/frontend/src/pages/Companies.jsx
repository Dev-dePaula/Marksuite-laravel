import React, { useState } from 'react';
import { Ac, Confirm, FormModal, Loader, PageHead, csv, hcol, can, useAction, useSave, useApi, Icon } from '../ui.jsx';
import { api } from '../api.js';

const DIMS = [['scoreComercial', 'Comercial'], ['scoreFinanceiro', 'Financeiro'], ['scoreProjectos', 'Projectos'], ['scorePessoas', 'Pessoas'], ['scoreClientes', 'Clientes'], ['scoreOperacoes', 'Operações']];
const fields = [{ k: 'name', l: 'Empresa', req: true, wide: true }, { k: 'abbr', l: 'Sigla (máx. 4)', req: true }, { k: 'color', l: 'Cor', type: 'color', def: '#2563EB' },
  ...DIMS.map(([k, l]) => ({ k, l: `Índice ${l}`, type: 'number', min: 0, max: 100, def: 80 }))];

export default function Companies({ user }) {
  const st = useApi('/companies');
  const [edit, setEdit] = useState(null); // {} = novo, obj = editar
  const [del, setDel] = useState(null);
  const act = useAction();
  const save = useSave();
  const w = can(user, 'companies');
  const dimsOf = (c) => Object.entries(c.dims);

  return (
    <>
      <PageHead title="Empresas do Grupo" sub="Corporate Health Index — pontuação por empresa e por dimensão">
        <button className="btn ghost" onClick={() => st.data && csv('empresas.csv', ['Empresa', 'Saúde', 'Receita', 'Margem'], st.data.map((c) => [c.name, c.health, c.revenue, c.margin ?? '']))}>Exportar CSV</button>
        {w && <button className="btn" onClick={() => setEdit({})}>+ Nova empresa</button>}
      </PageHead>
      <Loader state={st}>{(rows) => (
        <div className="grid g3">
          {rows.map((c) => (
            <div className="panel" style={{ padding: 18 }} key={c.id}>
              <div style={{ display: 'flex', gap: 12, alignItems: 'center', marginBottom: 14 }}>
                <span className="company-ic" style={{ background: c.color, width: 34, height: 34, borderRadius: 9, fontSize: 12 }}>{c.abbr}</span>
                <div style={{ flex: 1 }}><strong style={{ fontSize: 16 }}>{c.name}</strong><br /><small style={{ color: 'var(--muted)' }}>{Ac(c.revenue)}{c.margin != null ? ` · margem ${c.margin}%` : ''}</small></div>
                <span className="score" style={{ background: hcol(c.health), minWidth: 42, height: 28, fontSize: 14 }}>{c.health}</span>
              </div>
              {dimsOf(c).map(([k, v]) => (
                <div className="prog" style={{ marginBottom: 8 }} key={k}><div className="t" style={{ fontSize: 12.5 }}><span>{k}</span><strong>{v}</strong></div>
                  <div className={`bar ${v < 70 ? 'red' : v < 80 ? 'amb' : 'green'}`}><i style={{ width: `${v}%` }} /></div></div>
              ))}
              {w && <div className="actions" style={{ marginTop: 12 }}>
                <button className="iconbtn" title="Editar" onClick={() => setEdit(c)}><Icon n="edit" size={16} /></button>
                <button className="iconbtn" title="Eliminar" onClick={() => setDel(c)}><Icon n="del" size={16} /></button></div>}
            </div>
          ))}
          {edit && (
            <FormModal title={edit.id ? 'Editar empresa' : 'Nova empresa'} fields={fields}
              initial={edit.id ? { name: edit.name, abbr: edit.abbr, color: edit.color, ...Object.fromEntries(DIMS.map(([k, l]) => [k, edit.dims[l]])) } : {}}
              onClose={() => setEdit(null)}
              onSubmit={(v) => save(() => (edit.id ? api.put(`/companies/${edit.id}`, v) : api.post('/companies', v)), 'Empresa guardada.', st.reload)} />
          )}
          {del && <Confirm message={`Eliminar a empresa ${del.name}?`} label="Eliminar" onClose={() => setDel(null)} onOk={() => act(() => api.del(`/companies/${del.id}`), 'Empresa eliminada.', st.reload)} />}
        </div>
      )}</Loader>
    </>
  );
}
