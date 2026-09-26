<?php
declare(strict_types=1);
require __DIR__ . '/config/bootstrap.php';

$allowed = [
    'reports','suppliers','purchase_orders','grn','inventory','batches','adjustments',
    'verification','requisitions','opd_consumption','ipd_consumption','ot_consumption',
    'emergency','assets','departments','returns','users','quick-entry',
];
$view = $_GET['view'] ?? 'reports';
if (!in_array($view, $allowed, true)) { http_response_code(404); exit('Page not found'); }

/* ───────────────────────────────── POST handlers ───────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    require_login();
    log_activity($pdo, 'form_submitted', $action, null, ['view' => $view]);

    // ── Supplier ─────────────────────────────────────────────────────
    if ($action === 'supplier') {
        $pdo->prepare('INSERT INTO suppliers (code,name,contact_person,phone,email,address,gst_number,status) VALUES(?,?,?,?,?,?,?,?)')
            ->execute([trim($_POST['code']), trim($_POST['name']), trim($_POST['contact_person']),
                       trim($_POST['phone']), trim($_POST['email']), trim($_POST['address']),
                       trim($_POST['gst_number']), $_POST['status'] ?? 'Active']);
        flash('Supplier added.'); header('Location: ' . page_link('suppliers')); exit;
    }

    // ── Purchase Order ───────────────────────────────────────────────
    if ($action === 'purchase_order') {
        $pn = next_number($pdo, 'PO', 'purchase_orders', 'po_number');
        $pdo->prepare('INSERT INTO purchase_orders (po_number,po_date,supplier_id,purchase_reference,tender_reference,expected_delivery,notes,status,created_by) VALUES(?,?,?,?,?,?,?,?,?)')
            ->execute([$pn, $_POST['po_date'], (int)$_POST['supplier_id'],
                       trim($_POST['purchase_reference']), trim($_POST['tender_reference']),
                       $_POST['expected_delivery'], trim($_POST['notes']), 'Draft',
                       current_user()['id'] ?? null]);
        // Add line items
        $poId = (int) $pdo->lastInsertId();
        $iStmt = $pdo->prepare('INSERT INTO po_items (po_id,item_id,quantity_ordered,unit,rate,taxes,total_value) VALUES(?,?,?,?,?,?,?)');
        foreach (($_POST['item_id'] ?? []) as $k => $iid) {
            $iid = resolve_item_id($pdo, $iid, $_POST['item_code'][$k] ?? '');
            $qty  = (int) ($_POST['qty'][$k]  ?? 0);
            $rate = (float)($_POST['rate'][$k] ?? 0);
            $tax  = (float)($_POST['tax'][$k]  ?? 0);
            if ($iid && $qty > 0) {
                $tv = ($rate * $qty) + $tax;
                $iStmt->execute([$poId, (int)$iid, $qty, $_POST['unit'][$k] ?? '', $rate, $tax, $tv]);
            }
        }
        // Update total value
        $pdo->prepare('UPDATE purchase_orders SET total_value=(SELECT COALESCE(SUM(total_value),0) FROM po_items WHERE po_id=?) WHERE id=?')
            ->execute([$poId, $poId]);
        flash("Purchase order {$pn} created."); header('Location: ' . page_link('purchase_orders')); exit;
    }

    // ── GRN ──────────────────────────────────────────────────────────
    if ($action === 'grn') {
        $gn = next_number($pdo, 'GRN', 'grns', 'grn_number');
        $resolvedItems = [];
        foreach (($_POST['item_id'] ?? []) as $k => $postedId) {
            $resolvedItems[$k] = resolve_item_id($pdo, $postedId, $_POST['item_code'][$k] ?? '');
        }
        $_POST['item_id'] = $resolvedItems;
        reject_medicines_without_expiry($pdo, $resolvedItems, $_POST['exp_date'] ?? [], 'grn');
        $receivedItemIds = [];
        foreach ($resolvedItems as $k => $resolvedId) {
            if ($resolvedId && (int)($_POST['qty_received'][$k] ?? 0) > 0) {
                $receivedItemIds[] = $resolvedId;
            }
        }
        if (!$receivedItemIds) {
            flash('Scan or select at least one received item and enter its quantity.', 'error');
            header('Location: ' . page_link('grn'));
            exit;
        }
        $pdo->prepare('INSERT INTO grns (grn_number,po_id,supplier_id,challan_number,challan_date,received_date,inspection_status,remarks,created_by) VALUES(?,?,?,?,?,?,?,?,?)')
            ->execute([$gn, ($_POST['po_id'] ?: null), ($_POST['supplier_id'] ?: null),
                       trim($_POST['challan_number']), $_POST['challan_date'],
                       $_POST['received_date'], $_POST['inspection_status'],
                       trim($_POST['remarks']), current_user()['id'] ?? null]);
        $grnId = (int) $pdo->lastInsertId();
        $gStmt = $pdo->prepare('INSERT INTO grn_items (grn_id,item_id,batch_number,manufacturing_date,expiry_date,quantity_ordered,quantity_received,quantity_accepted,quantity_rejected,rate) VALUES(?,?,?,?,?,?,?,?,?,?)');
        foreach ($resolvedItems as $k => $iid) {
            $acc = (int)($_POST['qty_accepted'][$k] ?? 0);
            $rec = (int)($_POST['qty_received'][$k] ?? 0);
            $rej = (int)($_POST['qty_rejected'][$k] ?? 0);
            if ($iid && $rec > 0) {
                $gStmt->execute([$grnId, (int)$iid, trim($_POST['batch'][$k] ?? ''),
                                 $_POST['mfg_date'][$k] ?? null, $_POST['exp_date'][$k] ?? null,
                                 (int)($_POST['qty_ordered'][$k] ?? 0), $rec, $acc, $rej,
                                 (float)($_POST['rate'][$k] ?? 0)]);
                // Update stock on accepted quantity
                if ($acc > 0) {
                    $pdo->prepare('UPDATE items SET quantity = quantity + ? WHERE id = ?')->execute([$acc, (int)$iid]);
                    if (!empty($_POST['supplier_id'])) {
                        $pdo->prepare('UPDATE items SET supplier_id=? WHERE id=?')
                            ->execute([(int)$_POST['supplier_id'], (int)$iid]);
                    }
                    // Update batch/expiry on item
                    if (!empty($_POST['batch'][$k])) {
                        $pdo->prepare('UPDATE items SET batch_number=?, expiry_date=? WHERE id=?')
                            ->execute([trim($_POST['batch'][$k]), $_POST['exp_date'][$k] ?? null, (int)$iid]);
                    }
                    // Update status
                    $pdo->prepare('UPDATE items SET status = CASE WHEN quantity = 0 THEN "Out of stock" WHEN quantity <= reorder_level THEN "Low stock" ELSE "Available" END WHERE id=?')
                        ->execute([(int)$iid]);
                }
            }
        }
        // Update PO status
        if (!empty($_POST['po_id'])) {
            $pdo->prepare('UPDATE purchase_orders SET status="Delivered" WHERE id=?')->execute([(int)$_POST['po_id']]);
        }
        flash("GRN {$gn} created and stock updated."); header('Location: ' . page_link('grn')); exit;
    }

    // ── Requisition ──────────────────────────────────────────────────
    if ($action === 'requisition') {
        $rn = next_number($pdo, 'REQ', 'requisitions', 'req_number');
        $pdo->prepare('INSERT INTO requisitions (req_number,department_id,req_date,purpose,requesting_officer,status,created_by) VALUES(?,?,?,?,?,?,?)')
            ->execute([$rn, (int)$_POST['department_id'], $_POST['req_date'],
                       trim($_POST['purpose']), trim($_POST['requesting_officer']),
                       'Draft', current_user()['id'] ?? null]);
        $reqId = (int) $pdo->lastInsertId();
        $rStmt = $pdo->prepare('INSERT INTO requisition_items (req_id,item_id,required_quantity) VALUES(?,?,?)');
        foreach (($_POST['item_id'] ?? []) as $k => $iid) {
            $qty = (int)($_POST['required_qty'][$k] ?? 0);
            if ($iid && $qty > 0) $rStmt->execute([$reqId, (int)$iid, $qty]);
        }
        flash("Requisition {$rn} submitted."); header('Location: ' . page_link('requisitions')); exit;
    }

    // ── Requisition approve ──────────────────────────────────────────
    if ($action === 'approve_requisition') {
        $reqId = (int)$_POST['req_id'];
        $pdo->prepare('UPDATE requisitions SET status="Approved", approving_officer=? WHERE id=?')
            ->execute([current_user()['name'] ?? 'Store Officer', $reqId]);
        // Set approved quantities
        $aStmt = $pdo->prepare('UPDATE requisition_items SET approved_quantity=? WHERE id=?');
        foreach (($_POST['approved_qty'] ?? []) as $riId => $qty) {
            $aStmt->execute([(int)$qty, (int)$riId]);
        }
        flash('Requisition approved.'); header('Location: ' . page_link('requisitions')); exit;
    }

    // ── Requisition issue ────────────────────────────────────────────
    if ($action === 'issue_requisition') {
        $reqId = (int)$_POST['req_id'];
        $items = $pdo->prepare('SELECT * FROM requisition_items WHERE req_id=?');
        $items->execute([$reqId]);
        $requisitionItems = $items->fetchAll(PDO::FETCH_ASSOC);
        reject_expired_items($pdo, array_column($requisitionItems, 'item_id'), 'requisitions');
        foreach ($requisitionItems as $ri) {
            $issueQty = (int)($ri['approved_quantity'] ?: $ri['required_quantity']);
            $pdo->prepare('UPDATE items SET quantity = quantity - ? WHERE id = ? AND quantity >= ?')
                ->execute([$issueQty, $ri['item_id'], $issueQty]);
            $pdo->prepare('UPDATE requisition_items SET issued_quantity=? WHERE id=?')
                ->execute([$issueQty, $ri['id']]);
            $pdo->prepare('UPDATE items SET status = CASE WHEN quantity=0 THEN "Out of stock" WHEN quantity<=reorder_level THEN "Low stock" ELSE "Available" END WHERE id=?')
                ->execute([$ri['item_id']]);
        }
        $pdo->prepare('UPDATE requisitions SET status="Issued", store_officer=? WHERE id=?')
            ->execute([current_user()['name'] ?? 'Store Officer', $reqId]);
        flash('Requisition issued and stock updated.'); header('Location: ' . page_link('requisitions')); exit;
    }

    // ── OPD Consumption ──────────────────────────────────────────────
    if ($action === 'opd_consumption') {
        reject_expired_items($pdo, $_POST['item_id'] ?? [], 'opd_consumption');
        $cStmt = $pdo->prepare('INSERT INTO consumption_records (type,patient_mr,patient_name,department_id,visit_date,item_id,batch_number,quantity,posted_by) VALUES(?,?,?,?,?,?,?,?,?)');
        foreach (($_POST['item_id'] ?? []) as $k => $iid) {
            $qty = (int)($_POST['quantity'][$k] ?? 0);
            if ($iid && $qty > 0) {
                $cStmt->execute(['OPD', trim($_POST['patient_mr']), trim($_POST['patient_name']),
                                 ($_POST['department_id'] ?: null), $_POST['visit_date'],
                                 (int)$iid, trim($_POST['batch'][$k] ?? ''), $qty,
                                 current_user()['id'] ?? null]);
                $pdo->prepare('UPDATE items SET quantity = quantity - ? WHERE id = ?')->execute([$qty, (int)$iid]);
                $pdo->prepare('UPDATE items SET status = CASE WHEN quantity=0 THEN "Out of stock" WHEN quantity<=reorder_level THEN "Low stock" ELSE "Available" END WHERE id=?')->execute([(int)$iid]);
            }
        }
        flash('OPD consumption recorded.'); header('Location: ' . page_link('opd_consumption')); exit;
    }

    // ── IPD Consumption ──────────────────────────────────────────────
    if ($action === 'ipd_consumption') {
        reject_expired_items($pdo, $_POST['item_id'] ?? [], 'ipd_consumption');
        $cStmt = $pdo->prepare('INSERT INTO consumption_records (type,patient_mr,patient_name,department_id,ward,bed,admission_date,item_id,batch_number,quantity,posted_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
        foreach (($_POST['item_id'] ?? []) as $k => $iid) {
            $qty = (int)($_POST['quantity'][$k] ?? 0);
            if ($iid && $qty > 0) {
                $cStmt->execute(['IPD', trim($_POST['patient_mr']), trim($_POST['patient_name']),
                                 ($_POST['department_id'] ?: null), trim($_POST['ward']),
                                 trim($_POST['bed']), $_POST['admission_date'], (int)$iid,
                                 trim($_POST['batch'][$k] ?? ''), $qty, current_user()['id'] ?? null]);
                $pdo->prepare('UPDATE items SET quantity = quantity - ? WHERE id = ?')->execute([$qty, (int)$iid]);
                $pdo->prepare('UPDATE items SET status = CASE WHEN quantity=0 THEN "Out of stock" WHEN quantity<=reorder_level THEN "Low stock" ELSE "Available" END WHERE id=?')->execute([(int)$iid]);
            }
        }
        flash('IPD consumption recorded.'); header('Location: ' . page_link('ipd_consumption')); exit;
    }

    // ── OT Consumption ───────────────────────────────────────────────
    if ($action === 'ot_consumption') {
        reject_expired_items($pdo, $_POST['item_id'] ?? [], 'ot_consumption');
        $cStmt = $pdo->prepare('INSERT INTO consumption_records (type,patient_mr,patient_name,department_id,surgery_number,surgeon,procedure_name,item_id,batch_number,quantity,posted_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
        $sn = next_number($pdo, 'OT', 'consumption_records', 'surgery_number');
        foreach (($_POST['item_id'] ?? []) as $k => $iid) {
            $qty = (int)($_POST['quantity'][$k] ?? 0);
            if ($iid && $qty > 0) {
                $cStmt->execute(['OT', trim($_POST['patient_mr']), trim($_POST['patient_name']),
                                 5, // Operating Theatre dept
                                 $sn, trim($_POST['surgeon']), trim($_POST['procedure_name']),
                                 (int)$iid, trim($_POST['batch'][$k] ?? ''), $qty,
                                 current_user()['id'] ?? null]);
                $pdo->prepare('UPDATE items SET quantity = quantity - ? WHERE id = ?')->execute([$qty, (int)$iid]);
                $pdo->prepare('UPDATE items SET status = CASE WHEN quantity=0 THEN "Out of stock" WHEN quantity<=reorder_level THEN "Low stock" ELSE "Available" END WHERE id=?')->execute([(int)$iid]);
            }
        }
        flash("OT consumption for surgery {$sn} posted."); header('Location: ' . page_link('ot_consumption')); exit;
    }

    // ── Emergency Consumption ────────────────────────────────────────
    if ($action === 'emergency') {
        reject_expired_items($pdo, $_POST['item_id'] ?? [], 'emergency');
        $cStmt = $pdo->prepare('INSERT INTO consumption_records (type,patient_mr,patient_name,department_id,item_id,batch_number,quantity,posted_by) VALUES(?,?,?,?,?,?,?,?)');
        foreach (($_POST['item_id'] ?? []) as $k => $iid) {
            $qty = (int)($_POST['quantity'][$k] ?? 0);
            if ($iid && $qty > 0) {
                $cStmt->execute(['Emergency', trim($_POST['patient_mr']), trim($_POST['patient_name']),
                                 6, // Emergency dept
                                 (int)$iid, trim($_POST['batch'][$k] ?? ''), $qty,
                                 current_user()['id'] ?? null]);
                $pdo->prepare('UPDATE items SET quantity = quantity - ? WHERE id = ?')->execute([$qty, (int)$iid]);
                $pdo->prepare('UPDATE items SET status = CASE WHEN quantity=0 THEN "Out of stock" WHEN quantity<=reorder_level THEN "Low stock" ELSE "Available" END WHERE id=?')->execute([(int)$iid]);
            }
        }
        flash('Emergency consumption recorded.'); header('Location: ' . page_link('emergency')); exit;
    }

    // ── Stock Adjustment ─────────────────────────────────────────────
    if ($action === 'adjustment') {
        $an  = next_number($pdo, 'ADJ', 'stock_adjustments', 'adj_number');
        $qty = (int)$_POST['quantity'];
        if ($_POST['direction'] === 'out') $qty = -$qty;
        $pdo->prepare('INSERT INTO stock_adjustments (adj_number,item_id,reason,quantity,value,notes,requesting_user_id,status) VALUES(?,?,?,?,?,?,?,?)')
            ->execute([$an, (int)$_POST['item_id'], $_POST['reason'], $qty,
                       (float)($_POST['value'] ?? 0), trim($_POST['notes'] ?? ''),
                       current_user()['id'] ?? null, 'Pending']);
        flash("Adjustment {$an} submitted for authorization."); header('Location: ' . page_link('adjustments')); exit;
    }

    // ── Approve Adjustment ───────────────────────────────────────────
    if ($action === 'approve_adjustment') {
        $adjId = (int)$_POST['adj_id'];
        $adj   = $pdo->prepare('SELECT * FROM stock_adjustments WHERE id=?');
        $adj->execute([$adjId]);
        $adjRow = $adj->fetch(PDO::FETCH_ASSOC);
        if ($adjRow && $adjRow['status'] === 'Pending') {
            $pdo->prepare('UPDATE items SET quantity = quantity + ? WHERE id = ?')
                ->execute([(int)$adjRow['quantity'], (int)$adjRow['item_id']]);
            $pdo->prepare('UPDATE items SET status = CASE WHEN quantity=0 THEN "Out of stock" WHEN quantity<=reorder_level THEN "Low stock" ELSE "Available" END WHERE id=?')
                ->execute([(int)$adjRow['item_id']]);
            $pdo->prepare('UPDATE stock_adjustments SET status="Approved", authorizing_officer=? WHERE id=?')
                ->execute([current_user()['name'] ?? '', $adjId]);
        }
        flash('Adjustment approved and stock updated.'); header('Location: ' . page_link('adjustments')); exit;
    }

    // ── Stock Verification ───────────────────────────────────────────
    if ($action === 'verification') {
        $vn = next_number($pdo, 'VER', 'stock_verifications', 'ver_number');
        $pdo->prepare('INSERT INTO stock_verifications (ver_number,verification_date,committee_members,remarks,created_by) VALUES(?,?,?,?,?)')
            ->execute([$vn, $_POST['verification_date'], trim($_POST['committee_members']),
                       trim($_POST['remarks']), current_user()['id'] ?? null]);
        $verId = (int) $pdo->lastInsertId();
        $vStmt = $pdo->prepare('INSERT INTO stock_verification_items (verification_id,item_id,system_quantity,physical_quantity,difference,explanation) VALUES(?,?,?,?,?,?)');
        foreach (($_POST['item_id'] ?? []) as $k => $iid) {
            $phys = (int)($_POST['physical_qty'][$k] ?? 0);
            $sys  = (int)($_POST['system_qty'][$k]  ?? 0);
            if ($iid) {
                $vStmt->execute([$verId, (int)$iid, $sys, $phys, $phys - $sys, trim($_POST['explanation'][$k] ?? '')]);
            }
        }
        flash("Verification {$vn} saved."); header('Location: ' . page_link('verification')); exit;
    }

    // ── Department ───────────────────────────────────────────────────
    if ($action === 'department') {
        $pdo->prepare('INSERT INTO departments (name,type,incharge,phone,status) VALUES(?,?,?,?,?)')
            ->execute([trim($_POST['name']), $_POST['type'], trim($_POST['incharge']), trim($_POST['phone']), 'Active']);
        flash('Department added.'); header('Location: ' . page_link('departments')); exit;
    }

    // ── Item ─────────────────────────────────────────────────────────
    if ($action === 'item') {
        $category = trim((string)($_POST['category'] ?? ''));
        $expiryDate = $_POST['expiry_date'] ?: null;
        if (in_array($category, ['Medicines', 'Ophthalmic Medicines'], true) && !$expiryDate) {
            flash('An expiry date is required for all medicines.', 'error');
            header('Location: ' . page_link('inventory'));
            exit;
        }
        $itemCode = next_number($pdo, 'ITEM', 'items', 'code');
        $pdo->prepare('INSERT INTO items (code,name,category,unit,location,quantity,reorder_level,min_level,max_level,batch_number,expiry_date,batch_required) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$itemCode, trim($_POST['name']), $category,
                       $_POST['unit'], trim($_POST['location']), (int)$_POST['quantity'],
                       (int)$_POST['reorder_level'], (int)($_POST['min_level'] ?? 0),
                       (int)($_POST['max_level'] ?? 0), trim($_POST['batch_number']),
                       $expiryDate, isset($_POST['batch_required']) ? 1 : 0]);
        flash("Item {$itemCode} added to item master."); header('Location: ' . page_link('inventory')); exit;
    }

    // ── Asset ────────────────────────────────────────────────────────
    if ($action === 'asset') {
        $pdo->prepare('INSERT INTO assets (tag,name,department,location,custodian,status,warranty_until,category,make,model,serial_number,purchase_date,cost) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([trim($_POST['tag']), trim($_POST['name']), trim($_POST['department']),
                       trim($_POST['location']), trim($_POST['custodian']), $_POST['status'],
                       $_POST['warranty_until'] ?: null, trim($_POST['category']),
                       trim($_POST['make']), trim($_POST['model']), trim($_POST['serial_number']),
                       $_POST['purchase_date'] ?: null, (float)($_POST['cost'] ?? 0)]);
        flash('Asset added.'); header('Location: ' . page_link('assets')); exit;
    }

    // ── User ─────────────────────────────────────────────────────────
    if ($action === 'user') {
        $hash = password_hash($_POST['password'] ?? 'change123', PASSWORD_DEFAULT);
        $pdo->prepare('INSERT INTO users (name,email,role,department,status,password_hash) VALUES(?,?,?,?,?,?)')
            ->execute([trim($_POST['name']), trim($_POST['email']), $_POST['role'],
                       trim($_POST['department']), 'Active', $hash]);
        flash('User added.'); header('Location: ' . page_link('users')); exit;
    }

    // ── Quick entry (legacy) ─────────────────────────────────────────
    if ($action === 'transaction') {
        $pdo->prepare('INSERT INTO transactions (type,reference,party,quantity,status) VALUES(?,?,?,?,?)')
            ->execute([$_POST['type'], trim($_POST['reference']), trim($_POST['party']),
                       (int)$_POST['quantity'], $_POST['status']]);
        flash('Record saved to audit trail.'); header('Location: ' . page_link('quick-entry')); exit;
    }
}

/* ─────────────────────────────── Page setup ────────────────────────────── */
$titles = [
    'reports'         => ['Reports & analytics',       'MANAGEMENT & REPORTING',     'Turn live operational data into decisions.'],
    'suppliers'       => ['Supplier master',            '01 · PURCHASE SECTION',      'Manage supplier records, contacts, and status.'],
    'purchase_orders' => ['Purchase orders',            '02 · PURCHASE SECTION',      'Create and track supplier orders before material arrives.'],
    'grn'             => ['Goods received (GRN)',       '03 · STORE RECEIVING',       'Record received material against POs and update stock.'],
    'inventory'       => ['Item master / stock',        '04 · ITEM MASTER',           'Manage item codes, categories, stock levels, and locations.'],
    'batches'         => ['Batch & expiry control',     '05 · BATCH & EXPIRY',        'Track batches, expiry dates, and FEFO allocations.'],
    'adjustments'     => ['Stock adjustments',          '06 · STOCK CONTROL',         'Record authorized adjustments for damage, excess, or corrections.'],
    'verification'    => ['Stock verification',         '07 · PHYSICAL VERIFICATION', 'Compare physical counts with system balances.'],
    'requisitions'    => ['Departmental requisitions',  '08 · DEPARTMENTAL FLOW',     'Department raises requisition → approval → store issue.'],
    'opd_consumption' => ['OPD patient consumption',    '09 · OPD',                   'Record item consumption for outpatient visits.'],
    'ipd_consumption' => ['IPD / ward consumption',     '10 · IPD / WARDS',           'Record item usage for admitted patients by ward and bed.'],
    'ot_consumption'  => ['OT / surgery consumption',   '11 · OPERATING THEATRE',     'Post item usage for each surgery with surgeon and procedure details.'],
    'emergency'       => ['Emergency consumption',      '12 · EMERGENCY',             'Rapid consumption recording with post-entry regularization.'],
    'assets'          => ['Fixed asset register',       'FIXED ASSETS',               'Tag, maintain, and track durable hospital assets.'],
    'departments'     => ['Departments',                'DEPT. MASTER',               'Manage OPD, IPD, OT, Emergency, and other department records.'],
    'returns'         => ['Returns',                    '13 · RETURNS',               'Record unused material returned from departments.'],
    'users'           => ['Users & roles',              'SYSTEM CONTROL',             'Manage operators, approvers, and administrators.'],
    'quick-entry'     => ['Quick entry',                'DATA ENTRY',                 'Record a simple stock movement without a full workflow.'],
];
[$title, $kicker, $description] = $titles[$view];
$current = $view;
$items_all  = $pdo->query('SELECT id, code, name, unit, quantity, expiry_date, supplier_id FROM items ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$expiry_map = array_column($items_all, 'expiry_date', 'id');
$depts_all  = $pdo->query('SELECT id, name, type FROM departments WHERE status="Active" ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$supp_all   = $pdo->query('SELECT id, code, name FROM suppliers WHERE status="Active" ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$supplier_names = array_column($supp_all, 'name', 'id');
require __DIR__ . '/partials/header.php';
?>
<section class="system-page">
<div class="module-header">
    <div><p class="eyebrow"><?= e($kicker) ?></p><h1><?= e($title) ?></h1><p><?= e($description) ?></p></div>
</div>
<div class="instruction-banner">
    <span>i</span>
    <div><strong>How to use this module</strong>
    <p><?= e(match($view) {
        'suppliers'       => 'Add each supplier with their GST number and contact. Supplier records are linked to purchase orders and GRNs for full traceability.',
        'purchase_orders' => 'Create one PO per supplier delivery. Add item lines with quantities and rates. Stock is NOT updated until a GRN is created.',
        'grn'             => 'Select the matching PO, record challan details, enter received/accepted/rejected quantities, batch numbers and expiry dates. Stock increases on accepted quantity only.',
        'inventory'       => 'Use one unique item code per item. Stock changes automatically from GRNs, requisition issues, consumption, and adjustments.',
        'batches'         => 'All items with batch/expiry dates appear here. Review 30/60/90-day alerts and ensure FEFO allocation.',
        'adjustments'     => 'Submit an adjustment with reason and quantity. DD Finance or admin must approve before stock is updated.',
        'verification'    => 'Enter physical counts against system balances. Discrepancies require explanation and authorized adjustment.',
        'requisitions'    => 'Department creates requisition → Store Officer approves → Store Officer issues. Each step is tracked.',
        'opd_consumption' => 'Enter patient MR number, select items consumed during visit. Stock decreases immediately on save.',
        'ipd_consumption' => 'Record ward, bed, and admission date. Post all items consumed by the patient during admission.',
        'ot_consumption'  => 'Enter surgeon, procedure, and patient details. Record all IOLs, sutures, medicines, and consumables used.',
        'emergency'       => 'Rapid entry for emergency cases. Post now, add authorization reference later if needed.',
        'assets'          => 'Assign a unique asset tag, record department, custodian, warranty, and AMC. All transfers must be logged.',
        'departments'     => 'Maintain the list of departments that receive stock issues. Used in requisitions and consumption records.',
        'returns'         => 'Record unused material returned from departments. Returned stock is verified before re-entering available inventory.',
        'users'           => 'Add users with their role. Role determines which modules they can access and what they can do.',
        'quick-entry'     => 'Use only for simple movements. For full workflows, use the dedicated module so all fields are captured.',
        default           => 'Select a report type, apply date/department filters, review exceptions, and print or export the result.',
    }) ?></p></div>
</div>

<?php /* ════════════════════ SUPPLIERS ════════════════════════════════════ */ ?>
<?php if ($view === 'suppliers'): ?>
<div class="system-grid">
    <div class="panel">
        <div class="panel-heading"><h2>Supplier directory</h2><a class="button button-primary" href="#add-supplier">＋ Add supplier</a></div>
        <div class="table-wrap"><table>
            <thead><tr><th>Code</th><th>Supplier name</th><th>Contact</th><th>Phone</th><th>Email</th><th>GST No.</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($pdo->query('SELECT * FROM suppliers ORDER BY id DESC') as $s): ?>
            <tr>
                <td><b><?= e($s['code']) ?></b></td>
                <td><?= e($s['name']) ?></td>
                <td><?= e($s['contact_person']) ?></td>
                <td><?= e($s['phone']) ?></td>
                <td><?= e($s['email']) ?></td>
                <td><?= e($s['gst_number']) ?></td>
                <td><?= pill($s['status']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <div class="form-card" id="add-supplier">
        <h2>Add supplier</h2>
        <form method="post"><input type="hidden" name="action" value="supplier">
        <div class="form-grid one">
            <label>Supplier code<input name="code" value="<?= e(next_number($pdo, 'SUP', 'suppliers', 'code')) ?>" required></label>
            <label>Supplier name<input name="name" placeholder="e.g. Allied Pharma" required></label>
            <label>Contact person<input name="contact_person" value="Sales Manager"></label>
            <label>Phone<input name="phone" value="0300-<?= sprintf('%07d', rand(1000000, 9999999)) ?>"></label>
            <label>Email<input name="email" type="email" value="supplier<?= rand(10, 99) ?>@siovs.edu.pk"></label>
            <label>Address<textarea name="address" rows="2">Industrial Area, Karachi / Hyderabad</textarea></label>
            <label>GST number<input name="gst_number" value="GST-3277<?= rand(100000, 999999) ?>"></label>
        </div>
        <button class="button button-primary" type="submit">Add supplier</button>
        </form>
    </div>
</div>

<?php /* ════════════════════ PURCHASE ORDERS ════════════════════════════════ */ ?>
<?php elseif ($view === 'purchase_orders'): ?>
<div class="system-grid">
    <div class="panel">
        <div class="panel-heading"><h2>Purchase order list</h2><a class="button button-primary" href="#add-po">＋ New PO</a></div>
        <div class="table-wrap"><table>
            <thead><tr><th>PO Number</th><th>Date</th><th>Supplier</th><th>Expected delivery</th><th>Total value</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($pdo->query('SELECT po.*, s.name as supplier_name FROM purchase_orders po LEFT JOIN suppliers s ON s.id=po.supplier_id ORDER BY po.id DESC') as $po): ?>
            <tr>
                <td><b><?= e($po['po_number']) ?></b></td>
                <td><?= e($po['po_date']) ?></td>
                <td><?= e($po['supplier_name']) ?></td>
                <td><?= e($po['expected_delivery']) ?></td>
                <td>₹ <?= number_format((float)$po['total_value'], 2) ?></td>
                <td><?= pill($po['status']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <div class="form-card" id="add-po">
        <h2>Create purchase order</h2>
        <form method="post"><input type="hidden" name="action" value="purchase_order">
        <div class="form-grid one">
            <label>Supplier<select name="supplier_id" required><?php foreach ($supp_all as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['code']) ?> — <?= e($s['name']) ?></option><?php endforeach; ?></select></label>
            <label>PO date<input name="po_date" type="date" value="<?= date('Y-m-d') ?>" required></label>
            <label>Purchase reference<input name="purchase_reference" value="<?= e('PR-' . date('Y') . '-' . sprintf('%03d', rand(100, 999))) ?>"></label>
            <label>Tender/quotation reference<input name="tender_reference" value="<?= e('TQ-' . date('Y') . '-' . sprintf('%03d', rand(100, 999))) ?>"></label>
            <label>Expected delivery<input name="expected_delivery" type="date" value="<?= date('Y-m-d', strtotime('+14 days')) ?>"></label>
            <label>Notes<textarea name="notes" rows="2">Annual procurement batch for hospital inventory</textarea></label>
        </div>
        <p class="eyebrow" style="margin:1rem 0 .5rem">LINE ITEMS</p>
        <div id="po-items">
            <?php for ($i=0; $i<4; $i++): ?>
            <div class="line-item-row">
                <input class="item-barcode" name="item_code[]" placeholder="Scan or type item code" autocomplete="off" style="width:145px">
                <select name="item_id[]"><option value="">— Select item —</option><?php foreach ($items_all as $it): ?><option value="<?= $it['id'] ?>" data-code="<?= e($it['code']) ?>" data-name="<?= e($it['name']) ?>" data-unit="<?= e($it['unit']) ?>" data-stock="<?= e($it['quantity']) ?>"><?= e($it['code']) ?> — <?= e($it['name']) ?></option><?php endforeach; ?></select>
                <input name="qty[]"  type="number" min="1" placeholder="Qty" style="width:70px">
                <input name="unit[]" placeholder="Unit" style="width:60px">
                <input name="rate[]" type="number" step="0.01" placeholder="Rate" style="width:90px">
                <input name="tax[]"  type="number" step="0.01" placeholder="Tax" style="width:70px">
            </div>
            <?php endfor; ?>
        </div>
        <button class="button button-primary" type="submit">Create purchase order</button>
        </form>
    </div>
</div>

<?php /* ════════════════════ GRN ═══════════════════════════════════════════ */ ?>
<?php elseif ($view === 'grn'): ?>
<div class="system-grid">
    <div class="panel">
        <div class="panel-heading"><h2>GRN register</h2><a class="button button-primary" href="#add-grn">＋ New GRN</a></div>
        <div class="table-wrap"><table>
            <thead><tr><th>GRN Number</th><th>PO</th><th>Supplier</th><th>Challan No.</th><th>Received date</th><th>Inspection</th></tr></thead>
            <tbody>
            <?php foreach ($pdo->query('SELECT g.*, po.po_number, s.name AS sup FROM grns g LEFT JOIN purchase_orders po ON po.id=g.po_id LEFT JOIN suppliers s ON s.id=g.supplier_id ORDER BY g.id DESC') as $g): ?>
            <tr>
                <td><b><?= e($g['grn_number']) ?></b></td>
                <td><?= e($g['po_number']) ?></td>
                <td><?= e($g['sup']) ?></td>
                <td><?= e($g['challan_number']) ?></td>
                <td><?= e($g['received_date']) ?></td>
                <td><?= pill($g['inspection_status']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <div class="form-card" id="add-grn">
        <h2>Create GRN</h2>
        <form method="post"><input type="hidden" name="action" value="grn">
        <div class="form-grid one">
            <label>Linked PO (optional)<select name="po_id"><option value="">— Direct receipt —</option><?php foreach ($pdo->query('SELECT po.id, po.po_number, s.name AS sn FROM purchase_orders po LEFT JOIN suppliers s ON s.id=po.supplier_id ORDER BY po.id DESC') as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['po_number']) ?> — <?= e($p['sn']) ?></option><?php endforeach; ?></select></label>
            <label>Supplier<select name="supplier_id"><?php foreach ($supp_all as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></label>
            <label>Challan number<input name="challan_number" value="<?= e('DC-' . date('Y') . '-' . sprintf('%04d', rand(1000, 9999))) ?>"></label>
            <label>Challan date<input name="challan_date" type="date" value="<?= date('Y-m-d') ?>"></label>
            <label>Date received<input name="received_date" type="date" value="<?= date('Y-m-d') ?>" required></label>
            <label>Inspection status<select name="inspection_status"><option>Inspected</option><option>Pending</option><option>Partially accepted</option><option>Rejected</option></select></label>
            <label>Remarks<textarea name="remarks" rows="2">Material received in good condition</textarea></label>
        </div>
        <p class="eyebrow" style="margin:1rem 0 .5rem">RECEIVED ITEMS (stock updates on accepted qty)</p>
        <div id="grn-items">
            <?php for ($i=0; $i<4; $i++): ?>
            <div class="line-item-row grn-row">
                <input class="item-barcode" name="item_code[]" placeholder="Scan or type item code" autocomplete="off" style="width:145px">
                <select name="item_id[]"><option value="">— Select item —</option><?php foreach ($items_all as $it): ?><option value="<?= $it['id'] ?>" data-code="<?= e($it['code']) ?>" data-name="<?= e($it['name']) ?>" data-unit="<?= e($it['unit']) ?>" data-stock="<?= e($it['quantity']) ?>"><?= e($it['code']) ?> — <?= e($it['name']) ?></option><?php endforeach; ?></select>
                <input name="qty_ordered[]"   type="number" min="0" placeholder="Ordered" style="width:75px">
                <input name="qty_received[]"  type="number" min="0" placeholder="Received" style="width:75px">
                <input name="qty_accepted[]"  type="number" min="0" placeholder="Accepted" style="width:75px">
                <input name="qty_rejected[]"  type="number" min="0" placeholder="Rejected" style="width:75px">
                <input name="batch[]"         value="B-<?= date('Y') ?>-<?= sprintf('%03d', rand(100, 999)) ?>" placeholder="Batch No." style="width:100px">
                <input name="mfg_date[]"      type="date" value="<?= date('Y-m-d', strtotime('-1 month')) ?>" title="Mfg date">
                <input name="exp_date[]"      type="date" value="<?= date('Y-m-d', strtotime('+1 year')) ?>" title="Expiry date">
                <input name="rate[]"          type="number" step="0.01" placeholder="Rate" style="width:80px">
            </div>
            <?php endfor; ?>
        </div>
        <button class="button button-primary" type="submit">Save GRN &amp; update stock</button>
        </form>
    </div>
</div>

<?php /* ════════════════════ INVENTORY ═════════════════════════════════════ */ ?>
<?php elseif ($view === 'inventory'): ?>
<?php
$today = date('Y-m-d');
?>
<div class="system-grid">
    <div class="panel">
        <div class="panel-heading"><h2>Item master</h2><a class="button button-primary" href="#add-item">＋ Add item</a></div>
        <div class="panel-heading" style="border-top:none;padding-top:0"><input type="search" id="item-search" placeholder="Search items…" style="width:260px"></div>
        <div class="table-wrap"><table id="item-table">
            <thead><tr><th>Code / Item</th><th>Supplier</th><th>Category</th><th>Location</th><th>Qty</th><th>Reorder / Min / Max</th><th>Batch</th><th>Expiry</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($pdo->query('SELECT * FROM items ORDER BY name') as $it): ?>
            <?php $daysLeft = $it['expiry_date'] ? (int)((strtotime($it['expiry_date']) - strtotime($today)) / 86400) : null; ?>
            <tr>
                <td><b><?= e($it['code']) ?></b><br><span class="muted"><?= e($it['name']) ?></span></td>
                <td><?= e($supplier_names[$it['supplier_id']] ?? 'Not received yet') ?></td>
                <td><?= e($it['category']) ?></td>
                <td><?= e($it['location']) ?></td>
                <td class="<?= $it['quantity'] == 0 ? 'text-danger' : ($it['quantity'] <= $it['reorder_level'] ? 'text-warn' : '') ?>">
                    <strong><?= e($it['quantity']) ?></strong> <?= e($it['unit']) ?>
                </td>
                <td class="muted"><?= $it['reorder_level'] ?> / <?= $it['min_level'] ?> / <?= $it['max_level'] ?></td>
                <td><?= e($it['batch_number']) ?></td>
                <td class="<?= $daysLeft !== null && $daysLeft <= 30 ? 'text-danger' : ($daysLeft !== null && $daysLeft <= 90 ? 'text-warn' : '') ?>">
                    <?= e($it['expiry_date']) ?><?php if ($daysLeft !== null): ?><br><span class="muted"><?= $daysLeft ?>d</span><?php endif; ?>
                </td>
                <td><?= pill($it['status']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <div class="form-card" id="add-item">
        <h2>Add item to master</h2>
        <form method="post"><input type="hidden" name="action" value="item">
        <div class="form-grid one">
            <label>Item code<input name="code" value="<?= e(next_number($pdo, 'ITEM', 'items', 'code')) ?>" required></label>
            <label>Item name<input name="name" placeholder="e.g. Paracetamol 500mg tablets" required></label>
            <label>Category<select name="category" id="item-category"><option>Medicines</option><option>Ophthalmic Medicines</option><option>Surgical Consumables</option><option>Ophthalmic Consumables</option><option>General Medical Consumables</option><option>Laboratory Consumables</option><option>Cleaning Materials</option><option>Stationery</option><option>Electrical Items</option><option>Engineering Materials</option><option>Other</option></select></label>
            <label>Unit<input name="unit" value="Box" placeholder="Box / Vial / Unit" required></label>
            <label>Storage location<input name="location" value="Main Store · Shelf A<?= rand(1, 9) ?>" required></label>
            <label>Opening quantity<input name="quantity" type="number" min="0" value="50"></label>
            <label>Reorder level<input name="reorder_level" type="number" min="0" value="20"></label>
            <label>Minimum level<input name="min_level" type="number" min="0" value="10"></label>
            <label>Maximum level<input name="max_level" type="number" min="0" value="200"></label>
            <label>Batch number (if applicable)<input name="batch_number" value="B-<?= date('Y') ?>-<?= sprintf('%04d', rand(1000, 9999)) ?>"></label>
            <label>Expiry date<input name="expiry_date" id="item-expiry-date" type="date" value="<?= date('Y-m-d', strtotime('+1 year')) ?>"><small id="medicine-expiry-hint" class="muted">Required for medicines.</small></label>
            <label style="flex-direction:row;align-items:center;gap:.5rem"><input name="batch_required" type="checkbox" checked style="width:auto"> Batch/expiry tracking required</label>
        </div>
        <button class="button button-primary" type="submit">Add item</button>
        </form>
    </div>
</div>

<?php /* ════════════════════ BATCHES ═════════════════════════════════════== */ ?>
<?php elseif ($view === 'batches'): ?>
<?php
$today = date('Y-m-d');
$batchItems = $pdo->query(
    "SELECT *, CAST(julianday(expiry_date) - julianday('now') AS INTEGER) as days_left
     FROM items WHERE expiry_date IS NOT NULL ORDER BY expiry_date ASC"
)->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="panel">
    <div class="panel-heading"><h2>Batch &amp; expiry register</h2></div>
    <div class="table-wrap"><table>
        <thead><tr><th>Code / Item</th><th>Batch No.</th><th>Category</th><th>Location</th><th>Qty</th><th>Expiry date</th><th>Days left</th><th>Alert</th></tr></thead>
        <tbody>
        <?php foreach ($batchItems as $bi): ?>
        <tr>
            <td><b><?= e($bi['code']) ?></b><br><span class="muted"><?= e($bi['name']) ?></span></td>
            <td><?= e($bi['batch_number']) ?></td>
            <td><?= e($bi['category']) ?></td>
            <td><?= e($bi['location']) ?></td>
            <td><?= e($bi['quantity']) ?> <?= e($bi['unit']) ?></td>
            <td><?= e($bi['expiry_date']) ?></td>
            <td class="<?= $bi['days_left'] <= 0 ? 'text-danger' : ($bi['days_left'] <= 30 ? 'text-danger' : ($bi['days_left'] <= 90 ? 'text-warn' : '')) ?>">
                <?= $bi['days_left'] <= 0 ? 'EXPIRED' : $bi['days_left'] . ' days' ?>
            </td>
            <td><?= $bi['days_left'] <= 0 ? pill('Expired') : ($bi['days_left'] <= 30 ? pill('30-day alert') : ($bi['days_left'] <= 60 ? pill('60-day alert') : ($bi['days_left'] <= 90 ? pill('90-day alert') : pill('OK')))) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<?php /* ════════════════════ ADJUSTMENTS ═══════════════════════════════════ */ ?>
<?php elseif ($view === 'adjustments'): ?>
<div class="system-grid">
    <div class="panel">
        <div class="panel-heading"><h2>Adjustment register</h2><a class="button button-primary" href="#add-adj">＋ New adjustment</a></div>
        <div class="table-wrap"><table>
            <thead><tr><th>Adj. No.</th><th>Item</th><th>Reason</th><th>Qty</th><th>Value</th><th>Requested by</th><th>Authorized by</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($pdo->query('SELECT a.*, i.name AS iname, i.code AS icode, u.name AS uname FROM stock_adjustments a LEFT JOIN items i ON i.id=a.item_id LEFT JOIN users u ON u.id=a.requesting_user_id ORDER BY a.id DESC') as $adj): ?>
            <tr>
                <td><b><?= e($adj['adj_number']) ?></b></td>
                <td><?= e($adj['icode']) ?><br><span class="muted"><?= e($adj['iname']) ?></span></td>
                <td><?= e($adj['reason']) ?></td>
                <td class="<?= $adj['quantity'] < 0 ? 'text-danger' : 'text-success' ?>"><?= $adj['quantity'] > 0 ? '+' : '' ?><?= $adj['quantity'] ?></td>
                <td>₹ <?= number_format((float)$adj['value'], 2) ?></td>
                <td><?= e($adj['uname']) ?></td>
                <td><?= e($adj['authorizing_officer']) ?></td>
                <td><?= pill($adj['status']) ?></td>
                <td>
                <?php if ($adj['status'] === 'Pending' && (can('dd_finance') || can('adjustments'))): ?>
                <form method="post" style="display:inline">
                    <input type="hidden" name="action" value="approve_adjustment">
                    <input type="hidden" name="adj_id" value="<?= $adj['id'] ?>">
                    <button class="button button-light" type="submit" style="padding:.25rem .6rem;font-size:.8rem">Approve</button>
                </form>
                <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <div class="form-card" id="add-adj">
        <h2>Submit stock adjustment</h2>
        <p class="muted" style="margin-bottom:1rem">Requires authorization from DD Finance or Administrator before stock is updated.</p>
        <form method="post"><input type="hidden" name="action" value="adjustment">
        <div class="form-grid one">
            <label>Item<select name="item_id" required><?php foreach ($items_all as $it): ?><option value="<?= $it['id'] ?>"><?= e($it['code']) ?> — <?= e($it['name']) ?> (<?= $it['quantity'] ?> <?= e($it['unit']) ?>)</option><?php endforeach; ?></select></label>
            <label>Adjustment direction<select name="direction"><option value="out">Deduction (–)</option><option value="in">Addition (＋)</option></select></label>
            <label>Quantity<input name="quantity" type="number" min="1" value="5" required></label>
            <label>Reason<select name="reason"><option>Damage</option><option>Expiry</option><option>Breakage</option><option>Shortage</option><option>Excess</option><option>Correction</option><option>Other authorized reason</option></select></label>
            <label>Value (₹)<input name="value" type="number" step="0.01" min="0" value="250.00"></label>
            <label>Notes / supporting reference<textarea name="notes" rows="2">Authorized stock adjustment report</textarea></label>
        </div>
        <button class="button button-primary" type="submit">Submit for authorization</button>
        </form>
    </div>
</div>

<?php /* ════════════════════ VERIFICATION ═══════════════════════════════════ */ ?>
<?php elseif ($view === 'verification'): ?>
<div class="system-grid">
    <div class="panel">
        <div class="panel-heading"><h2>Verification records</h2><a class="button button-primary" href="#add-ver">＋ New verification</a></div>
        <div class="table-wrap"><table>
            <thead><tr><th>Ver. No.</th><th>Date</th><th>Committee</th><th>Status</th><th>Approved by</th></tr></thead>
            <tbody>
            <?php foreach ($pdo->query('SELECT * FROM stock_verifications ORDER BY id DESC') as $v): ?>
            <tr><td><b><?= e($v['ver_number']) ?></b></td><td><?= e($v['verification_date']) ?></td><td><?= e($v['committee_members']) ?></td><td><?= pill($v['status']) ?></td><td><?= e($v['approved_by']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <div class="form-card" id="add-ver">
        <h2>Physical stock verification</h2>
        <form method="post"><input type="hidden" name="action" value="verification">
        <div class="form-grid one">
            <label>Verification date<input name="verification_date" type="date" value="<?= date('Y-m-d') ?>" required></label>
            <label>Committee members<input name="committee_members" value="Verifying Officers — <?= e(current_user()['name'] ?? 'Officer') ?>"></label>
            <label>Remarks<textarea name="remarks" rows="2">Annual physical stock verification audit</textarea></label>
        </div>
        <p class="eyebrow" style="margin:1rem 0 .5rem">ITEM-WISE COUNT</p>
        <div class="table-wrap"><table>
            <thead><tr><th>Item</th><th>System Qty</th><th>Physical Qty</th><th>Explanation</th></tr></thead>
            <tbody>
            <?php foreach ($items_all as $it): ?>
            <tr>
                <td><?= e($it['code']) ?> — <?= e($it['name']) ?><input type="hidden" name="item_id[]" value="<?= $it['id'] ?>"><input type="hidden" name="system_qty[]" value="<?= $it['quantity'] ?>"></td>
                <td><?= $it['quantity'] ?> <?= e($it['unit']) ?></td>
                <td><input name="physical_qty[]" type="number" min="0" value="<?= $it['quantity'] ?>" style="width:80px"></td>
                <td><input name="explanation[]" placeholder="Reason if different" style="width:180px"></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <button class="button button-primary" type="submit">Save verification report</button>
        </form>
    </div>
</div>

<?php /* ════════════════════ REQUISITIONS ════════════════════════════════════ */ ?>
<?php elseif ($view === 'requisitions'): ?>
<div class="system-grid">
    <div class="panel">
        <div class="panel-heading"><h2>Requisition register</h2><a class="button button-primary" href="#add-req">＋ New requisition</a></div>
        <div class="table-wrap"><table>
            <thead><tr><th>Req. No.</th><th>Department</th><th>Date</th><th>Purpose</th><th>Requested by</th><th>Approved by</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($pdo->query('SELECT r.*, d.name AS dname FROM requisitions r LEFT JOIN departments d ON d.id=r.department_id ORDER BY r.id DESC') as $r): ?>
            <tr>
                <td><b><?= e($r['req_number']) ?></b></td>
                <td><?= e($r['dname']) ?></td>
                <td><?= e($r['req_date']) ?></td>
                <td><?= e($r['purpose']) ?></td>
                <td><?= e($r['requesting_officer']) ?></td>
                <td><?= e($r['approving_officer']) ?></td>
                <td><?= pill($r['status']) ?></td>
                <td>
                <?php if ($r['status'] === 'Draft' && can('requisitions')): ?>
                <form method="post" style="display:inline">
                    <input type="hidden" name="action" value="approve_requisition">
                    <input type="hidden" name="req_id" value="<?= $r['id'] ?>">
                    <?php $riRows = $pdo->prepare('SELECT ri.*, i.name AS iname FROM requisition_items ri LEFT JOIN items i ON i.id=ri.item_id WHERE ri.req_id=?'); $riRows->execute([$r['id']]); ?>
                    <?php foreach ($riRows->fetchAll(PDO::FETCH_ASSOC) as $ri): ?>
                    <span class="muted" style="font-size:.8rem"><?= e($ri['iname']) ?>: </span>
                    <input type="number" name="approved_qty[<?= $ri['id'] ?>]" value="<?= $ri['required_quantity'] ?>" min="0" style="width:60px">
                    <?php endforeach; ?>
                    <button class="button button-light" type="submit" style="padding:.25rem .6rem;font-size:.8rem;margin-left:.5rem">Approve</button>
                </form>
                <?php elseif ($r['status'] === 'Approved'): ?>
                <form method="post" style="display:inline">
                    <input type="hidden" name="action" value="issue_requisition">
                    <input type="hidden" name="req_id" value="<?= $r['id'] ?>">
                    <button class="button button-primary" type="submit" style="padding:.25rem .6rem;font-size:.8rem">Issue stock</button>
                </form>
                <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <div class="form-card" id="add-req">
        <h2>New departmental requisition</h2>
        <form method="post"><input type="hidden" name="action" value="requisition">
        <div class="form-grid one">
            <label>Department<select name="department_id" required><?php foreach ($depts_all as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?> (<?= e($d['type']) ?>)</option><?php endforeach; ?></select></label>
            <label>Requisition date<input name="req_date" type="date" value="<?= date('Y-m-d') ?>" required></label>
            <label>Purpose<input name="purpose" value="Monthly stock replenishment"></label>
            <label>Requesting officer<input name="requesting_officer" value="<?= e(current_user()['name'] ?? 'Officer') ?>"></label>
        </div>
        <p class="eyebrow" style="margin:1rem 0 .5rem">ITEMS REQUIRED</p>
        <div id="req-items">
            <?php for ($i=0; $i<4; $i++): ?>
            <div class="line-item-row">
                <select name="item_id[]"><option value="">— Select item —</option><?php foreach ($items_all as $it): ?><option value="<?= $it['id'] ?>"><?= e($it['code']) ?> — <?= e($it['name']) ?> (Stock: <?= $it['quantity'] ?>)</option><?php endforeach; ?></select>
                <input name="required_qty[]" type="number" min="1" placeholder="Qty required" style="width:120px">
            </div>
            <?php endfor; ?>
        </div>
        <button class="button button-primary" type="submit">Submit requisition</button>
        </form>
    </div>
</div>

<?php /* ════════════════════ OPD CONSUMPTION ══════════════════════════════════ */ ?>
<?php elseif ($view === 'opd_consumption'): ?>
<div class="system-grid">
    <div class="panel">
        <div class="panel-heading"><h2>OPD consumption log</h2></div>
        <div class="table-wrap"><table>
            <thead><tr><th>Date</th><th>MR No.</th><th>Patient</th><th>Department</th><th>Item</th><th>Batch</th><th>Qty</th></tr></thead>
            <tbody>
            <?php foreach ($pdo->query('SELECT c.*, i.name AS iname, i.code AS icode, d.name AS dname FROM consumption_records c LEFT JOIN items i ON i.id=c.item_id LEFT JOIN departments d ON d.id=c.department_id WHERE c.type="OPD" ORDER BY c.id DESC LIMIT 50') as $c): ?>
            <tr><td><?= e(date('d M Y', strtotime($c['created_at']))) ?></td><td><b><?= e($c['patient_mr']) ?></b></td><td><?= e($c['patient_name']) ?></td><td><?= e($c['dname']) ?></td><td><?= e($c['icode']) ?> — <?= e($c['iname']) ?></td><td><?= e($c['batch_number']) ?></td><td><?= e($c['quantity']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <div class="form-card">
        <h2>Record OPD consumption</h2>
        <form method="post"><input type="hidden" name="action" value="opd_consumption">
        <div class="form-grid one">
            <label>Patient MR No.<input name="patient_mr" value="<?= e('MR-' . date('Ymd') . '-' . sprintf('%03d', rand(100, 999))) ?>" required></label>
            <label>Patient name<input name="patient_name" value="Patient <?= sprintf('%03d', rand(100, 999)) ?>"></label>
            <label>Department<select name="department_id"><?php foreach (array_filter($depts_all, fn($d)=>$d['type']==='OPD') as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; foreach (array_filter($depts_all, fn($d)=>$d['type']!=='OPD') as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?></select></label>
            <label>Visit date<input name="visit_date" type="date" value="<?= date('Y-m-d') ?>" required></label>
        </div>
        <p class="eyebrow" style="margin:1rem 0 .5rem">ITEMS CONSUMED</p>
        <div id="opd-items">
        <?php for ($i=0; $i<4; $i++): ?>
        <div class="line-item-row">
            <select name="item_id[]"><option value="">— Select item —</option><?php foreach ($items_all as $it): ?><option value="<?= $it['id'] ?>"><?= e($it['code']) ?> — <?= e($it['name']) ?> (Stock: <?= $it['quantity'] ?>)</option><?php endforeach; ?></select>
            <input name="batch[]" placeholder="Batch" style="width:100px">
            <input name="quantity[]" type="number" min="1" placeholder="Qty" style="width:70px">
        </div>
        <?php endfor; ?>
        </div>
        <button class="button button-primary" type="submit">Post OPD consumption</button>
        </form>
    </div>
</div>

<?php /* ════════════════════ IPD CONSUMPTION ══════════════════════════════════ */ ?>
<?php elseif ($view === 'ipd_consumption'): ?>
<div class="system-grid">
    <div class="panel">
        <div class="panel-heading"><h2>IPD consumption log</h2></div>
        <div class="table-wrap"><table>
            <thead><tr><th>Date</th><th>MR No.</th><th>Patient</th><th>Ward</th><th>Bed</th><th>Item</th><th>Qty</th></tr></thead>
            <tbody>
            <?php foreach ($pdo->query('SELECT c.*, i.name AS iname, i.code AS icode FROM consumption_records c LEFT JOIN items i ON i.id=c.item_id WHERE c.type="IPD" ORDER BY c.id DESC LIMIT 50') as $c): ?>
            <tr><td><?= e(date('d M Y', strtotime($c['created_at']))) ?></td><td><b><?= e($c['patient_mr']) ?></b></td><td><?= e($c['patient_name']) ?></td><td><?= e($c['ward']) ?></td><td><?= e($c['bed']) ?></td><td><?= e($c['icode']) ?> — <?= e($c['iname']) ?></td><td><?= e($c['quantity']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <div class="form-card">
        <h2>Record IPD / ward consumption</h2>
        <form method="post"><input type="hidden" name="action" value="ipd_consumption">
        <div class="form-grid one">
            <label>Patient MR No.<input name="patient_mr" value="<?= e('MR-' . date('Ymd') . '-' . sprintf('%03d', rand(100, 999))) ?>" required></label>
            <label>Patient name<input name="patient_name" value="IPD Patient <?= sprintf('%03d', rand(100, 999)) ?>"></label>
            <label>Department / ward<select name="department_id"><?php foreach (array_filter($depts_all, fn($d)=>$d['type']==='IPD') as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; foreach (array_filter($depts_all, fn($d)=>$d['type']!=='IPD') as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?></select></label>
            <label>Ward<input name="ward" value="Ward 1"></label>
            <label>Bed number<input name="bed" value="Bed <?= rand(1, 20) ?>"></label>
            <label>Admission date<input name="admission_date" type="date" value="<?= date('Y-m-d') ?>"></label>
        </div>
        <p class="eyebrow" style="margin:1rem 0 .5rem">ITEMS CONSUMED</p>
        <?php for ($i=0; $i<4; $i++): ?>
        <div class="line-item-row">
            <select name="item_id[]"><option value="">— Select item —</option><?php foreach ($items_all as $it): ?><option value="<?= $it['id'] ?>"><?= e($it['code']) ?> — <?= e($it['name']) ?> (Stock: <?= $it['quantity'] ?>)</option><?php endforeach; ?></select>
            <input name="batch[]" placeholder="Batch" style="width:100px">
            <input name="quantity[]" type="number" min="1" placeholder="Qty" style="width:70px">
        </div>
        <?php endfor; ?>
        <button class="button button-primary" type="submit">Post IPD consumption</button>
        </form>
    </div>
</div>

<?php /* ════════════════════ OT CONSUMPTION ═══════════════════════════════════ */ ?>
<?php elseif ($view === 'ot_consumption'): ?>
<div class="system-grid">
    <div class="panel">
        <div class="panel-heading"><h2>OT / surgery consumption log</h2></div>
        <div class="table-wrap"><table>
            <thead><tr><th>Date</th><th>Surgery No.</th><th>Patient</th><th>Surgeon</th><th>Procedure</th><th>Item</th><th>Qty</th></tr></thead>
            <tbody>
            <?php foreach ($pdo->query('SELECT c.*, i.name AS iname, i.code AS icode FROM consumption_records c LEFT JOIN items i ON i.id=c.item_id WHERE c.type="OT" ORDER BY c.id DESC LIMIT 50') as $c): ?>
            <tr><td><?= e(date('d M Y', strtotime($c['created_at']))) ?></td><td><b><?= e($c['surgery_number']) ?></b></td><td><?= e($c['patient_mr']) ?> — <?= e($c['patient_name']) ?></td><td><?= e($c['surgeon']) ?></td><td><?= e($c['procedure_name']) ?></td><td><?= e($c['icode']) ?> — <?= e($c['iname']) ?></td><td><?= e($c['quantity']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <div class="form-card">
        <h2>Post OT / surgery consumption</h2>
        <form method="post"><input type="hidden" name="action" value="ot_consumption">
        <div class="form-grid one">
            <label>Patient MR No.<input name="patient_mr" value="<?= e('MR-' . date('Ymd') . '-' . sprintf('%03d', rand(100, 999))) ?>" required></label>
            <label>Patient name<input name="patient_name" value="Surgical Patient <?= sprintf('%03d', rand(100, 999)) ?>"></label>
            <label>Surgeon<input name="surgeon" value="Dr. Asad Rizvi" required></label>
            <label>Procedure / surgery<input name="procedure_name" value="Phacoemulsification with IOL" required></label>
        </div>
        <p class="eyebrow" style="margin:1rem 0 .5rem">ITEMS / CONSUMABLES USED</p>
        <?php for ($i=0; $i<6; $i++): ?>
        <div class="line-item-row">
            <select name="item_id[]"><option value="">— Select item —</option><?php foreach ($items_all as $it): ?><option value="<?= $it['id'] ?>"><?= e($it['code']) ?> — <?= e($it['name']) ?> (Stock: <?= $it['quantity'] ?>)</option><?php endforeach; ?></select>
            <input name="batch[]" placeholder="Batch" style="width:100px">
            <input name="quantity[]" type="number" min="1" placeholder="Qty" style="width:70px">
        </div>
        <?php endfor; ?>
        <button class="button button-primary" type="submit">Post OT consumption</button>
        </form>
    </div>
</div>

<?php /* ════════════════════ EMERGENCY ═════════════════════════════════════ */ ?>
<?php elseif ($view === 'emergency'): ?>
<div class="system-grid">
    <div class="panel">
        <div class="panel-heading"><h2>Emergency consumption log</h2></div>
        <div class="table-wrap"><table>
            <thead><tr><th>Date/Time</th><th>Patient MR</th><th>Patient</th><th>Item</th><th>Qty</th></tr></thead>
            <tbody>
            <?php foreach ($pdo->query('SELECT c.*, i.name AS iname, i.code AS icode FROM consumption_records c LEFT JOIN items i ON i.id=c.item_id WHERE c.type="Emergency" ORDER BY c.id DESC LIMIT 50') as $c): ?>
            <tr><td><?= e(date('d M Y g:i A', strtotime($c['created_at']))) ?></td><td><b><?= e($c['patient_mr']) ?></b></td><td><?= e($c['patient_name']) ?></td><td><?= e($c['icode']) ?> — <?= e($c['iname']) ?></td><td><?= e($c['quantity']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <div class="form-card">
        <h2>Emergency rapid entry</h2>
        <p class="muted" style="margin-bottom:1rem">Post now, add authorization reference later. Full audit trail is maintained.</p>
        <form method="post"><input type="hidden" name="action" value="emergency">
        <div class="form-grid one">
            <label>Patient MR / Case No.<input name="patient_mr" value="<?= e('EMG-' . date('Ymd') . '-' . sprintf('%03d', rand(100, 999))) ?>"></label>
            <label>Patient name<input name="patient_name" value="Emergency Case <?= sprintf('%03d', rand(100, 999)) ?>"></label>
        </div>
        <p class="eyebrow" style="margin:1rem 0 .5rem">ITEMS USED</p>
        <?php for ($i=0; $i<4; $i++): ?>
        <div class="line-item-row">
            <select name="item_id[]"><option value="">— Select item —</option><?php foreach ($items_all as $it): ?><option value="<?= $it['id'] ?>"><?= e($it['code']) ?> — <?= e($it['name']) ?> (Stock: <?= $it['quantity'] ?>)</option><?php endforeach; ?></select>
            <input name="batch[]" placeholder="Batch" style="width:100px">
            <input name="quantity[]" type="number" min="1" placeholder="Qty" style="width:70px">
        </div>
        <?php endfor; ?>
        <button class="button button-primary" type="submit">Post emergency consumption</button>
        </form>
    </div>
</div>

<?php /* ════════════════════ ASSETS ═════════════════════════════════════════ */ ?>
<?php elseif ($view === 'assets'): ?>
<div class="system-grid">
    <div class="panel">
        <div class="panel-heading"><h2>Asset register</h2><a class="button button-primary" href="#add-asset">＋ Add asset</a></div>
        <div class="table-wrap"><table>
            <thead><tr><th>Tag / Asset</th><th>Category</th><th>Make / Model</th><th>Department</th><th>Custodian</th><th>Status</th><th>Warranty</th><th>AMC expiry</th></tr></thead>
            <tbody>
            <?php foreach ($pdo->query('SELECT * FROM assets ORDER BY id DESC') as $a): ?>
            <tr>
                <td><b><?= e($a['tag']) ?></b><br><span class="muted"><?= e($a['name']) ?></span></td>
                <td><?= e($a['category']) ?></td>
                <td><?= e($a['make']) ?> <?= e($a['model']) ?></td>
                <td><?= e($a['department']) ?></td>
                <td><?= e($a['custodian']) ?></td>
                <td><?= pill($a['status']) ?></td>
                <td><?= e($a['warranty_until']) ?></td>
                <td><?= e($a['amc_expiry']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <div class="form-card" id="add-asset">
        <h2>Add asset</h2>
        <form method="post"><input type="hidden" name="action" value="asset">
        <div class="form-grid one">
            <label>Asset tag<input name="tag" value="<?= e(next_number($pdo, 'AST', 'assets', 'tag')) ?>" required></label>
            <label>Asset name<input name="name" placeholder="e.g. Slit Lamp Microscope" required></label>
            <label>Category<select name="category"><option>Medical Equipment</option><option>Electrical Equipment</option><option>Furniture</option><option>Computers</option><option>Printers</option><option>Air Conditioners</option><option>Office Equipment</option><option>Other</option></select></label>
            <label>Make / Brand<input name="make" value="Standard"></label>
            <label>Model<input name="model" value="2026 Model"></label>
            <label>Serial number<input name="serial_number" value="SN-<?= sprintf('%06d', rand(100000, 999999)) ?>"></label>
            <label>Department<input name="department" value="Central Store" required></label>
            <label>Location<input name="location" value="Store Room A" required></label>
            <label>Custodian<input name="custodian" value="<?= e(current_user()['name'] ?? 'Store Incharge') ?>" required></label>
            <label>Status<select name="status"><option>In service</option><option>AMC due soon</option><option>Under repair</option><option>Condemned</option><option>Disposed</option></select></label>
            <label>Purchase date<input name="purchase_date" type="date" value="<?= date('Y-m-d') ?>"></label>
            <label>Cost (₹)<input name="cost" type="number" step="0.01" min="0" value="25000.00"></label>
            <label>Warranty / AMC until<input name="warranty_until" type="date" value="<?= date('Y-m-d', strtotime('+1 year')) ?>"></label>
        </div>
        <button class="button button-primary" type="submit">Add asset</button>
        </form>
    </div>
</div>

<?php /* ════════════════════ DEPARTMENTS ═══════════════════════════════════ */ ?>
<?php elseif ($view === 'departments'): ?>
<div class="system-grid">
    <div class="panel">
        <div class="panel-heading"><h2>Department directory</h2><a class="button button-primary" href="#add-dept">＋ Add department</a></div>
        <div class="table-wrap"><table>
            <thead><tr><th>Name</th><th>Type</th><th>Incharge</th><th>Phone</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($pdo->query('SELECT * FROM departments ORDER BY type, name') as $d): ?>
            <tr><td><b><?= e($d['name']) ?></b></td><td><?= pill($d['type']) ?></td><td><?= e($d['incharge']) ?></td><td><?= e($d['phone']) ?></td><td><?= pill($d['status']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <div class="form-card" id="add-dept">
        <h2>Add department</h2>
        <form method="post"><input type="hidden" name="action" value="department">
        <div class="form-grid one">
            <label>Department name<input name="name" placeholder="e.g. Corneal Clinic" required></label>
            <label>Type<select name="type"><option>OPD</option><option>IPD</option><option>OT</option><option>Emergency</option><option>Other</option></select></label>
            <label>Incharge<input name="incharge" value="Dr. <?= e(current_user()['name'] ?? 'Officer') ?>"></label>
            <label>Phone<input name="phone" value="0300-<?= sprintf('%07d', rand(1000000, 9999999)) ?>"></label>
        </div>
        <button class="button button-primary" type="submit">Add department</button>
        </form>
    </div>
</div>

<?php /* ════════════════════ RETURNS ═══════════════════════════════════════ */ ?>
<?php elseif ($view === 'returns'): ?>
<?php
$returnRows = $pdo->query('SELECT * FROM transactions WHERE type = "Return / adjustment" ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="panel">
    <div class="panel-heading"><h2>Returns register</h2></div>
    <div class="table-wrap"><table>
        <thead><tr><th>Reference</th><th>Party</th><th>Qty</th><th>Status</th><th>Date</th></tr></thead>
        <tbody>
        <?php if (empty($returnRows)): ?>
        <tr><td colspan="5" class="muted" style="text-align:center;padding:2rem">No returns recorded yet. Use the Quick Entry or Stock Adjustments module to record returns.</td></tr>
        <?php else: foreach ($returnRows as $r): ?>
        <tr><td><b><?= e($r['reference']) ?></b></td><td><?= e($r['party']) ?></td><td><?= e($r['quantity']) ?></td><td><?= pill($r['status']) ?></td><td><?= e(date('d M Y', strtotime($r['created_at']))) ?></td></tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table></div>
</div>

<?php /* ════════════════════ USERS ══════════════════════════════════════════ */ ?>
<?php elseif ($view === 'users'): ?>
<div class="system-grid">
    <div class="panel">
        <div class="panel-heading"><h2>Operator directory</h2><a class="button button-primary" href="#add-user">＋ Add user</a></div>
        <div class="table-wrap"><table>
            <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Department</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($pdo->query('SELECT * FROM users ORDER BY role, name') as $usr): ?>
            <tr><td><b><?= e($usr['name']) ?></b></td><td><?= e($usr['email']) ?></td><td><?= pill(role_label($usr['role'])) ?></td><td><?= e($usr['department']) ?></td><td><?= pill($usr['status']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <div class="form-card" id="add-user">
        <h2>Add user</h2>
        <form method="post"><input type="hidden" name="action" value="user">
        <div class="form-grid one">
            <label>Full name<input name="name" placeholder="e.g. Tariq Ahmed" required></label>
            <label>Email<input name="email" type="email" placeholder="user@siovs.edu.pk" required></label>
            <label>Password<input name="password" type="password" value="pass123" required></label>
            <label>Role<select name="role"><option value="data_entry">Data Entry</option><option value="store_officer">Store Officer</option><option value="purchase_officer">Purchase Officer</option><option value="dept_incharge">Dept. Incharge</option><option value="dd_finance">DD Finance</option><option value="internal_audit">Internal Audit</option><option value="admin">Administrator</option></select></label>
            <label>Department<input name="department" value="General Operations" required></label>
        </div>
        <button class="button button-primary" type="submit">Add user</button>
        </form>
    </div>
</div>
<div class="system-grid" style="margin-top:1.5rem">
    <div class="panel">
        <div class="panel-heading"><h2>Active and recent sessions</h2></div>
        <div class="table-wrap"><table>
            <thead><tr><th>User</th><th>Started</th><th>Last seen</th><th>Ended</th><th>IP address</th></tr></thead>
            <tbody>
            <?php foreach ($pdo->query('SELECT s.*, u.name FROM user_sessions s LEFT JOIN users u ON u.id=s.user_id ORDER BY s.id DESC LIMIT 30') as $session): ?>
            <tr>
                <td><b><?= e($session['name'] ?? 'Unknown') ?></b></td>
                <td><?= e($session['started_at']) ?></td>
                <td><?= e($session['last_seen_at']) ?></td>
                <td><?= e($session['ended_at'] ?: 'Active') ?></td>
                <td><?= e($session['ip_address']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <div class="panel">
        <div class="panel-heading"><h2>User activity log</h2></div>
        <div class="table-wrap"><table>
            <thead><tr><th>User</th><th>Action</th><th>Entity</th><th>Details</th><th>Date/time</th></tr></thead>
            <tbody>
            <?php foreach ($pdo->query('SELECT l.*, u.name FROM user_activity_log l LEFT JOIN users u ON u.id=l.user_id ORDER BY l.id DESC LIMIT 50') as $activity): ?>
            <tr>
                <td><?= e($activity['name'] ?? 'System') ?></td>
                <td><b><?= e($activity['action']) ?></b></td>
                <td><?= e($activity['entity']) ?><?= $activity['entity_id'] ? ' #' . e($activity['entity_id']) : '' ?></td>
                <td class="muted"><?= e($activity['details']) ?></td>
                <td><?= e($activity['created_at']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
</div>

<?php /* ════════════════════ QUICK ENTRY ════════════════════════════════════ */ ?>
<?php elseif ($view === 'quick-entry'): ?>
<div class="form-card" style="max-width:540px">
    <h2>Quick stock movement entry</h2>
    <form method="post"><input type="hidden" name="action" value="transaction">
    <div class="form-grid one">
        <label>Activity type<select name="type"><option>Purchase order</option><option>Goods received</option><option>Department issue</option><option>Patient consumption</option><option>Return / adjustment</option></select></label>
        <label>Reference<input name="reference" value="<?= e('TX-' . date('Y') . '-' . sprintf('%04d', rand(1000, 9999))) ?>" required></label>
        <label>Party / location<input name="party" value="Main Central Depot" required></label>
        <label>Quantity<input name="quantity" type="number" min="1" value="10" required></label>
        <label>Status<select name="status"><option>Approved</option><option>Pending</option><option>Inspected</option><option>Issued</option><option>Posted</option></select></label>
    </div>
    <button class="button button-primary" type="submit">Save record →</button>
    </form>
</div>

<?php /* ════════════════════ REPORTS ════════════════════════════════════════ */ ?>
<?php else: ?>
<?php
$rtype  = $_GET['rtype'] ?? 'stock';
$query  = match($rtype) {
    'stock'       => 'SELECT code AS reference, name AS party, quantity AS quantity, status, created_at FROM items ORDER BY name',
    'low_stock'   => 'SELECT code AS reference, name AS party, quantity AS quantity, status, created_at FROM items WHERE quantity <= reorder_level ORDER BY quantity',
    'expiry'      => 'SELECT code AS reference, name AS party, quantity AS quantity, expiry_date AS status, expiry_date AS created_at FROM items WHERE expiry_date IS NOT NULL ORDER BY expiry_date',
    'batches'     => 'SELECT code AS reference, name AS party, quantity AS quantity, batch_number AS status, expiry_date AS created_at FROM items WHERE batch_number IS NOT NULL ORDER BY expiry_date',
    'grn'         => 'SELECT grn_number AS reference, challan_number AS party, 0 AS quantity, inspection_status AS status, received_date AS created_at FROM grns ORDER BY id DESC',
    'po'          => 'SELECT po_number AS reference, purchase_reference AS party, total_value AS quantity, status, po_date AS created_at FROM purchase_orders ORDER BY id DESC',
    'transactions'=> 'SELECT type AS reference, party, quantity, status, created_at FROM transactions ORDER BY id DESC',
    'assets'      => 'SELECT tag AS reference, name AS party, 1 AS quantity, status, created_at FROM assets ORDER BY department',
    'opd'         => 'SELECT patient_mr AS reference, patient_name AS party, quantity, type AS status, created_at FROM consumption_records WHERE type="OPD" ORDER BY id DESC',
    'ot'          => 'SELECT surgery_number AS reference, procedure_name AS party, quantity, type AS status, created_at FROM consumption_records WHERE type="OT" ORDER BY id DESC',
    'adjustments' => 'SELECT adj_number AS reference, reason AS party, quantity, status, created_at FROM stock_adjustments ORDER BY id DESC',
    default       => 'SELECT type AS reference, party, quantity, status, created_at FROM transactions ORDER BY id DESC',
};
$rows = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
$reportTabs = [
    'stock'       => 'Stock ledger',
    'low_stock'   => 'Low stock',
    'expiry'      => 'Expiry report',
    'batches'     => 'Batch register',
    'grn'         => 'GRN register',
    'po'          => 'PO report',
    'transactions'=> 'Audit trail',
    'assets'      => 'Asset register',
    'opd'         => 'OPD consumption',
    'ot'          => 'OT consumption',
    'adjustments' => 'Adjustments',
];
?>
<div class="report-tabs">
    <?php foreach ($reportTabs as $k => $label): ?>
    <a class="tab-link <?= $rtype === $k ? 'active' : '' ?>" href="?view=reports&rtype=<?= $k ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>
<div class="panel">
    <div class="panel-heading">
        <h2><?= e($reportTabs[$rtype] ?? 'Report') ?></h2>
        <button class="button button-light" onclick="window.print()">Print / export</button>
    </div>
    <div class="table-wrap"><table>
        <thead><tr><th>Reference</th><th>Party / Location</th><th>Quantity</th><th>Status</th><th>Date / time</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
        <tr>
            <td><b><?= e($row['reference']) ?></b></td>
            <td><?= e($row['party']) ?></td>
            <td><?= e($row['quantity']) ?></td>
            <td><?= pill((string)$row['status']) ?></td>
            <td class="muted"><?= e($row['created_at']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
