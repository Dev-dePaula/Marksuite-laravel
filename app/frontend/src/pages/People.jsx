import React, { useState } from 'react';
import { Bar, Confirm, FormModal, Icon, KpiRow, Loader, PageHead, Tag, can, fdate, initials, useAction, useApi, useSave } from '../ui.jsx';
import { api } from '../api.js';

export function People({ user }) {
  const st = useApi('/employees');
  const comps = useApi('/companies');
  const [q, setQ] = useState('');
  const [edit, setEdit] = useState(null);
  const [del, setDel] = useState(null);
  const act = useAction();
  const save = useSave();
  const w = can(user, 'employees');
  const fields = comps.data ? [
    { k: 'name', l: 'Nome', req: true, wide: true }, { k: 'jobTitle', l: 'Função', req: true },
    { k: 'companyId', l: 'Empresa', type: 'select', req: true, num: true, opts: comps.data.map((c) => ({ v: c.id, l: c.name })), def: comps.data[0]?.id },
    { k: 'performance', l: 'Desempenho (0-100)', type: 'number', min: 0, max: 100, def: 80, req: true }, { k: 'capacity', l: 'Capacidade (%)', type: 'number', min: 0, max: 200, def: 90, req: true },
  ] : [];
  return (
    <>
      <PageHead title="Pessoas" sub="Equipas, desempenho e capacidade">{w && <button className="btn" onClick={() => setEdit({})}>+ Novo colaborador</button>}</PageHead>
      <Loader state={st}>{(all) => {
        const rows = all.filter((e) => !q || e.name.toLowerCase().includes(q.toLowerCase()));
        const avg = (k) => Math.round(all.reduce((a, e) => a + e[k], 0) / (all.length || 1));
        return (
          <>
            <KpiRow items={[['Colaboradores', all.length, '#2563EB', 'people'], ['Desempenho médio', avg('performance'), '#16A34A', 'people'],
              ['Em sobrecarga', all.filter((e) => e.capacity > 100).length, '#DC2626', 'people'], ['Capacidade média', `${avg('capacity')}%`, '#7C3AED', 'people']]} />
            <div className="grid g-21">
              <div className="panel"><div className="hd"><h3>Colaboradores</h3><div className="spacer" />
                <input type="search" placeholder="Pesquisar" value={q} onChange={(e) => setQ(e.target.value)} style={{ padding: '7px 11px', border: '1px solid var(--line)', borderRadius: 8 }} /></div>
                <div className="tablewrap"><table><thead><tr><th>Colaborador</th><th>Função</th><th>Empresa</th><th>Desempenho</th><th>Capacidade</th><th>Estado</th><th /></tr></thead><tbody>
                  {rows.map((e) => (
                    <tr key={e.id}><td><div className="hcell"><span className="avatar sm">{initials(e.name)}</span><strong>{e.name}</strong></div></td><td>{e.jobTitle}</td><td>{e.company.name}</td>
                      <td style={{ width: 110 }}><strong>{e.performance}</strong><Bar value={e.performance} tone={e.performance < 70 ? 'red' : 'green'} /></td>
                      <td style={{ width: 110 }}><strong className={e.capacity > 100 ? 'dn' : ''}>{e.capacity}%</strong><Bar value={e.capacity} tone={e.capacity > 100 ? 'red' : e.capacity > 90 ? 'amb' : ''} /></td>
                      <td><Tag>{e.status}</Tag></td>
                      <td>{w && <div className="actions"><button className="iconbtn" title="Editar" onClick={() => setEdit(e)}><Icon n="edit" size={16} /></button>
                        <button className="iconbtn" title="Eliminar" onClick={() => setDel(e)}><Icon n="del" size={16} /></button></div>}</td></tr>
                  ))}</tbody></table></div></div>
              <div className="panel"><div className="hd"><h3>Capacidade por empresa</h3></div><div className="bd">
                {(comps.data || []).map((c) => {
                  const ps = all.filter((e) => e.companyId === c.id);
                  const cap = ps.length ? Math.round(ps.reduce((a, e) => a + e.capacity, 0) / ps.length) : 0;
                  return (<div className="prog" key={c.id}><div className="t"><span>{c.name} <small style={{ color: 'var(--muted)' }}>· {ps.length} pessoas</small></span><strong className={cap > 100 ? 'dn' : ''}>{cap}%</strong></div>
                    <Bar value={cap} tone={cap > 100 ? 'red' : cap > 90 ? 'amb' : 'green'} /></div>);
                })}</div></div>
            </div>
          </>
        );
      }}</Loader>
      {edit && fields.length > 0 && (
        <FormModal title={edit.id ? 'Editar colaborador' : 'Novo colaborador'} fields={fields} initial={edit} onClose={() => setEdit(null)}
          onSubmit={(v) => save(() => (edit.id ? api.put(`/employees/${edit.id}`, v) : api.post('/employees', v)), 'Colaborador guardado.', st.reload)} />
      )}
      {del && <Confirm message={`Eliminar ${del.name}?`} label="Eliminar" onClose={() => setDel(null)} onOk={() => act(() => api.del(`/employees/${del.id}`), 'Eliminado.', st.reload)} />}
    </>
  );
}

