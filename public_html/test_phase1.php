<?php
/**
 * Phase 1 POS API Test Harness (JSON / FORM / GET)
 * Path: /public_html/test_phase1.php
 *
 * Includes tenant_id & branch_id (required by your schema) and lets you choose
 * how to send (JSON, form-POST, or GET) in case JSON POST is filtered.
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

$BASE = "https://" . $_SERVER['HTTP_HOST'] . "/pos/api";

// ---- DEFAULTS: set these to real values in your DB ----
$DEFAULTS = [
    'tenant_id'      => 1,        // ✳️ change to your tenant id
    'branch_id'      => 1,        // ✳️ change to your branch id
    'station_code'   => 'POS1',
    'station_name'   => 'Front POS',
    'station_type'   => 'pos',
    'pin_user'       => '1234',   // must exist in users.pin_code with status='active'
    'pin_manager'    => '5678',   // must exist in users.pin_code with manager/admin/owner
    'opening_amount' => 200.00,
    'closing_amount' => 500.00,
    'fallback_user'  => 1,        // used if pin_login doesn't return a user_id
];

function build_query(array $params): string {
    return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

function callApi(string $url, array $payload, string $mode): array {
    // $mode: json | form | get
    if ($mode === 'get') {
        $url .= (strpos($url,'?')===false ? '?' : '&') . build_query($payload);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
    } else {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_POST => true,
        ];
        if ($mode === 'json') {
            $opts[CURLOPT_HTTPHEADER]   = ['Content-Type: application/json'];
            $opts[CURLOPT_POSTFIELDS]   = json_encode($payload, JSON_UNESCAPED_UNICODE);
        } else { // form
            $opts[CURLOPT_HTTPHEADER]   = ['Content-Type: application/x-www-form-urlencoded'];
            $opts[CURLOPT_POSTFIELDS]   = build_query($payload);
        }
        curl_setopt_array($ch, $opts);
    }

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    $json = null;
    if (is_string($raw)) {
        $tmp = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) $json = $tmp;
    }

    return [
        'request_url'  => $url,
        'request_body' => $mode==='get' ? null : $payload,
        'send_mode'    => $mode,
        'raw_response' => $raw,
        'json'         => $json,
        'curl_errno'   => $errno,
        'curl_error'   => $err,
    ];
}

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// ---------- Read form ----------
$tenant_id      = (int)($_POST['tenant_id']      ?? $DEFAULTS['tenant_id']);
$branch_id      = (int)($_POST['branch_id']      ?? $DEFAULTS['branch_id']);
$station_code   =        $_POST['station_code']   ?? $DEFAULTS['station_code'];
$station_name   =        $_POST['station_name']   ?? $DEFAULTS['station_name'];
$station_type   =        $_POST['station_type']   ?? $DEFAULTS['station_type'];
$pin_user       =        $_POST['pin_user']       ?? $DEFAULTS['pin_user'];
$pin_manager    =        $_POST['pin_manager']    ?? $DEFAULTS['pin_manager'];
$opening_amount = (float)($_POST['opening_amount']?? $DEFAULTS['opening_amount']);
$closing_amount = (float)($_POST['closing_amount']?? $DEFAULTS['closing_amount']);
$fallback_user  = (int)($_POST['fallback_user']   ?? $DEFAULTS['fallback_user']);
$station_id     = isset($_POST['station_id']) && $_POST['station_id'] !== '' ? (int)$_POST['station_id'] : null;
$send_mode      = $_POST['send_mode'] ?? 'json'; // json | form | get
$run            = isset($_POST['run']);

$results = [];
$session_id_to_close = null;
$derived_user_id = null;

// ---------- Run tests ----------
if ($run) {
    // AUTH - PIN Login
    $results['Auth - PIN Login'] = callApi("$BASE/auth/pin_login.php", [
        'pin'          => $pin_user,
        'station_code' => $station_code,
        // headers not possible via GET here; pass tenant/branch in body if your auth cares
        'tenant_id'    => $tenant_id,
        'branch_id'    => $branch_id,
    ], $send_mode);

    if (!empty($results['Auth - PIN Login']['json']['success']) &&
        !empty($results['Auth - PIN Login']['json']['data']['user_id'])) {
        $derived_user_id = (int)$results['Auth - PIN Login']['json']['data']['user_id'];
    }

    // AUTH - Validate PIN
    $results['Auth - Validate PIN'] = callApi("$BASE/auth/validate_pin.php", [
        'pin' => $pin_manager,
        'tenant_id' => $tenant_id,
        'branch_id' => $branch_id,
    ], $send_mode);

    // AUTH - Logout
    $results['Auth - Logout'] = callApi("$BASE/auth/logout.php", [], $send_mode);

    // Stations - Register
    $results['Stations - Register'] = callApi("$BASE/stations/register.php", [
        'tenant_id'    => $tenant_id,
        'branch_id'    => $branch_id,
        'station_code' => $station_code,
        'station_name' => $station_name,
        'station_type' => $station_type,
    ], $send_mode);

    // Stations - Heartbeat
    $results['Stations - Heartbeat'] = callApi("$BASE/stations/heartbeat.php", [
        'tenant_id'    => $tenant_id,
        'branch_id'    => $branch_id,
        'station_code' => $station_code,
    ], $send_mode);

    // Stations - Capabilities
    $results['Stations - Capabilities'] = callApi("$BASE/stations/capabilities.php", [
        'tenant_id'    => $tenant_id,
        'branch_id'    => $branch_id,
        'station_code' => $station_code,
    ], $send_mode);

    // Session - Open
    $results['Session - Open'] = callApi("$BASE/session/open.php", [
        'tenant_id'      => $tenant_id,
        'branch_id'      => $branch_id,
        'station_id'     => $station_id, // optional
        'user_id'        => $derived_user_id ?: $fallback_user,
        'opening_amount' => $opening_amount,
    ], $send_mode);

    if (!empty($results['Session - Open']['json']['success']) &&
        !empty($results['Session - Open']['json']['data']['session_id'])) {
        $session_id_to_close = (int)$results['Session - Open']['json']['data']['session_id'];
    }

    // Session - Active
    $results['Session - Active'] = callApi("$BASE/session/active.php", [
        'tenant_id'  => $tenant_id,
        'branch_id'  => $branch_id,
        'station_id' => $station_id,
    ], $send_mode);

    if (!empty($results['Session - Active']['json']['success']) &&
        !empty($results['Session - Active']['json']['data']['id'])) {
        $session_id_to_close = (int)$results['Session - Active']['json']['data']['id'];
    }

    // Session - Close
    $results['Session - Close'] = callApi("$BASE/session/close.php", [
        'session_id'     => $session_id_to_close ?: 0,
        'closing_amount' => $closing_amount,
    ], $send_mode);

    // Echo (diagnostic)
    $results['Echo (diagnostic)'] = callApi("$BASE/_echo.php", [
        'hello'      => 'world',
        'tenant_id'  => $tenant_id,
        'branch_id'  => $branch_id,
        'station'    => ['code'=>$station_code, 'name'=>$station_name],
    ], $send_mode);

    // Health
    $results['Health'] = callApi("$BASE/health.php", [], 'get');
}

// ---------- HTML ----------
function pre($v){ return '<pre style="white-space:pre-wrap;background:#0b1020;color:#c7d2fe;padding:12px;border-radius:8px">'.htmlspecialchars($v).'</pre>'; }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Phase 1 API Tests</title>
<style>
body{font-family:system-ui,Segoe UI,Roboto,Inter,Arial,sans-serif;background:#f7f7fb;margin:0;padding:24px;color:#111}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.05);padding:16px;margin-bottom:16px}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
label{display:block;font-size:14px;margin:8px 0 4px}
input,select{width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px}
.btn{padding:10px 16px;border-radius:10px;border:1px solid #111;background:#111;color:#fff;font-weight:600}
pre{overflow:auto}
small{color:#6b7280}
</style>
</head>
<body>
<h1>Phase 1 API Tests</h1>

<form method="post" class="card">
  <div class="grid">
    <div><label>Tenant ID</label><input name="tenant_id" type="number" value="<?=h($tenant_id)?>"></div>
    <div><label>Branch ID</label><input name="branch_id" type="number" value="<?=h($branch_id)?>"></div>
    <div><label>Station Code</label><input name="station_code" value="<?=h($station_code)?>"></div>
    <div><label>Station Name</label><input name="station_name" value="<?=h($station_name)?>"></div>
    <div><label>Station Type</label>
      <select name="station_type">
        <?php foreach (['pos','bar','kitchen','host'] as $opt): ?>
          <option value="<?=$opt?>" <?= $station_type===$opt?'selected':''?>><?=$opt?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><label>Station ID (optional)</label><input name="station_id" type="number" value="<?=h($station_id)?>"><small>Use if you know it.</small></div>
    <div><label>PIN (Cashier)</label><input name="pin_user" value="<?=h($pin_user)?>"></div>
    <div><label>PIN (Manager)</label><input name="pin_manager" value="<?=h($pin_manager)?>"></div>
    <div><label>Opening Amount</label><input name="opening_amount" type="number" step="0.01" value="<?=h($opening_amount)?>"></div>
    <div><label>Closing Amount</label><input name="closing_amount" type="number" step="0.01" value="<?=h($closing_amount)?>"></div>
    <div><label>Fallback User ID</label><input name="fallback_user" type="number" value="<?=h($fallback_user)?>"></div>
    <div>
      <label>Send As</label>
      <select name="send_mode">
        <option value="json" <?= $send_mode==='json'?'selected':''?>>JSON (application/json)</option>
        <option value="form" <?= $send_mode==='form'?'selected':''?>>Form POST (x-www-form-urlencoded)</option>
        <option value="get"  <?= $send_mode==='get' ?'selected':''?>>GET (query string)</option>
      </select>
      <small>If JSON is blocked, try Form or GET.</small>
    </div>
  </div>
  <div style="margin-top:16px"><button class="btn" name="run" value="1">Run All Tests</button></div>
</form>

<?php if ($run): ?>
  <?php foreach ($results as $title => $res): ?>
    <div class="card">
      <h2 style="margin:0 0 8px"><?=h($title)?></h2>
      <div><strong>URL:</strong> <?=h($res['request_url'])?></div>
      <div><strong>Send Mode:</strong> <?=h($res['send_mode'])?></div>
      <?php if ($res['request_body'] !== null): ?>
        <div><strong>Payload:</strong><?=pre(json_encode($res['request_body'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))?></div>
      <?php endif; ?>
      <div><strong>Response:</strong><?=pre($res['raw_response'] ?? 'No response')?></div>
      <?php if (!empty($res['curl_errno'])): ?>
        <div style="color:#b91c1c"><strong>cURL Error:</strong> #<?=h($res['curl_errno'])?> <?=h($res['curl_error'])?></div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
</body>
</html>
