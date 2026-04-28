<?php
/**
 * sync_to_sheets.php
 * ==================
 * Autoluxe Car Dealer — MySQL → Google Sheets Sync (Pure PHP)
 * ------------------------------------------------------------
 * No Python. No extra libraries. Runs on your existing XAMPP.
 *
 * HOW TO USE:
 *   Open browser: http://localhost/car-dealer/sync_to_sheets.php
 *   Or run via CLI: php sync_to_sheets.php
 *
 * SETUP:
 *   1. Place your credentials.json (Google service account key) 
 *      in the same folder as this file.
 *   2. Edit the CONFIG section below.
 *   3. Share your Google Sheet with the service account email.
 */

session_start();
require_once __DIR__ . '/core/config.php';

// ─────────────────────────────────────────────────────────────
// CONFIG  ← edit these
// ─────────────────────────────────────────────────────────────

define('CREDENTIALS_FILE', __DIR__ . '/credentials.json');
define('SPREADSHEET_NAME', 'Autoluxe Car Dealer'); // exact Google Sheet name

// ─────────────────────────────────────────────────────────────
// GOOGLE SHEETS API — Pure PHP (no library)
// Uses Google Service Account + JWT to get an access token,
// then calls the Sheets REST API directly.
// ─────────────────────────────────────────────────────────────

class GoogleSheets {
    private string $accessToken;
    private string $spreadsheetId;

    public function __construct(string $credentialsFile, string $spreadsheetName) {
        $this->accessToken  = $this->getAccessToken($credentialsFile);
        $this->spreadsheetId = $this->findSpreadsheetId($spreadsheetName);
    }

    // ── JWT + OAuth2 to get access token ──────────────────────
    private function getAccessToken(string $credFile): string {
        if (!file_exists($credFile)) {
            throw new Exception("credentials.json not found at: $credFile");
        }

        $creds = json_decode(file_get_contents($credFile), true);
        if (!$creds || $creds['type'] !== 'service_account') {
            throw new Exception("Invalid credentials.json — must be a service_account key.");
        }

        $now = time();

        // Build JWT header + claim
        $header = base64url_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claim  = base64url_encode(json_encode([
            'iss'   => $creds['client_email'],
            'scope' => 'https://www.googleapis.com/auth/spreadsheets https://www.googleapis.com/auth/drive.readonly',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));

        // Sign with private key
        $privateKey = openssl_pkey_get_private($creds['private_key']);
        if (!$privateKey) {
            throw new Exception("Failed to load private key from credentials.json.");
        }
        openssl_sign("$header.$claim", $signature, $privateKey, 'SHA256');
        $jwt = "$header.$claim." . base64url_encode($signature);

        // Exchange JWT for access token
        $response = http_post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]);

        $data = json_decode($response, true);
        if (empty($data['access_token'])) {
            throw new Exception("Failed to get access token: " . ($data['error_description'] ?? $response));
        }

