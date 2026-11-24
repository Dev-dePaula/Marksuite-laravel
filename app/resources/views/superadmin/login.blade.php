<!doctype html><html lang="pt"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Entrar · Super Admin</title>
<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0b1220;font:15px system-ui,sans-serif;color:#e5e7eb}
form{background:#131c2e;padding:28px;border-radius:14px;width:min(360px,92vw);border:1px solid #243049}h1{margin:0 0 18px;font-size:19px}
input{width:100%;margin:6px 0 14px;padding:10px;border-radius:8px;border:1px solid #243049;background:#0b1220;color:#e5e7eb;font:inherit}
button{width:100%;padding:11px;border:0;border-radius:8px;background:#2563eb;color:#fff;font:inherit;cursor:pointer}.e{background:#7f1d1d;padding:8px 10px;border-radius:8px;margin-bottom:12px;font-size:13px}</style></head>
<body><form method="post" action="/superadmin/login">@csrf<h1>MarkSuite · Super Admin</h1>
@if ($errors->any())<div class="e">{{ $errors->first() }}</div>@endif
<label>Email<input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"></label>
<label>Palavra-passe<input type="password" name="password" required autocomplete="current-password"></label>
<button>Entrar</button></form></body></html>
