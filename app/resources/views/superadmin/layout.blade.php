<!doctype html>
<html lang="pt">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>@yield('title', 'Super Admin') · MarkSuite</title>
<style>
:root{--bg:#f4f6fa;--card:#fff;--ink:#0f172a;--mut:#64748b;--line:#e2e8f0;--pri:#2563eb;--ok:#16a34a;--warn:#d97706;--bad:#dc2626}
@media (prefers-color-scheme:dark){:root{--bg:#0b1220;--card:#131c2e;--ink:#e5e7eb;--mut:#94a3b8;--line:#243049;--pri:#60a5fa}}
*{box-sizing:border-box}body{margin:0;font:14px/1.45 system-ui,Segoe UI,Roboto,sans-serif;background:var(--bg);color:var(--ink)}
a{color:var(--pri);text-decoration:none}a:hover{text-decoration:underline}
.wrap{display:flex;min-height:100vh}.side{width:210px;background:var(--card);border-right:1px solid var(--line);padding:16px 12px;position:sticky;top:0;height:100vh}
.side h1{font-size:15px;margin:0 8px 16px}.side a{display:block;padding:8px 10px;border-radius:8px;color:var(--ink);margin-bottom:2px}
.side a.on,.side a:hover{background:var(--bg);text-decoration:none}.side .out{margin-top:20px}
main{flex:1;padding:22px;max-width:1200px;min-width:0}h2{margin:0 0 14px;font-size:20px}h3{margin:0 0 10px;font-size:15px}
.grid{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));margin-bottom:16px}
.kpi,.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:14px}.kpi b{display:block;font-size:26px}.kpi span{color:var(--mut);font-size:12px}
.cols{display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));margin-bottom:14px}
table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:8px 6px;border-bottom:1px solid var(--line);vertical-align:top}th{color:var(--mut);font-size:12px;font-weight:600}
.tw{overflow-x:auto}.tag{display:inline-block;padding:2px 8px;border-radius:99px;font-size:11px;background:var(--bg);border:1px solid var(--line)}
.ok{color:var(--ok)}.bad{color:var(--bad)}.warn{color:var(--warn)}.mut{color:var(--mut)}
input,select,textarea,button{font:inherit;padding:7px 10px;border-radius:8px;border:1px solid var(--line);background:var(--card);color:var(--ink)}
textarea{width:100%}button,.btn{background:var(--pri);color:#fff;border:0;cursor:pointer;padding:7px 12px;border-radius:8px;display:inline-block}button.sec{background:var(--bg);color:var(--ink);border:1px solid var(--line)}button.red{background:var(--bad)}
form.inl{display:inline}.bar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}.flash{padding:10px 12px;border-radius:8px;margin-bottom:12px;background:#dcfce7;color:#14532d;word-break:break-all}.flash.err{background:#fee2e2;color:#7f1d1d}
.chart{display:flex;align-items:flex-end;gap:4px;height:110px}.chart div{flex:1;background:var(--pri);border-radius:4px 4px 0 0;min-height:2px;position:relative}.chart small{position:absolute;bottom:-18px;left:0;right:0;text-align:center;font-size:9px;color:var(--mut)}
.pg{margin-top:12px}.pg nav svg{width:16px}.pg nav a,.pg nav span{margin-right:6px}
@media(max-width:760px){.wrap{display:block}.side{width:auto;height:auto;position:static;display:flex;flex-wrap:wrap;gap:4px}.side h1{width:100%}}
</style></head><body>
@php $sa = request()->attributes->get('sa'); $cur = request()->segment(2) ?? ''; @endphp
<div class="wrap">
<nav class="side"><h1>MarkSuite · Super Admin</h1>
@foreach (['' => 'Visão geral', 'empresas' => 'Empresas', 'utilizadores' => 'Utilizadores', 'leads' => 'Leads', 'feedbacks' => 'Feedbacks', 'seguranca' => 'Segurança'] as $k => $l)
<a class="{{ $cur === $k ? 'on' : '' }}" href="/superadmin{{ $k ? '/'.$k : '' }}">{{ $l }}</a>
@endforeach
<div class="out mut" style="padding:0 10px;font-size:12px">{{ $sa->email }}</div>
<form method="post" action="/superadmin/logout" style="padding:6px 10px">@csrf<button class="sec">Sair</button></form></nav>
<main>
@if (session('ok'))<div class="flash">{{ session('ok') }}</div>@endif
@if (session('err'))<div class="flash err">{{ session('err') }}</div>@endif
@if ($errors->any())<div class="flash err">{{ $errors->first() }}</div>@endif
@yield('content')
</main></div></body></html>
