<?php
// public_html/api/category-printer-assignments.php - DEBUG VERSION
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Bootstrap database connection
$bootstrap_paths = [
    __DIR__ . '/../config/db.php',
    __DIR__ . '/../../config/db.php',
    dirname(__DIR__) . '/config/db.php'
];

$bootstrap_ok = false;
foreach ($bootstrap_paths as $path) {
    if (is_file($path)) {
        try {
            require_once $path;
            if (function_exists('use_backend_session') && function_exists('db')) {
                $bootstrap_ok = true;
                break;
            }
        } catch (Throwable $e) {
            error_log("Bootstrap error: " . $e->getMessage());
        }
    }
}

if (!$bootstrap_ok) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Configuration error']);
    exit;
}

try {
    use_backend_session();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Session error']);
    exit;
}

$user = $_SESSION['user'] ?? null;
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

try {
    $pdo = db();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$tenantId = (int)$user['tenant_id'];
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'update':
            $categoryId = (int)($input['category_id'] ?? 0);
            $printerId = !empty($input['printer_id']) ? (int)$input['printer_id'] : null;
            
            if (!$categoryId) {
                throw new Exception('Category ID required');
            }
            
            // DEBUG: Log what we received
            error_log("Category Assignment Debug - Category ID: $categoryId, Printer ID: " . ($printerId ?? 'NULL') . ", Tenant ID: $tenantId");
            
            // Validate category exists and belongs to tenant
            $stmt = $pdo->prepare("SELECT id FROM categories WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$categoryId, $tenantId]);
            if (!$stmt->fetch()) {
                throw new Exception('Category not found');
            }
            
            // If printer_id is provided, validate it exists and is active
            if ($printerId) {
                // DEBUG: Check what printers exist for this tenant
                $debugStmt = $pdo->prepare("SELECT id, name, is_active FROM printers WHERE tenant_id = ? ORDER BY id");
                $debugStmt->execute([$tenantId]);
                $allPrinters = $debugStmt->fetchAll(PDO::FETCH_ASSOC);
                error_log("Available printers for tenant $tenantId: " . json_encode($allPrinters));
                
                $stmt = $pdo->prepare("SELECT id, name, is_active FROM printers WHERE id = ? AND tenant_id = ?");
                $stmt->execute([$printerId, $tenantId]);
                $printer = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$printer) {
                    throw new Exception("Printer ID $printerId not found for tenant $tenantId");
                }
                
                if ($printer['is_active'] != 1) {
                    throw new Exception("Printer '{$printer['name']}' (ID: $printerId) is inactive (is_active = {$printer['is_active']})");
                }
            }
            
            // Remove existing assignment for this category
            $stmt = $pdo->prepare("DELETE FROM category_printer_assignments WHERE category_id = ? AND tenant_id = ?");
            $stmt->execute([$categoryId, $tenantId]);
            
            // Add new assignment if printer_id provided
            if ($printerId) {
                $stmt = $pdo->prepare("
                    INSERT INTO category_printer_assignments (category_id, printer_id, tenant_id) 
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$categoryId, $printerId, $tenantId]);
            }
            
            echo json_encode([
                'success' => true, 
                'message' => 'Category assignment updated successfully',
                'debug' => [
                    'category_id' => $categoryId,
                    'printer_id' => $printerId,
                    'tenant_id' => $tenantId
                ]
            ]);
            break;
            
        case 'debug':
            // New debug endpoint to check data
            $stmt = $pdo->prepare("SELECT id, name, is_active FROM printers WHERE tenant_id = ? ORDER BY id");
            $stmt->execute([$tenantId]);
            $printers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $stmt = $pdo->prepare("SELECT id, name_en, name_ar FROM categories WHERE tenant_id = ? AND is_active = 1 LIMIT 5");
            $stmt->execute([$tenantId]);
            $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'debug_info' => [
                    'tenant_id' => $tenantId,
                    'printers' => $printers,
                    'categories' => $categories
                ]
            ]);
            break;
            
        case 'get':
            $categoryId = (int)($_GET['category_id'] ?? 0);
            if (!$categoryId) {
                throw new Exception('Category ID required');
            }
            
            $stmt = $pdo->prepare("
                SELECT printer_id 
                FROM category_printer_assignments 
                WHERE category_id = ? AND tenant_id = ?
            ");
            $stmt->execute([$categoryId, $tenantId]);
            $assignments = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            echo json_encode([
                'success' => true, 
                'assignments' => array_map('intval', $assignments)
            ]);
            break;
            
        case 'list':
            $stmt = $pdo->prepare("
                SELECT 
                    cpa.category_id,
                    cpa.printer_id,
                    c.name_en,
                    c.name_ar,
                    p.name as printer_name
                FROM category_printer_assignments cpa
                JOIN categories c ON cpa.category_id = c.id
                JOIN printers p ON cpa.printer_id = p.id
                WHERE cpa.tenant_id = ? AND p.is_active = 1
                ORDER BY c.name_en ASC
            ");
            $stmt->execute([$tenantId]);
            $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true, 
                'assignments' => $assignments
            ]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    error_log("Category Assignment API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>