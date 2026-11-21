<?php
/**
 * /reset.php?t=TOKEN — ustawianie nowego hasła z linku.
 */

declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/auth.php';

$t = (string) ($_GET['t'] ?? '');
if ($t === '') { html_fail('Brak tokenu w linku.'); }

// token sprawdzamy "na sucho" — nie zużywamy go, dopóki hasło nie zostanie ustawione
$st = db()->prepare(
    "select account_id from one_time_tokens
      where purpose = 'reset_password' and token_hash = :h
        and used_at is null and expires_at > now() limit 1"
);
$st->execute([':h' => sha($t)]);
$row = $st->fetch();
if (!$row) { html_fail('Link nieprawidłowy lub wygasł.', 410); }

$token = htmlspecialchars($t, ENT_QUOTES);
?>
<!doctype html>
<html lang="pl"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Nowe hasło — Cesarstwo Playera</title>
<style>
  body{margin:0;background:#0e0c12;color:#ece6f2;font:15px/1.55 "Segoe UI",system-ui,sans-serif;
       display:grid;place-items:center;min-height:100vh;padding:20px}
  .card{background:linear-gradient(180deg,#171320,#151220);border:1px solid #2e2639;
        border-radius:16px;padding:24px;max-width:460px;width:100%}
  h1{font-size:1.4rem;margin:0 0 6px}
  .kicker{font-size:11.5px;letter-spacing:1.6px;text-transform:uppercase;color:#8c6f2e}
  label{display:block;font-size:13px;color:#9a90ab;margin:14px 0 5px}
  input{width:100%;box-sizing:border-box;background:#100d16;border:1px solid #2e2639;
        color:#ece6f2;border-radius:10px;padding:11px 13px;font:inherit}
  input:focus{outline:none;border-color:#8c6f2e}
  button{margin-top:18px;width:100%;background:linear-gradient(180deg,#e6c976,#c9a03f);
         color:#1a1520;border:0;padding:12px;border-radius:10px;font:inherit;font-weight:700;cursor:pointer}
  .hint{font-size:12px;color:#9a90ab;margin-top:10px}
  .msg{margin-top:14px;font-size:13.5px}
</style></head>
<body>
<div class="card">
  <div class="kicker">Cesarstwo Playera</div>
  <h1>Ustaw nowe hasło</h1>
  <p class="hint">8–32 znaki, mała i wielka litera, cyfra, znak specjalny.</p>
  <form id="f">
    <label>Nowe hasło</label>
    <input type="password" id="p1" autocomplete="new-password" required>
    <label>Powtórz hasło</label>
    <input type="password" id="p2" autocomplete="new-password" required>
    <button type="submit">Zmień hasło</button>
  </form>
  <div class="msg" id="msg"></div>
</div>
<script>
const TOKEN = <?= json_encode($t) ?>;
const msg = document.getElementById('msg');
document.getElementById('f').addEventListener('submit', async (e) => {
  e.preventDefault();
  const p1 = document.getElementById('p1').value, p2 = document.getElementById('p2').value;
  if (p1 !== p2) { msg.textContent = 'Hasła się różnią.'; msg.style.color = '#c8484f'; return; }
  msg.textContent = 'Zapisywanie…'; msg.style.color = '#9a90ab';
  try {
    const r = await fetch('/api/auth.php?action=password_reset_finish', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token: TOKEN, password: p1, password2: p2 })
    });
    const j = await r.json();
    msg.textContent = j.ok ? j.message : (j.error || 'Błąd.');
    msg.style.color = j.ok ? '#3fae72' : '#c8484f';
    if (j.ok) setTimeout(() => location.href = '/', 1600);
  } catch (err) {
    msg.textContent = 'Błąd połączenia.'; msg.style.color = '#c8484f';
  }
});
</script>
</body></html>
