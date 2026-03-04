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

/**
 * @return array<string, mixed>
 */
function handleClientBillingYear(PDO $connection, string $client, int $year): array
{
    $fromDateTime = sprintf('%04d-01-01 00:00:00', $year);
    $toDateTime = sprintf('%04d-01-01 00:00:00', $year + 1);

    $sql = "
        SELECT
            c.Codigo AS cliente_codigo,
            c.RazonSocial AS cliente,
            c.CodigoPlataforma AS id_reseller,
            SUM((fp.Importe * (100 - ISNULL(fp.Descuento, 0)) / 100.0) * ISNULL(fp.Cantidad, 1)) AS venta_total,
            SUM(ISNULL(fp.Coste, 0)) AS coste_total,
            SUM(((fp.Importe * (100 - ISNULL(fp.Descuento, 0)) / 100.0) * ISNULL(fp.Cantidad, 1)) - ISNULL(fp.Coste, 0)) AS beneficio_total
        FROM [castiphone].[dbo].[Facturas] f
        INNER JOIN [castiphone].[dbo].[FacturasProductos] fp
            ON f.[año] = fp.[año]
           AND f.Serie = fp.Serie
           AND f.Numero = fp.Numero
        INNER JOIN [castiphone].[dbo].[Clientes] c
            ON c.Codigo = f.Cliente
        WHERE f.Fecha >= :from_date
          AND f.Fecha < :to_date
          AND c.RazonSocial LIKE :client
          AND ISNULL(fp.TipoCobro, 0) = 0
        GROUP BY c.Codigo, c.RazonSocial, c.CodigoPlataforma
        HAVING SUM((fp.Importe * (100 - ISNULL(fp.Descuento, 0)) / 100.0) * ISNULL(fp.Cantidad, 1)) > 0
        ORDER BY venta_total DESC
    ";

    $statement = $connection->prepare($sql);
    $statement->bindValue(':from_date', $fromDateTime);
    $statement->bindValue(':to_date', $toDateTime);
    $statement->bindValue(':client', '%' . $client . '%');
    $statement->execute();

    $rows = [];
    while ($row = $statement->fetch()) {
        $venta = (float)($row['venta_total'] ?? 0);
        $coste = (float)($row['coste_total'] ?? 0);
        $beneficio = (float)($row['beneficio_total'] ?? 0);
        $margen = $venta > 0 ? ($beneficio / $venta) * 100 : 0.0;

        $rows[] = [
            'cliente_codigo' => (int)($row['cliente_codigo'] ?? 0),
            'cliente' => (string)($row['cliente'] ?? ''),
            'id_reseller' => isset($row['id_reseller']) ? (int)$row['id_reseller'] : null,
            'venta_total' => $venta,
            'coste_total' => $coste,
            'beneficio_total' => $beneficio,
            'margen_pct' => $margen,
        ];
    }

    return success([
        'query' => [
            'action' => 'client_billing_year',
            'client' => $client,
            'year' => $year,
            'from' => $fromDateTime,
            'to' => $toDateTime,
        ],
        'rows' => $rows,
    ]);
}

try {
    $action = trim((string)($_REQUEST['action'] ?? ''));
    if ($action === '') {
        throw new RuntimeException('Falta action.');
    }

    if ($action === 'client_billing_year') {
        $client = trim((string)($_REQUEST['client'] ?? ''));
        $year = (int)($_REQUEST['year'] ?? 0);

        if ($client === '') {
            throw new RuntimeException('Falta client.');
        }
        if ($year < 2000 || $year > 2100) {
            throw new RuntimeException('Year no valido.');
        }

        $connection = getCastiphoneConnection();
        $payload = handleClientBillingYear($connection, $client, $year);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    throw new RuntimeException('Action no soportada.');
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(failure($e->getMessage()), JSON_UNESCAPED_UNICODE);
}
