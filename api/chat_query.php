<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * @return array<string, mixed>
 */
function success(array $data): array
{
    return [
        'ok' => true,
        'data' => $data,
    ];
}

/**
 * @return array<string, mixed>
 */
function failure(string $message): array
{
    return [
        'ok' => false,
        'error' => $message,
    ];
}

function normalizeForMatch(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if (is_string($ascii) && $ascii !== '') {
        $value = $ascii;
    }

    return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
}

/**
 * @return array{id_column:string|null,name_columns:array<int,string>}
 */
function detectWorkflowResellerColumns(mysqli $connection): array
{
    $sql = "
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'resellers'
    ";

    $result = $connection->query($sql);
    if (!$result instanceof mysqli_result) {
        return ['id_column' => null, 'name_columns' => []];
    }

    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columnName = (string)($row['COLUMN_NAME'] ?? '');
        if ($columnName !== '') {
            $columns[] = $columnName;
        }
    }
    $result->free();

    $idColumn = null;
    foreach (['id', 'id_reseller'] as $candidate) {
        if (in_array($candidate, $columns, true)) {
            $idColumn = $candidate;
            break;
        }
    }

    $nameColumns = [];
    foreach (['razon_social', 'razonsocial', 'login', 'nombre', 'name'] as $candidate) {
        if (in_array($candidate, $columns, true)) {
            $nameColumns[] = $candidate;
        }
    }

    return ['id_column' => $idColumn, 'name_columns' => $nameColumns];
}

/**
 * @param array<int,string> $nameColumns
 * @return array{id:int,name:string,score:float}|null
 */
function selectBestWorkflowMatch(array $rows, array $nameColumns, string $target): ?array
{
    $targetNormalized = normalizeForMatch($target);
    if ($targetNormalized === '') {
        return null;
    }

    $best = null;
    $bestScore = 0.0;

    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        $rowBestName = '';
        $rowBestScore = 0.0;
        foreach ($nameColumns as $column) {
            $raw = trim((string)($row[$column] ?? ''));
            if ($raw === '') {
                continue;
            }

            $normalized = normalizeForMatch($raw);
            if ($normalized === '') {
                continue;
            }

            $score = 0.0;
            if ($normalized === $targetNormalized) {
                $score = 100.0;
            } elseif (str_contains($normalized, $targetNormalized) || str_contains($targetNormalized, $normalized)) {
                $score = 86.0;
            } else {
                similar_text($normalized, $targetNormalized, $percent);
                $score = (float)$percent;
            }

            if ($score > $rowBestScore) {
                $rowBestScore = $score;
                $rowBestName = $raw;
            }
        }

        if ($rowBestScore > $bestScore) {
            $bestScore = $rowBestScore;
            $best = [
                'id' => $id,
                'name' => $rowBestName,
                'score' => $rowBestScore,
            ];
        }
    }

    if ($best === null || $best['score'] < 45.0) {
        return null;
    }

    return $best;
}

/**
 * @return array{id:int,name:string,score:float}|null
 */
