<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/report_runner.php';

$reportId = $_GET['report'] ?? '';
$fromDate = $_GET['from'] ?? '';
$toDate = $_GET['to'] ?? '';

try {
    $resultData = runReport($reportId, $fromDate, $toDate);
} catch (Throwable $e) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Error al exportar: ' . $e->getMessage();
    exit;
}

$safeReportId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $reportId) ?? 'reporte';
$filename = sprintf('%s_%s_a_%s.csv', $safeReportId, $fromDate, $toDate);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');
if ($output === false) {
    http_response_code(500);
    echo 'No se pudo generar el archivo CSV.';
    exit;
}

fwrite($output, "\xEF\xBB\xBF");
fputcsv($output, $resultData['columns'], ';');

foreach ($resultData['rows'] as $row) {
    $line = [];
    foreach ($resultData['columns'] as $column) {
        $line[] = $row[$column] ?? '';
    }
    fputcsv($output, $line, ';');
}

fclose($output);
