<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');

const AI_MAX_ROWS = 200;
const AI_MAX_TABLES = 120;
const AI_MAX_COLUMNS = 4000;

/**
 * @return array<string, mixed>
 */
function aiSuccess(array $data): array
{
    return [
        'ok' => true,
        'data' => $data,
    ];
}

/**
 * @return array<string, mixed>
 */
function aiError(string $message): array
{
    return [
        'ok' => false,
        'error' => $message,
    ];
}

/**
 * @return array<string, array<string, string>>
 */
function aiSources(): array
{
    return [
        'db78' => [
            'engine' => 'mysql',
            'database' => envOrFail('DB78_NAME'),
            'description' => 'VoIPSwitch incoming traffic (178.239.216.78)',
        ],
        'workflow' => [
            'engine' => 'mysql',
            'database' => envOrFail('WORKFLOW_NAME'),
            'description' => 'Workflow mapping (178.239.216.71)',
        ],
        'wholesale' => [
            'engine' => 'mysql',
            'database' => envOrFail('WHOLESALE_NAME'),
            'description' => 'VoIPSwitch wholesale traffic (178.239.216.77)',
        ],
        'castiphone' => [
            'engine' => 'sqlserver',
            'database' => envOrFail('CASTIPHONE_DB'),
            'description' => 'Castiphone billing (SQL Server 178.239.216.10)',
        ],
    ];
}

function aiIsReadOnlySql(string $sql): bool
{
    $trimmed = trim($sql);
    if ($trimmed === '') {
        return false;
    }

    $withoutComments = preg_replace('/\/\*.*?\*\//s', ' ', $trimmed);
    $withoutComments = preg_replace('/--.*$/m', ' ', (string)$withoutComments);
    $withoutComments = trim((string)$withoutComments);

    if (!preg_match('/^(select|with)\b/i', $withoutComments)) {
        return false;
    }

    if (preg_match('/;\s*\S+/s', $withoutComments)) {
        return false;
    }

    $forbidden = '/\b(insert|update|delete|replace|merge|alter|drop|truncate|create|grant|revoke|call|exec|execute|attach|detach|pragma|use|kill)\b/i';
    if (preg_match($forbidden, $withoutComments)) {
        return false;
    }

    return true;
}

function aiNormalizeSql(string $sql): string
{
    return rtrim(trim($sql), ';');
}

function aiApplyMysqlLimit(string $sql, int $maxRows): string
{
    if (preg_match('/\blimit\s+\d+/i', $sql)) {
        return $sql;
    }

    return $sql . ' LIMIT ' . $maxRows;
}

function aiApplySqlServerLimit(string $sql, int $maxRows): string
{
    if (preg_match('/\btop\s+\d+\b/i', $sql) || preg_match('/\boffset\s+\d+\s+rows\b/i', $sql) || preg_match('/\bfetch\s+next\s+\d+\s+rows\b/i', $sql)) {
        return $sql;
    }

    if (preg_match('/^\s*select\s+distinct\b/i', $sql)) {
        return (string)preg_replace('/^\s*select\s+distinct\b/i', 'SELECT DISTINCT TOP ' . $maxRows, $sql, 1);
    }

    if (preg_match('/^\s*select\b/i', $sql)) {
        return (string)preg_replace('/^\s*select\b/i', 'SELECT TOP ' . $maxRows, $sql, 1);
    }

    return $sql;
}

function aiGetMysqlConnection(string $source): mysqli
{
    if ($source === 'db78') {
        return getDbConnection();
    }
    if ($source === 'workflow') {
        return getWorkflowTestConnection();
    }
    if ($source === 'wholesale') {
        return getWholesaleConnection();
    }

    throw new RuntimeException('Fuente MySQL no soportada.');
}

/**
 * @return array<int, array<string, mixed>>
 */
function aiQueryMysql(mysqli $connection, string $sql): array
{
    @$connection->query('SET SESSION max_execution_time=20000');
    $result = $connection->query($sql);
    if (!$result instanceof mysqli_result) {
        $error = $connection->error;
        throw new RuntimeException('Error MySQL: ' . ($error !== '' ? $error : 'query failed'));
    }

    $rows = [];
    $count = 0;
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
        $count += 1;
        if ($count >= AI_MAX_ROWS) {
            break;
        }
    }
    $result->free();

    return $rows;
}

/**
 * @return array<int, array<string, mixed>>
 */
