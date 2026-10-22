<?php
declare(strict_types=1);
/*
 * Database migrations (PostgreSQL). Safe, idempotent statements; each runs on its own so one
 * failing legacy statement never blocks the rest.
 * tms_migrate() runs them only when this file changes (hash stored in tms_meta), instead of on
 * every request as before.
 */

function ensure_schema(PDO $d): void {
    /*
     * Safe compatibility migration for the existing citykart_dc database.
     * Each statement runs independently so one failed legacy constraint does
     * not abort all subsequent migrations.
     */
    $sql = [
        "CREATE TABLE IF NOT EXISTS users (id SERIAL PRIMARY KEY, name VARCHAR(100), username VARCHAR(80), full_name VARCHAR(120), email VARCHAR(150), password VARCHAR(255) NOT NULL, role VARCHAR(30) DEFAULT 'dispatcher', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS name VARCHAR(100)",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS username VARCHAR(80)",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS full_name VARCHAR(120)",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS email VARCHAR(150)",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS role VARCHAR(30) DEFAULT 'dispatcher'",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",

        "CREATE TABLE IF NOT EXISTS stores (id SERIAL PRIMARY KEY, store_code VARCHAR(30) UNIQUE NOT NULL, store_name VARCHAR(120) NOT NULL, latitude NUMERIC(10,7) NOT NULL DEFAULT 0, longitude NUMERIC(10,7) NOT NULL DEFAULT 0, dispatch_days VARCHAR(100) DEFAULT 'Mon,Tue,Wed,Thu,Fri,Sat,Sun', active BOOLEAN DEFAULT TRUE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
        "ALTER TABLE stores ADD COLUMN IF NOT EXISTS store_code VARCHAR(30)",
        "ALTER TABLE stores ADD COLUMN IF NOT EXISTS store_name VARCHAR(120)",
        "ALTER TABLE stores ADD COLUMN IF NOT EXISTS latitude NUMERIC(10,7) DEFAULT 0",
        "ALTER TABLE stores ADD COLUMN IF NOT EXISTS longitude NUMERIC(10,7) DEFAULT 0",
        "ALTER TABLE stores ADD COLUMN IF NOT EXISTS dispatch_days VARCHAR(100) DEFAULT 'Mon,Tue,Wed,Thu,Fri,Sat,Sun'",
        "ALTER TABLE stores ADD COLUMN IF NOT EXISTS transit_days NUMERIC(6,2) DEFAULT 1",
        "ALTER TABLE stores ADD COLUMN IF NOT EXISTS city VARCHAR(100)",
        "ALTER TABLE stores ADD COLUMN IF NOT EXISTS state VARCHAR(100)",
        "ALTER TABLE stores ADD COLUMN IF NOT EXISTS address TEXT",
        "ALTER TABLE stores ADD COLUMN IF NOT EXISTS pincode VARCHAR(10)",
        "ALTER TABLE route_stops ADD COLUMN IF NOT EXISTS expected_delivery_at TIMESTAMP NULL",
        "ALTER TABLE route_stops ADD COLUMN IF NOT EXISTS delivered_at TIMESTAMP NULL",
        "ALTER TABLE route_stops ADD COLUMN IF NOT EXISTS actual_tat_hours NUMERIC(10,2) DEFAULT 0",
        "ALTER TABLE route_stops ADD COLUMN IF NOT EXISTS delay_minutes INT DEFAULT 0",
        "ALTER TABLE stores ADD COLUMN IF NOT EXISTS active BOOLEAN DEFAULT TRUE",
        "ALTER TABLE stores ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
        "ALTER TABLE stores DROP CONSTRAINT IF EXISTS stores_dispatch_days_check",
        "CREATE TABLE IF NOT EXISTS vehicles (id SERIAL PRIMARY KEY, vehicle_number VARCHAR(30) UNIQUE NOT NULL, vehicle_type VARCHAR(60), capacity_boxes INT DEFAULT 300, value_capacity NUMERIC(14,2) DEFAULT 2500000, status VARCHAR(30) DEFAULT 'Available', active BOOLEAN DEFAULT TRUE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
        "ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS vehicle_number VARCHAR(30)",
        "ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS vehicle_type VARCHAR(60)",
        "ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS capacity_boxes INT DEFAULT 300",
        "ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS value_capacity NUMERIC(14,2) DEFAULT 2500000",
        "ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS status VARCHAR(30) DEFAULT 'Available'",
        "ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS active BOOLEAN DEFAULT TRUE",
        "ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
        "ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS current_lat NUMERIC(10,7) DEFAULT 0",
        "ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS current_lon NUMERIC(10,7) DEFAULT 0",
        "ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS avg_speed_kmh NUMERIC(6,2) DEFAULT 40",
        "ALTER TABLE vehicles ALTER COLUMN type DROP NOT NULL",

        "CREATE TABLE IF NOT EXISTS drivers (id SERIAL PRIMARY KEY, driver_code VARCHAR(30), driver_name VARCHAR(120), phone VARCHAR(30), license_no VARCHAR(60), status VARCHAR(30) DEFAULT 'Available', active BOOLEAN DEFAULT TRUE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
        "ALTER TABLE drivers ADD COLUMN IF NOT EXISTS driver_code VARCHAR(30)",
        "ALTER TABLE drivers ADD COLUMN IF NOT EXISTS driver_name VARCHAR(120)",
        "ALTER TABLE drivers ADD COLUMN IF NOT EXISTS tpt_name VARCHAR(120)",
        "ALTER TABLE drivers ADD COLUMN IF NOT EXISTS phone VARCHAR(30)",
        "ALTER TABLE drivers ADD COLUMN IF NOT EXISTS license_no VARCHAR(60)",
        "ALTER TABLE drivers ADD COLUMN IF NOT EXISTS status VARCHAR(30) DEFAULT 'Available'",
        "ALTER TABLE drivers ADD COLUMN IF NOT EXISTS active BOOLEAN DEFAULT TRUE",
        "ALTER TABLE drivers ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
        "ALTER TABLE drivers ALTER COLUMN name DROP NOT NULL",

        "ALTER TABLE orders ADD COLUMN IF NOT EXISTS order_number VARCHAR(50)",
        "ALTER TABLE orders ADD COLUMN IF NOT EXISTS order_date DATE",
        "ALTER TABLE orders ADD COLUMN IF NOT EXISTS order_no VARCHAR(50)",
        "ALTER TABLE orders ADD COLUMN IF NOT EXISTS box_count INT",
        "ALTER TABLE orders ADD COLUMN IF NOT EXISTS order_value NUMERIC(14,2) DEFAULT 0",
        "ALTER TABLE orders ADD COLUMN IF NOT EXISTS dispatch_date DATE DEFAULT CURRENT_DATE",
        "ALTER TABLE orders ADD COLUMN IF NOT EXISTS plan_id INT",
        "ALTER TABLE orders ADD COLUMN IF NOT EXISTS status VARCHAR(30) DEFAULT 'Pending'",
        // Replace restrictive legacy status constraint with the complete TMS lifecycle.
        "ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_status_check",
        "UPDATE orders SET status='Pending' WHERE status IS NULL OR lower(trim(status)) NOT IN ('pending','planned','assigned','loaded','dispatched','delivered','cancelled','completed')",
        "ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (lower(trim(status)) IN ('pending','planned','assigned','loaded','dispatched','delivered','cancelled','completed'))",

        "CREATE TABLE IF NOT EXISTS dispatch_plans (id SERIAL PRIMARY KEY, plan_number VARCHAR(50) UNIQUE NOT NULL, plan_date DATE NOT NULL DEFAULT CURRENT_DATE, vehicle_id INT, driver_id INT, total_boxes INT NOT NULL DEFAULT 0, total_value NUMERIC(14,2) NOT NULL DEFAULT 0, store_count INT NOT NULL DEFAULT 0, status VARCHAR(30) NOT NULL DEFAULT 'Draft', created_by INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
        "ALTER TABLE dispatch_plans ADD COLUMN IF NOT EXISTS plan_no VARCHAR(50)",
        "ALTER TABLE dispatch_plans ADD COLUMN IF NOT EXISTS plan_number VARCHAR(50)",
        "ALTER TABLE dispatch_plans ADD COLUMN IF NOT EXISTS plan_date DATE DEFAULT CURRENT_DATE",
        "ALTER TABLE dispatch_plans ADD COLUMN IF NOT EXISTS vehicle_id INT",
        "ALTER TABLE dispatch_plans ADD COLUMN IF NOT EXISTS driver_id INT",
        "ALTER TABLE dispatch_plans ADD COLUMN IF NOT EXISTS total_boxes INT DEFAULT 0",
        "ALTER TABLE dispatch_plans ADD COLUMN IF NOT EXISTS planned_boxes INT DEFAULT 0",
        "ALTER TABLE dispatch_plans ADD COLUMN IF NOT EXISTS planned_value NUMERIC(14,2) DEFAULT 0",
        "ALTER TABLE dispatch_plans ADD COLUMN IF NOT EXISTS loaded_boxes INT DEFAULT 0",
        "ALTER TABLE dispatch_plans ADD COLUMN IF NOT EXISTS loaded_value NUMERIC(14,2) DEFAULT 0",
        "ALTER TABLE dispatch_plans ADD COLUMN IF NOT EXISTS total_value NUMERIC(14,2) DEFAULT 0",
        "ALTER TABLE dispatch_plans ADD COLUMN IF NOT EXISTS store_count INT DEFAULT 0",
        "ALTER TABLE dispatch_plans ADD COLUMN IF NOT EXISTS status VARCHAR(30) DEFAULT 'Draft'",
        "ALTER TABLE dispatch_plans ADD COLUMN IF NOT EXISTS created_by INT",
        "ALTER TABLE dispatch_plans ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
        "ALTER TABLE dispatch_plans ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
        "ALTER TABLE dispatch_plans ADD COLUMN IF NOT EXISTS departure_at TIMESTAMP NULL",
        "ALTER TABLE dispatch_plans ADD COLUMN IF NOT EXISTS avg_speed_kmh NUMERIC(6,2) DEFAULT 40",
        "ALTER TABLE dispatch_plans ADD COLUMN IF NOT EXISTS first_store_eta TIMESTAMP NULL",
        "ALTER TABLE dispatch_plans ADD COLUMN IF NOT EXISTS route_status VARCHAR(30) DEFAULT 'Planned'",
        "ALTER TABLE dispatch_plans ADD COLUMN IF NOT EXISTS source_store_id INT NULL",

        "CREATE TABLE IF NOT EXISTS plan_orders (plan_id INT NOT NULL, order_id INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, loaded_at TIMESTAMP NULL, PRIMARY KEY(plan_id, order_id))",
        "ALTER TABLE plan_orders ADD COLUMN IF NOT EXISTS loaded_at TIMESTAMP NULL",
        "CREATE INDEX IF NOT EXISTS idx_orders_pending_date ON orders(status, dispatch_date, plan_id)",
        "CREATE INDEX IF NOT EXISTS idx_plan_orders_order ON plan_orders(order_id)",
        "CREATE TABLE IF NOT EXISTS route_stops (id SERIAL PRIMARY KEY, plan_id INT NOT NULL REFERENCES dispatch_plans(id) ON DELETE CASCADE, sequence_no INT NOT NULL, store_id INT NOT NULL REFERENCES stores(id), status VARCHAR(30) DEFAULT 'Planned', eta_at TIMESTAMP NULL, arrived_at TIMESTAMP NULL, distance_from_prev_km NUMERIC(10,2) DEFAULT 0, UNIQUE(plan_id,sequence_no))",
        "CREATE TABLE IF NOT EXISTS plan_tracking (id SERIAL PRIMARY KEY, plan_id INT NOT NULL REFERENCES dispatch_plans(id) ON DELETE CASCADE, latitude NUMERIC(10,7) NOT NULL, longitude NUMERIC(10,7) NOT NULL, location_name VARCHAR(150), status_update VARCHAR(100), recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
        "ALTER TABLE route_stops ADD COLUMN IF NOT EXISTS distance_from_prev_km NUMERIC(10,2) DEFAULT 0",
        "CREATE INDEX IF NOT EXISTS idx_dispatch_plans_date ON dispatch_plans(plan_date, status)",
        "CREATE INDEX IF NOT EXISTS idx_route_stops_plan ON route_stops(plan_id, sequence_no)",
        "CREATE INDEX IF NOT EXISTS idx_plan_tracking_plan ON plan_tracking(plan_id, recorded_at)"
    ];

    foreach ($sql as $q) {
        try { $d->exec($q); } catch (Throwable $e) { /* preserve legacy data; continue migration */ }
    }

    // Backfill legacy/current order numbering so old delivered orders remain visible.
    $blocks = [
        "DO $$ BEGIN
            IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='orders' AND column_name='order_no') THEN
                UPDATE orders SET order_number = COALESCE(NULLIF(order_number,''), NULLIF(order_no,'')) WHERE order_number IS NULL OR order_number='';
            END IF;
            UPDATE orders SET order_number = 'ORD-'||to_char(COALESCE(created_at,CURRENT_TIMESTAMP),'YYMMDD')||'-'||id WHERE order_number IS NULL OR order_number='';
            IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='orders' AND column_name='order_no') THEN
                UPDATE orders SET order_no = COALESCE(NULLIF(order_no,''), order_number) WHERE order_no IS NULL OR order_no='';
            END IF;
        END $$",

        "DO $$ BEGIN
            UPDATE dispatch_plans SET plan_number = COALESCE(NULLIF(plan_number,''), plan_no) WHERE plan_number IS NULL OR plan_number='';
            UPDATE dispatch_plans SET plan_no = COALESCE(NULLIF(plan_no,''), plan_number, 'DP-'||to_char(COALESCE(plan_date,CURRENT_DATE),'YYMMDD')||'-'||id) WHERE plan_no IS NULL OR plan_no='';
        END $$",
        "DO $$ BEGIN
            IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='vehicles' AND column_name='type') THEN
                UPDATE vehicles SET vehicle_type = COALESCE(NULLIF(vehicle_type,''), type::text) WHERE vehicle_type IS NULL OR vehicle_type='';
            END IF;
            UPDATE vehicles SET status='Available' WHERE status IS NULL OR TRIM(status)='';
            UPDATE vehicles SET active=TRUE WHERE active IS NULL;
        END $$",
        "DO $$ BEGIN
            IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='drivers' AND column_name='name') THEN
                UPDATE drivers SET driver_name = COALESCE(NULLIF(driver_name,''), name::text) WHERE driver_name IS NULL OR driver_name='';
            UPDATE drivers SET tpt_name = COALESCE(NULLIF(tpt_name,''), driver_name, 'TPT') WHERE tpt_name IS NULL OR TRIM(tpt_name)='';
            END IF;
            UPDATE drivers SET status='Available' WHERE status IS NULL OR TRIM(status)='';
            UPDATE drivers SET active=TRUE WHERE active IS NULL;
            UPDATE drivers SET driver_code='DRV-'||id WHERE driver_code IS NULL OR TRIM(driver_code)='';
        END $$",
        "ALTER TABLE orders ALTER COLUMN order_date SET DEFAULT CURRENT_DATE",
        "DO $$ BEGIN
            UPDATE orders SET box_count=GREATEST(COALESCE(item_count,1),1) WHERE box_count IS NULL OR box_count<=0;
            UPDATE orders SET order_value=0 WHERE order_value IS NULL;
            UPDATE orders SET order_date=COALESCE(order_date, created_at::date, CURRENT_DATE) WHERE order_date IS NULL;
            UPDATE orders SET dispatch_date=CURRENT_DATE WHERE dispatch_date IS NULL;
            UPDATE orders SET status='Pending' WHERE status IS NULL OR TRIM(status)='';
        EXCEPTION WHEN undefined_table THEN NULL; END $$",
        "DO $$ BEGIN
            UPDATE users SET full_name=COALESCE(NULLIF(full_name,''),name,username,'User') WHERE full_name IS NULL OR full_name='';
            UPDATE users SET name=COALESCE(NULLIF(name,''),full_name,username,'User') WHERE name IS NULL OR name='';
            UPDATE users SET username=COALESCE(NULLIF(username,''),split_part(email,'@',1),'user_'||id) WHERE username IS NULL OR username='';
        EXCEPTION WHEN undefined_table THEN NULL; END $$"
    ];
    foreach ($blocks as $q) {
        try { $d->exec($q); } catch (Throwable $e) { /* compatibility only */ }
    }
}

