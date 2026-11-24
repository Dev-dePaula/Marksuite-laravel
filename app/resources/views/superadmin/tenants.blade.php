@extends('superadmin.layout')
@section('title','Empresas')
@section('content')
<h2>Empresas</h2>
<form class="bar" method="get"><input name="q" value="{{ request('q') }}" placeholder="Nome, slug ou email"><select name="plan"><option value="">Todos os planos</option>@foreach ($plans as $p)<option @selected(request('plan')==$p)>{{ $p }}</option>@endforeach</select>
<select name="active"><option value="">Todas</option><option value="1" @selected(request('active')==='1')>Activas</option><option value="0" @selected(request('active')==='0')>Suspensas</option></select><button>Filtrar</button></form>
<div class="card tw"><table><tr><th>Empresa</th><th>Plano</th><th>Estado</th><th>Utilizadores</th><th>Teste até</th><th>Última actividade</th><th>Criada</th></tr>
@foreach ($rows as $t)<tr><td><a href="/superadmin/empresas/{{ $t->id }}">{{ $t->name }}</a></td><td><span class="tag">{{ $t->plan }}</span></td>
<td>{!! $t->active ? '<span class="ok">Activa</span>' : '<span class="bad">Suspensa</span>' !!}</td><td>{{ $t->users_count }}</td>
<td class="mut">{{ $t->trialEndsAt ? \Carbon\Carbon::parse($t->trialEndsAt)->format('d/m/Y') : '–' }}</td><td class="mut">{{ $t->last_activity ? \Carbon\Carbon::parse($t->last_activity)->diffForHumans() : '–' }}</td>
<td class="mut">{{ \Carbon\Carbon::parse($t->created_at)->format('d/m/Y') }}</td></tr>@endforeach</table></div>
<div class="pg">{{ $rows->links() }}</div>
@endsection
