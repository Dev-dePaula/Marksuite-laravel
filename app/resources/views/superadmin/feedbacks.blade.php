@extends('superadmin.layout')
@section('title','Feedbacks')
@section('content')
<h2>Feedbacks dos clientes</h2>
<form class="bar" method="get"><select name="status"><option value="">Todos os estados</option>@foreach ($statuses as $s)<option @selected(request('status')==$s)>{{ $s }}</option>@endforeach</select>
<select name="type"><option value="">Todos os tipos</option>@foreach (['ELOGIO','SUGESTAO','PROBLEMA','OUTRO'] as $s)<option @selected(request('type')==$s)>{{ $s }}</option>@endforeach</select><button>Filtrar</button></form>
<p class="mut">Página pública: <b>/feedback</b> · API: <b>POST /api/feedback</b> (utilizador autenticado, associa a empresa) ou <b>POST /api/public/feedback</b>.</p>
<div class="card tw"><table><tr><th>Data</th><th>De</th><th>Tipo</th><th>Mensagem</th><th>Estado</th></tr>
@foreach ($rows as $f)<tr><td class="mut">{{ \Carbon\Carbon::parse($f->created_at)->format('d/m H:i') }}</td><td>{{ $f->name ?: '–' }}<br><span class="mut">{{ $f->email }}</span><br>{{ $f->tenant }}</td>
<td><span class="tag">{{ $f->type }}</span><br>{{ $f->rating ? str_repeat('★',$f->rating) : '' }}</td><td style="max-width:420px">{{ $f->message }}</td>
<td><form method="post" action="/superadmin/feedbacks/{{ $f->id }}">@csrf<select name="status">@foreach ($statuses as $s)<option @selected($f->status==$s)>{{ $s }}</option>@endforeach</select> <button class="sec">OK</button></form></td></tr>@endforeach</table></div>
<div class="pg">{{ $rows->links() }}</div>
@endsection