function tms_migrate_extra(PDO $d): void {
 foreach([
  "ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS gps_updated_at TIMESTAMP NULL",
  "ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS gps_speed_kmh NUMERIC(8,2) DEFAULT 0",
  "ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS gps_accuracy_m NUMERIC(10,2) DEFAULT 0",
  "ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS gps_status VARCHAR(30) DEFAULT 'Offline'",
  "ALTER TABLE route_stops ADD COLUMN IF NOT EXISTS expected_delivery_at TIMESTAMP NULL",
  "ALTER TABLE route_stops ADD COLUMN IF NOT EXISTS delivered_at TIMESTAMP NULL",
  "ALTER TABLE route_stops ADD COLUMN IF NOT EXISTS actual_tat_hours NUMERIC(10,2) DEFAULT 0",
  "ALTER TABLE route_stops ADD COLUMN IF NOT EXISTS delay_minutes INT DEFAULT 0",
  "ALTER TABLE plan_tracking ADD COLUMN IF NOT EXISTS speed_kmh NUMERIC(8,2) DEFAULT 0",
  "ALTER TABLE plan_tracking ADD COLUMN IF NOT EXISTS accuracy_m NUMERIC(10,2) DEFAULT 0",
  "ALTER TABLE plan_tracking ADD COLUMN IF NOT EXISTS source VARCHAR(30) DEFAULT 'manual'",
  "CREATE INDEX IF NOT EXISTS idx_vehicles_gps_updated ON vehicles(gps_updated_at)",
  "CREATE INDEX IF NOT EXISTS idx_plan_tracking_latest ON plan_tracking(plan_id, recorded_at DESC)"
 ] as $q){ try{$d->exec($q);}catch(Throwable $e){} }

 // New Planning / Trip Indent workflow. Legacy tables are preserved.
 foreach([
  "CREATE TABLE IF NOT EXISTS prd_pending (id BIGSERIAL PRIMARY KEY, source_name VARCHAR(150), destination_name VARCHAR(150), state VARCHAR(100), cluster_type VARCHAR(100), store_status VARCHAR(100), sub_div VARCHAR(30), challan_no VARCHAR(100), challan_barcode VARCHAR(120), challan_date DATE, qty NUMERIC(14,2) NOT NULL DEFAULT 0, value NUMERIC(16,2) NOT NULL DEFAULT 0, out_location VARCHAR(150), prepared_by VARCHAR(150), challan_status VARCHAR(100), age_days INT DEFAULT 0, last_dispatch_date DATE, source_system VARCHAR(20) DEFAULT 'CSV', source_key VARCHAR(180), synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE(challan_no, challan_barcode, destination_name))",
  "ALTER TABLE prd_pending ADD COLUMN IF NOT EXISTS source_site_code VARCHAR(60)",
  "UPDATE prd_pending SET source_site_code = CASE WHEN UPPER(COALESCE(source_system,''))='CSV' THEN 'CSV' ELSE 'LEGACY' END WHERE source_site_code IS NULL OR BTRIM(source_site_code)=''",
  "DO \$\$ DECLARE c RECORD; BEGIN FOR c IN SELECT conname FROM pg_constraint WHERE conrelid='prd_pending'::regclass AND contype='u' AND pg_get_constraintdef(oid) LIKE 'UNIQUE (challan_no, challan_barcode, destination_name)%' LOOP EXECUTE format('ALTER TABLE prd_pending DROP CONSTRAINT %I',c.conname); END LOOP; END \$\$",
  "ALTER TABLE prd_pending ALTER COLUMN source_site_code SET DEFAULT 'CSV'",
  "ALTER TABLE prd_pending ALTER COLUMN source_site_code SET NOT NULL",
  "DO \$\$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid='prd_pending'::regclass AND conname='prd_pending_dc_challan_unique') THEN ALTER TABLE prd_pending ADD CONSTRAINT prd_pending_dc_challan_unique UNIQUE (source_site_code,challan_no,challan_barcode,destination_name); END IF; END \$\$",
  "CREATE INDEX IF NOT EXISTS idx_prd_pending_source_site_challan ON prd_pending(source_site_code,challan_no)",
  "CREATE INDEX IF NOT EXISTS idx_prd_pending_dest ON prd_pending(destination_name,challan_date)",
  // PRD Pending planning board upgrade
  "ALTER TABLE prd_pending ADD COLUMN IF NOT EXISTS planned_trip_id INT NULL",
  "ALTER TABLE prd_pending ADD COLUMN IF NOT EXISTS planned_at TIMESTAMP NULL",
  "CREATE INDEX IF NOT EXISTS idx_prd_pending_planned ON prd_pending(planned_trip_id)",
  // Oracle store master + pending-only sync
  "ALTER TABLE prd_pending ADD COLUMN IF NOT EXISTS destination_site_code VARCHAR(60)",
  "CREATE INDEX IF NOT EXISTS idx_prd_pending_dest_site ON prd_pending(destination_site_code)",
  "ALTER TABLE stores ADD COLUMN IF NOT EXISTS oracle_site_code VARCHAR(60)",
  "ALTER TABLE stores ADD COLUMN IF NOT EXISTS cluster_type VARCHAR(100)",
  "ALTER TABLE stores ADD COLUMN IF NOT EXISTS store_status VARCHAR(100)",
  "ALTER TABLE stores ADD COLUMN IF NOT EXISTS oracle_synced_at TIMESTAMP NULL",
  "ALTER TABLE stores ALTER COLUMN store_code TYPE VARCHAR(60)",
  "ALTER TABLE stores ALTER COLUMN store_name TYPE VARCHAR(150)",
  "CREATE UNIQUE INDEX IF NOT EXISTS uq_stores_oracle_site ON stores(oracle_site_code) WHERE oracle_site_code IS NOT NULL",
  "CREATE TABLE IF NOT EXISTS tms_sync_log (id BIGSERIAL PRIMARY KEY, kind VARCHAR(20) NOT NULL, ok BOOLEAN NOT NULL, message TEXT, row_count INT, created_by INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
  "CREATE INDEX IF NOT EXISTS idx_tms_sync_log_kind ON tms_sync_log(kind,id DESC)",
  "CREATE TABLE IF NOT EXISTS prd_planner_notes (gkey VARCHAR(40) PRIMARY KEY, source_label VARCHAR(200), destination_name VARCHAR(150), note TEXT, updated_by INT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
  "CREATE TABLE IF NOT EXISTS tms_dc_master (id SERIAL PRIMARY KEY, dc_code VARCHAR(60) UNIQUE NOT NULL, dc_name VARCHAR(150) NOT NULL, latitude NUMERIC(10,7) DEFAULT 0, longitude NUMERIC(10,7) DEFAULT 0, active BOOLEAN NOT NULL DEFAULT TRUE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
  "CREATE TABLE IF NOT EXISTS tms_dc_master (id SERIAL PRIMARY KEY, dc_code VARCHAR(60) UNIQUE NOT NULL, dc_name VARCHAR(150) NOT NULL, latitude NUMERIC(10,7) DEFAULT 0, longitude NUMERIC(10,7) DEFAULT 0, active BOOLEAN NOT NULL DEFAULT TRUE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
  "DROP TRIGGER IF EXISTS aaa_tms_fill_trip_number ON trips",
  "DROP FUNCTION IF EXISTS tms_fill_trip_number()",
  "ALTER TABLE trips ADD COLUMN IF NOT EXISTS source_dc_id INT NULL REFERENCES tms_dc_master(id)",
  "CREATE TABLE IF NOT EXISTS tms_indents (id SERIAL PRIMARY KEY, indent_no VARCHAR(60) UNIQUE NOT NULL, indent_date DATE NOT NULL DEFAULT CURRENT_DATE, source_store_id INT NULL REFERENCES stores(id), source_dc_id INT NULL REFERENCES tms_dc_master(id), destination_store_id INT NOT NULL REFERENCES stores(id), packet_count INT NOT NULL DEFAULT 0, indent_value NUMERIC(14,2) NOT NULL DEFAULT 0, status VARCHAR(30) NOT NULL DEFAULT 'Planned', trip_id INT NULL REFERENCES trips(id) ON DELETE SET NULL, invoice_no VARCHAR(100), seal_no VARCHAR(100), source_ref VARCHAR(120), created_by INT NULL REFERENCES users(id), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
  "ALTER TABLE tms_indents ADD COLUMN IF NOT EXISTS source_dc_id INT NULL REFERENCES tms_dc_master(id)",
  "CREATE TABLE IF NOT EXISTS tms_trip_stops (id SERIAL PRIMARY KEY, trip_id INT NOT NULL REFERENCES trips(id) ON DELETE CASCADE, sequence_no INT NOT NULL, store_id INT NOT NULL REFERENCES stores(id), packet_count INT NOT NULL DEFAULT 0, stop_value NUMERIC(14,2) NOT NULL DEFAULT 0, status VARCHAR(30) NOT NULL DEFAULT 'Planned', delivered_at TIMESTAMP NULL, UNIQUE(trip_id,sequence_no))",
        "ALTER TABLE tms_trip_stops ADD COLUMN IF NOT EXISTS expected_delivery_at TIMESTAMP NULL",
        "ALTER TABLE tms_trip_stops ADD COLUMN IF NOT EXISTS delivery_remarks TEXT",
        "ALTER TABLE tms_trip_stops ADD COLUMN IF NOT EXISTS delivery_result VARCHAR(30)",
        "ALTER TABLE tms_trip_stops ADD COLUMN IF NOT EXISTS delivery_delay_days NUMERIC(8,2) DEFAULT 0",
  "CREATE TABLE IF NOT EXISTS tms_gate_passes (id SERIAL PRIMARY KEY, gate_pass_no VARCHAR(60) UNIQUE NOT NULL, trip_id INT NOT NULL UNIQUE REFERENCES trips(id) ON DELETE CASCADE, gate_pass_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, invoice_no VARCHAR(100), seal_no VARCHAR(100), packet_count INT NOT NULL DEFAULT 0, packet_value NUMERIC(14,2) NOT NULL DEFAULT 0, document_path VARCHAR(255), remarks TEXT, created_by INT NULL REFERENCES users(id), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
  "CREATE TABLE IF NOT EXISTS tms_trip_status_history (id SERIAL PRIMARY KEY, trip_id INT NOT NULL REFERENCES trips(id) ON DELETE CASCADE, status VARCHAR(30) NOT NULL, remarks TEXT, created_by INT NULL REFERENCES users(id), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
  "ALTER TABLE trips ADD COLUMN IF NOT EXISTS source_store_id INT NULL",
  "ALTER TABLE trips ADD COLUMN IF NOT EXISTS destination_store_id INT NULL",
  "ALTER TABLE trips ADD COLUMN IF NOT EXISTS vehicle_id INT NULL",
  "ALTER TABLE trips ADD COLUMN IF NOT EXISTS driver_id INT NULL",
  "ALTER TABLE trips ADD COLUMN IF NOT EXISTS tpt_name VARCHAR(120)",
  "ALTER TABLE trips ADD COLUMN IF NOT EXISTS planned_date DATE DEFAULT CURRENT_DATE",
  "ALTER TABLE trips ADD COLUMN IF NOT EXISTS dispatch_date DATE DEFAULT CURRENT_DATE",
  "ALTER TABLE trips ALTER COLUMN dispatch_date SET DEFAULT CURRENT_DATE",
  "UPDATE trips SET dispatch_date=COALESCE(dispatch_date,planned_date,CURRENT_DATE) WHERE dispatch_date IS NULL",
  "ALTER TABLE trips ADD COLUMN IF NOT EXISTS dispatched_at TIMESTAMP NULL",
  "ALTER TABLE trips ADD COLUMN IF NOT EXISTS completed_at TIMESTAMP NULL",
  "ALTER TABLE trips ADD COLUMN IF NOT EXISTS loaded_packets INT NOT NULL DEFAULT 0",
  "ALTER TABLE trips ADD COLUMN IF NOT EXISTS loaded_value NUMERIC(14,2) NOT NULL DEFAULT 0",
  "ALTER TABLE trips ADD COLUMN IF NOT EXISTS status VARCHAR(30) DEFAULT 'Planned'",
"ALTER TABLE trips DROP CONSTRAINT IF EXISTS trips_status_check",
"UPDATE trips SET status=CASE WHEN status IS NULL OR lower(trim(status)) IN ('planned','draft') THEN 'Planned' WHEN lower(trim(status)) IN ('in-transit','in transit','dispatched') THEN 'In Transit' WHEN lower(trim(status)) IN ('completed','delivered') THEN 'Delivered' WHEN lower(trim(status))='assigned' THEN 'Assigned' WHEN lower(trim(status))='loaded' THEN 'Loaded' WHEN lower(trim(status)) IN ('gate pass created','gatepass created') THEN 'Gate Pass Created' WHEN lower(trim(status))='breakdown' THEN 'Breakdown' WHEN lower(trim(status))='accident' THEN 'Accident' WHEN lower(trim(status))='cancelled' THEN 'Cancelled' ELSE 'Planned' END",
"ALTER TABLE trips ADD CONSTRAINT trips_status_check CHECK (status IN ('Planned','Assigned','Loaded','Gate Pass Created','In Transit','Breakdown','Accident','Delivered','Cancelled'))",
  "CREATE INDEX IF NOT EXISTS idx_tms_indent_status ON tms_indents(status,indent_date)",
  "CREATE INDEX IF NOT EXISTS idx_tms_trip_status ON tms_trip_stops(trip_id,status)"
 ] as $q){ try{$d->exec($q);}catch(Throwable $e){} }
}

