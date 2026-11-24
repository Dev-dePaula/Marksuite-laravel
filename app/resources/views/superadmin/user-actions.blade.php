@foreach (['toggle' => ($u->active ? 'Desactivar' : 'Activar'), 'unlock' => 'Desbloquear', 'logout' => 'Terminar sessões', 'reset' => 'Nova senha'] as $a => $l)
<form class="inl" method="post" action="/superadmin/utilizadores/{{ $u->id }}">@csrf<input type="hidden" name="action" value="{{ $a }}"><button class="sec" style="padding:3px 8px;font-size:12px">{{ $l }}</button></form>
@endforeach
