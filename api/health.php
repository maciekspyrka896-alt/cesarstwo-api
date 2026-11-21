<?php
/**
 * /api/health.php — diagnostyka. Otwórz w przeglądarce, gdy coś nie działa.
 * Nie zdradza sekretów: pokazuje tylko, CZY dana rzecz jest ustawiona.
 */

declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';

$out = [
    'php'        => PHP_VERSION,
    'sapi'       => PHP_SAPI,
    'pdo_drivers' => PDO::getAvailableDrivers(),
    'extensions' => [
        'openssl'  => extension_loaded('openssl'),
        'curl'     => extension_loaded('curl'),
        'mbstring' => extension_loaded('mbstring'),
        'gd'       => extension_loaded('gd'),
        'json'     => extension_loaded('json'),
        'pgsql'    => extension_loaded('pgsql'),
    ],
    'config' => [
        'file'        => basename((string) ($GLOBALS['CP_CONFIG_FILE'] ?? 'config.local.php')),
        'db_user'     => cfg('db.user') ?: '(brak)',
        'db_dsn_host' => parse_url('pgsql://' . explode(';', (string) cfg('db.dsn'))[0] . '')['host'] ?? '?',
        'aes_key_ok'  => strlen((string) base64_decode((string) cfg('app.aes_key'), true)) === 32,
        'pepper_ok'   => strlen((string) cfg('app.pepper4fa')) >= 16,
        'google_oauth'  => cfg('google.client_id') !== '',
        'discord_oauth' => cfg('discord.client_id') !== '',
        'gmail_api'     => cfg('mail.refresh_token') !== '',
        'cheeser_ai'    => cfg('cheeser.api_token') !== '',
    ],
];

// ---- baza ----
try {
    $pdo = db(true);   // true = rzuć wyjątek, nie kończ żądania
    $ver = $pdo->query('select current_database(), version()')->fetch();
    $tables = (int) $pdo->query(
        "select count(*) from information_schema.tables where table_schema = 'public'"
    )->fetchColumn();
    $accounts = (int) $pdo->query('select count(*) from accounts')->fetchColumn();
    $tos = $pdo->query('select version from tos_versions order by published_at desc limit 1')->fetchColumn();

    $out['database'] = [
        'connected'   => true,
        'database'    => $ver[0] ?? '?',
        'pg_version'  => mb_substr((string) ($ver[1] ?? ''), 0, 40),
        'dsn_used'    => preg_replace('/password=\S+/', 'password=***', (string) ($GLOBALS['CP_DB_DSN_OK'] ?? '')),
        'tables'      => $tables,
        'accounts'    => $accounts,
        'tos_version' => $tos ?: null,
    ];
} catch (Throwable $e) {
    $out['database'] = [
        'connected' => false,
        'error'     => $GLOBALS['CP_DB_ERROR'] ?? $e->getMessage(),
        'hint'      => 'Jeśli brakuje pdo_pgsql, hosting nie obsłuży Supabase po Postgresie '
                     . '— potrzebny inny hosting dla katalogu api/inc albo warstwa REST.',
    ];
}

// ---- katalogi ----
$out['writable'] = [
    'uploads'         => is_writable(upload_dir()) ?: false,
    'uploads/surveys' => is_writable(upload_dir('surveys')) ?: false,
    'uploads/avatars' => is_writable(upload_dir('avatars')) ?: false,
];

// ---- tryb poczty: log + ostatnie wiadomości ----
$out['mail'] = ['driver' => cfg('mail.driver', '?')];
if (cfg('mail.driver') === 'log' && cfg('debug_mode', false) && function_exists('mail_recent')) {
    $out['mail']['recent'] = mail_recent(5);
}

// ---- podsumowanie ----
$problems = [];
if (!in_array('pgsql', $out['pdo_drivers'], true)) {
    $problems[] = 'BRAK pdo_pgsql. InfinityFree udostępnia ten sterownik — trzeba go WŁĄCZYĆ: '
        . 'Panel → PHP Configuration → rozszerzenia → zaznacz pdo_pgsql (i pgsql) → Save. '
        . 'Po zmianie odśwież tę stronę.';
}
if (empty($out['database']['connected'])) {
    $problems[] = 'Baza nieosiągalna: ' . ($out['database']['error'] ?? 'nieznany błąd');
}
if (!$out['config']['google_oauth'])  { $problems[] = 'Google OAuth: brak client_id (logowanie Google zwróci 503).'; }
if (!$out['config']['discord_oauth']) { $problems[] = 'Discord OAuth: brak client_id (logowanie Discord zwróci 503).'; }
if (!$out['config']['gmail_api'])     { $problems[] = 'Gmail API: brak refresh_token — e-maile 2FA nie wyjdą.'; }
if (!$out['config']['cheeser_ai'])    { $problems[] = 'Cheeser AI: brak tokenu — czat zwraca zaślepkę.'; }

