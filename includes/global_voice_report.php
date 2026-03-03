<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

/**
 * Normalizes a phone number to only digits and strips country prefix when present.
 */
function normalizePhoneNumber(string $value): string
{
    $digits = preg_replace('/\D+/', '', $value) ?? '';

    if (str_starts_with($digits, '0034')) {
        $digits = substr($digits, 4);
    } elseif (str_starts_with($digits, '34') && strlen($digits) > 9) {
        $digits = substr($digits, 2);
    }

    return $digits;
}

/**
 * Normalizes reseller/login text for matching across systems.
 */
function normalizeMatchKey(string $value): string
{
    $normalized = strtolower(trim($value));
    return preg_replace('/[^a-z0-9]+/', '', $normalized) ?? '';
}

/**
 * Candidate keys to match wholesale login with workflow reseller login.
 */
function buildLoginMatchCandidates(string $login): array
{
    $raw = strtolower(trim($login));
    if ($raw === '') {
        return [];
    }

    $variants = [$raw];

    // Deterministic aliases only (no fuzzy matching).
    if (str_starts_with($raw, 'geo_')) {
        $variants[] = substr($raw, 4);
    }
    if (str_starts_with($raw, 'cpbx_')) {
        $cpbxBase = substr($raw, 5);
        $variants[] = $cpbxBase;
        // Common technical suffix like CPBX_FOO2 -> FOO.
        $variants[] = preg_replace('/\d+$/', '', $cpbxBase) ?? $cpbxBase;
    }

    if (str_ends_with($raw, '_asterisk_w')) {
        $variants[] = substr($raw, 0, -11);
    }
    if (str_ends_with($raw, '_asterisk')) {
        $variants[] = substr($raw, 0, -9);
    }
    if (str_ends_with($raw, '_voip_w')) {
        $variants[] = substr($raw, 0, -7);
    }
    if (str_ends_with($raw, '_voip')) {
        $variants[] = substr($raw, 0, -5);
    }
    if (str_ends_with($raw, '_w')) {
        $variants[] = substr($raw, 0, -2);
    }

    $candidates = [];
    foreach (array_unique($variants) as $variant) {
        $normalized = normalizeMatchKey($variant);
        if ($normalized !== '') {
            $candidates[] = $normalized;
        }
    }

    return array_values(array_unique($candidates));
}

/**
 * Builds deterministic reseller index from active workflow records.
 */
function buildResellerIndex(mysqli $workflowConnection): array
{
    $resellerIndex = [];
    $ambiguousKeys = [];

    $resellerAccessSql = "
        SELECT
            ra.id_reseller,
            ra.login,
            COALESCE(r.razonsocial, CONCAT('Reseller #', ra.id_reseller)) AS reseller_name
        FROM workflowtest.resellers_accesos ra
        INNER JOIN workflowtest.resellers r ON r.id = ra.id_reseller
        WHERE ra.login IS NOT NULL
          AND ra.login <> ''
          AND ra.status = 1
          AND r.activo = 1
    ";

    $resellerAccessResult = $workflowConnection->query($resellerAccessSql);
    if ($resellerAccessResult === false) {
        throw new RuntimeException('Error al cargar accesos de reseller: ' . $workflowConnection->error);
    }

    while ($accessRow = $resellerAccessResult->fetch_assoc()) {
        $key = normalizeMatchKey((string)$accessRow['login']);
        registerResellerIndex(
            $resellerIndex,
            $ambiguousKeys,
            $key,
            (int)$accessRow['id_reseller'],
            trim((string)$accessRow['reseller_name'])
        );
    }

    $resellerFallbackSql = "
        SELECT
            id,
            identificador,
            razonsocial
        FROM workflowtest.resellers
        WHERE activo = 1
    ";

    $resellerFallbackResult = $workflowConnection->query($resellerFallbackSql);
    if ($resellerFallbackResult === false) {
        throw new RuntimeException('Error al cargar resellers fallback: ' . $workflowConnection->error);
    }

    while ($resellerRow = $resellerFallbackResult->fetch_assoc()) {
        $idReseller = (int)$resellerRow['id'];
        $resellerName = trim((string)$resellerRow['razonsocial']);
        if ($resellerName === '') {
            $resellerName = 'Reseller #' . $idReseller;
        }

        $identificadorKey = normalizeMatchKey((string)$resellerRow['identificador']);
        registerResellerIndex($resellerIndex, $ambiguousKeys, $identificadorKey, $idReseller, $resellerName);
    }

    foreach (array_keys($ambiguousKeys) as $ambiguousKey) {
        unset($resellerIndex[$ambiguousKey]);
    }

    return $resellerIndex;
}

