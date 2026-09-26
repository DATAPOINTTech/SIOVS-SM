<?php
declare(strict_types=1);

session_start();

$root   = dirname(__DIR__);
$dbPath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'siovs.sqlite';
if (!is_dir(dirname($dbPath))) {
    mkdir(dirname($dbPath), 0775, true);
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
function can(string $perm): bool {
    $role = $_SESSION['siovs_user']['role'] ?? 'guest';
    if ($role === 'admin') return true;
    static $map = [
        'store_officer'    => ['grn','inventory','requisitions','adjustments','verification','reports','batches','assets','returns','quick-entry','suppliers','opd_consumption','ipd_consumption','ot_consumption','emergency','departments','users'],
        'purchase_officer' => ['purchase_orders','suppliers','reports','grn'],
        'dept_incharge'    => ['requisitions','opd_consumption','ipd_consumption','ot_consumption','emergency','reports'],
        'data_entry'       => ['quick-entry','opd_consumption','ipd_consumption','emergency'],
        'dd_finance'       => ['adjustments','reports','verification','transactions'],
        'internal_audit'   => ['reports','inventory','assets','grn','purchase_orders','adjustments','verification','batches'],
    ];
    return in_array($perm, $map[$role] ?? [], true);
}
function role_label(string $role): string {
    return match($role) {
        'admin'           => 'Administrator',
        'store_officer'   => 'Store Officer',
        'purchase_officer'=> 'Purchase Officer',
        'dept_incharge'   => 'Dept. Incharge',
        'dd_finance'      => 'DD Finance',
        'internal_audit'  => 'Internal Audit',
        'data_entry'      => 'Data Entry',
        default           => ucfirst($role),
    };
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
        'low stock','pending','draft','amc due soon','under repair' => 'attention',
        'critical','expired','cancelled','condemned','disposed' => 'danger',
        default => 'neutral',
    };
    return "<span class=\"pill {$cls}\">" . e($status) . "</span>";
}
