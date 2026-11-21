<?php
/**
 * API: /api/ai.php  — czat z Cheeser 1.0
 *
 * GET    ?action=conversations          lista rozmów
 * GET    ?action=messages&id=UUID       historia rozmowy
 * POST   ?action=send   {conversation_id?, message}
 * POST   ?action=new
 * POST   ?action=delete {conversation_id}
 *
 * Limit: bez Mecenasa 3 generacje (kod/pliki) w okienku 24 h.
 * Zwykłe pytania (bez generowania) nie są limitowane.
 */

declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/auth.php';
require __DIR__ . '/../inc/cheeser.php';

$action = $_GET['action'] ?? '';
try {
    switch ($action) {

        case 'conversations': {
            $a = require_login();
            $st = db()->prepare(
                'select id, title, model, updated_at from ai_conversations
                  where account_id = :a order by updated_at desc limit 50'
            );
            $st->execute([':a' => $a['id']]);
            json_out(['ok' => true, 'items' => $st->fetchAll()]);
        }

        case 'messages': {
            $a  = require_login();
            $id = (string) ($_GET['id'] ?? '');
            $own = db()->prepare('select 1 from ai_conversations where id = :i and account_id = :a');
            $own->execute([':i' => $id, ':a' => $a['id']]);
            if (!$own->fetchColumn()) { fail('Brak dostępu.', 403); }

            $st = db()->prepare(
                'select id, role, content, files, created_at from ai_messages
                  where conversation_id = :c order by created_at limit 200'
            );
            $st->execute([':c' => $id]);
            json_out(['ok' => true, 'items' => $st->fetchAll()]);
        }

        case 'new': {
            $a = require_login();
            $st = db()->prepare(
                'insert into ai_conversations (account_id, title, model)
                 values (:a, :t, :m) returning id'
            );
            $st->execute([
                ':a' => $a['id'],
                ':t' => 'Nowa rozmowa',
                ':m' => cfg('app.cheeser_model', 'cheeser-1.0'),
            ]);
            json_out(['ok' => true, 'conversation_id' => $st->fetchColumn()]);
        }

        case 'delete': {
            $a  = require_login();
            $in = read_json();
            csrf_check($in['csrf'] ?? null);
            db()->prepare('delete from ai_conversations where id = :i and account_id = :a')
                ->execute([':i' => (string) ($in['conversation_id'] ?? ''), ':a' => $a['id']]);
            json_out(['ok' => true]);
        }

        case 'send': {
            $a  = require_login();
            $in = read_json();
            csrf_check($in['csrf'] ?? null);

            $text = trim((string) ($in['message'] ?? ''));
            if ($text === '') { fail('Puste pytanie.'); }
            if (mb_strlen($text) > 8000) { fail('Wiadomość za długa (max 8000 znaków).'); }

            $convId = (string) ($in['conversation_id'] ?? '');
            if ($convId === '') {
                $st = db()->prepare(
                    'insert into ai_conversations (account_id, title, model)
                     values (:a, :t, :m) returning id'
                );
                $st->execute([
                    ':a' => $a['id'], ':t' => mb_substr($text, 0, 60),
                    ':m' => cfg('app.cheeser_model', 'cheeser-1.0'),
                ]);
                $convId = $st->fetchColumn();
            } else {
                $own = db()->prepare('select 1 from ai_conversations where id = :i and account_id = :a');
                $own->execute([':i' => $convId, ':a' => $a['id']]);
                if (!$own->fetchColumn()) { fail('Brak dostępu.', 403); }
            }

            // --- limit generacji -------------------------------
            $isGeneration = (bool) preg_match(
                '/(kod|napisz|wygeneruj|generuj|plik|skrypt|funkcj|program|html|css|js\b|python|sql|json)/iu',
                $text
            );
            $mec = is_mecenas($a);
            $quotaLeft = null;

            if ($isGeneration && !$mec) {
                $q = db()->prepare('select * from ai_generation_quota where account_id = :a');
                $q->execute([':a' => $a['id']]);
                $row = $q->fetch();

                $used = 0;
                if ($row) {
                    $fresh = strtotime((string) $row['window_start']) > time() - 86400;
                    $used  = $fresh ? (int) $row['used_count'] : 0;
                }
                $limit = (int) cfg('app.free_ai_generations', 3);
                if ($used >= $limit) {
                    json_out([
                        'ok' => false,
                        'error' => "Limit {$limit} generacji bez Mecenasa wykorzystany. "
                                 . 'Mecenas zdejmuje limit.',
                        'quota' => ['used' => $used, 'limit' => $limit, 'reset_in' => '24h'],
                    ], 429);
                }
                db()->prepare(
                    'insert into ai_generation_quota (account_id, used_count, window_start)
                     values (:a, 1, now())
                     on conflict (account_id) do update set
                       used_count = case when ai_generation_quota.window_start < now() - interval \'24 hours\'
                                         then 1 else ai_generation_quota.used_count + 1 end,
                       window_start = case when ai_generation_quota.window_start < now() - interval \'24 hours\'
                                           then now() else ai_generation_quota.window_start end'
                )->execute([':a' => $a['id']]);
                $quotaLeft = $limit - $used - 1;
            }

            // --- historia rozmowy do kontekstu ------------------
            $hist = db()->prepare(
                'select role, content from ai_messages where conversation_id = :c
                  order by created_at desc limit 30'
            );
            $hist->execute([':c' => $convId]);
            $prev = array_reverse($hist->fetchAll());

            $messages = [['role' => 'system', 'content' => cheeser_site_context()]];
            foreach ($prev as $p) {
                if (in_array($p['role'], ['user', 'assistant'], true)) {
                    $messages[] = ['role' => $p['role'], 'content' => $p['content']];
                }
            }
            $messages[] = ['role' => 'user', 'content' => $text];

            // --- zapis pytania ----------------------------------
            db()->prepare(
                'insert into ai_messages (conversation_id, role, content, counted_generation)
                 values (:c, \'user\', :t, :g)'
            )->execute([':c' => $convId, ':t' => $text, ':g' => $isGeneration ? '1' : '0']);

            // --- odpowiedź --------------------------------------
            $answer = cheeser_chat($messages);
            if ($answer === null) {
                $answer = 'Nie mogę teraz połączyć się z Cheeser AI (brak konfiguracji API '
                        . 'albo chwilowa awaria). Spróbuj ponownie później.';
            }

            // --- wykryj pliki w odpowiedzi (bloki kodu) ---------
            $files = [];
            if (preg_match_all('/```(\w+)?\n(.*?)```/s', $answer, $m, PREG_SET_ORDER)) {
                foreach ($m as $i => $blk) {
                    $lang = $blk[1] ?: 'txt';
                    $files[] = [
                        'name' => 'cheeser_' . ($i + 1) . '.' . ($lang === 'javascript' ? 'js' : $lang),
                        'lang' => $lang,
                        'size' => strlen($blk[2]),
                    ];
                }
            }

            db()->prepare(
                'insert into ai_messages (conversation_id, role, content, files)
                 values (:c, \'assistant\', :t, :f)'
            )->execute([
                ':c' => $convId, ':t' => $answer,
                ':f' => json_encode($files, JSON_UNESCAPED_UNICODE),
            ]);

            db()->prepare('update ai_conversations set updated_at = now() where id = :i')
                ->execute([':i' => $convId]);

            json_out([
                'ok' => true,
                'conversation_id' => $convId,
                'answer' => $answer,
                'files' => $files,
                'quota_left' => $quotaLeft,
                'mecenas' => $mec,
            ]);
        }

        default:
            fail('Nieznana akcja.', 404);
    }
} catch (Throwable $e) {
    error_log('ai.php: ' . $e->getMessage());
    fail('Błąd serwera.', 500);
}
