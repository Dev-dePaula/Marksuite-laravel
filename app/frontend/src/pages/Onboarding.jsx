import React, { useState } from 'react';
import { FormModal, can, useApi, useSave } from '../ui.jsx';
import { api } from '../api.js';
import { REGIMES } from './billing/lib.js';

/** Checklist de arranque: aparece no dashboard até a empresa preencher o essencial. */
export default function Onboarding({ go, user }) {
  const companies = useApi('/companies');
  const opps = useApi('/opportunities');
  const invoices = useApi('/invoices');
  const people = useApi('/employees');
  const [form, setForm] = useState(false);
  const save = useSave();
  if ([companies, opps, invoices, people].some((s) => !s.data)) return null;

  const c = (companies.data || [])[0] || {};
  const contacts = !!(c.email || c.phone);
  const dataOk = !!(c.nif && c.address && contacts && c.iban);
  const steps = [
    ['Preencher os dados da empresa (NIF, morada, contactos, IBAN)', dataOk, () => (can(user, 'billing') ? setForm(true) : go('emp')), 'Preencher'],
    ['Registar a equipa', (people.data || []).length > 0, () => go('pes'), 'Adicionar'],
    ['Criar o primeiro cliente / oportunidade', (opps.data || []).length > 0, () => go('crm'), 'Criar'],
    ['Emitir a primeira factura', (invoices.data || []).length > 0, () => go('fat'), 'Emitir'],
    ['Ver o vídeo tutorial do sistema', false, () => go('tut'), 'Ver tutorial'],
  ];
  const done = steps.filter((s) => s[1]).length;
  if (steps.slice(0, 4).every((s) => s[1])) return null;

  return (
    <div className="panel" style={{ marginTop: 14 }}>
      <div className="hd"><h3>Primeiros passos</h3><div className="spacer" /><small style={{ color: 'var(--muted)' }}>{done} de {steps.length - 1} concluídos</small></div>
      <div className="bd" style={{ display: 'grid', gap: 8 }}>
        <p style={{ margin: 0, color: 'var(--muted)', fontSize: 13 }}>A sua conta está vazia: preencha com os dados reais da sua empresa para começar.</p>
        {steps.map(([label, ok, run, cta]) => (
          <div key={label} style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
            <span aria-hidden style={{ width: 20, height: 20, borderRadius: '50%', display: 'grid', placeItems: 'center', fontSize: 12, color: '#fff', background: ok ? '#16A34A' : 'var(--line)' }}>{ok ? '✓' : ''}</span>
            <span style={{ flex: 1, textDecoration: ok ? 'line-through' : 'none', opacity: ok ? .6 : 1 }}>{label}</span>
            {ok && cta === 'Preencher' ? <button className="btn sm soft" onClick={run}>Editar</button> : !ok && <button className="btn sm soft" onClick={run}>{cta}</button>}
          </div>
        ))}
      </div>
      {form && <FormModal title={`Dados da empresa — ${c.name}`} onClose={() => setForm(false)}
        fields={[{ k: 'nif', l: 'NIF', req: true }, { k: 'address', l: 'Morada', req: true, wide: true },
          { k: 'email', l: 'Email de contacto', type: 'email', req: true }, { k: 'phone', l: 'Telefone', req: true },
          { k: 'iban', l: 'IBAN', req: true, wide: true }, { k: 'website', l: 'Website', nullable: true },
          { k: 'ivaRegime', l: 'Regime de IVA', type: 'select', req: true, opts: REGIMES, def: c.ivaRegime || 'geral' }]}
        initial={{ nif: c.nif || '', address: c.address || '', email: c.email || '', phone: c.phone || '', iban: c.iban || '', website: c.website || '', ivaRegime: c.ivaRegime || 'geral' }}
        onSubmit={(v) => save(() => api.put(`/companies/${c.id}/fiscal`, v), 'Dados da empresa guardados.', companies.reload)} />}
    </div>
  );
}
