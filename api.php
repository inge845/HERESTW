<?php
// --- Muestra todos los errores (SOLO PARA PRÁCTICA) ---
ini_set('display_errors', 1);
error_reporting(E_ALL);
// ------------------------------------

// --- 1. Datos de Conexión ---
$host = 'localhost';
$port = '5432';
$dbname = 'tienda_rusowip';
$user = 'postgres';
$password = '845'; // Asumo que esta es tu contraseña real

// --- 2. Permisos (CORS) ---
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS"); // Agregamos GET
header("Content-Type: application/json; charset=UTF-8");

// Manejo de solicitud preliminar OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// --- 3. Conexión a la Base de Datos ---
$dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $password, $options);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Error de conexión a la BD: " . $e->getMessage()]);
    exit();
}

// --- 4. LÓGICA DEL "MESERO" (Ruteador) ---
$metodo = $_SERVER['REQUEST_METHOD'];

// ========================================================
// MODO GET: Alguien está PIDIENDO datos (Cargar productos)
// ========================================================
if ($metodo === 'GET') {
    try {
        // Simplemente seleccionamos los productos para enviarlos al frontend
        $stmt = $pdo->prepare("SELECT id, nombre, stock FROM productos ORDER BY id ASC");
        $stmt->execute();
        $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        http_response_code(200);
        echo json_encode($productos); // Envía la lista de productos como JSON

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Error en la base de datos al obtener productos: " . $e->getMessage()]);
    }
} 
// ========================================================
// MODO POST: Alguien está ENVIANDO datos (Comprar o Actualizar Stock)
// ========================================================
else if ($metodo === 'POST') {
    
    $datos = json_decode(file_get_contents("php://input"));
    if (!$datos) {
        http_response_code(400);
        echo json_encode(["error" => "No se recibieron datos JSON."]);
        exit();
    }

    // --- RUTA POST 1: REALIZAR PEDIDO ---
    if (isset($datos->carrito) && !empty($datos->carrito)) {
        
        $carrito = $datos->carrito;
        $metodoPago = $datos->metodoPago;
        $total = $datos->total;

        try {
            $pdo->beginTransaction();
            // Paso A: Actualizar stock
            $stmtUpdate = $pdo->prepare("UPDATE productos SET stock = stock - ? WHERE id = ? AND stock >= ?");
            foreach ($carrito as $item) {
                $stmtUpdate->execute([$item->cantidad, $item->id, $item->cantidad]);
                if ($stmtUpdate->rowCount() === 0) {
                    throw new Exception("Stock insuficiente para el producto ID {$item->id}.");
                }
            }
            // Paso B: Registrar pedido (Asumimos cliente 1)
            $stmtPedido = $pdo->prepare("INSERT INTO pedidos (cliente_id, total, estado, metodo_pago) VALUES (?, ?, ?, ?) RETURNING id");
            $stmtPedido->execute([1, $total, 'Pagado', $metodoPago]);
            $nuevoPedidoId = $stmtPedido->fetchColumn();
            
            // Paso C: Registrar detalle del pedido
            $stmtDetalle = $pdo->prepare("INSERT INTO detalle_pedido (pedido_id, producto_id, cantidad, precio_unitario) VALUES (?, ?, ?, ?)");
            foreach ($carrito as $item) {
                $stmtDetalle->execute([$nuevoPedidoId, $item->id, $item->cantidad, $item->precio]);
            }
            $pdo->commit();
            http_response_code(200);
            echo json_encode(["mensaje" => "¡Pedido realizado con éxito! El stock ha sido actualizado."]);
        
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            http_response_code(400);
            echo json_encode(["error" => "Error al procesar el pedido: " . $e->getMessage()]);
        }
        exit();
    } 
    
    // --- (NUEVO) RUTA POST 2: ACTUALIZAR STOCK ---
    else if (isset($datos->accion) && $datos->accion == 'actualizar_stock') {
        
        if (!isset($datos->producto_id) || !isset($datos->cantidad_agregar)) {
            http_response_code(400);
            echo json_encode(["error" => "Datos incompletos para actualizar stock."]);
            exit();
        }

        $producto_id = $datos->producto_id;
        $cantidad_agregar = $datos->cantidad_agregar;

        try {
            // Usamos stock = stock + ? para AGREGAR al inventario
            $stmt = $pdo->prepare("UPDATE productos SET stock = stock + ? WHERE id = ?");
            $stmt->execute([$cantidad_agregar, $producto_id]);

            http_response_code(200);
            echo json_encode(["mensaje" => "¡Stock actualizado correctamente!"]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Error en la base de datos al actualizar stock: " . $e->getMessage()]);
        }
        exit();
    } 
    
    // --- Si no es ninguna de las dos ---
    else {
        http_response_code(400);
        echo json_encode(["error" => "Acción POST no reconocida."]);
    }
} 
// ========================================================
// OTRO MÉTODO (ej. PUT, DELETE)
// ========================================================
else {
    http_response_code(405); // Método no permitido
    echo json_encode(["error" => "Método no permitido."]);
}
?>