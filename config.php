<?php
// config.php — Konfiguracja Skilltree Studio
// UWAGA: Nie commituj tego pliku do publicznego repozytorium.

return [

    // ============= POŁĄCZENIE Z BAZĄ DANYCH =============
    // MySQL 5.7+ albo MariaDB 10.2+
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'prp_skilltree',
        'username' => 'root',
        'password' => '',
        'charset'  => 'utf8mb4',
    ],

    // ============= AUTORYZACJA =============
    // Pusty string = brak autoryzacji (każdy ma dostęp do API).
    // Jeżeli ustawisz token, MUSISZ ustawić go też w index.html
    // w sekcji APP_CONFIG.apiToken — wtedy frontend będzie wysyłać
    // header X-API-Token z każdym requestem.
    //
    // Wygeneruj losowy: bin2hex(random_bytes(16))  →  np. '8c4d2e9b...'
    'token' => '',

    // ============= LOGOWANIE =============
    // Czy zapisywać każdą zmianę do tabeli change_log.
    // Przydatne do audytu, undo, debugowania.
    'log_changes' => true,
];