function aiQuerySqlServer(PDO $connection, string $sql): array
{
    $connection->setAttribute(PDO::ATTR_TIMEOUT, 20);
    $statement = $connection->query($sql);
    if ($statement === false) {
        throw new RuntimeException('Error SQL Server: query failed');
    }

    $rows = [];
    $count = 0;
    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        $rows[] = $row;
        $count += 1;
        if ($count >= AI_MAX_ROWS) {
            break;
        }
    }

    return $rows;
}

/**
 * @return array<int, array<string, mixed>>
 */
function aiGetMysqlSchema(string $source, string $tablePattern): array
{
    $connection = aiGetMysqlConnection($source);
    try {
        $where = '';
        if ($tablePattern !== '') {
            $escaped = $connection->real_escape_string('%' . $tablePattern . '%');
            $where = " AND TABLE_NAME LIKE '{$escaped}'";
        }

        $tableSql = "
            SELECT TABLE_NAME
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_TYPE = 'BASE TABLE'
              {$where}
            ORDER BY TABLE_NAME
            LIMIT " . AI_MAX_TABLES;

        $tableResult = $connection->query($tableSql);
        if (!$tableResult instanceof mysqli_result) {
            throw new RuntimeException('No se pudo leer tablas.');
        }

        $tables = [];
        while ($row = $tableResult->fetch_assoc()) {
            $table = (string)($row['TABLE_NAME'] ?? '');
            if ($table !== '') {
                $tables[$table] = [
                    'table' => $table,
                    'columns' => [],
                ];
            }
        }
        $tableResult->free();

        if (count($tables) === 0) {
            return [];
        }

        $tableNamesEscaped = array_map(
            static fn(string $table): string => "'" . str_replace("'", "''", $table) . "'",
            array_keys($tables)
        );
        $columnSql = "
            SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_KEY
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME IN (" . implode(',', $tableNamesEscaped) . ")
            ORDER BY TABLE_NAME, ORDINAL_POSITION
            LIMIT " . AI_MAX_COLUMNS;

        $columnResult = $connection->query($columnSql);
        if ($columnResult instanceof mysqli_result) {
            while ($row = $columnResult->fetch_assoc()) {
                $table = (string)($row['TABLE_NAME'] ?? '');
                if (!isset($tables[$table])) {
                    continue;
                }
                $tables[$table]['columns'][] = [
                    'name' => (string)($row['COLUMN_NAME'] ?? ''),
                    'type' => (string)($row['DATA_TYPE'] ?? ''),
                    'nullable' => (string)($row['IS_NULLABLE'] ?? ''),
                    'key' => (string)($row['COLUMN_KEY'] ?? ''),
                ];
            }
            $columnResult->free();
        }

        return array_values($tables);
    } finally {
        $connection->close();
    }
}

/**
 * @return array<int, array<string, mixed>>
 */
function aiGetSqlServerSchema(PDO $connection, string $tablePattern): array
{
    $params = [];
    $where = "TABLE_SCHEMA = 'dbo'";
    if ($tablePattern !== '') {
        $where .= ' AND TABLE_NAME LIKE :table_pattern';
        $params[':table_pattern'] = '%' . $tablePattern . '%';
    }

    $tableSql = "
        SELECT TABLE_NAME
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_TYPE = 'BASE TABLE'
          AND {$where}
        ORDER BY TABLE_NAME
    ";

    $tableStmt = $connection->prepare($tableSql);
    foreach ($params as $key => $value) {
        $tableStmt->bindValue($key, $value);
    }
    $tableStmt->execute();

    $tables = [];
    while (($row = $tableStmt->fetch()) !== false) {
        $table = (string)($row['TABLE_NAME'] ?? '');
        if ($table !== '') {
            $tables[$table] = [
                'table' => $table,
                'columns' => [],
            ];
        }
        if (count($tables) >= AI_MAX_TABLES) {
            break;
        }
    }

    if (count($tables) === 0) {
        return [];
    }

    $tableNames = array_keys($tables);
    $chunks = [];
    foreach ($tableNames as $index => $tableName) {
        $chunks[] = ':t' . $index;
    }

    $columnSql = "
        SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, IS_NULLABLE
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = 'dbo'
          AND TABLE_NAME IN (" . implode(',', $chunks) . ")
        ORDER BY TABLE_NAME, ORDINAL_POSITION
    ";

    $columnStmt = $connection->prepare($columnSql);
    foreach ($tableNames as $index => $tableName) {
        $columnStmt->bindValue(':t' . $index, $tableName);
    }
    $columnStmt->execute();

    $read = 0;
    while (($row = $columnStmt->fetch()) !== false) {
        $table = (string)($row['TABLE_NAME'] ?? '');
        if (!isset($tables[$table])) {
            continue;
        }
        $tables[$table]['columns'][] = [
            'name' => (string)($row['COLUMN_NAME'] ?? ''),
            'type' => (string)($row['DATA_TYPE'] ?? ''),
            'nullable' => (string)($row['IS_NULLABLE'] ?? ''),
            'key' => '',
        ];
        $read += 1;
        if ($read >= AI_MAX_COLUMNS) {
            break;
        }
    }

    return array_values($tables);
}

