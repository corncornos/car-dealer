<?php
/**
 * sheets_sync.php
 * ===============
 * Drop this file in your car-dealer/ root folder.
 * Include it in config.php with: require_once __DIR__ . '/sheets_sync.php';
 *
 * Then call after any DB write:
 *   SheetsSync::push('vehicles');
 *   SheetsSync::push('sales');
 *   SheetsSync::push('customers');
 *   SheetsSync::push('reservations');
 *   SheetsSync::push('all');
 * 
 * 
 *
 * Place credentials.json in the same folder as this file.
 */

class SheetsSync
{
    const CREDENTIALS_FILE = __DIR__ . '/credentials.json';
    const SPREADSHEET_NAME = 'Autoluxe Car Dealer'; // ← match your sheet name exactly

    // Cache token in memory for this request
    private static ?string $token         = null;
    private static ?string $spreadsheetId = null;
    private static ?array  $tabIds        = null;

    // ── Public entry point ────────────────────────────────────────
    /**
     * Push one or all tables to Google Sheets.
     * Runs synchronously but redirect happens AFTER this completes.
     * Typical time: 1–3 seconds per table.
     *
     * @param string $table 'vehicles'|'sales'|'customers'|'reservations'|'all'
     */
    public static function push(string $table = 'all'): void
    {
        if (!file_exists(self::CREDENTIALS_FILE)) return;

        try {
            $pdo    = getPDO();
            $token  = self::getToken();
            $sid    = self::getSpreadsheetId($token);
            $tabIds = self::getTabIds($token, $sid);

            $tables = $table === 'all'
                ? ['vehicles', 'sales', 'customers', 'reservations']
                : [$table];

            foreach ($tables as $t) {
                $rows = self::getRows($pdo, $t);
                self::ensureTab($token, $sid, self::tabName($t), $tabIds);
                self::writeTab($token, $sid, self::tabName($t), $rows);
                self::formatHeader($token, $sid, $tabIds[self::tabName($t)] ?? 0);
            }
        } catch (Throwable $e) {
            // Never crash the main app — just log
            error_log('[SheetsSync] ' . $e->getMessage());
        }
    }

    // ── Tab name mapping ──────────────────────────────────────────
    private static function tabName(string $table): string
    {
        return match($table) {
            'vehicles'     => 'Vehicles',
            'sales'        => 'Sales',
            'customers'    => 'Customers',
            'reservations' => 'Reservations',
            default        => ucfirst($table),
        };
    }

    // ── Query data from MySQL ─────────────────────────────────────
    private static function getRows(PDO $pdo, string $table): array
    {
        $queries = [
            'vehicles' => "
                SELECT id, year, brand, model, transmission, mileage,
                       plate_number, body_type, color, engine_type,
                       fuel_type, status, purchase_price, selling_price,
                       notes, created_at
                FROM vehicles
                ORDER BY created_at DESC",

            'sales' => "
                SELECT s.id, v.brand, v.model, v.plate_number,
                       s.buyer_name, s.sale_price, s.sale_date,
                       s.payment_method, s.created_at
                FROM sales s
                JOIN vehicles v ON v.id = s.vehicle_id
                ORDER BY s.sale_date DESC",

            'customers' => "
                SELECT id, last_name, first_name, middle_name, date_created
                FROM customers
                ORDER BY date_created DESC",

            'reservations' => "
                SELECT r.id,
                       CONCAT(IFNULL(c.first_name,''), ' ', IFNULL(c.last_name,'')) AS customer_name,
                       r.contact, r.reservation_payment,
                       v.brand, v.model, v.plate_number, r.created_at
                FROM reservations r
                LEFT JOIN customers c ON c.id = r.customer_id
                LEFT JOIN vehicles  v ON v.id = r.vehicle_id
                ORDER BY r.created_at DESC",
        ];

        if (!isset($queries[$table])) return [];

        $stmt = $pdo->query($queries[$table]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) return [['No data']];

        // Header row
        $headers = array_map(
            fn($h) => ucwords(str_replace('_', ' ', $h)),
            array_keys($rows[0])
        );

        // Data rows — all values as strings
        $data = [$headers];
        foreach ($rows as $row) {
            $data[] = array_map(
                fn($v) => $v === null ? '' : (string)$v,
                array_values($row)
            );
        }

        return $data;
    }

    // ── Google OAuth2 via JWT ─────────────────────────────────────
    private static function getToken(): string
    {
        if (self::$token !== null) return self::$token;

        $creds = json_decode(file_get_contents(self::CREDENTIALS_FILE), true);
        if (!$creds || ($creds['type'] ?? '') !== 'service_account') {
            throw new RuntimeException('Invalid credentials.json');
        }

        $now   = time();
        $hdr   = self::b64u(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claim = self::b64u(json_encode([
            'iss'   => $creds['client_email'],
            'scope' => 'https://www.googleapis.com/auth/spreadsheets '
                     . 'https://www.googleapis.com/auth/drive.readonly',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));

        $key = openssl_pkey_get_private($creds['private_key']);
        if (!$key) throw new RuntimeException('Cannot load private key from credentials.json');

        openssl_sign("$hdr.$claim", $sig, $key, 'SHA256');
        $jwt = "$hdr.$claim." . self::b64u($sig);

        $res = self::post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]);

        $data = json_decode($res, true);
        if (empty($data['access_token'])) {
            throw new RuntimeException('Token error: ' . ($data['error_description'] ?? $res));
        }

        self::$token = $data['access_token'];
        return self::$token;
    }

