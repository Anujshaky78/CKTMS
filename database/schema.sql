-- CITYKART TMS - PostgreSQL production-compatible schema / migration
-- Database: citykart_dc
-- Safe to run on the existing TMS database. It preserves existing orders/trips/tracking data.

-- ============================================================
-- 1. AUTHENTICATION
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100),
    username VARCHAR(80),
    full_name VARCHAR(120),
    email VARCHAR(150),
    password VARCHAR(255) NOT NULL,
    role VARCHAR(30) DEFAULT 'dispatcher',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE users ADD COLUMN IF NOT EXISTS name VARCHAR(100);
ALTER TABLE users ADD COLUMN IF NOT EXISTS username VARCHAR(80);
ALTER TABLE users ADD COLUMN IF NOT EXISTS full_name VARCHAR(120);
ALTER TABLE users ADD COLUMN IF NOT EXISTS email VARCHAR(150);
ALTER TABLE users ADD COLUMN IF NOT EXISTS role VARCHAR(30) DEFAULT 'dispatcher';
ALTER TABLE users ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;

UPDATE users
SET full_name = COALESCE(NULLIF(full_name,''), name, username, 'User')
WHERE full_name IS NULL OR full_name='';

UPDATE users
SET name = COALESCE(NULLIF(name,''), full_name, username, 'User')
WHERE name IS NULL OR name='';

UPDATE users
SET username = COALESCE(NULLIF(username,''), split_part(email,'@',1), 'user_' || id)
WHERE username IS NULL OR username='';

