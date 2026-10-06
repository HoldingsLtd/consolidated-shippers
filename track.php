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
$held = $shipment && (int) $shipment['halted'] === 1;
$points = [];
if ($shipment) {
    $points[] = ['label' => $shipment['origin'], 'date' => 'Departure', 'lat' => (float) $shipment['origin_lat'], 'lng' => (float) $shipment['origin_lng'], 'kind' => 'passed'];
    foreach ($events as $event) {
        if ($event['lat'] !== null && $event['lng'] !== null) {
            $points[] = ['label' => $event['location'], 'date' => date('M j, Y', strtotime($event['event_time'])), 'lat' => (float) $event['lat'], 'lng' => (float) $event['lng'], 'kind' => (($event['badge'] ?? '') === 'On Hold' || $held) ? 'hold' : 'passed'];
        }
    }
    $points[] = ['label' => $shipment['current_label'], 'date' => $held ? 'On hold' : 'Current', 'lat' => (float) $shipment['current_lat'], 'lng' => (float) $shipment['current_lng'], 'kind' => $held ? 'hold' : 'moving'];
    $points[] = ['label' => $shipment['destination'], 'date' => $shipment['eta'] ? date('M j, Y', strtotime($shipment['eta'])) : 'Destination', 'lat' => (float) $shipment['dest_lat'], 'lng' => (float) $shipment['dest_lng'], 'kind' => 'end'];
}
?>
<section class="page-hero"><div class="wrap">
  <div class="kicker">Shipment file</div>
  <h1>Track your shipment</h1>
  <p class="lead">Enter the reference from the booking note.</p>
</div></section>
<section class="section" style="padding-top:8px"><div class="wrap">
  <?php if ($error): ?><div class="flash err"><?= e($error) ?></div><?php endif; ?>
  <form class="inline-form" action="track.php" method="get" style="max-width:560px">
    <input name="ref" value="<?= e($ref) ?>" placeholder="Tracking number">
    <button class="btn btn-navy" type="submit">Look up</button>
  </form>
  <?php if ($ref !== '' && !$shipment && !$error): ?><div class="note">No file under <?= e($ref) ?>.</div><?php endif; ?>
  <?php if ($shipment): ?>
    <article class="track-card">
      <div class="track-top">
        <div><span>Tracking number</span><strong><?= e($shipment['reference']) ?></strong><em class="<?= $held ? 'hold' : 'moving' ?>"><?= e($shipment['status']) ?></em></div>
        <div><span>Origin</span><strong><?= e($shipment['origin']) ?></strong><small><?= e($shipment['shipper']) ?></small></div>
        <div class="lane">------></div>
        <div><span>Destination</span><strong><?= e($shipment['destination']) ?></strong><small><?= e($shipment['consignee']) ?></small></div>
        <div><span>Estimated delivery</span><strong><?= e($shipment['eta'] ?: 'To be advised') ?></strong><small><?= e($shipment['mode']) ?></small></div>
      </div>
      <div id="map"></div>
      <div class="history-head"><h2>Travel history</h2></div>
      <ol class="history">
        <li>
          <b>1</b>
          <div><span>Departure</span><strong>Shipment picked up</strong><small><?= e($shipment['origin']) ?></small><p>Shipment left the origin desk.</p></div>
          <em>Departed</em>
        </li>
        <?php foreach ($events as $i => $event): ?>
          <li class="<?= (($event['badge'] ?? '') === 'On Hold') ? 'is-hold' : '' ?>">
            <b><?= $i + 2 ?></b>
            <div>
              <span><?= e($event['event_time']) ?></span>
              <strong><?= e($event['title'] ?? $event['location']) ?></strong>
              <small><?= e($event['location']) ?></small>
              <p><?= e($event['detail']) ?></p>
              <?php if (($event['badge'] ?? '') === 'On Hold' && $shipment['halt_reason']): ?><p class="reason">Reason: <?= e($shipment['halt_reason']) ?></p><?php endif; ?>
              <?php if (!empty($event['image'])): ?><img class="stop-photo" src="<?= e($event['image']) ?>" alt=""><?php endif; ?>
            </div>
            <em><?= e($event['badge'] ?? 'Update') ?></em>
          </li>
        <?php endforeach; ?>
        <li class="<?= $held ? 'is-hold' : 'is-live' ?>">
          <b><?= count($events) + 2 ?></b>
          <div><span>Current</span><strong><?= $held ? 'On hold' : 'In transit' ?></strong><small><?= e($shipment['current_label']) ?></small><?php if ($held): ?><p class="reason">Reason: <?= e($shipment['halt_reason'] ?: 'Paused by the desk.') ?></p><?php endif; ?></div>
          <em><?= $held ? 'On Hold' : 'In transit' ?></em>
        </li>
      </ol>
    </article>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
      const points = <?= json_encode($points) ?>;
      const map = L.map('map');
      L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', { maxZoom: 12, attribution: '&copy; OpenStreetMap &copy; CARTO' }).addTo(map);
      const latlngs = points.map(p => [p.lat, p.lng]);
      const line = L.polyline(latlngs, { color: '#2f6fed', weight: 4 }).addTo(map);
      points.forEach(p => {
        const held = p.kind === 'hold';
        const moving = p.kind === 'moving';
        const icon = L.divIcon({
          className: 'pin ' + p.kind,
          html: '<span></span><strong>' + p.label + '<small>' + p.date + '</small></strong>',
          iconSize: [150, 42],
          iconAnchor: [12, 34]
        });
        L.marker([p.lat, p.lng], { icon }).addTo(map);
      });
      map.fitBounds(line.getBounds(), { padding: [40, 40] });
    </script>
  <?php endif; ?>
