@extends('public.layout')
@section('title','Feedback')
@section('content')
<h1>A sua opinião</h1><p>Diga-nos o que está bom, o que podemos melhorar ou reporte um problema.</p>
<form method="post" action="/feedback">@csrf<input type="text" name="website" class="hp" tabindex="-1" autocomplete="off">
<label>Tipo<select name="type"><option value="SUGESTAO">Sugestão</option><option value="PROBLEMA">Problema</option><option value="ELOGIO">Elogio</option><option value="OUTRO">Outro</option></select></label>
<label>Nota<select name="rating"><option value="">–</option>@foreach ([5,4,3,2,1] as $n)<option value="{{ $n }}">{{ str_repeat('★',$n) }}</option>@endforeach</select></label>
<label>Nome<input name="name" maxlength="120"></label><label>Email (para lhe respondermos)<input type="email" name="email"></label>
<label>Mensagem<textarea name="message" rows="5" required maxlength="3000"></textarea></label><button>Enviar feedback</button></form>
@endsection
