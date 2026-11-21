<?php
/**
 * API: /api/thumbs.php — projekty miniaturek.
 *
 * Projekty trzymane w cache przeglądarki; serwer zapisuje tylko te,
 * które użytkownik świadomie zapisze w chmurze albo udostępni.
 *
 * GET  ?action=list              moje projekty (+ publiczne)
 * GET  ?action=get&id=UUID
 * POST ?action=save    {id?, name, data, preview?, is_public?}
 * POST ?action=delete  {id}
 */

declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/auth.php';

$action = $_GET['action'] ?? 'list';
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($action === 'list' && $method === 'GET') {
        $me = current_account();
        if ($me) {
            $st = db()->prepare(
                'select id, name, is_public, preview_url, updated_at from thumbnail_projects
                  where account_id = :a order by updated_at desc limit 100'
            );
            $st->execute([':a' => $me['id']]);
            $mine = $st->fetchAll();
        } else {
            $mine = [];
        }

        $pub = db()->query(
            'select t.id, t.name, t.preview_url, t.updated_at, a.username
               from thumbnail_projects t join accounts a on a.id = t.account_id
              where t.is_public and a.status = \'active\'
              order by t.updated_at desc limit 30'
        )->fetchAll();

        json_out(['ok' => true, 'mine' => $mine, 'public' => $pub]);
    }

    if ($action === 'get' && $method === 'GET') {
        $me = current_account();
        $id = (string) ($_GET['id'] ?? '');
        $st = db()->prepare('select * from thumbnail_projects where id = :i');
        $st->execute([':i' => $id]);
        $p = $st->fetch();
        if (!$p) { fail('Projekt nie istnieje.', 404); }
        if (!$p['is_public'] && (!$me || $me['id'] !== $p['account_id'])) {
            fail('Brak dostępu.', 403);
        }
        json_out(['ok' => true, 'project' => $p]);
    }

    if ($action === 'save' && $method === 'POST') {
        $me = require_login();
        $in = read_json();
        csrf_check($in['csrf'] ?? null);

        $name = mb_substr(trim((string) ($in['name'] ?? 'bez nazwy')), 0, 80);
        $data = $in['data'] ?? null;
        if (!is_array($data)) { fail('Brak danych projektu.', 422); }
        if (strlen(json_encode($data)) > 262144) { fail('Projekt za duży (max 256 KB).'); }

        $isPublic = !empty($in['is_public']);
        $preview  = isset($in['preview']) ? mb_substr((string) $in['preview'], 0, 500) : null;
        $id       = (string) ($in['id'] ?? '');

        if ($id !== '') {
            $st = db()->prepare(
                'update thumbnail_projects set name = :n, data = :d, is_public = :p,
                        preview_url = coalesce(:pv, preview_url), updated_at = now()
                  where id = :i and account_id = :a'
            );
            $st->execute([
                ':n' => $name, ':d' => json_encode($data), ':p' => $isPublic ? '1' : '0',
                ':pv' => $preview, ':i' => $id, ':a' => $me['id'],
            ]);
            json_out(['ok' => true, 'id' => $id, 'updated' => true]);
        }

        $st = db()->prepare(
            'insert into thumbnail_projects (account_id, name, data, is_public, preview_url)
             values (:a, :n, :d, :p, :pv) returning id'
        );
        $st->execute([
            ':a' => $me['id'], ':n' => $name, ':d' => json_encode($data),
            ':p' => $isPublic ? '1' : '0', ':pv' => $preview,
        ]);
        json_out(['ok' => true, 'id' => $st->fetchColumn()]);
    }

    if ($action === 'delete' && $method === 'POST') {
        $me = require_login();
        $in = read_json();
        csrf_check($in['csrf'] ?? null);
        db()->prepare('delete from thumbnail_projects where id = :i and account_id = :a')
            ->execute([':i' => (string) ($in['id'] ?? ''), ':a' => $me['id']]);
        json_out(['ok' => true]);
    }

    fail('Nieznana akcja.', 404);

} catch (Throwable $e) {
    error_log('thumbs.php: ' . $e->getMessage());
    fail('Błąd serwera.', 500);
}
