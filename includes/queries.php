<?php

declare(strict_types=1);

/**
 * Returns available report definitions and SQL templates.
 */
function getReports(): array
{
    return [
        'global_reseller_voz' => [
            'title' => 'Global - Reseller | Entrantes | Salientes | Facturacion',
            'runner' => 'global_voice',
        ],
        'entrantes_did_mensual' => [
            'title' => 'Entrantes - Minutos entrantes por DID',
            'sql' => "
                SELECT
                    SUM(c.duration) / 60 AS total_minutos_entrantes,
                    c.called_number
                FROM voipswitch.calls c
                WHERE c.call_start >= ?
                  AND c.call_start < ?
                  AND c.costD = 0
                  AND c.ip_number = '178.239.216.55'
                GROUP BY c.called_number
                ORDER BY SUM(c.duration) DESC
            ",
            'types' => 'ss',
        ],
        'entrantes_por_reseller' => [
            'title' => 'Entrantes - Minutos y llamadas por reseller',
            'sql' => "
                SELECT
                    c.id_client,
                    sip.login AS login_clientsip,
                    COUNT(c.id_call) AS numero_llamadas,
                    SUM(c.duration) / 60 AS suma_minutos
                FROM voipswitch.calls c
                JOIN voipswitch.clientsip sip ON sip.id_client = c.id_client
                WHERE c.call_start >= ?
                  AND c.call_start < ?
                GROUP BY c.id_client, sip.login
                ORDER BY numero_llamadas DESC
            ",
            'types' => 'ss',
        ],
        'salientes_por_retail' => [
            'title' => 'Salientes - Minutos, llamadas y coste por retail client',
            'sql' => "
                SELECT
                    COUNT(c.id_call) AS numero_llamadas,
                    SUM(c.duration) / 60 AS suma_minutos,
                    SUM(c.cost) AS suma_coste_reseller,
                    SUM(c.costd) AS suma_costd,
                    COALESCE(cs.login, sip.login, '(sin cliente)') AS cliente_login,
                    COALESCE(r.login, '(sin reseller)') AS reseller_login
                FROM voipswitch.calls c
                LEFT JOIN voipswitch.clientsshared cs ON cs.id_client = c.id_client
                LEFT JOIN voipswitch.clientsip sip ON sip.id_client = c.id_client
                LEFT JOIN voipswitch.resellers1 r ON r.id = c.id_reseller
                WHERE c.call_start >= ?
                  AND c.call_start < ?
                  AND c.ip_number <> '178.239.216.55'
                  AND c.id_client <> 49
                GROUP BY c.id_reseller, r.login, c.id_client, cs.login, sip.login
                ORDER BY numero_llamadas DESC
            ",
            'types' => 'ss',
        ],
        'salientes_por_reseller' => [
            'title' => 'Salientes - Minutos, llamadas y coste por reseller',
            'sql' => "
                SELECT
                    COUNT(c.id_call) AS numero_llamadas,
                    SUM(c.duration) / 60 AS suma_minutos,
                    SUM(c.cost) AS suma_coste_reseller,
                    SUM(c.costd) AS suma_coste_datium,
                    c.id_reseller,
                    COALESCE(r.login, '(sin reseller)') AS reseller_login
                FROM voipswitch.calls c
                LEFT JOIN voipswitch.resellers1 r ON r.id = c.id_reseller
                WHERE c.call_start >= ?
                  AND c.call_start < ?
                  AND c.ip_number <> '178.239.216.55'
                  AND c.id_client <> 49
                GROUP BY c.id_reseller, r.login
                ORDER BY numero_llamadas DESC
            ",
            'types' => 'ss',
        ],
    ];
}
