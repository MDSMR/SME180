<?php
/**
 * Impersonation Banner - Floating
 * Shows when super admin is impersonating a user
 */

// Only show if impersonating
if (!isset($_SESSION['impersonation'])) {
    return;
}

$impersonation = $_SESSION['impersonation'];
$duration = time() - strtotime($impersonation['started_at']);
$durationMinutes = floor($duration / 60);
$durationFormatted = $durationMinutes > 60 
    ? floor($durationMinutes / 60) . 'h ' . ($durationMinutes % 60) . 'm'
    : $durationMinutes . ' minutes';
?>

<style>
.impersonation-banner {
    position: fixed;
    bottom: 20px;
    right: 20px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 15px 20px;
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.3);
    z-index: 10000;
    max-width: 400px;
    animation: slideIn 0.3s ease-out;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

@keyframes slideIn {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

.impersonation-banner.minimized {
    width: 60px;
    height: 60px;
    padding: 0;
    border-radius: 50%;
    cursor: pointer;
    overflow: hidden;
}

.impersonation-content {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.impersonation-banner.minimized .impersonation-content {
    display: none;
}

.impersonation-icon-minimized {
    display: none;
    width: 60px;
    height: 60px;
    align-items: center;
    justify-content: center;
    font-size: 24px;
}

.impersonation-banner.minimized .impersonation-icon-minimized {
    display: flex;
}

.impersonation-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.impersonation-title {
    font-weight: 600;
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    opacity: 0.9;
}

.impersonation-minimize {
    background: rgba(255,255,255,0.2);
    border: none;
    color: white;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.2s;
}

.impersonation-minimize:hover {
    background: rgba(255,255,255,0.3);
}

.impersonation-info {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.impersonation-detail {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
}

.impersonation-label {
    opacity: 0.8;
    min-width: 60px;
}

.impersonation-value {
    font-weight: 600;
}

.impersonation-actions {
    display: flex;
    gap: 10px;
    margin-top: 5px;
}

.impersonation-btn {
    flex: 1;
    padding: 8px 12px;
    border: 1px solid rgba(255,255,255,0.3);
    background: rgba(255,255,255,0.1);
    color: white;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
    text-align: center;
    text-decoration: none;
}

.impersonation-btn:hover {
    background: rgba(255,255,255,0.2);
    border-color: rgba(255,255,255,0.4);
    transform: translateY(-1px);
}

.impersonation-btn.stop {
    background: rgba(239,68,68,0.2);
    border-color: rgba(239,68,68,0.4);
}

.impersonation-btn.stop:hover {
    background: rgba(239,68,68,0.3);
    border-color: rgba(239,68,68,0.5);
}

.impersonation-warning {
    background: rgba(245,158,11,0.2);
    border: 1px solid rgba(245,158,11,0.3);
    border-radius: 6px;
    padding: 8px 10px;
    font-size: 12px;
    line-height: 1.4;
    margin-top: 5px;
}

@media (max-width: 480px) {
    .impersonation-banner {
        bottom: 10px;
        right: 10px;
        left: 10px;
        max-width: none;
    }
}
</style>

<div class="impersonation-banner" id="impersonationBanner">
    <div class="impersonation-icon-minimized">
        👤
    </div>
    
    <div class="impersonation-content">
        <div class="impersonation-header">
            <div class="impersonation-title">⚠️ IMPERSONATION MODE</div>
            <button class="impersonation-minimize" onclick="toggleImpersonation()">
                <span id="minimizeIcon">−</span>
            </button>
        </div>
        
        <div class="impersonation-info">
            <div class="impersonation-detail">
                <span class="impersonation-label">Admin:</span>
                <span class="impersonation-value"><?= htmlspecialchars($impersonation['super_admin_name']) ?></span>
            </div>
            
            <div class="impersonation-detail">
                <span class="impersonation-label">Tenant:</span>
                <span class="impersonation-value"><?= htmlspecialchars($_SESSION['user']['tenant_name'] ?? 'Unknown') ?></span>
            </div>
            
            <div class="impersonation-detail">
                <span class="impersonation-label">User:</span>
                <span class="impersonation-value"><?= htmlspecialchars($_SESSION['user']['username'] ?? 'Unknown') ?></span>
            </div>
            
            <div class="impersonation-detail">
                <span class="impersonation-label">Duration:</span>
                <span class="impersonation-value" id="impersonationDuration"><?= $durationFormatted ?></span>
            </div>
            
            <?php if (!empty($impersonation['reason'])): ?>
            <div class="impersonation-detail">
                <span class="impersonation-label">Reason:</span>
                <span class="impersonation-value"><?= htmlspecialchars($impersonation['reason']) ?></span>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="impersonation-warning">
            ⚠️ All actions are being logged and attributed to the impersonated user.
        </div>
        
        <div class="impersonation-actions">
            <a href="/views/superadmin/dashboard.php" class="impersonation-btn">
                Dashboard
            </a>
            <button class="impersonation-btn stop" onclick="stopImpersonation()">
                Stop Impersonation
            </button>
        </div>
    </div>
</div>

<script>
let isMinimized = false;
let impersonationStartTime = <?= strtotime($impersonation['started_at']) ?>;

function toggleImpersonation() {
    const banner = document.getElementById('impersonationBanner');
    const icon = document.getElementById('minimizeIcon');
    
    isMinimized = !isMinimized;
    
    if (isMinimized) {
        banner.classList.add('minimized');
        icon.textContent = '+';
    } else {
        banner.classList.remove('minimized');
        icon.textContent = '−';
    }
}

function updateDuration() {
    const now = Math.floor(Date.now() / 1000);
    const duration = now - impersonationStartTime;
    const minutes = Math.floor(duration / 60);
    
    let formatted;
    if (minutes > 60) {
        const hours = Math.floor(minutes / 60);
        const mins = minutes % 60;
        formatted = hours + 'h ' + mins + 'm';
    } else {
        formatted = minutes + ' minutes';
    }
    
    document.getElementById('impersonationDuration').textContent = formatted;
}

function stopImpersonation() {
    if (confirm('Are you sure you want to stop impersonating this user?')) {
        fetch('/controllers/superadmin/impersonate.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=stop'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                window.location.href = data.redirect;
            } else {
                alert('Error: ' + data.error);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Failed to stop impersonation');
        });
    }
}

// Update duration every minute
setInterval(updateDuration, 60000);

// Make banner draggable
let isDragging = false;
let currentX;
let currentY;
let initialX;
let initialY;
let xOffset = 0;
let yOffset = 0;

const banner = document.getElementById('impersonationBanner');

banner.addEventListener('mousedown', dragStart);
document.addEventListener('mousemove', drag);
document.addEventListener('mouseup', dragEnd);

function dragStart(e) {
    if (e.target.closest('.impersonation-btn') || e.target.closest('.impersonation-minimize')) {
        return;
    }
    
    initialX = e.clientX - xOffset;
    initialY = e.clientY - yOffset;
    
    if (e.target === banner || e.target.closest('.impersonation-header')) {
        isDragging = true;
        banner.style.cursor = 'grabbing';
    }
}

function drag(e) {
    if (isDragging) {
        e.preventDefault();
        
        currentX = e.clientX - initialX;
        currentY = e.clientY - initialY;
        
        xOffset = currentX;
        yOffset = currentY;
        
        banner.style.transform = `translate(${currentX}px, ${currentY}px)`;
    }
}

function dragEnd(e) {
    initialX = currentX;
    initialY = currentY;
    
    isDragging = false;
    banner.style.cursor = 'grab';
}
</script>