/** v6: transporters, docks, vehicle placements, joint pickups, store holds. */
function tms_migrate_v6(PDO $d): void {
  foreach ([
    "CREATE TABLE IF NOT EXISTS tms_transporters (id SERIAL PRIMARY KEY, name VARCHAR(150) NOT NULL, contact_person VARCHAR(120), phone VARCHAR(30), email VARCHAR(150), gst_no VARCHAR(30), active BOOLEAN NOT NULL DEFAULT TRUE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
    "CREATE UNIQUE INDEX IF NOT EXISTS uq_tms_transporters_name ON tms_transporters(lower(name))",
    // seed transporters from names already typed on trips / drivers
    "INSERT INTO tms_transporters(name) SELECT DISTINCT ON (lower(BTRIM(n))) BTRIM(n) FROM (SELECT tpt_name n FROM trips UNION SELECT tpt_name FROM drivers) x WHERE NULLIF(BTRIM(n),'') IS NOT NULL AND upper(BTRIM(n)) NOT IN ('UNASSIGNED','UNASSIGNED TPT','NA','N/A','-','NONE','TBD') AND NOT EXISTS (SELECT 1 FROM tms_transporters t WHERE lower(BTRIM(t.name))=lower(BTRIM(x.n)))",
    "DELETE FROM tms_transporters t WHERE upper(BTRIM(t.name)) IN ('UNASSIGNED','UNASSIGNED TPT','NA','N/A','-','NONE','TBD') AND NOT EXISTS (SELECT 1 FROM tms_vehicle_placements vp WHERE vp.transporter_id=t.id) AND NOT EXISTS (SELECT 1 FROM vehicles v WHERE v.transporter_id=t.id)",
    "ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS transporter_id INT NULL",
    "ALTER TABLE trips ADD COLUMN IF NOT EXISTS vehicle_type VARCHAR(60)",
    "ALTER TABLE trips ADD COLUMN IF NOT EXISTS transporter_id INT NULL",
    "ALTER TABLE trips ADD COLUMN IF NOT EXISTS placement_id INT NULL",
    "ALTER TABLE trips ADD COLUMN IF NOT EXISTS dock_id INT NULL",
    "ALTER TABLE trips ADD COLUMN IF NOT EXISTS is_joint BOOLEAN NOT NULL DEFAULT FALSE",
    "ALTER TABLE trips ADD COLUMN IF NOT EXISTS cancelled_at TIMESTAMP NULL",
    "ALTER TABLE trips ADD COLUMN IF NOT EXISTS cancel_reason TEXT",
    "CREATE TABLE IF NOT EXISTS tms_docks (id SERIAL PRIMARY KEY, dc_id INT NOT NULL, dock_code VARCHAR(40) NOT NULL, dock_name VARCHAR(120), active BOOLEAN NOT NULL DEFAULT TRUE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
    "CREATE UNIQUE INDEX IF NOT EXISTS uq_tms_docks ON tms_docks(dc_id, lower(dock_code))",
    "CREATE TABLE IF NOT EXISTS tms_vehicle_placements (id SERIAL PRIMARY KEY, trip_id INT NOT NULL, transporter_id INT NOT NULL, vehicle_type VARCHAR(60), status VARCHAR(20) NOT NULL DEFAULT 'Requested', vehicle_no VARCHAR(30), vehicle_id INT NULL, driver_name VARCHAR(120), driver_phone VARCHAR(30), dock_id INT NULL, requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, confirmed_at TIMESTAMP NULL, dock_at TIMESTAMP NULL, placed_at TIMESTAMP NULL, failed_at TIMESTAMP NULL, fail_reason VARCHAR(120), remarks TEXT, created_by INT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
    "CREATE INDEX IF NOT EXISTS idx_vp_trip ON tms_vehicle_placements(trip_id)",
    "CREATE INDEX IF NOT EXISTS idx_vp_tpt_date ON tms_vehicle_placements(transporter_id, requested_at)",
    "CREATE TABLE IF NOT EXISTS tms_trip_pickups (id SERIAL PRIMARY KEY, trip_id INT NOT NULL, sequence_no INT NOT NULL, dc_id INT NOT NULL, packet_count INT NOT NULL DEFAULT 0, pickup_value NUMERIC(14,2) NOT NULL DEFAULT 0, UNIQUE(trip_id, sequence_no))",
    "CREATE TABLE IF NOT EXISTS prd_store_holds (id SERIAL PRIMARY KEY, store_key VARCHAR(150) NOT NULL, store_label VARCHAR(150), reason VARCHAR(60) NOT NULL, remarks TEXT, hold_until DATE NULL, created_by INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, released_at TIMESTAMP NULL, released_by INT NULL)",
    "CREATE INDEX IF NOT EXISTS idx_prd_holds_key ON prd_store_holds(store_key) WHERE released_at IS NULL",
  ] as $q) { try { $d->exec($q); } catch (Throwable $e) { /* keep going */ } }
}

