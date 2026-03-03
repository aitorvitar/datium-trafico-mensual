<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/report_runner.php';

/**
 * Creates URL with query params.
 */
function buildQueryUrl(array $params): string
{
    return '?' . http_build_query($params);
}

/**
 * Formats visible table values for currency and percentage columns.
 */
function formatDisplayValue(string $column, mixed $value): string
{
    if (
        str_starts_with($column, 'Facturacion') ||
        in_array($column, ['Venta servicios', 'Compra servicios', 'Beneficio servicios'], true)
    ) {
        return number_format((float)$value, 2, ',', '.') . ' €';
    }

    if (str_starts_with($column, 'Margen')) {
        return number_format((float)$value, 2, ',', '.') . ' %';
    }

    return (string)$value;
}

$reports = getReports();

$selectedReport = $_GET['report'] ?? 'global_reseller_voz';
$fromDate = $_GET['from'] ?? '2026-01-01';
$toDate = $_GET['to'] ?? '2026-02-01';
$sortColumn = (string)($_GET['sort'] ?? '');
$sortDir = strtolower((string)($_GET['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
$page = max(1, (int)($_GET['page'] ?? 1));
$rawFilters = isset($_GET['filters']) && is_array($_GET['filters']) ? $_GET['filters'] : [];

$resultData = null;
$error = null;

if (isset($_GET['load'])) {
    try {
        $resultData = runReport($selectedReport, $fromDate, $toDate, [
            'filters' => $rawFilters,
            'sort' => $sortColumn,
            'dir' => $sortDir,
            'page' => $page,
            'per_page' => 20,
        ]);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Datium - Trafico Mensual</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <main class="container">
        <h1>Datium - Reporting VoIP</h1>

        <form method="get" class="filters">
            <label>
                Reporte
                <select name="report" required>
                    <?php foreach ($reports as $id => $report): ?>
                        <option value="<?= htmlspecialchars($id) ?>" <?= $selectedReport === $id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($report['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Desde
                <input type="date" name="from" value="<?= htmlspecialchars($fromDate) ?>" required>
            </label>

            <label>
                Hasta (exclusivo)
                <input type="date" name="to" value="<?= htmlspecialchars($toDate) ?>" required>
            </label>

            <div class="actions">
                <button type="submit" name="load" value="1">Cargar reporte</button>
                <?php if ($resultData !== null && empty($error)): ?>
                    <a class="button secondary" href="export.php?report=<?= urlencode($selectedReport) ?>&from=<?= urlencode($fromDate) ?>&to=<?= urlencode($toDate) ?>">
                        Exportar CSV
                    </a>
                <?php endif; ?>
            </div>
        </form>

        <?php if ($error !== null): ?>
            <p class="alert error"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>

        <?php if ($resultData !== null && empty($error)): ?>
            <section class="report">
                <h2><?= htmlspecialchars($resultData['title']) ?></h2>
                <p>
                    Total filas: <?= (int)$resultData['filteredCount'] ?>
                    (de <?= (int)$resultData['totalRows'] ?>)
                </p>

                <form method="get" class="table-filters">
                    <input type="hidden" name="report" value="<?= htmlspecialchars($selectedReport) ?>">
                    <input type="hidden" name="from" value="<?= htmlspecialchars($fromDate) ?>">
                    <input type="hidden" name="to" value="<?= htmlspecialchars($toDate) ?>">
                    <input type="hidden" name="load" value="1">
                    <?php if ($resultData['sortColumn'] !== ''): ?>
                        <input type="hidden" name="sort" value="<?= htmlspecialchars($resultData['sortColumn']) ?>">
                        <input type="hidden" name="dir" value="<?= htmlspecialchars($resultData['sortDir']) ?>">
                    <?php endif; ?>
                    <div class="filter-grid">
                        <?php foreach ($resultData['columns'] as $column): ?>
                            <label>
                                <?= htmlspecialchars($column) ?>
                                <input
                                    type="text"
                                    name="filters[<?= htmlspecialchars($column) ?>]"
                                    value="<?= htmlspecialchars((string)($resultData['filters'][$column] ?? '')) ?>"
                                    placeholder="Filtrar..."
                                >
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="actions">
                        <button type="submit">Aplicar filtros</button>
                        <?php
                            $clearUrl = buildQueryUrl([
                                'report' => $selectedReport,
                                'from' => $fromDate,
                                'to' => $toDate,
                                'load' => 1,
                            ]);
                        ?>
                        <a class="button secondary" href="<?= htmlspecialchars($clearUrl) ?>">Limpiar filtros</a>
                    </div>
                </form>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <?php foreach ($resultData['columns'] as $column): ?>
                                    <?php
                                        $nextDir = 'asc';
                                        if ($resultData['sortColumn'] === $column && $resultData['sortDir'] === 'asc') {
                                            $nextDir = 'desc';
                                        }
                                        $sortParams = [
                                            'report' => $selectedReport,
                                            'from' => $fromDate,
                                            'to' => $toDate,
                                            'load' => 1,
                                            'sort' => $column,
                                            'dir' => $nextDir,
                                            'page' => 1,
                                        ];
                                        $activeFilters = array_filter(
                                            $resultData['filters'],
                                            static fn(string $value): bool => $value !== ''
                                        );
                                        if (!empty($activeFilters)) {
                                            $sortParams['filters'] = $activeFilters;
                                        }
                                        $sortIcon = '';
                                        if ($resultData['sortColumn'] === $column) {
                                            $sortIcon = $resultData['sortDir'] === 'asc' ? ' (ASC)' : ' (DESC)';
                                        }
                                    ?>
                                    <th>
                                        <a class="sort-link" href="<?= htmlspecialchars(buildQueryUrl($sortParams)) ?>">
                                            <?= htmlspecialchars($column . $sortIcon) ?>
                                        </a>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($resultData['rows'])): ?>
                                <tr>
                                    <td colspan="<?= max(1, count($resultData['columns'])) ?>">Sin resultados para el rango indicado.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($resultData['rows'] as $row): ?>
                                    <tr>
                                        <?php foreach ($resultData['columns'] as $column): ?>
                                            <?php if ($selectedReport === 'global_reseller_voz' && $column === 'Reseller' && (int)($row['id_reseller'] ?? 0) > 0): ?>
                                                <?php
                                                    $detailUrl = 'reseller_detail.php?' . http_build_query([
                                                        'id_reseller' => (int)$row['id_reseller'],
                                                        'from' => $fromDate,
                                                        'to' => $toDate,
                                                    ]);
                                                ?>
                                                <td>
                                                    <a class="reseller-link" href="<?= htmlspecialchars($detailUrl) ?>" target="_blank" rel="noopener noreferrer">
                                                        <?= htmlspecialchars((string)($row[$column] ?? '')) ?>
                                                    </a>
                                                </td>
                                            <?php else: ?>
                                                <td><?= htmlspecialchars(formatDisplayValue($column, $row[$column] ?? '')) ?></td>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($resultData['maxPage'] > 1): ?>
                    <nav class="pagination">
                        <?php
                            $basePageParams = [
                                'report' => $selectedReport,
                                'from' => $fromDate,
                                'to' => $toDate,
                                'load' => 1,
                            ];
                            if ($resultData['sortColumn'] !== '') {
                                $basePageParams['sort'] = $resultData['sortColumn'];
                                $basePageParams['dir'] = $resultData['sortDir'];
                            }
                            $activeFilters = array_filter(
                                $resultData['filters'],
                                static fn(string $value): bool => $value !== ''
                            );
                            if (!empty($activeFilters)) {
                                $basePageParams['filters'] = $activeFilters;
                            }
                            $startPage = max(1, $resultData['page'] - 2);
                            $endPage = min($resultData['maxPage'], $resultData['page'] + 2);
                        ?>

                        <?php if ($resultData['page'] > 1): ?>
                            <a class="button" href="<?= htmlspecialchars(buildQueryUrl(array_merge($basePageParams, ['page' => $resultData['page'] - 1]))) ?>">Anterior</a>
                        <?php endif; ?>

                        <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                            <?php if ($p === $resultData['page']): ?>
                                <span class="page-current"><?= $p ?></span>
                            <?php else: ?>
                                <a class="button" href="<?= htmlspecialchars(buildQueryUrl(array_merge($basePageParams, ['page' => $p]))) ?>"><?= $p ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($resultData['page'] < $resultData['maxPage']): ?>
                            <a class="button" href="<?= htmlspecialchars(buildQueryUrl(array_merge($basePageParams, ['page' => $resultData['page'] + 1]))) ?>">Siguiente</a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>

