<?php
declare(strict_types=1);
// Business rules shared by every trip path (PRD plan, auto-create, manual trip, trip edit).

function tms_trip_max_pkt(): int { return max(1, (int)(app_cfg('trip.max_pkt') ?? 300)); }
function tms_trip_max_stores(): int { return max(1, (int)(app_cfg('trip.max_stores') ?? 2)); }

/** Message to show a user for an exception; turns the database's one-active-trip-per-vehicle/driver rule into a plain message. */
function tms_friendly_error(Throwable $x): string {
  $m = $x->getMessage();
  if ($x instanceof PDOException && (string)($x->errorInfo[0] ?? '') === '23505') {
    if (strpos($m, 'uq_trips_active_vehicle') !== false) return 'Selected vehicle already assigned to another active Trip.';
    if (strpos($m, 'uq_trips_active_driver') !== false) return 'Selected driver already assigned to another active Trip.';
  }
  return $m;
}

/** Allowed trip status moves. Delivered and Cancelled are final. */
function tms_trip_transitions(): array {
  return [
    'Planned'           => ['Assigned', 'Cancelled'],
    'Assigned'          => ['Planned', 'Reached at source', 'Cancelled'],
    'Reached at source' => ['Loaded', 'Assigned', 'Cancelled'],
    'Loaded'            => ['Gate Pass Created', 'Cancelled'],
    'Gate Pass Created' => ['In Transit', 'Loaded'],
    'In Transit'        => ['Breakdown', 'Accident', 'Delivered'],
    'Breakdown'         => ['In Transit', 'Delivered'],
    'Accident'          => ['In Transit', 'Delivered'],
  ];
}
function tms_trip_can_move(string $from, string $to): bool {
  return $from === $to || in_array($to, tms_trip_transitions()[$from] ?? [], true);
}
/** Throws when a trip cannot move from $from to $to. Call it on a row read with FOR UPDATE. */
function tms_trip_require_move(string $from, string $to, string $code = ''): void {
  if (!tms_trip_can_move($from, $to)) throw new Exception('Trip' . ($code !== '' ? " $code" : '') . " is $from; $to par nahi ja sakta.");
}

/** Throws with a clear message when a trip would break the limits. */
function tms_trip_check(int $stores, int $pkt, string $label = 'Trip'): void {
  $ms = tms_trip_max_stores(); $mp = tms_trip_max_pkt();
  if ($stores < 1) throw new Exception("$label me kam se kam 1 store chahiye.");
  if ($stores > $ms) throw new Exception("$label me $stores stores hain. Ek trip me maximum $ms store" . ($ms > 1 ? 's' : '') . ' allowed hain.');
  $single = (int)(app_cfg('trip.single_min_pkt') ?? 400);
  if ($pkt > $mp && !($stores === 1 && $pkt >= $single)) throw new Exception("$label me $pkt PKT hai. Ek trip me maximum $mp PKT allowed hai (sirf $single+ PKT wala akela store is limit se bada trip le sakta hai).");
}
