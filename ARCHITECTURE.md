# Citykart TMS · structure (v6)

## Folder layout
```
app/
  bootstrap.php            Har page ka ek entry: session, config, helpers, DB, auth, rules, layout
  lib/helpers.php          e(), money(), redirect(), flash, csrf, json_out(), app_cfg(), asset()
  lib/database.php         PDO connection (config/database.php) + migrations
  lib/auth.php             require_login()
  lib/rules.php            Trip limits: tms_trip_max_pkt(), tms_trip_max_stores(), tms_trip_check()
  services/prd_planner.php PRD board, vehicle planning, selection → trips (300 PKT / 2 store split)
  services/oracle_sync.php Oracle SELECT-only sync: PRD Pending + Store Master
  services/trip_service.php distance, route order, insert_trip_compat(), prd_create_trip_tx() (single or joint pickup)
  services/dispatch_service.php vehicle placement flow (request/confirm/dock/placed/failed/cancel), store holds
  controllers/prd_api.php        PRD JSON/CSV endpoints (note, preview, create trips, export)
  controllers/planning_actions.php  Planning POST actions (indent, trip, gate pass, status, sync)
  controllers/planning_data.php     Planning tab data
  views/layout.php         App shell: sidebar, top bar, Oracle status pill
  views/planning/*.php     prd_pending, trips, trip_detail, indents, gatepasses, _scripts
assets/css/app.css         Design system (poori app)
assets/css/planning.css    Planning module styles
assets/js/app.js           Sidebar, loader, planning tab switching
database/migrations.php    Schema migrations (sirf jab file badle tab chalti hain)
config/app.php             App name + TRIP RULES (max_pkt 300, max_stores 2)
config/database.php        PostgreSQL connection
config/oracle.php          Oracle settings (credentials → oracle.local.php)
config/prd_planning.php    Planner tuning (radius, fill %, overdue days, fallback vehicles)
pages/*.php                URLs same as before (INDEX, PLANNING, MASTER, REPORTS, …) plus
                           DISPATCH (dispatch desk), MASTER_OPS (transporters, docks, vehicle upload),
                           SCORECARD, PRINT_GATEPASS, PRINT_DOCK
```
Purane include paths (`config/DB.php`, `auth/auth.php`, `includes/layout.php`, `includes/prd_planner.php`,
`includes/oracle_sync.php`) chhote shims hain jo `app/bootstrap.php` load karte hain, isliye koi purana page nahi tootta.

## Server-specific files (update ZIP me kabhi nahi aate)
| File | Kya rakhna hai |
|---|---|
| `config/oracle.local.php` | Oracle user, password, connect, src_site_codes (copy `oracle.local.example.php`) |
| `config/database.local.php` | PostgreSQL host/port/name/user/password agar default (127.0.0.1 / citykart_dc / postgres) se alag ho |
| `config/app.local.php` | Rules override, jaise `['trip' => ['max_pkt' => 300, 'max_stores' => 2]]` |

## Trip rules (ek jagah, har raste par)
`config/app.php → trip.max_pkt = 300`, `trip.max_stores = 2`. Ye check hote hain:
PRD Pending → Review plan, one-click auto-create, manual trip, trip edit (save se pehle), aur server par
`prd_create_trip_tx()`. 300 se bada store FIFO (purane challans pehle) se kai trips me bantta hai;
bacha hissa paas ke store ke saath club ho sakta hai. Rule se pehle bani over-limit planned trips Trips tab par
warning ke saath dikhti hain.

## Kaam ka flow
Dashboard pipeline: PRD Pending → Planned → Assigned → Loaded → Gate Pass → In Transit → Delivered.

See WHATS_NEW_v6.md for the v6 feature map.
