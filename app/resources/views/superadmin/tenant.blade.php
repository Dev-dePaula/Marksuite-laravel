@extends('superadmin.layout')
@section('title',$t->name)
@section('content')
<p><a href="/superadmin/empresas">← Empresas</a></p>
<h2>{{ $t->name }} {!! $t->active ? '<span class="tag ok">Activa</span>' : '<span class="tag bad">Suspensa</span>' !!}</h2>
<div class="grid">@foreach ($metrics as $l => $v)<div class="kpi"><b>{{ $v }}</b><span>{{ $l }}</span></div>@endforeach</div>
<div class="cols">
<div class="card"><h3>Gestão</h3>
<form method="post" action="/superadmin/empresas/{{ $t->id }}" class="bar">@csrf<select name="plan">@foreach ($plans as $p)<option @selected($t->plan==$p)>{{ $p }}</option>@endforeach</select><button>Mudar plano</button></form>
<form method="post" action="/superadmin/empresas/{{ $t->id }}" class="bar">@csrf<input type="number" name="extend" min="1" max="365" value="30" style="width:80px"><button class="sec">Prolongar teste (dias)</button>
<span class="mut">Termina: {{ $t->trialEndsAt ? \Carbon\Carbon::parse($t->trialEndsAt)->format('d/m/Y') : '–' }}</span></form>
<form method="post" action="/superadmin/empresas/{{ $t->id }}">@csrf<input type="hidden" name="active" value="{{ $t->active ? 0 : 1 }}"><button class="{{ $t->active ? 'red' : '' }}">{{ $t->active ? 'Suspender empresa (termina sessões)' : 'Reactivar empresa' }}</button></form>
<p class="mut">Slug: {{ $t->slug }} · criada em {{ \Carbon\Carbon::parse($t->created_at)->format('d/m/Y H:i') }}</p></div>
<div class="card"><h3>Utilizadores ({{ $users->count() }})</h3><div class="tw"><table><tr><th>Nome</th><th>Perfil</th><th>2FA</th><th></th></tr>
@foreach ($users as $u)<tr><td>{{ $u->name }}{{ $u->isOwner ? ' ★' : '' }}<br><span class="mut">{{ $u->email }}</span></td><td>{{ $u->role }}</td><td>{{ $u->totpEnabled ? 'sim' : '–' }}</td>
<td>@include('superadmin.user-actions',['u'=>$u])</td></tr>@endforeach</table></div></div></div>
<div class="cols">
<div class="card"><h3>Actividade recente</h3><div class="tw"><table>@forelse ($activity as $a)<tr><td class="mut">{{ \Carbon\Carbon::parse($a->createdAt)->format('d/m H:i') }}</td><td>{{ $a->username }}</td><td>{{ $a->type }} {{ $a->path }}</td></tr>@empty<tr><td class="mut">Sem actividade.</td></tr>@endforelse</table></div></div>
<div class="card"><h3>Erros recentes</h3><div class="tw"><table>@forelse ($errorLogs as $e)<tr><td class="mut">{{ \Carbon\Carbon::parse($e->createdAt)->format('d/m H:i') }}</td><td>{{ $e->status }}</td><td>{{ \Illuminate\Support\Str::limit($e->message,80) }}</td></tr>@empty<tr><td class="mut">Sem erros.</td></tr>@endforelse</table></div></div></div>
<div class="card"><h3>Feedbacks desta empresa</h3>@forelse ($feedbacks as $f)<div><span class="tag">{{ $f->type }}</span> {{ $f->rating ? str_repeat('★',$f->rating) : '' }} {{ $f->message }} <span class="mut">— {{ $f->name }}</span></div>@empty<span class="mut">Nenhum.</span>@endforelse</div>
@endsection
