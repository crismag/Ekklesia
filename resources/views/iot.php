<?php
/** IoT placeholder page */
require_once __DIR__ . '/_portal-shell.php';
$base = htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>IoT — Church Portal</title>
  <style>body{font:14px/1.5 Inter,system-ui,Arial;color:var(--ink,#17211b);margin:0;padding:20px} .card{background:#fff;border:1px solid #e6efe8;padding:16px;border-radius:8px;max-width:980px}</style>
</head>
<body>
<?= portal_header($basePath, 'IoT', 'Device integrations and status', [], null, $actor) ?>
<div class="card">
  <h1>IoT Integrations</h1>
  <p>No IoT integrations are currently configured. Use this page to manage device registrations, view device status, and configure webhooks.</p>
  <h2>Next steps</h2>
  <ul>
    <li>Add device registration API endpoints under <code>/api/iot/...</code>.</li>
    <li>Store device metadata in a new <code>portal_iot_devices</code> table.</li>
    <li>Provide webhooks or MQTT bridge for real-time events.</li>
  </ul>
</div>
</body>
</html>
