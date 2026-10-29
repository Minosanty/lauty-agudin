<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/conexion.php';

// Normalización de rol de usuario
$rolOriginal = (string) ($_SESSION['rol'] ?? '');
$rolNormalizado = strtolower(trim($rolOriginal));
$rolNormalizado = strtr($rolNormalizado, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
$rolNormalizado = trim(preg_replace('/[^a-z0-9]+/', ' ', $rolNormalizado));

// Definición de permisos por rol
$esCajero = in_array($rolNormalizado, ['cajero'], true);
$esJefe = in_array($rolNormalizado, ['jefe de sucursal', 'jefe sucursal', 'jefe'], true);
$esAdmin = in_array($rolNormalizado, ['gerencia', 'gerente', 'admin', 'administrador'], true);

// Reglas de negocio solicitadas:
// - Cajero: ve tarjetas y carrito (punto de venta) + consulta stock. NO ve ventas.
// - Jefe: ve ventas de su sucursal + consulta stock. NO ve tarjetas ni carrito.
// - Admin: ve todas las ventas de todas las sucursales + consulta stock. NO ve tarjetas ni carrito.
$puedeVerTarjetasYCarrito = $esCajero;
$puedeVerVentasSucursal = $esJefe;
$puedeVerTodasLasVentas = $esAdmin;
$puedeVerStock = true; // Cajero, Jefe y Admin pueden ver el stock

$mensaje = (string) ($_SESSION['flash_message'] ?? '');
$tipoMensaje = (string) ($_SESSION['flash_type'] ?? 'info');
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

$carrito = $_SESSION['carrito'] ?? [];
if (!is_array($carrito)) {
    $carrito = [];
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Carga del catálogo de productos
$resProductos = $conexion->query("SELECT id_producto, codigo_barra, descripcion, precio, stock FROM productos ORDER BY descripcion ASC");
$productos = $resProductos->fetch_all(MYSQLI_ASSOC);

$catalogo = [];
$totalStock = 0;
$stockBajoCount = 0;
$agotadosCount = 0;

foreach ($productos as $producto) {
    $id = (int) $producto['id_producto'];
    $catalogo[$id] = $producto;
    $s = (int) $producto['stock'];
    $totalStock += $s;
    if ($s === 0) {
        $agotadosCount++;
    } elseif ($s <= 5) {
        $stockBajoCount++;
    }
}

/**
 * Función auxiliar para generar el HTML interno del carrito
 */
function renderCartHtml(array $carrito, array $catalogo, string $csrfToken): string {
    if (empty($carrito)) {
        return '<p class="empty-cart" id="empty-cart-msg">El carrito está vacío. Agrega productos desde el catálogo para iniciar una venta.</p>';
    }

    $totalCarrito = 0;
    $html = '<div class="cart-items" id="cart-items-container">';
    foreach ($carrito as $idProducto => $cantidad) {
        if (isset($catalogo[$idProducto])) {
            $producto = $catalogo[$idProducto];
            $subtotalItem = (float) $producto['precio'] * (int) $cantidad;
            $totalCarrito += $subtotalItem;
            $html .= '
            <div class="cart-row">
                <div>
                    <h3>' . htmlspecialchars($producto['descripcion'], ENT_QUOTES, 'UTF-8') . '</h3>
                    <p>
                        Cód. ' . htmlspecialchars($producto['codigo_barra'], ENT_QUOTES, 'UTF-8') . ' &bull; 
                        ' . (int) $cantidad . ' un. &times; $' . number_format((float) $producto['precio'], 2) . '
                    </p>
                </div>
                <strong>$' . number_format($subtotalItem, 2) . '</strong>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">
                    <input type="hidden" name="accion" value="quitar">
                    <input type="hidden" name="id_producto" value="' . (int) $idProducto . '">
                    <button class="remove-button" type="submit" aria-label="Quitar ' . htmlspecialchars($producto['descripcion'], ENT_QUOTES, 'UTF-8') . '">
                        Quitar
                    </button>
                </form>
            </div>';
        }
    }
    $html .= '</div>';
    $html .= '<div class="cart-total"><span>Total de la Venta</span><strong id="cart-total-amount">$' . number_format($totalCarrito, 2) . '</strong></div>';
    $html .= '<form method="POST">
        <input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">
        <input type="hidden" name="accion" value="finalizar">
        <button class="checkout-button" type="submit">
            Confirmar y Finalizar Venta ($' . number_format($totalCarrito, 2) . ')
        </button>
    </form>';

    return $html;
}

// ==========================================
// PROCESAMIENTO DE ACCIONES (POST)
// ==========================================
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $accion = $_POST['accion'] ?? '';
    $tokenEnviado = (string) ($_POST['csrf_token'] ?? '');
    $esAjax = !empty($_POST['ajax']);

    if (!hash_equals($_SESSION['csrf_token'], $tokenEnviado)) {
        http_response_code(403);
        $mensaje = "La sesión expiró o el token no es válido. Actualiza la página antes de continuar.";
        $tipoMensaje = "error";
        if ($esAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'mensaje' => $mensaje]);
            exit();
        }
    } elseif (in_array($accion, ['agregar', 'quitar', 'finalizar'], true)) {
        // SEGURIDAD DE ROL: Solo el cajero tiene autorización para interactuar con el carrito y registrar ventas
        if (!$puedeVerTarjetasYCarrito) {
            http_response_code(403);
            $mensaje = "Acceso denegado: Su rol no tiene autorización para operar el carrito ni registrar ventas.";
            $tipoMensaje = "error";
            if ($esAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'mensaje' => $mensaje]);
                exit();
            }
        } elseif ($accion === 'agregar') {
            $idProducto = filter_var($_POST['id_producto'] ?? null, FILTER_VALIDATE_INT);
            $cantidad = filter_var($_POST['cantidad'] ?? null, FILTER_VALIDATE_INT);

            if ($idProducto !== false && $idProducto > 0 && $cantidad !== false && $cantidad > 0) {
                $stmtProd = $conexion->prepare("SELECT stock, descripcion FROM productos WHERE id_producto = ?");
                $stmtProd->bind_param("i", $idProducto);
                $stmtProd->execute();
                $producto = $stmtProd->get_result()->fetch_assoc();
                $cantidadCarrito = ($carrito[$idProducto] ?? 0) + $cantidad;

                if ($producto && $cantidadCarrito <= (int) $producto['stock']) {
                    $carrito[$idProducto] = $cantidadCarrito;
                    $_SESSION['carrito'] = $carrito;
                    $_SESSION['flash_message'] = "Producto agregado al carrito con éxito.";
                    $_SESSION['flash_type'] = "success";

                    if ($esAjax) {
                        header('Content-Type: application/json; charset=utf-8');
                        $totArt = array_sum($carrito);
                        $totMonto = 0;
                        foreach ($carrito as $idP => $cant) {
                            if (isset($catalogo[$idP])) {
                                $totMonto += (float) $catalogo[$idP]['precio'] * (int) $cant;
                            }
                        }
                        $cartHtml = renderCartHtml($carrito, $catalogo, $_SESSION['csrf_token']);

                        echo json_encode([
                            'ok' => true,
                            'mensaje' => '¡' . $producto['descripcion'] . ' (' . $cantidad . ' un.) agregado!',
                            'total_items' => $totArt,
                            'total_monto' => number_format($totMonto, 2),
                            'cart_html' => $cartHtml
                        ]);
                        exit();
                    }

                    // En envío tradicional se redirige a la tarjeta del producto para NO saltar al final
                    header("Location: sistema.php#producto-" . $idProducto);
                    exit();
                } else {
                    $mensaje = "No hay stock suficiente para la cantidad solicitada.";
                    $tipoMensaje = "error";
                    if ($esAjax) {
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode(['ok' => false, 'mensaje' => $mensaje]);
                        exit();
                    }
                }
            } else {
                $mensaje = "Selecciona una cantidad válida mayor a cero.";
                $tipoMensaje = "error";
                if ($esAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['ok' => false, 'mensaje' => $mensaje]);
                    exit();
                }
            }
        } elseif ($accion === 'quitar') {
            $idProducto = filter_var($_POST['id_producto'] ?? null, FILTER_VALIDATE_INT);
            if ($idProducto !== false && $idProducto > 0 && isset($carrito[$idProducto])) {
                unset($carrito[$idProducto]);
                $_SESSION['carrito'] = $carrito;
                $_SESSION['flash_message'] = "Producto removido del carrito.";
                $_SESSION['flash_type'] = "info";
                header("Location: sistema.php#carrito");
                exit();
            } else {
                $mensaje = "No se pudo quitar el producto del carrito.";
                $tipoMensaje = "error";
            }
        } elseif ($accion === 'finalizar') {
            if (empty($carrito)) {
                $mensaje = "El carrito está vacío. Agrega productos antes de finalizar la venta.";
                $tipoMensaje = "error";
            } elseif (empty($_SESSION['id_sucursal'])) {
                $mensaje = "Tu usuario no tiene una sucursal asignada para registrar ventas.";
                $tipoMensaje = "error";
            } else {
                try {
                    $conexion->begin_transaction();
                    $productosCarrito = [];
                    $total = 0;
                    $stmtProd = $conexion->prepare("SELECT id_producto, descripcion, precio, stock FROM productos WHERE id_producto = ? FOR UPDATE");

                    foreach ($carrito as $idProducto => $cantidad) {
                        $idProducto = (int) $idProducto;
                        $cantidad = (int) $cantidad;
                        $stmtProd->bind_param("i", $idProducto);
                        $stmtProd->execute();
                        $producto = $stmtProd->get_result()->fetch_assoc();

                        if (!$producto || $cantidad < 1 || (int) $producto['stock'] < $cantidad) {
                            throw new RuntimeException("Stock insuficiente para completar la venta del producto: " . ($producto['descripcion'] ?? 'desconocido'));
                        }

                        $producto['cantidad'] = $cantidad;
                        $producto['subtotal'] = (float) $producto['precio'] * $cantidad;
                        $productosCarrito[] = $producto;
                        $total += $producto['subtotal'];
                    }

                    $stmtVenta = $conexion->prepare("INSERT INTO ventas (id_usuario, id_sucursal, total) VALUES (?, ?, ?)");
                    $stmtVenta->bind_param("iid", $_SESSION['usuario_id'], $_SESSION['id_sucursal'], $total);
                    $stmtVenta->execute();
                    $idVenta = $stmtVenta->insert_id;

                    $stmtDetalle = $conexion->prepare("INSERT INTO detalle_ventas (id_venta, id_producto, cantidad, subtotal) VALUES (?, ?, ?, ?)");
                    $stmtUpdate = $conexion->prepare("UPDATE productos SET stock = stock - ? WHERE id_producto = ? AND stock >= ?");

                    foreach ($productosCarrito as $producto) {
                        $idProducto = (int) $producto['id_producto'];
                        $cantidad = (int) $producto['cantidad'];
                        $subtotal = (float) $producto['subtotal'];

                        $stmtDetalle->bind_param("iiid", $idVenta, $idProducto, $cantidad, $subtotal);
                        $stmtDetalle->execute();

                        $stmtUpdate->bind_param("iii", $cantidad, $idProducto, $cantidad);
                        $stmtUpdate->execute();

                        if ($stmtUpdate->affected_rows !== 1) {
                            throw new RuntimeException("Error de concurrencia al descontar el stock del producto ID $idProducto.");
                        }
                    }

                    $conexion->commit();
                    $_SESSION['carrito'] = [];
                    $_SESSION['flash_message'] = "¡Venta #" . $idVenta . " registrada correctamente! Total cobrado: $" . number_format($total, 2);
                    $_SESSION['flash_type'] = "success";
                    header("Location: sistema.php#carrito");
                    exit();
                } catch (Throwable $error) {
                    try {
                        $conexion->rollback();
                    } catch (Throwable $rollbackError) {
                        error_log("Fallo en rollback: " . $rollbackError->getMessage());
                    }
                    error_log("Error al procesar la venta: " . $error->getMessage());
                    $mensaje = $error instanceof RuntimeException
                        ? $error->getMessage()
                        : "Ocurrió un error al registrar la venta. La operación fue cancelada sin alterar el stock.";
                    $tipoMensaje = "error";
                }
            }
        }
    } else {
        http_response_code(400);
        $mensaje = "Acción no reconocida.";
        $tipoMensaje = "error";
    }
}

