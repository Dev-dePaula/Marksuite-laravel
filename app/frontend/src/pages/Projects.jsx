import React, { useState } from 'react';
import { Bar, Confirm, FormModal, Icon, KpiRow, Loader, Modal, PageHead, Tag, can, fdate, useAction, useApi, useSave } from '../ui.jsx';
import { api } from '../api.js';

const tone = (s) => (s === 'Atrasado' ? 'red' : s === 'Em risco' ? 'amb' : 'green');
const riskTag = (r) => (r >= 60 ? 'red' : r >= 35 ? 'amb' : 'ok');

export default function Projects({ user }) {
  const st = useApi('/projects');
  const comps = useApi('/companies');
  const emps = useApi('/employees');
  const [status, setStatus] = useState('');
  const [edit, setEdit] = useState(null);
  const [del, setDel] = useState(null);
  const [view, setView] = useState(null);
  const act = useAction();
  const save = useSave();
  const w = can(user, 'projects');
  const ready = comps.data && emps.data;

  const fields = ready ? [
    { k: 'name', l: 'Projecto', req: true, wide: true },
    { k: 'companyId', l: 'Empresa', type: 'select', req: true, num: true, opts: comps.data.map((c) => ({ v: c.id, l: c.name })), def: comps.data[0]?.id },
    { k: 'client', l: 'Cliente', def: 'Interno', req: true },
    { k: 'ownerId', l: 'Responsável', type: 'select', req: true, num: true, opts: emps.data.map((e) => ({ v: e.id, l: e.name })), def: emps.data[0]?.id },
    { k: 'progress', l: 'Progresso (%)', type: 'number', min: 0, max: 100, def: 0, req: true },
    { k: 'startDate', l: 'Início', type: 'date', req: true, def: new Date().toISOString().slice(0, 10) },
    { k: 'dueDate', l: 'Prazo', type: 'date', req: true },
    { k: 'delayedDeps', l: 'Dependências atrasadas', type: 'number', min: 0, def: 0, req: true },
  ] : [];

  return (
    <>
      <PageHead title="Projectos" sub="Execução, dependências e risco de prazo (estado calculado automaticamente)">
        <select value={status} onChange={(e) => setStatus(e.target.value)} aria-label="Filtrar por estado">
          <option value="">Todos os estados</option>{['No prazo', 'Em risco', 'Atrasado', 'Concluído'].map((s) => <option key={s}>{s}</option>)}</select>
        {w && <button className="btn" onClick={() => setEdit({})}>+ Novo projecto</button>}
      </PageHead>
      <Loader state={st}>{(all) => {
        const active = all.filter((p) => p.status !== 'Concluído');
        const rows = all.filter((p) => !status || p.status === status);
        return (
          <>
            <KpiRow items={[['Projectos activos', active.length, '#2563EB', 'proj'], ['No prazo', all.filter((p) => p.status === 'No prazo').length, '#16A34A', 'proj'],
              ['Em risco / atrasados', all.filter((p) => ['Em risco', 'Atrasado'].includes(p.status)).length, '#DC2626', 'proj'],
              ['Progresso médio', `${Math.round(active.reduce((a, p) => a + p.progress, 0) / (active.length || 1))}%`, '#7C3AED', 'proj']]} />
            <div className="panel tablewrap"><table><thead><tr><th>Projecto</th><th>Empresa / Cliente</th><th>Responsável</th><th>Progresso</th><th>Prazo</th><th>Risco</th><th>Estado</th><th /></tr></thead><tbody>
              {rows.map((p) => (
                <tr key={p.id}><td><strong>{p.name}</strong></td><td>{p.company.name}<br /><small style={{ color: 'var(--muted)' }}>{p.client}</small></td><td>{p.owner.name}</td>
                  <td style={{ width: 120 }}><strong>{p.progress}%</strong><Bar value={p.progress} tone={tone(p.status)} /></td>
                  <td className={p.daysLeft < 0 && p.status !== 'Concluído' ? 'dn' : ''}>{fdate(p.dueDate)}<br /><small>{p.status === 'Concluído' ? '' : p.daysLeft < 0 ? `${-p.daysLeft} d atraso` : `${p.daysLeft} dias`}</small></td>
                  <td><Tag kind={riskTag(p.risk)}>{p.risk}%</Tag>{p.delayedDeps ? <small style={{ color: 'var(--muted)' }}> {p.delayedDeps} dep.</small> : null}</td>
                  <td><Tag>{p.status}</Tag></td>
                  <td><div className="actions"><button className="iconbtn" title="Ver" onClick={() => setView(p)}><Icon n="eye" size={16} /></button>
                    {w && <><button className="iconbtn" title="Editar" onClick={() => setEdit(p)}><Icon n="edit" size={16} /></button>
                      <button className="iconbtn" title="Eliminar" onClick={() => setDel(p)}><Icon n="del" size={16} /></button></>}</div></td></tr>
              ))}</tbody></table></div>
          </>
        );
      }}</Loader>
      {view && (
        <Modal title={view.name} onClose={() => setView(null)}>
          <div style={{ marginBottom: 12 }}><Tag>{view.status}</Tag> <Tag kind={riskTag(view.risk)}>Risco de derrapagem {view.risk}%</Tag></div>
          <dl className="dl"><dt>Empresa</dt><dd>{view.company.name}</dd><dt>Cliente</dt><dd>{view.client}</dd><dt>Responsável</dt><dd>{view.owner.name}</dd>
            <dt>Progresso</dt><dd>{view.progress}%</dd><dt>Início</dt><dd>{fdate(view.startDate)}</dd><dt>Prazo</dt><dd>{fdate(view.dueDate)} ({view.daysLeft} dias)</dd><dt>Dependências atrasadas</dt><dd>{view.delayedDeps}</dd></dl>
          <div style={{ marginTop: 14 }}><Bar value={view.progress} tone={tone(view.status)} /></div>
        </Modal>
      )}
      {edit && ready && (
        <FormModal title={edit.id ? 'Editar projecto' : 'Novo projecto'} fields={fields} onClose={() => setEdit(null)}
          initial={edit.id ? { ...edit, startDate: fdate(edit.startDate), dueDate: fdate(edit.dueDate) } : {}}
          onSubmit={(v) => save(() => (edit.id ? api.put(`/projects/${edit.id}`, v) : api.post('/projects', v)), 'Projecto guardado.', st.reload)} />
      )}
      {del && <Confirm message={`Eliminar o projecto ${del.name}?`} label="Eliminar" onClose={() => setDel(null)} onOk={() => act(() => api.del(`/projects/${del.id}`), 'Projecto eliminado.', st.reload)} />}
    </>
  );
}
