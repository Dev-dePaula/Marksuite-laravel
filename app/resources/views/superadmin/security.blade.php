@extends('superadmin.layout')
@section('title','Segurança')
@section('content')
<h2>Segurança</h2>
<div class="cols">
<div class="card"><h3>IPs bloqueados agora</h3><div class="tw"><table>@forelse ($blocks as $b)<tr><td>{{ $b->ip }}</td><td class="mut">{{ $b->reason }}<br>até {{ \Carbon\Carbon::parse($b->until)->format('d/m H:i') }}</td>
<td><form method="post" action="/superadmin/seguranca/desbloquear">@csrf<input type="hidden" name="ip" value="{{ $b->ip }}"><button class="sec">Desbloquear</button></form></td></tr>@empty<tr><td class="mut">Nenhum.</td></tr>@endforelse</table></div></div>
<div class="card"><h3>Eventos nas últimas 24h</h3>@forelse ($summary as $s)<div>{{ $s->type }} <b>{{ $s->n }}</b></div>@empty<span class="mut">Sem eventos.</span>@endforelse</div></div>
<div class="card tw"><h3>Últimos eventos</h3><table><tr><th>Data</th><th>Tipo</th><th>IP</th><th>Utilizador</th><th>Detalhe</th></tr>
@foreach ($events as $e)<tr><td class="mut">{{ \Carbon\Carbon::parse($e->createdAt)->format('d/m H:i:s') }}</td><td>{{ $e->type }}</td><td>{{ $e->ip }}</td><td>{{ $e->username }}</td><td class="mut">{{ $e->detail }}</td></tr>@endforeach</table></div>
@endsection
