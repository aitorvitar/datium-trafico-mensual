<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';

loadDotEnv(dirname(__DIR__) . '/.env');

/**
 * Creates a MySQL connection with optional charset.
 */
function createMysqlConnection(
    string $host,
    string $user,
    string $pass,
    string $dbName,
    int $port = 3306,
    string $charset = 'utf8mb4'
): mysqli
{
    $connection = @new mysqli($host, $user, $pass, $dbName, $port);

    if ($connection->connect_error) {
        throw new RuntimeException('Error de conexion MySQL: ' . $connection->connect_error);
    }

    if (!$connection->set_charset($charset)) {
        throw new RuntimeException('No se pudo establecer charset ' . $charset . '.');
    }

    return $connection;
}

/**
 * Creates a SQL Server PDO connection trying common available drivers.
 */
function createSqlServerConnection(string $host, string $dbName, string $user, string $pass): PDO
{
    $drivers = PDO::getAvailableDrivers();
    $dsnCandidates = [];

    if (in_array('dblib', $drivers, true)) {
        $dsnCandidates[] = "dblib:host={$host};dbname={$dbName};charset=utf8";
    }

    if (in_array('sqlsrv', $drivers, true)) {
        // Castiphone runs with legacy TLS settings; force non-encrypted SQLSRV transport here.
        $dsnCandidates[] = "sqlsrv:Server={$host};Database={$dbName};Encrypt=no;TrustServerCertificate=yes";
    }

    if (empty($dsnCandidates)) {
        throw new RuntimeException('No hay driver PDO para SQL Server disponible (dblib/sqlsrv).');
    }

    $lastError = null;
    foreach ($dsnCandidates as $dsn) {
        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            return $pdo;
        } catch (PDOException $e) {
            $lastError = $e->getMessage();
        }
    }

    throw new RuntimeException('Error de conexion SQL Server: ' . ($lastError ?? 'desconocido'));
}

/**
 * Main incoming traffic server (178.239.216.78).
 */
function getDbConnection(): mysqli
{
    return createMysqlConnection(
        envOrFail('DB78_HOST'),
        envOrFail('DB78_USER'),
        envOrFail('DB78_PASS'),
        envOrFail('DB78_NAME'),
        envInt('DB78_PORT', 3306)
    );
}

/**
 * DID and reseller mapping source (178.239.216.71).
 */
function getWorkflowTestConnection(): mysqli
{
    return createMysqlConnection(
        envOrFail('WORKFLOW_HOST'),
        envOrFail('WORKFLOW_USER'),
        envOrFail('WORKFLOW_PASS'),
        envOrFail('WORKFLOW_NAME'),
        envInt('WORKFLOW_PORT', 3306),
        envOrFail('WORKFLOW_CHARSET')
    );
}

/**
 * Wholesale outgoing traffic source (178.239.216.77).
 */
function getWholesaleConnection(): mysqli
{
    return createMysqlConnection(
        envOrFail('WHOLESALE_HOST'),
        envOrFail('WHOLESALE_USER'),
        envOrFail('WHOLESALE_PASS'),
        envOrFail('WHOLESALE_NAME'),
        envInt('WHOLESALE_PORT', 3306)
    );
}

/**
 * Commercial billing server (castiphone SQL Server, 178.239.216.10).
 */
function getCastiphoneConnection(): PDO
{
    return createSqlServerConnection(
        envOrFail('CASTIPHONE_HOST'),
        envOrFail('CASTIPHONE_DB'),
        envOrFail('CASTIPHONE_USER'),
        envOrFail('CASTIPHONE_PASS')
    );
}
