<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/conexion.php';

// Si ya tiene sesión activa, redirige al sistema directamente
if (isset($_SESSION['usuario_id'])) {
    header("Location: sistema.php");
    exit();
}

$error = "";

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = (string) ($_POST['csrf_token'] ?? '');
    $usuario = trim((string) ($_POST['usuario'] ?? ''));
    $clave = (string) ($_POST['clave'] ?? '');

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = "La sesión expiró. Actualiza la página e inténtalo nuevamente.";
    } elseif ($usuario !== '' && $clave !== '') {
        $stmt = $conexion->prepare("
            SELECT u.id_usuario, u.nombre, u.usuario, u.rol, u.id_sucursal, u.clave, s.nombre AS nombre_sucursal
            FROM usuarios u
            LEFT JOIN sucursales s ON u.id_sucursal = s.id_sucursal
            WHERE u.usuario = ?
        ");
        $stmt->bind_param("s", $usuario);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        $claveGuardada = (string) ($row['clave'] ?? '');
        $claveValida = $row && (password_verify($clave, $claveGuardada) || hash_equals($claveGuardada, $clave));

        if ($claveValida) {
            // Actualizar contraseña a hash seguro si estaba en texto plano
            if (!password_verify($clave, $claveGuardada)) {
                $claveHash = password_hash($clave, PASSWORD_DEFAULT);
                $stmtActualizarClave = $conexion->prepare("UPDATE usuarios SET clave = ? WHERE id_usuario = ?");
                $stmtActualizarClave->bind_param("si", $claveHash, $row['id_usuario']);
                $stmtActualizarClave->execute();
            }

            session_regenerate_id(true);
            $_SESSION['usuario_id'] = (int) $row['id_usuario'];
            $_SESSION['nombre'] = $row['nombre'];
            $_SESSION['usuario'] = $row['usuario'];
            $_SESSION['rol'] = $row['rol'];
            $_SESSION['id_sucursal'] = $row['id_sucursal'] !== null ? (int) $row['id_sucursal'] : null;
            $_SESSION['nombre_sucursal'] = $row['nombre_sucursal'] ?? 'Central';

            header("Location: sistema.php");
            exit();
        } else {
            $error = "Usuario o contraseña incorrectos.";
        }
    } else {
        $error = "Ingresa tu usuario y contraseña.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#123b5d">
    <title>Iniciar sesión | Farmacias del Norte</title>
    <link rel="icon" type="image/png" href="logo-icono.png">
    <link rel="stylesheet" href="estilos.css">
</head>
<body>
    <main class="login-page">
        <section class="login-box card" aria-labelledby="login-title">
            <a class="brand login-brand" href="index.php" aria-label="Farmacias del Norte">
                <img src="logo-icono.png" alt="Logo Farmacias del Norte" class="brand-logo login-logo">
                <span><strong>Farmacias del Norte</strong><small>Tu salud, nuestra prioridad</small></span>
            </a>
            <p class="eyebrow">Acceso al sistema</p>
            <h1 id="login-title">¡Bienvenido!</h1>
            <p class="login-description">Ingresa con tus credenciales asignadas.</p>

            <?php if ($error !== ''): ?>
                <p class="notice notice-error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>

            <form method="POST" class="login-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                
                <label for="usuario">Usuario</label>
                <input id="usuario" type="text" name="usuario" autocomplete="username" placeholder="ej. cajero1, jefe1, admin" required autofocus>
                
                <label for="clave">Contraseña</label>
                <input id="clave" type="password" name="clave" autocomplete="current-password" placeholder="Contraseña" required>
                
                <button type="submit">Ingresar al sistema</button>
            </form>

            <div class="login-accounts-help">
                <p><strong>Cuentas de demostración (Clave: 1234):</strong></p>
                <ul>
                    <li><span class="badge badge-cajero">Cajero</span> <code>cajero1</code> (Casa Central)</li>
                    <li><span class="badge badge-jefe">Jefe Sucursal</span> <code>jefe1</code> (Casa Central)</li>
                    <li><span class="badge badge-admin">Gerencia/Admin</span> <code>admin</code> (Control Global)</li>
                </ul>
            </div>
        </section>
    </main>
</body>
</html>
