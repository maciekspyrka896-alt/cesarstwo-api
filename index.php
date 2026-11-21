<?php
// Punkt wejścia API. Strona WWW stoi osobno (cesarstwoplayera.ct.ws).
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok'   => true,
    'name' => 'Cesarstwo Playera — API',
    'docs' => '/api/health.php',
], JSON_UNESCAPED_UNICODE);
