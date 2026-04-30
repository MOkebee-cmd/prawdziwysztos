<?php
// install.php — Instalator schematu bazy danych
// Uruchom raz: http://twoja-strona/install.php
// Po użyciu USUŃ plik z serwera.

require __DIR__ . '/db.php';

header('Content-Type: text/html; charset=utf-8');

$cfg = db_config();
$dbName = $cfg['db']['database'];

$messages = [];
$errors = [];

try {
    // Połącz się BEZ wybranej bazy żeby móc ją utworzyć
    $dsn = sprintf('mysql:host=%s;port=%d;charset=%s',
        $cfg['db']['host'], $cfg['db']['port'], $cfg['db']['charset']);
    $rootPdo = new PDO($dsn, $cfg['db']['username'], $cfg['db']['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $messages[] = "✓ Baza danych <code>$dbName</code> istnieje";

    // Teraz połącz przez normalny helper
    $pdo = db();

    // ============= TABELA: categories =============
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS categories (
            id          VARCHAR(32)  NOT NULL PRIMARY KEY,
            name        VARCHAR(64)  NOT NULL,
            accent      VARCHAR(16)  NOT NULL DEFAULT '#7d8a78',
            type_key    VARCHAR(32)  NOT NULL,
            sort_order  INT          NOT NULL DEFAULT 0,
            levels_json LONGTEXT     NULL,
            created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $messages[] = "✓ Tabela <code>categories</code>";

    // ============= TABELA: specializations =============
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS specializations (
            id            VARCHAR(64)  NOT NULL,
            category_id   VARCHAR(32)  NOT NULL,
            label         VARCHAR(128) NOT NULL,
            description   TEXT         NULL,
            color         VARCHAR(16)  NOT NULL DEFAULT '#7d8a78',
            btn_color     VARCHAR(16)  NOT NULL DEFAULT '#7d8a7840',
            sort_order    INT          NOT NULL DEFAULT 0,
            created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id, category_id),
            INDEX idx_spec_cat (category_id),
            CONSTRAINT fk_spec_cat FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $messages[] = "✓ Tabela <code>specializations</code>";

    // ============= TABELA: perks =============
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS perks (
            id              VARCHAR(64)  NOT NULL,
            spec_id         VARCHAR(64)  NOT NULL,
            category_id     VARCHAR(32)  NOT NULL,
            label           VARCHAR(128) NOT NULL,
            image           VARCHAR(128) NULL,
            required_level  INT          NOT NULL DEFAULT 1,
            pos_x           INT          NOT NULL DEFAULT 200,
            pos_y           INT          NOT NULL DEFAULT 200,
            required_perks_json LONGTEXT NULL,
            levels_json     LONGTEXT     NULL,
            created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id, spec_id, category_id),
            INDEX idx_perk_spec (spec_id, category_id),
            CONSTRAINT fk_perk_spec FOREIGN KEY (spec_id, category_id) REFERENCES specializations(id, category_id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $messages[] = "✓ Tabela <code>perks</code>";

    // ============= TABELA: change_log =============
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS change_log (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            ts          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            action      VARCHAR(32)     NOT NULL,
            entity      VARCHAR(32)     NOT NULL,
            entity_id   VARCHAR(192)    NULL,
            payload     LONGTEXT        NULL,
            client_id   VARCHAR(64)     NULL,
            ip          VARCHAR(64)     NULL,
            INDEX idx_log_ts (ts),
            INDEX idx_log_entity (entity, entity_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $messages[] = "✓ Tabela <code>change_log</code>";

    // ============= SEED: 4 kategorie =============
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM categories");
    $stmt->execute();
    $catCount = (int)$stmt->fetchColumn();

    if ($catCount === 0) {
        $defaultLevels = [];
        for ($i = 1; $i <= 20; $i++) {
            $defaultLevels[] = ['label' => "Level $i", 'value' => 1000 * $i, 'perkPoints' => 1];
        }
        $levelsJson = json_encode($defaultLevels, JSON_UNESCAPED_UNICODE);

        $cats = [
            ['general', 'Ogólne',  '#7d8a78', 'character', 0],
            ['civ',     'Cywil',   '#788494', 'civ',       1],
            ['crime',   'Crime',   '#927878', 'crime',     2],
            ['faction', 'Frakcje', '#8e8270', 'trucker',   3],
        ];
        $ins = $pdo->prepare("INSERT INTO categories (id, name, accent, type_key, sort_order, levels_json) VALUES (?,?,?,?,?,?)");
        foreach ($cats as $c) {
            $ins->execute([$c[0], $c[1], $c[2], $c[3], $c[4], $levelsJson]);
        }
        $messages[] = "✓ Wstępne kategorie (4)";

        // Demo data: Siła + Trucker
        $specIns = $pdo->prepare("INSERT INTO specializations (id, category_id, label, description, color, btn_color, sort_order) VALUES (?,?,?,?,?,?,?)");
        $perkIns = $pdo->prepare("INSERT INTO perks (id, spec_id, category_id, label, image, required_level, pos_x, pos_y, required_perks_json, levels_json) VALUES (?,?,?,?,?,?,?,?,?,?)");

        $specIns->execute(['strength', 'general', 'Siła', 'Drzewko siły – cięższe ciosy, większy udźwig.', '#7d8a78', '#7d8a7840', 0]);
        $perkIns->execute(['p_str_1', 'strength', 'general', 'Większa siła z ciosów', 'increase_strength_from_hits', 1, 200, 200, '{}', json_encode([['desc' => 'Zwiększa siłę zdobywaną z ciosów o 5%.', 'strengthModifier' => 0.05]], JSON_UNESCAPED_UNICODE)]);
        $perkIns->execute(['p_str_2', 'strength', 'general', 'Większa siła z ciosów II', 'increase_strength_from_hits_2', 2, 380, 200, json_encode(['p_str_1' => 1]), json_encode([['desc' => 'Zwiększa siłę zdobywaną z ciosów o kolejne 5%.', 'strengthModifier' => 0.05]], JSON_UNESCAPED_UNICODE)]);
        $perkIns->execute(['p_inv_1', 'strength', 'general', 'Większy udźwig', 'increase_inventory_weight', 1, 200, 340, '{}', json_encode([['desc' => 'Zwiększa maksymalny udźwig o 10kg.', 'inventoryWeightModifier' => 10]], JSON_UNESCAPED_UNICODE)]);

        $specIns->execute(['trucker', 'faction', 'Trucker', 'Specjalizacja kierowcy ciężarówki.', '#8e8270', '#8e827040', 0]);
        $perkIns->execute(['long_distance',  'trucker', 'faction', 'Long Distance',  'long_distance',  1, 200, 200, '{}', json_encode([['desc' => 'Otrzymujesz więcej XP z długich tras.']], JSON_UNESCAPED_UNICODE)]);
        $perkIns->execute(['on_time',        'trucker', 'faction', 'On Time',        'on_time',        2, 380, 200, json_encode(['long_distance' => 1]), json_encode([['desc' => 'Bonus za dostarczenie na czas.']], JSON_UNESCAPED_UNICODE)]);
        $perkIns->execute(['careful_driver', 'trucker', 'faction', 'Careful Driver', 'careful_driver', 3, 560, 200, json_encode(['on_time' => 1]), json_encode([['desc' => 'Mniejsze zużycie pojazdu.']], JSON_UNESCAPED_UNICODE)]);
        $perkIns->execute(['explosive_load', 'trucker', 'faction', 'Explosive Load', 'explosive_load', 4, 740, 200, json_encode(['careful_driver' => 1]), json_encode([['desc' => 'Możliwość przewozu materiałów wybuchowych.']], JSON_UNESCAPED_UNICODE)]);

        $messages[] = "✓ Dane demo (drzewa Siła i Trucker)";
    } else {
        $messages[] = "ℹ Kategorie już istnieją – pominięto seed.";
    }

} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<title>Instalator Skilltree Studio</title>
<style>
  body { font-family: -apple-system, system-ui, sans-serif; background: #131316; color: #e8e6e1; padding: 40px; max-width: 700px; margin: 0 auto; line-height: 1.6; }
  h1 { color: #aa9b6e; margin-bottom: 24px; }
  .ok { color: #7d8a78; }
  .err { color: #b87878; padding: 12px; background: #2a1818; border-radius: 6px; margin: 8px 0; }
  ul { list-style: none; padding: 0; }
  li { padding: 6px 0; border-bottom: 1px solid #2a2a30; }
  code { background: #212126; padding: 2px 6px; border-radius: 3px; font-family: ui-monospace, monospace; font-size: 12px; }
  .warn { background: #2a2418; border: 1px solid #6b5a3a; padding: 16px; border-radius: 8px; margin-top: 24px; }
</style>
</head>
<body>
  <h1>Instalator Skilltree Studio</h1>

  <?php if ($errors): ?>
    <h2>Błędy:</h2>
    <?php foreach ($errors as $e): ?>
      <div class="err"><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>
  <?php else: ?>
    <h2 class="ok">Instalacja zakończona.</h2>
    <ul>
      <?php foreach ($messages as $m): ?>
        <li><?= $m ?></li>
      <?php endforeach; ?>
    </ul>

    <div class="warn">
      <strong>Co dalej:</strong>
      <ol>
        <li>USUŃ <code>install.php</code> z serwera (bezpieczeństwo)</li>
        <li>Otwórz <a href="index.html" style="color:#aa9b6e;">index.html</a></li>
        <li>Jeżeli chcesz zabezpieczyć API, ustaw <code>token</code> w <code>config.php</code> i ten sam token w <code>index.html</code> (stała <code>APP_CONFIG.apiToken</code>)</li>
      </ol>
    </div>
  <?php endif; ?>
</body>
</html>