/**
 * Maps incoming id_client to unique login for the period.
 */
function fetchIncomingClientLoginMap(mysqli $incomingConnection, string $fromDateTime, string $toDateTime): array
{
    $sql = "
        SELECT
            c.id_client,
            COUNT(DISTINCT NULLIF(TRIM(s.login), '')) AS login_count,
            MIN(NULLIF(TRIM(s.login), '')) AS login_single
        FROM voipswitch.calls c
        LEFT JOIN voipswitch.clientsip s ON s.id_client = c.id_client
        WHERE c.call_start >= ?
          AND c.call_start < ?
          AND c.costD = 0
          AND c.ip_number = '178.239.216.55'
        GROUP BY c.id_client
    ";

    $stmt = $incomingConnection->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Error preparando mapa id_client->login: ' . $incomingConnection->error);
    }
    $stmt->bind_param('ss', $fromDateTime, $toDateTime);

    if (!$stmt->execute()) {
        throw new RuntimeException('Error ejecutando mapa id_client->login: ' . $stmt->error);
    }

    $result = $stmt->get_result();
    $clientLoginMap = [];
    while ($row = $result->fetch_assoc()) {
        if ((int)$row['login_count'] === 1 && trim((string)$row['login_single']) !== '') {
            $clientLoginMap[(int)$row['id_client']] = trim((string)$row['login_single']);
        }
    }

    $stmt->close();
    return $clientLoginMap;
}

/**
 * Registers a match key and marks it as ambiguous if it points to multiple resellers.
 */
function registerResellerIndex(
    array &$index,
    array &$ambiguousKeys,
    string $key,
    int $idReseller,
    string $resellerName
): void {
    if ($key === '') {
        return;
    }

    if (!isset($index[$key])) {
        $index[$key] = [
            'id_reseller' => $idReseller,
            'reseller' => $resellerName,
        ];
        return;
    }

    if ((int)$index[$key]['id_reseller'] !== $idReseller) {
        $ambiguousKeys[$key] = true;
    }
}

/**
 * Resolves reseller by login using deterministic candidate keys.
 */
function resolveResellerByLogin(string $login, array $resellerIndex): ?array
{
    foreach (buildLoginMatchCandidates($login) as $candidateKey) {
        if (isset($resellerIndex[$candidateKey])) {
            return $resellerIndex[$candidateKey];
        }
    }

    return null;
}

/**
 * Ensures a row exists for the given reseller key.
 */
function ensureGlobalRow(array &$rows, string $rowKey, ?int $idReseller, string $resellerName): void
{
    if (!isset($rows[$rowKey])) {
        $rows[$rowKey] = [
            'id_reseller' => $idReseller !== null ? (string)$idReseller : '',
            'Reseller' => $resellerName,
            'Minutos entrantes' => 0.0,
            'Minutos salientes (W)' => 0.0,
            'Minutos salientes (R)' => 0.0,
            'Minutos salientes totales' => 0.0,
            'Facturacion wholesale' => 0.0,
            'Facturacion residencial' => 0.0,
            'Margen wholesale' => 0.0,
            'Margen residencial' => 0.0,
            'Venta servicios' => 0.0,
            'Compra servicios' => 0.0,
            'Beneficio servicios' => 0.0,
            'Margen servicios' => 0.0,
            'Facturacion Voz' => 0.0,
            'Facturacion resto' => 0.0,
        ];
    }
}

