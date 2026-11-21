<?php
/**
 * API: /api/auth.php
 * Akcje: register_start, register_verify, register_finish,
 *        login, login_2fa, logout, factor_totp_setup, factor_4fa_setup, me
 */

declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/auth.php';
require __DIR__ . '/../inc/mail.php';
require __DIR__ . '/../inc/passwordless.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';
$in     = $_SERVER['REQUEST_METHOD'] === 'POST' ? read_json() : [];

// Wszystkie mutujące akcje wymagają CSRF.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($in['csrf'] ?? null);
}

try {
    switch ($action) {

        // ------------------------------------------------ rejestracja
        case 'register_start': {
            $r = register_start(
                $in['email'] ?? null,
                $in['discord_id'] ?? null,
                $in['google_sub'] ?? null
            );
            json_out($r, $r['ok'] ? 200 : 422);
        }

        case 'register_verify': {
            $by = (string) ($in['code'] ?? $in['token'] ?? '');
            $r = register_verify($by);
            if ($r['ok']) {
                $a = db()->prepare('select id, auth_stage from accounts where id = :i');
                $a->execute([':i' => $r['account_id']]);
                $r['next'] = 'set_credentials';
            }
            json_out($r, $r['ok'] ? 200 : 422);
        }

        case 'register_finish': {
            $r = register_finish((string) ($in['account_id'] ?? ''), $in);
            if ($r['ok']) {
                create_session((string) $in['account_id'], 1);
                $r['next'] = 'setup_factors';
            }
            json_out($r, $r['ok'] ? 200 : 422);
        }

        // --------------------------------------------------- logowanie
        case 'login': {
            $r = login_password((string) ($in['login'] ?? ''), (string) ($in['password'] ?? ''));
            json_out($r, $r['ok'] ? 200 : 401);
        }

        case 'login_2fa': {
            $r = login_2fa_verify((string) ($in['code'] ?? ''));
            if ($r['ok']) {
                create_session($r['account_id'], 2);
                $f = factors_for($r['account_id']);
                $r['next'] = 'dashboard';
                $r['factors'] = [
                    'totp'       => isset($f['totp']),
                    'password2'  => isset($f['password2']),
                    'cheeser4fa' => isset($f['cheeser4fa']),
                    'backup'     => true,
                ];
                if (!has_required_factors($r['account_id'])) {
                    $r['next'] = 'setup_factors';
                }
            }
            json_out($r, $r['ok'] ? 200 : 401);
        }

        case 'logout': {
            destroy_session();
            json_out(['ok' => true]);
        }

        case 'me': {
            $a = current_account();
            if (!$a) { json_out(['ok' => true, 'account' => null]); }
            json_out(['ok' => true, 'account' => [
                'id'          => $a['id'],
                'username'    => $a['username'],
                'email'       => $a['email'],
                'is_admin'    => (bool) $a['is_admin'],
                'mecenas'     => is_mecenas($a),
                'age_group'   => $a['age_group'],
                'gender'      => $a['gender'],
                'status'      => $a['status'],
                'csrf'        => csrf_token(),
            ]]);
        }

        // ----------------------------------------------------- 3FA: TOTP
        case 'factor_totp_setup': {
            $a = require_login();
            $secret = new_totp_secret();
            // tymczasowo zapisujemy niepotwierdzony sekret w sesji
            $_SESSION['pending_totp'] = $secret;
            json_out([
                'ok'      => true,
                'secret'  => $secret,
                'uri'     => totp_uri($secret, $a['username'] ?: $a['email']),
                'qr_url'  => qr_url(totp_uri($secret, $a['username'] ?: $a['email'])),
            ]);
        }

        case 'factor_totp_confirm': {
            $a = require_login();
            $secret = $_SESSION['pending_totp'] ?? '';
            if ($secret === '' || !totp_verify($secret, (string) ($in['code'] ?? ''))) {
                fail('Kod z aplikacji nieprawidłowy.');
            }
            ensure_factor($a['id'], 'totp', $secret, 'Google Authenticator');
            unset($_SESSION['pending_totp']);
            $codes = regenerate_backup_codes($a['id']);
            audit($a['id'], 'factor.totp.enabled', 'account', $a['id']);
            json_out(['ok' => true, 'backup_codes' => $codes]);
        }

        // ------------------------------------------- 3FA: drugie hasło
        case 'factor_password2_setup': {
            $a = require_login();
            $p2 = (string) ($in['password2'] ?? '');
            if (($err = validate_password($p2)) !== null) { fail($err); }
            ensure_factor($a['id'], 'password2', $p2, 'drugie hasło');
            audit($a['id'], 'factor.password2.enabled', 'account', $a['id']);
            json_out(['ok' => true]);
        }

        // ------------------------------------------------ 4FA: Cheeserai
        case 'factor_4fa_setup': {
            $a = require_login();
            $entries = $in['entries'] ?? [];
            if (!is_array($entries) || count($entries) < 2) {
                fail('Podaj minimum 2 dane (np. data urodzenia i imię zwierzaka).');
            }
            $clean = [];
            foreach ($entries as $k => $v) {
                $k = preg_replace('/[^a-z0-9_]/', '', mb_strtolower((string) $k)) ?? '';
                if ($k === '') { continue; }
                $clean[$k] = (string) $v;
            }
            $required = max(1, (int) ($in['required'] ?? 1));
            cheeser4fa_store($a['id'], $clean, $required);
            audit($a['id'], 'factor.4fa.enabled', 'account', $a['id'], ['fields' => array_keys($clean)]);
            json_out(['ok' => true, 'fields' => array_keys($clean), 'required' => $required]);
        }

        case 'factor_4fa_verify': {
            $a = require_login();
            $hits = cheeser4fa_verify($a['id'], (array) ($in['answers'] ?? []));
            $need = cheeser4fa_required($a['id']);
            $ok = $hits >= $need;
            db()->prepare('update sessions set fa_level = greatest(fa_level, 3) where id = :i')
                ->execute([':i' => $a['session_id']]);
            json_out(['ok' => $ok, 'hits' => $hits, 'required' => $need],
                $ok ? 200 : 401);
        }


        // ------------------------------------- logowanie BEZ HASŁA (2/3/4FA)
        case 'pwless_start': {
            json_out(passwordless_start((string) ($in['login'] ?? '')));
        }

        case 'pwless_2fa': {
            $r = passwordless_verify_2fa((string) ($in['code'] ?? ''));
            json_out($r, $r['ok'] ? 200 : 401);
        }

        case 'pwless_factor': {
            $r = passwordless_verify_factor(
                (string) ($in['account_id'] ?? ''),
                (string) ($in['method'] ?? ''),
                $in
            );
            json_out($r, $r['ok'] ? 200 : 401);
        }

        // ------------------------------------------- wymuszenie czynnika
        case 'onboarding_status': {
            $a = require_login();
            $f = factors_for($a['id']);
            $required = has_required_factors($a['id']);
            json_out([
                'ok' => true,
                'complete' => $required,
                'factors' => [
                    'email_code' => isset($f['email_code']),
                    'totp'       => isset($f['totp']),
                    'password2'  => isset($f['password2']),
                    'cheeser4fa' => isset($f['cheeser4fa']),
                ],
                'missing' => $required ? [] : ['jeden z: TOTP, drugie hasło, 4FA Cheeserai'],
            ]);
        }

        case 'onboarding_complete': {
            $a = require_login();
            if (!has_required_factors($a['id'])) {
                fail('Najpierw ustaw minimum jeden czynnik poza 2FA.', 409);
            }
            db()->prepare('update accounts set onboarded_at = now() where id = :i')
                ->execute([':i' => $a['id']]);
            json_out(['ok' => true]);
        }

        // --------------------------------------------- zgoda na ciastka
        case 'cookies_consent': {
            $a = current_account();
            $raw = $_SERVER['REQUEST_METHOD'] === 'POST' ? $in : [];
            $consent = [
                'necessary'  => true,
                'functional' => !empty($raw['functional']),
                'analytics'  => !empty($raw['analytics']),
                'version'    => '1.0',
                'at'         => date('c'),
            ];
            if ($a) {
                db()->prepare(
                    'update accounts set cookies_consent = :c, consent_updated_at = now() where id = :i'
                )->execute([':c' => json_encode($consent), ':i' => $a['id']]);
            }
            json_out(['ok' => true, 'consent' => $consent]);
        }

        // ------------------------------------------------- usuwanie konta
        case 'account_delete': {
            $a = require_login();
            if (($_SERVER['REQUEST_METHOD'] === 'POST')) { csrf_check($in['csrf'] ?? null); }
            $confirm = (string) ($in['confirm'] ?? '');
            if ($confirm !== ($a['username'] ?: $a['email'])) {
                fail('Wpisz swoją nazwę, aby potwierdzić usunięcie.', 422);
            }
            db()->prepare('select release_username(:i)')->execute([':i' => $a['id']]);
            db()->prepare(
                'update accounts set status = \'deleted\', deleted_at = now(),
                        email = null, password_hash = null, bio = null, avatar_url = null
                  where id = :i'
            )->execute([':i' => $a['id']]);
            db()->prepare('update sessions set revoked_at = now() where account_id = :a')
                ->execute([':a' => $a['id']]);
            audit($a['id'], 'account.deleted', 'account', $a['id']);
            destroy_session();
            json_out(['ok' => true, 'message' => 'Konto usunięte, nazwa wróciła do puli.']);
        }

        // ------------------------------------------------- reset hasła
        case 'password_reset_start': {
            $login = (string) ($in['login'] ?? '');
            $st = db()->prepare(
                'select id, email, status from accounts
                  where (lower(username) = lower(:l) or lower(email) = lower(:l)) limit 1'
            );
            $st->execute([':l' => ltrim($login, '@')]);
            $acc = $st->fetch();
            if ($acc && $acc['status'] === 'active' && $acc['email']) {
                $ott = issue_ott('reset_password', (string) $acc['id'], [], 1800);
                $link = rtrim(cfg('app.base_url'), '/') . '/reset.php?t=' . $ott['token'];
                mail_send(
                    $acc['email'],
                    'Zmiana hasła — Cesarstwo Playera',
                    '<p>Kliknij, aby ustawić nowe hasło:</p>' . mail_btn($link, 'Ustaw nowe hasło')
                    . '<p>Link wygasa po 30 minutach.</p>',
                    'Zmiana hasła: ' . $link
                );
            }
            json_out(['ok' => true, 'message' => 'Jeśli konto istnieje, wysłaliśmy link.']);
        }

        case 'password_reset_finish': {
            $row = consume_ott('reset_password', (string) ($in['token'] ?? ''));
            if ($row === null) { fail('Link nieprawidłowy lub wygasł.', 410); }
            $p = (string) ($in['password'] ?? '');
            if ($p !== (string) ($in['password2'] ?? '')) { fail('Hasła się różnią.'); }
            if (($err = validate_password($p)) !== null) { fail($err); }
            $hash = hash_password($p);
            if ($hash === null) { fail('Serwer nie obsługuje Argon2id.', 500); }
            db()->prepare('update accounts set password_hash = :h where id = :i')
                ->execute([':h' => $hash, ':i' => $row['account_id']]);
            db()->prepare('update sessions set revoked_at = now() where account_id = :a')
                ->execute([':a' => $row['account_id']]);
            audit((string) $row['account_id'], 'password.reset', 'account', (string) $row['account_id']);
            json_out(['ok' => true, 'message' => 'Hasło zmienione. Zaloguj się ponownie.']);
        }

        default:
            fail('Nieznana akcja.', 404);
    }
} catch (Throwable $e) {
    error_log('auth.php: ' . $e->getMessage());
    fail('Błąd serwera.', 500);
}