// Cálculo del carrito (solo relevante para Cajero)
$totalCarrito = 0;
$cantidadArticulosCarrito = 0;
foreach ($carrito as $idProducto => $cantidad) {
    if (isset($catalogo[$idProducto])) {
        $totalCarrito += (float) $catalogo[$idProducto]['precio'] * (int) $cantidad;
        $cantidadArticulosCarrito += (int) $cantidad;
    }
}

// ==========================================
// CONSULTA DE SUCURSALES (para filtro Admin)
// ==========================================
$sucursalesList = [];
$resSuc = $conexion->query("SELECT id_sucursal, nombre FROM sucursales ORDER BY nombre ASC");
if ($resSuc) {
    $sucursalesList = $resSuc->fetch_all(MYSQLI_ASSOC);
}

// Nombre de la sucursal activa del usuario
$nombreSucursalActual = $_SESSION['nombre_sucursal'] ?? '';
if (empty($nombreSucursalActual) && !empty($_SESSION['id_sucursal'])) {
    foreach ($sucursalesList as $suc) {
        if ((int) $suc['id_sucursal'] === (int) $_SESSION['id_sucursal']) {
            $nombreSucursalActual = $suc['nombre'];
            $_SESSION['nombre_sucursal'] = $nombreSucursalActual;
            break;
        }
    }
}
if (empty($nombreSucursalActual)) {
    $nombreSucursalActual = 'Sede Central';
}