-- ============================================================
-- 2. STORE MASTER
-- ============================================================
CREATE TABLE IF NOT EXISTS stores (
    id SERIAL PRIMARY KEY,
    store_code VARCHAR(30) UNIQUE NOT NULL,
    store_name VARCHAR(120) NOT NULL,
    city VARCHAR(100),
    state VARCHAR(100),
    address TEXT,
    pincode VARCHAR(10),
    latitude NUMERIC(10,7) NOT NULL DEFAULT 0,
    longitude NUMERIC(10,7) NOT NULL DEFAULT 0,
    dispatch_days VARCHAR(100) DEFAULT 'Mon,Tue,Wed,Thu,Fri,Sat,Sun',
    transit_days NUMERIC(6,2) DEFAULT 1,
    active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE stores ADD COLUMN IF NOT EXISTS dispatch_days VARCHAR(100) DEFAULT 'Mon,Tue,Wed,Thu,Fri,Sat,Sun';
ALTER TABLE stores ADD COLUMN IF NOT EXISTS city VARCHAR(100);
ALTER TABLE stores ADD COLUMN IF NOT EXISTS state VARCHAR(100);
ALTER TABLE stores ADD COLUMN IF NOT EXISTS address TEXT;
ALTER TABLE stores ADD COLUMN IF NOT EXISTS pincode VARCHAR(10);
ALTER TABLE stores ADD COLUMN IF NOT EXISTS transit_days NUMERIC(6,2) DEFAULT 1;
ALTER TABLE stores ADD COLUMN IF NOT EXISTS active BOOLEAN DEFAULT TRUE;
ALTER TABLE stores ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE stores DROP CONSTRAINT IF EXISTS stores_dispatch_days_check;

-- ============================================================
-- 3. VEHICLE MASTER
-- ============================================================
CREATE TABLE IF NOT EXISTS vehicles (
    id SERIAL PRIMARY KEY,
    vehicle_number VARCHAR(30) UNIQUE NOT NULL,
    vehicle_type VARCHAR(60) NOT NULL,
    capacity_boxes INT DEFAULT 300,
    value_capacity NUMERIC(14,2) DEFAULT 2500000,
    status VARCHAR(30) DEFAULT 'Available',
    active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS capacity_boxes INT DEFAULT 300;
ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS value_capacity NUMERIC(14,2) DEFAULT 2500000;
ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS status VARCHAR(30) DEFAULT 'Available';
ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS active BOOLEAN DEFAULT TRUE;
ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;

-- ============================================================
-- 4. TPT DRIVER MASTER
-- ============================================================
CREATE TABLE IF NOT EXISTS drivers (
    id SERIAL PRIMARY KEY,
    driver_code VARCHAR(30) UNIQUE NOT NULL,
    driver_name VARCHAR(120) NOT NULL,
    tpt_name VARCHAR(120),
    phone VARCHAR(30),
    license_no VARCHAR(60),
    status VARCHAR(30) DEFAULT 'Available',
    active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE drivers ADD COLUMN IF NOT EXISTS tpt_name VARCHAR(120);
ALTER TABLE drivers ADD COLUMN IF NOT EXISTS phone VARCHAR(30);
ALTER TABLE drivers ADD COLUMN IF NOT EXISTS license_no VARCHAR(60);
ALTER TABLE drivers ADD COLUMN IF NOT EXISTS status VARCHAR(30) DEFAULT 'Available';
ALTER TABLE drivers ADD COLUMN IF NOT EXISTS active BOOLEAN DEFAULT TRUE;
ALTER TABLE drivers ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;

-- ============================================================
-- 5. EXISTING ORDERS - COMPATIBILITY MIGRATION
-- Existing installation has item_count, weight_kg, trip_id.
-- TMS now additionally needs box_count, order_value and dispatch_date.
-- ============================================================
CREATE TABLE IF NOT EXISTS orders (
    id SERIAL PRIMARY KEY,
    order_number VARCHAR(50) UNIQUE NOT NULL,
    store_id INT NOT NULL,
    box_count INT DEFAULT 1,
    order_value NUMERIC(14,2) DEFAULT 0,
    dispatch_date DATE DEFAULT CURRENT_DATE,
    trip_id INT DEFAULT NULL,
    item_count INT DEFAULT 0,
    weight_kg NUMERIC DEFAULT 0,
    status VARCHAR(30) DEFAULT 'Pending',
    plan_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE orders ADD COLUMN IF NOT EXISTS order_number VARCHAR(50);
ALTER TABLE orders ADD COLUMN IF NOT EXISTS order_no VARCHAR(50);
ALTER TABLE orders ADD COLUMN IF NOT EXISTS store_id INT;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS box_count INT;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS order_value NUMERIC(14,2);
ALTER TABLE orders ADD COLUMN IF NOT EXISTS dispatch_date DATE;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS plan_id INT;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS item_count INT;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS weight_kg NUMERIC;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS trip_id INT;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS status VARCHAR(30) DEFAULT 'Pending';

-- Replace restrictive legacy status constraint with the complete TMS order lifecycle.
ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_status_check;
UPDATE orders SET status='Pending' WHERE status IS NULL OR lower(trim(status)) NOT IN ('pending','planned','assigned','loaded','dispatched','delivered','cancelled','completed');
ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (lower(trim(status)) IN ('pending','planned','assigned','loaded','dispatched','delivered','cancelled','completed'));
ALTER TABLE orders ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;

-- Preserve legacy order_no/order_number data and ensure every old row has a visible order number.
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='orders' AND column_name='order_no') THEN
        UPDATE orders SET order_number = COALESCE(NULLIF(order_number,''), NULLIF(order_no,'')) WHERE order_number IS NULL OR order_number='';
    END IF;
    UPDATE orders SET order_number = 'ORD-'||to_char(COALESCE(created_at,CURRENT_TIMESTAMP),'YYMMDD')||'-'||id WHERE order_number IS NULL OR order_number='';
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='orders' AND column_name='order_no') THEN
        UPDATE orders SET order_no = COALESCE(NULLIF(order_no,''), order_number) WHERE order_no IS NULL OR order_no='';
    END IF;
END $$;

-- Backfill new fields from the legacy order structure without destroying data.
UPDATE orders
SET box_count = GREATEST(COALESCE(item_count,1),1)
WHERE box_count IS NULL OR box_count <= 0;

UPDATE orders
SET dispatch_date = CURRENT_DATE
WHERE dispatch_date IS NULL;

UPDATE orders
SET status = 'Pending'
WHERE status IS NULL OR trim(status)='' OR lower(trim(status)) NOT IN ('pending','planned','assigned','loaded','dispatched','delivered','cancelled','completed');

-- Old installations may not have the store FK. Add it only when it is safe.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname='orders_store_id_fkey'
    ) THEN
        BEGIN
            ALTER TABLE orders ADD CONSTRAINT orders_store_id_fkey
                FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE RESTRICT;
        EXCEPTION WHEN duplicate_object THEN NULL;
        END;
    END IF;
END $$;


-- Center DC master for Planning source
CREATE TABLE IF NOT EXISTS tms_dc_master (
    id SERIAL PRIMARY KEY,
    dc_code VARCHAR(60) UNIQUE NOT NULL,
    dc_name VARCHAR(150) NOT NULL,
    latitude NUMERIC(10,7) DEFAULT 0,
    longitude NUMERIC(10,7) DEFAULT 0,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- 6. LEGACY TRIPS / TRACKING - PRESERVE IF PRESENT
-- ============================================================
CREATE TABLE IF NOT EXISTS trips (
    id SERIAL PRIMARY KEY,
    trip_code VARCHAR(50) UNIQUE NOT NULL,
    vehicle_type VARCHAR(50) DEFAULT '24FT Dedicated Truck',
    total_boxes INT NOT NULL DEFAULT 0,
    total_value NUMERIC(14,2) NOT NULL DEFAULT 0,
    status VARCHAR(30) DEFAULT 'In-Transit',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS tracking_logs (
    id SERIAL PRIMARY KEY,
    trip_id INT REFERENCES trips(id) ON DELETE CASCADE,
    latitude NUMERIC(10,7) NOT NULL,
    longitude NUMERIC(10,7) NOT NULL,
    location_name VARCHAR(150),
    status_update VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='fk_orders_trips') THEN
        BEGIN
            ALTER TABLE orders ADD CONSTRAINT fk_orders_trips
                FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE SET NULL;
        EXCEPTION WHEN duplicate_object THEN NULL;
        END;
    END IF;
END $$;

-- ============================================================
-- 7. DISPATCH PLAN MODULE
-- Order -> Dispatch Plan -> Vehicle/TPT Driver -> Dispatch
-- ============================================================
CREATE TABLE IF NOT EXISTS dispatch_plans (
    id SERIAL PRIMARY KEY,
    plan_no VARCHAR(50),
    plan_number VARCHAR(50),
    plan_date DATE NOT NULL,
    vehicle_id INT NULL REFERENCES vehicles(id),
    driver_id INT NULL REFERENCES drivers(id),
    total_boxes INT NOT NULL DEFAULT 0,
    total_value NUMERIC(14,2) NOT NULL DEFAULT 0,
    store_count INT NOT NULL DEFAULT 0,
    status VARCHAR(30) DEFAULT 'Draft',
    created_by INT NULL REFERENCES users(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS plan_orders (
    plan_id INT NOT NULL REFERENCES dispatch_plans(id) ON DELETE CASCADE,
    order_id INT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    loaded_at TIMESTAMP NULL,
    PRIMARY KEY(plan_id, order_id)
);

ALTER TABLE dispatch_plans ADD COLUMN IF NOT EXISTS plan_no VARCHAR(50);
ALTER TABLE dispatch_plans ADD COLUMN IF NOT EXISTS plan_number VARCHAR(50);
ALTER TABLE dispatch_plans ADD COLUMN IF NOT EXISTS vehicle_id INT;
ALTER TABLE dispatch_plans ADD COLUMN IF NOT EXISTS driver_id INT;
ALTER TABLE dispatch_plans ADD COLUMN IF NOT EXISTS total_boxes INT NOT NULL DEFAULT 0;
ALTER TABLE dispatch_plans ADD COLUMN IF NOT EXISTS planned_boxes INT NOT NULL DEFAULT 0;
ALTER TABLE dispatch_plans ADD COLUMN IF NOT EXISTS planned_value NUMERIC(14,2) NOT NULL DEFAULT 0;
ALTER TABLE dispatch_plans ADD COLUMN IF NOT EXISTS loaded_boxes INT NOT NULL DEFAULT 0;
ALTER TABLE dispatch_plans ADD COLUMN IF NOT EXISTS loaded_value NUMERIC(14,2) NOT NULL DEFAULT 0;
ALTER TABLE dispatch_plans ADD COLUMN IF NOT EXISTS total_value NUMERIC(14,2) NOT NULL DEFAULT 0;
ALTER TABLE dispatch_plans ADD COLUMN IF NOT EXISTS store_count INT NOT NULL DEFAULT 0;
ALTER TABLE dispatch_plans ADD COLUMN IF NOT EXISTS status VARCHAR(30) DEFAULT 'Draft';
ALTER TABLE dispatch_plans ADD COLUMN IF NOT EXISTS created_by INT;
ALTER TABLE dispatch_plans ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE dispatch_plans ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;
UPDATE dispatch_plans SET plan_number = COALESCE(NULLIF(plan_number,''), plan_no) WHERE plan_number IS NULL OR plan_number='';
UPDATE dispatch_plans SET plan_no = COALESCE(NULLIF(plan_no,''), plan_number, 'DP-'||to_char(COALESCE(plan_date,CURRENT_DATE),'YYMMDD')||'-'||id) WHERE plan_no IS NULL OR plan_no='';

-- plan_id FK is deliberately added only if it does not already exist.
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='orders_plan_id_fkey') THEN
        BEGIN
            ALTER TABLE orders ADD CONSTRAINT orders_plan_id_fkey
                FOREIGN KEY (plan_id) REFERENCES dispatch_plans(id) ON DELETE SET NULL;
        EXCEPTION WHEN duplicate_object THEN NULL;
        END;
    END IF;
END $$;

-- ============================================================
-- 8. INDEXES
-- ============================================================
CREATE INDEX IF NOT EXISTS idx_orders_pending_date ON orders(status, dispatch_date, plan_id);
CREATE INDEX IF NOT EXISTS idx_orders_store ON orders(store_id);
CREATE INDEX IF NOT EXISTS idx_dispatch_plans_date ON dispatch_plans(plan_date, status);
CREATE INDEX IF NOT EXISTS idx_plan_orders_order ON plan_orders(order_id);
CREATE INDEX IF NOT EXISTS idx_vehicles_status ON vehicles(status, active);
CREATE INDEX IF NOT EXISTS idx_drivers_status ON drivers(status, active);

-- ============================================================
-- 9. DEFAULT LOGIN
-- Login: admin / 123
-- Password hash is bcrypt for password 123.
-- ============================================================
INSERT INTO users(username,name,full_name,email,password,role)
SELECT 'admin','Admin Dispatcher','Admin Dispatcher','admin@citykart.com',
       '$2y$12$sdyVah/udfo21tqKOoxf1OtEZZwpJxLNHh.tp4sHIvvLn2VG/F7UW','admin'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username='admin' OR email='admin@citykart.com');

-- If admin already exists, ensure username/full_name are usable by the application.
UPDATE users
SET username = COALESCE(NULLIF(username,''),'admin'),
    full_name = COALESCE(NULLIF(full_name,''),NULLIF(name,''),'Admin Dispatcher')
WHERE email='admin@citykart.com' OR username='admin';

-- ============================================================
-- 10. DEMO STORES (insert only if absent)
-- ============================================================
INSERT INTO stores(store_code,store_name,latitude,longitude,dispatch_days,active)
VALUES
('CK-01','Citykart Connaught Place',28.6315,77.2167,'Mon,Tue,Wed,Thu,Fri,Sat,Sun',TRUE),
('CK-02','Citykart Saket Mall',28.5284,77.2192,'Mon,Tue,Wed,Thu,Fri,Sat,Sun',TRUE),
('CK-03','Citykart Noida Sec 18',28.5708,77.3261,'Mon,Tue,Wed,Thu,Fri,Sat,Sun',TRUE),
('CK-04','Citykart Gurugram Cyber Hub',28.4950,77.0895,'Mon,Tue,Wed,Thu,Fri,Sat,Sun',TRUE)
ON CONFLICT(store_code) DO NOTHING;


-- IMPORTANT BUSINESS RULES USED BY THE APPLICATION:
-- 1) A dispatch plan can consolidate preferably 2 nearest stores.
-- 2) Both hard limits must be satisfied together (AND):
--       total_boxes <= 300
--       total_value <= 2500000
-- 3) Vehicle and TPT driver are assigned only AFTER plan creation.
-- 4) Existing legacy orders are preserved; their box_count is backfilled from item_count.
-- 5) Existing order_value cannot be guessed. Such legacy rows remain visible but should
--    be given a valid order value before they are eligible for dispatch planning.