export function Operations({ user }) {
  const st = useApi('/processes');
  const actions = useApi('/process-actions');
  const emps = useApi('/employees');
  const [plan, setPlan] = useState(null);
  const act = useAction();
  const save = useSave();
  const w = can(user, 'processes');
  const reload = () => { st.reload(); actions.reload(); };
  return (
    <>
      <PageHead title="Operações" sub="Processos a decorrer e eficiência operacional" />
      <Loader state={st}>{(rows) => (
        <>
          <KpiRow items={[['Processos activos', rows.length, '#2563EB', 'ops'], ['Execução média', `${Math.round(rows.reduce((a, p) => a + p.execution, 0) / (rows.length || 1))}%`, '#16A34A', 'ops'],
            ['Em risco / atraso', rows.filter((p) => p.status !== 'No prazo').length, '#DC2626', 'ops'], ['SLA cumpridos', `${Math.round((rows.filter((p) => p.execution >= 85).length / (rows.length || 1)) * 100)}%`, '#7C3AED', 'ops']]} />
          <div className="panel tablewrap"><table><thead><tr><th>Processo</th><th>Empresa</th><th>Responsável</th><th>SLA</th><th>Execução</th><th>Estado</th><th /></tr></thead><tbody>
            {rows.map((p) => (
              <tr key={p.id}><td><strong>{p.name}</strong></td><td>{p.company?.name || 'Grupo'}</td><td>{p.owner.name}</td><td>{p.sla}</td>
                <td style={{ width: 130 }}><strong>{p.execution}%</strong><Bar value={p.execution} tone={p.execution < 70 ? 'red' : p.execution < 85 ? 'amb' : 'green'} /></td><td><Tag>{p.status}</Tag></td>
                <td>{w && <button className="btn sm ghost" onClick={() => setPlan(p)}>Melhorar</button>}</td></tr>
            ))}</tbody></table></div>
        </>
      )}</Loader>
      <Loader state={actions}>{(rows) => rows.some((a) => !a.done) && (
        <div className="panel" style={{ marginTop: 14 }}><div className="hd"><h3>Planos de melhoria em curso</h3></div><div className="tablewrap"><table><thead><tr><th>Processo</th><th>Acção</th><th>Responsável</th><th>Prazo</th><th /></tr></thead><tbody>
          {rows.filter((a) => !a.done).map((a) => (<tr key={a.id}><td>{a.process.name}</td><td>{a.action}</td><td>{a.owner.name}</td><td>{fdate(a.dueDate)}</td>
            <td>{w && <button className="btn sm ok" onClick={() => act(() => api.post(`/process-actions/${a.id}/done`), 'Acção concluída; execução actualizada.', reload)}>Concluir</button>}</td></tr>))}</tbody></table></div></div>
      )}</Loader>
      {plan && emps.data && (
        <FormModal title={`Plano de melhoria — ${plan.name}`} okLabel="Registar" onClose={() => setPlan(null)}
          fields={[{ k: 'action', l: 'Acção', req: true, wide: true, ph: 'Automatizar validação, reforçar equipa...' },
            { k: 'ownerId', l: 'Responsável', type: 'select', req: true, num: true, opts: emps.data.map((e) => ({ v: e.id, l: e.name })), def: emps.data[0]?.id }, { k: 'dueDate', l: 'Prazo', type: 'date', req: true }]}
          onSubmit={(v) => save(() => api.post(`/processes/${plan.id}/improve`, v), 'Plano registado. A execução sobe quando a acção for concluída.', reload)} />
      )}
    </>
  );
}

export function Courses({ user }) {
  const st = useApi('/courses');
  const emps = useApi('/employees');
  const [sel, setSel] = useState(null);
  const save = useSave();
  const w = can(user, 'courses');
  return (
    <>
      <PageHead title="Universidade Corporativa" sub="Formação interna e desenvolvimento de competências" />
      <Loader state={st}>{(rows) => (
        <div className="grid g3">{rows.map((c) => (
          <div className="panel" style={{ padding: 18 }} key={c.id}><h4 style={{ fontSize: 15.5 }}>{c.name}</h4><small style={{ color: 'var(--muted)' }}>{c.enrolled} inscritos</small>
            <div className="prog" style={{ marginTop: 14 }}><div className="t"><span>Conclusão média</span><strong>{c.avgCompletion}%</strong></div><Bar value={c.avgCompletion} tone={c.avgCompletion < 75 ? 'amb' : 'green'} /></div>
            {w && <button className="btn sm ghost" onClick={() => setSel(c)}>Inscrever colaborador</button>}</div>))}</div>
      )}</Loader>
      {sel && emps.data && (
        <FormModal title={`Inscrever em ${sel.name}`} okLabel="Inscrever" onClose={() => setSel(null)}
          fields={[{ k: 'employeeId', l: 'Colaborador', type: 'select', req: true, num: true, wide: true, opts: emps.data.map((e) => ({ v: e.id, l: e.name })), def: emps.data[0]?.id }]}
          onSubmit={(v) => save(() => api.post(`/courses/${sel.id}/enroll`, v), 'Colaborador inscrito.', st.reload)} />
      )}
    </>
  );
}
