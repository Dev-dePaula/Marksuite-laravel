@extends('superadmin.layout')
@section('title','Visão geral')
@section('content')
<h2>Visão geral da plataforma</h2>
<div class="grid">
@foreach ([['Empresas',$stats['tenants']],['Activas',$stats['active']],['Suspensas',$stats['suspended']],['Em teste',$stats['trial']],['Planos pagos',$stats['paid']],['Utilizadores',$stats['users']],['Novas (7 dias)',$stats['new7']],['Activas nas últimas 24h',$stats['active24']],['Leads novos',$stats['leads']],['Feedbacks novos',$stats['feedbacks']],['Nota média',$stats['rating'] ?: '–']] as [$l,$v])
<div class="kpi"><b>{{ $v }}</b><span>{{ $l }}</span></div>
@endforeach
</div>
<div class="card" style="margin-bottom:14px"><h3>Novas empresas — últimos 14 dias</h3>
<div class="chart" style="margin-bottom:22px">@foreach ($days as $d)<div style="height:{{ max(2, $d['n']/$max*100) }}%" title="{{ $d['n'] }}"><small>{{ $d['label'] }}</small></div>@endforeach</div></div>
<div class="cols">
<div class="card"><h3>Últimas empresas registadas</h3><div class="tw"><table><tr><th>Empresa</th><th>Plano</th><th>Criada</th></tr>
@foreach ($recent as $t)<tr><td><a href="/superadmin/empresas/{{ $t->id }}">{{ $t->name }}</a></td><td><span class="tag">{{ $t->plan }}</span></td><td class="mut">{{ \Carbon\Carbon::parse($t->created_at)->format('d/m H:i') }}</td></tr>@endforeach</table></div></div>
<div class="card"><h3>Testes a expirar (7 dias)</h3>@forelse ($expiring as $t)<div><a href="/superadmin/empresas/{{ $t->id }}">{{ $t->name }}</a> <span class="warn">{{ \Carbon\Carbon::parse($t->trialEndsAt)->format('d/m') }}</span></div>@empty<span class="mut">Nenhum.</span>@endforelse</div>
<div class="card"><h3>Últimos leads</h3>@forelse ($leads as $l)<div><b>{{ $l->name }}</b> <span class="mut">{{ $l->email }}</span> <span class="tag">{{ $l->source }}</span></div>@empty<span class="mut">Sem leads.</span>@endforelse<p><a href="/superadmin/leads">Ver todos →</a></p></div>
<div class="card"><h3>Últimos feedbacks</h3>@forelse ($feedbacks as $f)<div><span class="tag">{{ $f->type }}</span> {{ $f->rating ? str_repeat('★',$f->rating) : '' }} {{ \Illuminate\Support\Str::limit($f->message,70) }}</div>@empty<span class="mut">Sem feedbacks.</span>@endforelse<p><a href="/superadmin/feedbacks">Ver todos →</a></p></div>
</div>
<div class="card"><h3>Alterar a minha palavra-passe</h3><form method="post" action="/superadmin/password" class="bar">@csrf
<input type="password" name="current" placeholder="Actual" required autocomplete="current-password"><input type="password" name="password" placeholder="Nova (10+ caracteres)" required autocomplete="new-password"><button>Guardar</button></form></div>
@endsection
