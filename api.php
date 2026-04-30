<?php
// api.php — Endpoint REST dla Skilltree Studio
// Obsługuje: load, save_perk, delete_perk, save_spec, delete_spec,
//            move_perk, save_full, log

declare(strict_types=1);

require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Tylko ten sam origin w produkcji – jeśli musisz mieć CORS, włącz tutaj:
// header('Access-Control-Allow-Origin: *');
// header('Access-Control-Allow-Headers: Content-Type, X-API-Token');
// header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
// if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$cfg = db_config();

// ============= AUTORYZACJA =============
if (!empty($cfg['token'])) {
    $sent = $_SERVER['HTTP_X_API_TOKEN'] ?? '';
    if (!hash_equals($cfg['token'], (string)$sent)) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

// ============= INPUT =============
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$rawBody = file_get_contents('php://input');
$body = [];
if ($rawBody) {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) $body = $decoded;
}
if (!$action && !empty($body['action'])) $action = $body['action'];

$clientId = $_SERVER['HTTP_X_CLIENT_ID'] ?? null;
$ip = $_SERVER['REMOTE_ADDR'] ?? null;

function ok($data = []): void {
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function logChange(string $action, string $entity, ?string $entityId, $payload, ?string $clientId, ?string $ip): void {
    $cfg = db_config();
    if (empty($cfg['log_changes'])) return;
    try {
        $stmt = db()->prepare("INSERT INTO change_log (action, entity, entity_id, payload, client_id, ip) VALUES (?,?,?,?,?,?)");
        $stmt->execute([
            $action, $entity, $entityId,
            $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
            $clientId, $ip
        ]);
    } catch (Throwable $e) {
        // Cicho – log nie może wywalić zapisu danych
    }
}

// Pomocnik: walidacja ID (alphanumeric + _ + -)
function validId(string $id, int $maxLen = 64): bool {
    return $id !== '' && strlen($id) <= $maxLen && (bool)preg_match('/^[a-zA-Z0-9_\-]+$/', $id);
}

try {
    $pdo = db();

    switch ($action) {

        // ============= LOAD: pełny stan =============
        case 'load': {
            $cats = $pdo->query("SELECT id, name, accent, type_key, sort_order, levels_json FROM categories ORDER BY sort_order, id")->fetchAll();
            $specs = $pdo->query("SELECT id, category_id, label, description, color, btn_color, sort_order FROM specializations ORDER BY sort_order, id")->fetchAll();
            $perks = $pdo->query("SELECT id, spec_id, category_id, label, image, required_level, pos_x, pos_y, required_perks_json, levels_json FROM perks ORDER BY id")->fetchAll();

            $types = [];
            foreach ($cats as $c) {
                $types[$c['id']] = [
                    'name'    => $c['name'],
                    'accent'  => $c['accent'],
                    'typeKey' => $c['type_key'],
                    'levels'  => $c['levels_json'] ? json_decode($c['levels_json'], true) : [],
                    'specializations' => new stdClass(), // wymusza {} w JSON jeżeli puste
                ];
            }
            // Tablica do zbierania spec/perk po category – żeby końcowa struktura
            // była zawsze obiektem
            $specsByCat = [];
            $perksBySpec = [];
            foreach ($specs as $s) {
                if (!isset($types[$s['category_id']])) continue;
                $specsByCat[$s['category_id']][$s['id']] = [
                    'id'       => $s['id'],
                    'label'    => $s['label'],
                    'desc'     => $s['description'] ?? '',
                    'color'    => $s['color'],
                    'btnColor' => $s['btn_color'],
                    'perks'    => new stdClass(),
                ];
            }
            foreach ($perks as $p) {
                $cid = $p['category_id'];
                $sid = $p['spec_id'];
                if (!isset($specsByCat[$cid][$sid])) continue;
                $reqs = $p['required_perks_json'] ? json_decode($p['required_perks_json'], true) : [];
                if (!is_array($reqs) || array_is_list($reqs)) $reqs = [];
                $perksBySpec[$cid][$sid][$p['id']] = [
                    'id'            => $p['id'],
                    'label'         => $p['label'],
                    'image'         => $p['image'] ?? '',
                    'requiredLevel' => (int)$p['required_level'],
                    'requiredPerks' => empty($reqs) ? new stdClass() : $reqs,
                    'levels'        => $p['levels_json'] ? json_decode($p['levels_json'], true) : [['desc' => '']],
                    'x'             => (int)$p['pos_x'],
                    'y'             => (int)$p['pos_y'],
                ];
            }
            // Złóż całość – używamy (object) cast żeby puste mapy były {}
            foreach ($types as $cid => &$type) {
                if (empty($specsByCat[$cid])) {
                    $type['specializations'] = new stdClass();
                } else {
                    $specsObj = [];
                    foreach ($specsByCat[$cid] as $sid => $spec) {
                        if (!empty($perksBySpec[$cid][$sid])) {
                            $spec['perks'] = $perksBySpec[$cid][$sid];
                        } else {
                            $spec['perks'] = new stdClass();
                        }
                        $specsObj[$sid] = $spec;
                    }
                    $type['specializations'] = $specsObj;
                }
            }
            unset($type);
            ok(['data' => ['types' => $types, 'version' => 1]]);
            break;
        }

        // ============= SAVE SPEC =============
        case 'save_spec': {
            $catId = (string)($body['categoryId'] ?? '');
            $spec  = $body['spec'] ?? null;
            if (!validId($catId, 32) || !is_array($spec)) fail(400, 'Bad input');
            $sid = (string)($spec['id'] ?? '');
            if (!validId($sid)) fail(400, 'Bad spec id');

            $stmt = $pdo->prepare("
                INSERT INTO specializations (id, category_id, label, description, color, btn_color, sort_order)
                VALUES (:id, :cat, :label, :desc, :color, :btn, :sort)
                ON DUPLICATE KEY UPDATE
                    label = VALUES(label),
                    description = VALUES(description),
                    color = VALUES(color),
                    btn_color = VALUES(btn_color)
            ");
            $stmt->execute([
                ':id'    => $sid,
                ':cat'   => $catId,
                ':label' => (string)($spec['label'] ?? $sid),
                ':desc'  => (string)($spec['desc'] ?? ''),
                ':color' => (string)($spec['color'] ?? '#7d8a78'),
                ':btn'   => (string)($spec['btnColor'] ?? '#7d8a7840'),
                ':sort'  => (int)($spec['sortOrder'] ?? 0),
            ]);
            logChange('save_spec', 'spec', "$catId/$sid", $spec, $clientId, $ip);
            ok();
            break;
        }

        // ============= DELETE SPEC =============
        case 'delete_spec': {
            $catId = (string)($body['categoryId'] ?? '');
            $sid   = (string)($body['specId'] ?? '');
            if (!validId($catId, 32) || !validId($sid)) fail(400, 'Bad input');
            $stmt = $pdo->prepare("DELETE FROM specializations WHERE id = ? AND category_id = ?");
            $stmt->execute([$sid, $catId]);
            logChange('delete_spec', 'spec', "$catId/$sid", null, $clientId, $ip);
            ok(['deleted' => $stmt->rowCount()]);
            break;
        }

        // ============= SAVE PERK =============
        case 'save_perk': {
            $catId = (string)($body['categoryId'] ?? '');
            $sid   = (string)($body['specId'] ?? '');
            $perk  = $body['perk'] ?? null;
            if (!validId($catId, 32) || !validId($sid) || !is_array($perk)) fail(400, 'Bad input');
            $pid = (string)($perk['id'] ?? '');
            if (!validId($pid)) fail(400, 'Bad perk id');

            // Sanity: spec must exist
            $check = $pdo->prepare("SELECT 1 FROM specializations WHERE id = ? AND category_id = ?");
            $check->execute([$sid, $catId]);
            if (!$check->fetchColumn()) fail(400, 'Spec does not exist');

            $reqPerks = $perk['requiredPerks'] ?? [];
            if (!is_array($reqPerks) && !is_object($reqPerks)) $reqPerks = [];
            $levels = $perk['levels'] ?? [['desc' => '']];
            if (!is_array($levels) || empty($levels)) $levels = [['desc' => '']];

            $stmt = $pdo->prepare("
                INSERT INTO perks (id, spec_id, category_id, label, image, required_level, pos_x, pos_y, required_perks_json, levels_json)
                VALUES (:id, :sid, :cid, :label, :image, :rl, :x, :y, :req, :lvl)
                ON DUPLICATE KEY UPDATE
                    label = VALUES(label),
                    image = VALUES(image),
                    required_level = VALUES(required_level),
                    pos_x = VALUES(pos_x),
                    pos_y = VALUES(pos_y),
                    required_perks_json = VALUES(required_perks_json),
                    levels_json = VALUES(levels_json)
            ");
            $stmt->execute([
                ':id'    => $pid,
                ':sid'   => $sid,
                ':cid'   => $catId,
                ':label' => (string)($perk['label'] ?? $pid),
                ':image' => (string)($perk['image'] ?? ''),
                ':rl'    => (int)($perk['requiredLevel'] ?? 1),
                ':x'     => (int)($perk['x'] ?? 200),
                ':y'     => (int)($perk['y'] ?? 200),
                ':req'   => json_encode($reqPerks, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT),
                ':lvl'   => json_encode($levels, JSON_UNESCAPED_UNICODE),
            ]);
            logChange('save_perk', 'perk', "$catId/$sid/$pid", $perk, $clientId, $ip);
            ok();
            break;
        }

        // ============= DELETE PERK =============
        case 'delete_perk': {
            $catId = (string)($body['categoryId'] ?? '');
            $sid   = (string)($body['specId'] ?? '');
            $pid   = (string)($body['perkId'] ?? '');
            if (!validId($catId, 32) || !validId($sid) || !validId($pid)) fail(400, 'Bad input');

            $pdo->beginTransaction();
            try {
                // Usuń perk
                $stmt = $pdo->prepare("DELETE FROM perks WHERE id = ? AND spec_id = ? AND category_id = ?");
                $stmt->execute([$pid, $sid, $catId]);
                $deleted = $stmt->rowCount();

                // Wyczyść referencje w innych perkach (requiredPerks)
                $list = $pdo->prepare("SELECT id, required_perks_json FROM perks WHERE spec_id = ? AND category_id = ?");
                $list->execute([$sid, $catId]);
                $upd = $pdo->prepare("UPDATE perks SET required_perks_json = ? WHERE id = ? AND spec_id = ? AND category_id = ?");
                foreach ($list->fetchAll() as $row) {
                    $req = $row['required_perks_json'] ? json_decode($row['required_perks_json'], true) : [];
                    if (is_array($req) && array_key_exists($pid, $req)) {
                        unset($req[$pid]);
                        $upd->execute([json_encode($req, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT), $row['id'], $sid, $catId]);
                    }
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            logChange('delete_perk', 'perk', "$catId/$sid/$pid", null, $clientId, $ip);
            ok(['deleted' => $deleted]);
            break;
        }

        // ============= MOVE PERK (lekki update tylko pozycji) =============
        case 'move_perk': {
            $catId = (string)($body['categoryId'] ?? '');
            $sid   = (string)($body['specId'] ?? '');
            $pid   = (string)($body['perkId'] ?? '');
            $x     = (int)($body['x'] ?? 0);
            $y     = (int)($body['y'] ?? 0);
            if (!validId($catId, 32) || !validId($sid) || !validId($pid)) fail(400, 'Bad input');

            $stmt = $pdo->prepare("UPDATE perks SET pos_x = ?, pos_y = ? WHERE id = ? AND spec_id = ? AND category_id = ?");
            $stmt->execute([$x, $y, $pid, $sid, $catId]);
            // Nie logujemy każdego ruchu (zaśmiecałoby log) – tylko jeżeli ktoś włączy verbose
            ok();
            break;
        }

        // ============= SAVE FULL (import / bulk replace) =============
        case 'save_full': {
            $data = $body['data'] ?? null;
            if (!is_array($data) || !isset($data['types'])) fail(400, 'Bad input');

            $pdo->beginTransaction();
            try {
                // Czyszczenie i zastąpienie. Foreign keys z ON DELETE CASCADE robią robotę.
                $pdo->exec("DELETE FROM perks");
                $pdo->exec("DELETE FROM specializations");
                // Kategorie zostają (tylko 4) – aktualizujemy
                $catUpd = $pdo->prepare("UPDATE categories SET name = ?, accent = ?, levels_json = ? WHERE id = ?");
                $specIns = $pdo->prepare("INSERT INTO specializations (id, category_id, label, description, color, btn_color, sort_order) VALUES (?,?,?,?,?,?,?)");
                $perkIns = $pdo->prepare("INSERT INTO perks (id, spec_id, category_id, label, image, required_level, pos_x, pos_y, required_perks_json, levels_json) VALUES (?,?,?,?,?,?,?,?,?,?)");

                foreach ($data['types'] as $catId => $type) {
                    if (!validId((string)$catId, 32)) continue;
                    $levels = $type['levels'] ?? [];
                    $catUpd->execute([
                        (string)($type['name'] ?? $catId),
                        (string)($type['accent'] ?? '#7d8a78'),
                        json_encode($levels, JSON_UNESCAPED_UNICODE),
                        $catId,
                    ]);
                    $specs = $type['specializations'] ?? [];
                    $sortS = 0;
                    foreach ($specs as $sid => $spec) {
                        if (!validId((string)$sid)) continue;
                        $specIns->execute([
                            (string)$sid,
                            (string)$catId,
                            (string)($spec['label'] ?? $sid),
                            (string)($spec['desc'] ?? ''),
                            (string)($spec['color'] ?? '#7d8a78'),
                            (string)($spec['btnColor'] ?? '#7d8a7840'),
                            $sortS++,
                        ]);
                        $perks = $spec['perks'] ?? [];
                        foreach ($perks as $pid => $perk) {
                            if (!validId((string)$pid)) continue;
                            $req = $perk['requiredPerks'] ?? [];
                            if (!is_array($req) && !is_object($req)) $req = [];
                            $lvl = $perk['levels'] ?? [['desc' => '']];
                            if (!is_array($lvl) || empty($lvl)) $lvl = [['desc' => '']];
                            $perkIns->execute([
                                (string)$pid,
                                (string)$sid,
                                (string)$catId,
                                (string)($perk['label'] ?? $pid),
                                (string)($perk['image'] ?? ''),
                                (int)($perk['requiredLevel'] ?? 1),
                                (int)($perk['x'] ?? 200),
                                (int)($perk['y'] ?? 200),
                                json_encode($req, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT),
                                json_encode($lvl, JSON_UNESCAPED_UNICODE),
                            ]);
                        }
                    }
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            logChange('save_full', 'all', null, ['size' => strlen(json_encode($data))], $clientId, $ip);
            ok();
            break;
        }

        // ============= LOG (do debugowania z frontu, opcjonalne) =============
        case 'log': {
            $msg = (string)($body['message'] ?? '');
            logChange('client_log', 'client', null, ['message' => $msg, 'data' => $body['data'] ?? null], $clientId, $ip);
            ok();
            break;
        }

        default:
            fail(400, 'Unknown action: ' . htmlspecialchars($action));
    }

} catch (Throwable $e) {
    error_log('[skilltree-api] ' . $e->getMessage());
    fail(500, 'Server error: ' . $e->getMessage());
}