$out['problems'] = $problems;
$out['ok'] = $problems === [];

if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'json')) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
$esc = fn($v) => htmlspecialchars(is_bool($v) ? ($v ? 'tak' : 'nie') : (string) $v, ENT_QUOTES);
?>
<!doctype html>
<html lang="pl"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Diagnostyka — Cesarstwo Playera</title>
<style>
  body{margin:0;background:#0e0c12;color:#ece6f2;font:15px/1.6 "Segoe UI",system-ui,sans-serif;padding:32px 16px}
  .wrap{max-width:760px;margin:auto}
  h1{font-size:1.5rem;margin:0 0 4px}
  .kicker{font-size:11.5px;letter-spacing:1.6px;text-transform:uppercase;color:#8c6f2e;margin-bottom:14px}
  .card{background:#171320;border:1px solid #2e2639;border-radius:14px;padding:16px;margin-bottom:14px}
  h2{font-size:1rem;margin:0 0 10px;color:#d4a94a}
  table{width:100%;border-collapse:collapse;font-size:14px}
  td{padding:6px 0;border-bottom:1px solid #241d31}
  td:first-child{color:#9a90ab;width:44%}
  .ok{color:#3fae72}.bad{color:#c8484f}.warn{color:#d98c31}
  ul{margin:8px 0 0;padding-left:20px}
  li{margin-bottom:6px}
  code{background:#100d16;border:1px solid #2e2639;border-radius:5px;padding:1px 5px;font-size:13px}
</style></head>
<body><div class="wrap">
  <div class="kicker">Cesarstwo Playera</div>
  <h1>Diagnostyka</h1>
  <p style="color:#9a90ab;margin-top:0">Otwórz tę stronę, gdy cokolwiek zwraca błąd 500 lub 503.</p>

  <div class="card">
    <h2>Status ogólny</h2>
    <?php if ($out['ok']): ?>
      <p class="ok">Wszystko skonfigurowane.</p>
    <?php else: ?>
      <p class="bad">Wykryto <?= count($problems) ?> problem(y):</p>
      <ul><?php foreach ($problems as $p): ?><li><?= $esc($p) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Baza danych</h2>
    <table>
      <?php foreach (($out['database'] ?? []) as $k => $v): ?>
        <tr><td><?= $esc($k) ?></td><td><?= $esc(is_scalar($v) ? $v : json_encode($v)) ?></td></tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="card">
    <h2>PHP</h2>
    <table>
      <tr><td>wersja</td><td><?= $esc($out['php']) ?></td></tr>
      <tr><td>sterowniki PDO</td><td><?= $esc(implode(', ', $out['pdo_drivers']) ?: 'brak') ?></td></tr>
      <?php foreach ($out['extensions'] as $k => $v): ?>
        <tr><td>rozszerzenie <?= $esc($k) ?></td>
          <td class="<?= $v ? 'ok' : 'bad' ?>"><?= $esc($v) ?></td></tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="card">
    <h2>Konfiguracja</h2>
    <table>
      <?php foreach ($out['config'] as $k => $v): ?>
        <tr><td><?= $esc($k) ?></td>
          <td class="<?= ($v === false || $v === '' || $v === '(brak)') ? 'warn' : '' ?>"><?= $esc($v) ?></td></tr>
      <?php endforeach; ?>
    </table>
  </div>

  <?php if (!empty($out['mail']['recent'])): ?>
  <div class="card">
    <h2>Ostatnie wiadomości (tryb log)</h2>
    <p style="color:#9a90ab;font-size:13px;margin-top:0">
      Poczta nie jest wysyłana — kody poniżej działają w formularzach.</p>
    <?php foreach ($out['mail']['recent'] as $m): ?>
      <div style="border:1px solid #241d31;border-radius:10px;padding:10px;margin-bottom:8px">
        <div style="font-size:13px"><b><?= $esc($m['subject'] ?? '') ?></b>
          <span style="color:#9a90ab"> → <?= $esc($m['to'] ?? '') ?></span></div>
        <?php if (!empty($m['codes'])): ?>
          <div style="margin-top:6px">kody: <b style="color:#d4a94a;letter-spacing:3px"><?= $esc(implode('  ', $m['codes'])) ?></b></div>
        <?php endif; ?>
        <div style="font-size:12px;color:#9a90ab;margin-top:4px"><?= $esc($m['at'] ?? '') ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="card">
    <h2>Katalogi</h2>
    <table>
      <?php foreach ($out['writable'] as $k => $v): ?>
        <tr><td><?= $esc($k) ?></td><td class="<?= $v ? 'ok' : 'bad' ?>"><?= $esc($v) ?></td></tr>
      <?php endforeach; ?>
    </table>
  </div>
</div></body></html>
