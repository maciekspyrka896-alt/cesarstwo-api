<?php
/**
 * /discord/callback.php — odbiór kodu OAuth2 z Discorda.
 *
 * Logowanie przez Discorda: jeśli konto już istnieje -> sesja.
 * Nowe konto -> status 'pending', etap 2 (użytkownik MUSI podać e-mail,
 * bo Discord nie zawsze go zwraca; bez Gmaila jest ryzyko utraty konta).
 */

declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/auth.php';

$code  = (string) ($_GET['code'] ?? '');
$state = (string) ($_GET['state'] ?? '');

// Discord potrafi odesłać błąd zamiast kodu — pokazujemy go wprost.
if (!empty($_GET['error'])) {
    $reason = (string) ($_GET['error_description'] ?? $_GET['error']);
    html_fail('Discord odrzucił logowanie: ' . $reason, 400);
}

if ($code === '') {
    html_fail('Brak kodu w adresie powrotnym. Otwórz logowanie od początku: /discord/start.php', 400);
}

if (empty($_SESSION['oauth_state']) || !hash_equals($_SESSION['oauth_state'], $state)) {
    $hasState = empty($_SESSION['oauth_state']) ? 'brak w sesji' : 'inny';
    html_fail('Nieprawidłowy stan OAuth (' . $hasState . '). '
        . 'Najczęściej znaczy to, że wracasz z innej przeglądarki albo sesja wygasła — '
        . 'odśwież stronę i kliknij logowanie ponownie.', 400);
}
unset($_SESSION['oauth_state']);

// 1. wymiana kodu na token
$ch = curl_init('https://discord.com/api/oauth2/token');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_POSTFIELDS     => http_build_query([
        'client_id'     => cfg('discord.client_id'),
        'client_secret' => cfg('discord.client_secret'),
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => cfg('discord.redirect'),
    ]),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
]);
$tok = json_decode((string) curl_exec($ch), true);
curl_close($ch);

if (empty($tok['access_token'])) {
    html_fail('Discord nie zwrócił tokenu. Spróbuj ponownie.', 502);
}

// 2. dane użytkownika
$ch = curl_init('https://discord.com/api/users/@me');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $tok['access_token']],
]);
$du = json_decode((string) curl_exec($ch), true);
curl_close($ch);

if (empty($du['id'])) { html_fail('Nie udało się pobrać profilu Discorda.', 502); }

$discordId = (string) $du['id'];
$discordMail = isset($du['email']) && is_string($du['email']) ? $du['email'] : null;

// 3. istniejące konto?
$st = db()->prepare('select * from accounts where discord_id = :d limit 1');
$st->execute([':d' => $discordId]);
$acc = $st->fetch();

if (!$acc && $discordMail !== null) {
    $st = db()->prepare('select * from accounts where lower(email) = lower(:e) limit 1');
    $st->execute([':e' => $discordMail]);
    $acc = $st->fetch();
    if ($acc) {
        db()->prepare('update accounts set discord_id = :d where id = :i')
            ->execute([':d' => $discordId, ':i' => $acc['id']]);
    }
}

if ($acc) {
    if ($acc['status'] === 'banned') {
        html_fail('Konto zbanowane: ' . ($acc['ban_reason'] ?? 'naruszenie regulaminu'), 403);
    }
    if ($acc['status'] === 'pending' || empty($acc['username'])) {
        create_session((string) $acc['id'], 1);
        front_redirect('/?krok=profil&zrodlo=discord');
    }
    create_session((string) $acc['id'], 2);
    log_ip((string) $acc['id'], 'login_discord');
    front_redirect('/');
}

// 4. nowe konto przez Discorda
$r = register_start($discordMail, $discordId, null);
if (empty($r['ok'])) { html_fail($r['error'] ?? 'Nie udało się utworzyć konta.', 422); }

create_session((string) $r['account_id'], 1);
front_redirect('/?krok=haslo&zrodlo=discord');
