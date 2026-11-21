<?php
/**
 * /verify.php?t=TOKEN — lądowanie linku z e-maila.
 * Weryfikuje token, ustawia email_verified_at i przenosi do etapu 3 rejestracji.
 */

declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/auth.php';

$t = (string) ($_GET['t'] ?? '');
if ($t === '') { html_fail('Brak tokenu w linku.'); }

$r = register_verify($t);
if (!$r['ok']) { html_fail($r['error'] ?? 'Link nieprawidłowy lub wygasł.', 410); }

// konto potwierdzone -> od razu sesja tymczasowa, żeby móc ustawić hasło
create_session((string) $r['account_id'], 1);

front_redirect('/?krok=haslo');
