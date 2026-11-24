@extends('superadmin.layout')
@section('title','Utilizadores')
@section('content')
<h2>Utilizadores de todas as empresas</h2>
<form class="bar" method="get"><input name="q" value="{{ request('q') }}" placeholder="Nome, email ou empresa"><button>Pesquisar</button></form>
<div class="card tw"><table><tr><th>Utilizador</th><th>Empresa</th><th>Perfil</th><th>Estado</th><th>Criado</th><th>Acções</th></tr>
@foreach ($rows as $u)<tr><td>{{ $u->name }}{{ $u->isOwner ? ' ★' : '' }}<br><span class="mut">{{ $u->email }}</span></td><td><a href="/superadmin/empresas/{{ $u->tid }}">{{ $u->tenant }}</a></td><td>{{ $u->role }}{{ $u->totpEnabled ? ' · 2FA' : '' }}</td>
<td>{!! $u->active ? '<span class="ok">Activo</span>' : '<span class="bad">Inactivo</span>' !!}{!! $u->lockedUntil && \Carbon\Carbon::parse($u->lockedUntil)->isFuture() ? ' <span class="warn">bloqueado</span>' : '' !!}</td>
<td class="mut">{{ \Carbon\Carbon::parse($u->created_at)->format('d/m/Y') }}</td><td>@include('superadmin.user-actions',['u'=>$u])</td></tr>@endforeach</table></div>
<div class="pg">{{ $rows->links() }}</div>
@endsection