        return $data['access_token'];
    }

    // ── Find spreadsheet ID by name via Drive API ─────────────
    private function findSpreadsheetId(string $name): string {
        $encoded = urlencode("name='$name' and mimeType='application/vnd.google-apps.spreadsheet' and trashed=false");
        $url = "https://www.googleapis.com/drive/v3/files?q=$encoded&fields=files(id,name)";

        $result = json_decode($this->apiGet($url), true);

        if (empty($result['files'])) {
            throw new Exception("Spreadsheet '$name' not found. Make sure it exists and is shared with your service account.");
        }

        return $result['files'][0]['id'];
    }

    // ── Get all existing sheet (tab) names and their IDs ──────
    public function getSheets(): array {
        $url  = "https://sheets.googleapis.com/v4/spreadsheets/{$this->spreadsheetId}?fields=sheets.properties";
        $data = json_decode($this->apiGet($url), true);
        $map  = [];
        foreach ($data['sheets'] ?? [] as $s) {
            $map[$s['properties']['title']] = $s['properties']['sheetId'];
        }
        return $map;
    }

    // ── Ensure a tab exists, create if missing ────────────────
    public function ensureSheet(string $title): void {
        $sheets = $this->getSheets();
        if (isset($sheets[$title])) return;

        $this->batchUpdate([[
            'addSheet' => ['properties' => ['title' => $title]]
        ]]);
    }

    // ── Clear a tab ───────────────────────────────────────────
    public function clearSheet(string $title): void {
        $range = urlencode("$title!A1:ZZ");
        $url   = "https://sheets.googleapis.com/v4/spreadsheets/{$this->spreadsheetId}/values/$range:clear";
        $this->apiPost($url, []);
    }

    // ── Write rows to a tab (header + data) ───────────────────
    public function writeSheet(string $title, array $rows): void {
        $range   = urlencode("$title!A1");
        $url     = "https://sheets.googleapis.com/v4/spreadsheets/{$this->spreadsheetId}/values/$range"
                 . "?valueInputOption=RAW";
        $payload = ['values' => $rows];
        $this->apiPut($url, $payload);
    }

    // ── Bold + colour the header row ──────────────────────────
    public function formatHeader(string $title): void {
        $sheets  = $this->getSheets();
        $sheetId = $sheets[$title] ?? null;
        if ($sheetId === null) return;

        $this->batchUpdate([[
            'repeatCell' => [
                'range'  => ['sheetId' => $sheetId, 'startRowIndex' => 0, 'endRowIndex' => 1],
                'cell'   => [
                    'userEnteredFormat' => [
                        'backgroundColor' => ['red' => 0.08, 'green' => 0.09, 'blue' => 0.18],
                        'textFormat'      => [
                            'bold'            => true,
                            'foregroundColor' => ['red' => 0.83, 'green' => 0.69, 'blue' => 0.22],
                        ],
                    ],
                ],
                'fields' => 'userEnteredFormat(backgroundColor,textFormat)',
            ],
        ]]);
    }

    // ── Generic API helpers ───────────────────────────────────
    private function apiGet(string $url): string {
        return http_get($url, $this->accessToken);
    }

    private function apiPost(string $url, array $body): string {
        return http_post_json($url, $body, $this->accessToken);
    }

    private function apiPut(string $url, array $body): string {
        return http_put_json($url, $body, $this->accessToken);
    }

    private function batchUpdate(array $requests): string {
        $url = "https://sheets.googleapis.com/v4/spreadsheets/{$this->spreadsheetId}:batchUpdate";
        return $this->apiPost($url, ['requests' => $requests]);
    }
}

// ─────────────────────────────────────────────────────────────
// HTTP HELPERS (cURL wrappers)
// ─────────────────────────────────────────────────────────────

function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function http_post(string $url, array $fields): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $result = curl_exec($ch);
    if ($result === false) throw new Exception("cURL error: " . curl_error($ch));
    curl_close($ch);
    return $result;
}

function http_post_json(string $url, array $body, string $token): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer $token",
            "Content-Type: application/json",
        ],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $result = curl_exec($ch);
    if ($result === false) throw new Exception("cURL error: " . curl_error($ch));
    curl_close($ch);
    return $result;
}

function http_put_json(string $url, array $body, string $token): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer $token",
            "Content-Type: application/json",
        ],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $result = curl_exec($ch);
    if ($result === false) throw new Exception("cURL error: " . curl_error($ch));
    curl_close($ch);
    return $result;
}

function http_get(string $url, string $token): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token"],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $result = curl_exec($ch);
    if ($result === false) throw new Exception("cURL error: " . curl_error($ch));
    curl_close($ch);
    return $result;
}

// ─────────────────────────────────────────────────────────────
// TABLE QUERIES
// ─────────────────────────────────────────────────────────────