/**
 * v6.1: make tables that ALREADY existed on the server (created by an older system with other columns)
 * usable by v6. Never drops data: adds missing columns, backfills them from the old columns,
 * and relaxes NOT NULL on old columns the TMS does not fill.
 */
function tms_adapt_existing_tables(PDO $d): void {
  $need = [
    'tms_transporters' => ['id' => 'SERIAL', 'name' => 'VARCHAR(150)', 'contact_person' => 'VARCHAR(120)', 'phone' => 'VARCHAR(30)', 'email' => 'VARCHAR(150)', 'gst_no' => 'VARCHAR(30)', 'active' => 'BOOLEAN DEFAULT TRUE', 'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'],
    'tms_docks' => ['id' => 'SERIAL', 'dc_id' => 'INT', 'dock_code' => 'VARCHAR(40)', 'dock_name' => 'VARCHAR(120)', 'active' => 'BOOLEAN DEFAULT TRUE', 'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'],
    'tms_vehicle_placements' => ['id' => 'SERIAL', 'trip_id' => 'INT', 'transporter_id' => 'INT', 'vehicle_type' => 'VARCHAR(60)', 'status' => "VARCHAR(20) DEFAULT 'Requested'", 'vehicle_no' => 'VARCHAR(30)', 'vehicle_id' => 'INT', 'driver_name' => 'VARCHAR(120)', 'driver_phone' => 'VARCHAR(30)', 'dock_id' => 'INT', 'requested_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP', 'confirmed_at' => 'TIMESTAMP', 'dock_at' => 'TIMESTAMP', 'placed_at' => 'TIMESTAMP', 'failed_at' => 'TIMESTAMP', 'fail_reason' => 'VARCHAR(120)', 'remarks' => 'TEXT', 'created_by' => 'INT', 'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'],
    'tms_trip_pickups' => ['id' => 'SERIAL', 'trip_id' => 'INT', 'sequence_no' => 'INT', 'dc_id' => 'INT', 'packet_count' => 'INT DEFAULT 0', 'pickup_value' => 'NUMERIC(14,2) DEFAULT 0'],
    'prd_store_holds' => ['id' => 'SERIAL', 'store_key' => 'VARCHAR(150)', 'store_label' => 'VARCHAR(150)', 'reason' => 'VARCHAR(60)', 'remarks' => 'TEXT', 'hold_until' => 'DATE', 'created_by' => 'INT', 'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP', 'released_at' => 'TIMESTAMP', 'released_by' => 'INT'],
    'tms_dc_master' => ['oracle_site_code' => 'VARCHAR(60)'],
  ];
  $cols = function (string $t) use ($d): array {
    $q = $d->prepare("SELECT column_name, is_nullable, column_default, data_type FROM information_schema.columns WHERE table_schema=current_schema() AND table_name=?");
    $q->execute([$t]); $o = []; foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $o[$r['column_name']] = $r; return $o;
  };
  foreach ($need as $t => $list) {
    try {
      $have = $cols($t); if (!$have) continue;
      foreach ($list as $c => $type) if (!isset($have[$c])) { try { $d->exec("ALTER TABLE $t ADD COLUMN IF NOT EXISTS $c $type"); } catch (Throwable $e) {} }
      if ($t === 'tms_dc_master') continue;
      // Old NOT NULL columns the TMS never fills would block inserts
      foreach ($have as $c => $r) if (!isset($list[$c]) && $r['is_nullable'] === 'NO' && $r['column_default'] === null) { try { $d->exec("ALTER TABLE $t ALTER COLUMN \"$c\" DROP NOT NULL"); } catch (Throwable $e) {} }
    } catch (Throwable $e) {}
  }
  // Transporters: backfill name / phone / active from whatever the old table used
  try {
    $have = $cols('tms_transporters');
    $pick = function (array $cands) use ($have) { foreach ($cands as $c) if (isset($have[$c]) && in_array($have[$c]['data_type'], ['character varying', 'text', 'character'], true)) return $c; return null; };
    $nm = $pick(['transporter_name', 'tpt_name', 'transporter', 'vendor_name', 'company_name', 'party_name', 'firm_name', 'title', 'transporter_code', 'code']);
    if ($nm) $d->exec("UPDATE tms_transporters SET name=NULLIF(BTRIM(\"$nm\"),'') WHERE NULLIF(BTRIM(name),'') IS NULL");
    $d->exec("UPDATE tms_transporters SET name='Transporter ' || id WHERE NULLIF(BTRIM(name),'') IS NULL");
    $ph = $pick(['mobile', 'mobile_no', 'phone_no', 'contact_no', 'contact_number', 'phone_number']);
    if ($ph) $d->exec("UPDATE tms_transporters SET phone=\"$ph\" WHERE phone IS NULL");
    $cp = $pick(['contact_name', 'owner_name', 'contact']);
    if ($cp) $d->exec("UPDATE tms_transporters SET contact_person=\"$cp\" WHERE contact_person IS NULL");
    $st = $pick(['status', 'transporter_status']);
    if ($st) $d->exec("UPDATE tms_transporters SET active = lower(COALESCE(\"$st\",'active')) NOT IN ('inactive','blocked','disabled','deactive','no','0','false')");
    $d->exec("UPDATE tms_transporters SET active=TRUE WHERE active IS NULL");
    $d->prepare("INSERT INTO tms_meta(k,v) VALUES('tpt_legacy_name_col',?) ON CONFLICT(k) DO UPDATE SET v=EXCLUDED.v")->execute([(string)$nm]);
  } catch (Throwable $e) {}
}

