<?php
declare(strict_types=1);

final class TursoConnection {
    private string $baseUrl;
    private string $token;
    private ?string $baton = null;
    private int $lastInsertId = 0;
    private int $lastAffectedRows = 0;
    private ?Closure $transport;
    private $curlHandle = null;

    public function __construct(string $url, string $token, ?Closure $transport = null) {
        $url = trim($url);
        $token = trim($token);
        if ($token === '') {
            throw new RuntimeException('Turso auth token is required. Set TURSO_AUTH_TOKEN in the backend environment.');
        }
        $this->baseUrl = self::httpBaseUrl($url);
        $this->token = $token;
        $this->transport = $transport;
    }

    public function getAttribute(int $attribute): string {
        return $attribute === PDO::ATTR_DRIVER_NAME ? 'turso' : '';
    }

    public function prepare(string $sql): TursoStatement {
        return new TursoStatement($this, $sql);
    }

    public function query(string $sql): TursoStatement {
        $stmt = $this->prepare($sql);
        $stmt->execute();
        return $stmt;
    }

    public function exec(string $sql): int {
        return $this->execute($sql)['affected_row_count'] ?? 0;
    }

    public function beginTransaction(): bool {
        $this->execute('BEGIN', [], false);
        return true;
    }

    public function commit(): bool {
        $this->execute('COMMIT', [], true);
        return true;
    }

    public function rollBack(): bool {
        $this->execute('ROLLBACK', [], true);
        return true;
    }

    public function lastInsertId(): string {
        return (string)$this->lastInsertId;
    }

    public function affectedRows(): int {
        return $this->lastAffectedRows;
    }

    public function execute(string $sql, array $params = [], ?bool $close = null): array {
        $stmt = ['sql' => $sql];
        if ($params) $stmt['args'] = array_map([self::class, 'encodeValue'], array_values($params));
        $requests = [['type' => 'execute', 'stmt' => $stmt]];
        if ($close ?? $this->baton === null) $requests[] = ['type' => 'close'];
        $payload = ['requests' => $requests];
        if ($this->baton !== null) $payload['baton'] = $this->baton;

        $response = $this->request($payload);
        $first = $response['results'][0] ?? null;
        if (($first['type'] ?? '') !== 'ok') {
            $message = $first['error']['message'] ?? $first['error'] ?? 'Turso query failed.';
            throw new RuntimeException((string)$message);
        }

        $result = $first['response']['result'] ?? [];
        $this->baton = $response['baton'] ?? null;
        $this->lastInsertId = (int)($result['last_insert_rowid'] ?? $this->lastInsertId);
        $this->lastAffectedRows = (int)($result['affected_row_count'] ?? 0);
        return $result;
    }

    public function executeBatch(array $statements): array {
        if (!$statements) return [];
        $requests = [];
        foreach ($statements as $statement) {
            $stmt = ['sql' => $statement['sql']];
            $params = $statement['params'] ?? [];
            if ($params) $stmt['args'] = array_map([self::class, 'encodeValue'], array_values($params));
            $requests[] = ['type' => 'execute', 'stmt' => $stmt];
        }
        $requests[] = ['type' => 'close'];
        $payload = ['requests' => $requests];
        if ($this->baton !== null) $payload['baton'] = $this->baton;

        $response = $this->request($payload);
        $out = [];
        foreach ($response['results'] ?? [] as $index => $item) {
            if ($index >= count($statements)) break;
            if (($item['type'] ?? '') !== 'ok') {
                $message = $item['error']['message'] ?? $item['error'] ?? 'Turso batch query failed.';
                throw new RuntimeException((string)$message);
            }
            $out[] = $item['response']['result'] ?? [];
        }
        $this->baton = $response['baton'] ?? null;
        $last = end($out) ?: [];
        $this->lastInsertId = (int)($last['last_insert_rowid'] ?? $this->lastInsertId);
        $this->lastAffectedRows = (int)($last['affected_row_count'] ?? 0);
        return $out;
    }

    /** One atomic HTTP batch; callers supply SQL/params without transaction-control statements. */
    public function executeTransaction(array $statements): array {
        if (!$statements) return [];
        if ($this->baton !== null) throw new RuntimeException('An atomic batch cannot join an open Turso stream.');
        $statements = array_values($statements);
        $steps = [['stmt' => ['sql' => 'BEGIN IMMEDIATE']]];
        foreach ($statements as $statement) {
            $sql = $statement['sql'];
            // Strip leading comments before checking the transaction owner boundary.
            $leading = preg_replace('/\A(?:\s+|--[^\r\n]*(?:\r?\n|$)|\/\*.*?\*\/)+/s', '', $sql);
            if (preg_match('/^(BEGIN|COMMIT|END|ROLLBACK|SAVEPOINT|RELEASE)\b/i', $leading)) {
                throw new InvalidArgumentException('Atomic batch statements cannot control transactions.');
            }
            $stmt = ['sql' => $sql];
            if (!empty($statement['params'])) $stmt['args'] = array_map([self::class, 'encodeValue'], array_values($statement['params']));
            $steps[] = ['condition' => ['type' => 'ok', 'step' => count($steps) - 1], 'stmt' => $stmt];
        }
        $commit = count($steps);
        $steps[] = ['condition' => ['type' => 'ok', 'step' => $commit - 1], 'stmt' => ['sql' => 'COMMIT']];
        $steps[] = ['condition' => ['type' => 'and', 'conds' => [
            ['type' => 'ok', 'step' => 0],
            ['type' => 'not', 'cond' => ['type' => 'ok', 'step' => $commit]],
        ]], 'stmt' => ['sql' => 'ROLLBACK']];

        // Pipeline requests continue after errors; the batch conditions prevent partial commits.
        $response = $this->request(['requests' => [
            ['type' => 'batch', 'batch' => ['steps' => $steps]], ['type' => 'close'],
        ]]);
        $first = $response['results'][0] ?? [];
        if (($first['type'] ?? '') !== 'ok' || ($first['response']['type'] ?? '') !== 'batch') {
            throw new RuntimeException((string)($first['error']['message'] ?? 'Turso transaction failed.'));
        }
        $result = $first['response']['result'] ?? [];
        $results = $result['step_results'] ?? [];
        $errors = $result['step_errors'] ?? [];
        if (count($results) !== count($steps) || count($errors) !== count($steps)) {
            throw new RuntimeException('Turso returned an incomplete transaction response; retry with the same request key.');
        }
        foreach ($errors as $error) {
            if ($error !== null) throw new RuntimeException((string)($error['message'] ?? 'Turso transaction step failed.'));
        }
        for ($index = 0; $index <= $commit; $index++) {
            if (!is_array($results[$index])) throw new RuntimeException('Turso transaction did not commit; retry with the same request key.');
        }
        $out = array_slice($results, 1, count($statements));
        $last = end($out);
        $this->lastInsertId = (int)($last['last_insert_rowid'] ?? $this->lastInsertId);
        $this->lastAffectedRows = (int)($last['affected_row_count'] ?? 0);
        return $out;
    }

