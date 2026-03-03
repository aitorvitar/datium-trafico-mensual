<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/report_runner.php';

header('Content-Type: application/json; charset=utf-8');

$reportId = (string)($_GET['report'] ?? 'global_reseller_voz');
$fromDate = (string)($_GET['from'] ?? date('Y-m-01', strtotime('-1 month')));
$toDate = (string)($_GET['to'] ?? date('Y-m-01'));
$sort = (string)($_GET['sort'] ?? '');
$dir = (string)($_GET['dir'] ?? 'desc');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(1, min(200, (int)($_GET['per_page'] ?? 20)));
$filters = isset($_GET['filters']) && is_array($_GET['filters']) ? $_GET['filters'] : [];

try {
    $data = runReport($reportId, $fromDate, $toDate, [
        'filters' => $filters,
        'sort' => $sort,
        'dir' => $dir,
        'page' => $page,
        'per_page' => $perPage,
    ]);

    echo json_encode([
        'ok' => true,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