/** v6.2: one prd_pending row per challan line *and division class* (APPS / GM), so PKT and value split by division. */
function tms_migrate_v62(PDO $d): void {
  foreach ([
    "ALTER TABLE prd_pending DROP CONSTRAINT IF EXISTS prd_pending_dc_challan_unique",
    "CREATE UNIQUE INDEX IF NOT EXISTS uq_prd_pending_line ON prd_pending (source_site_code, challan_no, (COALESCE(challan_barcode,'')), destination_name, (COALESCE(sub_div,'')))",
    "CREATE INDEX IF NOT EXISTS idx_prd_pending_src_div ON prd_pending (source_site_code, sub_div)",
  ] as $q) { try { $d->exec($q); } catch (Throwable $e) {} }
}

function tms_migrate(PDO $d, bool $force = false): void {
  $hash = md5_file(__FILE__) . '|' . (is_file(__DIR__ . '/../config/prd_planning.php') ? '1' : '0');
  try {
    $d->exec("CREATE TABLE IF NOT EXISTS tms_meta (k VARCHAR(60) PRIMARY KEY, v TEXT, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    if (!$force) { $q = $d->prepare("SELECT v FROM tms_meta WHERE k='schema_hash'"); $q->execute(); if ($q->fetchColumn() === $hash) return; }
  } catch (Throwable $e) { /* run migrations anyway */ }
  ensure_schema($d);
  tms_migrate_extra($d);
  tms_adapt_existing_tables($d);
  tms_migrate_v6($d);
  tms_adapt_existing_tables($d);
  tms_migrate_v62($d);
  try { $d->prepare("INSERT INTO tms_meta(k,v,updated_at) VALUES('schema_hash',?,CURRENT_TIMESTAMP) ON CONFLICT(k) DO UPDATE SET v=EXCLUDED.v,updated_at=CURRENT_TIMESTAMP")->execute([$hash]); } catch (Throwable $e) {}
}
