<?php
/**
 * /discord/start.php — początek OAuth2 z Discord.
 */

declare(strict_types=1);

$__dir = __DIR__;
$__boot = null;
foreach ([$__dir . '/../inc/bootstrap.php', $__dir . '/../../inc/bootstrap.php',
          $__dir . '/../inc/config.local.php', $__dir . '/../../inc/config.local.php'] as $c) {
    if (is_file($c)) { $__boot = $c; break; }
}
if ($__boot === null || !str_ends_with($__boot, 'bootstrap.php')) {
    http_response_code(500);
    exit('Brak pliku inc/bootstrap.php obok katalogu discord.');
}
require $__boot;

$clientId = (string) cfg('discord.client_id');
if ($clientId === '') {
    oauth_fail('Discord nie jest jeszcze skonfigurowany.',
        'Brak <code>client_id</code> w <code>inc/config.local.php</code>. '
      . 'Wyklikaj aplikację OAuth i wklej <b>Client ID</b> oraz <b>Client Secret</b>.');
}

$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state_discord'] = $state;

$q = http_build_query([
    'client_id'     => $clientId,
    'redirect_uri'  => (string) cfg('discord.redirect'),
    'response_type' => 'code',
    'scope'         => (string) cfg('discord.scope'),
    'state'         => $state,
]);

header('Location: https://discord.com/oauth2/authorize?' . $q);
exit;

/** Czytelny błąd zamiast pustej strony 500. */
function oauth_fail(string $title, string $hint): never
{
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title>'
       . '<body style="font:16px/1.6 system-ui;background:#0e0c12;color:#ece6f2;padding:44px;max-width:640px;margin:auto">'
       . '<div style="font-size:12px;letter-spacing:2px;text-transform:uppercase;color:#8c6f2e">Cesarstwo Playera</div>'
       . '<h1 style="color:#d4a94a">' . htmlspecialchars($title) . '</h1>'
       . '<p>' . $hint . '</p>'
       . '<p style="font-size:13px;color:#9a90ab">Potrzebujesz pomocy? Zajrzyj do '
       . '<a style="color:#d4a94a" href="/api/health.php">diagnostyki</a>.</p>'
       . '<p><a style="color:#d4a94a" href="/">Wróć na stronę</a></p>';
    exit;
}
