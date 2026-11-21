<?php
/**
 * API: /api/profile.php
 * GET  ?action=view&u=nazwa          profil publiczny + ankiety + liczniki
 * GET  ?action=search&q=...          szukanie użytkowników
 * POST ?action=follow    {username}  obserwuj / przestań
 * POST ?action=update    {bio, display_name, gender}
 * GET  ?action=me                    mój profil (z e-mailem)
 * POST ?action=avatar                (multipart: avatar)
 */

declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/auth.php';

$action = $_GET['action'] ?? 'view';
$method = $_SERVER['REQUEST_METHOD'];

try {
    // ------------------------------------------------------------- PROFIL
    if ($action === 'view' && $method === 'GET') {
        $u = ltrim((string) ($_GET['u'] ?? ''), '@');
        $st = db()->prepare('select * from v_public_profiles where lower(username) = lower(:u) limit 1');
        $st->execute([':u' => $u]);
        $p = $st->fetch();
        if (!$p) { fail('Nie ma takiego użytkownika.', 404); }

        $s = db()->prepare(
            "select id, kind, title, like_count, response_count, view_count, published_at
               from surveys where author_id = :a and status = 'public'
              order by published_at desc limit 30"
        );
        $s->execute([':a' => $p['id']]);

        $me = current_account();
        $following = false;
        if ($me) {
            $f = db()->prepare('select 1 from follows where follower_id = :f and followed_id = :t');
            $f->execute([':f' => $me['id'], ':t' => $p['id']]);
            $following = (bool) $f->fetchColumn();
        }

        json_out(['ok' => true, 'profile' => $p, 'surveys' => $s->fetchAll(), 'following' => $following]);
    }

    // ------------------------------------------------------------ SZUKANIE
    if ($action === 'search' && $method === 'GET') {
        $q = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($q) < 2) { fail('Wpisz minimum 2 znaki.'); }
        $st = db()->prepare(
            'select username, display_name, avatar_url, followers_count, mecenas
               from v_public_profiles where username ilike :q order by followers_count desc limit 20'
        );
        $st->execute([':q' => '%' . $q . '%']);
        json_out(['ok' => true, 'items' => $st->fetchAll()]);
    }

    // ------------------------------------------------------- OBSERWOWANIE
    if ($action === 'follow' && $method === 'POST') {
        $me = require_login();
        $in = read_json();
        csrf_check($in['csrf'] ?? null);

        $u = ltrim((string) ($in['username'] ?? ''), '@');
        $st = db()->prepare('select id, username from accounts where lower(username) = lower(:u) and status = \'active\'');
        $st->execute([':u' => $u]);
        $t = $st->fetch();
        if (!$t) { fail('Nie ma takiego użytkownika.', 404); }
        if ($t['id'] === $me['id']) { fail('Nie możesz obserwować siebie.', 422); }

        $has = db()->prepare('select 1 from follows where follower_id = :f and followed_id = :t');
        $has->execute([':f' => $me['id'], ':t' => $t['id']]);

        if ($has->fetchColumn()) {
            db()->prepare('delete from follows where follower_id = :f and followed_id = :t')
                ->execute([':f' => $me['id'], ':t' => $t['id']]);
            json_out(['ok' => true, 'following' => false]);
        }
        db()->prepare('insert into follows (follower_id, followed_id) values (:f, :t)')
            ->execute([':f' => $me['id'], ':t' => $t['id']]);
        json_out(['ok' => true, 'following' => true]);
    }

    // ------------------------------------------------------------- EDYCJA
    if ($action === 'update' && $method === 'POST') {
        $me = require_login();
        $in = read_json();
        csrf_check($in['csrf'] ?? null);

        $bio = mb_substr(trim((string) ($in['bio'] ?? '')), 0, 280);
        $dn  = mb_substr(trim((string) ($in['display_name'] ?? '')), 0, 40);
        $g   = in_array($in['gender'] ?? '', ['kobieta', 'mezczyzna', 'inna', 'wolne'], true)
             ? $in['gender'] : null;

        db()->prepare(
            'update accounts set bio = :b, display_name = coalesce(nullif(:d, \'\'), display_name),
                    gender = coalesce(:g, gender) where id = :i'
        )->execute([':b' => $bio, ':d' => $dn, ':g' => $g, ':i' => $me['id']]);

        json_out(['ok' => true]);
    }

    // ------------------------------------------------------------ AWATAR
    if ($action === 'avatar' && $method === 'POST') {
        $me = require_login();
        csrf_check();

        $f = $_FILES['avatar'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) { fail('Brak pliku.'); }
        if ($f['size'] > 2 * 1024 * 1024) { fail('Awatar maksymalnie 2 MB.'); }

        $info = @getimagesize($f['tmp_name']);
        $mime = $info['mime'] ?? '';
        $ext  = match ($mime) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', default => null,
        };
        if ($ext === null) { fail('Dozwolone: JPG, PNG, WEBP.'); }

        global $CONFIG;
        $dir = $CONFIG['app']['upload_dir'] . '/avatars';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $name = $me['id'] . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) { fail('Zapis nie powiódł się.', 500); }

        $url = rtrim($CONFIG['app']['base_url'], '/') . '/uploads/avatars/' . $name;
        db()->prepare('update accounts set avatar_url = :u where id = :i')
            ->execute([':u' => $url, ':i' => $me['id']]);

        json_out(['ok' => true, 'avatar_url' => $url]);
    }

    // ---------------------------------------------------------------- ME
    if ($action === 'me') {
        $a = require_login();
        $st = db()->prepare(
            'select id, username, display_name, email, bio, avatar_url, gender, birth_date,
                    age_group, country_code, mecenas_lifetime, mecenas_until, created_at,
                    previous_username, onboarded_at
               from accounts where id = :i'
        );
        $st->execute([':i' => $a['id']]);
        json_out(['ok' => true, 'account' => $st->fetch()]);
    }

    fail('Nieznana akcja.', 404);

} catch (Throwable $e) {
    error_log('profile.php: ' . $e->getMessage());
    fail('Błąd serwera.', 500);
}
