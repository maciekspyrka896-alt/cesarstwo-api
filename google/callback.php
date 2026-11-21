<?php
/**
 * /google/callback.php — odbiór kodu OAuth2 z Google.
 * Konto Google => e-mail potwierdzony (użytkownik przeszedł weryfikację Google),
 * więc email_verified_at ustawiamy od razu i grupę wiekową z Gmaila.
 */

declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/auth.php';

$code  = (string) ($_GET['code'] ?? '');
$state = (string) ($_GET['state'] ?? '');

if (!empty($_GET['error'])) {
    $reason = (string) ($_GET['error_description'] ?? $_GET['error']);
    html_fail('Google odrzucił logowanie: ' . $reason, 400);
}

if ($code === '') {
    html_fail('Brak kodu w adresie powrotnym. Otwórz logowanie od początku: /google/start.php', 400);
}

if (empty($_SESSION['oauth_state_google'])
    || !hash_equals($_SESSION['oauth_state_google'], $state)) {
    $hasState = empty($_SESSION['oauth_state_google']) ? 'brak w sesji' : 'inny';
    html_fail('Nieprawidłowy stan OAuth Google (' . $hasState . '). '
        . 'Odśwież stronę i kliknij logowanie ponownie.', 400);
}
unset($_SESSION['oauth_state_google']);

// 1. kod -> tokeny
$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_POSTFIELDS     => http_build_query([
        'code'          => $code,
        'client_id'     => cfg('google.client_id'),
        'client_secret' => cfg('google.client_secret'),
        'redirect_uri'  => cfg('google.redirect'),
        'grant_type'    => 'authorization_code',
    ]),
]);
$tok = json_decode((string) curl_exec($ch), true);
curl_close($ch);

if (empty($tok['access_token'])) { html_fail('Google nie zwrócił tokenu.', 502); }

// 2. profil (id_token wystarczy, ale wolimy userinfo — pewniejsze pola)
$ch = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $tok['access_token']],
]);
$gu = json_decode((string) curl_exec($ch), true);
curl_close($ch);

if (empty($gu['sub'])) { html_fail('Nie udało się pobrać profilu Google.', 502); }

$sub   = (string) $gu['sub'];
$email = isset($gu['email']) ? (string) $gu['email'] : null;

// 3. istniejące konto?
$st = db()->prepare('select * from accounts where google_sub = :s limit 1');
$st->execute([':s' => $sub]);
$acc = $st->fetch();

if (!$acc && $email !== null) {
    $st = db()->prepare('select * from accounts where lower(email) = lower(:e) limit 1');
    $st->execute([':e' => $email]);
    $acc = $st->fetch();
    if ($acc) {
        db()->prepare('update accounts set google_sub = :s, email_verified_at = coalesce(email_verified_at, now())
                        where id = :i')
            ->execute([':s' => $sub, ':i' => $acc['id']]);
    }
}

if ($acc) {
    if ($acc['status'] === 'banned') {
        html_fail('Konto zbanowane: ' . ($acc['ban_reason'] ?? 'naruszenie regulaminu'), 403);
    }
    create_session((string) $acc['id'], 2);
    log_ip((string) $acc['id'], 'login_google');

    if ($acc['status'] === 'pending' || empty($acc['username'])) {
        header('Location: /?krok=profil&zrodlo=google');
    } else {
        header('Location: /');
    }
    exit;
}

// 4. nowe konto — e-mail uznajemy za potwierdzony przez Google
$r = register_start($email, null, $sub);
if (empty($r['ok'])) { html_fail($r['error'] ?? 'Nie udało się utworzyć konta.', 422); }

db()->prepare('update accounts set email_verified_at = now(), auth_stage = 2 where id = :i')
    ->execute([':i' => $r['account_id']]);

create_session((string) $r['account_id'], 1);
front_redirect('/?krok=haslo&zrodlo=google');
