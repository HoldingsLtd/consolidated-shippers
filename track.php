<?php
$pageTitle = 'Track a shipment';
require __DIR__ . '/includes/header.php';
if (!function_exists('cs_db') && function_exists('cf_db')) {
    function cs_db(): ?PDO { return cf_db(); }
}
$ref = strtoupper(trim($_GET['ref'] ?? ''));
$shipment = null;
$events = [];
$error = '';
if ($ref !== '') {
    $db = function_exists('cs_db') ? cs_db() : null;
    if (!$db) {
        $error = 'Tracking needs MySQL. Import database.sql and set includes/config.php.';
    } else {
        $stmt = $db->prepare('SELECT * FROM shipments WHERE reference = ?');
        $stmt->execute([$ref]);
        $shipment = $stmt->fetch();
        if ($shipment) {
            $ev = $db->prepare('SELECT * FROM shipment_events WHERE shipment_id = ? ORDER BY event_time ASC');
            $ev->execute([$shipment['id']]);
            $events = $ev->fetchAll();
        }
    }
}
$points = [];
$history = [];
if ($shipment) {
    $held = (int) $shipment['halted'] === 1;
    $points[] = ['label' => 'Departure: ' . $shipment['origin'], 'lat' => (float) $shipment['origin_lat'], 'lng' => (float) $shipment['origin_lng'], 'kind' => 'passed'];
    $history[] = ['time' => 'Departure', 'location' => $shipment['origin'], 'detail' => 'Shipment left the origin desk.', 'image' => ''];
    foreach ($events as $event) {
        $history[] = ['time' => $event['event_time'], 'location' => $event['location'], 'detail' => $event['detail'], 'image' => $event['image'] ?? ''];
        if ($event['lat'] !== null && $event['lng'] !== null) {
            $points[] = ['label' => $event['event_time'] . ' · ' . $event['location'], 'lat' => (float) $event['lat'], 'lng' => (float) $event['lng'], 'kind' => 'passed'];
        }
    }
    $points[] = ['label' => ($held ? 'On hold: ' : 'Current: ') . $shipment['current_label'], 'lat' => (float) $shipment['current_lat'], 'lng' => (float) $shipment['current_lng'], 'kind' => $held ? 'hold' : 'moving'];
    $history[] = ['time' => 'Current', 'location' => $shipment['current_label'], 'detail' => $held ? ($shipment['halt_reason'] ?: 'Paused by the desk.') : 'In transit at the last recorded position.', 'image' => ''];
    $points[] = ['label' => 'Destination: ' . $shipment['destination'], 'lat' => (float) $shipment['dest_lat'], 'lng' => (float) $shipment['dest_lng'], 'kind' => 'end'];
}
?>
<section class="page-hero"><div class="wrap">
  <div class="kicker">Shipment file</div>
  <h1>Track your shipment</h1>
  <p class="lead">The map keeps departure, every point the desk has logged, the current pin, and the destination.</p>
</div></section>
<section class="section" style="padding-top:8px"><div class="wrap">
  <?php if ($error): ?><div class="flash err"><?= e($error) ?></div><?php endif; ?>
  <form class="inline-form" action="track.php" method="get" style="max-width:560px">
    <input name="ref" value="<?= e($ref) ?>" placeholder="CSK-902089">
    <button class="btn btn-navy" type="submit">Look up</button>
  </form>
  <?php if ($ref !== '' && !$shipment && !$error): ?><div class="note">No file under <?= e($ref) ?>.</div><?php endif; ?>
  <?php if ($shipment): ?>
    <div class="panel pad" style="margin-top:18px">
      <div class="kicker"><?= e($shipment['reference']) ?></div>
      <h2><?= e($shipment['status']) ?></h2>
      <?php if ((int) $shipment['halted'] === 1): ?><div class="halt"><strong>On hold.</strong> <?= e($shipment['halt_reason'] ?: 'The desk paused this shipment.') ?></div><?php endif; ?>
      <div class="grid-2">
        <p><strong>Sender</strong><br><?= e($shipment['shipper']) ?><br><?= e($shipment['origin']) ?></p>
        <p><strong>Receiver</strong><br><?= e($shipment['consignee']) ?><br><?= e($shipment['destination']) ?></p>
      </div>
      <p><?= e($shipment['mode']) ?> · <?= e($shipment['cargo']) ?><?php if ($shipment['eta']): ?> · ETA <?= e($shipment['eta']) ?><?php endif; ?></p>
      <div id="map"></div>
      <h3>Travel history</h3>
      <div class="timeline">
        <?php foreach ($history as $stop): ?>
          <article>
            <strong><?= e($stop['time']) ?> · <?= e($stop['location']) ?></strong>
            <div><?= e($stop['detail']) ?></div>
            <?php if ($stop['image'] !== ''): ?><img class="stop-photo" src="<?= e($stop['image']) ?>" alt="Stop photo"><?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    </div>
    <style>
      .stop-photo { width: 320px; height: 200px; max-width: 100%; object-fit: cover; border-radius: 12px; margin-top: 8px; display: block; background: #efe6d6; }
      .blink-pin { animation: pinblink 1s steps(2, end) infinite; }
      @keyframes pinblink { 50% { opacity: 0.25; } }
    </style>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
      const points = <?= json_encode($points) ?>;
      const map = L.map('map');
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 12, attribution: '&copy; OpenStreetMap' }).addTo(map);
      const latlngs = points.map(p => [p.lat, p.lng]);
      const line = L.polyline(latlngs, { color: '#a97832', weight: 4 }).addTo(map);
      points.forEach(p => {
        const moving = p.kind === 'moving';
        const held = p.kind === 'hold';
        const marker = L.circleMarker([p.lat, p.lng], {
          radius: moving || held ? 11 : 7,
          color: moving || held ? '#8d2f2f' : '#1f6b4a',
          fillColor: p.kind === 'end' ? '#10243b' : (moving || held ? '#c4473a' : '#2f9e62'),
          fillOpacity: 0.95,
          className: moving ? 'blink-pin' : ''
        }).addTo(map);
        marker.bindPopup(p.label);
      });
      map.fitBounds(line.getBounds(), { padding: [30, 30] });
    </script>
  <?php endif; ?>
</div></section>
<?php require __DIR__ . '/includes/footer.php'; ?>
