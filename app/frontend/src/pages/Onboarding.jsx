import React from 'react';
import { useApi } from '../ui.jsx';

/** Checklist de arranque: aparece no dashboard até a empresa preencher o essencial. */
export default function Onboarding({ go }) {
  const companies = useApi('/companies');
  const opps = useApi('/opportunities');
  const invoices = useApi('/invoices');
  const people = useApi('/employees');
  if ([companies, opps, invoices, people].some((s) => !s.data)) return null;

  const c = (companies.data || [])[0] || {};
  const steps = [
    ['Preencher os dados da empresa (NIF, morada, contactos, IBAN)', !!(c.nif && c.address), 'emp', 'Preencher'],
    ['Registar a equipa', (people.data || []).length > 0, 'pes', 'Adicionar'],
    ['Criar o primeiro cliente / oportunidade', (opps.data || []).length > 0, 'crm', 'Criar'],
    ['Emitir a primeira factura', (invoices.data || []).length > 0, 'fat', 'Emitir'],
    ['Ver o vídeo tutorial do sistema', false, 'tut', 'Ver tutorial'],
  ];
  const done = steps.filter((s) => s[1]).length;
  if (steps.slice(0, 4).every((s) => s[1])) return null;

  return (
    <div className="panel" style={{ marginTop: 14 }}>
      <div className="hd"><h3>Primeiros passos</h3><div className="spacer" /><small style={{ color: 'var(--muted)' }}>{done} de {steps.length - 1} concluídos</small></div>
      <div className="bd" style={{ display: 'grid', gap: 8 }}>
        <p style={{ margin: 0, color: 'var(--muted)', fontSize: 13 }}>A sua conta está vazia: preencha com os dados reais da sua empresa para começar.</p>
        {steps.map(([label, ok, to, cta]) => (
          <div key={to} style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
            <span aria-hidden style={{ width: 20, height: 20, borderRadius: '50%', display: 'grid', placeItems: 'center', fontSize: 12, color: '#fff', background: ok ? '#16A34A' : 'var(--line)' }}>{ok ? '✓' : ''}</span>
            <span style={{ flex: 1, textDecoration: ok ? 'line-through' : 'none', opacity: ok ? .6 : 1 }}>{label}</span>
            {!ok && <button className="btn sm soft" onClick={() => go(to)}>{cta}</button>}
          </div>
        ))}
      </div>
    </div>
  );
}