-- ============================================================
-- 11. ROUTE + LIVE TRACKING EXTENSIONS
-- ============================================================
ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS current_lat NUMERIC(10,7) DEFAULT 0;
ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS current_lon NUMERIC(10,7) DEFAULT 0;
ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS avg_speed_kmh NUMERIC(6,2) DEFAULT 40;
ALTER TABLE vehicles ALTER COLUMN capacity_boxes SET DEFAULT 325;

ALTER TABLE dispatch_plans ADD COLUMN IF NOT EXISTS departure_at TIMESTAMP NULL;
ALTER TABLE dispatch_plans ADD COLUMN IF NOT EXISTS avg_speed_kmh NUMERIC(6,2) DEFAULT 40;
ALTER TABLE dispatch_plans ADD COLUMN IF NOT EXISTS first_store_eta TIMESTAMP NULL;
ALTER TABLE dispatch_plans ADD COLUMN IF NOT EXISTS route_status VARCHAR(30) DEFAULT 'Planned';
ALTER TABLE dispatch_plans ADD COLUMN IF NOT EXISTS source_store_id INT;

CREATE TABLE IF NOT EXISTS route_stops (
    id SERIAL PRIMARY KEY,
    plan_id INT NOT NULL REFERENCES dispatch_plans(id) ON DELETE CASCADE,
    sequence_no INT NOT NULL,
    store_id INT NOT NULL REFERENCES stores(id),
    status VARCHAR(30) DEFAULT 'Planned',
    eta_at TIMESTAMP NULL,
    arrived_at TIMESTAMP NULL,
    distance_from_prev_km NUMERIC(10,2) DEFAULT 0,
    UNIQUE(plan_id, sequence_no)
);

