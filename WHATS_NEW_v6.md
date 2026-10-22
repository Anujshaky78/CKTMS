# v6 · What's new (13 requirements)

| # | Requirement | Where | Notes |
|---|---|---|---|
| 1 | Both warehouses' pending, individually or together, for FTL/PTL advice | PRD Pending → **Warehouse view**: *Both, separately* · *Both combined (joint)* · *<DC> only* | Joint view = one row per store with PKT from each DC; FTL/PTL is suggested on the combined load. |
| 2 | One joint vehicle from both warehouses, or individual trips | PRD Pending → Review plan → **Individual trips / Joint vehicle** | Joint trips record pickups in order (e.g. KUNDLI 140 → RAI 120). Same 300 PKT / 2 store limit. Each source must exist in Center DC master (dc_code = Oracle site code). |
| 3 | Gate pass print with all details and copy count | Gate Pass register → **Print** (copies 1–10) · `PRINT_GATEPASS.php` | Copies labelled Original·Security, Duplicate·Transporter, Triplicate·Store, Quadruplicate·Office. Vehicle, dock, transporter, driver, invoice, seal, pickups, stores, challans, signatures. |
| 4 | Dashboard: total vehicles required | Dashboard → **Vehicles required** | Planned trips without a vehicle (by type) + PRD Pending suggested loads (by type). |
| 5 | Stores to be dispatched, cluster-wise | Dashboard → **Stores to be dispatched** → *Total / By cluster* | Each store counted once even if two DCs report different clusters. |
| 6 | Vehicle master upload; transporter dropdown at loading | Masters → **Transporters & docks** → *Vehicle master upload* (CSV + template); Trip **Accept/Assign** uses the Transporter master | Upload updates by vehicle number, creates new transporters, reports skipped rows with line numbers. |
| 7 | Stores waiting > 3 days highlighted; plan or remove | PRD Pending: red rows, banner with **Select all due / Show only due**; **Hold store…** removes it from planning | `due_days` in config/prd_planning.php (default 3). Due stores seed the plan first. |
| 8 | Hold reason / remarks for held stores | PRD Pending → **Hold store…** (reason list, remarks, optional hold-until date) → **Release hold** | Held stores leave all recommendations, vehicle counts and trip creation (server-checked). |
| 9 | End-of-shift status on dashboard | Dashboard → **End-of-shift status** (pick a date, **Print shift report**) | Planned, requested, confirmed, placed, **failed vehicles**, loaded, gate passes, dispatched, delivered, **cancelled trips**, plus carry-over and lists. |
| 10 | Transporter scorecard: requirement vs placement, TD, MTD | Control → **Transporter scorecard** | Required, confirmed, placed, failed, placement %, avg confirm / placement time, trips & PKT dispatched, failure reasons, MTD daily trend. Requests closed by a trip cancel are not held against the transporter. |
| 11 | Vehicle number against the trip once confirmed | Planning → **Dispatch desk** → *Save vehicle* | Unknown numbers are added to Vehicle master under that transporter; a vehicle already on another active trip is refused. |
| 12 | Dock allocation for confirmed vehicles | Dispatch desk → **Allot dock**; docks in Masters → Transporters & docks | Dock must belong to the loading DC; a busy dock is refused unless “allot even if busy”. |
| 13 | Dock-wise print | Dispatch desk → **Print dock plan** · `PRINT_DOCK.php` | One page per dock (vehicle, transporter, trip, stores in order, PKT, tick column) or a supervisor summary. |

## Dispatch desk flow
Needs vehicle → **Request** (transporter + type) → **Save vehicle** (number, driver) → **Allot dock** → **Vehicle reported at dock** → open trip to Load → Gate pass → Dispatch.
At any open step: **Vehicle failed?** (reason) puts the trip back to “Needs vehicle”; **Cancel trip** (reason) releases PRD challans to PRD Pending and manual indents back to Planned.

## New database objects (created automatically)
`tms_transporters`, `tms_docks`, `tms_vehicle_placements`, `tms_trip_pickups`, `prd_store_holds`; columns on `trips` (vehicle_type, transporter_id, placement_id, dock_id, is_joint, cancelled_at, cancel_reason) and `vehicles.transporter_id`. Existing transporter names typed on trips/drivers are copied into the Transporter master once.

## First-day setup
1. Masters → Transporters & docks: check the transporter list, add docks per warehouse (“Add several docks” creates D01…Dnn).
2. Upload the vehicle master CSV (download the template on the same page).
3. Center DC master must have both warehouses with `dc_code` = Oracle site code (123, 456) for joint trips.
4. Check vehicle capacities in the Vehicle master: the planner uses the largest capacity per vehicle type. A wrong value (e.g. a 19FT entered as 325 PKT) makes that type look as big as a 32FT.

## v6.1 fixes
- **Fatal error `column tp.name does not exist`** (Dashboard, Scorecard, Dispatch desk, Gate pass print): the server already had an older
  `tms_transporters` table with different columns. The migration now keeps that table and its data, adds the columns v6 needs,
  fills `name` / `phone` / `active` from the old columns (e.g. transporter_name, mobile, status) and relaxes NOT NULL on old columns
  the TMS does not fill. New or edited transporters are written back to the old name column too.
