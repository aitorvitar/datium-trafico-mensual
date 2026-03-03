<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/report_runner.php';

function getRowFloat(array $row, string $key): float
{
    return (float)($row[$key] ?? 0);
}

function euro(float $value): string
{
    return number_format($value, 2, ',', '.') . ' €';
}

function percent(float $value): string
{
    return number_format($value, 2, ',', '.') . ' %';
}

$idReseller = max(0, (int)($_GET['id_reseller'] ?? 0));
$fromDate = (string)($_GET['from'] ?? '2026-01-01');
$toDate = (string)($_GET['to'] ?? '2026-02-01');

$error = null;
$detail = null;

if ($idReseller <= 0) {
    $error = 'id_reseller invalido.';
} else {
    try {
        $report = runReport('global_reseller_voz', $fromDate, $toDate);
        foreach ($report['rows'] as $row) {
            if ((int)($row['id_reseller'] ?? 0) === $idReseller) {
                $detail = $row;
                break;
            }
        }
        if ($detail === null) {
            $error = 'No se encontraron datos para este reseller en el periodo seleccionado.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$backUrl = 'index.php?' . http_build_query([
    'report' => 'global_reseller_voz',
    'from' => $fromDate,
    'to' => $toDate,
    'load' => 1,
]);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Detalle reseller</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
            margin: 14px 0;
        }
        .kpi-card {
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #f8fbff;
            padding: 10px;
        }
        .kpi-card .label {
            font-size: 0.85rem;
            color: #445b74;
            margin-bottom: 4px;
        }
        .kpi-card .value {
            font-size: 1.1rem;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <main class="container">
        <h1>Detalle reseller</h1>
        <p><a class="button" href="<?= htmlspecialchars($backUrl) ?>">Volver al reporte global</a></p>

        <?php if ($error !== null): ?>
            <p class="alert error"><?= htmlspecialchars($error) ?></p>
        <?php else: ?>
            <?php
                $facturacionVoz = getRowFloat($detail, 'Facturacion Voz');
                $facturacionWholesale = getRowFloat($detail, 'Facturacion wholesale');
                $facturacionResidencial = getRowFloat($detail, 'Facturacion residencial');
                $ventaServicios = getRowFloat($detail, 'Venta servicios');
                $compraServicios = getRowFloat($detail, 'Compra servicios');
                $beneficioServicios = getRowFloat($detail, 'Beneficio servicios');
                $margenServiciosPct = getRowFloat($detail, 'Margen servicios');
                $margenWholesalePct = getRowFloat($detail, 'Margen wholesale');
                $margenResidencialPct = getRowFloat($detail, 'Margen residencial');
                $margenWholesaleAbs = ($margenWholesalePct / 100) * $facturacionWholesale;
                $margenResidencialAbs = ($margenResidencialPct / 100) * $facturacionResidencial;
                $margenVozPct = $facturacionVoz > 0
                    ? (($margenWholesaleAbs + $margenResidencialAbs) / $facturacionVoz) * 100
                    : 0.0;
            ?>
            <h2>
                <?= htmlspecialchars((string)$detail['Reseller']) ?>
                (id_reseller: <?= htmlspecialchars((string)$detail['id_reseller']) ?>)
            </h2>
            <p>Periodo: <?= htmlspecialchars($fromDate) ?> a <?= htmlspecialchars($toDate) ?> (hasta exclusivo)</p>

            <section class="kpi-grid">
                <article class="kpi-card">
                    <div class="label">Minutos entrantes</div>
                    <div class="value"><?= number_format(getRowFloat($detail, 'Minutos entrantes'), 2, ',', '.') ?></div>
                </article>
                <article class="kpi-card">
                    <div class="label">Minutos salientes (W)</div>
                    <div class="value"><?= number_format(getRowFloat($detail, 'Minutos salientes (W)'), 2, ',', '.') ?></div>
                </article>
                <article class="kpi-card">
                    <div class="label">Minutos salientes (R)</div>
                    <div class="value"><?= number_format(getRowFloat($detail, 'Minutos salientes (R)'), 2, ',', '.') ?></div>
                </article>
                <article class="kpi-card">
                    <div class="label">Minutos salientes totales</div>
                    <div class="value"><?= number_format(getRowFloat($detail, 'Minutos salientes totales'), 2, ',', '.') ?></div>
                </article>
                <article class="kpi-card">
                    <div class="label">Facturacion wholesale</div>
                    <div class="value"><?= euro($facturacionWholesale) ?></div>
                </article>
                <article class="kpi-card">
                    <div class="label">Facturacion residencial</div>
                    <div class="value"><?= euro($facturacionResidencial) ?></div>
                </article>
                <article class="kpi-card">
                    <div class="label">Facturacion Voz</div>
                    <div class="value"><?= euro($facturacionVoz) ?></div>
                </article>
                <article class="kpi-card">
                    <div class="label">Venta servicios</div>
                    <div class="value"><?= euro($ventaServicios) ?></div>
                </article>
                <article class="kpi-card">
                    <div class="label">Compra servicios</div>
                    <div class="value"><?= euro($compraServicios) ?></div>
                </article>
                <article class="kpi-card">
                    <div class="label">Beneficio servicios</div>
                    <div class="value"><?= euro($beneficioServicios) ?></div>
                </article>
                <article class="kpi-card">
                    <div class="label">Margen servicios</div>
                    <div class="value"><?= percent($margenServiciosPct) ?></div>
                </article>
                <article class="kpi-card">
                    <div class="label">Margen wholesale</div>
                    <div class="value"><?= percent($margenWholesalePct) ?></div>
                </article>
                <article class="kpi-card">
                    <div class="label">Margen residencial</div>
                    <div class="value"><?= percent($margenResidencialPct) ?></div>
                </article>
                <article class="kpi-card">
                    <div class="label">Margen Voz</div>
                    <div class="value"><?= percent($margenVozPct) ?></div>
                </article>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>