// ==========================================
// CONSULTA DE VENTAS (Solo para Jefe y Admin)
// ==========================================
$ventas = [];
$detallesPorVenta = [];
$totalVentasMonto = 0;
$filtroSucursalSeleccionada = null;

if ($puedeVerTodasLasVentas || $puedeVerVentasSucursal) {
    if ($puedeVerTodasLasVentas) {
        // Admin: puede ver todas o filtrar opcionalmente por una sucursal específica
        $filtroSucursal = filter_input(INPUT_GET, 'sucursal', FILTER_VALIDATE_INT);
        if ($filtroSucursal && $filtroSucursal > 0) {
            $filtroSucursalSeleccionada = $filtroSucursal;
            $stmtVentas = $conexion->prepare("
                SELECT v.id_venta, v.fecha, s.nombre AS sucursal, u.nombre AS cajero, v.total, v.id_sucursal
                FROM ventas v
                JOIN sucursales s ON v.id_sucursal = s.id_sucursal
                JOIN usuarios u ON v.id_usuario = u.id_usuario
                WHERE v.id_sucursal = ?
                ORDER BY v.fecha DESC
            ");
            $stmtVentas->bind_param("i", $filtroSucursal);
            $stmtVentas->execute();
            $ventas = $stmtVentas->get_result()->fetch_all(MYSQLI_ASSOC);
        } else {
            $resV = $conexion->query("
                SELECT v.id_venta, v.fecha, s.nombre AS sucursal, u.nombre AS cajero, v.total, v.id_sucursal
                FROM ventas v
                JOIN sucursales s ON v.id_sucursal = s.id_sucursal
                JOIN usuarios u ON v.id_usuario = u.id_usuario
                ORDER BY v.fecha DESC
            ");
            $ventas = $resV ? $resV->fetch_all(MYSQLI_ASSOC) : [];
        }
    } else {
        // Jefe de Sucursal: ESTRICTAMENTE limitado a las ventas de su sucursal
        $idSucursalUsuario = (int) ($_SESSION['id_sucursal'] ?? 0);
        $stmtVentas = $conexion->prepare("
            SELECT v.id_venta, v.fecha, s.nombre AS sucursal, u.nombre AS cajero, v.total, v.id_sucursal
            FROM ventas v
            JOIN sucursales s ON v.id_sucursal = s.id_sucursal
            JOIN usuarios u ON v.id_usuario = u.id_usuario
            WHERE v.id_sucursal = ?
            ORDER BY v.fecha DESC
        ");
        $stmtVentas->bind_param("i", $idSucursalUsuario);
        $stmtVentas->execute();
        $ventas = $stmtVentas->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    foreach ($ventas as $v) {
        $totalVentasMonto += (float) $v['total'];
    }

    // Consulta en lote de los detalles de productos vendidos para las ventas mostradas
    if (!empty($ventas)) {
        $idsVentas = array_column($ventas, 'id_venta');
        $inClause = implode(',', array_map('intval', $idsVentas));
        $resDet = $conexion->query("
            SELECT d.id_venta, d.cantidad, d.subtotal, p.descripcion, p.codigo_barra, p.precio
            FROM detalle_ventas d
            JOIN productos p ON d.id_producto = p.id_producto
            WHERE d.id_venta IN ($inClause)
            ORDER BY d.id_detalle ASC
        ");
        if ($resDet) {
            while ($det = $resDet->fetch_assoc()) {
                $detallesPorVenta[(int) $det['id_venta']][] = $det;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#123b5d">
    <title>Sistema de Gestión | Farmacias del Norte</title>
    <link rel="icon" type="image/png" href="logo-icono.png">
    <link rel="stylesheet" href="estilos.css">
</head>
<body>
    <header>
        <a class="brand" href="sistema.php" aria-label="Farmacias del Norte, inicio">
            <img src="logo-icono.png" alt="Logo Farmacias del Norte" class="brand-logo">
            <span><strong>Farmacias del Norte</strong><small>Tu salud, nuestra prioridad</small></span>
        </a>
        <div class="account-bar">
            <div class="account-details">
                <strong><?php echo htmlspecialchars($_SESSION['nombre'], ENT_QUOTES, 'UTF-8'); ?></strong>
                <div>
                    <?php if ($esCajero): ?>
                        <span class="badge badge-cajero">Cajero</span>
                    <?php elseif ($esJefe): ?>
                        <span class="badge badge-jefe">Jefe de Sucursal</span>
                    <?php else: ?>
                        <span class="badge badge-admin">Gerencia / Admin</span>
                    <?php endif; ?>
                    <span class="badge-sucursal"><?php echo htmlspecialchars($nombreSucursalActual, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            </div>
            <a class="logout-link" href="logout.php">Cerrar sesión</a>
        </div>
    </header>

    <div class="container">
        <!-- Notificaciones flash tradicionales -->
        <?php if ($mensaje !== ''): ?>
            <div class="notice <?php echo $tipoMensaje === 'error' ? 'notice-error' : ''; ?>" role="status">
                <?php echo htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <!-- Banner de Bienvenida y Navegación según Rol -->
        <section class="role-header-banner">
            <div>
                <?php if ($esCajero): ?>
                    <h1 class="role-title">Módulo de Caja y Mostrador</h1>
                    <p class="role-subtitle">Realiza ventas mediante el catálogo y consulta el stock disponible en tiempo real.</p>
                <?php elseif ($esJefe): ?>
                    <h1 class="role-title">Panel de Jefatura &mdash; <?php echo htmlspecialchars($nombreSucursalActual, ENT_QUOTES, 'UTF-8'); ?></h1>
                    <p class="role-subtitle">Supervisión de ventas de tu sucursal y monitoreo de stock de productos.</p>
                <?php else: ?>
                    <h1 class="role-title">Panel de Administración General (Gerencia)</h1>
                    <p class="role-subtitle">Auditoría de todas las ventas de la red de sucursales e inventario global.</p>
                <?php endif; ?>
            </div>
            <nav class="nav-links" aria-label="Acceso rápido">
                <?php if ($puedeVerTarjetasYCarrito): ?>
                    <a class="nav-pill" href="#punto-venta">🛍️ Catálogo</a>
                    <a class="nav-pill" href="#carrito">🛒 Carrito (<span class="cart-count-num"><?php echo $cantidadArticulosCarrito; ?></span>)</a>
                <?php endif; ?>
                <?php if ($puedeVerVentasSucursal || $puedeVerTodasLasVentas): ?>
                    <a class="nav-pill" href="#ventas">📊 Ventas</a>
                <?php endif; ?>
                <a class="nav-pill" href="#inventario">📦 Stock e Inventario</a>
            </nav>
        </section>

        <!-- Métricas resumidas por rol -->
        <section class="stats-grid">
            <?php if ($esCajero): ?>
                <div class="stat-card">
                    <p class="stat-label">Productos en Catálogo</p>
                    <p class="stat-value"><?php echo count($productos); ?></p>
                    <p class="stat-subtext"><?php echo count($productos) - $agotadosCount; ?> con existencias activas</p>
                </div>
                <div class="stat-card">
                    <p class="stat-label">Artículos en tu Carrito</p>
                    <p class="stat-value" id="stat-cart-items"><?php echo $cantidadArticulosCarrito; ?></p>
                    <p class="stat-subtext">Unidades seleccionadas</p>
                </div>
                <div class="stat-card">
                    <p class="stat-label">Total a Cobrar</p>
                    <p class="stat-value" id="stat-cart-total">$<?php echo number_format($totalCarrito, 2); ?></p>
                    <p class="stat-subtext">Sucursal: <?php echo htmlspecialchars($nombreSucursalActual, ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
                <div class="stat-card">
                    <p class="stat-label">Productos Agotados</p>
                    <p class="stat-value" style="color: <?php echo $agotadosCount > 0 ? '#c81e1e' : 'inherit'; ?>;"><?php echo $agotadosCount; ?></p>
                    <p class="stat-subtext">Sin stock para venta</p>
                </div>
            <?php elseif ($esJefe): ?>
                <div class="stat-card">
                    <p class="stat-label">Total Ventas Sucursal</p>
                    <p class="stat-value">$<?php echo number_format($totalVentasMonto, 2); ?></p>
                    <p class="stat-subtext"><?php echo htmlspecialchars($nombreSucursalActual, ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
                <div class="stat-card">
                    <p class="stat-label">Operaciones Realizadas</p>
                    <p class="stat-value"><?php echo count($ventas); ?></p>
                    <p class="stat-subtext">Ventas registradas</p>
                </div>
                <div class="stat-card">
                    <p class="stat-label">Ticket Promedio</p>
                    <p class="stat-value">$<?php echo count($ventas) > 0 ? number_format($totalVentasMonto / count($ventas), 2) : '0.00'; ?></p>
                    <p class="stat-subtext">Por venta completada</p>
                </div>
                <div class="stat-card">
                    <p class="stat-label">Alertas de Stock</p>
                    <p class="stat-value" style="color: <?php echo ($stockBajoCount + $agotadosCount) > 0 ? '#b78103' : 'inherit'; ?>;">
                        <?php echo $stockBajoCount + $agotadosCount; ?>
                    </p>
                    <p class="stat-subtext"><?php echo $agotadosCount; ?> agotados, <?php echo $stockBajoCount; ?> bajo stock</p>
                </div>
            <?php else: ?>
                <div class="stat-card">
                    <p class="stat-label">Recaudación Global</p>
                    <p class="stat-value">$<?php echo number_format($totalVentasMonto, 2); ?></p>
                    <p class="stat-subtext">Todas las sucursales</p>
                </div>
                <div class="stat-card">
                    <p class="stat-label">Total de Ventas Globales</p>
                    <p class="stat-value"><?php echo count($ventas); ?></p>
                    <p class="stat-subtext">Operaciones consolidadas</p>
                </div>
                <div class="stat-card">
                    <p class="stat-label">Sucursales Registradas</p>
                    <p class="stat-value"><?php echo count($sucursalesList); ?></p>
                    <p class="stat-subtext">Puntos de venta habilitados</p>
                </div>
                <div class="stat-card">
                    <p class="stat-label">Alertas de Stock Global</p>
                    <p class="stat-value" style="color: <?php echo ($stockBajoCount + $agotadosCount) > 0 ? '#b78103' : 'inherit'; ?>;">
                        <?php echo $stockBajoCount + $agotadosCount; ?>
                    </p>
                    <p class="stat-subtext"><?php echo $agotadosCount; ?> agotados, <?php echo $stockBajoCount; ?> críticos</p>
                </div>
            <?php endif; ?>
        </section>

        <!-- ============================================================= -->
        <!-- VISTA DE TARJETAS Y CARRITO: EXCLUSIVA PARA EL ROL DE CAJERO -->
        <!-- ============================================================= -->
        <?php if ($puedeVerTarjetasYCarrito): ?>
            <!-- SECCIÓN 1: CATÁLOGO EN TARJETAS PARA EL CAJERO -->
            <section class="catalog-section" id="punto-venta">
                <div class="section-heading">
                    <div>
                        <p class="eyebrow">Punto de Venta / Mostrador</p>
                        <h2>Catálogo de Medicamentos y Productos</h2>
                        <p class="section-description">Selecciona la cantidad de productos para agregarlos al carrito del cliente.</p>
                    </div>
                    <a class="cart-link" href="#carrito">
                        <span class="cart-icon" aria-hidden="true">🛒</span> Ver carrito
                        <span class="cart-badge"><?php echo $cantidadArticulosCarrito; ?></span>
                    </a>
                </div>

                <?php if ($productos): ?>
                    <div class="product-grid">
                        <?php foreach ($productos as $producto): ?>
                            <?php 
                            $idProd = (int) $producto['id_producto'];
                            $stockActual = (int) $producto['stock']; 
                            ?>
                            <article class="product-card" id="producto-<?php echo $idProd; ?>">
                                <div class="product-image" aria-hidden="true">
                                    <img src="logo-icono.png" alt="" class="product-art-img">
                                    <span class="image-label">Farmacia</span>
                                </div>
                                <div class="product-info">
                                    <p class="product-code">Cód. <?php echo htmlspecialchars($producto['codigo_barra'], ENT_QUOTES, 'UTF-8'); ?></p>
                                    <h3><?php echo htmlspecialchars($producto['descripcion'], ENT_QUOTES, 'UTF-8'); ?></h3>
                                    <div class="product-meta">
                                        <strong>$<?php echo number_format((float) $producto['precio'], 2); ?></strong>
                                        <span class="stock-label <?php echo $stockActual < 1 ? 'out-of-stock' : ''; ?>">
                                            <?php if ($stockActual < 1): ?>
                                                Agotado
                                            <?php elseif ($stockActual <= 5): ?>
                                                Últimas <?php echo $stockActual; ?> u.
                                            <?php else: ?>
                                                Stock: <?php echo $stockActual; ?>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <form method="POST" class="add-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="accion" value="agregar">
                                        <input type="hidden" name="id_producto" value="<?php echo $idProd; ?>">
                                        
                                        <label class="visually-hidden" for="cantidad-<?php echo $idProd; ?>">Cantidad</label>
                                        <input id="cantidad-<?php echo $idProd; ?>" 
                                               type="number" 
                                               name="cantidad" 
                                               min="1" 
                                               max="<?php echo $stockActual; ?>" 
                                               value="1" 
                                               required 
                                               <?php echo $stockActual < 1 ? 'disabled' : ''; ?>>
                                        <button type="submit" <?php echo $stockActual < 1 ? 'disabled' : ''; ?>>
                                            Agregar al carrito
                                        </button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="empty-state">No hay productos registrados en el sistema actualmente.</p>
                <?php endif; ?>
            </section>

            <!-- SECCIÓN 2: CARRITO DE COMPRAS Y FINALIZACIÓN DE VENTA -->
            <section class="card cart-section" id="carrito">
                <div class="section-heading">
                    <div>
                        <p class="eyebrow">Cobro y Facturación</p>
                        <h2>Carrito de Venta Actual</h2>
                    </div>
                    <span class="cart-count"><span class="cart-count-num"><?php echo $cantidadArticulosCarrito; ?></span> artículo(s)</span>
                </div>

                <div id="cart-content-wrapper">
                    <?php echo renderCartHtml($carrito, $catalogo, $_SESSION['csrf_token']); ?>
                </div>
            </section>
        <?php endif; ?>

        <!-- ========================================================================= -->
        <!-- VISTA DE VENTAS: EXCLUSIVA PARA JEFE DE SUCURSAL Y GERENCIA / ADMIN -->
        <!-- ========================================================================= -->
        <?php if ($puedeVerTodasLasVentas || $puedeVerVentasSucursal): ?>
            <section class="card" id="ventas">
                <div class="section-heading">
                    <div>
                        <p class="eyebrow">Auditoría y Reportes</p>
                        <?php if ($puedeVerTodasLasVentas): ?>
                            <h2>Reporte de Ventas &mdash; Todas las Sucursales</h2>
                            <p class="section-description">Visualización centralizada de todas las ventas registradas en la empresa.</p>
                        <?php else: ?>
                            <h2>Reporte de Ventas &mdash; <?php echo htmlspecialchars($nombreSucursalActual, ENT_QUOTES, 'UTF-8'); ?></h2>
                            <p class="section-description">Listado exclusivo de las ventas completadas en esta sucursal.</p>
                        <?php endif; ?>
                    </div>

                    <?php if ($puedeVerTodasLasVentas && count($sucursalesList) > 1): ?>
                        <!-- Filtro por sucursal para Administrador -->
                        <form method="GET" class="filter-select-wrapper">
                            <label for="filtro-sucursal">Filtrar por sucursal:</label>
                            <select id="filtro-sucursal" name="sucursal" onchange="this.form.submit()">
                                <option value="">Todas las sucursales (<?php echo count($sucursalesList); ?>)</option>
                                <?php foreach ($sucursalesList as $s): ?>
                                    <option value="<?php echo (int) $s['id_sucursal']; ?>" <?php echo $filtroSucursalSeleccionada === (int) $s['id_sucursal'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($s['nombre'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($filtroSucursalSeleccionada): ?>
                                <a href="sistema.php#ventas" class="remove-button" style="text-decoration:none; display:inline-flex; align-items:center;">Limpiar</a>
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th>Nº Ticket</th>
                            <th>Fecha y Hora</th>
                            <th>Sucursal</th>
                            <th>Vendedor / Cajero</th>
                            <th>Total Cobrado</th>
                            <th>Detalle de Productos</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($ventas)): ?>
                            <?php foreach ($ventas as $v): ?>
                                <?php 
                                $idV = (int) $v['id_venta'];
                                $itemsVenta = $detallesPorVenta[$idV] ?? [];
                                $totalItems = 0;
                                foreach ($itemsVenta as $it) {
                                    $totalItems += (int) $it['cantidad'];
                                }
                                ?>
                                <tr>
                                    <td><strong>#<?php echo $idV; ?></strong></td>
                                    <td><?php echo htmlspecialchars($v['fecha'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <span class="badge" style="background:#eaf4fb; color:#123b5d; border:1px solid #c7ddec;">
                                            <?php echo htmlspecialchars($v['sucursal'], ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($v['cajero'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><strong style="color:var(--green); font-size:1.05rem;">$<?php echo number_format((float) $v['total'], 2); ?></strong></td>
                                    <td>
                                        <?php if (!empty($itemsVenta)): ?>
                                            <details class="sale-details">
                                                <summary>Ver <?php echo $totalItems; ?> artículo(s)</summary>
                                                <div class="sale-items-box">
                                                    <?php foreach ($itemsVenta as $item): ?>
                                                        <div class="sale-item-row">
                                                            <span>
                                                                <strong><?php echo (int) $item['cantidad']; ?>&times;</strong> 
                                                                <?php echo htmlspecialchars($item['descripcion'], ENT_QUOTES, 'UTF-8'); ?>
                                                                <small style="color:#64748b;">($<?php echo number_format((float) $item['precio'], 2); ?> c/u)</small>
                                                            </span>
                                                            <strong>$<?php echo number_format((float) $item['subtotal'], 2); ?></strong>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </details>
                                        <?php else: ?>
                                            <span style="color:var(--muted); font-size:0.85rem;">Sin detalle disponible</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="table-empty">
                                    No se encontraron ventas registradas
                                    <?php echo $filtroSucursalSeleccionada ? 'para la sucursal seleccionada.' : 'en este momento.'; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </section>
        <?php endif; ?>

        <!-- ========================================================================= -->
        <!-- VISTA DE STOCK E INVENTARIO: ACCESIBLE PARA TODOS (CAJERO, JEFE Y ADMIN) -->
        <!-- ========================================================================= -->
        <?php if ($puedeVerStock): ?>
            <section class="card" id="inventario">
                <div class="section-heading">
                    <div>
                        <p class="eyebrow">Control de Almacén</p>
                        <h2>Inventario General de Productos y Stock</h2>
                        <p class="section-description">Consulta de precios, códigos de barra y unidades en existencia.</p>
                    </div>
                </div>

                <!-- Barra de herramientas: Buscador en tiempo real y Filtros rápidos -->
                <div class="table-toolbar">
                    <div class="search-box">
                        <label for="buscar-stock" class="visually-hidden">Buscar en inventario</label>
                        <input id="buscar-stock" type="text" placeholder="🔍 Buscar por descripción o código..." oninput="filtrarTablaStock()">
                    </div>
                    <div class="filter-group">
                        <button type="button" class="filter-pill active" data-filtro="todos" onclick="setFiltroStock('todos', this)">Todos (<?php echo count($productos); ?>)</button>
                        <button type="button" class="filter-pill" data-filtro="disponible" onclick="setFiltroStock('disponible', this)">Disponibles (<?php echo count($productos) - $agotadosCount; ?>)</button>
                        <button type="button" class="filter-pill" data-filtro="bajo" onclick="setFiltroStock('bajo', this)">Stock Bajo &le; 5 (<?php echo $stockBajoCount; ?>)</button>
                        <button type="button" class="filter-pill" data-filtro="agotado" onclick="setFiltroStock('agotado', this)">Agotados (<?php echo $agotadosCount; ?>)</button>
                    </div>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Descripción del Producto</th>
                            <th>Precio Unitario</th>
                            <th>Stock Actual</th>
                            <th>Estado de Disponibilidad</th>
                        </tr>
                    </thead>
                    <tbody id="tabla-stock-body">
                        <?php foreach ($productos as $r): ?>
                            <?php 
                            $st = (int) $r['stock'];
                            $estadoClase = 'stock-ok';
                            $estadoTexto = 'Disponible';
                            $filtroCategoria = 'disponible';
                            if ($st === 0) {
                                $estadoClase = 'stock-none';
                                $estadoTexto = 'Agotado';
                                $filtroCategoria = 'agotado';
                            } elseif ($st <= 5) {
                                $estadoClase = 'stock-low';
                                $estadoTexto = 'Stock Crítico';
                                $filtroCategoria = 'bajo';
                            }
                            ?>
                            <tr class="fila-producto" data-categoria="<?php echo $filtroCategoria; ?>" data-stock="<?php echo $st; ?>">
                                <td><code><?php echo htmlspecialchars($r['codigo_barra'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                                <td class="col-descripcion"><strong><?php echo htmlspecialchars($r['descripcion'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                <td>$<?php echo number_format((float) $r['precio'], 2); ?></td>
                                <td><strong><?php echo $st; ?></strong> unidades</td>
                                <td><span class="stock-pill <?php echo $estadoClase; ?>"><?php echo $estadoTexto; ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr id="sin-resultados-stock" style="display: none;">
                            <td colspan="5" class="table-empty">No se encontraron productos que coincidan con la búsqueda.</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        <?php endif; ?>
    </div>

    <!-- Scripts de interactividad dinámica -->
    <script>
        // 1. Manejo dinámico de Agregar al Carrito (evita recargas y saltos de scroll)
        document.querySelectorAll('.add-form').forEach(form => {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                const submitBtn = form.querySelector('button[type="submit"]');
                if (!submitBtn || submitBtn.disabled) return;

                const originalText = submitBtn.innerHTML;
                const formData = new FormData(form);
                formData.append('ajax', '1');

                submitBtn.disabled = true;
                submitBtn.innerHTML = 'Agregando...';

                fetch('sistema.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Error en el servidor (' + response.status + ')');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.ok) {
                        // Actualiza insignias del contador de carrito
                        document.querySelectorAll('.cart-badge, .cart-count-num').forEach(el => {
                            el.textContent = data.total_items;
                        });

                        // Actualiza tarjetas estadísticas del cajero si existen
                        const statItems = document.getElementById('stat-cart-items');
                        if (statItems) statItems.textContent = data.total_items;
                        const statTotal = document.getElementById('stat-cart-total');
                        if (statTotal) statTotal.textContent = '$' + data.total_monto;

                        // Actualiza el contenido del carrito abajo sin necesidad de recargar
                        const cartWrapper = document.getElementById('cart-content-wrapper');
                        if (cartWrapper && data.cart_html) {
                            cartWrapper.innerHTML = data.cart_html;
                        }

                        // Feedback visual en el botón
                        submitBtn.style.backgroundColor = '#18794e';
                        submitBtn.innerHTML = '✓ ¡Agregado!';

                        // Notificación flotante arriba a la derecha
                        mostrarNotificacion(data.mensaje, 'success');

                        setTimeout(() => {
                            submitBtn.style.backgroundColor = '';
                            submitBtn.innerHTML = originalText;
                            submitBtn.disabled = false;
                        }, 1200);
                    } else {
                        mostrarNotificacion(data.mensaje || 'No se pudo agregar el producto', 'error');
                        submitBtn.style.backgroundColor = '';
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }
                })
                .catch(err => {
                    console.error('Error al agregar vía AJAX:', err);
                    // Si ocurre cualquier falla de red, envía el formulario por el método clásico
                    form.submit();
                });
            });
        });

        // 2. Sistema de Notificaciones Toast Flotantes
        function mostrarNotificacion(mensaje, tipo) {
            let container = document.getElementById('toast-container');
            if (!container) {
                container = document.createElement('div');
                container.id = 'toast-container';
                document.body.appendChild(container);
            }
            const toast = document.createElement('div');
            toast.className = 'toast toast-' + (tipo || 'success');
            toast.innerHTML = '<span>' + (tipo === 'error' ? '⚠️' : '🛒') + '</span> <span>' + mensaje + '</span>';
            container.appendChild(toast);

            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateY(-15px)';
                toast.style.transition = 'all 0.25s ease';
                setTimeout(() => toast.remove(), 250);
            }, 2500);
        }

        // 3. Filtrado dinámico para la tabla de stock
        let filtroEstadoActual = 'todos';

        function setFiltroStock(tipo, btn) {
            filtroEstadoActual = tipo;
            document.querySelectorAll('.filter-pill').forEach(b => b.classList.remove('active'));
            if (btn) btn.classList.add('active');
            filtrarTablaStock();
        }

        function filtrarTablaStock() {
            const input = document.getElementById('buscar-stock');
            const query = input ? input.value.toLowerCase().trim() : '';
            const filas = document.querySelectorAll('.fila-producto');
            let visibles = 0;

            filas.forEach(fila => {
                const categoria = fila.getAttribute('data-categoria');
                const texto = fila.textContent.toLowerCase();
                const coincideTexto = query === '' || texto.includes(query);
                
                let coincideCategoria = true;
                if (filtroEstadoActual === 'disponible') {
                    coincideCategoria = (categoria === 'disponible' || categoria === 'bajo');
                } else if (filtroEstadoActual === 'bajo') {
                    coincideCategoria = (categoria === 'bajo');
                } else if (filtroEstadoActual === 'agotado') {
                    coincideCategoria = (categoria === 'agotado');
                }

                if (coincideTexto && coincideCategoria) {
                    fila.style.display = '';
                    visibles++;
                } else {
                    fila.style.display = 'none';
                }
            });

            const sinResultados = document.getElementById('sin-resultados-stock');
            if (sinResultados) {
                sinResultados.style.display = (visibles === 0) ? '' : 'none';
            }
        }
    </script>
</body>
</html>