- **Gate pass print**: no longer depends on a `users.name` column (uses full_name / name / username, whichever exists).
- **Site 420 not on PRD Pending**: PRD loads only the Oracle sites in `src_site_codes`. New screen **Masters → Transporters & docks →
  Warehouses (Oracle sites)**: add a site to the sync from the UI, **Search Oracle** for every site that has pending challans, and link each
  site to its Center DC (fixes “Source not in DC master”, needed for joint trips and docks).

## v6.2 · PRD Pending redesign + report-accurate data
**Data now follows the PRD Oracle report** (store × source × APPS/GM):
- PKT = distinct challans **per division class** (APPS = MENS/KIDS/LADIES, GM = the rest). A challan with APPS and GM items is one APPS PKT and one GM PKT, as in the report's `COUNT(DISTINCT CASE … THEN CHALLAN_NO END)` columns.
- Lines whose item is missing in MAS$ITEM, whose division is NON-TRADING / FIXED ASSETS, or whose section is RT_CONSUMABLES are excluded (same as the report's WHERE).
- No date limit by default (`days_back` = 0), like the report.
- Item master is de-duplicated per ICODE first, so a repeated ICODE cannot double a line (the raw report join would).
- Filters live in config/oracle.php: `apps_divisions`, `exclude_divisions`, `exclude_sections`.
- One `prd_pending` row per challan per division (unique key now includes sub_div). Run **Refresh from Oracle** once after installing.

**New PRD Pending screen**
- Summary cards per warehouse + combined (APPS / GM PKT and value) + “Needs attention”.
- Matrix: Destination | per warehouse APPS PKT, APPS Val, GM PKT, GM Val | Combined PKT, Value | Waiting | Suggestion; sticky **Grand total** row. Sortable columns; “PKT only” toggle hides value columns.
- Warehouse filter (All / each site), suggestions **Per warehouse** or **Joint**.
- Click a store name for detail: site-wise breakdown, suggestion reasons, flags, hold / release, planner note, and its challans (loaded on demand, so the page no longer carries every challan).
- **Export CSV** = the same matrix with GRAND TOTAL row.
- Stores waiting more than `due_days` (3) are always suggested “Dispatch now”; `critical_days` aligned to 3.

## v6.3 · Report query as the source + cleaner tabs
- **Data follows the PRD report query exactly**: per store and warehouse APPS PKT/VAL, GM PKT/VAL, **TOTAL PKT/VAL** (TOTAL PKT = distinct challans, so a challan with APPS and GM items counts once in TOTAL and once in each division), combined TOTAL APPS / GM, GRAND TOTAL.
- **LAST_DISP_DATE** = latest `INVOICE_DATE` in CUBE$SALDC (cube 15) for the store, pulled with every sync. "Days since dispatch" and the > 3 days highlight now use it. TMS trip dates are used only if Oracle has no invoice date.
- Vehicle planning uses TOTAL PKT (distinct challans) against the 300 PKT / 2 store limit.
- **PRD Pending screen**: Destination · State · Store status · Cluster · Last dispatch · **Dispatch suggestion** · per warehouse APPS PKT/Val, GM PKT/Val, Total PKT/Val · Total. One short suggestion per store (e.g. “Dispatch · 24FT · 92%”, “Dispatch now · 14FT · 40%”, “Club with BRB · 24FT”, “Wait · 35% fill”). Suggestions default to **Joint vehicle**; switch to **Per warehouse** any time.
- **Export** gives the same columns as the report (DESTINATION_NAME … TOTAL_GM_VAL + TOTAL, days, suggestion) with GRAND TOTAL.
- **All tabs trimmed**: no explanatory sub-lines or duplicate headings in Trips, Indents, Gate Pass, Trip Status; Dispatch desk summary is one line; Dashboard keeps only the pipeline, vehicles required, stores to dispatch, end-of-shift and needs-action.
- Trip Status shows “—” instead of a false distance when a store or DC has no coordinates.

## v6.4 · Same look on every tab + no DC choice in Review plan
- **Review plan no longer asks for a Center DC.** The loading DC comes from the site's link (Masters → Transporters & docks → Warehouses). Joint trips load first at the warehouse with the most PKT. If a site is not linked, the trip shows “Site not linked to a Center DC” and cannot be created until it is linked.
- **Every tab now matches PRD Pending**: one stats strip at the top (Trips by status + PKT not yet dispatched; Indents by status; Gate pass waiting / created today; Trip Status by status; Stores / Vehicles / Drivers / DCs counts; Dispatch desk steps; Reports KPIs), then one action row, then one compact table with a sticky header. No duplicate headings, no explanatory sub-lines, no box around a table.
- Trips and Indents: the “Trip Builder” / “Indent Management” cards are now just their buttons; Add Trip / Bulk Upload open in their own panel.
- Removed outdated text (“Manual Trip me 4 stores tak”).
