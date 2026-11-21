<?php
/**
 * /api/csrf.php — zwraca token CSRF i stan sesji.
 * Front woła to przy starcie, zanim wyśle pierwszy POST.
 */

declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';

$a = current_account();

json_out([
    'ok'   => true,
    'csrf' => csrf_token(),
    'account' => $a ? [
        'id'        => $a['id'],
        'username' => $a['username'],
        'email'    => $a['email'],
        'is_admin' => (bool) $a['is_admin'],
        'mecenas'  => is_mecenas($a),
        'age_group' => $a['age_group'],
        'gender'   => $a['gender'],
        'status'   => $a['status'],
    ] : null,
]);
