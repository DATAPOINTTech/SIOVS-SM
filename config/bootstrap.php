<?php
declare(strict_types=1);

session_start();

$root   = dirname(__DIR__);
$dbPath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'siovs.sqlite';
$uploadDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'uploads';
if (!is_dir(dirname($dbPath))) {
    mkdir(dirname($dbPath), 0775, true);
}
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0775, true);
}

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('PRAGMA journal_mode = WAL');

/* ── Schema helpers ──────────────────────────────────────────────────────── */
function add_col(PDO $db, string $table, string $col, string $def): void {
    $exists = array_column($db->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array($col, $exists, true)) {
        $db->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$def}");
    }
}

/* ── Core tables (preserve existing data) ───────────────────────────────── */
$pdo->exec('CREATE TABLE IF NOT EXISTS transactions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    type       TEXT    NOT NULL,
    reference  TEXT    NOT NULL,
    party      TEXT    NOT NULL,
    quantity   INTEGER NOT NULL DEFAULT 0,
    status     TEXT    NOT NULL DEFAULT "Pending",
    created_at TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
)');

$pdo->exec('CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    name          TEXT NOT NULL,
    email         TEXT NOT NULL UNIQUE,
    role          TEXT NOT NULL DEFAULT "data_entry",
    department    TEXT NOT NULL DEFAULT "",
    status        TEXT NOT NULL DEFAULT "Active",
    password_hash TEXT,
    created_at    TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');
add_col($pdo, 'users', 'password_hash', 'TEXT');

$pdo->exec('CREATE TABLE IF NOT EXISTS user_sessions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id   TEXT NOT NULL UNIQUE,
    user_id      INTEGER REFERENCES users(id),
    started_at   TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at     TEXT,
    ip_address   TEXT,
    user_agent   TEXT
)');

$pdo->exec('CREATE TABLE IF NOT EXISTS user_activity_log (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER REFERENCES users(id),
    session_id TEXT,
    action     TEXT NOT NULL,
    entity     TEXT,
    entity_id  INTEGER,
    details    TEXT,
    ip_address TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');

$pdo->exec('CREATE TABLE IF NOT EXISTS items (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    code          TEXT    NOT NULL UNIQUE,
    name          TEXT    NOT NULL,
    category      TEXT    NOT NULL,
    unit          TEXT    NOT NULL,
    location      TEXT    NOT NULL,
    quantity      INTEGER NOT NULL DEFAULT 0,
    reorder_level INTEGER NOT NULL DEFAULT 0,
    batch_number  TEXT,
    expiry_date   TEXT,
    status        TEXT    NOT NULL DEFAULT "Available",
    created_at    TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
)');
add_col($pdo, 'items', 'sub_category',       'TEXT');
add_col($pdo, 'items', 'min_level',           'INTEGER NOT NULL DEFAULT 0');
add_col($pdo, 'items', 'max_level',           'INTEGER NOT NULL DEFAULT 0');
add_col($pdo, 'items', 'average_consumption', 'INTEGER NOT NULL DEFAULT 0');
add_col($pdo, 'items', 'lead_time_days',      'INTEGER NOT NULL DEFAULT 0');
add_col($pdo, 'items', 'batch_required',      'INTEGER NOT NULL DEFAULT 0');
add_col($pdo, 'items', 'supplier_id',         'INTEGER REFERENCES suppliers(id)');

$pdo->exec('CREATE TABLE IF NOT EXISTS assets (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    tag          TEXT NOT NULL UNIQUE,
    name         TEXT NOT NULL,
    department   TEXT NOT NULL,
    location     TEXT NOT NULL,
    custodian    TEXT NOT NULL,
    status       TEXT NOT NULL DEFAULT "In service",
    warranty_until TEXT,
    created_at   TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');
add_col($pdo, 'assets', 'category',        'TEXT');
add_col($pdo, 'assets', 'make',            'TEXT');
add_col($pdo, 'assets', 'model',           'TEXT');
add_col($pdo, 'assets', 'serial_number',   'TEXT');
add_col($pdo, 'assets', 'purchase_date',   'TEXT');
add_col($pdo, 'assets', 'cost',            'REAL');
add_col($pdo, 'assets', 'useful_life_years','INTEGER');
add_col($pdo, 'assets', 'disposal_date',   'TEXT');
add_col($pdo, 'assets', 'disposal_ref',    'TEXT');
add_col($pdo, 'assets', 'amc_start',       'TEXT');
add_col($pdo, 'assets', 'amc_expiry',      'TEXT');
add_col($pdo, 'assets', 'warranty_start',  'TEXT');
add_col($pdo, 'assets', 'vendor_name',     'TEXT');

/* ── New Phase-I tables ──────────────────────────────────────────────────── */
$pdo->exec('CREATE TABLE IF NOT EXISTS suppliers (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    code           TEXT NOT NULL UNIQUE,
    name           TEXT NOT NULL,
    contact_person TEXT,
    phone          TEXT,
    email          TEXT,
    address        TEXT,
    gst_number     TEXT,
    status         TEXT NOT NULL DEFAULT "Active",
    created_at     TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');

$pdo->exec('CREATE TABLE IF NOT EXISTS purchase_orders (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    po_number          TEXT NOT NULL UNIQUE,
    po_date            TEXT NOT NULL,
    supplier_id        INTEGER NOT NULL REFERENCES suppliers(id),
    purchase_reference TEXT,
    tender_reference   TEXT,
    expected_delivery  TEXT,
    total_value        REAL    DEFAULT 0,
    status             TEXT    NOT NULL DEFAULT "Draft",
    notes              TEXT,
    created_by         INTEGER REFERENCES users(id),
    created_at         TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
)');

$pdo->exec('CREATE TABLE IF NOT EXISTS po_items (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    po_id             INTEGER NOT NULL REFERENCES purchase_orders(id),
    item_id           INTEGER NOT NULL REFERENCES items(id),
    quantity_ordered  INTEGER NOT NULL DEFAULT 0,
    unit              TEXT,
    rate              REAL    DEFAULT 0,
    taxes             REAL    DEFAULT 0,
    total_value       REAL    DEFAULT 0,
    warranty_applicable INTEGER DEFAULT 0
)');

$pdo->exec('CREATE TABLE IF NOT EXISTS grns (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    grn_number        TEXT NOT NULL UNIQUE,
    po_id             INTEGER REFERENCES purchase_orders(id),
    supplier_id       INTEGER REFERENCES suppliers(id),
    challan_number    TEXT,
    challan_date      TEXT,
    received_date     TEXT NOT NULL,
    inspection_status TEXT NOT NULL DEFAULT "Pending",
    remarks           TEXT,
    created_by        INTEGER REFERENCES users(id),
    created_at        TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');

$pdo->exec('CREATE TABLE IF NOT EXISTS grn_items (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    grn_id             INTEGER NOT NULL REFERENCES grns(id),
    item_id            INTEGER NOT NULL REFERENCES items(id),
    batch_number       TEXT,
    manufacturing_date TEXT,
    expiry_date        TEXT,
    quantity_ordered   INTEGER DEFAULT 0,
    quantity_received  INTEGER NOT NULL DEFAULT 0,
    quantity_accepted  INTEGER NOT NULL DEFAULT 0,
    quantity_rejected  INTEGER NOT NULL DEFAULT 0,
    rate               REAL    DEFAULT 0,
    total_value        REAL    DEFAULT 0,
    remarks            TEXT
)');

$pdo->exec('CREATE TABLE IF NOT EXISTS departments (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    type       TEXT NOT NULL DEFAULT "Other",
    incharge   TEXT,
    phone      TEXT,
    status     TEXT NOT NULL DEFAULT "Active",
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');

$pdo->exec('CREATE TABLE IF NOT EXISTS requisitions (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    req_number         TEXT NOT NULL UNIQUE,
    department_id      INTEGER NOT NULL REFERENCES departments(id),
    req_date           TEXT NOT NULL,
    purpose            TEXT,
    requesting_officer TEXT,
    approving_officer  TEXT,
    store_officer      TEXT,
    status             TEXT NOT NULL DEFAULT "Draft",
    created_by         INTEGER REFERENCES users(id),
    created_at         TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');

$pdo->exec('CREATE TABLE IF NOT EXISTS requisition_items (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    req_id            INTEGER NOT NULL REFERENCES requisitions(id),
    item_id           INTEGER NOT NULL REFERENCES items(id),
    required_quantity INTEGER NOT NULL DEFAULT 0,
    approved_quantity INTEGER DEFAULT 0,
    issued_quantity   INTEGER DEFAULT 0
)');

$pdo->exec('CREATE TABLE IF NOT EXISTS consumption_records (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    type           TEXT NOT NULL,
    patient_mr     TEXT,
    patient_name   TEXT,
    department_id  INTEGER REFERENCES departments(id),
    visit_date     TEXT,
    surgery_number TEXT,
    surgeon        TEXT,
    procedure_name TEXT,
    ward           TEXT,
    bed            TEXT,
    admission_date TEXT,
    item_id        INTEGER NOT NULL REFERENCES items(id),
    batch_number   TEXT,
    quantity       INTEGER NOT NULL DEFAULT 0,
    posted_by      INTEGER REFERENCES users(id),
    created_at     TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');

$pdo->exec('CREATE TABLE IF NOT EXISTS stock_adjustments (
    id                   INTEGER PRIMARY KEY AUTOINCREMENT,
    adj_number           TEXT NOT NULL UNIQUE,
    item_id              INTEGER NOT NULL REFERENCES items(id),
    reason               TEXT NOT NULL,
    quantity             INTEGER NOT NULL,
    value                REAL    DEFAULT 0,
    requesting_user_id   INTEGER REFERENCES users(id),
    authorizing_officer  TEXT,
    status               TEXT NOT NULL DEFAULT "Pending",
    notes                TEXT,
    verification_id      INTEGER REFERENCES stock_verifications(id),
    created_at           TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');

$pdo->exec('CREATE TABLE IF NOT EXISTS stock_verifications (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    ver_number         TEXT NOT NULL UNIQUE,
    verification_date  TEXT NOT NULL,
    committee_members  TEXT,
    remarks            TEXT,
    status             TEXT NOT NULL DEFAULT "Draft",
    approved_by        TEXT,
    created_by         INTEGER REFERENCES users(id),
    created_at         TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');

$pdo->exec('CREATE TABLE IF NOT EXISTS stock_verification_items (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    verification_id  INTEGER NOT NULL REFERENCES stock_verifications(id),
    item_id          INTEGER NOT NULL REFERENCES items(id),
    system_quantity  INTEGER NOT NULL DEFAULT 0,
    physical_quantity INTEGER,
    difference       INTEGER,
    explanation      TEXT,
    adjustment_made  INTEGER DEFAULT 0
)');

/* ── Phase 0: Core Multi-Tier Inventory, Batches, Audit & Deletion Tables ─── */
$pdo->exec('CREATE TABLE IF NOT EXISTS department_stock (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    department_id  INTEGER NOT NULL REFERENCES departments(id),
    item_id        INTEGER NOT NULL REFERENCES items(id),
    batch_number   TEXT NOT NULL DEFAULT "",
    quantity       INTEGER NOT NULL DEFAULT 0,
    expiry_date    TEXT,
    updated_at     TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(department_id, item_id, batch_number)
);');

$pdo->exec('CREATE TABLE IF NOT EXISTS item_batches (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    item_id            INTEGER NOT NULL REFERENCES items(id),
    batch_number       TEXT NOT NULL,
    manufacturing_date TEXT,
    expiry_date        TEXT,
    quantity           INTEGER NOT NULL DEFAULT 0,
    unit_cost          REAL DEFAULT 0,
    supplier_id        INTEGER REFERENCES suppliers(id),
    grn_id             INTEGER REFERENCES grns(id),
    location           TEXT,
    status             TEXT NOT NULL DEFAULT "Active",
    created_at         TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(item_id, batch_number)
);');

$pdo->exec('CREATE TABLE IF NOT EXISTS stock_ledger (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    transaction_type TEXT NOT NULL,
    reference_no     TEXT NOT NULL,
    item_id          INTEGER NOT NULL REFERENCES items(id),
    batch_number     TEXT,
    source_type      TEXT NOT NULL,
    source_id        INTEGER,
    dest_type        TEXT NOT NULL,
    dest_id          INTEGER,
    quantity         INTEGER NOT NULL,
    balance_before   INTEGER DEFAULT 0,
    balance_after    INTEGER DEFAULT 0,
    user_id          INTEGER REFERENCES users(id),
    notes            TEXT,
    created_at       TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);');

$pdo->exec('CREATE TABLE IF NOT EXISTS deletion_requests (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    request_no       TEXT NOT NULL UNIQUE,
    table_name       TEXT NOT NULL,
    record_id        INTEGER NOT NULL,
    reference_no     TEXT NOT NULL,
    section          TEXT NOT NULL,
    description      TEXT NOT NULL,
    quantity         INTEGER DEFAULT 0,
    value            REAL DEFAULT 0,
    reason           TEXT NOT NULL,
    supporting_doc   TEXT,
    requested_by     INTEGER NOT NULL REFERENCES users(id),
    requested_at     TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status           TEXT NOT NULL DEFAULT "Pending",
    reviewed_by      INTEGER REFERENCES users(id),
    reviewed_at      TEXT,
    review_remarks   TEXT,
    stock_reversed   INTEGER NOT NULL DEFAULT 0
);');

$pdo->exec('CREATE TABLE IF NOT EXISTS manual_reconciliations (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    rec_number        TEXT NOT NULL UNIQUE,
    register_name     TEXT NOT NULL,
    page_no           TEXT NOT NULL,
    entry_no          TEXT NOT NULL,
    transaction_type  TEXT NOT NULL,
    transaction_id    INTEGER,
    transaction_ref   TEXT,
    register_qty      INTEGER NOT NULL,
    system_qty        INTEGER NOT NULL,
    difference        INTEGER NOT NULL,
    reason            TEXT,
    corrective_action TEXT,
    verified_by       INTEGER REFERENCES users(id),
    verified_date     TEXT NOT NULL,
    created_at        TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);');

$pdo->exec('CREATE TABLE IF NOT EXISTS department_returns (
    id                   INTEGER PRIMARY KEY AUTOINCREMENT,
    return_number        TEXT NOT NULL UNIQUE,
    department_id        INTEGER NOT NULL REFERENCES departments(id),
    return_date          TEXT NOT NULL,
    reason               TEXT NOT NULL,
    returning_officer    TEXT,
    receiving_officer    TEXT,
    manual_register_name TEXT,
    manual_register_page TEXT,
    manual_register_entry TEXT,
    status               TEXT NOT NULL DEFAULT "Received",
    created_by           INTEGER REFERENCES users(id),
    created_at           TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);');

$pdo->exec('CREATE TABLE IF NOT EXISTS department_return_items (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    return_id         INTEGER NOT NULL REFERENCES department_returns(id),
    item_id           INTEGER NOT NULL REFERENCES items(id),
    batch_number      TEXT,
    quantity_returned INTEGER NOT NULL DEFAULT 0,
    remarks           TEXT
);');

$pdo->exec('CREATE TABLE IF NOT EXISTS procedure_templates (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    procedure_name TEXT NOT NULL UNIQUE,
    description    TEXT,
    status         TEXT NOT NULL DEFAULT "Active",
    created_at     TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);');

$pdo->exec('CREATE TABLE IF NOT EXISTS procedure_template_items (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    template_id    INTEGER NOT NULL REFERENCES procedure_templates(id),
    item_id        INTEGER NOT NULL REFERENCES items(id),
    default_qty    INTEGER NOT NULL DEFAULT 1
);');

$pdo->exec('CREATE TABLE IF NOT EXISTS document_attachments (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    entity_type    TEXT NOT NULL,
    entity_id      INTEGER NOT NULL,
    file_name      TEXT NOT NULL,
    file_path      TEXT NOT NULL,
    file_size      INTEGER,
    mime_type      TEXT,
    uploaded_by    INTEGER REFERENCES users(id),
    uploaded_at    TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);');

$pdo->exec('CREATE TABLE IF NOT EXISTS asset_transfers (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    transfer_number     TEXT NOT NULL UNIQUE,
    asset_id            INTEGER NOT NULL REFERENCES assets(id),
    previous_department TEXT,
    new_department      TEXT NOT NULL,
    previous_location   TEXT,
    new_location        TEXT NOT NULL,
    previous_custodian  TEXT,
    new_custodian       TEXT NOT NULL,
    transfer_date       TEXT NOT NULL,
    reason              TEXT,
    authorized_by       TEXT,
    received_by         TEXT,
    created_by          INTEGER REFERENCES users(id),
    created_at          TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);');

$pdo->exec('CREATE TABLE IF NOT EXISTS asset_maintenance (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    maintenance_no    TEXT NOT NULL UNIQUE,
    asset_id          INTEGER NOT NULL REFERENCES assets(id),
    maintenance_type  TEXT NOT NULL DEFAULT "Breakdown",
    department        TEXT,
    problem_details   TEXT,
    reported_date     TEXT NOT NULL,
    reported_by       TEXT,
    priority          TEXT NOT NULL DEFAULT "Medium",
    technician_vendor TEXT,
    repair_details    TEXT,
    parts_replaced    TEXT,
    labor_cost        REAL DEFAULT 0,
    parts_cost        REAL DEFAULT 0,
    total_cost        REAL DEFAULT 0,
    status            TEXT NOT NULL DEFAULT "Under repair",
    date_returned     TEXT,
    warranty_covered  INTEGER DEFAULT 0,
    next_due_date     TEXT,
    remarks           TEXT,
    created_by        INTEGER REFERENCES users(id),
    created_at        TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);');

$pdo->exec('CREATE TABLE IF NOT EXISTS condemnations (
    id                   INTEGER PRIMARY KEY AUTOINCREMENT,
    condemnation_no      TEXT NOT NULL UNIQUE,
    asset_id             INTEGER REFERENCES assets(id),
    item_id              INTEGER REFERENCES items(id),
    item_name            TEXT NOT NULL,
    tag_or_code          TEXT,
    department           TEXT,
    survey_board_ref     TEXT NOT NULL,
    survey_board_date    TEXT NOT NULL,
    reason               TEXT NOT NULL,
    recommendation       TEXT,
    committee_members    TEXT,
    estimated_scrap_val  REAL DEFAULT 0,
    disposal_method      TEXT NOT NULL DEFAULT "Auction",
    disposal_date        TEXT,
    auction_challan_no   TEXT,
    recovered_amount     REAL DEFAULT 0,
    buyer_vendor_name    TEXT,
    status               TEXT NOT NULL DEFAULT "Recommended",
    approved_by          TEXT,
    created_by           INTEGER REFERENCES users(id),
    created_at           TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);');

/* ── Add columns to existing tables for manual registers and lifecycle ───── */
add_col($pdo, 'assets', 'asset_type', 'TEXT NOT NULL DEFAULT "Fixed Asset"');
add_col($pdo, 'assets', 'po_number', 'TEXT');
add_col($pdo, 'assets', 'challan_number', 'TEXT');
add_col($pdo, 'assets', 'grn_number', 'TEXT');
add_col($pdo, 'assets', 'invoice_number', 'TEXT');
add_col($pdo, 'assets', 'condemnation_id', 'INTEGER');

add_col($pdo, 'grns', 'status', 'TEXT NOT NULL DEFAULT "Active"');
add_col($pdo, 'grns', 'manual_register_name', 'TEXT');
add_col($pdo, 'grns', 'manual_register_page', 'TEXT');
add_col($pdo, 'grns', 'manual_register_entry', 'TEXT');

add_col($pdo, 'purchase_orders', 'manual_register_name', 'TEXT');
add_col($pdo, 'purchase_orders', 'manual_register_page', 'TEXT');
add_col($pdo, 'purchase_orders', 'manual_register_entry', 'TEXT');

add_col($pdo, 'requisitions', 'manual_register_name', 'TEXT');
add_col($pdo, 'requisitions', 'manual_register_page', 'TEXT');
add_col($pdo, 'requisitions', 'manual_register_entry', 'TEXT');

add_col($pdo, 'consumption_records', 'status', 'TEXT NOT NULL DEFAULT "Active"');
add_col($pdo, 'consumption_records', 'manual_register_name', 'TEXT');
add_col($pdo, 'consumption_records', 'manual_register_page', 'TEXT');
add_col($pdo, 'consumption_records', 'manual_register_entry', 'TEXT');
add_col($pdo, 'consumption_records', 'regularized_at', 'TEXT');
add_col($pdo, 'consumption_records', 'regularized_by', 'INTEGER REFERENCES users(id)');

add_col($pdo, 'stock_adjustments', 'manual_register_name', 'TEXT');
add_col($pdo, 'stock_adjustments', 'manual_register_page', 'TEXT');
add_col($pdo, 'stock_adjustments', 'manual_register_entry', 'TEXT');
add_col($pdo, 'manual_reconciliations', 'status', 'TEXT NOT NULL DEFAULT "Matched"');

/* ── Seed data ───────────────────────────────────────────────────────────── */
if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
    $stmt = $pdo->prepare('INSERT INTO users (name,email,role,department,status,password_hash) VALUES(?,?,?,?,?,?)');
    foreach ([
        ['Ayaz Khan',      'ayaz@siovs.edu.pk',  'admin',           'Central Store',       'Active', password_hash('admin123',  PASSWORD_DEFAULT)],
        ['Imran Ali',      'imran@siovs.edu.pk',  'store_officer',   'Central Store',       'Active', password_hash('store123',  PASSWORD_DEFAULT)],
        ['Rana Tariq',     'rana@siovs.edu.pk',   'purchase_officer','Purchase Section',    'Active', password_hash('po123',     PASSWORD_DEFAULT)],
        ['Dr. Sara Ahmed', 'sara@siovs.edu.pk',   'dept_incharge',   'Emergency Department','Active', password_hash('dept123',   PASSWORD_DEFAULT)],
        ['Zahid Hussain',  'zahid@siovs.edu.pk',  'dd_finance',      'Finance',             'Active', password_hash('fin123',    PASSWORD_DEFAULT)],
        ['Audit Officer',  'audit@siovs.edu.pk',  'internal_audit',  'Internal Audit',      'Active', password_hash('audit123',  PASSWORD_DEFAULT)],
    ] as $u) { $stmt->execute($u); }
}

if ((int) $pdo->query('SELECT COUNT(*) FROM suppliers')->fetchColumn() === 0) {
    $stmt = $pdo->prepare('INSERT INTO suppliers (code,name,contact_person,phone,email,status) VALUES(?,?,?,?,?,?)');
    foreach ([
        ['SUP-001','Medline Supplies',    'Ahmed Khan', '0300-1234567','sales@medline.pk',      'Active'],
        ['SUP-002','CarePoint Pharma',    'Sara Mirza', '0321-7654321','info@carepoint.pk',     'Active'],
        ['SUP-003','Pak Surgical Co.',    'Bilal Raza', '0333-9876543','billing@paksurgical.pk','Active'],
    ] as $r) { $stmt->execute($r); }
}

if ((int) $pdo->query('SELECT COUNT(*) FROM departments')->fetchColumn() === 0) {
    $stmt = $pdo->prepare('INSERT INTO departments (name,type,incharge,status) VALUES(?,?,?,?)');
    foreach ([
        ['OPD Pharmacy',       'OPD',       'Dr. Farrukh',          'Active'],
        ['Ward 1 (IPD)',        'IPD',       'Head Nurse Zainab',    'Active'],
        ['Ward 2 (IPD)',        'IPD',       'Head Nurse Rehana',    'Active'],
        ['Ward 3 (IPD)',        'IPD',       'Head Nurse Asma',      'Active'],
        ['Operating Theatre',  'OT',        'OT Manager Saleem',    'Active'],
        ['Emergency Department','Emergency', 'Dr. Sara Ahmed',       'Active'],
        ['ICU',                 'Other',     'ICU Supervisor',       'Active'],
        ['Laboratory',          'Other',     'Lab Incharge',         'Active'],
    ] as $d) { $stmt->execute($d); }
}

if ((int) $pdo->query('SELECT COUNT(*) FROM items')->fetchColumn() === 0) {
    $stmt = $pdo->prepare('INSERT INTO items
        (code,name,category,unit,location,quantity,reorder_level,min_level,max_level,batch_number,expiry_date,status,batch_required)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ([
        ['GLV-001','Examination gloves (medium)',    'Surgical Consumables',       'Box',   'Main Store · A2', 18,  25, 15, 100,'GLV-2408',   '2027-08-31','Low stock', 0],
        ['CEF-014','Ceftriaxone 1g injection',       'Medicines',                  'Vial',  'Cold Room · B1',  64,  20, 10, 200,'B-CEF-2407', '2026-10-04','Available', 1],
        ['SYR-030','Disposable syringe 5 ml',        'General Medical Consumables','Unit',  'OT Store · C1',  210,  80, 50, 500,'B-SYR-2410', '2028-01-20','Available', 0],
        ['IOL-001','Intraocular lens (PC IOL)',       'Ophthalmic Consumables',     'Piece', 'OT Store · D1',   45,  10,  5, 100,'IOL-2406',   '2028-06-30','Available', 1],
        ['MSK-010','Surgical mask (3-ply)',           'Surgical Consumables',       'Box',   'Main Store · B4', 80,  30, 20, 200,'B-MSK-2410', '2026-12-15','Available', 0],
        ['SUL-002','Sulphacetamide eye drops 10%',   'Ophthalmic Medicines',       'Bottle','Cold Room · B2',   5,  15, 10, 100,'SUL-2405',   '2026-09-25','Low stock', 1],
        ['BDG-005','Bandage crepe 10 cm',            'General Medical Consumables','Roll',  'Main Store · A3', 120, 40, 30, 300, null,          null,        'Available', 0],
        ['ANS-009','Lignocaine 2% injection',        'Medicines',                  'Amp',   'Cold Room · B3',   30, 15, 10, 150,'B-LIG-2409', '2027-03-31','Available', 1],
    ] as $r) { $stmt->execute($r); }
}

if ((int) $pdo->query('SELECT COUNT(*) FROM assets')->fetchColumn() === 0) {
    $stmt = $pdo->prepare('INSERT INTO assets (tag,name,department,location,custodian,status,warranty_until,category,make,model) VALUES(?,?,?,?,?,?,?,?,?,?)');
    foreach ([
        ['AST-00084','Infusion pump',     'ICU',               'ICU Bay 4','Nursing supervisor','In service', '2027-04-15','Medical Equipment','Fresenius','Agilia SP'],
        ['AST-00061','Patient monitor',   'Operating Theatre', 'OT 1',     'OT manager',       'AMC due soon','2026-11-30','Medical Equipment','Philips',  'IntelliVue MX450'],
        ['AST-00042','OT Microscope',     'Operating Theatre', 'OT 2',     'OT manager',       'In service', '2028-02-28','Medical Equipment','Zeiss',    'OPMI Lumera'],
        ['AST-00021','Autoclave (large)', 'Central Store',     'Store A',  'Store Officer',    'In service', '2027-08-15','Equipment',        'Tuttnauer','2540M'],
    ] as $r) { $stmt->execute($r); }
}

// Ensure rich ophthalmic assets exist
if ((int) $pdo->query('SELECT COUNT(*) FROM assets WHERE tag="AST-00101"')->fetchColumn() === 0) {
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO assets (tag,name,department,location,custodian,status,warranty_until,category,make,model,cost,amc_expiry) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ([
        ['AST-00101','Slit Lamp Microscope',    'OPD Pharmacy',       'OPD Room 3',  'Dr. Farrukh',        'In service',  '2027-10-31','Medical Equipment','Carl Zeiss', 'SL 115',     850000, '2028-10-31'],
        ['AST-00102','Phacoemulsification Sys','Operating Theatre',  'OT Room 1',   'OT Manager Saleem',  'AMC due soon','2026-10-25','Medical Equipment','Alcon',      'Laureate',   3200000, '2026-10-25'],
        ['AST-00103','Nd:YAG Laser System',     'Emergency Department','Laser Suite', 'Dr. Sara Ahmed',     'In service',  '2027-05-15','Medical Equipment','Ellex',      'Super Q',    2400000, '2027-11-15'],
        ['AST-00104','Ophthalmic B-Scan',       'Laboratory',         'Ultrasound Bay','Lab Incharge',      'Under repair','2025-12-31','Medical Equipment','Quantel',    'Compact Touch', 1150000, '2026-06-30'],
        ['AST-00105','Manual Keratometer',      'Central Store',      'Dead Stock A','Store Officer',      'Condemned',   '2015-01-01','Medical Equipment','Bausch&Lomb','Classic OM', 120000,  null],
    ] as $a) { $stmt->execute($a); }
}

if ((int) $pdo->query('SELECT COUNT(*) FROM asset_transfers')->fetchColumn() === 0) {
    $aId = (int)$pdo->query("SELECT id FROM assets WHERE tag='AST-00084' LIMIT 1")->fetchColumn();
    if ($aId) {
        $pdo->prepare('INSERT INTO asset_transfers (transfer_number, asset_id, previous_department, new_department, previous_location, new_location, previous_custodian, new_custodian, transfer_date, reason, authorized_by, received_by, created_by)
            VALUES (?, ?, "Central Store", "ICU", "Store Room A", "ICU Bay 4", "Store Officer", "Nursing supervisor", "2026-09-05", "Urgent patient monitoring requirement in intensive care", "Medical Superintendent", "Staff Nurse Bilquis", 1)')
            ->execute(['TRF-2026-0001', $aId]);
    }
}

if ((int) $pdo->query('SELECT COUNT(*) FROM asset_maintenance')->fetchColumn() === 0) {
    $aId = (int)$pdo->query("SELECT id FROM assets WHERE tag='AST-00104' LIMIT 1")->fetchColumn();
    if ($aId) {
        $pdo->prepare('INSERT INTO asset_maintenance (maintenance_no, asset_id, maintenance_type, department, problem_details, reported_date, reported_by, priority, technician_vendor, repair_details, parts_replaced, labor_cost, parts_cost, total_cost, status, warranty_covered, remarks, created_by)
            VALUES (?, ?, "Breakdown", "Laboratory", "Probe transducer intermittent signal error during posterior segment evaluation", "2026-09-20", "Lab Incharge", "High", "Biomedical Precision Engineering", "Probe piezoelectric crystal inspection and connector re-soldering in progress", "Transducer Cable Harness", 10000, 45000, 55000, "Under repair", 0, "Awaiting test calibration bench results", 1)')
            ->execute(['MNT-2026-0001', $aId]);
    }
    $aId2 = (int)$pdo->query("SELECT id FROM assets WHERE tag='AST-00061' LIMIT 1")->fetchColumn();
    if ($aId2) {
        $pdo->prepare('INSERT INTO asset_maintenance (maintenance_no, asset_id, maintenance_type, department, problem_details, reported_date, reported_by, priority, technician_vendor, repair_details, parts_replaced, labor_cost, parts_cost, total_cost, status, date_returned, warranty_covered, remarks, created_by)
            VALUES (?, ?, "Preventive AMC", "Operating Theatre", "Quarterly scheduled preventive maintenance & ECG/NIBP sensor calibration", "2026-09-10", "OT Manager Saleem", "Medium", "Philips Medical Authorized Tech", "Full diagnostic self-test passed, optical NIBP hose leak tested", "NIBP Cuff Hose", 5000, 7500, 12500, "Completed", "2026-09-12", 1, "Next calibration due in 90 days", 1)')
            ->execute(['MNT-2026-0002', $aId2]);
    }
}

if ((int) $pdo->query('SELECT COUNT(*) FROM condemnations')->fetchColumn() === 0) {
    $aId = (int)$pdo->query("SELECT id FROM assets WHERE tag='AST-00105' LIMIT 1")->fetchColumn();
    $pdo->prepare('INSERT INTO condemnations (condemnation_no, asset_id, item_name, tag_or_code, department, survey_board_ref, survey_board_date, reason, recommendation, committee_members, estimated_scrap_val, disposal_method, status, approved_by, created_by)
        VALUES (?, ?, "Manual Keratometer", "AST-00105", "Central Store", "SIOVS/SB/2026/08", "2026-08-25", "Beyond Economic Repair (BER) - Optical prism internal misalignment and silvering decayed. Original manufacturer spare parts obsolete.", "Condemn asset and dispose via open public auction under Sindh Financial Rules", "DD Finance, Store Officer, Biomedical Engineer", 8000.0, "Auction", "Approved by Board", "Prof. Dr. Competent Authority", 1)')
        ->execute(['CND-2026-0001', $aId ?: null]);
}


if ((int) $pdo->query('SELECT COUNT(*) FROM purchase_orders')->fetchColumn() === 0) {
    $pdo->exec('INSERT INTO purchase_orders (po_number,po_date,supplier_id,expected_delivery,total_value,status)
        VALUES ("PO-2026-0042","2026-09-01",1,"2026-09-15",48000,"Delivered")');
    $pdo->exec('INSERT INTO purchase_orders (po_number,po_date,supplier_id,expected_delivery,total_value,status)
        VALUES ("PO-2026-0043","2026-09-10",2,"2026-09-25",22500,"Approved")');
    $pdo->exec('INSERT INTO purchase_orders (po_number,po_date,supplier_id,expected_delivery,total_value,status)
        VALUES ("PO-2026-0044","2026-09-12",3,"2026-09-30",15750,"Draft")');
}

if ((int) $pdo->query('SELECT COUNT(*) FROM grns')->fetchColumn() === 0) {
    $pdo->exec('INSERT INTO grns (grn_number,po_id,supplier_id,challan_number,challan_date,received_date,inspection_status)
        VALUES ("GRN-2026-0038",1,1,"DC-4521","2026-09-14","2026-09-14","Inspected")');
}

if ((int) $pdo->query('SELECT COUNT(*) FROM requisitions')->fetchColumn() === 0) {
    $pdo->exec('INSERT INTO requisitions (req_number,department_id,req_date,purpose,requesting_officer,status)
        VALUES ("REQ-2026-0198",6,"2026-09-16","Monthly stock replenishment","Dr. Sara Ahmed","Draft")');
    $pdo->exec('INSERT INTO requisitions (req_number,department_id,req_date,purpose,requesting_officer,approving_officer,status)
        VALUES ("REQ-2026-0197",5,"2026-09-15","OT surgical supplies","OT Manager Saleem","Store Officer Imran","Approved")');
}

if ((int) $pdo->query('SELECT COUNT(*) FROM consumption_records')->fetchColumn() === 0) {
    // Dynamically look up OPD and OT department IDs to avoid FK violations on existing DBs
    $opdDeptId = $pdo->query('SELECT id FROM departments WHERE type="OPD" LIMIT 1')->fetchColumn() ?: null;
    $otDeptId  = $pdo->query('SELECT id FROM departments WHERE type="OT" LIMIT 1')->fetchColumn() ?: null;
    $itemId1   = $pdo->query('SELECT id FROM items LIMIT 1 OFFSET 1')->fetchColumn() ?: null;
    $itemId2   = $pdo->query('SELECT id FROM items LIMIT 1 OFFSET 3')->fetchColumn() ?: null;
    if ($itemId1) {
        $pdo->prepare('INSERT INTO consumption_records (type,patient_mr,patient_name,department_id,visit_date,item_id,quantity) VALUES (?,?,?,?,?,?,?)')
            ->execute(['OPD', 'MR-88412', 'Khalid Mehmood', $opdDeptId, '2026-09-16', $itemId1, 2]);
    }
    if ($itemId2) {
        $pdo->prepare('INSERT INTO consumption_records (type,patient_mr,patient_name,department_id,surgery_number,surgeon,procedure_name,item_id,quantity) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute(['OT', 'MR-77391', 'Fatima Bibi', $otDeptId, 'OT-2026-0890', 'Dr. Asad Rizvi', 'Phacoemulsification', $itemId2, 1]);
    }
}

if ((int) $pdo->query('SELECT COUNT(*) FROM transactions')->fetchColumn() === 0) {
    $seed = $pdo->prepare('INSERT INTO transactions (type,reference,party,quantity,status) VALUES(?,?,?,?,?)');
    foreach ([
        ['Purchase order',    'PO-2026-0042',  'Medline Supplies',             120,'Approved'],
        ['Goods received',    'GRN-2026-0038', 'Medline Supplies',              86,'Inspected'],
        ['Department issue',  'ISS-2026-0117', 'Emergency Department',          24,'Issued'],
        ['Patient consumption','PC-2026-0071', 'Ward 3 · Patient #P-1884',       6,'Posted'],
    ] as $r) { $seed->execute($r); }
}

if ((int) $pdo->query('SELECT COUNT(*) FROM users WHERE role="competent_authority"')->fetchColumn() === 0) {
    $pdo->prepare('INSERT INTO users (name,email,role,department,status,password_hash) VALUES(?,?,?,?,?,?)')
        ->execute(['Prof. Dr. Competent Authority', 'director@siovs.edu.pk', 'competent_authority', 'Executive Office', 'Active', password_hash('dir123', PASSWORD_DEFAULT)]);
}

if ((int) $pdo->query('SELECT COUNT(*) FROM item_batches')->fetchColumn() === 0) {
    $itms = $pdo->query('SELECT id, batch_number, expiry_date, quantity, location FROM items WHERE batch_number IS NOT NULL AND batch_number != ""')->fetchAll(PDO::FETCH_ASSOC);
    $bStmt = $pdo->prepare('INSERT OR IGNORE INTO item_batches (item_id, batch_number, expiry_date, quantity, location, status) VALUES(?,?,?,?,?,?)');
    foreach ($itms as $it) {
        $bStmt->execute([$it['id'], $it['batch_number'], $it['expiry_date'], $it['quantity'], $it['location'], 'Active']);
    }
}

if ((int) $pdo->query('SELECT COUNT(*) FROM procedure_templates')->fetchColumn() === 0) {
    $ptStmt = $pdo->prepare('INSERT INTO procedure_templates (procedure_name, description) VALUES(?,?)');
    $ptStmt->execute(['Phacoemulsification (Cataract)', 'Standard phacoemulsification with foldable IOL implantation']);
    $phacoId = (int)$pdo->lastInsertId();
    $ptStmt->execute(['Extracapsular Cataract Extraction (SICS)', 'Small incision cataract surgery with rigid PMMA IOL']);
    $ptStmt->execute(['Pars Plana Vitrectomy (PPV)', 'Vitreoretinal micro-incision surgery']);
    $ptStmt->execute(['Trabeculectomy (Glaucoma)', 'Glaucoma filtration surgery']);

    $pItem = $pdo->prepare('INSERT INTO procedure_template_items (template_id, item_id, default_qty) VALUES(?,?,?)');
    $iolId = $pdo->query("SELECT id FROM items WHERE code='IOL-001' LIMIT 1")->fetchColumn();
    $syrId = $pdo->query("SELECT id FROM items WHERE code='SYR-030' LIMIT 1")->fetchColumn();
    $glvId = $pdo->query("SELECT id FROM items WHERE code='GLV-001' LIMIT 1")->fetchColumn();
    $mskId = $pdo->query("SELECT id FROM items WHERE code='MSK-010' LIMIT 1")->fetchColumn();
    $ansId = $pdo->query("SELECT id FROM items WHERE code='ANS-009' LIMIT 1")->fetchColumn();
    if ($phacoId && $iolId) {
        $pItem->execute([$phacoId, (int)$iolId, 1]);
        if ($syrId) $pItem->execute([$phacoId, (int)$syrId, 2]);
        if ($glvId) $pItem->execute([$phacoId, (int)$glvId, 2]);
        if ($mskId) $pItem->execute([$phacoId, (int)$mskId, 2]);
        if ($ansId) $pItem->execute([$phacoId, (int)$ansId, 1]);
    }
}

if ((int) $pdo->query('SELECT COUNT(*) FROM department_stock')->fetchColumn() === 0) {
    $opdId = $pdo->query('SELECT id FROM departments WHERE type="OPD" LIMIT 1')->fetchColumn();
    $otId  = $pdo->query('SELECT id FROM departments WHERE type="OT" LIMIT 1')->fetchColumn();
    $emgId = $pdo->query('SELECT id FROM departments WHERE type="Emergency" LIMIT 1')->fetchColumn();

    $dsStmt = $pdo->prepare('INSERT OR IGNORE INTO department_stock (department_id, item_id, batch_number, quantity, expiry_date) VALUES(?,?,?,?,?)');
    $syrId = $pdo->query("SELECT id FROM items WHERE code='SYR-030' LIMIT 1")->fetchColumn();
    $cefId = $pdo->query("SELECT id FROM items WHERE code='CEF-014' LIMIT 1")->fetchColumn();
    $iolId = $pdo->query("SELECT id FROM items WHERE code='IOL-001' LIMIT 1")->fetchColumn();
    $glvId = $pdo->query("SELECT id FROM items WHERE code='GLV-001' LIMIT 1")->fetchColumn();

    if ($opdId && $cefId) $dsStmt->execute([(int)$opdId, (int)$cefId, 'B-CEF-2407', 20, '2026-10-04']);
    if ($otId && $iolId)  $dsStmt->execute([(int)$otId,  (int)$iolId, 'IOL-2406', 15, '2028-06-30']);
    if ($otId && $syrId)  $dsStmt->execute([(int)$otId,  (int)$syrId, 'B-SYR-2410', 50, '2028-01-20']);
    if ($emgId && $glvId) $dsStmt->execute([(int)$emgId, (int)$glvId, 'GLV-2408', 10, '2027-08-31']);
}

/* ── Helper functions ────────────────────────────────────────────────────── */
function e(string|int|float|null $v): string {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}
function is_logged_in(): bool { return isset($_SESSION['siovs_user']); }
function current_user(): array { return $_SESSION['siovs_user'] ?? []; }
function log_activity(PDO $pdo, string $action, ?string $entity = null, ?int $entityId = null, array $details = []): void {
    $pdo->prepare('INSERT INTO user_activity_log (user_id,session_id,action,entity,entity_id,details,ip_address) VALUES(?,?,?,?,?,?,?)')
        ->execute([
            current_user()['id'] ?? null,
            session_id() ?: null,
            $action,
            $entity,
            $entityId,
            $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
}
function touch_session(PDO $pdo): void {
    if (!is_logged_in()) return;
    $pdo->prepare('UPDATE user_sessions SET last_seen_at=CURRENT_TIMESTAMP WHERE session_id=? AND ended_at IS NULL')
        ->execute([session_id()]);
}
function resolve_item_id(PDO $pdo, mixed $postedId, mixed $postedCode): ?int {
    $code = trim((string)$postedCode);
    if ($code !== '') {
        $stmt = $pdo->prepare('SELECT id FROM items WHERE code=?');
        $stmt->execute([$code]);
        $resolved = $stmt->fetchColumn();
        if ($resolved !== false) return (int)$resolved;
    }
    $id = (int)$postedId;
    if ($id > 0) {
        $stmt = $pdo->prepare('SELECT id FROM items WHERE id=?');
        $stmt->execute([$id]);
        if ($stmt->fetchColumn()) return $id;
    }
    return null;
}
function require_login(): void {
    if (!is_logged_in()) { header('Location: login.php'); exit; }
}
if (is_logged_in()) {
    touch_session($pdo);
}
function flash(string $msg, string $type = 'success'): void {
    $_SESSION['flash'] = ['message' => $msg, 'type' => $type];
}
function page_link(string $page): string {
    return 'page.php?view=' . rawurlencode($page);
}
function has_role(string ...$roles): bool {
    $currentRole = $_SESSION['siovs_user']['role'] ?? 'guest';
    return in_array($currentRole, $roles, true);
}
function can(string $perm): bool {
    $role = $_SESSION['siovs_user']['role'] ?? 'guest';
    if ($role === 'admin') return true;
    if ($role === $perm) return true; // Direct role check compatibility
    static $map = [
        'store_officer'       => [
            'purchase_orders', // View approved POs to receive GRN against them
            'grn',             // Generate GRN, inspect receipts, accept/reject deliveries
            'inventory',       // Item Master, Stock Ledger, Min/Max/Reorder levels, Bin locations
            'requisitions',    // View & Issue approved department requisitions (FEFO)
            'batches',         // Batch & Expiry management, FEFO tracking
            'returns',         // Verify & accept department returns into store
            'verification',    // Physical stocktaking verification audits
            'adjustments',     // Initiate / submit stock adjustment requests
            'reports',         // Store & inventory reports, registers, valuation
            'departments',     // View departmental virtual stock holdings
            'suppliers',       // Supplier reference directory for receipts
            'assets',          // Store-held assets and equipment receipt
            'reconciliation',  // Manual register vs electronic ledger reconciliation
        ],
        'purchase_officer'    => ['purchase_orders','suppliers','inventory','reports'],
        'dept_incharge'       => ['requisitions','opd_consumption','ipd_consumption','ot_consumption','emergency','reports'],
        'data_entry'          => ['quick-entry','opd_consumption','ipd_consumption','emergency'],
        'dd_finance'          => ['adjustments','reports','verification','transactions','deletions','reconciliation','assets'],
        'internal_audit'      => ['reports','inventory','assets','grn','purchase_orders','adjustments','verification','batches','deletions','reconciliation'],
        'competent_authority' => ['reports','inventory','assets','adjustments','verification','deletions'],
    ];
    return in_array($perm, $map[$role] ?? [], true);
}
function role_label(string $role): string {
    return match($role) {
        'admin'               => 'Administrator',
        'store_officer'       => 'Store Officer',
        'purchase_officer'    => 'Purchase Officer',
        'dept_incharge'       => 'Dept. Incharge',
        'dd_finance'          => 'DD Finance',
        'internal_audit'      => 'Internal Audit',
        'data_entry'          => 'Data Entry',
        'competent_authority' => 'Competent Authority',
        default               => ucfirst($role),
    };
}

/**
 * Synchronized header context & badge counters across all pages
 */
function get_header_context(PDO $pdo, array $u): array {
    $today = date('Y-m-d');
    $role = $u['role'] ?? '';
    $isPurchaseOfficer = ($role === 'purchase_officer');
    $isStoreOfficer    = ($role === 'store_officer');
    $isDDFinance       = ($role === 'dd_finance');

    if ($isPurchaseOfficer) {
        $poDraftCount       = (int)$pdo->query('SELECT COUNT(*) FROM purchase_orders WHERE status = "Draft"')->fetchColumn();
        $poApprovedCount    = (int)$pdo->query('SELECT COUNT(*) FROM purchase_orders WHERE status = "Approved"')->fetchColumn();
        $poPartialCount     = (int)$pdo->query('SELECT COUNT(*) FROM purchase_orders WHERE status = "Partially Received"')->fetchColumn();
        $poAwaitingDelivery = $poApprovedCount + $poPartialCount;
        $poOverdueCount     = (int)$pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE expected_delivery IS NOT NULL AND expected_delivery != '' AND expected_delivery < '{$today}' AND status IN ('Approved', 'Partially Received')")->fetchColumn();
        $reorderCount       = (int)$pdo->query('SELECT COUNT(*) FROM items WHERE quantity <= reorder_level')->fetchColumn();
        $alertCount         = $poOverdueCount + $reorderCount + $poDraftCount;

        $pendingActions = [];
        if ($poOverdueCount > 0) {
            $pendingActions[] = ['label' => "{$poOverdueCount} purchase order" . ($poOverdueCount > 1 ? 's' : '') . ' overdue for delivery', 'link' => page_link('purchase_orders'), 'perm' => 'purchase_orders'];
        }
        if ($poDraftCount > 0) {
            $pendingActions[] = ['label' => "{$poDraftCount} draft purchase order" . ($poDraftCount > 1 ? 's' : '') . ' awaiting authorization', 'link' => page_link('purchase_orders'), 'perm' => 'purchase_orders'];
        }
        if ($reorderCount > 0) {
            $pendingActions[] = ['label' => "{$reorderCount} store item" . ($reorderCount > 1 ? 's' : '') . ' below reorder level', 'link' => page_link('inventory'), 'perm' => 'inventory'];
        }

        return [
            'isPurchaseOfficer'   => true,
            'isStoreOfficer'      => false,
            'isDDFinance'         => false,
            'poDraftCount'        => $poDraftCount,
            'poApprovedCount'     => $poApprovedCount,
            'poPartialCount'      => $poPartialCount,
            'poAwaitingDelivery'  => $poAwaitingDelivery,
            'poOverdueCount'      => $poOverdueCount,
            'reorderCount'        => $reorderCount,
            'alertCount'          => $alertCount,
            'pendingActions'      => $pendingActions,
        ];
    } elseif ($isStoreOfficer) {
        // ── Store Officer Specific Context (Sections 4, 6, 8, 14, 29) ─────────
        $incomingPOs     = (int)$pdo->query('SELECT COUNT(*) FROM purchase_orders WHERE status IN ("Approved", "Partially Received")')->fetchColumn();
        $pendingGRNs     = (int)$pdo->query('SELECT COUNT(*) FROM grns WHERE inspection_status = "Pending"')->fetchColumn();
        $pendingReqs     = (int)$pdo->query('SELECT COUNT(*) FROM requisitions WHERE status = "Approved"')->fetchColumn();
        $pendingReturns  = (int)$pdo->query('SELECT COUNT(*) FROM department_returns WHERE status = "Pending"')->fetchColumn();
        $criticalStock   = (int)$pdo->query('SELECT COUNT(*) FROM items WHERE quantity = 0')->fetchColumn();
        $lowStock        = (int)$pdo->query('SELECT COUNT(*) FROM items WHERE quantity <= reorder_level AND quantity > 0')->fetchColumn();
        $nearExpiry30    = (int)$pdo->query("SELECT COUNT(*) FROM items WHERE expiry_date IS NOT NULL AND expiry_date >= '{$today}' AND expiry_date <= date('{$today}','+30 days')")->fetchColumn();
        $pendingAdj      = (int)$pdo->query('SELECT COUNT(*) FROM stock_adjustments WHERE status = "Pending"')->fetchColumn();
        $alertCount      = $incomingPOs + $pendingGRNs + $pendingReqs + $pendingReturns + $nearExpiry30 + $criticalStock;

        $pendingActions = [];
        if ($incomingPOs > 0) {
            $pendingActions[] = ['label' => "{$incomingPOs} purchase order" . ($incomingPOs > 1 ? 's' : '') . ' arrived · Ready for GRN', 'link' => page_link('purchase_orders'), 'perm' => 'purchase_orders'];
        }
        if ($pendingGRNs > 0) {
            $pendingActions[] = ['label' => "{$pendingGRNs} GRN" . ($pendingGRNs > 1 ? 's' : '') . ' pending physical inspection', 'link' => page_link('grn'), 'perm' => 'grn'];
        }
        if ($pendingReqs > 0) {
            $pendingActions[] = ['label' => "{$pendingReqs} requisition" . ($pendingReqs > 1 ? 's' : '') . ' approved · Awaiting store issue', 'link' => page_link('requisitions'), 'perm' => 'requisitions'];
        }
        if ($pendingReturns > 0) {
            $pendingActions[] = ['label' => "{$pendingReturns} department return" . ($pendingReturns > 1 ? 's' : '') . ' awaiting verification', 'link' => page_link('returns'), 'perm' => 'returns'];
        }
        if ($nearExpiry30 > 0) {
            $pendingActions[] = ['label' => "{$nearExpiry30} item" . ($nearExpiry30 > 1 ? 's' : '') . ' expiring in 30 days · Review FEFO', 'link' => page_link('batches'), 'perm' => 'batches'];
        }
        if ($criticalStock > 0) {
            $pendingActions[] = ['label' => "{$criticalStock} item" . ($criticalStock > 1 ? 's' : '') . ' out of stock in Main Store', 'link' => page_link('inventory'), 'perm' => 'inventory'];
        }

        return [
            'isPurchaseOfficer'   => false,
            'isStoreOfficer'      => true,
            'isDDFinance'         => false,
            'incomingPOs'         => $incomingPOs,
            'openPOs'             => $incomingPOs,
            'pendingGRNs'         => $pendingGRNs,
            'pendingReqs'         => $pendingReqs,
            'pendingReturns'      => $pendingReturns,
            'criticalStock'       => $criticalStock,
            'lowStock'            => $lowStock,
            'nearExpiry30'        => $nearExpiry30,
            'pendingAdj'          => $pendingAdj,
            'alertCount'          => $alertCount,
            'pendingActions'      => $pendingActions,
        ];
    } elseif ($role === 'dd_finance') {
        // ── DD Finance Specific Context (Financial Oversight & Approvals) ──
        $pendingAdj       = (int)$pdo->query('SELECT COUNT(*) FROM stock_adjustments WHERE status = "Pending"')->fetchColumn();
        $pendingDeletions = (int)$pdo->query('SELECT COUNT(*) FROM deletion_requests WHERE status = "Pending"')->fetchColumn();
        $pendingVer       = (int)$pdo->query('SELECT COUNT(*) FROM stock_verifications WHERE status = "Submitted"')->fetchColumn();
        $openPOs          = (int)$pdo->query('SELECT COUNT(*) FROM purchase_orders WHERE status NOT IN ("Delivered","Cancelled")')->fetchColumn();
        $stockValue       = (float)$pdo->query(
            "SELECT COALESCE(SUM(i.quantity * COALESCE(
                (SELECT g.rate FROM grn_items g WHERE g.item_id=i.id AND g.rate>0 ORDER BY g.id DESC LIMIT 1),
                0
            )),0) FROM items i"
        )->fetchColumn();
        $alertCount       = $pendingAdj + $pendingDeletions;

        $pendingActions = [];
        if ($pendingAdj > 0) {
            $pendingActions[] = ['label' => "{$pendingAdj} stock adjustment" . ($pendingAdj > 1 ? 's' : '') . ' awaiting financial authorization', 'link' => page_link('adjustments'), 'perm' => 'adjustments'];
        }
        if ($pendingDeletions > 0) {
            $pendingActions[] = ['label' => "{$pendingDeletions} deletion request" . ($pendingDeletions > 1 ? 's' : '') . ' pending audit authorization', 'link' => page_link('deletions'), 'perm' => 'deletions'];
        }
        if ($pendingVer > 0) {
            $pendingActions[] = ['label' => "{$pendingVer} physical stock verification" . ($pendingVer > 1 ? 's' : '') . ' submitted for financial review', 'link' => page_link('verification'), 'perm' => 'verification'];
        }

        return [
            'isPurchaseOfficer'   => false,
            'isStoreOfficer'      => false,
            'isDDFinance'         => true,
            'pendingAdj'          => $pendingAdj,
            'pendingDeletions'    => $pendingDeletions,
            'pendingVer'          => $pendingVer,
            'openPOs'             => $openPOs,
            'stockValue'          => $stockValue,
            'alertCount'          => $alertCount,
            'pendingActions'      => $pendingActions,
        ];
    } else {
        $criticalStock = (int)$pdo->query('SELECT COUNT(*) FROM items WHERE quantity = 0')->fetchColumn();
        $nearExpiry30  = (int)$pdo->query("SELECT COUNT(*) FROM items WHERE expiry_date IS NOT NULL AND expiry_date >= '{$today}' AND expiry_date <= date('{$today}','+30 days')")->fetchColumn();
        $pendingGRNs   = (int)$pdo->query('SELECT COUNT(*) FROM grns WHERE inspection_status = "Pending"')->fetchColumn();
        $pendingReqs   = (int)$pdo->query('SELECT COUNT(*) FROM requisitions WHERE status IN ("Draft","Approved")')->fetchColumn();
        $pendingAdj    = (int)$pdo->query('SELECT COUNT(*) FROM stock_adjustments WHERE status = "Pending"')->fetchColumn();
        $openPOs       = (int)$pdo->query('SELECT COUNT(*) FROM purchase_orders WHERE status NOT IN ("Delivered","Cancelled")')->fetchColumn();
        $alertCount    = $nearExpiry30 + $criticalStock + $pendingAdj + $pendingGRNs;

        $pendingActions = [];
        if ($pendingGRNs > 0) $pendingActions[] = ['label' => "{$pendingGRNs} GRN" . ($pendingGRNs > 1 ? 's' : '') . ' pending inspection',         'link' => page_link('grn'),          'perm' => 'grn'];
        if ($pendingReqs > 0) $pendingActions[] = ['label' => "{$pendingReqs} requisition" . ($pendingReqs > 1 ? 's' : '') . ' awaiting action',     'link' => page_link('requisitions'), 'perm' => 'requisitions'];
        if ($pendingAdj  > 0) $pendingActions[] = ['label' => "{$pendingAdj} stock adjustment" . ($pendingAdj > 1 ? 's' : '') . ' pending approval', 'link' => page_link('adjustments'),  'perm' => 'adjustments'];

        return [
            'isPurchaseOfficer'   => false,
            'isStoreOfficer'      => false,
            'isDDFinance'         => false,
            'criticalStock'       => $criticalStock,
            'nearExpiry30'        => $nearExpiry30,
            'pendingGRNs'         => $pendingGRNs,
            'pendingReqs'         => $pendingReqs,
            'pendingAdj'          => $pendingAdj,
            'openPOs'             => $openPOs,
            'alertCount'          => $alertCount,
            'pendingActions'      => $pendingActions,
        ];
    }
}

/**
 * ── Phase 0: Universal Stock Movement Engine ────────────────────────────────
 */
function record_stock_movement(
    PDO $pdo,
    string $type,
    string $refNo,
    int $itemId,
    int $qty,
    ?string $batchNumber = null,
    ?string $expiryDate = null,
    ?int $deptId = null,
    ?int $supplierId = null,
    ?string $patientOrNote = null,
    ?int $userId = null,
    float $unitCost = 0.0
): bool {
    if ($qty <= 0) return false;
    $batchNumber = trim((string)$batchNumber);

    $stmt = $pdo->prepare('SELECT quantity FROM items WHERE id=?');
    $stmt->execute([$itemId]);
    $currentMainQty = (int)$stmt->fetchColumn();

    $sourceType    = 'MAIN_STORE';
    $sourceId      = null;
    $destType      = 'MAIN_STORE';
    $destId        = null;
    $balanceBefore = $currentMainQty;
    $balanceAfter  = $currentMainQty;

    switch ($type) {
        case 'GRN_RECEIPT':
            $sourceType = 'VENDOR';
            $sourceId   = $supplierId;
            $destType   = 'MAIN_STORE';
            $destId     = null;
            $balanceAfter = $currentMainQty + $qty;

            $pdo->prepare('UPDATE items SET quantity = quantity + ? WHERE id = ?')->execute([$qty, $itemId]);

            if ($batchNumber !== '') {
                $bStmt = $pdo->prepare('INSERT INTO item_batches (item_id, batch_number, expiry_date, quantity, unit_cost, supplier_id, status)
                    VALUES (?, ?, ?, ?, ?, ?, "Active")
                    ON CONFLICT(item_id, batch_number) DO UPDATE SET
                        quantity = quantity + excluded.quantity,
                        expiry_date = COALESCE(excluded.expiry_date, item_batches.expiry_date),
                        unit_cost = CASE WHEN excluded.unit_cost > 0 THEN excluded.unit_cost ELSE item_batches.unit_cost END,
                        status = "Active"');
                $bStmt->execute([$itemId, $batchNumber, $expiryDate, $qty, $unitCost, $supplierId]);
            }
            break;

        case 'STORE_ISSUE':
            $sourceType = 'MAIN_STORE';
            $sourceId   = null;
            $destType   = 'DEPARTMENT';
            $destId     = $deptId;
            $balanceAfter = max(0, $currentMainQty - $qty);

            $pdo->prepare('UPDATE items SET quantity = MAX(0, quantity - ?) WHERE id = ?')->execute([$qty, $itemId]);

            if ($batchNumber !== '') {
                $pdo->prepare('UPDATE item_batches SET quantity = MAX(0, quantity - ?) WHERE item_id = ? AND batch_number = ?')
                    ->execute([$qty, $itemId, $batchNumber]);
            }

            if ($deptId) {
                $dsStmt = $pdo->prepare('INSERT INTO department_stock (department_id, item_id, batch_number, expiry_date, quantity, updated_at)
                    VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                    ON CONFLICT(department_id, item_id, batch_number) DO UPDATE SET
                        quantity = quantity + excluded.quantity,
                        expiry_date = COALESCE(excluded.expiry_date, department_stock.expiry_date),
                        updated_at = CURRENT_TIMESTAMP');
                $dsStmt->execute([$deptId, $itemId, $batchNumber, $expiryDate, $qty]);
            }
            break;

        case 'DEPT_CONSUMPTION':
            $sourceType = 'DEPARTMENT';
            $sourceId   = $deptId;
            $destType   = 'PATIENT';
            $destId     = null;

            if ($deptId) {
                if ($batchNumber === '') {
                    $fefo = get_fefo_batches($pdo, $itemId, $deptId);
                    if (!empty($fefo)) {
                        $batchNumber = (string)$fefo[0]['batch_number'];
                        $expiryDate = $fefo[0]['expiry_date'] ?? $expiryDate;
                    }
                }
                $dqStmt = $pdo->prepare('SELECT quantity FROM department_stock WHERE department_id=? AND item_id=? AND batch_number=?');
                $dqStmt->execute([$deptId, $itemId, $batchNumber]);
                $curDeptQty = (int)$dqStmt->fetchColumn();
                $balanceBefore = $curDeptQty;
                $balanceAfter  = max(0, $curDeptQty - $qty);

                $upStmt = $pdo->prepare('UPDATE department_stock SET quantity = MAX(0, quantity - ?), updated_at = CURRENT_TIMESTAMP WHERE department_id = ? AND item_id = ? AND batch_number = ?');
                $upStmt->execute([$qty, $deptId, $itemId, $batchNumber]);
                if ($upStmt->rowCount() === 0) {
                    $pdo->prepare('INSERT INTO department_stock (department_id, item_id, batch_number, expiry_date, quantity, updated_at) VALUES (?, ?, ?, ?, 0, CURRENT_TIMESTAMP)')
                        ->execute([$deptId, $itemId, $batchNumber, $expiryDate]);
                }
            } else {
                $sourceType = 'MAIN_STORE';
                $destType   = 'PATIENT';
                $balanceBefore = $currentMainQty;
                $balanceAfter  = max(0, $currentMainQty - $qty);
                $pdo->prepare('UPDATE items SET quantity = MAX(0, quantity - ?) WHERE id = ?')->execute([$qty, $itemId]);
                if ($batchNumber !== '') {
                    $pdo->prepare('UPDATE item_batches SET quantity = MAX(0, quantity - ?) WHERE item_id = ? AND batch_number = ?')
                        ->execute([$qty, $itemId, $batchNumber]);
                }
            }
            break;

        case 'DEPT_RETURN':
            $sourceType = 'DEPARTMENT';
            $sourceId   = $deptId;
            $destType   = 'MAIN_STORE';
            $destId     = null;
            $balanceAfter = $currentMainQty + $qty;

            if ($deptId) {
                if (empty($expiryDate) && $batchNumber !== '') {
                    $expCheck = $pdo->prepare('SELECT expiry_date FROM department_stock WHERE department_id = ? AND item_id = ? AND batch_number = ? LIMIT 1');
                    $expCheck->execute([$deptId, $itemId, $batchNumber]);
                    $expiryDate = $expCheck->fetchColumn() ?: null;
                }
                $pdo->prepare('UPDATE department_stock SET quantity = MAX(0, quantity - ?), updated_at = CURRENT_TIMESTAMP WHERE department_id = ? AND item_id = ? AND batch_number = ?')
                    ->execute([$qty, $deptId, $itemId, $batchNumber]);
            }
            $pdo->prepare('UPDATE items SET quantity = quantity + ? WHERE id = ?')->execute([$qty, $itemId]);
            if ($batchNumber !== '') {
                $bUp = $pdo->prepare('UPDATE item_batches SET quantity = quantity + ? WHERE item_id = ? AND batch_number = ?');
                $bUp->execute([$qty, $itemId, $batchNumber]);
                if ($bUp->rowCount() === 0) {
                    $pdo->prepare('INSERT INTO item_batches (item_id, batch_number, expiry_date, quantity, status) VALUES (?, ?, ?, ?, "Active")')
                        ->execute([$itemId, $batchNumber, $expiryDate, $qty]);
                }
            }
            break;

        case 'ADJUSTMENT_IN':
            $sourceType = 'ADJUSTMENT';
            $destType   = 'MAIN_STORE';
            $balanceAfter = $currentMainQty + $qty;
            $pdo->prepare('UPDATE items SET quantity = quantity + ? WHERE id = ?')->execute([$qty, $itemId]);
            if ($batchNumber !== '') {
                $pdo->prepare('UPDATE item_batches SET quantity = quantity + ? WHERE item_id = ? AND batch_number = ?')
                    ->execute([$qty, $itemId, $batchNumber]);
            }
            break;

        case 'ADJUSTMENT_OUT':
            $sourceType = 'MAIN_STORE';
            $destType   = 'ADJUSTMENT';
            $balanceAfter = max(0, $currentMainQty - $qty);
            $pdo->prepare('UPDATE items SET quantity = MAX(0, quantity - ?) WHERE id = ?')->execute([$qty, $itemId]);
            if ($batchNumber !== '') {
                $pdo->prepare('UPDATE item_batches SET quantity = MAX(0, quantity - ?) WHERE item_id = ? AND batch_number = ?')
                    ->execute([$qty, $itemId, $batchNumber]);
            }
            break;
    }

    $pdo->prepare('UPDATE items SET status = CASE WHEN quantity = 0 THEN "Out of stock" WHEN quantity <= reorder_level THEN "Low stock" ELSE "Available" END WHERE id=?')
        ->execute([$itemId]);

    $lStmt = $pdo->prepare('INSERT INTO stock_ledger (transaction_type, reference_no, item_id, batch_number, source_type, source_id, dest_type, dest_id, quantity, balance_before, balance_after, user_id, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $lStmt->execute([$type, $refNo, $itemId, $batchNumber, $sourceType, $sourceId, $destType, $destId, $qty, $balanceBefore, $balanceAfter, $userId, $patientOrNote]);

    return true;
}

/**
 * First Expiry, First Out (FEFO) Batch Finder
 */
function get_fefo_batches(PDO $pdo, int $itemId, ?int $deptId = null): array {
    if ($deptId !== null && $deptId > 0) {
        $stmt = $pdo->prepare('SELECT ds.batch_number, ds.quantity, ds.expiry_date, ib.manufacturing_date, ib.unit_cost
            FROM department_stock ds
            LEFT JOIN item_batches ib ON ib.item_id = ds.item_id AND ib.batch_number = ds.batch_number
            WHERE ds.department_id = ? AND ds.item_id = ? AND ds.quantity > 0
            ORDER BY CASE WHEN ds.expiry_date IS NULL OR ds.expiry_date = "" THEN "9999-99-99" ELSE ds.expiry_date END ASC');
        $stmt->execute([$deptId, $itemId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmt = $pdo->prepare('SELECT batch_number, quantity, expiry_date, manufacturing_date, unit_cost, location
        FROM item_batches
        WHERE item_id = ? AND quantity > 0
        ORDER BY CASE WHEN expiry_date IS NULL OR expiry_date = "" THEN "9999-99-99" ELSE expiry_date END ASC');
    $stmt->execute([$itemId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Submit Deletion Request (Ordinary users)
 */
function request_deletion(
    PDO $pdo,
    string $tableName,
    int $recordId,
    string $refNo,
    string $section,
    string $description,
    int $qty,
    float $value,
    string $reason,
    ?string $docPath = null,
    ?int $userId = null
): string {
    $reqNo = next_number($pdo, 'DEL', 'deletion_requests', 'request_no');
    $uId = $userId ?? (current_user()['id'] ?? 1);
    $stmt = $pdo->prepare('INSERT INTO deletion_requests (
        request_no, table_name, record_id, reference_no, section, description,
        quantity, value, reason, supporting_doc, requested_by, status
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "Pending")');
    $stmt->execute([
        $reqNo, $tableName, $recordId, $refNo, $section, $description,
        $qty, $value, trim($reason), $docPath, $uId
    ]);

    try {
        $pdo->prepare("UPDATE {$tableName} SET status='Deletion Requested' WHERE id=?")->execute([$recordId]);
    } catch (Throwable $e) {}

    log_activity($pdo, 'deletion_requested', $tableName, $recordId, ['request_no' => $reqNo, 'reason' => $reason]);
    return $reqNo;
}

/**
 * DD Finance Deletion & Stock Reversal Engine
 */
function approve_deletion_request(PDO $pdo, int $requestId, int $ddFinanceUserId, string $remarks): bool {
    $stmt = $pdo->prepare('SELECT * FROM deletion_requests WHERE id = ? AND status = "Pending"');
    $stmt->execute([$requestId]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$req) return false;

    $table = $req['table_name'];
    $recId = (int)$req['record_id'];

    if ($table === 'consumption_records') {
        $cStmt = $pdo->prepare('SELECT * FROM consumption_records WHERE id = ?');
        $cStmt->execute([$recId]);
        $rec = $cStmt->fetch(PDO::FETCH_ASSOC);
        if ($rec && ($rec['status'] ?? 'Active') !== 'Cancelled') {
            if (!empty($rec['department_id'])) {
                $dsStmt = $pdo->prepare('INSERT INTO department_stock (department_id, item_id, batch_number, quantity, updated_at)
                    VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
                    ON CONFLICT(department_id, item_id, batch_number) DO UPDATE SET
                        quantity = quantity + excluded.quantity,
                        updated_at = CURRENT_TIMESTAMP');
                $dsStmt->execute([$rec['department_id'], $rec['item_id'], $rec['batch_number'] ?? '', (int)$rec['quantity']]);
            }
            $lStmt = $pdo->prepare('INSERT INTO stock_ledger (transaction_type, reference_no, item_id, batch_number, source_type, source_id, dest_type, dest_id, quantity, user_id, notes)
                VALUES ("REVERSAL", ?, ?, ?, "CONSUMPTION_REVERSAL", ?, "DEPARTMENT", ?, ?, ?, ?)');
            $lStmt->execute([
                $req['reference_no'], $rec['item_id'], $rec['batch_number'] ?? '',
                null, $rec['department_id'], (int)$rec['quantity'], $ddFinanceUserId,
                'DD Finance approved deletion: ' . $remarks
            ]);
            $pdo->prepare('UPDATE consumption_records SET status="Cancelled" WHERE id=?')->execute([$recId]);
        }
    } elseif ($table === 'requisitions') {
        $items = $pdo->prepare('SELECT * FROM requisition_items WHERE req_id = ?');
        $items->execute([$recId]);
        $rStmt = $pdo->prepare('SELECT department_id FROM requisitions WHERE id = ?');
        $rStmt->execute([$recId]);
        $deptId = (int)$rStmt->fetchColumn();

        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $ri) {
            $iq = (int)$ri['issued_quantity'];
            if ($iq > 0) {
                $pdo->prepare('UPDATE items SET quantity = quantity + ? WHERE id = ?')->execute([$iq, $ri['item_id']]);
                if ($deptId) {
                    $pdo->prepare('UPDATE department_stock SET quantity = MAX(0, quantity - ?) WHERE department_id = ? AND item_id = ?')
                        ->execute([$iq, $deptId, $ri['item_id']]);
                }
                $pdo->prepare('INSERT INTO stock_ledger (transaction_type, reference_no, item_id, source_type, source_id, dest_type, dest_id, quantity, user_id, notes)
                    VALUES ("REVERSAL", ?, ?, "DEPARTMENT", ?, "MAIN_STORE", NULL, ?, ?, ?)')
                    ->execute([$req['reference_no'], $ri['item_id'], $deptId, $iq, $ddFinanceUserId, 'DD Finance Requisition Cancel Reversal: ' . $remarks]);
            }
        }
        $pdo->prepare('UPDATE requisitions SET status="Cancelled" WHERE id=?')->execute([$recId]);
    } elseif ($table === 'grns') {
        $gItems = $pdo->prepare('SELECT * FROM grn_items WHERE grn_id = ?');
        $gItems->execute([$recId]);
        foreach ($gItems->fetchAll(PDO::FETCH_ASSOC) as $gi) {
            $aq = (int)$gi['quantity_accepted'];
            if ($aq > 0) {
                $pdo->prepare('UPDATE items SET quantity = MAX(0, quantity - ?) WHERE id = ?')->execute([$aq, $gi['item_id']]);
                if (!empty($gi['batch_number'])) {
                    $pdo->prepare('UPDATE item_batches SET quantity = MAX(0, quantity - ?) WHERE item_id = ? AND batch_number = ?')
                        ->execute([$aq, $gi['item_id'], $gi['batch_number']]);
                }
                $pdo->prepare('INSERT INTO stock_ledger (transaction_type, reference_no, item_id, batch_number, source_type, dest_type, quantity, user_id, notes)
                    VALUES ("REVERSAL", ?, ?, ?, "MAIN_STORE", "VENDOR_REVERSAL", ?, ?, ?)')
                    ->execute([$req['reference_no'], $gi['item_id'], $gi['batch_number'] ?? '', $aq, $ddFinanceUserId, 'DD Finance GRN Cancel Reversal: ' . $remarks]);
            }
        }
        $pdo->prepare('UPDATE grns SET status="Cancelled" WHERE id=?')->execute([$recId]);
    } elseif ($table === 'stock_adjustments') {
        $aStmt = $pdo->prepare('SELECT * FROM stock_adjustments WHERE id = ?');
        $aStmt->execute([$recId]);
        $adj = $aStmt->fetch(PDO::FETCH_ASSOC);
        if ($adj) {
            $aq = (int)$adj['quantity'];
            $revQty = -$aq;
            if ($revQty > 0) {
                $pdo->prepare('UPDATE items SET quantity = quantity + ? WHERE id = ?')->execute([$revQty, $adj['item_id']]);
            } else {
                $pdo->prepare('UPDATE items SET quantity = MAX(0, quantity - ?) WHERE id = ?')->execute([abs($revQty), $adj['item_id']]);
            }
            $pdo->prepare('UPDATE stock_adjustments SET status="Cancelled" WHERE id=?')->execute([$recId]);
        }
    }

    $pdo->prepare('UPDATE deletion_requests SET status="Approved", reviewed_by=?, reviewed_at=CURRENT_TIMESTAMP, review_remarks=?, stock_reversed=1 WHERE id=?')
        ->execute([$ddFinanceUserId, $remarks, $requestId]);

    log_activity($pdo, 'deletion_approved', $table, $recId, ['request_id' => $requestId, 'remarks' => $remarks]);
    return true;
}

/**
 * DD Finance Deletion Rejection Engine
 */
function reject_deletion_request(PDO $pdo, int $requestId, int $ddFinanceUserId, string $remarks): bool {
    $stmt = $pdo->prepare('SELECT * FROM deletion_requests WHERE id = ? AND status = "Pending"');
    $stmt->execute([$requestId]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$req) return false;

    $table = $req['table_name'];
    $recId = (int)$req['record_id'];

    try {
        $pdo->prepare("UPDATE {$table} SET status = CASE WHEN status = 'Deletion Requested' THEN 'Active' ELSE status END WHERE id = ?")
            ->execute([$recId]);
    } catch (Throwable $e) {}

    $pdo->prepare('UPDATE deletion_requests SET status = "Rejected", reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP, review_remarks = ? WHERE id = ?')
        ->execute([$ddFinanceUserId, $remarks, $requestId]);

    log_activity($pdo, 'deletion_rejected', $table, $recId, ['request_id' => $requestId, 'remarks' => $remarks]);
    return true;
}
function next_number(PDO $pdo, string $prefix, string $table, string $col): string {
    $year = date('Y');
    $stmt = $pdo->prepare("SELECT {$col} FROM {$table} WHERE {$col} LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute(["{$prefix}-{$year}-%"]);
    $last = $stmt->fetchColumn();
    $seq  = $last ? ((int) substr((string)$last, -4) + 1) : 1;
    return sprintf('%s-%s-%04d', $prefix, $year, $seq);
}
function reject_expired_items(PDO $pdo, array $itemIds, string $view): void {
    $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
    if (!$itemIds) return;
    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $stmt = $pdo->prepare("SELECT code, name FROM items WHERE id IN ({$placeholders}) AND expiry_date IS NOT NULL AND expiry_date < date('now')");
    $stmt->execute($itemIds);
    $expired = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($expired) {
        $labels = array_map(static fn(array $item): string => "{$item['code']} — {$item['name']}", $expired);
        flash('Expired medicine/item cannot be issued: ' . implode(', ', $labels), 'error');
        header('Location: ' . page_link($view));
        exit;
    }
}
function reject_medicines_without_expiry(PDO $pdo, array $itemIds, array $expiryDates, string $view): void {
    $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
    if (!$itemIds) return;
    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $stmt = $pdo->prepare("SELECT id, code, name, category FROM items WHERE id IN ({$placeholders})");
    $stmt->execute($itemIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $index = array_search((string)$item['id'], array_map('strval', $itemIds), true);
        $expiry = $expiryDates[$index] ?? null;
        if (in_array($item['category'], ['Medicines', 'Ophthalmic Medicines'], true) && !$expiry) {
            flash("Expiry date is required for medicine {$item['code']} — {$item['name']}.", 'error');
            header('Location: ' . page_link($view));
            exit;
        }
    }
}
function pill(string $status): string {
    $cls = match(strtolower($status)) {
        'approved','posted','issued','inspected','available','active','in service','delivered' => 'available',
        'low stock','pending','draft','amc due soon','under repair','deletion requested','partially received' => 'attention',
        'critical','expired','cancelled','condemned','disposed','rejected' => 'danger',
        default => 'neutral',
    };
    return "<span class=\"pill {$cls}\">" . e($status) . "</span>";
}

function save_uploaded_document(PDO $pdo, string $entityType, int $entityId, string $inputName = 'document'): ?int {
    if (empty($_FILES[$inputName]['name']) || empty($_FILES[$inputName]['tmp_name'])) {
        return null;
    }
    $file = $_FILES[$inputName];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $root = dirname(__DIR__);
    $uploadDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }

    $ext = pathinfo((string)$file['name'], PATHINFO_EXTENSION);
    $safeName = sprintf('%s_%d_%s.%s', $entityType, $entityId, date('Ymd_His'), $ext ? strtolower($ext) : 'bin');
    $dest = $uploadDir . DIRECTORY_SEPARATOR . $safeName;

    if (move_uploaded_file($file['tmp_name'], $dest)) {
        $stmt = $pdo->prepare('INSERT INTO document_attachments (entity_type, entity_id, file_name, file_path, file_size, mime_type, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $entityType,
            $entityId,
            basename((string)$file['name']),
            'storage/uploads/' . $safeName,
            (int)$file['size'],
            $file['type'] ?? 'application/octet-stream',
            current_user()['id'] ?? null
        ]);
        return (int)$pdo->lastInsertId();
    }
    return null;
}