/**
 * Builds DID -> reseller maps using workflowtest as of report end date.
 */
function fetchDidResellerMap(mysqli $workflowConnection, string $toDateTime): array
{
    $didToResellerRaw = [];
    $didToResellerNormalized = [];

    $didMapSql = "
        SELECT
            s.linea,
            s.id_reseller,
            COALESCE(r.razonsocial, CONCAT('Reseller #', s.id_reseller)) AS reseller_name
        FROM workflowtest.solicitudes s
        INNER JOIN (
            SELECT linea, MAX(id) AS latest_id
            FROM workflowtest.solicitudes
            WHERE id_estado = 4
              AND linea IS NOT NULL
              AND linea <> ''
              AND fecha_estado < ?
            GROUP BY linea
        ) latest ON latest.latest_id = s.id
        LEFT JOIN workflowtest.resellers r ON r.id = s.id_reseller
    ";

    $didMapStmt = $workflowConnection->prepare($didMapSql);
    if (!$didMapStmt) {
        throw new RuntimeException('Error preparando mapeo DID->reseller: ' . $workflowConnection->error);
    }
    $didMapStmt->bind_param('s', $toDateTime);
    if (!$didMapStmt->execute()) {
        throw new RuntimeException('Error ejecutando mapeo DID->reseller: ' . $didMapStmt->error);
    }

    $didMapResult = $didMapStmt->get_result();
    while ($mapRow = $didMapResult->fetch_assoc()) {
        $linea = trim((string)$mapRow['linea']);
        if ($linea === '') {
            continue;
        }

        $idReseller = (int)$mapRow['id_reseller'];
        $resellerName = trim((string)$mapRow['reseller_name']);
        if ($resellerName === '') {
            $resellerName = 'Reseller #' . $idReseller;
        }

        $resellerData = [
            'id_reseller' => $idReseller,
            'reseller' => $resellerName,
        ];

        $didToResellerRaw[$linea] = $resellerData;

        $normalizedDid = normalizePhoneNumber($linea);
        if ($normalizedDid !== '' && !isset($didToResellerNormalized[$normalizedDid])) {
            $didToResellerNormalized[$normalizedDid] = $resellerData;
        }
    }

    $didMapStmt->close();

    return [
        'raw' => $didToResellerRaw,
        'normalized' => $didToResellerNormalized,
    ];
}

/**
 * Resolves reseller from DID using direct and normalized maps.
 */
function resolveDidReseller(string $did, array $didToResellerRaw, array $didToResellerNormalized): ?array
{
    $did = trim($did);
    if ($did === '') {
        return null;
    }

    if (isset($didToResellerRaw[$did])) {
        return $didToResellerRaw[$did];
    }

    $normalizedDid = normalizePhoneNumber($did);
    if ($normalizedDid !== '' && isset($didToResellerNormalized[$normalizedDid])) {
        return $didToResellerNormalized[$normalizedDid];
    }

    return null;
}

/**
 * Fetches monthly service billing from castiphone grouped by CodigoPlataforma (workflow id_reseller).
 */
