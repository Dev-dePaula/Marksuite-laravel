@extends('superadmin.layout')
@section('title','Leads')
@section('content')
<h2>Leads</h2>
<form class="bar" method="get"><input name="q" value="{{ request('q') }}" placeholder="Nome, email ou empresa"><select name="status"><option value="">Todos</option>@foreach ($statuses as $s)<option @selected(request('status')==$s)>{{ $s }}</option>@endforeach</select><button>Filtrar</button>
<a class="btn" href="/superadmin/leads?export=csv&status={{ request('status') }}&q={{ request('q') }}">Exportar CSV</a></form>
<p class="mut">Formulário público para divulgar: <b>/contacto</b> · API: <b>POST /api/public/lead</b> · cada novo registo no ERP também entra aqui (origem "registo").</p>
<div class="card tw"><table><tr><th>Data</th><th>Contacto</th><th>Empresa / mensagem</th><th>Origem</th><th>Estado e notas</th></tr>
@foreach ($rows as $l)<tr><td class="mut">{{ \Carbon\Carbon::parse($l->created_at)->format('d/m H:i') }}</td><td><b>{{ $l->name }}</b><br>{{ $l->email }}<br>{{ $l->phone }}</td><td>{{ $l->company }}<br><span class="mut">{{ $l->message }}</span></td><td><span class="tag">{{ $l->source }}</span></td>
<td><form method="post" action="/superadmin/leads/{{ $l->id }}">@csrf<select name="status">@foreach ($statuses as $s)<option @selected($l->status==$s)>{{ $s }}</option>@endforeach</select> <button class="sec">Guardar</button><br><textarea name="notes" rows="2" placeholder="Notas">{{ $l->notes }}</textarea></form></td></tr>@endforeach</table></div>
<div class="pg">{{ $rows->links() }}</div>
@endsection
