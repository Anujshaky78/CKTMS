<?php
declare(strict_types=1);
// Business rules shared by every trip path (PRD plan, auto-create, manual trip, trip edit).

function tms_trip_max_pkt(): int { return max(1, (int)(app_cfg('trip.max_pkt') ?? 300)); }
function tms_trip_max_stores(): int { return max(1, (int)(app_cfg('trip.max_stores') ?? 2)); }

/** Throws with a clear message when a trip would break the limits. */
function tms_trip_check(int $stores, int $pkt, string $label = 'Trip'): void {
  $ms = tms_trip_max_stores(); $mp = tms_trip_max_pkt();
  if ($stores < 1) throw new Exception("$label me kam se kam 1 store chahiye.");
  if ($stores > $ms) throw new Exception("$label me $stores stores hain. Ek trip me maximum $ms store" . ($ms > 1 ? 's' : '') . ' allowed hain.');
  if ($pkt > $mp) throw new Exception("$label me $pkt PKT hai. Ek trip me maximum $mp PKT allowed hai.");
}