function fetchServiceBillingByReseller(PDO $castiphoneConnection, string $fromDateTime, string $toDateTime): array
{
    $yearColumn = '[año]';

    $sql = "
        SELECT
            c.CodigoPlataforma AS id_reseller,
            SUM((fp.Importe * (100 - fp.Descuento) / 100.0) * fp.Cantidad) AS venta_servicios,
            SUM(fp.Coste) AS compra_servicios
        FROM [castiphone].[dbo].[Facturas] f
        INNER JOIN [castiphone].[dbo].[FacturasProductos] fp
            ON f.{$yearColumn} = fp.{$yearColumn}
           AND f.Serie = fp.Serie
           AND f.Numero = fp.Numero
        INNER JOIN [castiphone].[dbo].[Clientes] c
            ON c.Codigo = f.Cliente
        WHERE f.Fecha >= :from_date
          AND f.Fecha < :to_date
          AND c.CodigoPlataforma IS NOT NULL
          AND c.CodigoPlataforma > 0
          AND ISNULL(fp.tipoCobro, 0) = 0
        GROUP BY c.CodigoPlataforma
    ";

    $stmt = $castiphoneConnection->prepare($sql);
    $stmt->bindValue(':from_date', $fromDateTime);
    $stmt->bindValue(':to_date', $toDateTime);
    $stmt->execute();

    $billingByReseller = [];
    while ($row = $stmt->fetch()) {
        $idReseller = (int)($row['id_reseller'] ?? 0);
        if ($idReseller <= 0) {
            continue;
        }

        $venta = (float)($row['venta_servicios'] ?? 0);
        $compra = (float)($row['compra_servicios'] ?? 0);
        $beneficio = $venta - $compra;
        $margen = $venta > 0 ? ($beneficio / $venta) * 100 : 0.0;

        $billingByReseller[$idReseller] = [
            'Venta servicios' => $venta,
            'Compra servicios' => $compra,
            'Beneficio servicios' => $beneficio,
            'Margen servicios' => $margen,
        ];
    }

    return $billingByReseller;
}

/**
 * New global voice report by reseller.
 */
