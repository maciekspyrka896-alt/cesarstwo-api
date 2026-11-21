<?php
/**
 * API: /api/surveys.php
 * GET  ?action=feed&q=...&kind=guess|opinion&page=1
 * POST ?action=create   (multipart: kind,title,description,options[],video_url,correct_index + images[])
 * POST ?action=vote     {survey_id, option_id}
 * POST ?action=like     {survey_id}
 * POST ?action=view     {survey_id}   (wołane po 10 s widoczności)
 * POST ?action=dispute  {survey_id, reason}  — spór z oceną AI => kolejka do człowieka
 */

declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/auth.php';
require __DIR__ . '/../inc/surveys.php';
require __DIR__ . '/../inc/cheeser.php';

$action = $_GET['action'] ?? 'feed';
$method = $_SERVER['REQUEST_METHOD'];

try {
    // ------------------------------------------------------------- FEED
    if ($action === 'feed' && $method === 'GET') {
        $q     = trim((string) ($_GET['q'] ?? ''));
        $kind  = in_array($_GET['kind'] ?? '', ['guess', 'opinion'], true) ? $_GET['kind'] : null;
        $page  = max(1, (int) ($_GET['page'] ?? 1));
        $per   = 12;
        $off   = ($page - 1) * $per;

        $where = ["s.status = 'public'"];
        $bind  = [];
        if ($q !== '') {
            $where[] = '(s.title ILIKE :q or s.description ILIKE :q)';
            $bind[':q'] = '%' . $q . '%';
        }
        if ($kind !== null) { $where[] = 's.kind = :k'; $bind[':k'] = $kind; }

        $sql = 'select s.id, s.kind, s.title, s.description, s.view_count, s.like_count,
                       s.response_count, s.max_responses, s.video_url, s.published_at,
                       a.username, a.avatar_url,
                       (select json_agg(json_build_object(\'id\',o.id,\'label\',o.label,
                                \'votes\',o.vote_count,\'position\',o.position) order by o.position)
                          from survey_options o where o.survey_id = s.id) as options,
                       (select json_agg(json_build_object(\'url\',i.public_url,\'mime\',i.mime_type)
                                order by i.position)
                          from survey_images i where i.survey_id = s.id) as images
                  from surveys s join accounts a on a.id = s.author_id
                 where ' . implode(' and ', $where) . '
                 order by s.published_at desc nulls last
                 limit ' . $per . ' offset ' . $off;

        $st = db()->prepare($sql);
        $st->execute($bind);
        json_out(['ok' => true, 'items' => $st->fetchAll(), 'page' => $page]);
    }

    // ----------------------------------------------------------- CREATE
    if ($action === 'create' && $method === 'POST') {
        $acc = require_login();
        csrf_check();

        $in = [
            'kind'          => $_POST['kind'] ?? 'opinion',
            'title'         => $_POST['title'] ?? '',
            'description'   => $_POST['description'] ?? '',
            'video_url'     => $_POST['video_url'] ?? '',
            'correct_index' => $_POST['correct_index'] ?? 0,
            'options'       => $_POST['options'] ?? [],
        ];
        $uploads = normalize_uploads($_FILES['images'] ?? null);

        $r = survey_create($acc, $in, $uploads);
        json_out($r, $r['ok'] ? 200 : 422);
    }

    // ------------------------------------------------------------- VOTE
    if ($action === 'vote' && $method === 'POST') {
        $acc = require_login();
        $in  = read_json();
        csrf_check($in['csrf'] ?? null);
        $r = survey_vote((string) $in['survey_id'], $acc, (string) $in['option_id'], (string) client_ip());
        json_out($r, $r['ok'] ? 200 : 422);
    }

    // ------------------------------------------------------------- LIKE
    if ($action === 'like' && $method === 'POST') {
        $acc = require_login();
        $in  = read_json();
        csrf_check($in['csrf'] ?? null);
        $sid = (string) ($in['survey_id'] ?? '');

        $has = db()->prepare('select 1 from survey_likes where survey_id = :s and account_id = :a');
        $has->execute([':s' => $sid, ':a' => $acc['id']]);

        if ($has->fetchColumn()) {
            db()->prepare('delete from survey_likes where survey_id = :s and account_id = :a')
                ->execute([':s' => $sid, ':a' => $acc['id']]);
            json_out(['ok' => true, 'liked' => false]);
        }
        db()->prepare('insert into survey_likes (survey_id, account_id) values (:s, :a)')
            ->execute([':s' => $sid, ':a' => $acc['id']]);
        json_out(['ok' => true, 'liked' => true]);
    }

    // ------------------------------------------------------------- VIEW
    if ($action === 'view' && $method === 'POST') {
        $in = read_json();
        csrf_check($in['csrf'] ?? null);
        json_out(survey_view((string) ($in['survey_id'] ?? '')));
    }

    // ---------------------------------------------------------- DISPUTE
    if ($action === 'dispute' && $method === 'POST') {
        $acc = require_login();
        $in  = read_json();
        csrf_check($in['csrf'] ?? null);
        $sid = (string) ($in['survey_id'] ?? '');

        db()->prepare(
            'update content_reviews set user_disputed = true
              where target_kind = \'survey\' and target_id = :t'
        )->execute([':t' => $sid]);

        db()->prepare(
            'insert into reports (reporter_id, target_kind, target_id, reason, details)
             values (:r, \'survey\', :t, \'spor_z_ai\', :d)'
        )->execute([
            ':r' => $acc['id'], ':t' => $sid,
            ':d' => mb_substr((string) ($in['reason'] ?? ''), 0, 2000),
        ]);

        json_out(['ok' => true, 'message' => 'Sprawę przejmie człowiek — decyzja AI jest zaskarżona.']);
    }

    fail('Nieznana akcja.', 404);

} catch (Throwable $e) {
    error_log('surveys.php: ' . $e->getMessage());
    fail('Błąd serwera.', 500);
}

/** $_FILES['images'] -> lista [{name,type,tmp_name,size,error}] */
function normalize_uploads(?array $f): array
{
    if (!$f || empty($f['name'])) { return []; }
    if (!is_array($f['name'])) { return [$f]; }
    $out = [];
    foreach ($f['name'] as $i => $n) {
        if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { continue; }
        $out[] = [
            'name'     => $n,
            'type'     => $f['type'][$i] ?? '',
            'tmp_name' => $f['tmp_name'][$i] ?? '',
            'size'     => (int) ($f['size'][$i] ?? 0),
            'error'    => $f['error'][$i],
        ];
    }
    return $out;
}