CREATE TABLE IF NOT EXISTS plan_tracking (
    id SERIAL PRIMARY KEY,
    plan_id INT NOT NULL REFERENCES dispatch_plans(id) ON DELETE CASCADE,
    latitude NUMERIC(10,7) NOT NULL,
    longitude NUMERIC(10,7) NOT NULL,
    location_name VARCHAR(150),
    status_update VARCHAR(100),
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_route_stops_plan ON route_stops(plan_id, sequence_no);
CREATE INDEX IF NOT EXISTS idx_plan_tracking_plan ON plan_tracking(plan_id, recorded_at);

-- Planning target used by the application: minimum 2 stores,
-- combined value ₹20,00,000–₹25,00,000 AND combined packets 300–325.

-- 12. REAL-TIME MOBILE GPS EXTENSIONS
ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS gps_updated_at TIMESTAMP NULL;
ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS gps_speed_kmh NUMERIC(8,2) DEFAULT 0;
ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS gps_accuracy_m NUMERIC(10,2) DEFAULT 0;
ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS gps_status VARCHAR(30) DEFAULT 'Offline';
ALTER TABLE plan_tracking ADD COLUMN IF NOT EXISTS speed_kmh NUMERIC(8,2) DEFAULT 0;
ALTER TABLE plan_tracking ADD COLUMN IF NOT EXISTS accuracy_m NUMERIC(10,2) DEFAULT 0;
ALTER TABLE plan_tracking ADD COLUMN IF NOT EXISTS source VARCHAR(30) DEFAULT 'manual';
CREATE INDEX IF NOT EXISTS idx_vehicles_gps_updated ON vehicles(gps_updated_at);
CREATE INDEX IF NOT EXISTS idx_plan_tracking_latest ON plan_tracking(plan_id, recorded_at DESC);

-- ============================================================
-- 9. NEW PLANNING / TRIP INDENT WORKFLOW
-- ============================================================
CREATE TABLE IF NOT EXISTS tms_indents (
 id SERIAL PRIMARY KEY, indent_no VARCHAR(60) UNIQUE NOT NULL, indent_date DATE NOT NULL DEFAULT CURRENT_DATE,
 source_store_id INT NULL REFERENCES stores(id), source_dc_id INT NULL REFERENCES tms_dc_master(id), destination_store_id INT NOT NULL REFERENCES stores(id),
 packet_count INT NOT NULL DEFAULT 0, indent_value NUMERIC(14,2) NOT NULL DEFAULT 0,
 status VARCHAR(30) NOT NULL DEFAULT 'Planned', trip_id INT NULL REFERENCES trips(id) ON DELETE SET NULL,
 invoice_no VARCHAR(100), seal_no VARCHAR(100), source_ref VARCHAR(120), created_by INT NULL REFERENCES users(id), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS tms_trip_stops (
 id SERIAL PRIMARY KEY, trip_id INT NOT NULL REFERENCES trips(id) ON DELETE CASCADE, sequence_no INT NOT NULL,
 store_id INT NOT NULL REFERENCES stores(id), packet_count INT NOT NULL DEFAULT 0, stop_value NUMERIC(14,2) NOT NULL DEFAULT 0,
 status VARCHAR(30) NOT NULL DEFAULT 'Planned', delivered_at TIMESTAMP NULL, UNIQUE(trip_id,sequence_no)
);
CREATE TABLE IF NOT EXISTS tms_gate_passes (
 id SERIAL PRIMARY KEY, gate_pass_no VARCHAR(60) UNIQUE NOT NULL, trip_id INT NOT NULL UNIQUE REFERENCES trips(id) ON DELETE CASCADE,
 gate_pass_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, invoice_no VARCHAR(100), seal_no VARCHAR(100), packet_count INT NOT NULL DEFAULT 0,
 packet_value NUMERIC(14,2) NOT NULL DEFAULT 0, document_path VARCHAR(255), remarks TEXT, created_by INT NULL REFERENCES users(id), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS tms_trip_status_history (
 id SERIAL PRIMARY KEY, trip_id INT NOT NULL REFERENCES trips(id) ON DELETE CASCADE, status VARCHAR(30) NOT NULL,
 remarks TEXT, created_by INT NULL REFERENCES users(id), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
ALTER TABLE tms_trip_stops ADD COLUMN IF NOT EXISTS expected_delivery_at TIMESTAMP NULL;
ALTER TABLE tms_trip_stops ADD COLUMN IF NOT EXISTS delivery_result VARCHAR(40) NULL;
ALTER TABLE tms_trip_stops ADD COLUMN IF NOT EXISTS delivery_delay_days NUMERIC(10,2) NOT NULL DEFAULT 0;
ALTER TABLE tms_trip_stops ADD COLUMN IF NOT EXISTS delivery_remarks TEXT NULL;
ALTER TABLE trips ADD COLUMN IF NOT EXISTS source_store_id INT NULL;
ALTER TABLE trips ADD COLUMN IF NOT EXISTS destination_store_id INT NULL;
ALTER TABLE trips ADD COLUMN IF NOT EXISTS vehicle_id INT NULL;
ALTER TABLE trips ADD COLUMN IF NOT EXISTS driver_id INT NULL;
ALTER TABLE trips ADD COLUMN IF NOT EXISTS tpt_name VARCHAR(120);
ALTER TABLE trips ADD COLUMN IF NOT EXISTS planned_date DATE DEFAULT CURRENT_DATE;
ALTER TABLE trips ADD COLUMN IF NOT EXISTS dispatched_at TIMESTAMP NULL;
ALTER TABLE trips ADD COLUMN IF NOT EXISTS completed_at TIMESTAMP NULL;
ALTER TABLE trips ADD COLUMN IF NOT EXISTS loaded_packets INT NOT NULL DEFAULT 0;
ALTER TABLE trips ADD COLUMN IF NOT EXISTS loaded_value NUMERIC(14,2) NOT NULL DEFAULT 0;
CREATE INDEX IF NOT EXISTS idx_tms_indent_status ON tms_indents(status,indent_date);
CREATE INDEX IF NOT EXISTS idx_tms_trip_status ON tms_trip_stops(trip_id,status);

