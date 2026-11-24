@extends('public.layout')
@section('title','Fale connosco')
@section('content')
<h1>Fale connosco</h1><p>Deixe os seus dados e a nossa equipa entra em contacto.</p>
<form method="post" action="/contacto">@csrf<input type="text" name="website" class="hp" tabindex="-1" autocomplete="off">
<label>Nome<input name="name" required maxlength="120" value="{{ old('name') }}"></label><label>Email<input type="email" name="email" required value="{{ old('email') }}"></label>
<label>Telefone / WhatsApp<input name="phone" maxlength="50" value="{{ old('phone') }}"></label><label>Empresa<input name="company" maxlength="120" value="{{ old('company') }}"></label>
<label>Mensagem<textarea name="message" rows="4" maxlength="1000">{{ old('message') }}</textarea></label><button>Enviar</button></form>
@endsection
