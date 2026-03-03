<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/global_voice_report.php';

/**
 * Validates date in Y-m-d format.
 */
function isValidDate(string $date): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return $dt !== false && $dt->format('Y-m-d') === $date;
}

/**
 * Adds reseller name to incoming DID rows.
 */
function addResellerToIncomingDidRows(array $rows, string $toDateTime): array
{
    $workflowConnection = getWorkflowTestConnection();

    try {
        $didMap = fetchDidResellerMap($workflowConnection, $toDateTime);
        $didToResellerRaw = $didMap['raw'];
        $didToResellerNormalized = $didMap['normalized'];

        foreach ($rows as &$row) {
            $did = (string)($row['called_number'] ?? '');
            $resellerData = resolveDidReseller($did, $didToResellerRaw, $didToResellerNormalized);
            $row['reseller'] = $resellerData['reseller'] ?? '(DID no asociado)';
        }
        unset($row);
    } finally {
        $workflowConnection->close();
    }

    return $rows;
}

/**
 * Checks whether a string can be treated as numeric for sorting.
 */
function isSortableNumeric(string $value): bool
{
    $normalized = str_replace(',', '.', trim($value));
    return $normalized !== '' && is_numeric($normalized);
}

/**
 * Applies filtering, sorting and pagination in server-side mode.
 */
function applyReportControls(array $rows, array $columns, array $controls): array
{
    $filtersInput = isset($controls['filters']) && is_array($controls['filters']) ? $controls['filters'] : [];
    $filters = [];
    foreach ($columns as $column) {
        $filters[$column] = trim((string)($filtersInput[$column] ?? ''));
    }

    $sortColumn = (string)($controls['sort'] ?? '');
    if (!in_array($sortColumn, $columns, true)) {
        $sortColumn = '';
    }

    $sortDir = strtolower((string)($controls['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

    $filteredRows = array_values(array_filter($rows, static function (array $row) use ($columns, $filters): bool {
        foreach ($columns as $column) {
            $needle = $filters[$column] ?? '';
            if ($needle === '') {
                continue;
            }
            $haystack = (string)($row[$column] ?? '');
            if (stripos($haystack, $needle) === false) {
                return false;
            }
        }
        return true;
    }));

    if ($sortColumn !== '') {
        usort($filteredRows, static function (array $a, array $b) use ($sortColumn, $sortDir): int {
            $left = (string)($a[$sortColumn] ?? '');
            $right = (string)($b[$sortColumn] ?? '');

            if (isSortableNumeric($left) && isSortableNumeric($right)) {
                $leftValue = (float)str_replace(',', '.', $left);
                $rightValue = (float)str_replace(',', '.', $right);
                $cmp = $leftValue <=> $rightValue;
            } else {
                $cmp = strcasecmp($left, $right);
            }

            return $sortDir === 'asc' ? $cmp : -$cmp;
        });
    }

    $controlsEnabled = !empty($controls);
    $page = max(1, (int)($controls['page'] ?? 1));
    $perPage = $controlsEnabled ? max(1, (int)($controls['per_page'] ?? 20)) : max(1, count($filteredRows));

    $totalRows = count($rows);
    $filteredCount = count($filteredRows);
    $maxPage = max(1, (int)ceil($filteredCount / $perPage));
    $page = min($page, $maxPage);
    $offset = ($page - 1) * $perPage;
    $pageRows = array_slice($filteredRows, $offset, $perPage);

    return [
        'rows' => $pageRows,
        'filters' => $filters,
        'totalRows' => $totalRows,
        'filteredCount' => $filteredCount,
        'page' => $page,
        'maxPage' => $maxPage,
        'perPage' => $perPage,
        'sortColumn' => $sortColumn,
        'sortDir' => $sortDir,
    ];
}

/**
 * Runs selected report and returns an array with columns and rows.
 */
function runReport(string $reportId, string $fromDate, string $toDate, array $controls = []): array
{
    $reports = getReports();

    if (!isset($reports[$reportId])) {
        throw new InvalidArgumentException('Reporte no valido.');
    }

    if (!isValidDate($fromDate) || !isValidDate($toDate)) {
        throw new InvalidArgumentException('Formato de fechas invalido. Usa YYYY-MM-DD.');
    }

    // Keep the same semantics as your SQL: [fromDate 00:00:00, toDate 00:00:00)
    $fromDateTime = $fromDate . ' 00:00:00';
    $toDateTime = $toDate . ' 00:00:00';

    if (strtotime($toDateTime) <= strtotime($fromDateTime)) {
        throw new InvalidArgumentException('La fecha "hasta" debe ser mayor que la fecha "desde".');
    }

    $report = $reports[$reportId];

    if (($report['runner'] ?? '') === 'global_voice') {
        $reportData = runGlobalVoiceReport($fromDate, $toDate);
        $view = applyReportControls($reportData['rows'], $reportData['columns'], $controls);

        return [
            'title' => $reportData['title'],
            'columns' => $reportData['columns'],
            'rows' => $view['rows'],
            'filters' => $view['filters'],
            'totalRows' => $view['totalRows'],
            'filteredCount' => $view['filteredCount'],
            'page' => $view['page'],
            'maxPage' => $view['maxPage'],
            'perPage' => $view['perPage'],
            'sortColumn' => $view['sortColumn'],
            'sortDir' => $view['sortDir'],
        ];
    }

    $connection = getDbConnection();

    if (!isset($report['sql'], $report['types'])) {
        $connection->close();
        throw new RuntimeException('Definicion de reporte invalida.');
    }

    $stmt = $connection->prepare($report['sql']);

    if (!$stmt) {
        throw new RuntimeException('Error al preparar consulta: ' . $connection->error);
    }

    $stmt->bind_param($report['types'], $fromDateTime, $toDateTime);

    if (!$stmt->execute()) {
        $stmt->close();
        $connection->close();
        throw new RuntimeException('Error al ejecutar consulta: ' . $stmt->error);
    }

    $result = $stmt->get_result();
    $fields = $result->fetch_fields();
    $columns = [];

    foreach ($fields as $field) {
        $columns[] = $field->name;
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();
    $connection->close();

    if ($reportId === 'entrantes_did_mensual') {
        $rows = addResellerToIncomingDidRows($rows, $toDateTime);
        if (!in_array('reseller', $columns, true)) {
            $columns[] = 'reseller';
        }
    }

    $view = applyReportControls($rows, $columns, $controls);

    return [
        'title' => $report['title'],
        'columns' => $columns,
        'rows' => $view['rows'],
        'filters' => $view['filters'],
        'totalRows' => $view['totalRows'],
        'filteredCount' => $view['filteredCount'],
        'page' => $view['page'],
        'maxPage' => $view['maxPage'],
        'perPage' => $view['perPage'],
        'sortColumn' => $view['sortColumn'],
        'sortDir' => $view['sortDir'],
    ];
}