function getTables(PDO $pdo): array {
    return [

        'Vehicles' => (function() use ($pdo) {
            $s = $pdo->query("
                SELECT id, year, brand, model, transmission, mileage,
                       plate_number, body_type, color, engine_type,
                       fuel_type, status, purchase_price, selling_price,
                       notes, created_at
                FROM vehicles ORDER BY created_at DESC
            ");
            return $s->fetchAll(PDO::FETCH_ASSOC);
        })(),

        'Sales' => (function() use ($pdo) {
            $s = $pdo->query("
                SELECT s.id, v.brand, v.model, v.plate_number,
                       s.buyer_name, s.sale_price, s.sale_date,
                       s.payment_method, s.created_at
                FROM sales s
                JOIN vehicles v ON v.id = s.vehicle_id
                ORDER BY s.sale_date DESC
            ");
            return $s->fetchAll(PDO::FETCH_ASSOC);
        })(),

        'Customers' => (function() use ($pdo) {
            $s = $pdo->query("
                SELECT id, last_name, first_name, middle_name, date_created
                FROM customers ORDER BY date_created DESC
            ");
            return $s->fetchAll(PDO::FETCH_ASSOC);
        })(),

        'Reservations' => (function() use ($pdo) {
            $s = $pdo->query("
                SELECT r.id,
                       CONCAT(c.first_name,' ',c.last_name) AS customer_name,
                       r.contact, r.reservation_payment,
                       v.brand, v.model, v.plate_number, r.created_at
                FROM reservations r
                LEFT JOIN customers c ON c.id = r.customer_id
                LEFT JOIN vehicles  v ON v.id = r.vehicle_id
                ORDER BY r.created_at DESC
            ");
            return $s->fetchAll(PDO::FETCH_ASSOC);
        })(),

        'Viewings' => (function() use ($pdo) {
            $s = $pdo->query("
                SELECT vs.id,
                       CONCAT(c.first_name,' ',c.last_name) AS customer_name,
                       v.brand, v.model, v.plate_number,
                       vs.viewing_date, vs.created_at
                FROM viewing_schedules vs
                LEFT JOIN customers c ON c.id = vs.customer_id
                LEFT JOIN vehicles  v ON v.id = vs.vehicle_id
                ORDER BY vs.viewing_date DESC
            ");
            return $s->fetchAll(PDO::FETCH_ASSOC);
        })(),

        'Audit Logs' => (function() use ($pdo) {
            $s = $pdo->query("
                SELECT id, created_at, user_name, action, detail, ip
                FROM audit_logs
                ORDER BY created_at DESC
                LIMIT 2000
            ");
            return $s->fetchAll(PDO::FETCH_ASSOC);
        })(),
    ];
}

// ─────────────────────────────────────────────────────────────
// CONVERT DB rows → 2D array for Sheets (header + rows)
// ─────────────────────────────────────────────────────────────

function toSheetRows(array $rows): array {
    if (empty($rows)) return [['No data']];
    $headers   = array_keys($rows[0]);
    $sheetRows = [array_map('ucwords', array_map(fn($h) => str_replace('_', ' ', $h), $headers))];
    foreach ($rows as $row) {
        $sheetRows[] = array_map(fn($v) => $v === null ? '' : (string)$v, array_values($row));
    }
    return $sheetRows;
}

// ─────────────────────────────────────────────────────────────
// MAIN — run sync
// ─────────────────────────────────────────────────────────────

// Only allow logged-in admins when accessed via browser
$isCli = (php_sapi_name() === 'cli');
if (!$isCli && (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin')) {
    http_response_code(403);
    die('<b>Access denied.</b> Admin login required.');
}

// Which table to sync? (optional GET/CLI param)
$only = $isCli ? ($argv[1] ?? null) : ($_GET['table'] ?? null);

$results = [];
$overallSuccess = true;

try {
    // 1. Connect to MySQL
    $pdo = getPDO();

    // 2. Connect to Google Sheets
    $gs = new GoogleSheets(CREDENTIALS_FILE, SPREADSHEET_NAME);

    // 3. Fetch all tables
    $tables = getTables($pdo);
    if ($only) {
        $key = ucfirst(strtolower($only));
        if (!isset($tables[$key])) {
            throw new Exception("Unknown table '$only'. Valid: " . implode(', ', array_keys($tables)));
        }
        $tables = [$key => $tables[$key]];
    }

    // 4. Sync each table
    foreach ($tables as $tabName => $rows) {
        try {
            $gs->ensureSheet($tabName);
            $gs->clearSheet($tabName);
            $gs->writeSheet($tabName, toSheetRows($rows));
            $gs->formatHeader($tabName);
            $results[$tabName] = ['ok' => true, 'rows' => count($rows)];
        } catch (Exception $e) {
            $results[$tabName] = ['ok' => false, 'error' => $e->getMessage()];
            $overallSuccess = false;
        }
    }

} catch (Exception $e) {
    $results['__fatal'] = ['ok' => false, 'error' => $e->getMessage()];
    $overallSuccess = false;
}

// ─────────────────────────────────────────────────────────────
// OUTPUT
// ─────────────────────────────────────────────────────────────

if ($isCli) {
    // CLI output
    echo "\n=== Autoluxe → Google Sheets Sync ===\n\n";
    foreach ($results as $tab => $r) {
        if ($tab === '__fatal') { echo "FATAL ERROR: {$r['error']}\n"; continue; }
        $status = $r['ok'] ? "OK  ({$r['rows']} rows)" : "FAILED — {$r['error']}";
        echo sprintf("  %-20s %s\n", $tab, $status);
    }
    echo "\nDone: " . date('Y-m-d H:i:s') . "\n\n";

} else {
    // Browser output
    $bg   = $overallSuccess ? '#edfaf1' : '#fdf0ef';
    $head = $overallSuccess ? '#27ae60' : '#e74c3c';
    $msg  = $overallSuccess ? '✔ Sync Completed Successfully' : '✘ Sync Completed With Errors';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Google Sheets Sync — Autoluxe</title>
<style>
  body { font-family: Arial, sans-serif; background: #f4f6f9; display: flex; justify-content: center; padding: 40px; }
  .card { background: #fff; border-radius: 10px; box-shadow: 0 2px 16px rgba(0,0,0,0.10); max-width: 560px; width: 100%; overflow: hidden; }
  .card-header { background: #1a1a2e; color: #d4af37; padding: 20px 28px; font-size: 18px; font-weight: 700; letter-spacing: 0.5px; }
  .card-body { padding: 28px; }
  .status-banner { background: <?= $bg ?>; border-radius: 6px; padding: 14px 18px; font-size: 15px; font-weight: 600; color: <?= $head ?>; margin-bottom: 22px; }
  table { width: 100%; border-collapse: collapse; font-size: 14px; }
  th { background: #f0f2f5; color: #555; padding: 9px 14px; text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
  td { padding: 10px 14px; border-bottom: 1px solid #f0f0f0; }
  .ok  { color: #27ae60; font-weight: 600; }
  .err { color: #e74c3c; font-size: 12px; }
  .btn { display: inline-block; margin-top: 22px; padding: 10px 24px; background: #1a1a2e; color: #d4af37; border-radius: 6px; text-decoration: none; font-size: 14px; font-weight: 600; }
  .btn:hover { background: #0f3460; }
  .ts { font-size: 12px; color: #aaa; margin-top: 16px; }
</style>
</head>
<body>
<div class="card">
  <div class="card-header">📊 Google Sheets Sync — Autoluxe</div>
  <div class="card-body">
    <div class="status-banner"><?= $msg ?></div>
    <table>
      <thead><tr><th>Tab</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($results as $tab => $r): ?>
        <?php if ($tab === '__fatal'): ?>
          <tr><td colspan="2" class="err">⚠ Fatal: <?= htmlspecialchars($r['error']) ?></td></tr>
        <?php elseif ($r['ok']): ?>
          <tr><td><?= htmlspecialchars($tab) ?></td><td class="ok">✔ <?= $r['rows'] ?> rows synced</td></tr>
        <?php else: ?>
          <tr><td><?= htmlspecialchars($tab) ?></td><td class="err">✘ <?= htmlspecialchars($r['error']) ?></td></tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="ts">Synced at: <?= date('F j, Y — g:i A') ?></p>
    <a href="dashboard.php" class="btn">← Back to Dashboard</a>
  </div>
</div>
</body>
</html>
<?php } ?>