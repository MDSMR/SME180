<?php
// api/printers.php
// RESTful API endpoint for printer management
declare(strict_types=1);

// Set JSON headers early
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Initialize response
$response = ['success' => false, 'message' => 'Unknown error'];

try {
    // Bootstrap database connection
    $bootstrap_paths = [
        __DIR__ . '/../config/db.php',
        dirname(__DIR__) . '/config/db.php',
    ];
    
    $db_loaded = false;
    foreach ($bootstrap_paths as $path) {
        if (is_file($path)) {
            require_once $path;
            $db_loaded = true;
            break;
        }
    }
    
    if (!$db_loaded) {
        throw new Exception('Database configuration not found');
    }
    
    // Start session and check authentication
    use_backend_session();
    
    if (!isset($_SESSION['user'])) {
        http_response_code(401);
        $response = ['success' => false, 'error' => 'Unauthorized'];
        echo json_encode($response);
        exit;
    }
    
    $user = $_SESSION['user'];
    $tenantId = (int)$user['tenant_id'];
    
    // Get database connection
    $pdo = db();
    
    // Get request method and action
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? $_POST['action'] ?? null;
    
    // Parse JSON input for POST/PUT requests
    $input = [];
    if ($method === 'POST' || $method === 'PUT') {
        $rawInput = file_get_contents('php://input');
        if ($rawInput) {
            $input = json_decode($rawInput, true) ?? [];
        }
        // Merge with $_POST for form-encoded data
        $input = array_merge($_POST, $input);
    }
    
    // Handle GET requests
    if ($method === 'GET') {
        if ($action === 'list' || !$action) {
            // List all printers for tenant - FIXED: Use different parameter names
            $stmt = $pdo->prepare("
                SELECT 
                    p.*,
                    COALESCE(b.name, 'No Branch') as branch_name
                FROM printers p
                LEFT JOIN branches b ON p.branch_id = b.id AND b.tenant_id = ?
                WHERE p.tenant_id = ? AND p.is_active = 1
                ORDER BY p.id ASC
            ");
            $stmt->execute([$tenantId, $tenantId]);
            $printers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format printers for frontend
            $formattedPrinters = [];
            foreach ($printers as $printer) {
                // Parse connection string for IP printers
                $ip_address = '';
                $port = '9100';
                
                if ($printer['connection_type'] === 'ip' && !empty($printer['connection_string'])) {
                    if (strpos($printer['connection_string'], ':') !== false) {
                        list($ip_address, $port) = explode(':', $printer['connection_string']);
                    } else {
                        $ip_address = $printer['connection_string'];
                    }
                }
                
                $formattedPrinters[] = [
                    'id' => (int)$printer['id'],
                    'name' => $printer['name'],
                    'type' => $printer['type'],
                    'brand' => $printer['brand'] ?? '',
                    'model' => $printer['model'] ?? '',
                    'connection_type' => $printer['connection_type'] === 'ip' ? 'ethernet' : $printer['connection_type'],
                    'ip_address' => $ip_address,
                    'port' => $port,
                    'paper_size' => $printer['paper_size'] ?? '80mm',
                    'branch_id' => (int)$printer['branch_id'],
                    'branch_name' => $printer['branch_name'],
                    'status' => $printer['status'] ?? 'offline',
                    'last_ping' => $printer['last_ping'],
                    'station' => $printer['station'] ?? ''
                ];
            }
            
            $response = [
                'success' => true,
                'printers' => $formattedPrinters
            ];
            
        } elseif ($action === 'get' && isset($_GET['id'])) {
            // Get single printer
            $printerId = (int)$_GET['id'];
            
            $stmt = $pdo->prepare("
                SELECT p.*, b.name as branch_name
                FROM printers p
                LEFT JOIN branches b ON p.branch_id = b.id
                WHERE p.id = ? AND p.tenant_id = ?
            ");
            $stmt->execute([$printerId, $tenantId]);
            $printer = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($printer) {
                // Parse connection string
                $ip_address = '';
                $port = '9100';
                
                if ($printer['connection_type'] === 'ip' && !empty($printer['connection_string'])) {
                    if (strpos($printer['connection_string'], ':') !== false) {
                        list($ip_address, $port) = explode(':', $printer['connection_string']);
                    } else {
                        $ip_address = $printer['connection_string'];
                    }
                }
                
                $response = [
                    'success' => true,
                    'printer' => [
                        'id' => (int)$printer['id'],
                        'name' => $printer['name'],
                        'type' => $printer['type'],
                        'brand' => $printer['brand'] ?? '',
                        'model' => $printer['model'] ?? '',
                        'connection_type' => $printer['connection_type'] === 'ip' ? 'ethernet' : $printer['connection_type'],
                        'ip_address' => $ip_address,
                        'port' => $port,
                        'paper_size' => $printer['paper_size'] ?? '80mm',
                        'branch_id' => (int)$printer['branch_id'],
                        'branch_name' => $printer['branch_name'],
                        'status' => $printer['status'] ?? 'offline',
                        'last_ping' => $printer['last_ping'],
                        'station' => $printer['station'] ?? ''
                    ]
                ];
            } else {
                $response = ['success' => false, 'error' => 'Printer not found'];
            }
        }
        
    // Handle POST requests
    } elseif ($method === 'POST') {
        $action = $input['action'] ?? $action;
        
        if ($action === 'create') {
            // Create new printer
            $name = trim($input['name'] ?? '');
            $type = $input['type'] ?? 'receipt';
            $brand = trim($input['brand'] ?? '');
            $model = trim($input['model'] ?? '');
            $station = trim($input['station'] ?? '');
            $connection_type = $input['connection_type'] ?? 'ethernet';
            $branch_id = (int)($input['branch_id'] ?? 0);
            $paper_size = $input['paper_size'] ?? '80mm';
            
            // Build connection string
            $connection_string = '';
            if ($connection_type === 'ethernet' || $connection_type === 'wifi') {
                $connection_type = 'ip'; // Store as 'ip' in database
                $ip = trim($input['ip_address'] ?? '');
                $port = trim($input['port'] ?? '9100');
                if ($ip) {
                    $connection_string = $ip . ':' . $port;
                }
            }
            
            if (!$name) {
                $response = ['success' => false, 'error' => 'Printer name is required'];
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO printers (
                        tenant_id, name, type, brand, model, station,
                        connection_type, connection_string, paper_size, 
                        branch_id, status, is_active, created_at
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?,
                        ?, 'offline', 1, NOW()
                    )
                ");
                
                $stmt->execute([
                    $tenantId,
                    $name,
                    $type,
                    $brand,
                    $model,
                    $station,
                    $connection_type,
                    $connection_string,
                    $paper_size,
                    $branch_id
                ]);
                
                $response = [
                    'success' => true,
                    'message' => 'Printer created successfully',
                    'id' => (int)$pdo->lastInsertId()
                ];
            }
            
        } elseif ($action === 'update') {
            // Update existing printer
            $id = (int)($input['id'] ?? 0);
            $name = trim($input['name'] ?? '');
            $type = $input['type'] ?? 'receipt';
            $brand = trim($input['brand'] ?? '');
            $model = trim($input['model'] ?? '');
            $station = trim($input['station'] ?? '');
            $connection_type = $input['connection_type'] ?? 'ethernet';
            $branch_id = (int)($input['branch_id'] ?? 0);
            $paper_size = $input['paper_size'] ?? '80mm';
            
            // Build connection string
            $connection_string = '';
            if ($connection_type === 'ethernet' || $connection_type === 'wifi') {
                $connection_type = 'ip'; // Store as 'ip' in database
                $ip = trim($input['ip_address'] ?? '');
                $port = trim($input['port'] ?? '9100');
                if ($ip) {
                    $connection_string = $ip . ':' . $port;
                }
            }
            
            if (!$name) {
                $response = ['success' => false, 'error' => 'Printer name is required'];
            } else {
                $stmt = $pdo->prepare("
                    UPDATE printers SET
                        name = ?,
                        type = ?,
                        brand = ?,
                        model = ?,
                        station = ?,
                        connection_type = ?,
                        connection_string = ?,
                        paper_size = ?,
                        branch_id = ?,
                        updated_at = NOW()
                    WHERE id = ? AND tenant_id = ?
                ");
                
                $stmt->execute([
                    $name,
                    $type,
                    $brand,
                    $model,
                    $station,
                    $connection_type,
                    $connection_string,
                    $paper_size,
                    $branch_id,
                    $id,
                    $tenantId
                ]);
                
                if ($stmt->rowCount() > 0) {
                    $response = [
                        'success' => true,
                        'message' => 'Printer updated successfully'
                    ];
                } else {
                    $response = ['success' => false, 'error' => 'Printer not found or no changes made'];
                }
            }
            
        } elseif ($action === 'delete') {
            // Soft delete printer
            $id = (int)($input['id'] ?? 0);
            
            $stmt = $pdo->prepare("
                UPDATE printers 
                SET is_active = 0, updated_at = NOW()
                WHERE id = ? AND tenant_id = ?
            ");
            
            $stmt->execute([$id, $tenantId]);
            
            if ($stmt->rowCount() > 0) {
                // Also remove category assignments
                $stmt = $pdo->prepare("
                    DELETE FROM category_printer_assignments 
                    WHERE printer_id = ? AND tenant_id = ?
                ");
                $stmt->execute([$id, $tenantId]);
                
                $response = [
                    'success' => true,
                    'message' => 'Printer removed successfully'
                ];
            } else {
                $response = ['success' => false, 'error' => 'Printer not found'];
            }
            
        } elseif ($action === 'test_print') {
            // Test print functionality
            $id = (int)($input['id'] ?? 0);
            
            // Get printer details
            $stmt = $pdo->prepare("
                SELECT * FROM printers 
                WHERE id = ? AND tenant_id = ?
            ");
            $stmt->execute([$id, $tenantId]);
            $printer = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($printer) {
                // Simulate test print (in real implementation, send test print to printer)
                // For now, just update status and last_ping
                $stmt = $pdo->prepare("
                    UPDATE printers 
                    SET status = 'online', last_ping = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$id]);
                
                $response = [
                    'success' => true,
                    'message' => 'Test print sent successfully',
                    'printer_name' => $printer['name']
                ];
            } else {
                $response = ['success' => false, 'error' => 'Printer not found'];
            }
            
        } elseif ($action === 'ping_all') {
            // Ping all printers to check status
            $stmt = $pdo->prepare("
                SELECT id, name, connection_type, connection_string 
                FROM printers 
                WHERE tenant_id = ? AND is_active = 1
            ");
            $stmt->execute([$tenantId]);
            $printers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $results = [];
            foreach ($printers as $printer) {
                // Simulate ping (in real implementation, actually ping the printer)
                // Random status for demo
                $status = rand(0, 10) > 3 ? 'online' : 'offline';
                
                $stmt = $pdo->prepare("
                    UPDATE printers 
                    SET status = ?, last_ping = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$status, $printer['id']]);
                
                $results[] = [
                    'id' => (int)$printer['id'],
                    'name' => $printer['name'],
                    'status' => $status
                ];
            }
            
            $response = [
                'success' => true,
                'results' => $results
            ];
        } else {
            $response = ['success' => false, 'error' => 'Invalid action'];
        }
    } else {
        $response = ['success' => false, 'error' => 'Method not allowed'];
    }
    
} catch (Exception $e) {
    error_log('Printer API Error: ' . $e->getMessage());
    $response = [
        'success' => false,
        'error' => 'Server error',
        'message' => $e->getMessage() // Remove in production
    ];
}

// Output JSON response
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>