function resolveWorkflowResellerByName(mysqli $connection, string $input): ?array
{
    $input = trim($input);
    if ($input === '') {
        return null;
    }

    $detected = detectWorkflowResellerColumns($connection);
    $idColumn = $detected['id_column'];
    $nameColumns = $detected['name_columns'];
    if ($idColumn === null || count($nameColumns) === 0) {
        return null;
    }

    $conditions = [];
    foreach ($nameColumns as $column) {
        $conditions[] = "`{$column}` LIKE ?";
    }
    $sql = sprintf(
        "SELECT `%s` AS id, %s FROM `resellers` WHERE %s LIMIT 80",
        $idColumn,
        implode(', ', array_map(static fn(string $c): string => "`{$c}`", $nameColumns)),
        implode(' OR ', $conditions)
    );

    $statement = $connection->prepare($sql);
    if (!$statement instanceof mysqli_stmt) {
        return null;
    }

    $like = '%' . $input . '%';
    $types = str_repeat('s', count($nameColumns));
    $params = array_fill(0, count($nameColumns), $like);
    $bind = [$types];
    foreach ($params as $idx => $paramValue) {
        $bind[] = &$params[$idx];
    }
    $statement->bind_param(...$bind);
    $statement->execute();
    $result = $statement->get_result();
    if (!$result instanceof mysqli_result) {
        $statement->close();
        return null;
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $result->free();
    $statement->close();

    return selectBestWorkflowMatch($rows, $nameColumns, $input);
}

/**
 * @return array{id:int,name:string}|null
 */
function resolveWorkflowResellerById(mysqli $connection, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $detected = detectWorkflowResellerColumns($connection);
    $idColumn = $detected['id_column'];
    $nameColumns = $detected['name_columns'];
    if ($idColumn === null || count($nameColumns) === 0) {
        return null;
    }

    $sql = sprintf(
        "SELECT `%s` AS id, %s FROM `resellers` WHERE `%s` = ? LIMIT 1",
        $idColumn,
        implode(', ', array_map(static fn(string $c): string => "`{$c}`", $nameColumns)),
        $idColumn
    );

    $statement = $connection->prepare($sql);
    if (!$statement instanceof mysqli_stmt) {
        return null;
    }

    $statement->bind_param('i', $id);
    $statement->execute();
    $result = $statement->get_result();
    if (!$result instanceof mysqli_result) {
        $statement->close();
        return null;
    }

    $row = $result->fetch_assoc();
    $result->free();
    $statement->close();
    if (!is_array($row)) {
        return null;
    }

    foreach (['razon_social', 'razonsocial', 'login', 'nombre', 'name'] as $candidate) {
        $name = trim((string)($row[$candidate] ?? ''));
        if ($name !== '') {
            return [
                'id' => (int)($row['id'] ?? 0),
                'name' => $name,
            ];
        }
    }

    return [
        'id' => (int)($row['id'] ?? 0),
        'name' => '',
    ];
}

/**
 * @param array<int,string> $nameColumns
 */
function firstNonEmptyName(array $row, array $nameColumns): string
{
    foreach ($nameColumns as $column) {
        $value = trim((string)($row[$column] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

/**
 * @return array<int,array{id:int,name:string,score:float}>
 */
function findWorkflowResellerCandidates(mysqli $connection, string $input, int $limit = 5): array
{
    $input = trim($input);
    if ($input === '') {
        return [];
    }

    $detected = detectWorkflowResellerColumns($connection);
    $idColumn = $detected['id_column'];
    $nameColumns = $detected['name_columns'];
    if ($idColumn === null || count($nameColumns) === 0) {
        return [];
    }

    $sql = sprintf(
        "SELECT `%s` AS id, %s FROM `resellers` LIMIT 3000",
        $idColumn,
        implode(', ', array_map(static fn(string $c): string => "`{$c}`", $nameColumns))
    );

    $result = $connection->query($sql);
    if (!$result instanceof mysqli_result) {
        return [];
    }

    $target = normalizeForMatch($input);
    if ($target === '') {
        $result->free();
        return [];
    }

    $candidates = [];
    while ($row = $result->fetch_assoc()) {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        $bestName = '';
        $bestScore = 0.0;
        foreach ($nameColumns as $column) {
            $raw = trim((string)($row[$column] ?? ''));
            if ($raw === '') {
                continue;
            }
            $nameParts = preg_split('/[^[:alnum:]]+/u', $raw) ?: [];
            $variants = [$raw];
            foreach ($nameParts as $part) {
                $part = trim((string)$part);
                if ($part !== '') {
                    $variants[] = $part;
                }
            }

            foreach ($variants as $variant) {
                $normalized = normalizeForMatch($variant);
                if ($normalized === '' || strlen($normalized) < 3) {
                    continue;
                }

                $score = 0.0;
                if ($normalized === $target) {
                    $score = 100.0;
                } elseif (strlen($normalized) >= 4 && (str_contains($normalized, $target) || str_contains($target, $normalized))) {
                    $score = 86.0;
                } else {
                    similar_text($normalized, $target, $percent);
                    $score = (float)$percent;
                }

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestName = $raw;
                }
            }
        }

        if ($bestScore >= 35.0) {
            $candidates[] = [
                'id' => $id,
                'name' => $bestName !== '' ? $bestName : firstNonEmptyName($row, $nameColumns),
                'score' => $bestScore,
            ];
        }
    }
    $result->free();

    usort(
        $candidates,
        static function (array $a, array $b): int {
            if ($a['score'] === $b['score']) {
                return strcmp($a['name'], $b['name']);
            }
            return $a['score'] < $b['score'] ? 1 : -1;
        }
    );

    $unique = [];
    $used = [];
    foreach ($candidates as $candidate) {
        $key = (string)$candidate['id'];
        if (isset($used[$key])) {
            continue;
        }
        $used[$key] = true;
        $unique[] = [
            'id' => (int)$candidate['id'],
            'name' => (string)$candidate['name'],
            'score' => round((float)$candidate['score'], 2),
        ];
        if (count($unique) >= $limit) {
            break;
        }
    }

    return $unique;
}

/**
 * @return array<int,array<string,mixed>>
 */
function fetchBillingByReseller(PDO $connection, int $resellerId, int $year, bool $byMonth): array
{
    $fromDateTime = sprintf('%04d-01-01 00:00:00', $year);
    $toDateTime = sprintf('%04d-01-01 00:00:00', $year + 1);

    if ($byMonth) {
        $sql = "
            SELECT
                YEAR(f.Fecha) AS anio,
                MONTH(f.Fecha) AS mes,
                SUM((fp.Importe * (100 - ISNULL(fp.Descuento, 0)) / 100.0) * ISNULL(fp.Cantidad, 1)) AS venta_total,
                SUM(ISNULL(fp.Coste, 0)) AS coste_total,
                SUM(((fp.Importe * (100 - ISNULL(fp.Descuento, 0)) / 100.0) * ISNULL(fp.Cantidad, 1)) - ISNULL(fp.Coste, 0)) AS beneficio_total
            FROM [castiphone].[dbo].[Facturas] f
            INNER JOIN [castiphone].[dbo].[FacturasProductos] fp
                ON f.[Año] = fp.[Año]
               AND f.Serie = fp.Serie
               AND f.Numero = fp.Numero
            INNER JOIN [castiphone].[dbo].[Clientes] c
                ON c.Codigo = f.Cliente
            WHERE f.Fecha >= :from_date
              AND f.Fecha < :to_date
              AND c.CodigoPlataforma = :reseller_id
              AND ISNULL(fp.TipoCobro, 0) = 0
            GROUP BY YEAR(f.Fecha), MONTH(f.Fecha)
            HAVING SUM((fp.Importe * (100 - ISNULL(fp.Descuento, 0)) / 100.0) * ISNULL(fp.Cantidad, 1)) > 0
            ORDER BY YEAR(f.Fecha), MONTH(f.Fecha)
        ";
    } else {
        $sql = "
            SELECT
                :year_value AS anio,
                NULL AS mes,
                SUM((fp.Importe * (100 - ISNULL(fp.Descuento, 0)) / 100.0) * ISNULL(fp.Cantidad, 1)) AS venta_total,
                SUM(ISNULL(fp.Coste, 0)) AS coste_total,
                SUM(((fp.Importe * (100 - ISNULL(fp.Descuento, 0)) / 100.0) * ISNULL(fp.Cantidad, 1)) - ISNULL(fp.Coste, 0)) AS beneficio_total
            FROM [castiphone].[dbo].[Facturas] f
            INNER JOIN [castiphone].[dbo].[FacturasProductos] fp
                ON f.[Año] = fp.[Año]
               AND f.Serie = fp.Serie
               AND f.Numero = fp.Numero
            INNER JOIN [castiphone].[dbo].[Clientes] c
                ON c.Codigo = f.Cliente
            WHERE f.Fecha >= :from_date
              AND f.Fecha < :to_date
              AND c.CodigoPlataforma = :reseller_id
              AND ISNULL(fp.TipoCobro, 0) = 0
            HAVING SUM((fp.Importe * (100 - ISNULL(fp.Descuento, 0)) / 100.0) * ISNULL(fp.Cantidad, 1)) > 0
        ";
    }

    $statement = $connection->prepare($sql);
    $statement->bindValue(':from_date', $fromDateTime);
    $statement->bindValue(':to_date', $toDateTime);
    $statement->bindValue(':reseller_id', $resellerId, PDO::PARAM_INT);
    if (!$byMonth) {
        $statement->bindValue(':year_value', $year, PDO::PARAM_INT);
    }
    $statement->execute();

    $rows = [];
    while ($row = $statement->fetch()) {
        $venta = (float)($row['venta_total'] ?? 0);
        $coste = (float)($row['coste_total'] ?? 0);
        $beneficio = (float)($row['beneficio_total'] ?? 0);
        $margen = $venta > 0 ? ($beneficio / $venta) * 100 : 0.0;

        $rows[] = [
            'year' => isset($row['anio']) ? (int)$row['anio'] : null,
            'month' => isset($row['mes']) ? (int)$row['mes'] : null,
            'venta_total' => $venta,
            'coste_total' => $coste,
            'beneficio_total' => $beneficio,
            'margen_pct' => $margen,
        ];
    }

    return $rows;
}

/**
 * @return array<int,array<string,mixed>>
 */
function fetchBillingByClientName(PDO $connection, string $clientName, int $year, bool $byMonth): array
{
    $fromDateTime = sprintf('%04d-01-01 00:00:00', $year);
    $toDateTime = sprintf('%04d-01-01 00:00:00', $year + 1);

    if ($byMonth) {
        $sql = "
            SELECT
                YEAR(f.Fecha) AS anio,
                MONTH(f.Fecha) AS mes,
                SUM((fp.Importe * (100 - ISNULL(fp.Descuento, 0)) / 100.0) * ISNULL(fp.Cantidad, 1)) AS venta_total,
                SUM(ISNULL(fp.Coste, 0)) AS coste_total,
                SUM(((fp.Importe * (100 - ISNULL(fp.Descuento, 0)) / 100.0) * ISNULL(fp.Cantidad, 1)) - ISNULL(fp.Coste, 0)) AS beneficio_total
            FROM [castiphone].[dbo].[Facturas] f
            INNER JOIN [castiphone].[dbo].[FacturasProductos] fp
                ON f.[Año] = fp.[Año]
               AND f.Serie = fp.Serie
               AND f.Numero = fp.Numero
            INNER JOIN [castiphone].[dbo].[Clientes] c
                ON c.Codigo = f.Cliente
            WHERE f.Fecha >= :from_date
              AND f.Fecha < :to_date
              AND c.RazonSocial LIKE :client
              AND ISNULL(fp.TipoCobro, 0) = 0
            GROUP BY YEAR(f.Fecha), MONTH(f.Fecha)
            HAVING SUM((fp.Importe * (100 - ISNULL(fp.Descuento, 0)) / 100.0) * ISNULL(fp.Cantidad, 1)) > 0
            ORDER BY YEAR(f.Fecha), MONTH(f.Fecha)
        ";
    } else {
        $sql = "
            SELECT
                :year_value AS anio,
                NULL AS mes,
                SUM((fp.Importe * (100 - ISNULL(fp.Descuento, 0)) / 100.0) * ISNULL(fp.Cantidad, 1)) AS venta_total,
                SUM(ISNULL(fp.Coste, 0)) AS coste_total,
                SUM(((fp.Importe * (100 - ISNULL(fp.Descuento, 0)) / 100.0) * ISNULL(fp.Cantidad, 1)) - ISNULL(fp.Coste, 0)) AS beneficio_total
            FROM [castiphone].[dbo].[Facturas] f
            INNER JOIN [castiphone].[dbo].[FacturasProductos] fp
                ON f.[Año] = fp.[Año]
               AND f.Serie = fp.Serie
               AND f.Numero = fp.Numero
            INNER JOIN [castiphone].[dbo].[Clientes] c
                ON c.Codigo = f.Cliente
            WHERE f.Fecha >= :from_date
              AND f.Fecha < :to_date
              AND c.RazonSocial LIKE :client
              AND ISNULL(fp.TipoCobro, 0) = 0
            HAVING SUM((fp.Importe * (100 - ISNULL(fp.Descuento, 0)) / 100.0) * ISNULL(fp.Cantidad, 1)) > 0
        ";
    }

    $statement = $connection->prepare($sql);
    $statement->bindValue(':from_date', $fromDateTime);
    $statement->bindValue(':to_date', $toDateTime);
    $statement->bindValue(':client', '%' . $clientName . '%');
    if (!$byMonth) {
        $statement->bindValue(':year_value', $year, PDO::PARAM_INT);
    }
    $statement->execute();

    $rows = [];
    while ($row = $statement->fetch()) {
        $venta = (float)($row['venta_total'] ?? 0);
        $coste = (float)($row['coste_total'] ?? 0);
        $beneficio = (float)($row['beneficio_total'] ?? 0);
        $margen = $venta > 0 ? ($beneficio / $venta) * 100 : 0.0;

        $rows[] = [
            'year' => isset($row['anio']) ? (int)$row['anio'] : null,
            'month' => isset($row['mes']) ? (int)$row['mes'] : null,
            'venta_total' => $venta,
            'coste_total' => $coste,
            'beneficio_total' => $beneficio,
            'margen_pct' => $margen,
        ];
    }

    return $rows;
}

/**
 * @param array<int,array<string,mixed>> $rows
 * @return array<string,float>
 */
function computeTotals(array $rows): array
{
    $venta = 0.0;
    $coste = 0.0;
    $beneficio = 0.0;
    foreach ($rows as $row) {
        $venta += (float)($row['venta_total'] ?? 0);
        $coste += (float)($row['coste_total'] ?? 0);
        $beneficio += (float)($row['beneficio_total'] ?? 0);
    }

    return [
        'venta_total' => $venta,
        'coste_total' => $coste,
        'beneficio_total' => $beneficio,
        'margen_pct' => $venta > 0 ? ($beneficio / $venta) * 100 : 0.0,
    ];
}

function validateIsoDate(string $value): string
{
    $value = trim($value);
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    if (!$dt instanceof DateTime || $dt->format('Y-m-d') !== $value) {
        throw new RuntimeException('Fecha no valida. Usa formato YYYY-MM-DD.');
    }
    return $value;
}

/**
 * @return array<int,array<string,mixed>>
 */
function fetchBillingByResellerRange(PDO $connection, int $resellerId, string $fromDate, string $toDate, bool $byMonth): array
{
    $fromDateTime = $fromDate . ' 00:00:00';
    $toDateTime = $toDate . ' 00:00:00';

    if ($byMonth) {
        $sql = "
            SELECT
                YEAR(f.Fecha) AS anio,
                MONTH(f.Fecha) AS mes,
                SUM((fp.Importe * (100 - ISNULL(fp.Descuento, 0)) / 100.0) * ISNULL(fp.Cantidad, 1)) AS venta_total,
                SUM(ISNULL(fp.Coste, 0)) AS coste_total,
                SUM(((fp.Importe * (100 - ISNULL(fp.Descuento, 0)) / 100.0) * ISNULL(fp.Cantidad, 1)) - ISNULL(fp.Coste, 0)) AS beneficio_total
            FROM [castiphone].[dbo].[Facturas] f
            INNER JOIN [castiphone].[dbo].[FacturasProductos] fp
                ON f.[Año] = fp.[Año]
               AND f.Serie = fp.Serie
               AND f.Numero = fp.Numero
            INNER JOIN [castiphone].[dbo].[Clientes] c
                ON c.Codigo = f.Cliente
            WHERE f.Fecha >= :from_date
              AND f.Fecha < :to_date
              AND c.CodigoPlataforma = :reseller_id
              AND ISNULL(fp.TipoCobro, 0) = 0
            GROUP BY YEAR(f.Fecha), MONTH(f.Fecha)
            HAVING SUM((fp.Importe * (100 - ISNULL(fp.Descuento, 0)) / 100.0) * ISNULL(fp.Cantidad, 1)) > 0
            ORDER BY YEAR(f.Fecha), MONTH(f.Fecha)
        ";
    } else {
        $sql = "
            SELECT
                NULL AS anio,
                NULL AS mes,
                SUM((fp.Importe * (100 - ISNULL(fp.Descuento, 0)) / 100.0) * ISNULL(fp.Cantidad, 1)) AS venta_total,
                SUM(ISNULL(fp.Coste, 0)) AS coste_total,
                SUM(((fp.Importe * (100 - ISNULL(fp.Descuento, 0)) / 100.0) * ISNULL(fp.Cantidad, 1)) - ISNULL(fp.Coste, 0)) AS beneficio_total
            FROM [castiphone].[dbo].[Facturas] f
            INNER JOIN [castiphone].[dbo].[FacturasProductos] fp
                ON f.[Año] = fp.[Año]
               AND f.Serie = fp.Serie
               AND f.Numero = fp.Numero
            INNER JOIN [castiphone].[dbo].[Clientes] c
                ON c.Codigo = f.Cliente
            WHERE f.Fecha >= :from_date
              AND f.Fecha < :to_date
              AND c.CodigoPlataforma = :reseller_id
              AND ISNULL(fp.TipoCobro, 0) = 0
            HAVING SUM((fp.Importe * (100 - ISNULL(fp.Descuento, 0)) / 100.0) * ISNULL(fp.Cantidad, 1)) > 0
        ";
    }

    $statement = $connection->prepare($sql);
    $statement->bindValue(':from_date', $fromDateTime);
    $statement->bindValue(':to_date', $toDateTime);
    $statement->bindValue(':reseller_id', $resellerId, PDO::PARAM_INT);
    $statement->execute();

    $rows = [];
    while ($row = $statement->fetch()) {
        $venta = (float)($row['venta_total'] ?? 0);
        $coste = (float)($row['coste_total'] ?? 0);
        $beneficio = (float)($row['beneficio_total'] ?? 0);
        $margen = $venta > 0 ? ($beneficio / $venta) * 100 : 0.0;

        $rows[] = [
            'year' => isset($row['anio']) ? (int)$row['anio'] : null,
            'month' => isset($row['mes']) ? (int)$row['mes'] : null,
            'venta_total' => $venta,
            'coste_total' => $coste,
            'beneficio_total' => $beneficio,
            'margen_pct' => $margen,
        ];
    }

    return $rows;
}

/**
 * @param array<int,array<string,mixed>> $rows
 * @return array<int,array<string,mixed>>
 */
function buildRanking(array $rows, string $metric): array
{
    usort(
        $rows,
        static function (array $a, array $b) use ($metric): int {
            $av = (float)($a[$metric] ?? 0);
            $bv = (float)($b[$metric] ?? 0);
            if ($av === $bv) {
                return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
            }
            return $av < $bv ? 1 : -1;
        }
    );
    return $rows;
}

/**
 * @param array<int,string> $resellerNames
 * @param array<int,int> $resellerIds
 * @return array<string,mixed>
 */
function handleBillingAnalyze(
    PDO $castiphoneConnection,
    array $resellerNames,
    array $resellerIds,
    string $fromDate,
    string $toDate,
    bool $byMonth
): array {
    $notes = [];
    $resolved = [];
    $unresolved = [];
    $resolvedMap = [];
    $workflowConnection = null;

    try {
        $workflowConnection = getWorkflowTestConnection();
    } catch (Throwable $e) {
        $notes[] = 'No se pudo abrir conexion workflow para mapeo de reseller.';
    }

    if ($workflowConnection instanceof mysqli) {
        foreach ($resellerIds as $rid) {
            $rid = (int)$rid;
            if ($rid <= 0 || isset($resolvedMap[$rid])) {
                continue;
            }
            $info = resolveWorkflowResellerById($workflowConnection, $rid);
            if ($info !== null) {
                $resolvedMap[$rid] = [
                    'id' => $rid,
                    'name' => (string)$info['name'],
                    'input' => 'id:' . $rid,
                    'score' => 100.0,
                ];
            }
        }

        foreach ($resellerNames as $rawName) {
            $name = trim((string)$rawName);
            if ($name === '') {
                continue;
            }
            $best = resolveWorkflowResellerByName($workflowConnection, $name);
            if ($best !== null) {
                $rid = (int)$best['id'];
                if (!isset($resolvedMap[$rid])) {
                    $resolvedMap[$rid] = [
                        'id' => $rid,
                        'name' => (string)$best['name'],
                        'input' => $name,
                        'score' => (float)$best['score'],
                    ];
                }
            } else {
                $candidates = findWorkflowResellerCandidates($workflowConnection, $name, 3);
                $unresolved[] = [
                    'input_name' => $name,
                    'candidates' => $candidates,
                ];
            }
        }

        $workflowConnection->close();
    }

    foreach ($resolvedMap as $item) {
        $rid = (int)$item['id'];
        $name = (string)$item['name'];
        $rows = fetchBillingByResellerRange($castiphoneConnection, $rid, $fromDate, $toDate, $byMonth);
        $totals = computeTotals($rows);
        $resolved[] = [
            'id' => $rid,
            'name' => $name,
            'input' => (string)$item['input'],
            'score' => (float)$item['score'],
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    $rankingBase = array_map(
        static function (array $item): array {
            return [
                'id' => (int)$item['id'],
                'name' => (string)$item['name'],
                'venta_total' => (float)($item['totals']['venta_total'] ?? 0),
                'beneficio_total' => (float)($item['totals']['beneficio_total'] ?? 0),
                'coste_total' => (float)($item['totals']['coste_total'] ?? 0),
                'margen_pct' => (float)($item['totals']['margen_pct'] ?? 0),
            ];
        },
        $resolved
    );

    return success([
        'query' => [
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'by_month' => $byMonth,
            'input_names' => $resellerNames,
            'input_ids' => $resellerIds,
        ],
        'resolved' => $resolved,
        'unresolved' => $unresolved,
        'ranking_by_venta' => buildRanking($rankingBase, 'venta_total'),
        'ranking_by_beneficio' => buildRanking($rankingBase, 'beneficio_total'),
        'notes' => $notes,
    ]);
}

/**
 * @return array<string,mixed>
 */
function handleBillingQuery(PDO $castiphoneConnection, string $client, int $year, int $resellerId, bool $byMonth): array
{
    $notes = [];
    $candidates = [];
    $workflowConnection = null;
    try {
        $workflowConnection = getWorkflowTestConnection();
    } catch (Throwable $e) {
        $notes[] = 'No se pudo abrir conexion workflow para mapeo de reseller.';
    }

    $resolvedResellerId = $resellerId > 0 ? $resellerId : 0;
    $resolvedResellerName = '';

    if ($resolvedResellerId <= 0 && $client !== '' && $workflowConnection instanceof mysqli) {
        $resolved = resolveWorkflowResellerByName($workflowConnection, $client);
        if ($resolved !== null) {
            $resolvedResellerId = $resolved['id'];
            $resolvedResellerName = $resolved['name'];
            $notes[] = 'Mapeado por workflowtest.resellers.';
        }
    }

    if ($resolvedResellerId > 0 && $workflowConnection instanceof mysqli && $resolvedResellerName === '') {
        $resolved = resolveWorkflowResellerById($workflowConnection, $resolvedResellerId);
        if ($resolved !== null) {
            $resolvedResellerName = $resolved['name'];
        }
    }

    if ($resolvedResellerId <= 0 && $client !== '' && $workflowConnection instanceof mysqli) {
        $candidates = findWorkflowResellerCandidates($workflowConnection, $client, 5);
    }

    if ($workflowConnection instanceof mysqli) {
        $workflowConnection->close();
    }

    if ($resolvedResellerId > 0) {
        $rows = fetchBillingByReseller($castiphoneConnection, $resolvedResellerId, $year, $byMonth);
        $totals = computeTotals($rows);
        return success([
            'query' => [
                'mode' => 'reseller',
                'client' => $client,
                'year' => $year,
                'by_month' => $byMonth,
                'reseller_id' => $resolvedResellerId,
                'reseller_name' => $resolvedResellerName,
            ],
            'rows' => $rows,
            'totals' => $totals,
            'candidates' => [],
            'notes' => $notes,
        ]);
    }

    $rows = fetchBillingByClientName($castiphoneConnection, $client, $year, $byMonth);
    $totals = computeTotals($rows);
    $notes[] = 'Filtro por castiphone.Clientes.RazonSocial LIKE.';

    return success([
        'query' => [
            'mode' => 'client_name',
            'client' => $client,
            'year' => $year,
            'by_month' => $byMonth,
            'reseller_id' => null,
            'reseller_name' => '',
        ],
        'rows' => $rows,
        'totals' => $totals,
        'candidates' => $candidates,
        'notes' => $notes,
    ]);
}

try {
    $action = trim((string)($_REQUEST['action'] ?? ''));
    if ($action === '') {
        throw new RuntimeException('Falta action.');
    }

    $castiphoneConnection = getCastiphoneConnection();

    if ($action === 'billing_query') {
        $client = trim((string)($_REQUEST['client'] ?? ''));
        $year = (int)($_REQUEST['year'] ?? 0);
        $resellerId = (int)($_REQUEST['reseller_id'] ?? 0);
        $byMonthRaw = strtolower(trim((string)($_REQUEST['by_month'] ?? '0')));
        $byMonth = in_array($byMonthRaw, ['1', 'true', 'yes'], true);

        if ($year < 2000 || $year > 2100) {
            throw new RuntimeException('Year no valido.');
        }
        if ($client === '' && $resellerId <= 0) {
            throw new RuntimeException('Debes indicar client o reseller_id.');
        }

        $payload = handleBillingQuery($castiphoneConnection, $client, $year, $resellerId, $byMonth);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'billing_analyze') {
        $namesRaw = (string)($_REQUEST['reseller_names_json'] ?? '[]');
        $idsRaw = (string)($_REQUEST['reseller_ids_json'] ?? '[]');
        $fromDate = validateIsoDate((string)($_REQUEST['date_from'] ?? ''));
        $toDate = validateIsoDate((string)($_REQUEST['date_to'] ?? ''));
        $byMonthRaw = strtolower(trim((string)($_REQUEST['by_month'] ?? '1')));
        $byMonth = in_array($byMonthRaw, ['1', 'true', 'yes'], true);

        if ($fromDate >= $toDate) {
            throw new RuntimeException('Rango de fechas no valido.');
        }

        $names = json_decode($namesRaw, true);
        $ids = json_decode($idsRaw, true);
        if (!is_array($names)) {
            $names = [];
        }
        if (!is_array($ids)) {
            $ids = [];
        }

        $resellerNames = array_values(
            array_filter(
                array_map(static fn($v): string => trim((string)$v), $names),
                static fn(string $v): bool => $v !== ''
            )
        );
        $resellerIds = array_values(
            array_filter(
                array_map(static fn($v): int => (int)$v, $ids),
                static fn(int $v): bool => $v > 0
            )
        );

        if (count($resellerNames) === 0 && count($resellerIds) === 0) {
            throw new RuntimeException('Debes indicar reseller_names_json o reseller_ids_json.');
        }

        $payload = handleBillingAnalyze($castiphoneConnection, $resellerNames, $resellerIds, $fromDate, $toDate, $byMonth);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Backward compatible action.
    if ($action === 'client_billing_year') {
        $client = trim((string)($_REQUEST['client'] ?? ''));
        $year = (int)($_REQUEST['year'] ?? 0);
        if ($client === '') {
            throw new RuntimeException('Falta client.');
        }
        if ($year < 2000 || $year > 2100) {
            throw new RuntimeException('Year no valido.');
        }

        $payload = handleBillingQuery($castiphoneConnection, $client, $year, 0, false);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    throw new RuntimeException('Action no soportada.');
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(failure($e->getMessage()), JSON_UNESCAPED_UNICODE);
}
