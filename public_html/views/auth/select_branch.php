<?php
// /views/auth/select_branch.php - Branch selection for multi-branch users
declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
use_backend_session();

// Check if user is logged in but needs to select branch
if (empty($_SESSION['user_id']) || !empty($_SESSION['branch_id'])) {
  redirect('/views/admin/dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $branch_id = (int)($_POST['branch_id'] ?? 0);
  
  try {
    $pdo = db();
    
    // Verify user has access to this branch
    $stmt = $pdo->prepare("
      SELECT b.id, b.name 
      FROM branches b
      JOIN user_branches ub ON b.id = ub.branch_id
      WHERE ub.user_id = :user_id 
        AND b.id = :branch_id
        AND b.is_active = 1
    ");
    
    $stmt->execute([
      ':user_id' => $_SESSION['user_id'],
      ':branch_id' => $branch_id
    ]);
    
    $branch = $stmt->fetch();
    
    if ($branch) {
      $_SESSION['branch_id'] = $branch['id'];
      $_SESSION['branch_name'] = $branch['name'];
      $_SESSION['user']['branch_id'] = $branch['id'];
      $_SESSION['user']['branch_name'] = $branch['name'];
      
      redirect('/views/admin/dashboard.php');
    } else {
      $error = 'Invalid branch selection.';
    }
  } catch (Exception $e) {
    error_log('Branch selection error: ' . $e->getMessage());
    $error = 'An error occurred. Please try again.';
  }
}

// Get user's available branches
$branches = [];
try {
  $pdo = db();
  $stmt = $pdo->prepare("
    SELECT b.id, b.name, b.address, b.branch_type
    FROM branches b
    JOIN user_branches ub ON b.id = ub.branch_id
    WHERE ub.user_id = :user_id 
      AND b.is_active = 1
    ORDER BY b.name
  ");
  
  $stmt->execute([':user_id' => $_SESSION['user_id']]);
  $branches = $stmt->fetchAll();
  
} catch (Exception $e) {
  error_log('Failed to load branches: ' . $e->getMessage());
  $error = 'Unable to load branches.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Select Branch - Smorll POS</title>
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Oxygen', 'Ubuntu', sans-serif;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      padding: 20px;
    }

    .container {
      background: rgba(255, 255, 255, 0.95);
      backdrop-filter: blur(20px);
      border-radius: 20px;
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
      padding: 40px;
      width: 100%;
      max-width: 600px;
    }

    h2 {
      color: #1a1a2e;
      font-size: 28px;
      font-weight: 700;
      margin-bottom: 8px;
      text-align: center;
    }

    .subtitle {
      color: #666;
      font-size: 14px;
      margin-bottom: 30px;
      text-align: center;
    }

    .error {
      background: linear-gradient(135deg, #ff6b6b 0%, #ff5252 100%);
      color: white;
      padding: 12px 16px;
      border-radius: 10px;
      margin-bottom: 20px;
      font-size: 14px;
      text-align: center;
    }

    .branch-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
      gap: 20px;
    }

    .branch-card {
      background: white;
      border: 2px solid #e0e0e0;
      border-radius: 12px;
      padding: 20px;
      cursor: pointer;
      transition: all 0.3s ease;
      text-align: left;
      width: 100%;
    }

    .branch-card:hover {
      border-color: #667eea;
      box-shadow: 0 8px 25px rgba(102, 126, 234, 0.2);
      transform: translateY(-2px);
    }

    .branch-card h3 {
      color: #1a1a2e;
      font-size: 18px;
      font-weight: 600;
      margin-bottom: 8px;
    }

    .branch-card p {
      color: #666;
      font-size: 14px;
      margin-bottom: 8px;
    }

    .branch-type {
      display: inline-block;
      padding: 4px 8px;
      background: #f0f0f0;
      border-radius: 4px;
      font-size: 12px;
      color: #666;
      margin-top: 8px;
    }

    .branch-type.central_kitchen {
      background: #e3f2fd;
      color: #1976d2;
    }

    .branch-type.sales_branch {
      background: #f3e5f5;
      color: #7b1fa2;
    }

    .branch-type.mixed {
      background: #e8f5e9;
      color: #388e3c;
    }

    .logout-link {
      display: block;
      text-align: center;
      margin-top: 20px;
      color: #666;
      text-decoration: none;
      font-size: 14px;
    }

    .logout-link:hover {
      color: #667eea;
    }
  </style>
</head>
<body>
  <div class="container">
    <h2>Select Your Branch</h2>
    <p class="subtitle">Choose the branch you want to work with today</p>
    
    <?php if($error): ?>
      <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <?php if(empty($branches)): ?>
      <p style="text-align: center; color: #666; padding: 40px 0;">
        No branches available. Please contact your administrator.
      </p>
    <?php else: ?>
      <form method="post">
        <div class="branch-grid">
          <?php foreach($branches as $branch): ?>
            <button type="submit" name="branch_id" value="<?= $branch['id'] ?>" class="branch-card">
              <h3><?= htmlspecialchars($branch['name']) ?></h3>
              <?php if(!empty($branch['address'])): ?>
                <p><?= htmlspecialchars($branch['address']) ?></p>
              <?php endif; ?>
              <span class="branch-type <?= htmlspecialchars($branch['branch_type']) ?>">
                <?= htmlspecialchars(str_replace('_', ' ', $branch['branch_type'])) ?>
              </span>
            </button>
          <?php endforeach; ?>
        </div>
      </form>
    <?php endif; ?>
    
    <a href="/controllers/auth/logout.php" class="logout-link">← Logout</a>
  </div>
</body>
</html>