/**
 * @return array<string, mixed>
 */
function aiHandleListSources(): array
{
    $sources = [];
    foreach (aiSources() as $name => $meta) {
        $sources[] = [
            'source' => $name,
            'engine' => $meta['engine'],
            'database' => $meta['database'],
            'description' => $meta['description'],
        ];
    }

    return aiSuccess([
        'sources' => $sources,
    ]);
}

/**
 * @return array<string, mixed>
 */
function aiHandleGetSchema(string $source, string $tablePattern): array
{
    $sources = aiSources();
    if (!isset($sources[$source])) {
        throw new RuntimeException('Fuente no valida.');
    }

    if ($sources[$source]['engine'] === 'mysql') {
        $tables = aiGetMysqlSchema($source, $tablePattern);
    } else {
        $connection = getCastiphoneConnection();
        $tables = aiGetSqlServerSchema($connection, $tablePattern);
        $connection = null;
    }

    return aiSuccess([
        'source' => $source,
        'engine' => $sources[$source]['engine'],
        'database' => $sources[$source]['database'],
        'tables' => $tables,
    ]);
}

/**
 * @return array<string, mixed>
 */
function aiHandleRunQuery(string $source, string $sql): array
{
    $sources = aiSources();
    if (!isset($sources[$source])) {
        throw new RuntimeException('Fuente no valida.');
    }
    if (!aiIsReadOnlySql($sql)) {
        throw new RuntimeException('Solo se permiten consultas SELECT/CTE de solo lectura.');
    }

    $normalizedSql = aiNormalizeSql($sql);
    $engine = $sources[$source]['engine'];
    $executedSql = $normalizedSql;

    if ($engine === 'mysql') {
        $executedSql = aiApplyMysqlLimit($normalizedSql, AI_MAX_ROWS);
        $connection = aiGetMysqlConnection($source);
        try {
            $rows = aiQueryMysql($connection, $executedSql);
        } finally {
            $connection->close();
        }
    } else {
        $executedSql = aiApplySqlServerLimit($normalizedSql, AI_MAX_ROWS);
        $connection = getCastiphoneConnection();
        $rows = aiQuerySqlServer($connection, $executedSql);
        $connection = null;
    }

    $columns = [];
    if (isset($rows[0]) && is_array($rows[0])) {
        $columns = array_keys($rows[0]);
    }

    return aiSuccess([
        'source' => $source,
        'engine' => $engine,
        'database' => $sources[$source]['database'],
        'sql_executed' => $executedSql,
        'row_count' => count($rows),
        'max_rows' => AI_MAX_ROWS,
        'columns' => $columns,
        'rows' => $rows,
    ]);
}

try {
    $action = trim((string)($_REQUEST['action'] ?? ''));
    if ($action === '') {
        throw new RuntimeException('Falta action.');
    }

    if ($action === 'list_sources') {
        echo json_encode(aiHandleListSources(), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'get_schema') {
        $source = trim((string)($_REQUEST['source'] ?? ''));
        $tablePattern = trim((string)($_REQUEST['table_pattern'] ?? ''));
        if ($source === '') {
            throw new RuntimeException('Falta source.');
        }

        echo json_encode(aiHandleGetSchema($source, $tablePattern), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'run_query') {
        $source = trim((string)($_REQUEST['source'] ?? ''));
        $sql = trim((string)($_REQUEST['sql'] ?? ''));
        if ($source === '') {
            throw new RuntimeException('Falta source.');
        }
        if ($sql === '') {
            throw new RuntimeException('Falta sql.');
        }

        echo json_encode(aiHandleRunQuery($source, $sql), JSON_UNESCAPED_UNICODE);
        exit;
    }

    throw new RuntimeException('Action no soportada.');
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(aiError($e->getMessage()), JSON_UNESCAPED_UNICODE);
}