</div></section>
<style>
.track-card { margin-top: 18px; background: #fff; border: 1px solid #e6e0d4; border-radius: 16px; overflow: hidden; }
.track-top { display: grid; grid-template-columns: 1.2fr 1fr auto 1fr 1fr; gap: 12px; padding: 16px; }
.track-top span, .history small, .history span { display: block; color: #6d6456; font-size: 12px; }
.track-top strong { display: block; font-size: 18px; }
.track-top em { display: inline-block; margin-top: 6px; border-radius: 999px; padding: 3px 8px; font-style: normal; font-size: 12px; }
.track-top em.moving { background: #e5f6ea; color: #1f7a3a; }
.track-top em.hold { background: #fde8e6; color: #9d2c2c; }
#map { height: 420px; }
.history-head { padding: 8px 16px; }
.history { list-style: none; margin: 0; padding: 0 16px 16px; }
.history li { display: grid; grid-template-columns: 36px 1fr auto; gap: 12px; padding: 12px 0; border-top: 1px solid #eee6d8; }
.history b { width: 28px; height: 28px; border-radius: 50%; background: #2f9e62; color: #fff; display: grid; place-items: center; }
.history .is-hold b, .pin.hold span { background: #c4473a; }
.history .is-live b, .pin.moving span { background: #2f9e62; animation: pinblink 1s steps(2, end) infinite; }
.history em { border-radius: 999px; background: #e5f6ea; color: #1f7a3a; padding: 4px 8px; font-style: normal; height: fit-content; }
.history .is-hold em { background: #fde8e6; color: #9d2c2c; }
.reason { background: #fdecec; color: #9d2c2c; border-radius: 8px; padding: 8px; }
.stop-photo { width: 320px; height: 200px; max-width: 100%; object-fit: cover; border-radius: 12px; }
.pin { background: none; border: 0; }
.pin span { width: 16px; height: 16px; border-radius: 50%; background: #2f9e62; display: block; border: 2px solid #fff; }
.pin.end span { background: #10243b; }
.pin strong { display: block; background: #fff; border-radius: 8px; padding: 4px 6px; width: max-content; box-shadow: 0 4px 12px rgba(0,0,0,.12); font-size: 12px; }
.pin small { display: block; color: #6d6456; }
@keyframes pinblink { 50% { opacity: .3; } }
@media (max-width: 800px) { .track-top { grid-template-columns: 1fr 1fr; } .lane { display: none; } }
</style>
<?php require __DIR__ . '/includes/footer.php'; ?>
