<?php
/**
 * API: /api/admin.php — tylko dla is_admin.
 *
 * POST ?action=publish|reject      {survey_id, reason?}
 * POST ?action=grant_mecenas       {username, days?|lifetime:true}
 * POST ?action=revoke_mecenas      {username}
 * POST ?action=ban                 {username, reason, days?}  (ban konta + IP)
 * POST ?action=unban               {username}
 * POST ?action=mute                {username, hours}
 * POST ?action=reports             (GET lista)
 * POST ?action=resolve_report      {report_id, resolution}
 * GET  ?action=queue               kolejka: AI-sporne + ankiety niepubliczne
 * POST ?action=resolve_review      {review_id, verdict, note?}
 */

declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/auth.php';

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

try {
    $admin = require_admin();

    // --------------------------------------------------- KOLEJKA MODERACJI
    if ($action === 'queue' && $method === 'GET') {
        $q = db()->query(
            "select s.id, s.title, s.kind, s.status, s.ai_score, s.ai_report, s.created_at,
                    a.username,
                    (select count(*) from content_reviews r
                      where r.target_kind='survey' and r.target_id=s.id::text and r.user_disputed) as disputed
               from surveys s join accounts a on a.id = s.author_id
              where s.status in ('ai_review','pending_admin')
              order by s.created_at asc limit 100"
        );
        json_out(['ok' => true, 'items' => $q->fetchAll()]);
    }

    // ------------------------------------------------------------ PUBLIKACJA
    if ($action === 'publish' && $method === 'POST') {
        $in = read_json();
        csrf_check($in['csrf'] ?? null);
        $sid = (string) ($in['survey_id'] ?? '');

        db()->prepare(
            "update surveys set status='public', published_at=now(), admin_id=:a, admin_checked_at=now()
              where id = :i"
        )->execute([':a' => $admin['id'], ':i' => $sid]);

        db()->prepare(
            'insert into content_reviews (target_kind, target_id, ai_verdict, human_id, human_verdict, human_at)
             values (\'survey\', :t, \'ok\', :h, \'publish\', now())'
        )->execute([':t' => $sid, ':h' => $admin['id']]);

        audit($admin['id'], 'survey.publish', 'survey', $sid);
        json_out(['ok' => true]);
    }

    if ($action === 'reject' && $method === 'POST') {
        $in = read_json();
        csrf_check($in['csrf'] ?? null);
        $sid = (string) ($in['survey_id'] ?? '');
        $why = mb_substr((string) ($in['reason'] ?? 'Naruszenie regulaminu.'), 0, 500);

        db()->prepare(
            "update surveys set status='rejected', admin_id=:a, admin_checked_at=now(), rejection_reason=:r
              where id = :i"
        )->execute([':a' => $admin['id'], ':i' => $sid, ':r' => $why]);

        audit($admin['id'], 'survey.reject', 'survey', $sid, ['reason' => $why]);
        json_out(['ok' => true]);
    }

    // ------------------------------------------------------------- MECENAS
    if ($action === 'grant_mecenas' && $method === 'POST') {
        $in = read_json();
        csrf_check($in['csrf'] ?? null);

        $target = find_account((string) ($in['username'] ?? ''));
        if (!$target) { fail('Nie ma takiego użytkownika.', 404); }

        $lifetime = !empty($in['lifetime']);
        $days     = max(1, (int) ($in['days'] ?? 30));

        db()->prepare(
            'insert into grants (account_id, kind, granted_by, note, ends_at)
             values (:a, :k, :g, :n, case when :lt then null else now() + interval \'1 day\' * :d end)'
        )->execute([
            ':a' => $target['id'],
            ':k' => $lifetime ? 'mecenas_lifetime' : 'mecenas_temporary',
            ':g' => $admin['id'],
            ':n' => mb_substr((string) ($in['note'] ?? ''), 0, 300),
            ':lt' => $lifetime ? '1' : '0',
            ':d'  => $days,
        ]);

        if ($lifetime) {
            db()->prepare('update accounts set mecenas_lifetime = true, mecenas_until = null where id = :i')
                ->execute([':i' => $target['id']]);
        } else {
            db()->prepare('update accounts set mecenas_until = now() + interval \'1 day\' * :d where id = :i')
                ->execute([':d' => $days, ':i' => $target['id']]);
        }

        moderation_note($target['id'], $admin['id'], 'mecenas', $lifetime ? 'na stałe' : "{$days} dni");
        audit($admin['id'], 'mecenas.grant', 'account', $target['id'], ['days' => $lifetime ? null : $days]);
        json_out(['ok' => true]);
    }

    if ($action === 'revoke_mecenas' && $method === 'POST') {
        $in = read_json();
        csrf_check($in['csrf'] ?? null);
        $target = find_account((string) ($in['username'] ?? ''));
        if (!$target) { fail('Nie ma takiego użytkownika.', 404); }

        db()->prepare('update accounts set mecenas_until = null, mecenas_lifetime = false where id = :i')
            ->execute([':i' => $target['id']]);
        db()->prepare('update grants set revoked_at = now() where account_id = :a and revoked_at is null')
            ->execute([':a' => $target['id']]);

        audit($admin['id'], 'mecenas.revoke', 'account', $target['id']);
        json_out(['ok' => true]);
    }

    // ----------------------------------------------------------------- BAN
    if ($action === 'ban' && $method === 'POST') {
        $in = read_json();
        csrf_check($in['csrf'] ?? null);

        $target = find_account((string) ($in['username'] ?? ''));
        if (!$target) { fail('Nie ma takiego użytkownika.', 404); }
        if (!empty($target['is_admin']) && $target['id'] !== $admin['id']) {
            fail('Nie można banować innego administratora.', 403);
        }

        $reason = mb_substr((string) ($in['reason'] ?? 'Naruszenie regulaminu.'), 0, 500);
        $days   = isset($in['days']) && (int) $in['days'] > 0 ? (int) $in['days'] : null;

        db()->prepare(
            'insert into bans (account_id, ip, reason, issued_by, expires_at)
             values (:a, :ip, :r, :b, case when :d is null then null else now() + interval \'1 day\' * :d end)'
        )->execute([
            ':a' => $target['id'], ':ip' => $target['last_ip'],
            ':r' => $reason, ':b' => $admin['id'], ':d' => $days,
        ]);

        db()->prepare(
            'update accounts set status = \'banned\', ban_reason = :r, banned_at = now() where id = :i'
        )->execute([':r' => $reason, ':i' => $target['id']]);

        // zwolnienie nazwy: regulamin mowi, ze po banie nazwa wraca do puli
        db()->prepare('select release_username(:i)')->execute([':i' => $target['id']]);

        // unieważnienie sesji
        db()->prepare('update sessions set revoked_at = now() where account_id = :a and revoked_at is null')
            ->execute([':a' => $target['id']]);

        moderation_note($target['id'], $admin['id'], 'ban', $reason);
        audit($admin['id'], 'account.ban', 'account', $target['id'], ['reason' => $reason, 'days' => $days]);
        json_out(['ok' => true]);
    }

    if ($action === 'unban' && $method === 'POST') {
        $in = read_json();
        csrf_check($in['csrf'] ?? null);
        $target = find_account((string) ($in['username'] ?? ''));
        if (!$target) { fail('Nie ma takiego użytkownika.', 404); }

        db()->prepare("update accounts set status='active', ban_reason=null, banned_at=null where id=:i")
            ->execute([':i' => $target['id']]);
        // po unbanie probujemy oddac nazwe, jesli nikt jej nie zajal
        db()->prepare('select try_restore_username(:i)')->execute([':i' => $target['id']]);
        db()->prepare("update bans set lifted_at = now() where account_id = :a and lifted_at is null")
            ->execute([':a' => $target['id']]);

        audit($admin['id'], 'account.unban', 'account', $target['id']);
        json_out(['ok' => true]);
    }

    if ($action === 'mute' && $method === 'POST') {
        $in = read_json();
        csrf_check($in['csrf'] ?? null);
        $target = find_account((string) ($in['username'] ?? ''));
        if (!$target) { fail('Nie ma takiego użytkownika.', 404); }

        $hours = max(1, (int) ($in['hours'] ?? 24));
        db()->prepare('update accounts set muted_until = now() + interval \'1 hour\' * :h where id = :i')
            ->execute([':h' => $hours, ':i' => $target['id']]);

        moderation_note($target['id'], $admin['id'], 'mute', "{$hours} h");
        audit($admin['id'], 'account.mute', 'account', $target['id'], ['hours' => $hours]);
        json_out(['ok' => true]);
    }

    // ------------------------------------------------------------ RAPORTY
    if ($action === 'reports' && $method === 'GET') {
        $st = db()->query(
            "select r.*, a.username as reporter
               from reports r left join accounts a on a.id = r.reporter_id
              where r.status in ('open','in_review')
              order by r.created_at desc limit 100"
        );
        json_out(['ok' => true, 'items' => $st->fetchAll()]);
    }

    if ($action === 'resolve_report' && $method === 'POST') {
        $in = read_json();
        csrf_check($in['csrf'] ?? null);

        db()->prepare(
            'update reports set status = :s, handled_by = :h, handled_at = now(), resolution = :r
              where id = :i'
        )->execute([
            ':s' => in_array($in['status'] ?? '', ['actioned', 'dismissed'], true) ? $in['status'] : 'dismissed',
            ':h' => $admin['id'],
            ':r' => mb_substr((string) ($in['resolution'] ?? ''), 0, 1000),
            ':i' => (string) ($in['report_id'] ?? ''),
        ]);
        json_out(['ok' => true]);
    }

    // ------------------------------------------- ROZSTRZYGNIĘCIE SPORU Z AI
    if ($action === 'resolve_review' && $method === 'POST') {
        $in = read_json();
        csrf_check($in['csrf'] ?? null);

        db()->prepare(
            'update content_reviews set human_id = :h, human_verdict = :v, human_at = now()
              where id = :i'
        )->execute([
            ':h' => $admin['id'],
            ':v' => mb_substr((string) ($in['verdict'] ?? 'ok'), 0, 40),
            ':i' => (string) ($in['review_id'] ?? ''),
        ]);
        audit($admin['id'], 'review.resolve', 'content_review', (string) ($in['review_id'] ?? ''));
        json_out(['ok' => true]);
    }

    fail('Nieznana akcja.', 404);

} catch (Throwable $e) {
    error_log('admin.php: ' . $e->getMessage());
    fail('Błąd serwera.', 500);
}

function find_account(string $username): ?array
{
    $st = db()->prepare('select * from accounts where lower(username) = lower(:u) limit 1');
    $st->execute([':u' => ltrim($username, '@')]);
    return $st->fetch() ?: null;
}

function moderation_note(string $targetId, string $moderatorId, string $action, string $reason): void
{
    db()->prepare(
        'insert into moderation_actions (target_id, moderator_id, action, reason)
         values (:t, :m, :a, :r)'
    )->execute([':t' => $targetId, ':m' => $moderatorId, ':a' => $action, ':r' => $reason]);
}
