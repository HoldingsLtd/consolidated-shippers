<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/functions.php';
if (is_file(dirname(__DIR__) . '/includes/compat.php')) {
    require_once dirname(__DIR__) . '/includes/compat.php';
}
if (!function_exists('cs_config') && function_exists('cf_config')) {
    function cs_config(): array { return cf_config(); }
}
if (!function_exists('cs_db') && function_exists('cf_db')) {
    function cs_db(): ?PDO { return cf_db(); }
}
if (!function_exists('cs_hash') && function_exists('cf_hash')) {
    function cs_hash(string $password): string { return cf_hash($password); }
}
if (!function_exists('places')) {
    function places(): array
    {
        return [
            'Overland Park, Kansas' => [38.9822, -94.6708],
            'Kansas City' => [39.0997, -94.5786],
            'Dallas' => [32.7767, -96.7970],
            'Chicago' => [41.8781, -87.6298],
            'Houston' => [29.7604, -95.3698],
            'New York / New Jersey' => [40.6895, -74.0447],
            'Los Angeles' => [33.7405, -118.2720],
            'North Atlantic' => [42.2000, -38.5000],
            'Hamburg' => [53.5511, 9.9937],
            'Bremerhaven' => [53.5396, 8.5809],
            'Frankfurt' => [50.0379, 8.5622],
            'Rotterdam' => [51.9225, 4.4792],
            'Antwerp' => [51.2194, 4.4025],
            'Amsterdam' => [52.3105, 4.7683],
        ];
    }
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
function admin_ok(): bool { return !empty($_SESSION['admin']); }
if (isset($_GET['out'])) { unset($_SESSION['admin']); header('Location: index.php'); exit; }
$error = '';
$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $cfg = cs_config();
    if (hash_equals($cfg['admin_user'], (string) ($_POST['user'] ?? '')) && hash_equals($cfg['admin_pass_hash'], cs_hash((string) ($_POST['password'] ?? '')))) {
        $_SESSION['admin'] = $cfg['admin_user'];
        header('Location: index.php');
        exit;
    }
    $error = 'Sign-in failed.';
}
$db = admin_ok() ? cs_db() : null;
if (admin_ok() && !$db) {
    $error = 'Database not connected.';
}
if (admin_ok() && $db && $_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id = (int) ($_POST['id'] ?? 0);
        $fields = [
            trim($_POST['reference'] ?? ''), trim($_POST['customer_email'] ?? ''), trim($_POST['shipper'] ?? ''), trim($_POST['consignee'] ?? ''),
            trim($_POST['origin'] ?? ''), trim($_POST['destination'] ?? ''), (float) $_POST['origin_lat'], (float) $_POST['origin_lng'],
            (float) $_POST['dest_lat'], (float) $_POST['dest_lng'], trim($_POST['current_label'] ?? ''), (float) $_POST['current_lat'], (float) $_POST['current_lng'],
            trim($_POST['mode'] ?? ''), trim($_POST['cargo'] ?? ''), trim($_POST['status'] ?? ''), isset($_POST['halted']) ? 1 : 0, trim($_POST['halt_reason'] ?? ''),
            ($_POST['eta'] ?? '') !== '' ? $_POST['eta'] : null, trim($_POST['notes'] ?? ''),
        ];
        if ($id > 0) {
            $stmt = $db->prepare('UPDATE shipments SET reference=?, customer_email=?, shipper=?, consignee=?, origin=?, destination=?, origin_lat=?, origin_lng=?, dest_lat=?, dest_lng=?, current_label=?, current_lat=?, current_lng=?, mode=?, cargo=?, status=?, halted=?, halt_reason=?, eta=?, notes=? WHERE id=?');
            $stmt->execute([...$fields, $id]);
            $notice = 'Shipment updated.';
        } else {
            $stmt = $db->prepare('INSERT INTO shipments (reference, customer_email, shipper, consignee, origin, destination, origin_lat, origin_lng, dest_lat, dest_lng, current_label, current_lat, current_lng, mode, cargo, status, halted, halt_reason, eta, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute($fields);
            $id = (int) $db->lastInsertId();
            $notice = 'Shipment added.';
        }
        header('Location: index.php?edit=' . $id . '&saved=1');
        exit;
    }
    if ($action === 'event') {
        $image = '';
        if (!empty($_FILES['photo']['tmp_name']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $dir = dirname(__DIR__) . '/uploads/tracking';
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                $image = 'uploads/tracking/' . date('YmdHis') . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
                move_uploaded_file($_FILES['photo']['tmp_name'], dirname(__DIR__) . '/' . $image);
            }
        }
        try {
            $stmt = $db->prepare('INSERT INTO shipment_events (shipment_id, event_time, location, lat, lng, detail, image) VALUES (?,?,?,?,?,?,?)');
            $stmt->execute([(int) $_POST['shipment_id'], $_POST['event_time'], trim($_POST['location'] ?? ''), $_POST['lat'] !== '' ? $_POST['lat'] : null, $_POST['lng'] !== '' ? $_POST['lng'] : null, trim($_POST['detail'] ?? ''), $image]);
        } catch (Throwable $e) {
            $stmt = $db->prepare('INSERT INTO shipment_events (shipment_id, event_time, location, lat, lng, detail) VALUES (?,?,?,?,?,?)');
            $stmt->execute([(int) $_POST['shipment_id'], $_POST['event_time'], trim($_POST['location'] ?? ''), $_POST['lat'] !== '' ? $_POST['lat'] : null, $_POST['lng'] !== '' ? $_POST['lng'] : null, trim($_POST['detail'] ?? '')]);
        }
        header('Location: index.php?edit=' . (int) $_POST['shipment_id']);
        exit;
    }
    if ($action === 'delete_event') {
        $db->prepare('DELETE FROM shipment_events WHERE id = ?')->execute([(int) $_POST['event_id']]);
        header('Location: index.php?edit=' . (int) $_POST['shipment_id']);
        exit;
    }
}
$view = $_GET['view'] ?? 'shipments';
$edit = null;
$events = [];
if ($db && isset($_GET['edit'])) {
    $stmt = $db->prepare('SELECT * FROM shipments WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
    if ($edit) {
        $ev = $db->prepare('SELECT * FROM shipment_events WHERE shipment_id = ? ORDER BY event_time DESC');
        $ev->execute([$edit['id']]);
        $events = $ev->fetchAll();
    }
}
if ($db && isset($_GET['new'])) {
    $edit = ['id' => 0, 'reference' => 'CSK-' . random_int(100000, 999999), 'customer_email' => '', 'shipper' => '', 'consignee' => '', 'origin' => 'Kansas City', 'destination' => 'Hamburg', 'origin_lat' => 39.0997, 'origin_lng' => -94.5786, 'dest_lat' => 53.5511, 'dest_lng' => 9.9937, 'current_label' => 'Kansas City', 'current_lat' => 39.0997, 'current_lng' => -94.5786, 'mode' => 'Ocean FCL', 'cargo' => '', 'status' => 'Booked', 'halted' => 0, 'halt_reason' => '', 'eta' => '', 'notes' => ''];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Desk · Consolidated Shippers</title>
  <link rel="stylesheet" href="../assets/css/style.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
</head>
<body>
<main class="section"><div class="wrap">
  <h1>Operations desk</h1>
  <?php if (!admin_ok()): ?>
    <?php if ($error): ?><div class="flash err"><?= e($error) ?></div><?php endif; ?>
    <form class="form-card" method="post" style="max-width:420px">
      <input type="hidden" name="action" value="login">
      <label>User<input name="user" required></label>
      <label>Password<input type="password" name="password" required></label>
      <button class="btn btn-navy" style="margin-top:12px" type="submit">Sign in</button>
    </form>
    <p class="note">Default admin / ChangeMe!2026. Change it before launch.</p>
  <?php else: ?>
    <p class="admin-nav"><a href="index.php">Shipments</a><a href="index.php?new=1">Add shipment</a><a href="index.php?view=quotes">Quotes</a><a href="index.php?view=bookings">Bookings</a><a href="index.php?view=messages">Messages</a><a href="index.php?out=1">Sign out</a></p>
    <?php if ($error): ?><div class="flash err"><?= e($error) ?></div><?php endif; ?>
    <?php if (isset($_GET['saved'])): ?><div class="flash ok">Saved. The public track page uses these coordinates.</div><?php endif; ?>
    <?php if ($edit): ?>
      <form class="form-card" method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
        <div class="form-grid">
          <label>Reference<input name="reference" value="<?= e($edit['reference']) ?>" required></label>
          <label>Customer email<input name="customer_email" value="<?= e($edit['customer_email']) ?>"></label>
          <label>Shipper<input name="shipper" value="<?= e($edit['shipper']) ?>" required></label>
          <label>Consignee<input name="consignee" value="<?= e($edit['consignee']) ?>" required></label>
          <label>Departure place<select id="origin-place"><?php foreach (places() as $name => $xy): ?><option data-lat="<?= $xy[0] ?>" data-lng="<?= $xy[1] ?>" <?= $edit['origin'] === $name ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?></select></label>
          <label>Destination place<select id="dest-place"><?php foreach (places() as $name => $xy): ?><option data-lat="<?= $xy[0] ?>" data-lng="<?= $xy[1] ?>" <?= $edit['destination'] === $name ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?></select></label>
          <label>Departure name<input name="origin" id="origin" value="<?= e($edit['origin']) ?>"></label>
          <label>Destination name<input name="destination" id="destination" value="<?= e($edit['destination']) ?>"></label>
          <label>Departure lat<input name="origin_lat" id="origin_lat" value="<?= e((string) $edit['origin_lat']) ?>"></label>
          <label>Departure lng<input name="origin_lng" id="origin_lng" value="<?= e((string) $edit['origin_lng']) ?>"></label>
          <label>Destination lat<input name="dest_lat" id="dest_lat" value="<?= e((string) $edit['dest_lat']) ?>"></label>
          <label>Destination lng<input name="dest_lng" id="dest_lng" value="<?= e((string) $edit['dest_lng']) ?>"></label>
          <label>Current place<select id="current-place"><?php foreach (places() as $name => $xy): ?><option data-lat="<?= $xy[0] ?>" data-lng="<?= $xy[1] ?>" <?= $edit['current_label'] === $name ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?></select></label>
          <label>Current label<input name="current_label" id="current_label" value="<?= e($edit['current_label']) ?>"></label>
          <label>Current lat<input name="current_lat" id="current_lat" value="<?= e((string) $edit['current_lat']) ?>"></label>
          <label>Current lng<input name="current_lng" id="current_lng" value="<?= e((string) $edit['current_lng']) ?>"></label>
          <label>Mode<input name="mode" value="<?= e($edit['mode']) ?>"></label>
          <label>Cargo<input name="cargo" value="<?= e($edit['cargo']) ?>"></label>
          <label>Status<input name="status" value="<?= e($edit['status']) ?>"></label>
          <label>ETA<input type="date" name="eta" value="<?= e((string) $edit['eta']) ?>"></label>
          <label class="full"><input type="checkbox" name="halted" <?= (int) $edit['halted'] === 1 ? 'checked' : '' ?> style="width:auto"> Pause this shipment</label>
          <label class="full">Reason the customer will see<textarea name="halt_reason"><?= e($edit['halt_reason']) ?></textarea></label>
          <label class="full">Internal notes<textarea name="notes"><?= e((string) ($edit['notes'] ?? '')) ?></textarea></label>
          <div class="full"><button class="btn btn-navy" type="submit">Save shipment</button></div>
        </div>
      </form>
      <div id="map"></div>
      <?php if ((int) $edit['id'] > 0): ?>
        <h2>Transit events</h2>
        <form class="form-card" method="post" enctype="multipart/form-data">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="event">
          <input type="hidden" name="shipment_id" value="<?= (int) $edit['id'] ?>">
          <div class="form-grid">
            <label>Time<input type="datetime-local" name="event_time" required></label>
            <label>Location<input name="location" required></label>
            <label>Lat<input name="lat"></label>
            <label>Lng<input name="lng"></label>
            <label class="full">What the customer should read<textarea name="detail" required></textarea></label>
            <label class="full">Photo of this stop<input type="file" name="photo" accept="image/jpeg,image/png,image/webp"></label>
            <div class="full"><button class="btn btn-navy" type="submit">Add passed point</button></div>
          </div>
        </form>
        <div class="table-wrap"><table><thead><tr><th>Time</th><th>Place</th><th>Detail</th><th></th></tr></thead><tbody>
          <?php foreach ($events as $event): ?>
            <tr><td><?= e($event['event_time']) ?></td><td><?= e($event['location']) ?></td><td><?= e($event['detail']) ?></td>
            <td><form method="post"><?= '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">' ?><input type="hidden" name="action" value="delete_event"><input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>"><input type="hidden" name="shipment_id" value="<?= (int) $edit['id'] ?>"><button class="btn btn-danger" type="submit">Delete</button></form></td></tr>
          <?php endforeach; ?>
        </tbody></table></div>
      <?php endif; ?>
      <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
      <script>
        function fill(sel, nameId, latId, lngId) {
          const opt = sel.selectedOptions[0];
          document.getElementById(nameId).value = opt.textContent;
          document.getElementById(latId).value = opt.dataset.lat;
          document.getElementById(lngId).value = opt.dataset.lng;
          draw();
        }
        document.getElementById('origin-place').addEventListener('change', function () { fill(this, 'origin', 'origin_lat', 'origin_lng'); });
        document.getElementById('dest-place').addEventListener('change', function () { fill(this, 'destination', 'dest_lat', 'dest_lng'); });
        document.getElementById('current-place').addEventListener('change', function () { fill(this, 'current_label', 'current_lat', 'current_lng'); });
        const map = L.map('map');
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 10, attribution: '&copy; OpenStreetMap' }).addTo(map);
        let layer = L.layerGroup().addTo(map);
        function draw() {
          layer.clearLayers();
          const o = [parseFloat(document.getElementById('origin_lat').value), parseFloat(document.getElementById('origin_lng').value)];
          const c = [parseFloat(document.getElementById('current_lat').value), parseFloat(document.getElementById('current_lng').value)];
          const d = [parseFloat(document.getElementById('dest_lat').value), parseFloat(document.getElementById('dest_lng').value)];
          const line = L.polyline([o, c, d], { color: '#a97832' }).addTo(layer);
          L.marker(o).addTo(layer).bindPopup('Departure');
          L.marker(c).addTo(layer).bindPopup('Current');
          L.marker(d).addTo(layer).bindPopup('Destination');
          map.fitBounds(line.getBounds(), { padding: [24, 24] });
        }
        draw();
      </script>
    <?php elseif ($view === 'shipments'): ?>
      <div class="table-wrap"><table><thead><tr><th>Reference</th><th>Lane</th><th>Status</th><th>Paused</th><th></th></tr></thead><tbody>
        <?php foreach ($db->query('SELECT * FROM shipments ORDER BY id DESC') as $row): ?>
          <tr><td><?= e($row['reference']) ?></td><td><?= e($row['origin']) ?> → <?= e($row['destination']) ?></td><td><?= e($row['status']) ?></td><td><?= (int) $row['halted'] === 1 ? 'Yes' : 'No' ?></td><td><a href="index.php?edit=<?= (int) $row['id'] ?>">Edit</a> · <a href="../track.php?ref=<?= e($row['reference']) ?>">Public map</a></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
    <?php else: ?>
      <?php
        $sql = ['quotes' => 'SELECT * FROM quotes ORDER BY id DESC LIMIT 100', 'bookings' => 'SELECT * FROM bookings ORDER BY id DESC LIMIT 100', 'messages' => 'SELECT * FROM messages ORDER BY id DESC LIMIT 100'][$view] ?? '';
        $rows = $sql ? $db->query($sql)->fetchAll() : [];
      ?>
      <div class="table-wrap"><table>
        <?php if ($rows): ?>
          <thead><tr><?php foreach (array_keys($rows[0]) as $col): ?><th><?= e($col) ?></th><?php endforeach; ?></tr></thead>
          <tbody><?php foreach ($rows as $row): ?><tr><?php foreach ($row as $val): ?><td><?= e((string) $val) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
        <?php else: ?><tr><td>No rows yet.</td></tr><?php endif; ?>
      </table></div>
    <?php endif; ?>
  <?php endif; ?>
</div></main>
</body></html>