    private function request(array $payload): array {
        $queries = 0;
        foreach ($payload['requests'] as $request) {
            $queries += $request['type'] === 'batch' ? count($request['batch']['steps']) : (int)($request['type'] === 'execute');
        }
        return RequestTiming::measure('db',fn()=>$this->transport !== null ? ($this->transport)($payload) : $this->sendRequest($payload),$queries);
    }

    private function sendRequest(array $payload): array {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension is required for Turso. Enable extension=curl in php.ini, then restart Apache.');
        }
        $ch = $this->curlHandle ??= curl_init($this->baseUrl . '/v2/pipeline');
        if (!$ch) throw new RuntimeException('Could not initialize Turso HTTP request.');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_TIMEOUT => 20,
        ]);
        $caInfo = self::caInfoPath();
        if ($caInfo !== null) curl_setopt($ch, CURLOPT_CAINFO, $caInfo);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        if ($raw === false || $raw === '') throw new RuntimeException('Turso request failed: ' . ($error ?: 'empty response'));
        $json = json_decode($raw, true);
        if (!is_array($json)) throw new RuntimeException('Turso returned an unreadable response.');
        if ($status < 200 || $status >= 300) {
            $message = $json['message'] ?? $json['error'] ?? $raw;
            throw new RuntimeException('Turso HTTP ' . $status . ': ' . $message);
        }
        return $json;
    }

    private static function caInfoPath(): ?string {
        $configured = trim((string)(ini_get('curl.cainfo') ?: ini_get('openssl.cafile') ?: ''));
        if ($configured !== '' && is_file($configured)) return null;
        $local = defined('ROOT') ? ROOT . '/config/cacert.pem' : dirname(__DIR__) . '/config/cacert.pem';
        return is_file($local) ? $local : null;
    }

    private static function httpBaseUrl(string $url): string {
        if (str_starts_with($url, 'libsql://') || str_starts_with($url, 'turso://')) {
            $url = preg_replace('/^(libsql|turso):\/\//', 'https://', $url);
        }
        return rtrim((string)$url, '/');
    }

    private static function encodeValue(mixed $value): array {
        if ($value === null) return ['type' => 'null'];
        if (is_int($value)) return ['type' => 'integer', 'value' => (string)$value];
        if (is_float($value)) return ['type' => 'float', 'value' => $value];
        if (is_bool($value)) return ['type' => 'integer', 'value' => $value ? '1' : '0'];
        return ['type' => 'text', 'value' => (string)$value];
    }
}

final class TursoStatement {
    private TursoConnection $connection;
    private string $sql;
    private array $rows = [];
    private int $affectedRows = 0;

    public function __construct(TursoConnection $connection, string $sql) {
        $this->connection = $connection;
        $this->sql = $sql;
    }

    public function execute(array $params = []): bool {
        $result = $this->connection->execute($this->sql, $params);
        $this->rows = self::decodeRows($result);
        $this->affectedRows = (int)($result['affected_row_count'] ?? 0);
        return true;
    }

    public static function decodeRows(array $result): array {
        $columns = array_map(fn($c) => $c['name'], $result['cols'] ?? []);
        $rows = [];
        foreach ($result['rows'] ?? [] as $row) {
            $assoc = [];
            foreach ($columns as $index => $name) $assoc[$name] = self::decodeValue($row[$index] ?? ['type' => 'null']);
            $rows[] = $assoc;
        }
        return $rows;
    }

    public function fetch(int $mode = PDO::FETCH_ASSOC): mixed {
        $row = array_shift($this->rows);
        if (!$row) return false;
        return $mode === PDO::FETCH_COLUMN ? reset($row) : $row;
    }

    public function fetchAll(int $mode = PDO::FETCH_ASSOC): array {
        if ($mode === PDO::FETCH_COLUMN) return array_map(fn($r) => reset($r), $this->rows);
        return $this->rows;
    }

    public function fetchColumn(int $column = 0): mixed {
        $row = $this->fetch();
        if (!$row) return false;
        return array_values($row)[$column] ?? false;
    }

    public function rowCount(): int {
        return $this->affectedRows;
    }

    private static function decodeValue(array $value): mixed {
        return match ($value['type'] ?? 'null') {
            'integer' => (int)($value['value'] ?? 0),
            'float' => (float)($value['value'] ?? 0),
            'text' => (string)($value['value'] ?? ''),
            'blob' => base64_decode((string)($value['base64'] ?? ''), true),
            default => null,
        };
    }
}
