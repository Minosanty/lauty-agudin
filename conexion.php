<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';
$dbName = getenv('DB_NAME') ?: 'farmacia_db';

// Prueba puertos habituales (3306 estándar de MySQL y 8080 configurado en este servidor XAMPP)
$puertos = [];
if ($envPort = getenv('DB_PORT')) {
    $puertos[] = (int) $envPort;
}
$puertos[] = 3306;
$puertos[] = 8080;
$puertos = array_unique($puertos);

$conexion = null;
$ultimoError = null;

foreach ($puertos as $puerto) {
    try {
        $conexion = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $puerto);
        $conexion->set_charset("utf8mb4");
        break;
    } catch (mysqli_sql_exception $error) {
        $ultimoError = $error;
    }
}

if (!$conexion) {
    error_log("Error al conectar con la base de datos: " . ($ultimoError ? $ultimoError->getMessage() : 'Error desconocido'));
    http_response_code(500);
    echo "No se pudo conectar con la base de datos. Verifica que el servicio de base de datos esté iniciado y vuelve a intentarlo.";
    exit(1);
}
