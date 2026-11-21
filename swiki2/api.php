<?php
declare(strict_types=1);

header("Access-Control-Allow-Headers: *");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}


// ============================================================================
// Configuration & Pluggable Auth
// ============================================================================
const DB_PATH        = __DIR__ . '/wiki-v2.db';
const BLOCK_SIZE     = 4096;
const GCM_OVERHEAD   = 28; // 12-byte IV + 16-byte AuthTag
const MAX_BODY_BYTES = 16 * 1024 * 1024; // 16 MiB cap for batch writes

require_once __DIR__ . '/auth.php';

// ============================================================================
// Helpers
// ============================================================================
function jsonResponse(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function isValidKey(mixed $k): bool {
    return is_string($k) && preg_match('/^[0-9a-f]{64}$/', $k) === 1;
}

function isValidValue(mixed $v): bool {
    if (!is_string($v) || $v === '') {
        return false;
    }
    $raw = base64_decode($v, true);
    if ($raw === false) {
        return false;
    }
    $ciphertextLen = strlen($raw) - GCM_OVERHEAD;
    return $ciphertextLen > 0 && ($ciphertextLen % BLOCK_SIZE) === 0;
}

function openDatabase(): PDO {
    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA synchronous = NORMAL;');
    $pdo->exec('PRAGMA busy_timeout = 5000;');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS kv_store (
            k TEXT PRIMARY KEY NOT NULL,
            v TEXT NOT NULL
        ) WITHOUT ROWID;
    ');

    return $pdo;
}

// ============================================================================
// Request Router
// ============================================================================
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($method) {
        case 'GET': {
            if (!can_read()) {
                jsonResponse(403, ['error' => 'Read access denied']);
            }

            $k = $_GET['k'] ?? '';
            if (!isValidKey($k)) {
                jsonResponse(400, ['error' => 'Invalid key format']);
            }

            $pdo = openDatabase();
            $stmt = $pdo->prepare('SELECT v FROM kv_store WHERE k = :k');
            $stmt->execute([':k' => $k]);
            $row = $stmt->fetch();

            if (!$row) {
                jsonResponse(404, ['error' => 'Not found']);
            }

            jsonResponse(200, ['k' => $k, 'v' => $row['v']]);
        }

        case 'POST':
        case 'PUT': {
            if (!can_write()) {
                jsonResponse(403, ['error' => 'Write access denied']);
            }

            $rawBody = file_get_contents('php://input', false, null, 0, MAX_BODY_BYTES + 1);
            if ($rawBody === false || strlen($rawBody) > MAX_BODY_BYTES) {
                jsonResponse(413, ['error' => 'Payload too large']);
            }

            $data = json_decode($rawBody, true);
            if (!is_array($data)) {
                jsonResponse(400, ['error' => 'Invalid JSON body']);
            }

            // Normalize single { k, v } or batch { entries: [ { k, v }, ... ] }
            $entries = isset($data['entries']) && is_array($data['entries'])
                ? $data['entries']
                : [$data];

            if (count($entries) === 0) {
                jsonResponse(400, ['error' => 'No entries provided']);
            }

            foreach ($entries as $item) {
                if (!is_array($item) || !isValidKey($item['k'] ?? null) || !isValidValue($item['v'] ?? null)) {
                    jsonResponse(400, ['error' => 'Invalid k or unaligned/malformed v payload']);
                }
            }

            $pdo = openDatabase();
            $stmt = $pdo->prepare('
                INSERT INTO kv_store (k, v)
                VALUES (:k, :v)
                ON CONFLICT(k) DO UPDATE SET v = excluded.v
            ');

            $pdo->beginTransaction();
            try {
                foreach ($entries as $item) {
                    $stmt->execute([
                        ':k' => $item['k'],
                        ':v' => $item['v'],
                    ]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            jsonResponse(200, ['ok' => true, 'written' => count($entries)]);
        }

        case 'DELETE': {
            if (!can_write()) {
                jsonResponse(403, ['error' => 'Write access denied']);
            }

            $k = $_GET['k'] ?? '';
            if (!isValidKey($k)) {
                jsonResponse(400, ['error' => 'Invalid key format']);
            }

            $pdo = openDatabase();
            $stmt = $pdo->prepare('DELETE FROM kv_store WHERE k = :k');
            $stmt->execute([':k' => $k]);

            jsonResponse(200, ['ok' => true, 'deleted' => $stmt->rowCount()]);
        }

        default:
            header('Allow: GET, POST, PUT, DELETE');
            jsonResponse(405, ['error' => 'Method not allowed']);
    }
} catch (Throwable $e) {
    error_log('swiki api.php error: ' . $e->getMessage());
    jsonResponse(500, ['error' => 'Internal server error '.$e->getMessage()]);
}