    // ── Find spreadsheet ID by name ───────────────────────────────
    private static function getSpreadsheetId(string $token): string
    {
        if (self::$spreadsheetId !== null) return self::$spreadsheetId;

        $q   = urlencode("name='" . self::SPREADSHEET_NAME . "' and mimeType='application/vnd.google-apps.spreadsheet' and trashed=false");
        $res = json_decode(self::get("https://www.googleapis.com/drive/v3/files?q=$q&fields=files(id,name)", $token), true);

        if (empty($res['files'][0]['id'])) {
            throw new RuntimeException("Spreadsheet '" . self::SPREADSHEET_NAME . "' not found. Share it with your service account email.");
        }

        self::$spreadsheetId = $res['files'][0]['id'];
        return self::$spreadsheetId;
    }

    // ── Get all tab names + their sheet IDs ───────────────────────
    private static function getTabIds(string $token, string $sid): array
    {
        if (self::$tabIds !== null) return self::$tabIds;

        $res = json_decode(self::get(
            "https://sheets.googleapis.com/v4/spreadsheets/$sid?fields=sheets.properties",
            $token
        ), true);

        $map = [];
        foreach ($res['sheets'] ?? [] as $s) {
            $map[$s['properties']['title']] = $s['properties']['sheetId'];
        }

        self::$tabIds = $map;
        return $map;
    }

    // ── Create tab if it doesn't exist ────────────────────────────
    private static function ensureTab(string $token, string $sid, string $title, array &$tabIds): void
    {
        if (isset($tabIds[$title])) return;

        $res = json_decode(self::postJson(
            "https://sheets.googleapis.com/v4/spreadsheets/$sid:batchUpdate",
            ['requests' => [['addSheet' => ['properties' => ['title' => $title]]]]],
            $token
        ), true);

        $newId = $res['replies'][0]['addSheet']['properties']['sheetId'] ?? 0;
        $tabIds[$title]        = $newId;
        self::$tabIds[$title]  = $newId;
    }

    // ── Clear tab and write fresh rows ────────────────────────────
    private static function writeTab(string $token, string $sid, string $title, array $rows): void
    {
        if (empty($rows)) return;

        // 1. Clear everything
        $range = urlencode("$title!A1:ZZ");
        self::postJson(
            "https://sheets.googleapis.com/v4/spreadsheets/$sid/values/$range:clear",
            [],
            $token
        );

        // 2. Write all rows
        $r2 = urlencode("$title!A1");
        self::putJson(
            "https://sheets.googleapis.com/v4/spreadsheets/$sid/values/$r2?valueInputOption=RAW",
            ['values' => $rows],
            $token
        );
    }

    // ── Bold + colour header row ──────────────────────────────────
    private static function formatHeader(string $token, string $sid, int $sheetId): void
    {
        self::postJson(
            "https://sheets.googleapis.com/v4/spreadsheets/$sid:batchUpdate",
            ['requests' => [[
                'repeatCell' => [
                    'range'  => ['sheetId' => $sheetId, 'startRowIndex' => 0, 'endRowIndex' => 1],
                    'cell'   => ['userEnteredFormat' => [
                        'backgroundColor' => ['red' => 0.08, 'green' => 0.09, 'blue' => 0.18],
                        'textFormat'      => [
                            'bold'            => true,
                            'foregroundColor' => ['red' => 0.83, 'green' => 0.69, 'blue' => 0.22],
                        ],
                    ]],
                    'fields' => 'userEnteredFormat(backgroundColor,textFormat)',
                ],
            ]]],
            $token
        );
    }

    // ── cURL helpers ──────────────────────────────────────────────
    private static function b64u(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function post(string $url, array $fields): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $r = curl_exec($ch);
        if ($r === false) throw new RuntimeException('cURL: ' . curl_error($ch));
        curl_close($ch);
        return $r;
    }

    private static function get(string $url, string $token): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token"],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $r = curl_exec($ch);
        if ($r === false) throw new RuntimeException('cURL: ' . curl_error($ch));
        curl_close($ch);
        return $r;
    }

    private static function postJson(string $url, array $body, string $token): string
    {
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
        $r = curl_exec($ch);
        if ($r === false) throw new RuntimeException('cURL: ' . curl_error($ch));
        curl_close($ch);
        return $r;
    }

    private static function putJson(string $url, array $body, string $token): string
    {
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
        $r = curl_exec($ch);
        if ($r === false) throw new RuntimeException('cURL: ' . curl_error($ch));
        curl_close($ch);
        return $r;
    }
}