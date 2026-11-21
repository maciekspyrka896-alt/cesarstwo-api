<?php
/**
 * API: /api/files.php — pliki wygenerowane przez Cheeser AI.
 *
 * GET ?action=download&id=UUID  zwraca plik z ai_messages.files
 *
 * Pliki nie są składowane osobno — siedzą w JSON wiadomości. Endpoint
 * wyciąga wskazany blok kodu i serwuje go jako plik do pobrania.
 */

declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/auth.php';

$action = $_GET['action'] ?? '';

try {
    if ($action === 'download' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $me   = require_login();
        $msgId = (string) ($_GET['id'] ?? '');
        $idx  = max(0, (int) ($_GET['i'] ?? 0));

        $st = db()->prepare(
            'select m.content, m.files, c.account_id
               from ai_messages m join ai_conversations c on c.id = m.conversation_id
              where m.id = :i limit 1'
        );
        $st->execute([':i' => $msgId]);
        $row = $st->fetch();
        if (!$row) { fail('Nie ma takiej wiadomości.', 404); }
        if ($row['account_id'] !== $me['id'] && empty($me['is_admin'])) {
            fail('Brak dostępu.', 403);
        }

        $files = json_decode((string) $row['files'], true) ?: [];
        if (!isset($files[$idx])) { fail('Nie ma takiego pliku.', 404); }

        // wyciągnij bloki kodu z treści
        preg_match_all('/```(\w+)?\n(.*?)```/s', (string) $row['content'], $m, PREG_SET_ORDER);
        if (!isset($m[$idx])) { fail('Nie udało się odtworzyć pliku.', 410); }

        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) ($files[$idx]['name'] ?? 'cheeser.txt'));

        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('X-Content-Type-Options: nosniff');
        echo $m[$idx][2];
        exit;
    }

    if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $me = require_login();
        $st = db()->prepare(
            'select m.id, m.files, m.created_at
               from ai_messages m join ai_conversations c on c.id = m.conversation_id
              where c.account_id = :a and json_array_length(m.files) > 0
              order by m.created_at desc limit 50'
        );
        $st->execute([':a' => $me['id']]);
        json_out(['ok' => true, 'items' => $st->fetchAll()]);
    }

    fail('Nieznana akcja.', 404);

} catch (Throwable $e) {
    error_log('files.php: ' . $e->getMessage());
    fail('Błąd serwera.', 500);
}