function runGlobalVoiceReport(string $fromDate, string $toDate): array
{
    // Cross-server monthly aggregates can take longer than standard PHP timeout.
    @set_time_limit(300);

    $fromDateTime = $fromDate . ' 00:00:00';
    $toDateTime = $toDate . ' 00:00:00';

    $incomingConnection = getDbConnection();
    $workflowConnection = getWorkflowTestConnection();
    $wholesaleConnection = getWholesaleConnection();
    $castiphoneConnection = null;

    try {
        // 1) DID -> reseller map from workflowtest.solicitudes (latest accepted row per DID).
        $didMap = fetchDidResellerMap($workflowConnection, $toDateTime);
        $didToResellerRaw = $didMap['raw'];
        $didToResellerNormalized = $didMap['normalized'];
        $resellerIndex = buildResellerIndex($workflowConnection);
        $incomingClientLogins = fetchIncomingClientLoginMap($incomingConnection, $fromDateTime, $toDateTime);

        // 2) Incoming minutes grouped by DID from 178.239.216.78.
        $incomingSql = "
            SELECT
                c.called_number,
                c.id_client,
                SUM(c.duration) / 60 AS minutos_entrantes
            FROM voipswitch.calls c
            WHERE c.call_start >= ?
              AND c.call_start < ?
              AND c.costD = 0
              AND c.ip_number = '178.239.216.55'
            GROUP BY c.called_number, c.id_client
        ";

        $incomingStmt = $incomingConnection->prepare($incomingSql);
        if (!$incomingStmt) {
            throw new RuntimeException('Error preparando entrantes: ' . $incomingConnection->error);
        }
        $incomingStmt->bind_param('ss', $fromDateTime, $toDateTime);

        if (!$incomingStmt->execute()) {
            throw new RuntimeException('Error ejecutando entrantes: ' . $incomingStmt->error);
        }

        $incomingRowsResult = $incomingStmt->get_result();
        $globalRows = [];

        while ($incomingRow = $incomingRowsResult->fetch_assoc()) {
            $did = trim((string)$incomingRow['called_number']);
            $minutes = (float)$incomingRow['minutos_entrantes'];

            $resellerData = resolveDidReseller($did, $didToResellerRaw, $didToResellerNormalized);

            if ($resellerData === null) {
                $idClient = (int)($incomingRow['id_client'] ?? 0);
                $login = $incomingClientLogins[$idClient] ?? '';
                $mappedByClient = null;

                foreach (buildLoginMatchCandidates($login) as $candidateKey) {
                    if (isset($resellerIndex[$candidateKey])) {
                        $mappedByClient = $resellerIndex[$candidateKey];
                        break;
                    }
                }

                if ($mappedByClient !== null) {
                    $rowKey = 'reseller_' . $mappedByClient['id_reseller'];
                    ensureGlobalRow($globalRows, $rowKey, (int)$mappedByClient['id_reseller'], $mappedByClient['reseller']);
                    $globalRows[$rowKey]['Minutos entrantes'] += $minutes;
                    continue;
                }

                $rowKey = 'id_client_unmapped_' . $idClient;
                $rowName = '(id_client no asociado) ' . $idClient;
                ensureGlobalRow($globalRows, $rowKey, null, $rowName);
                $globalRows[$rowKey]['Minutos entrantes'] += $minutes;
                continue;
            }

            $rowKey = 'reseller_' . $resellerData['id_reseller'];
            ensureGlobalRow($globalRows, $rowKey, (int)$resellerData['id_reseller'], $resellerData['reseller']);
            $globalRows[$rowKey]['Minutos entrantes'] += $minutes;
        }

        $incomingStmt->close();

        // 3) Outgoing wholesale minutes grouped by client login from 178.239.216.77.
        $wholesaleSql = "
            SELECT
                c.id_client,
                COALESCE(MAX(s.login), CONCAT('id_client_', c.id_client)) AS wholesale_login,
                SUM(c.duration) / 60 AS minutos_salientes,
                SUM(c.cost) AS facturacion_wholesale,
                SUM(c.costd) AS coste_wholesale
            FROM voipswitch.calls c
            LEFT JOIN voipswitch.clientsip s ON s.id_client = c.id_client
            WHERE c.call_start >= ?
              AND c.call_start < ?
              AND c.ip_number <> '178.239.216.55'
              AND c.id_client <> 49
            GROUP BY c.id_client
        ";

        $wholesaleStmt = $wholesaleConnection->prepare($wholesaleSql);
        if (!$wholesaleStmt) {
            throw new RuntimeException('Error preparando salientes wholesale: ' . $wholesaleConnection->error);
        }
        $wholesaleStmt->bind_param('ss', $fromDateTime, $toDateTime);

        if (!$wholesaleStmt->execute()) {
            throw new RuntimeException('Error ejecutando salientes wholesale: ' . $wholesaleStmt->error);
        }

        $wholesaleRowsResult = $wholesaleStmt->get_result();
        while ($wholesaleRow = $wholesaleRowsResult->fetch_assoc()) {
            $login = trim((string)$wholesaleRow['wholesale_login']);
            $minutes = (float)$wholesaleRow['minutos_salientes'];
            $billing = (float)($wholesaleRow['facturacion_wholesale'] ?? 0);
            $companyCost = (float)($wholesaleRow['coste_wholesale'] ?? 0);
            $margin = $billing - $companyCost;

            $mappedReseller = null;
            $mappedReseller = resolveResellerByLogin($login, $resellerIndex);

            if ($mappedReseller === null) {
                $rowKey = 'wholesale_unmapped_' . normalizeMatchKey($login);
                $rowName = '(Wholesale sin mapear) ' . ($login !== '' ? $login : 'sin_login');
                ensureGlobalRow($globalRows, $rowKey, null, $rowName);
                $globalRows[$rowKey]['Minutos salientes (W)'] += $minutes;
                $globalRows[$rowKey]['Facturacion wholesale'] += $billing;
                $globalRows[$rowKey]['Margen wholesale'] += $margin;
                continue;
            }

            $rowKey = 'reseller_' . $mappedReseller['id_reseller'];
            ensureGlobalRow($globalRows, $rowKey, (int)$mappedReseller['id_reseller'], $mappedReseller['reseller']);
            $globalRows[$rowKey]['Minutos salientes (W)'] += $minutes;
            $globalRows[$rowKey]['Facturacion wholesale'] += $billing;
            $globalRows[$rowKey]['Margen wholesale'] += $margin;
        }

        $wholesaleStmt->close();

        // 4) Outgoing residential minutes grouped by reseller from 178.239.216.78.
        $residentialSql = "
            SELECT
                c.id_reseller,
                COALESCE(r.login, '(sin reseller)') AS reseller_login,
                COUNT(c.id_call) AS numero_llamadas,
                SUM(c.duration) / 60 AS minutos_salientes_residencial,
                SUM(c.costR1) AS facturacion_residencial,
                SUM(c.costd) AS coste_residencial
            FROM voipswitch.calls c
            LEFT JOIN voipswitch.resellers1 r ON r.id = c.id_reseller
            WHERE c.call_start >= ?
              AND c.call_start < ?
              AND c.ip_number <> '178.239.216.55'
              AND c.id_client <> 49
            GROUP BY c.id_reseller, r.login
        ";

        $residentialStmt = $incomingConnection->prepare($residentialSql);
        if (!$residentialStmt) {
            throw new RuntimeException('Error preparando salientes residencial: ' . $incomingConnection->error);
        }
        $residentialStmt->bind_param('ss', $fromDateTime, $toDateTime);

        if (!$residentialStmt->execute()) {
            throw new RuntimeException('Error ejecutando salientes residencial: ' . $residentialStmt->error);
        }

        $residentialRowsResult = $residentialStmt->get_result();
        while ($residentialRow = $residentialRowsResult->fetch_assoc()) {
            $login = trim((string)($residentialRow['reseller_login'] ?? ''));
            $minutes = (float)($residentialRow['minutos_salientes_residencial'] ?? 0);
            $billing = (float)($residentialRow['facturacion_residencial'] ?? 0);
            $companyCost = (float)($residentialRow['coste_residencial'] ?? 0);
            $margin = $billing - $companyCost;

            $mappedReseller = resolveResellerByLogin($login, $resellerIndex);

            if ($mappedReseller === null) {
                $rowKey = 'residencial_unmapped_' . normalizeMatchKey($login);
                $rowName = '(Residencial sin mapear) ' . ($login !== '' ? $login : 'sin_login');
                ensureGlobalRow($globalRows, $rowKey, null, $rowName);
                $globalRows[$rowKey]['Minutos salientes (R)'] += $minutes;
                $globalRows[$rowKey]['Facturacion residencial'] += $billing;
                $globalRows[$rowKey]['Margen residencial'] += $margin;
                continue;
            }

            $rowKey = 'reseller_' . $mappedReseller['id_reseller'];
            ensureGlobalRow($globalRows, $rowKey, (int)$mappedReseller['id_reseller'], $mappedReseller['reseller']);
            $globalRows[$rowKey]['Minutos salientes (R)'] += $minutes;
            $globalRows[$rowKey]['Facturacion residencial'] += $billing;
            $globalRows[$rowKey]['Margen residencial'] += $margin;
        }

        $residentialStmt->close();

        // 5) Service billing from castiphone by CodigoPlataforma (id_reseller).
        // If SQL Server driver is not available, keep service billing fields in zero.
        $serviceBillingByReseller = [];
        try {
            $castiphoneConnection = getCastiphoneConnection();
            $serviceBillingByReseller = fetchServiceBillingByReseller($castiphoneConnection, $fromDateTime, $toDateTime);
        } catch (Throwable $ignoredCastiphoneError) {
            $serviceBillingByReseller = [];
        }

        foreach ($serviceBillingByReseller as $idReseller => $serviceBilling) {
            $rowKey = 'reseller_' . $idReseller;
            if (!isset($globalRows[$rowKey])) {
                continue;
            }

            $globalRows[$rowKey]['Venta servicios'] = (float)$serviceBilling['Venta servicios'];
            $globalRows[$rowKey]['Compra servicios'] = (float)$serviceBilling['Compra servicios'];
            $globalRows[$rowKey]['Beneficio servicios'] = (float)$serviceBilling['Beneficio servicios'];
            $globalRows[$rowKey]['Margen servicios'] = (float)$serviceBilling['Margen servicios'];
        }

        $rows = array_values($globalRows);

        foreach ($rows as &$row) {
            $row['Minutos salientes totales'] =
                (float)$row['Minutos salientes (W)'] + (float)$row['Minutos salientes (R)'];
            $row['Facturacion Voz'] =
                (float)$row['Facturacion wholesale'] + (float)$row['Facturacion residencial'];

            $whBilling = (float)$row['Facturacion wholesale'];
            $resBilling = (float)$row['Facturacion residencial'];
            $whMarginAbs = (float)$row['Margen wholesale'];
            $resMarginAbs = (float)$row['Margen residencial'];

            $row['Margen wholesale'] = $whBilling > 0 ? ($whMarginAbs / $whBilling) * 100 : 0.0;
            $row['Margen residencial'] = $resBilling > 0 ? ($resMarginAbs / $resBilling) * 100 : 0.0;
            $row['Facturacion resto'] = (float)$row['Venta servicios'];
        }
        unset($row);

        // Sort by incoming minutes as requested.
        usort($rows, static function (array $a, array $b): int {
            if ($a['Minutos entrantes'] === $b['Minutos entrantes']) {
                return $b['Minutos salientes totales'] <=> $a['Minutos salientes totales'];
            }
            return $b['Minutos entrantes'] <=> $a['Minutos entrantes'];
        });

        foreach ($rows as &$row) {
            $row['Minutos entrantes'] = round((float)$row['Minutos entrantes'], 2);
            $row['Minutos salientes (W)'] = round((float)$row['Minutos salientes (W)'], 2);
            $row['Minutos salientes (R)'] = round((float)$row['Minutos salientes (R)'], 2);
            $row['Minutos salientes totales'] = round((float)$row['Minutos salientes totales'], 2);
            $row['Facturacion wholesale'] = round((float)$row['Facturacion wholesale'], 2);
            $row['Facturacion residencial'] = round((float)$row['Facturacion residencial'], 2);
            $row['Margen wholesale'] = round((float)$row['Margen wholesale'], 2);
            $row['Margen residencial'] = round((float)$row['Margen residencial'], 2);
            $row['Venta servicios'] = round((float)$row['Venta servicios'], 2);
            $row['Compra servicios'] = round((float)$row['Compra servicios'], 2);
            $row['Beneficio servicios'] = round((float)$row['Beneficio servicios'], 2);
            $row['Margen servicios'] = round((float)$row['Margen servicios'], 2);
            $row['Facturacion Voz'] = round((float)$row['Facturacion Voz'], 2);
            $row['Facturacion resto'] = round((float)$row['Facturacion resto'], 2);
        }
        unset($row);

        return [
            'title' => 'Tabla global voz por reseller',
            'columns' => [
                'id_reseller',
                'Reseller',
                'Minutos entrantes',
                'Minutos salientes (W)',
                'Minutos salientes (R)',
                'Minutos salientes totales',
                'Facturacion wholesale',
                'Facturacion residencial',
                'Margen wholesale',
                'Margen residencial',
                'Venta servicios',
                'Compra servicios',
                'Beneficio servicios',
                'Margen servicios',
                'Facturacion Voz',
                'Facturacion resto',
            ],
            'rows' => $rows,
        ];
    } finally {
        $incomingConnection->close();
        $workflowConnection->close();
        $wholesaleConnection->close();
        if ($castiphoneConnection instanceof PDO) {
            $castiphoneConnection = null;
        }
    }
}


