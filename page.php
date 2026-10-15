<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

$allowed = [
    'reports','suppliers','purchase_orders','grn','inventory','batches','adjustments',
    'verification','requisitions','opd_consumption','ipd_consumption','ot_consumption',
    'emergency','assets','departments','returns','users','quick-entry',
    'reconciliation','deletions',
];
$view = $_GET['view'] ?? 'reports';
if (!in_array($view, $allowed, true)) { http_response_code(404); exit('Page not found'); }

require_login();
if (!can($view)) {
    if ((current_user()['role'] ?? '') === 'purchase_officer') {
        flash('Access restricted: Under SIOVS SOP Section 3 & 29, the Purchase Section is authorized to access Purchase Orders, Suppliers, Store Stock/Reorder Levels, and Procurement Reports only.', 'error');
        header('Location: ' . page_link('purchase_orders'));
        exit;
    }
    if ((current_user()['role'] ?? '') === 'store_officer') {
        flash('Access restricted: Under SIOVS SOP Section 27 & 29, the Store Section manages physical Store Inventory, GRNs, Issues, and Returns. Administrative and financial deletion approvals are restricted.', 'error');
        header('Location: index.php');
        exit;
    }
    if ((current_user()['role'] ?? '') === 'dd_finance') {
        flash('Access restricted: Under SIOVS SOP Sections 27, 28 & 29, the Deputy Director Finance oversees financial authorizations, valuations, capital assets, reconciliation, and audit deletions. Day-to-day warehouse receipt, clinical dispensing, and user administration are segregated.', 'error');
        header('Location: index.php');
        exit;
    }
    if ((current_user()['role'] ?? '') === 'dept_incharge') {
        flash('Access restricted: Under SIOVS Clinical SOPs, Department In-charges manage departmental sub-store indents, patient dispensing, and returns. Commercial procurement, warehouse receipts, and financial adjustments are restricted.', 'error');
        header('Location: index.php');
        exit;
    }
    flash('Access denied: You do not have permission to access that section.', 'error');
    header('Location: index.php');
    exit;
}

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
        $poId = !empty($_POST['po_id']) ? (int)$_POST['po_id'] : null;

        if ($poId) {
            // Update existing Draft PO
            $existing = $pdo->prepare('SELECT * FROM purchase_orders WHERE id=? AND status="Draft"');
            $existing->execute([$poId]);
            $poRow = $existing->fetch(PDO::FETCH_ASSOC);
            if (!$poRow) {
                flash('Only draft purchase orders can be edited.', 'error');
                header('Location: ' . page_link('purchase_orders'));
                exit;
            }

            $pdo->prepare('UPDATE purchase_orders SET
                supplier_id=?, po_date=?, purchase_reference=?, tender_reference=?,
                expected_delivery=?, notes=?, manual_register_name=?, manual_register_page=?,
                manual_register_entry=? WHERE id=? AND status="Draft"')
                ->execute([
                    (int)$_POST['supplier_id'],
                    $_POST['po_date'],
                    trim($_POST['purchase_reference']),
                    trim($_POST['tender_reference']),
                    $_POST['expected_delivery'],
                    trim($_POST['notes']),
                    trim($_POST['manual_register_name'] ?? 'Store Purchase Register'),
                    trim($_POST['manual_register_page'] ?? ''),
                    trim($_POST['manual_register_entry'] ?? ''),
                    $poId
                ]);

            // Re-insert line items
            $pdo->prepare('DELETE FROM po_items WHERE po_id=?')->execute([$poId]);
            $iStmt = $pdo->prepare('INSERT INTO po_items (po_id, item_id, quantity_ordered, unit, rate, taxes, total_value, warranty_applicable) VALUES(?,?,?,?,?,?,?,?)');
            foreach (($_POST['item_id'] ?? []) as $k => $iid) {
                $iid = resolve_item_id($pdo, $iid, $_POST['item_code'][$k] ?? '');
                $qty  = (int)($_POST['qty'][$k] ?? 0);
                $rate = (float)($_POST['rate'][$k] ?? 0);
                $tax  = (float)($_POST['tax'][$k] ?? 0);
                $warranty = !empty($_POST['warranty'][$k]) ? 1 : 0;
                if ($iid && $qty > 0) {
                    $tv = ($rate * $qty) + $tax;
                    $iStmt->execute([$poId, (int)$iid, $qty, $_POST['unit'][$k] ?? '', $rate, $tax, $tv, $warranty]);
                }
            }
            $pdo->prepare('UPDATE purchase_orders SET total_value=(SELECT COALESCE(SUM(total_value),0) FROM po_items WHERE po_id=?) WHERE id=?')
                ->execute([$poId, $poId]);

            save_uploaded_document($pdo, 'purchase_orders', $poId, 'po_document');
            log_activity($pdo, 'po_updated', 'purchase_orders', $poId);

            flash("Purchase order {$poRow['po_number']} updated successfully.");
            header('Location: ' . page_link('purchase_orders'));
            exit;
        }

        // New Purchase Order in Draft
        $pn = next_number($pdo, 'PO', 'purchase_orders', 'po_number');
        $pdo->prepare('INSERT INTO purchase_orders (
            po_number, po_date, supplier_id, purchase_reference, tender_reference,
            expected_delivery, notes, status, manual_register_name, manual_register_page,
            manual_register_entry, created_by
        ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $pn, $_POST['po_date'], (int)$_POST['supplier_id'],
                trim($_POST['purchase_reference']), trim($_POST['tender_reference']),
                $_POST['expected_delivery'], trim($_POST['notes']), 'Draft',
                trim($_POST['manual_register_name'] ?? 'Store Purchase Register'),
                trim($_POST['manual_register_page'] ?? ''),
                trim($_POST['manual_register_entry'] ?? ''),
                current_user()['id'] ?? null
            ]);
        $poId = (int)$pdo->lastInsertId();

        $iStmt = $pdo->prepare('INSERT INTO po_items (po_id, item_id, quantity_ordered, unit, rate, taxes, total_value, warranty_applicable) VALUES(?,?,?,?,?,?,?,?)');
        foreach (($_POST['item_id'] ?? []) as $k => $iid) {
            $iid = resolve_item_id($pdo, $iid, $_POST['item_code'][$k] ?? '');
            $qty  = (int)($_POST['qty'][$k] ?? 0);
            $rate = (float)($_POST['rate'][$k] ?? 0);
            $tax  = (float)($_POST['tax'][$k] ?? 0);
            $warranty = !empty($_POST['warranty'][$k]) ? 1 : 0;
            if ($iid && $qty > 0) {
                $tv = ($rate * $qty) + $tax;
                $iStmt->execute([$poId, (int)$iid, $qty, $_POST['unit'][$k] ?? '', $rate, $tax, $tv, $warranty]);
            }
        }
        $pdo->prepare('UPDATE purchase_orders SET total_value=(SELECT COALESCE(SUM(total_value),0) FROM po_items WHERE po_id=?) WHERE id=?')
            ->execute([$poId, $poId]);

        save_uploaded_document($pdo, 'purchase_orders', $poId, 'po_document');

        flash("Purchase order {$pn} created in Draft status."); header('Location: ' . page_link('purchase_orders')); exit;
    }

    // ── Approve Purchase Order ────────────────────────────────────────
    if ($action === 'approve_po') {
        $poId = (int)$_POST['po_id'];
        $pdo->prepare('UPDATE purchase_orders SET status="Approved" WHERE id=? AND status="Draft"')->execute([$poId]);
        log_activity($pdo, 'po_approved', 'purchase_orders', $poId);
        flash('Purchase order approved and authorized for delivery.');
        header('Location: ' . page_link('purchase_orders'));
        exit;
    }

    // ── Cancel Purchase Order ─────────────────────────────────────────
    if ($action === 'cancel_po') {
        $poId = (int)$_POST['po_id'];
        $pdo->prepare('UPDATE purchase_orders SET status="Cancelled" WHERE id=? AND status="Draft"')->execute([$poId]);
        log_activity($pdo, 'po_cancelled', 'purchase_orders', $poId);
        flash('Purchase order cancelled.');
        header('Location: ' . page_link('purchase_orders'));
        exit;
    }

    // ── GRN ──────────────────────────────────────────────────────────
    if ($action === 'grn') {
        if (!can('grn')) {
            flash('Access restricted: Purchase Section cannot directly alter Store stock. Physical receiving and GRN creation must be performed by the Store Officer.', 'error');
            header('Location: ' . page_link('purchase_orders'));
            exit;
        }
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

        $challanNo = trim($_POST['challan_number']);
        $suppId = !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
        $poId   = !empty($_POST['po_id']) ? (int)$_POST['po_id'] : null;
        $inspStatus = $_POST['inspection_status'] ?? 'Inspected';
        $isPendingInspection = ($inspStatus === 'Pending');

        $pdo->prepare('INSERT INTO grns (
            grn_number, po_id, supplier_id, challan_number, challan_date,
            received_date, inspection_status, remarks, manual_register_name,
            manual_register_page, manual_register_entry, status, created_by
        ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $gn, $poId, $suppId,
                $challanNo, $_POST['challan_date'],
                $_POST['received_date'], $inspStatus,
                trim($_POST['remarks']),
                trim($_POST['manual_register_name'] ?? 'Store Inward Register'),
                trim($_POST['manual_register_page'] ?? ''),
                trim($_POST['manual_register_entry'] ?? ''),
                'Active',
                current_user()['id'] ?? null
            ]);
        $grnId = (int)$pdo->lastInsertId();

        $gStmt = $pdo->prepare('INSERT INTO grn_items (
            grn_id, item_id, batch_number, manufacturing_date, expiry_date,
            quantity_ordered, quantity_received, quantity_accepted, quantity_rejected, rate
        ) VALUES(?,?,?,?,?,?,?,?,?,?)');

        foreach ($resolvedItems as $k => $iid) {
            $rec = (int)($_POST['qty_received'][$k] ?? 0);
            // If inspection is pending, accepted qty is 0 until Store Officer physically inspects
            $acc = $isPendingInspection ? 0 : (int)($_POST['qty_accepted'][$k] ?? 0);
            $rej = $isPendingInspection ? 0 : (int)($_POST['qty_rejected'][$k] ?? 0);
            $rate = (float)($_POST['rate'][$k] ?? 0);
            $batch = trim($_POST['batch'][$k] ?? '');
            $mfg = !empty($_POST['mfg_date'][$k]) ? $_POST['mfg_date'][$k] : null;
            $exp = !empty($_POST['exp_date'][$k]) ? $_POST['exp_date'][$k] : null;

            if ($iid && $rec > 0) {
                $gStmt->execute([
                    $grnId, (int)$iid, $batch,
                    $mfg, $exp,
                    (int)($_POST['qty_ordered'][$k] ?? 0), $rec, $acc, $rej,
                    $rate
                ]);

                // Update stock on accepted quantity via Universal Stock Engine ONLY if already inspected
                if (!$isPendingInspection && $acc > 0) {
                    record_stock_movement(
                        $pdo,
                        'GRN_RECEIPT',
                        $gn,
                        (int)$iid,
                        $acc,
                        $batch,
                        $exp,
                        null,
                        $suppId,
                        'GRN accepted delivery under DC #' . $challanNo,
                        current_user()['id'] ?? null,
                        $rate
                    );

                    if ($suppId) {
                        $pdo->prepare('UPDATE items SET supplier_id=? WHERE id=?')->execute([$suppId, (int)$iid]);
                    }
                    if ($batch !== '') {
                        $pdo->prepare('UPDATE items SET batch_number=?, expiry_date=? WHERE id=?')->execute([$batch, $exp, (int)$iid]);
                    }
                }
            }
        }

        // PO Fulfillment Check (only count accepted quantities)
        if ($poId) {
            $totOrdered = (int)$pdo->query("SELECT COALESCE(SUM(quantity_ordered),0) FROM po_items WHERE po_id={$poId}")->fetchColumn();
            $totAccepted = (int)$pdo->query("SELECT COALESCE(SUM(gi.quantity_accepted),0) FROM grn_items gi JOIN grns g ON g.id=gi.grn_id WHERE g.po_id={$poId}")->fetchColumn();
            $newStatus = ($totAccepted >= $totOrdered && $totOrdered > 0) ? 'Delivered' : 'Partially Received';
            $pdo->prepare('UPDATE purchase_orders SET status=? WHERE id=?')->execute([$newStatus, $poId]);
        }

        save_uploaded_document($pdo, 'grns', $grnId, 'grn_document');

        if ($isPendingInspection) {
            flash("GRN {$gn} recorded with Pending Inspection. Goods held in quarantine/unloading area until physical inspection is completed.");
        } else {
            flash("GRN {$gn} created and Main Store stock updated.");
        }
        header('Location: ' . page_link('grn')); exit;
    }

    // ── Complete GRN Inspection ──────────────────────────────────────
    if ($action === 'complete_grn_inspection') {
        if (!can('grn')) {
            flash('Access restricted: Only Store Officer can perform physical inspection and post stock.', 'error');
            header('Location: ' . page_link('grn'));
            exit;
        }
        $grnId = (int)($_POST['grn_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT g.*, s.name as sup_name FROM grns g LEFT JOIN suppliers s ON s.id=g.supplier_id WHERE g.id=? AND g.inspection_status="Pending"');
        $stmt->execute([$grnId]);
        $grn = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$grn) {
            flash('GRN not found or inspection already completed.', 'error');
            header('Location: ' . page_link('grn'));
            exit;
        }

        $challanNo = $grn['challan_number'];
        $suppId    = $grn['supplier_id'];
        $poId      = $grn['po_id'];
        $gn        = $grn['grn_number'];

        $resolvedItems = [];
        foreach (($_POST['item_id'] ?? []) as $k => $postedId) {
            $resolvedItems[$k] = resolve_item_id($pdo, $postedId, $_POST['item_code'][$k] ?? '');
        }
        reject_medicines_without_expiry($pdo, $resolvedItems, $_POST['exp_date'] ?? [], 'grn');

        $totalAccepted = 0;
        $totalRejected = 0;

        foreach ($resolvedItems as $k => $iid) {
            $giId  = (int)($_POST['grn_item_id'][$k] ?? 0);
            $acc   = (int)($_POST['qty_accepted'][$k] ?? 0);
            $rej   = (int)($_POST['qty_rejected'][$k] ?? 0);
            $rate  = (float)($_POST['rate'][$k] ?? 0);
            $batch = trim($_POST['batch'][$k] ?? '');
            $mfg   = !empty($_POST['mfg_date'][$k]) ? $_POST['mfg_date'][$k] : null;
            $exp   = !empty($_POST['exp_date'][$k]) ? $_POST['exp_date'][$k] : null;

            if ($giId && $iid) {
                $pdo->prepare('UPDATE grn_items SET quantity_accepted=?, quantity_rejected=?, batch_number=?, manufacturing_date=?, expiry_date=?, rate=? WHERE id=? AND grn_id=?')
                    ->execute([$acc, $rej, $batch, $mfg, $exp, $rate, $giId, $grnId]);
            }

            if ($iid && $acc > 0) {
                record_stock_movement(
                    $pdo,
                    'GRN_RECEIPT',
                    $gn,
                    (int)$iid,
                    $acc,
                    $batch,
                    $exp,
                    null,
                    $suppId,
                    'GRN accepted delivery under DC #' . $challanNo,
                    current_user()['id'] ?? null,
                    $rate
                );

                if ($suppId) {
                    $pdo->prepare('UPDATE items SET supplier_id=? WHERE id=?')->execute([$suppId, (int)$iid]);
                }
                if ($batch !== '') {
                    $pdo->prepare('UPDATE items SET batch_number=?, expiry_date=? WHERE id=?')->execute([$batch, $exp, (int)$iid]);
                }
            }

            $totalAccepted += $acc;
            $totalRejected += $rej;
        }

        $finalStatus = $_POST['inspection_status'] ?? 'Inspected';
        if ($totalAccepted === 0 && $totalRejected > 0) {
            $finalStatus = 'Rejected';
        } elseif ($totalRejected > 0 && $totalAccepted > 0) {
            $finalStatus = 'Partially accepted';
        }

        $remarks = trim($_POST['remarks'] ?? '');
        $pdo->prepare('UPDATE grns SET inspection_status=?, remarks=? WHERE id=?')
            ->execute([$finalStatus, $remarks, $grnId]);

        // PO Fulfillment Check
        if ($poId) {
            $totOrdered  = (int)$pdo->query("SELECT COALESCE(SUM(quantity_ordered),0) FROM po_items WHERE po_id={$poId}")->fetchColumn();
            $totAccepted = (int)$pdo->query("SELECT COALESCE(SUM(gi.quantity_accepted),0) FROM grn_items gi JOIN grns g ON g.id=gi.grn_id WHERE g.po_id={$poId}")->fetchColumn();
            $newStatus   = ($totAccepted >= $totOrdered && $totOrdered > 0) ? 'Delivered' : 'Partially Received';
            $pdo->prepare('UPDATE purchase_orders SET status=? WHERE id=?')->execute([$newStatus, $poId]);
        }

        log_activity($pdo, 'grn_inspected', 'grns', $grnId);
        flash("GRN {$gn} inspection completed ({$finalStatus}). {$totalAccepted} unit(s) posted to Main Store stock.");
        header('Location: ' . page_link('grn'));
        exit;
    }

    // ── Requisition ──────────────────────────────────────────────────
    if ($action === 'requisition') {
        $rn = next_number($pdo, 'REQ', 'requisitions', 'req_number');
        $pdo->prepare('INSERT INTO requisitions (
            req_number, department_id, req_date, purpose, requesting_officer,
            status, manual_register_name, manual_register_page, manual_register_entry, created_by
        ) VALUES(?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $rn, (int)$_POST['department_id'], $_POST['req_date'],
                trim($_POST['purpose']), trim($_POST['requesting_officer']),
                'Draft',
                trim($_POST['manual_register_name'] ?? 'Department Requisition Register'),
                trim($_POST['manual_register_page'] ?? ''),
                trim($_POST['manual_register_entry'] ?? ''),
                current_user()['id'] ?? null
            ]);
        $reqId = (int) $pdo->lastInsertId();
        $rStmt = $pdo->prepare('INSERT INTO requisition_items (req_id,item_id,required_quantity) VALUES(?,?,?)');
        foreach (($_POST['item_id'] ?? []) as $k => $iid) {
            $iid = resolve_item_id($pdo, $iid, $_POST['item_code'][$k] ?? '');
            $qty = (int)($_POST['required_qty'][$k] ?? 0);
            if ($iid && $qty > 0) $rStmt->execute([$reqId, (int)$iid, $qty]);
        }

        save_uploaded_document($pdo, 'requisitions', $reqId, 'req_document');
        flash("Requisition {$rn} submitted in Draft status."); header('Location: ' . page_link('requisitions')); exit;
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
        flash('Requisition approved and authorized for store issue.'); header('Location: ' . page_link('requisitions')); exit;
    }

    // ── Requisition issue (Multi-Tier Store -> Department Movement) ───
    if ($action === 'issue_requisition') {
        if (!can('store_officer') && !can('admin')) {
            flash('Unauthorized: Under SIOVS Segregation of Duties, only the Store Officer or Administrator can physically issue inventory from Central Store.', 'error');
            header('Location: ' . page_link('requisitions'));
            exit;
        }
        $reqId = (int)$_POST['req_id'];
        $rStmt = $pdo->prepare('SELECT r.*, d.name AS dname FROM requisitions r JOIN departments d ON d.id=r.department_id WHERE r.id=?');
        $rStmt->execute([$reqId]);
        $req = $rStmt->fetch(PDO::FETCH_ASSOC);

        $items = $pdo->prepare('SELECT * FROM requisition_items WHERE req_id=?');
        $items->execute([$reqId]);
        $requisitionItems = $items->fetchAll(PDO::FETCH_ASSOC);
        reject_expired_items($pdo, array_column($requisitionItems, 'item_id'), 'requisitions');

        foreach ($requisitionItems as $ri) {
            $issueQty = (int)($ri['approved_quantity'] ?: $ri['required_quantity']);
            if ($issueQty <= 0) continue;

            // Use FEFO to auto-select batch from Main Store
            $fefoBatches = get_fefo_batches($pdo, (int)$ri['item_id']);
            $batchToIssue = !empty($fefoBatches) ? $fefoBatches[0]['batch_number'] : '';
            $expDateToIssue = !empty($fefoBatches) ? $fefoBatches[0]['expiry_date'] : null;

            // Multi-tier movement: Decrements Main Store, Increments Department Stock, logs to stock_ledger
            record_stock_movement(
                $pdo,
                'STORE_ISSUE',
                $req['req_number'],
                (int)$ri['item_id'],
                $issueQty,
                $batchToIssue,
                $expDateToIssue,
                (int)$req['department_id'],
                null,
                'Issued to ' . $req['dname'] . ' under ' . $req['req_number'],
                current_user()['id'] ?? null
            );

            $pdo->prepare('UPDATE requisition_items SET issued_quantity=? WHERE id=?')->execute([$issueQty, $ri['id']]);
        }
        $pdo->prepare('UPDATE requisitions SET status="Issued", store_officer=? WHERE id=?')
            ->execute([current_user()['name'] ?? 'Store Officer', $reqId]);
        flash("Requisition {$req['req_number']} issued. Main Store stock transferred to {$req['dname']}."); header('Location: ' . page_link('requisitions')); exit;
    }

    // ── Department Return (Unused Material -> Main Store) ────────────
    if ($action === 'department_return') {
        $retNo = next_number($pdo, 'RET', 'department_returns', 'return_number');
        $deptId = (int)$_POST['department_id'];
        $returnDate = $_POST['return_date'] ?: date('Y-m-d');
        $reason = trim($_POST['reason'] ?? 'Unused stock returned');
        $returningOfficer = trim($_POST['returning_officer'] ?? (current_user()['name'] ?? 'Officer'));
        $receivingOfficer = trim($_POST['receiving_officer'] ?? (current_user()['name'] ?? 'Store Officer'));
        $manualReg = trim($_POST['manual_register_name'] ?? 'Store Return Register');
        $manualPage = trim($_POST['manual_register_page'] ?? '');
        $manualEntry = trim($_POST['manual_register_entry'] ?? '');

        $dStmt = $pdo->prepare('SELECT name FROM departments WHERE id=?');
        $dStmt->execute([$deptId]);
        $deptName = (string)$dStmt->fetchColumn();

        $pdo->prepare('INSERT INTO department_returns (
            return_number, department_id, return_date, reason, returning_officer,
            receiving_officer, manual_register_name, manual_register_page,
            manual_register_entry, status, created_by
        ) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $retNo, $deptId, $returnDate, $reason, $returningOfficer,
                $receivingOfficer, $manualReg, $manualPage, $manualEntry,
                'Verified', current_user()['id'] ?? null
            ]);
        $returnId = (int)$pdo->lastInsertId();

        $rItemStmt = $pdo->prepare('INSERT INTO department_return_items (return_id, item_id, batch_number, quantity_returned, remarks) VALUES(?,?,?,?,?)');

        foreach (($_POST['item_id'] ?? []) as $k => $iid) {
            $iid = resolve_item_id($pdo, $iid, $_POST['item_code'][$k] ?? '');
            $qty = (int)($_POST['quantity'][$k] ?? 0);
            $batch = trim($_POST['batch'][$k] ?? '');
            $exp = !empty($_POST['exp_date'][$k]) ? $_POST['exp_date'][$k] : null;
            $rem = trim($_POST['item_remarks'][$k] ?? '');

            if ($iid && $qty > 0) {
                $rItemStmt->execute([$returnId, (int)$iid, $batch, $qty, $rem]);

                // Reverse movement: Decrement department stock, increment Main Store stock and item_batches
                record_stock_movement(
                    $pdo,
                    'DEPT_RETURN',
                    $retNo,
                    (int)$iid,
                    $qty,
                    $batch,
                    $exp,
                    $deptId,
                    null,
                    'Return from ' . $deptName . ': ' . $reason,
                    current_user()['id'] ?? null
                );
            }
        }

        save_uploaded_document($pdo, 'department_returns', $returnId, 'return_document');
        flash("Return {$retNo} received and verified. Stock returned to Main Store.");
        header('Location: ' . page_link('returns'));
        exit;
    }

    // ── OPD Consumption ──────────────────────────────────────────────
    if ($action === 'opd_consumption') {
        reject_expired_items($pdo, $_POST['item_id'] ?? [], 'opd_consumption');
        $deptId = (int)($_POST['department_id'] ?: 0);
        if (!$deptId) {
            $deptId = (int)$pdo->query('SELECT id FROM departments WHERE type="OPD" LIMIT 1')->fetchColumn();
        }
        $pmr = trim($_POST['patient_mr'] ?? '');
        $pname = trim($_POST['patient_name'] ?? '');
        $visitDate = $_POST['visit_date'] ?: date('Y-m-d');
        $manualReg = trim($_POST['manual_register_name'] ?? 'OPD Daily Dispensing Register');
        $manualPage = trim($_POST['manual_register_page'] ?? '');
        $manualEntry = trim($_POST['manual_register_entry'] ?? '');
        $dispenseRef = 'OPD-' . date('Ymd') . '-' . sprintf('%04d', rand(1000, 9999));

        $cStmt = $pdo->prepare('INSERT INTO consumption_records (
            type, patient_mr, patient_name, department_id, visit_date, item_id,
            batch_number, quantity, manual_register_name, manual_register_page,
            manual_register_entry, status, posted_by
        ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');

        $count = 0;
        foreach (($_POST['item_id'] ?? []) as $k => $iid) {
            $iid = resolve_item_id($pdo, $iid, $_POST['item_code'][$k] ?? '');
            $qty = (int)($_POST['quantity'][$k] ?? 0);
            $batch = trim($_POST['batch'][$k] ?? '');
            $exp = !empty($_POST['exp_date'][$k]) ? $_POST['exp_date'][$k] : null;

            if ($iid && $qty > 0) {
                $cStmt->execute([
                    'OPD', $pmr, $pname, $deptId ?: null, $visitDate, (int)$iid,
                    $batch, $qty, $manualReg, $manualPage, $manualEntry, 'Active',
                    current_user()['id'] ?? null
                ]);
                record_stock_movement(
                    $pdo,
                    'DEPT_CONSUMPTION',
                    $dispenseRef,
                    (int)$iid,
                    $qty,
                    $batch,
                    $exp,
                    $deptId ?: null,
                    null,
                    "OPD Dispensing: {$pmr} - {$pname}",
                    current_user()['id'] ?? null
                );
                $count++;
            }
        }
        flash("OPD consumption recorded for patient {$pmr} ({$count} item lines dispensed).");
        header('Location: ' . page_link('opd_consumption'));
        exit;
    }

    // ── IPD Consumption ──────────────────────────────────────────────
    if ($action === 'ipd_consumption') {
        reject_expired_items($pdo, $_POST['item_id'] ?? [], 'ipd_consumption');
        $deptId = (int)($_POST['department_id'] ?: 0);
        if (!$deptId) {
            $deptId = (int)$pdo->query('SELECT id FROM departments WHERE type="IPD" LIMIT 1')->fetchColumn();
        }
        $pmr = trim($_POST['patient_mr'] ?? '');
        $pname = trim($_POST['patient_name'] ?? '');
        $ward = trim($_POST['ward'] ?? '');
        $bed = trim($_POST['bed'] ?? '');
        $admDate = $_POST['admission_date'] ?: date('Y-m-d');
        $manualReg = trim($_POST['manual_register_name'] ?? 'IPD Ward Stock Register');
        $manualPage = trim($_POST['manual_register_page'] ?? '');
        $manualEntry = trim($_POST['manual_register_entry'] ?? '');
        $ipdRef = 'IPD-' . date('Ymd') . '-' . sprintf('%04d', rand(1000, 9999));

        $cStmt = $pdo->prepare('INSERT INTO consumption_records (
            type, patient_mr, patient_name, department_id, ward, bed, admission_date,
            item_id, batch_number, quantity, manual_register_name, manual_register_page,
            manual_register_entry, status, posted_by
        ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');

        $count = 0;
        foreach (($_POST['item_id'] ?? []) as $k => $iid) {
            $iid = resolve_item_id($pdo, $iid, $_POST['item_code'][$k] ?? '');
            $qty = (int)($_POST['quantity'][$k] ?? 0);
            $batch = trim($_POST['batch'][$k] ?? '');
            $exp = !empty($_POST['exp_date'][$k]) ? $_POST['exp_date'][$k] : null;

            if ($iid && $qty > 0) {
                $cStmt->execute([
                    'IPD', $pmr, $pname, $deptId ?: null, $ward, $bed, $admDate, (int)$iid,
                    $batch, $qty, $manualReg, $manualPage, $manualEntry, 'Active',
                    current_user()['id'] ?? null
                ]);
                record_stock_movement(
                    $pdo,
                    'DEPT_CONSUMPTION',
                    $ipdRef,
                    (int)$iid,
                    $qty,
                    $batch,
                    $exp,
                    $deptId ?: null,
                    null,
                    "IPD: Ward {$ward}, Bed {$bed} - {$pmr} ({$pname})",
                    current_user()['id'] ?? null
                );
                $count++;
            }
        }
        flash("IPD consumption recorded for {$pmr} (Ward {$ward}, Bed {$bed}).");
        header('Location: ' . page_link('ipd_consumption'));
        exit;
    }

    // ── OT Consumption ───────────────────────────────────────────────
    if ($action === 'ot_consumption') {
        reject_expired_items($pdo, $_POST['item_id'] ?? [], 'ot_consumption');
        $deptId = (int)($_POST['department_id'] ?: 0);
        if (!$deptId) {
            $deptId = (int)$pdo->query('SELECT id FROM departments WHERE type="OT" LIMIT 1')->fetchColumn();
        }
        $sn = trim($_POST['surgery_number'] ?? '') ?: next_number($pdo, 'OT', 'consumption_records', 'surgery_number');
        $pmr = trim($_POST['patient_mr'] ?? '');
        $pname = trim($_POST['patient_name'] ?? '');
        $surgeon = trim($_POST['surgeon'] ?? 'Surgeon');
        $procedure = trim($_POST['procedure_name'] ?? 'Ophthalmic Surgery');
        $manualReg = trim($_POST['manual_register_name'] ?? 'OT Surgery Consumption Register');
        $manualPage = trim($_POST['manual_register_page'] ?? '');
        $manualEntry = trim($_POST['manual_register_entry'] ?? '');

        $cStmt = $pdo->prepare('INSERT INTO consumption_records (
            type, patient_mr, patient_name, department_id, surgery_number, surgeon,
            procedure_name, item_id, batch_number, quantity, manual_register_name,
            manual_register_page, manual_register_entry, status, posted_by
        ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');

        $count = 0;
        foreach (($_POST['item_id'] ?? []) as $k => $iid) {
            $iid = resolve_item_id($pdo, $iid, $_POST['item_code'][$k] ?? '');
            $qty = (int)($_POST['quantity'][$k] ?? 0);
            $batch = trim($_POST['batch'][$k] ?? '');
            $exp = !empty($_POST['exp_date'][$k]) ? $_POST['exp_date'][$k] : null;

            if ($iid && $qty > 0) {
                $cStmt->execute([
                    'OT', $pmr, $pname, $deptId ?: null, $sn, $surgeon, $procedure,
                    (int)$iid, $batch, $qty, $manualReg, $manualPage, $manualEntry,
                    'Active', current_user()['id'] ?? null
                ]);
                record_stock_movement(
                    $pdo,
                    'DEPT_CONSUMPTION',
                    $sn,
                    (int)$iid,
                    $qty,
                    $batch,
                    $exp,
                    $deptId ?: null,
                    null,
                    "OT: {$procedure} ({$sn}) - {$pmr} / Surgeon: {$surgeon}",
                    current_user()['id'] ?? null
                );
                $count++;
            }
        }
        flash("OT consumption for surgery {$sn} ({$procedure}) posted.");
        header('Location: ' . page_link('ot_consumption'));
        exit;
    }

    // ── Emergency Consumption ────────────────────────────────────────
    if ($action === 'emergency') {
        reject_expired_items($pdo, $_POST['item_id'] ?? [], 'emergency');
        $deptId = (int)($_POST['department_id'] ?: 0);
        if (!$deptId) {
            $deptId = (int)$pdo->query('SELECT id FROM departments WHERE type="Emergency" LIMIT 1')->fetchColumn();
        }
        $emgRef = trim($_POST['patient_mr'] ?? '') ?: ('EMG-' . date('Ymd') . '-' . sprintf('%03d', rand(100, 999)));
        $pname = trim($_POST['patient_name'] ?? 'Emergency Case');
        $manualReg = trim($_POST['manual_register_name'] ?? 'Emergency Rapid Dispensing Register');
        $manualPage = trim($_POST['manual_register_page'] ?? '');
        $manualEntry = trim($_POST['manual_register_entry'] ?? '');

        $cStmt = $pdo->prepare('INSERT INTO consumption_records (
            type, patient_mr, patient_name, department_id, item_id, batch_number,
            quantity, manual_register_name, manual_register_page, manual_register_entry,
            status, posted_by
        ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');

        $count = 0;
        foreach (($_POST['item_id'] ?? []) as $k => $iid) {
            $iid = resolve_item_id($pdo, $iid, $_POST['item_code'][$k] ?? '');
            $qty = (int)($_POST['quantity'][$k] ?? 0);
            $batch = trim($_POST['batch'][$k] ?? '');
            $exp = !empty($_POST['exp_date'][$k]) ? $_POST['exp_date'][$k] : null;

            if ($iid && $qty > 0) {
                $cStmt->execute([
                    'Emergency', $emgRef, $pname, $deptId ?: null, (int)$iid, $batch,
                    $qty, $manualReg, $manualPage, $manualEntry, 'Unregularized',
                    current_user()['id'] ?? null
                ]);
                record_stock_movement(
                    $pdo,
                    'DEPT_CONSUMPTION',
                    $emgRef,
                    (int)$iid,
                    $qty,
                    $batch,
                    $exp,
                    $deptId ?: null,
                    null,
                    "Emergency: {$emgRef} - {$pname}",
                    current_user()['id'] ?? null
                );
                $count++;
            }
        }
        flash("Emergency rapid dispensing {$emgRef} recorded ({$count} items). Awaiting regularization.");
        header('Location: ' . page_link('emergency'));
        exit;
    }

    // ── Regularize Emergency Consumption ──────────────────────────────
    if ($action === 'regularize_emergency') {
        $recId = (int)$_POST['record_id'];
        $officialMr = trim($_POST['official_mr']);
        $pname = trim($_POST['patient_name'] ?? '');
        $manualReg = trim($_POST['manual_register_name'] ?? 'Emergency Register');
        $manualPage = trim($_POST['manual_register_page'] ?? '');
        $manualEntry = trim($_POST['manual_register_entry'] ?? '');

        $pdo->prepare('UPDATE consumption_records SET
            patient_mr = CASE WHEN ? != "" THEN ? ELSE patient_mr END,
            patient_name = CASE WHEN ? != "" THEN ? ELSE patient_name END,
            manual_register_name = ?,
            manual_register_page = ?,
            manual_register_entry = ?,
            status = "Regularized",
            regularized_at = CURRENT_TIMESTAMP,
            regularized_by = ?
            WHERE id = ? AND type = "Emergency"')
            ->execute([
                $officialMr, $officialMr,
                $pname, $pname,
                $manualReg, $manualPage, $manualEntry,
                current_user()['id'] ?? null,
                $recId
            ]);
        flash('Emergency record successfully regularized with official MR number and manual register reference.');
        header('Location: ' . page_link('emergency'));
        exit;
    }

    // ── Stock Adjustment ─────────────────────────────────────────────
    if ($action === 'adjustment') {
        $an  = next_number($pdo, 'ADJ', 'stock_adjustments', 'adj_number');
        $qty = (int)$_POST['quantity'];
        if (($_POST['direction'] ?? 'out') === 'out') $qty = -$qty;
        $pdo->prepare('INSERT INTO stock_adjustments (
            adj_number, item_id, reason, quantity, value, notes, requesting_user_id,
            status, manual_register_name, manual_register_page, manual_register_entry
        ) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $an, (int)$_POST['item_id'], $_POST['reason'], $qty,
                (float)($_POST['value'] ?? 0), trim($_POST['notes'] ?? ''),
                current_user()['id'] ?? null, 'Pending',
                trim($_POST['manual_register_name'] ?? 'Store Adjustment Register'),
                trim($_POST['manual_register_page'] ?? ''),
                trim($_POST['manual_register_entry'] ?? '')
            ]);
        flash("Adjustment {$an} submitted to DD Finance for authorization.");
        header('Location: ' . page_link('adjustments'));
        exit;
    }

    // ── Approve Adjustment (DD Finance / Admin only) ─────────────────
    if ($action === 'approve_adjustment') {
        if (!can('dd_finance') && !can('competent_authority') && (current_user()['role'] ?? '') !== 'admin') {
            flash('Unauthorized: Only DD Finance, Competent Authority, or Admin can authorize stock adjustments.', 'error');
            header('Location: ' . page_link('adjustments'));
            exit;
        }
        $adjId = (int)$_POST['adj_id'];
        $adj   = $pdo->prepare('SELECT * FROM stock_adjustments WHERE id=?');
        $adj->execute([$adjId]);
        $adjRow = $adj->fetch(PDO::FETCH_ASSOC);
        if ($adjRow && $adjRow['status'] === 'Pending') {
            $aq = (int)$adjRow['quantity'];
            $type = $aq >= 0 ? 'ADJUSTMENT_IN' : 'ADJUSTMENT_OUT';
            record_stock_movement(
                $pdo,
                $type,
                $adjRow['adj_number'],
                (int)$adjRow['item_id'],
                abs($aq),
                null,
                null,
                null,
                null,
                'Adjustment approved by ' . (current_user()['name'] ?? 'DD Finance') . ': ' . $adjRow['reason'],
                current_user()['id'] ?? null
            );
            $pdo->prepare('UPDATE stock_adjustments SET status="Approved", authorizing_officer=? WHERE id=?')
                ->execute([current_user()['name'] ?? 'DD Finance', $adjId]);
            flash("Adjustment {$adjRow['adj_number']} approved and posted to stock ledger.");
        }
        header('Location: ' . page_link('adjustments'));
        exit;
    }

    // ── Reject Adjustment (DD Finance / Admin only) ──────────────────
    if ($action === 'reject_adjustment') {
        if (!can('dd_finance') && !can('competent_authority') && (current_user()['role'] ?? '') !== 'admin') {
            flash('Unauthorized: Only DD Finance, Competent Authority, or Admin can reject stock adjustments.', 'error');
            header('Location: ' . page_link('adjustments'));
            exit;
        }
        $adjId = (int)$_POST['adj_id'];
        $reason = trim($_POST['rejection_reason'] ?? 'Disapproved by DD Finance');
        $adj   = $pdo->prepare('SELECT * FROM stock_adjustments WHERE id=?');
        $adj->execute([$adjId]);
        $adjRow = $adj->fetch(PDO::FETCH_ASSOC);
        if ($adjRow && $adjRow['status'] === 'Pending') {
            $updatedNotes = trim(($adjRow['notes'] ?? '') . ' [Rejected by ' . (current_user()['name'] ?? 'DD Finance') . ': ' . $reason . ']');
            $pdo->prepare('UPDATE stock_adjustments SET status="Rejected", authorizing_officer=?, notes=? WHERE id=?')
                ->execute([current_user()['name'] ?? 'DD Finance', $updatedNotes, $adjId]);
            flash("Adjustment {$adjRow['adj_number']} has been rejected. Stock ledger was not modified.");
        }
        header('Location: ' . page_link('adjustments'));
        exit;
    }

    // ── Stock Verification (Draft & Submit Lifecycle) ─────────────────
    if ($action === 'verification') {
        $verId = !empty($_POST['verification_id']) ? (int)$_POST['verification_id'] : 0;
        $submissionMode = strtolower(trim($_POST['submission_mode'] ?? 'submitted'));
        $targetStatus = ($submissionMode === 'draft') ? 'Draft' : 'Submitted';

        if ($verId > 0) {
            // Updating existing verification
            $existing = $pdo->prepare('SELECT * FROM stock_verifications WHERE id = ?');
            $existing->execute([$verId]);
            $verRow = $existing->fetch(PDO::FETCH_ASSOC);

            if (!$verRow) {
                flash('Verification record not found.', 'error');
                header('Location: ' . page_link('verification'));
                exit;
            }

            // Check if any adjustments have already been approved by DD Finance
            $apprCheck = $pdo->prepare('SELECT COUNT(*) FROM stock_adjustments WHERE verification_id = ? AND status = "Approved"');
            $apprCheck->execute([$verId]);
            if ((int)$apprCheck->fetchColumn() > 0) {
                flash('This verification has already been approved and adjusted by DD Finance and is permanently locked.', 'error');
                header('Location: ' . page_link('verification'));
                exit;
            }

            $vn = $verRow['ver_number'];
            $pdo->prepare('UPDATE stock_verifications SET verification_date = ?, committee_members = ?, remarks = ?, status = ? WHERE id = ?')
                ->execute([
                    $_POST['verification_date'],
                    trim($_POST['committee_members']),
                    trim($_POST['remarks']),
                    $targetStatus,
                    $verId
                ]);

            // Clear old items and old pending adjustments
            $pdo->prepare('DELETE FROM stock_verification_items WHERE verification_id = ?')->execute([$verId]);
            $pdo->prepare('DELETE FROM stock_adjustments WHERE verification_id = ? AND status = "Pending"')->execute([$verId]);
        } else {
            // Creating new verification
            $vn = next_number($pdo, 'VER', 'stock_verifications', 'ver_number');
            $pdo->prepare('INSERT INTO stock_verifications (ver_number, verification_date, committee_members, remarks, status, created_by) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([
                    $vn,
                    $_POST['verification_date'],
                    trim($_POST['committee_members']),
                    trim($_POST['remarks']),
                    $targetStatus,
                    current_user()['id'] ?? null
                ]);
            $verId = (int)$pdo->lastInsertId();
        }

        $vStmt = $pdo->prepare('INSERT INTO stock_verification_items (verification_id, item_id, system_quantity, physical_quantity, difference, explanation) VALUES (?, ?, ?, ?, ?, ?)');

        $adjCount = 0;
        foreach (($_POST['item_id'] ?? []) as $k => $iid) {
            $phys = (int)($_POST['physical_qty'][$k] ?? 0);
            $sys  = (int)($_POST['system_qty'][$k]  ?? 0);
            $diff = $phys - $sys;
            if ($iid) {
                $expl = trim($_POST['explanation'][$k] ?? '');
                $vStmt->execute([$verId, (int)$iid, $sys, $phys, $diff, $expl]);

                // Only generate adjustments if submitted (not draft) and generate_adjustments is set
                if ($targetStatus === 'Submitted' && $diff !== 0 && !empty($_POST['generate_adjustments'])) {
                    $an = next_number($pdo, 'ADJ', 'stock_adjustments', 'adj_number');
                    $reason = $diff > 0 ? 'Physical Verification Excess' : 'Physical Verification Shortage';
                    $pdo->prepare('INSERT INTO stock_adjustments (
                        adj_number, item_id, reason, quantity, value, notes, requesting_user_id,
                        status, manual_register_name, manual_register_page, manual_register_entry, verification_id
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                        ->execute([
                            $an, (int)$iid, $reason, $diff, 0,
                            "Auto-generated from Physical Verification {$vn}: " . $expl,
                            current_user()['id'] ?? null, 'Pending',
                            'Physical Stock Verification Register', '', '',
                            $verId
                        ]);
                    $adjCount++;
                }
            }
        }

        if ($targetStatus === 'Draft') {
            flash("Draft Physical Verification {$vn} saved. Counts are staged (0 adjustments created).");
        } else {
            flash("Verification {$vn} saved." . ($adjCount > 0 ? " {$adjCount} pending discrepancy adjustment(s) created for DD Finance authorization." : ''));
        }
        header('Location: ' . page_link('verification'));
        exit;
    }

    // ── Void Physical Stock Verification ──────────────────────────────
    if ($action === 'void_verification') {
        $verId = (int)($_POST['verification_id'] ?? 0);
        $vStmt = $pdo->prepare('SELECT * FROM stock_verifications WHERE id = ?');
        $vStmt->execute([$verId]);
        $vRow = $vStmt->fetch(PDO::FETCH_ASSOC);

        if (!$vRow) {
            flash('Verification record not found.', 'error');
            header('Location: ' . page_link('verification'));
            exit;
        }

        // Check if any adjustments are already approved
        $apprCheck = $pdo->prepare('SELECT COUNT(*) FROM stock_adjustments WHERE verification_id = ? AND status = "Approved"');
        $apprCheck->execute([$verId]);
        if ((int)$apprCheck->fetchColumn() > 0) {
            flash('Cannot void: adjustments for this audit have already been approved by DD Finance and posted to the stock ledger.', 'error');
            header('Location: ' . page_link('verification'));
            exit;
        }

        // Void the verification and cancel any pending adjustments
        $pdo->prepare('UPDATE stock_verifications SET status = "Voided" WHERE id = ?')->execute([$verId]);
        $pdo->prepare('UPDATE stock_adjustments SET status = "Cancelled" WHERE verification_id = ? AND status = "Pending"')->execute([$verId]);

        flash("Physical Verification {$vRow['ver_number']} has been voided. All linked pending adjustment requests were cancelled.");
        header('Location: ' . page_link('verification'));
        exit;
    }

    // ── Submit Deletion Request (Ordinary Staff / Store Officers) ───
    if ($action === 'submit_deletion_request') {
        $table = trim($_POST['table_name'] ?? '');
        $recId = (int)($_POST['record_id'] ?? 0);
        $refNo = trim($_POST['reference_no'] ?? '');
        $sec = trim($_POST['section'] ?? 'Store Section');
        $desc = trim($_POST['description'] ?? '');
        $qty = (int)($_POST['quantity'] ?? 0);
        $val = (float)($_POST['value'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');

        if (!$table || !$recId || !$reason) {
            flash('Error: Missing required fields for deletion request.', 'error');
            header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? page_link('deletions')));
            exit;
        }

        $docPath = null;
        if (!empty($_FILES['supporting_doc']['name']) && $_FILES['supporting_doc']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['supporting_doc']['name'], PATHINFO_EXTENSION));
            $safeName = 'DEL_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $safeName;
            if (move_uploaded_file($_FILES['supporting_doc']['tmp_name'], $targetPath)) {
                $docPath = 'storage/uploads/' . $safeName;
            }
        }

        $reqNo = request_deletion(
            $pdo, $table, $recId, $refNo, $sec, $desc, $qty, $val, $reason, $docPath, current_user()['id'] ?? null
        );
        flash("Deletion Request {$reqNo} submitted to DD Finance queue for authorization.");
        header('Location: ' . page_link('deletions'));
        exit;
    }

    // ── Approve Deletion Request (DD Finance / Competent Authority) ──
    if ($action === 'approve_deletion') {
        if (!can('dd_finance') && !can('competent_authority') && (current_user()['role'] ?? '') !== 'admin') {
            flash('Unauthorized: Only DD Finance or Competent Authority can approve deletions.', 'error');
            header('Location: ' . page_link('deletions'));
            exit;
        }
        $reqId = (int)$_POST['request_id'];
        $remarks = trim($_POST['remarks'] ?? 'Approved by DD Finance');
        $ok = approve_deletion_request($pdo, $reqId, (int)(current_user()['id'] ?? 1), $remarks);
        if ($ok) {
            flash("Deletion request #{$reqId} approved. Stock balances and ledger records have been rolled back.");
        } else {
            flash("Unable to approve deletion request #{$reqId}.", 'error');
        }
        header('Location: ' . page_link('deletions'));
        exit;
    }

    // ── Reject Deletion Request (DD Finance / Competent Authority) ───
    if ($action === 'reject_deletion') {
        if (!can('dd_finance') && !can('competent_authority') && (current_user()['role'] ?? '') !== 'admin') {
            flash('Unauthorized: Only DD Finance or Competent Authority can reject deletions.', 'error');
            header('Location: ' . page_link('deletions'));
            exit;
        }
        $reqId = (int)$_POST['request_id'];
        $remarks = trim($_POST['remarks'] ?? 'Rejected by DD Finance');
        $ok = reject_deletion_request($pdo, $reqId, (int)(current_user()['id'] ?? 1), $remarks);
        if ($ok) {
            flash("Deletion request #{$reqId} rejected. Target record restored.");
        } else {
            flash("Unable to reject deletion request #{$reqId}.", 'error');
        }
        header('Location: ' . page_link('deletions'));
        exit;
    }

    // ── Manual vs Digital Reconciliation ─────────────────────────────
    if ($action === 'reconcile_register') {
        $rn = next_number($pdo, 'REC', 'manual_reconciliations', 'rec_number');
        $regName = trim($_POST['register_name']);
        $pageNo = trim($_POST['page_no']);
        $entryNo = trim($_POST['entry_no']);
        $txType = trim($_POST['transaction_type']);
        $txRef = trim($_POST['transaction_ref']);
        $regQty = (int)$_POST['register_qty'];
        $sysQty = (int)$_POST['system_qty'];
        $diff = $regQty - $sysQty;
        $reason = trim($_POST['reason'] ?? '');
        $corrective = trim($_POST['corrective_action'] ?? '');
        $status = ($diff === 0) ? 'Matched' : 'Discrepancy';

        $pdo->prepare('INSERT INTO manual_reconciliations (
            rec_number, register_name, page_no, entry_no, transaction_type,
            transaction_ref, register_qty, system_qty, difference, reason,
            corrective_action, status, verified_by, verified_date
        ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $rn, $regName, $pageNo, $entryNo, $txType,
                $txRef, $regQty, $sysQty, $diff, $reason,
                $corrective, $status, current_user()['id'] ?? null,
                $_POST['verified_date'] ?: date('Y-m-d')
            ]);
        flash("Reconciliation entry {$rn} recorded ({$status}).");
        header('Location: ' . page_link('reconciliation'));
        exit;
    }

    // ── Department ───────────────────────────────────────────────────
    if ($action === 'department') {
        $pdo->prepare('INSERT INTO departments (name,type,incharge,phone,status) VALUES(?,?,?,?,?)')
            ->execute([trim($_POST['name']), $_POST['type'], trim($_POST['incharge']), trim($_POST['phone']), 'Active']);
        flash('Department added.'); header('Location: ' . page_link('departments')); exit;
    }

    // ── Item ─────────────────────────────────────────────────────────
    if ($action === 'item') {
        if ((current_user()['role'] ?? '') === 'purchase_officer') {
            flash('Access restricted: The Purchase Section cannot directly initialize or alter Store stock. Stock will be increased only after the Store receives material and generates a GRN (SOP Section 3).', 'error');
            header('Location: ' . page_link('inventory'));
            exit;
        }
        $category = trim((string)($_POST['category'] ?? ''));
        $expiryDate = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
        if (in_array($category, ['Medicines', 'Ophthalmic Medicines'], true) && !$expiryDate) {
            flash('An expiry date is required for all medicines.', 'error');
            header('Location: ' . page_link('inventory'));
            exit;
        }
        $itemCode = next_number($pdo, 'ITEM', 'items', 'code');
        $qty = (int)($_POST['quantity'] ?? 0);
        $batch = trim($_POST['batch_number'] ?? '');
        $batchReq = isset($_POST['batch_required']) ? 1 : (in_array($category, ['Medicines', 'Ophthalmic Medicines', 'Surgical Consumables'], true) ? 1 : 0);
        $reorder = (int)($_POST['reorder_level'] ?? 0);

        $pdo->prepare('INSERT INTO items (
            code, name, category, sub_category, unit, location, quantity,
            reorder_level, min_level, max_level, average_consumption, lead_time_days,
            batch_number, expiry_date, batch_required, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $itemCode, trim($_POST['name']), $category, trim($_POST['sub_category'] ?? ''),
                $_POST['unit'], trim($_POST['location']), $qty,
                $reorder, (int)($_POST['min_level'] ?? 0),
                (int)($_POST['max_level'] ?? 0), (int)($_POST['average_consumption'] ?? 0),
                (int)($_POST['lead_time_days'] ?? 0), $batch, $expiryDate, $batchReq,
                $qty == 0 ? 'Out of stock' : ($qty <= $reorder ? 'Low stock' : 'Available')
            ]);
        $itemId = (int)$pdo->lastInsertId();

        if ($qty > 0) {
            if ($batch !== '') {
                $bStmt = $pdo->prepare('INSERT INTO item_batches (item_id, batch_number, expiry_date, quantity, location, status) VALUES (?, ?, ?, ?, ?, "Active")
                    ON CONFLICT(item_id, batch_number) DO UPDATE SET quantity = quantity + excluded.quantity');
                $bStmt->execute([$itemId, $batch, $expiryDate, $qty, trim($_POST['location'])]);
            }
            $lStmt = $pdo->prepare('INSERT INTO stock_ledger (transaction_type, reference_no, item_id, batch_number, source_type, dest_type, quantity, balance_before, balance_after, user_id, notes)
                VALUES ("ADJUSTMENT_IN", ?, ?, ?, "OPENING_BALANCE", "MAIN_STORE", ?, 0, ?, ?, "Opening stock entered in Item Master")');
            $lStmt->execute(['OPENING-' . $itemCode, $itemId, $batch, $qty, $qty, current_user()['id'] ?? null]);
        }

        flash("Item {$itemCode} added to item master."); header('Location: ' . page_link('inventory')); exit;
    }

    // ── Asset Master Creation (Phase 5) ──────────────────────────────
    if ($action === 'asset') {
        $tag = trim($_POST['tag']);
        $pdo->prepare('INSERT INTO assets (
            tag, name, department, location, custodian, status,
            warranty_until, warranty_start, amc_start, amc_expiry,
            category, make, model, serial_number, purchase_date, cost,
            po_number, challan_number, invoice_number, asset_type
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $tag, trim($_POST['name']), trim($_POST['department']),
                trim($_POST['location']), trim($_POST['custodian']), $_POST['status'] ?? 'In service',
                $_POST['warranty_until'] ?: null, $_POST['warranty_start'] ?: null,
                $_POST['amc_start'] ?: null, $_POST['amc_expiry'] ?: null,
                trim($_POST['category']), trim($_POST['make']), trim($_POST['model']),
                trim($_POST['serial_number']), $_POST['purchase_date'] ?: null,
                (float)($_POST['cost'] ?? 0), trim($_POST['po_number'] ?? ''),
                trim($_POST['challan_number'] ?? ''), trim($_POST['invoice_number'] ?? ''),
                $_POST['asset_type'] ?? 'Fixed Asset'
            ]);
        $assetId = (int)$pdo->lastInsertId();
        log_activity($pdo, 'asset_created', 'assets', $assetId, ['tag' => $tag]);
        flash("Fixed Asset {$tag} added to asset register.");
        header('Location: ' . page_link('assets'));
        exit;
    }

    // ── Inter-Department Asset Transfer (Phase 5) ────────────────────
    if ($action === 'transfer_asset') {
        $assetId = (int)$_POST['asset_id'];
        $stmt = $pdo->prepare('SELECT * FROM assets WHERE id = ?');
        $stmt->execute([$assetId]);
        $ast = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ast) {
            flash('Asset not found.', 'error');
            header('Location: ' . page_link('assets') . '&subview=transfers');
            exit;
        }
        $trfNo = next_number($pdo, 'TRF', 'asset_transfers', 'transfer_number');
        $newDept = trim($_POST['new_department']);
        $newLoc  = trim($_POST['new_location']);
        $newCust = trim($_POST['new_custodian']);
        $trfDate = $_POST['transfer_date'] ?: date('Y-m-d');
        $reason  = trim($_POST['reason'] ?? '');
        $authBy  = trim($_POST['authorized_by'] ?? 'Medical Superintendent');
        $recvBy  = trim($_POST['received_by'] ?? $newCust);

        $pdo->prepare('INSERT INTO asset_transfers (
            transfer_number, asset_id, previous_department, new_department,
            previous_location, new_location, previous_custodian, new_custodian,
            transfer_date, reason, authorized_by, received_by, created_by
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $trfNo, $assetId, $ast['department'], $newDept,
                $ast['location'], $newLoc, $ast['custodian'], $newCust,
                $trfDate, $reason, $authBy, $recvBy, current_user()['id'] ?? null
            ]);

        $pdo->prepare('UPDATE assets SET department = ?, location = ?, custodian = ? WHERE id = ?')
            ->execute([$newDept, $newLoc, $newCust, $assetId]);

        log_activity($pdo, 'asset_transferred', 'assets', $assetId, ['transfer_number' => $trfNo, 'to' => $newDept]);
        flash("Asset {$ast['tag']} transferred to {$newDept} (Transfer #{$trfNo}).");
        header('Location: ' . page_link('assets') . '&subview=transfers');
        exit;
    }

    // ── Asset Maintenance & Breakdown Logging (Phase 5) ──────────────
    if ($action === 'maintenance_asset') {
        $assetId = (int)$_POST['asset_id'];
        $stmt = $pdo->prepare('SELECT * FROM assets WHERE id = ?');
        $stmt->execute([$assetId]);
        $ast = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ast) {
            flash('Asset not found.', 'error');
            header('Location: ' . page_link('assets') . '&subview=maintenance');
            exit;
        }
        $mntNo = next_number($pdo, 'MNT', 'asset_maintenance', 'maintenance_no');
        $mType = $_POST['maintenance_type'] ?? 'Breakdown';
        $prob  = trim($_POST['problem_details'] ?? '');
        $repDate = $_POST['reported_date'] ?: date('Y-m-d');
        $repBy = trim($_POST['reported_by'] ?? (current_user()['name'] ?? 'Staff'));
        $priority = $_POST['priority'] ?? 'Medium';
        $techVendor = trim($_POST['technician_vendor'] ?? '');
        $repairDetails = trim($_POST['repair_details'] ?? '');
        $partsReplaced = trim($_POST['parts_replaced'] ?? '');
        $laborCost = (float)($_POST['labor_cost'] ?? 0);
        $partsCost = (float)($_POST['parts_cost'] ?? 0);
        $totalCost = $laborCost + $partsCost;
        $status = $_POST['status'] ?? 'Under repair';
        $dateReturned = !empty($_POST['date_returned']) ? $_POST['date_returned'] : null;
        $warrantyCovered = !empty($_POST['warranty_covered']) ? 1 : 0;
        $nextDue = !empty($_POST['next_due_date']) ? $_POST['next_due_date'] : null;
        $remarks = trim($_POST['remarks'] ?? '');

        $pdo->prepare('INSERT INTO asset_maintenance (
            maintenance_no, asset_id, maintenance_type, department, problem_details,
            reported_date, reported_by, priority, technician_vendor, repair_details,
            parts_replaced, labor_cost, parts_cost, total_cost, status,
            date_returned, warranty_covered, next_due_date, remarks, created_by
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $mntNo, $assetId, $mType, $ast['department'], $prob,
                $repDate, $repBy, $priority, $techVendor, $repairDetails,
                $partsReplaced, $laborCost, $partsCost, $totalCost, $status,
                $dateReturned, $warrantyCovered, $nextDue, $remarks, current_user()['id'] ?? null
            ]);

        if ($status === 'Under repair') {
            $pdo->prepare('UPDATE assets SET status = "Under repair" WHERE id = ?')->execute([$assetId]);
        } elseif ($status === 'Completed') {
            $pdo->prepare('UPDATE assets SET status = "In service" WHERE id = ?')->execute([$assetId]);
        }

        log_activity($pdo, 'asset_maintenance_logged', 'assets', $assetId, ['maintenance_no' => $mntNo, 'status' => $status]);
        flash("Maintenance record {$mntNo} saved for asset {$ast['tag']}.");
        header('Location: ' . page_link('assets') . '&subview=maintenance');
        exit;
    }

    // ── Asset Condemnation & Dead Stock Recommendation (Phase 5) ─────
    if ($action === 'condemn_asset') {
        $assetId = !empty($_POST['asset_id']) ? (int)$_POST['asset_id'] : null;
        $ast = null;
        if ($assetId) {
            $stmt = $pdo->prepare('SELECT * FROM assets WHERE id = ?');
            $stmt->execute([$assetId]);
            $ast = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        $cndNo = next_number($pdo, 'CND', 'condemnations', 'condemnation_no');
        $itemName = trim($_POST['item_name'] ?: ($ast['name'] ?? 'Hospital Equipment'));
        $tagOrCode = trim($_POST['tag_or_code'] ?: ($ast['tag'] ?? ''));
        $dept = trim($_POST['department'] ?: ($ast['department'] ?? 'Central Store'));
        $sbRef = trim($_POST['survey_board_ref']);
        $sbDate = $_POST['survey_board_date'] ?: date('Y-m-d');
        $reason = trim($_POST['reason']);
        $recom = trim($_POST['recommendation'] ?? 'Dispose via public auction under Sindh Financial Rules');
        $comm = trim($_POST['committee_members'] ?? 'DD Finance, Store Officer, Biomedical Engineer');
        $scrapVal = (float)($_POST['estimated_scrap_val'] ?? 0);
        $dispMethod = $_POST['disposal_method'] ?? 'Auction';
        $status = $_POST['status'] ?? 'Approved by Board';
        $appBy = trim($_POST['approved_by'] ?? 'Prof. Dr. Competent Authority');

        $pdo->prepare('INSERT INTO condemnations (
            condemnation_no, asset_id, item_name, tag_or_code, department,
            survey_board_ref, survey_board_date, reason, recommendation,
            committee_members, estimated_scrap_val, disposal_method, status, approved_by, created_by
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $cndNo, $assetId, $itemName, $tagOrCode, $dept,
                $sbRef, $sbDate, $reason, $recom,
                $comm, $scrapVal, $dispMethod, $status, $appBy, current_user()['id'] ?? null
            ]);
        $cndId = (int)$pdo->lastInsertId();

        if ($assetId) {
            $pdo->prepare('UPDATE assets SET status = "Condemned", condemnation_id = ? WHERE id = ?')->execute([$cndId, $assetId]);
        }

        log_activity($pdo, 'asset_condemned', 'condemnations', $cndId, ['condemnation_no' => $cndNo]);
        flash("Asset {$itemName} ({$tagOrCode}) condemned under Survey Board {$sbRef} (Condemnation #{$cndNo}).");
        header('Location: ' . page_link('assets') . '&subview=condemnations');
        exit;
    }

    // ── Auction / Disposal Finalization (Phase 5) ─────────────────────
    if ($action === 'auction_disposal') {
        $cndId = (int)$_POST['condemnation_id'];
        $stmt = $pdo->prepare('SELECT * FROM condemnations WHERE id = ?');
        $stmt->execute([$cndId]);
        $cnd = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$cnd) {
            flash('Condemnation record not found.', 'error');
            header('Location: ' . page_link('assets') . '&subview=condemnations');
            exit;
        }
        $dispDate = $_POST['disposal_date'] ?: date('Y-m-d');
        $challanNo = trim($_POST['auction_challan_no']);
        $recovered = (float)($_POST['recovered_amount'] ?? 0);
        $buyer = trim($_POST['buyer_vendor_name'] ?? '');

        $pdo->prepare('UPDATE condemnations SET
            disposal_date = ?, auction_challan_no = ?, recovered_amount = ?, buyer_vendor_name = ?, status = "Disposed/Auctioned"
            WHERE id = ?')
            ->execute([$dispDate, $challanNo, $recovered, $buyer, $cndId]);

        if (!empty($cnd['asset_id'])) {
            $pdo->prepare('UPDATE assets SET status = "Disposed" WHERE id = ?')->execute([$cnd['asset_id']]);
        }

        log_activity($pdo, 'condemnation_disposed', 'condemnations', $cndId, ['challan' => $challanNo, 'recovered' => $recovered]);
        flash("Condemnation #{$cnd['condemnation_no']} marked as Auctioned/Disposed with Challan {$challanNo}.");
        header('Location: ' . page_link('assets') . '&subview=condemnations');
        exit;
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
    'reconciliation'  => ['Manual vs digital reconciliation', '14 · GOVERNANCE & AUDIT',   'Reconcile physical Sindh Government registers with digital transaction logs.'],
    'deletions'       => ['Deletion & reversal approvals',    '15 · DD FINANCE CONTROL',    'Exclusive DD Finance approval authority for record deletions and stock reversals.'],
    'users'           => ['Users & roles',              'SYSTEM CONTROL',             'Manage operators, approvers, and administrators.'],
    'quick-entry'     => ['Quick entry',                'DATA ENTRY',                 'Record a simple stock movement without a full workflow.'],
];
[$title, $kicker, $description] = $titles[$view];
$current = $view;
$items_all  = $pdo->query('SELECT id, code, name, unit, quantity, expiry_date, supplier_id FROM items ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$expiry_map = array_column($items_all, 'expiry_date', 'id');
$depts_all  = $pdo->query('SELECT id, name, type FROM departments WHERE status="Active" ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$supp_all   = $pdo->query('SELECT id, code, name FROM suppliers WHERE status="Active" ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
// ── CSV Streaming Export Engine (Phase 5) ─────────────────────────
if ($view === 'reports' && ($_GET['export'] ?? '') === 'csv') {
    require_login();
    $rtype = $_GET['rtype'] ?? 'stock';
    $filename = "SIOVS_Report_{$rtype}_" . date('Ymd_His') . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // Excel UTF-8 BOM

    if ($rtype === 'purchase') {
        fputcsv($out, ['SINDH INSTITUTE OF OPHTHALMOLOGY & VISUAL SCIENCES (SIOVS) - PROCUREMENT & PO FULFILLMENT AUDIT REPORT']);
        fputcsv($out, ['Report Date: ' . date('d-M-Y H:i:s'), 'Generated By: ' . (current_user()['name'] ?? 'Purchase Officer')]);
        fputcsv($out, ['PO Number', 'PO Date', 'Supplier Code', 'Supplier Name', 'Expected Delivery', 'PO Status', 'Item Code', 'Item Name', 'Unit', 'Qty Ordered', 'Qty Received (GRN)', 'Qty Accepted (Store)', 'Qty Rejected', 'Pending Shortage', 'Rate (Rs.)', 'Line Value (Rs.)']);
        $sql = 'SELECT po.po_number, po.po_date, po.expected_delivery, po.status AS po_status,
                       s.code AS supplier_code, s.name AS supplier_name,
                       i.code AS item_code, i.name AS item_name, pi.unit, pi.quantity_ordered, pi.rate, pi.total_value,
                       COALESCE((SELECT SUM(gi.quantity_received) FROM grn_items gi JOIN grns g ON g.id = gi.grn_id WHERE g.po_id = po.id AND gi.item_id = pi.item_id), 0) AS total_received,
                       COALESCE((SELECT SUM(gi.quantity_accepted) FROM grn_items gi JOIN grns g ON g.id = gi.grn_id WHERE g.po_id = po.id AND gi.item_id = pi.item_id), 0) AS total_accepted,
                       COALESCE((SELECT SUM(gi.quantity_rejected) FROM grn_items gi JOIN grns g ON g.id = gi.grn_id WHERE g.po_id = po.id AND gi.item_id = pi.item_id), 0) AS total_rejected
                FROM purchase_orders po
                JOIN suppliers s ON s.id = po.supplier_id
                JOIN po_items pi ON pi.po_id = po.id
                JOIN items i ON i.id = pi.item_id
                ORDER BY po.id DESC, pi.id ASC';
        $stmt = $pdo->query($sql);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $shortage = max(0, (int)$r['quantity_ordered'] - (int)$r['total_accepted']);
            fputcsv($out, [$r['po_number'], $r['po_date'], $r['supplier_code'], $r['supplier_name'], $r['expected_delivery'] ?: 'Not set', $r['po_status'], $r['item_code'], $r['item_name'], $r['unit'], $r['quantity_ordered'], $r['total_received'], $r['total_accepted'], $r['total_rejected'], $shortage, $r['rate'], $r['total_value']]);
        }
    } elseif ($rtype === 'stock') {
        fputcsv($out, ['SINDH INSTITUTE OF OPHTHALMOLOGY & VISUAL SCIENCES (SIOVS) - STOCK LEDGER AUDIT REPORT']);
        fputcsv($out, ['Report Date: ' . date('d-M-Y H:i:s'), 'Generated By: ' . (current_user()['name'] ?? 'System')]);
        fputcsv($out, ['ID', 'Date/Time', 'Reference No', 'Item Code', 'Item Name', 'Tx Type', 'Source', 'Destination', 'Qty Moved', 'Balance Before', 'Balance After', 'Officer', 'Notes']);
        $sql = 'SELECT sl.*, i.code AS icode, i.name AS iname, u.name AS uname FROM stock_ledger sl LEFT JOIN items i ON i.id = sl.item_id LEFT JOIN users u ON u.id = sl.user_id ORDER BY sl.id DESC';
        $stmt = $pdo->query($sql);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, [$r['id'], $r['created_at'], $r['reference_no'], $r['icode'], $r['iname'], $r['transaction_type'], $r['source_type'], $r['dest_type'], $r['quantity'], $r['balance_before'], $r['balance_after'], $r['uname'] ?? 'System', $r['notes']]);
        }
    } elseif ($rtype === 'expiry') {
        fputcsv($out, ['SIOVS EXPIRY BUCKETS & RISK ANALYSIS REPORT']);
        fputcsv($out, ['Report Date: ' . date('d-M-Y H:i:s')]);
        fputcsv($out, ['Item Code', 'Item Name', 'Category', 'Batch Number', 'Expiry Date', 'Days Left', 'Quantity', 'Unit', 'Unit Cost', 'Financial Risk (Rs.)', 'Location', 'Risk Status']);
        $stmt = $pdo->query('SELECT ib.*, i.code AS icode, i.name AS iname, i.category, i.unit, CAST(julianday(ib.expiry_date) - julianday("now") AS INTEGER) AS days_left FROM item_batches ib JOIN items i ON i.id = ib.item_id WHERE ib.quantity > 0 ORDER BY ib.expiry_date ASC');
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $days = (int)$r['days_left'];
            $status = $days <= 0 ? 'Expired' : ($days <= 30 ? 'Critical (<30d)' : ($days <= 60 ? 'Warning (30-60d)' : ($days <= 90 ? 'Attention (60-90d)' : 'Safe (>90d)')));
            $risk = (float)$r['quantity'] * (float)$r['unit_cost'];
            fputcsv($out, [$r['icode'], $r['iname'], $r['category'], $r['batch_number'], $r['expiry_date'], $days, $r['quantity'], $r['unit'], $r['unit_cost'], $risk, $r['location'] ?? 'Main Store', $status]);
        }
    } elseif ($rtype === 'consumption') {
        fputcsv($out, ['SIOVS PATIENT CONSUMPTION AUDIT REPORT']);
        fputcsv($out, ['Report Date: ' . date('d-M-Y H:i:s')]);
        fputcsv($out, ['ID', 'Date/Time', 'Department', 'Type', 'Patient MR', 'Patient Name', 'Procedure / Ward-Bed', 'Surgeon / Incharge', 'Item Code', 'Item Name', 'Batch', 'Quantity', 'Unit', 'Status', 'Manual Register', 'Page', 'Entry']);
        $stmt = $pdo->query('SELECT cr.*, d.name AS dname, i.code AS icode, i.name AS iname, i.unit FROM consumption_records cr LEFT JOIN departments d ON d.id = cr.department_id LEFT JOIN items i ON i.id = cr.item_id ORDER BY cr.id DESC');
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $spec = $r['procedure_name'] ?: ($r['ward'] ? "Ward {$r['ward']} Bed {$r['bed']}" : '—');
            fputcsv($out, [$r['id'], $r['created_at'], $r['dname'] ?? 'General', $r['type'], $r['patient_mr'], $r['patient_name'], $spec, $r['surgeon'] ?? '—', $r['icode'], $r['iname'], $r['batch_number'], $r['quantity'], $r['unit'], $r['status'], $r['manual_register_name'], $r['manual_register_page'], $r['manual_register_entry']]);
        }
    } elseif ($rtype === 'assets') {
        fputcsv($out, ['SIOVS FIXED ASSET & MAINTENANCE LIFECYCLE REPORT']);
        fputcsv($out, ['Report Date: ' . date('d-M-Y H:i:s')]);
        fputcsv($out, ['Asset Tag', 'Name', 'Category', 'Make', 'Model', 'Serial Number', 'Department', 'Location', 'Custodian', 'Purchase Date', 'Cost (Rs.)', 'Status', 'Warranty Until', 'AMC Expiry', 'Total Mnt Cost (Rs.)']);
        $stmt = $pdo->query('SELECT a.*, (SELECT COALESCE(SUM(total_cost),0) FROM asset_maintenance WHERE asset_id = a.id) AS mnt_cost FROM assets a ORDER BY a.department, a.name');
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, [$r['tag'], $r['name'], $r['category'], $r['make'], $r['model'], $r['serial_number'], $r['department'], $r['location'], $r['custodian'], $r['purchase_date'], $r['cost'], $r['status'], $r['warranty_until'], $r['amc_expiry'], $r['mnt_cost']]);
        }
    } elseif ($rtype === 'condemnations') {
        fputcsv($out, ['SIOVS DEAD STOCK & CONDEMNED EQUIPMENT REGISTER']);
        fputcsv($out, ['Report Date: ' . date('d-M-Y H:i:s')]);
        fputcsv($out, ['Condemnation No', 'Tag/Code', 'Item Name', 'Department', 'Survey Board Ref', 'Board Date', 'Reason', 'Recommendation', 'Committee Members', 'Est. Scrap Value', 'Disposal Method', 'Disposal Date', 'Auction Challan', 'Recovered Amount', 'Status', 'Approved By']);
        $stmt = $pdo->query('SELECT * FROM condemnations ORDER BY id DESC');
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, [$r['condemnation_no'], $r['tag_or_code'], $r['item_name'], $r['department'], $r['survey_board_ref'], $r['survey_board_date'], $r['reason'], $r['recommendation'], $r['committee_members'], $r['estimated_scrap_val'], $r['disposal_method'], $r['disposal_date'], $r['auction_challan_no'], $r['recovered_amount'], $r['status'], $r['approved_by']]);
        }
    } elseif ($rtype === 'reconciliation') {
        fputcsv($out, ['SIOVS SINDH GOVT. MANUAL VS DIGITAL REGISTER RECONCILIATION CERTIFICATE']);
        fputcsv($out, ['Report Date: ' . date('d-M-Y H:i:s')]);
        fputcsv($out, ['Rec No', 'Register Name', 'Page No', 'Entry No', 'Transaction Type', 'Transaction Ref', 'Manual Qty', 'System Qty', 'Difference', 'Status', 'Reason', 'Corrective Action', 'Verified Date']);
        $stmt = $pdo->query('SELECT * FROM manual_reconciliations ORDER BY id DESC');
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, [$r['rec_number'], $r['register_name'], $r['page_no'], $r['entry_no'], $r['transaction_type'], $r['transaction_ref'], $r['register_qty'], $r['system_qty'], $r['difference'], $r['status'], $r['reason'], $r['corrective_action'], $r['verified_date']]);
        }
    } elseif ($rtype === 'valuation') {
        fputcsv($out, ['SINDH INSTITUTE OF OPHTHALMOLOGY & VISUAL SCIENCES (SIOVS) - INVENTORY & ASSET VALUATION REPORT']);
        fputcsv($out, ['Report Date: ' . date('d-M-Y H:i:s'), 'Authorized By: ' . (current_user()['name'] ?? 'DD Finance')]);
        fputcsv($out, ['Item Code', 'Item Name', 'Category', 'Current Stock', 'Unit', 'Unit Rate / Cost (Rs.)', 'Total Valuation (Rs.)', 'Stock Status']);
        $sql = "SELECT i.code, i.name, i.category, i.quantity, i.unit, i.reorder_level,
                       COALESCE((SELECT g.rate FROM grn_items g WHERE g.item_id = i.id AND g.rate > 0 ORDER BY g.id DESC LIMIT 1), 0) AS unit_rate
                FROM items i
                ORDER BY (i.quantity * COALESCE((SELECT g.rate FROM grn_items g WHERE g.item_id = i.id AND g.rate > 0 ORDER BY g.id DESC LIMIT 1), 0)) DESC";
        $stmt = $pdo->query($sql);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $val = (float)$r['quantity'] * (float)$r['unit_rate'];
            $st = ((int)$r['quantity'] === 0) ? 'Out of Stock' : (((int)$r['quantity'] <= (int)$r['reorder_level']) ? 'Low Stock' : 'Adequate');
            fputcsv($out, [$r['code'], $r['name'], $r['category'], $r['quantity'], $r['unit'], $r['unit_rate'], number_format($val, 2, '.', ''), $st]);
        }
    } else {
        fputcsv($out, ['SIOVS REPORT', $rtype]);
        fputcsv($out, ['Reference', 'Party / Name', 'Quantity', 'Status', 'Date']);
        $stmt = $pdo->query('SELECT code AS reference, name AS party, quantity, status, created_at FROM items ORDER BY name');
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, [$r['reference'], $r['party'], $r['quantity'], $r['status'], $r['created_at']]);
        }
    }
    fclose($out);
    exit;
}

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
        'adjustments'     => ((current_user()['role'] ?? '') === 'dd_finance')
            ? 'Financial Authorization Mode: Review pending adjustments requested by Store Officers. Approve to post double-entry ledger entries to stock, or Reject to disallow without altering inventory balances.'
            : 'Submit an adjustment with reason and quantity. DD Finance or admin must approve before stock is updated.',
        'verification'    => ((current_user()['role'] ?? '') === 'dd_finance')
            ? 'Audit & Discrepancy Review: Review physical verification counts conducted by the multi-member Board of Survey (Store Officer + Internal Auditor). Inspect variances and monitor auto-generated stock adjustments.'
            : 'Enter physical counts against system balances. Discrepancies require explanation and authorized adjustment.',
        'requisitions'    => 'Department creates requisition → Store Officer approves → Store Officer issues. Each step is tracked.',
        'opd_consumption' => 'Enter patient MR number, select items consumed during visit. Stock decreases immediately on save.',
        'ipd_consumption' => 'Record ward, bed, and admission date. Post all items consumed by the patient during admission.',
        'ot_consumption'  => 'Enter surgeon, procedure, and patient details. Record all IOLs, sutures, medicines, and consumables used.',
        'emergency'       => 'Rapid entry for emergency cases. Post now, add authorization reference later if needed.',
        'assets'          => 'Assign a unique asset tag, record department, custodian, warranty, and AMC. All transfers must be logged.',
        'departments'     => 'Maintain the list of departments that receive stock issues. Used in requisitions and consumption records.',
        'returns'         => 'Record unused material returned from departments. Returned stock is verified before re-entering available inventory.',
        'reconciliation'  => 'Compare physical register counts (GRN, Indent, OPD, OT, Return) with system transactions to audit and verify zero-variance synchronization.',
        'deletions'       => 'Exclusively authorized for DD Finance and Competent Authority. Approving a deletion automatically performs double-entry stock rollback in the ledger.',
        'users'           => 'Add users with their role. Role determines which modules they can access and what they can do.',
        'quick-entry'     => 'Use only for simple movements. For full workflows, use the dedicated module so all fields are captured.',
        default           => 'Select a report type, apply date/department filters, review exceptions, and print or export the result.',
    }) ?></p></div>
</div>

<style>
/* ── Guaranteed Module KPI Grid & Card Styles (immune to external cache/overwrites) ── */
.kpi-grid {
    display: grid !important;
    grid-template-columns: repeat(4, 1fr) !important;
    gap: 14px !important;
    margin: 1rem 1.25rem 1.5rem !important;
}
@media (max-width: 1080px) {
    .kpi-grid.four, .kpi-grid {
        grid-template-columns: repeat(2, 1fr) !important;
    }
}
@media (max-width: 600px) {
    .kpi-grid.four, .kpi-grid {
        grid-template-columns: 1fr !important;
    }
}
.kpi-grid .kpi-card {
    min-height: auto !important;
    background: #ffffff !important;
    border: 1px solid var(--line, #dcebe5) !important;
    border-radius: 10px !important;
    padding: 14px 18px !important;
    box-shadow: 0 2px 8px rgba(22, 91, 70, 0.06) !important;
    display: flex !important;
    flex-direction: column !important;
    justify-content: center !important;
}
.kpi-grid .kpi-card .kpi-label {
    font-size: 11px !important;
    font-weight: 700 !important;
    color: #334155 !important; /* High-contrast dark slate grey */
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    margin: 0 0 6px 0 !important;
    max-width: 100% !important;
    line-height: 1.3 !important;
    opacity: 1 !important;
}
.kpi-grid .kpi-card .kpi-val {
    font-family: 'Space Grotesk', -apple-system, BlinkMacSystemFont, sans-serif !important;
    font-size: 24px !important;
    font-weight: 700 !important;
    color: #0f172a !important;
    margin: 0 0 4px 0 !important;
    line-height: 1.15 !important;
}
.kpi-grid .kpi-card .kpi-sub {
    font-size: 11px !important;
    color: #64748b !important;
    margin: 0 !important;
    line-height: 1.3 !important;
}
</style>

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
<?php
$editPoId = !empty($_GET['edit_po']) ? (int)$_GET['edit_po'] : null;
$editPO = null;
$editPoItems = [];
if ($editPoId) {
    $stmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE id=? AND status="Draft"');
    $stmt->execute([$editPoId]);
    $editPO = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($editPO) {
        $stmtItems = $pdo->prepare('SELECT pi.*, i.code, i.name, i.unit as item_unit FROM po_items pi JOIN items i ON i.id=pi.item_id WHERE pi.po_id=? ORDER BY pi.id ASC');
        $stmtItems->execute([$editPoId]);
        $editPoItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<div class="system-grid">
    <div class="panel">
        <div class="panel-heading"><h2>Purchase order list</h2><a class="button button-primary" href="#add-po">＋ New PO</a></div>
        <div class="table-wrap"><table>
            <thead><tr><th>PO Number</th><th>Date</th><th>Supplier</th><th>Manual Reg.</th><th>Expected delivery</th><th>Total Value (PKR)</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($pdo->query('SELECT po.*, s.name as supplier_name FROM purchase_orders po LEFT JOIN suppliers s ON s.id=po.supplier_id ORDER BY po.id DESC') as $po): ?>
            <tr>
                <td><b><?= e($po['po_number']) ?></b><br><span class="muted"><?= e($po['purchase_reference'] ?: 'Direct Purchase') ?></span></td>
                <td><?= e($po['po_date']) ?></td>
                <td><?= e($po['supplier_name']) ?></td>
                <td>
                    <?php if (!empty($po['manual_register_name'])): ?>
                        <small><b><?= e($po['manual_register_name']) ?></b><br>Pg: <?= e($po['manual_register_page'] ?: '-') ?> · Ent: <?= e($po['manual_register_entry'] ?: '-') ?></small>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php
                    $isOverdue = (!empty($po['expected_delivery']) && $po['expected_delivery'] < date('Y-m-d') && in_array($po['status'], ['Approved', 'Partially Received'], true));
                    ?>
                    <?php if ($isOverdue): ?>
                        <span class="badge" style="background:#fee2e2;color:#991b1b;border:1px solid #f87171;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:700">⚠️ OVERDUE</span><br>
                        <b style="color:#b91c1c;font-size:12px"><?= e(date('d M Y', strtotime($po['expected_delivery']))) ?></b>
                    <?php elseif (!empty($po['expected_delivery'])): ?>
                        <?= e(date('d M Y', strtotime($po['expected_delivery']))) ?>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td><b>Rs. <?= number_format((float)$po['total_value'], 2) ?></b></td>
                <td><?= pill($po['status']) ?></td>
                <td>
                    <?php if ($po['status'] === 'Draft' && (can('purchase_orders') || can('admin'))): ?>
                    <div style="display:inline-flex;gap:4px;align-items:center">
                        <a href="page.php?view=purchase_orders&edit_po=<?= $po['id'] ?>#add-po" class="button button-light" style="padding:.2rem .5rem;font-size:.8rem;text-decoration:none" title="Edit Draft PO">
                            ✏️ Edit
                        </a>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="action" value="approve_po">
                            <input type="hidden" name="po_id" value="<?= $po['id'] ?>">
                            <button class="button button-primary" type="submit" style="padding:.2rem .6rem;font-size:.8rem" title="Approve & Authorize PO">Approve</button>
                        </form>
                        <form method="post" style="display:inline" onsubmit="return confirm('Are you sure you want to cancel this draft purchase order?')">
                            <input type="hidden" name="action" value="cancel_po">
                            <input type="hidden" name="po_id" value="<?= $po['id'] ?>">
                            <button class="button button-light" type="submit" style="padding:.2rem .5rem;font-size:.8rem;color:#b91c1c" title="Cancel Draft">✕</button>
                        </form>
                    </div>
                    <?php elseif (in_array($po['status'], ['Approved', 'Partially Received'], true) && can('grn')): ?>
                    <a href="<?= page_link('grn') ?>&po_id=<?= $po['id'] ?>#add-grn" class="button button-primary" style="padding:.2rem .6rem;font-size:.8rem;text-decoration:none;display:inline-flex;align-items:center;gap:3px">
                        <span>📦</span> Generate GRN
                    </a>
                    <?php elseif (in_array($po['status'], ['Approved', 'Partially Received'], true)): ?>
                    <span class="badge" style="background:#f0fdf4;color:#166534;font-size:11px;padding:3px 8px;border-radius:4px;border:1px solid #bbf7d0">
                        🚚 Awaiting Store GRN
                    </span>
                    <?php elseif ($po['status'] === 'Delivered'): ?>
                    <span class="badge" style="background:#ecfdf5;color:#047857;font-size:11px;padding:3px 8px;border-radius:4px;border:1px solid #a7f3d0">
                        ✓ Store Received
                    </span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <div class="form-card" id="add-po">
        <h2><?= $editPO ? 'Edit purchase order (' . e($editPO['po_number']) . ')' : 'Create purchase order' ?></h2>
        <p class="muted" style="margin-bottom:1rem">All procurement pricing and rates in Pakistani Rupees (PKR / Rs.)</p>
        <?php if ($editPO): ?>
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:8px 12px;margin-bottom:1rem;font-size:12px;color:#1e40af">
            Editing draft order <b><?= e($editPO['po_number']) ?></b>. Line items, quantities, and rates can be freely modified before approval.
        </div>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="purchase_order">
        <?php if ($editPO): ?>
            <input type="hidden" name="po_id" value="<?= $editPO['id'] ?>">
        <?php endif; ?>
        <div class="form-grid one">
            <label>Supplier<select name="supplier_id" required>
                <?php foreach ($supp_all as $s): ?>
                <option value="<?= $s['id'] ?>" <?= ($editPO && $editPO['supplier_id'] == $s['id']) ? 'selected' : '' ?>><?= e($s['code']) ?> — <?= e($s['name']) ?></option>
                <?php endforeach; ?>
            </select></label>
            <label>PO date<input name="po_date" type="date" value="<?= $editPO ? e($editPO['po_date']) : date('Y-m-d') ?>" required></label>
            <label>Purchase reference<input name="purchase_reference" value="<?= $editPO ? e($editPO['purchase_reference']) : e('PR-' . date('Y') . '-' . sprintf('%03d', rand(100, 999))) ?>"></label>
            <label>Tender / Quotation reference<input name="tender_reference" value="<?= $editPO ? e($editPO['tender_reference']) : e('TQ-' . date('Y') . '-' . sprintf('%03d', rand(100, 999))) ?>"></label>
            <label>Expected delivery date<input name="expected_delivery" type="date" value="<?= $editPO ? e($editPO['expected_delivery']) : date('Y-m-d', strtotime('+14 days')) ?>"></label>
            <label>Manual register reference
                <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:.4rem;margin-top:.25rem">
                    <input name="manual_register_name" value="<?= $editPO ? e($editPO['manual_register_name']) : 'Store Purchase Register' ?>" placeholder="Register Name">
                    <input name="manual_register_page" value="<?= $editPO ? e($editPO['manual_register_page']) : '' ?>" placeholder="Page No.">
                    <input name="manual_register_entry" value="<?= $editPO ? e($editPO['manual_register_entry']) : '' ?>" placeholder="Entry No.">
                </div>
            </label>
            <label>Upload PO / Tender document (PDF/Image)
                <input type="file" name="po_document" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
            </label>
            <label>Procurement notes<textarea name="notes" rows="2"><?= $editPO ? e($editPO['notes']) : 'Official procurement for SIOVS hospital inventory' ?></textarea></label>
        </div>
        <p class="eyebrow" style="margin:1rem 0 .25rem">ORDERED ITEMS (RATES IN PAKISTANI RUPEES · PKR)</p>
        <p class="muted" style="font-size:12px;margin:0 0 .75rem">💡 <b>Quick Entry:</b> Scan a barcode or type an <b>Item code</b> to auto-select, or pick directly from the <b>Item dropdown</b> (both stay synchronized).</p>
        <div id="po-items">
            <?php
            $displayItems = !empty($editPoItems) ? $editPoItems : array_fill(0, 4, []);
            foreach ($displayItems as $row):
                $rowId = $row['item_id'] ?? '';
                $rowCode = $row['code'] ?? '';
                $rowQty = $row['quantity_ordered'] ?? '';
                $rowUnit = $row['unit'] ?? ($row['item_unit'] ?? '');
                $rowRate = $row['rate'] ?? '';
                $rowTax = $row['taxes'] ?? '';
                $rowWarranty = !empty($row['warranty_applicable']);
            ?>
            <div class="line-item-row">
                <input class="item-barcode" name="item_code[]" value="<?= e($rowCode) ?>" placeholder="Scan / Code" autocomplete="off" style="width:130px" title="Scan barcode or type item code for quick auto-lookup">
                <select name="item_id[]">
                    <option value="">— Select item —</option>
                    <?php foreach ($items_all as $it): ?>
                    <option value="<?= $it['id'] ?>" <?= ($rowId == $it['id']) ? 'selected' : '' ?> data-code="<?= e($it['code']) ?>" data-name="<?= e($it['name']) ?>" data-unit="<?= e($it['unit']) ?>" data-stock="<?= e($it['quantity']) ?>"><?= e($it['code']) ?> — <?= e($it['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input name="qty[]"  type="number" min="1" value="<?= e($rowQty) ?>" placeholder="Qty" style="width:70px">
                <input name="unit[]" value="<?= e($rowUnit) ?>" placeholder="Unit" style="width:60px">
                <input name="rate[]" type="number" step="0.01" value="<?= e($rowRate) ?>" placeholder="Rate (Rs.)" title="Unit Rate in PKR" style="width:95px">
                <input name="tax[]"  type="number" step="0.01" value="<?= e($rowTax) ?>" placeholder="Tax (Rs.)" title="Taxes in PKR" style="width:75px">
                <label style="display:inline-flex;align-items:center;gap:3px;font-size:11px;margin:0"><input type="checkbox" name="warranty[]" value="1" <?= $rowWarranty ? 'checked' : '' ?>> Warranty</label>
            </div>
            <?php endforeach; ?>
        </div>
        <div style="display:flex;gap:8px;align-items:center;margin-top:1rem">
            <button class="button button-primary" type="submit"><?= $editPO ? 'Update purchase order' : 'Create purchase order (Draft)' ?></button>
            <?php if ($editPO): ?>
                <a href="<?= page_link('purchase_orders') ?>" class="button button-light" style="text-decoration:none">Cancel Edit</a>
            <?php endif; ?>
        </div>
        </form>
    </div>
</div>

<?php /* ════════════════════ GRN ═══════════════════════════════════════════ */ ?>
<?php elseif ($view === 'grn'): ?>
<?php
$selected_po_id = !empty($_GET['po_id']) ? (int)$_GET['po_id'] : null;

// Query all pending POs (Approved or Partially Received) awaiting physical delivery
$pending_pos = $pdo->query('
    SELECT po.id, po.po_number, po.po_date, po.expected_delivery, po.total_value, po.status, po.supplier_id,
           s.name AS supplier_name, s.code AS supplier_code,
           COUNT(pi.id) AS item_count,
           COALESCE(SUM(pi.quantity_ordered), 0) AS total_items_ordered
    FROM purchase_orders po
    LEFT JOIN suppliers s ON s.id = po.supplier_id
    LEFT JOIN po_items pi ON pi.po_id = po.id
    WHERE po.status IN ("Approved", "Partially Received")
    GROUP BY po.id
    ORDER BY po.id DESC
')->fetchAll(PDO::FETCH_ASSOC);

// Map of all items for pending POs
$po_items_map = [];
$po_items_stmt = $pdo->query('
    SELECT pi.po_id, pi.item_id, pi.quantity_ordered, pi.unit, pi.rate,
           i.code, i.name, i.unit AS item_unit, i.quantity AS store_stock
    FROM po_items pi
    JOIN items i ON i.id = pi.item_id
    ORDER BY pi.id ASC
');
while ($row = $po_items_stmt->fetch(PDO::FETCH_ASSOC)) {
    $po_items_map[$row['po_id']][] = $row;
}

$preloaded_po = null;
$preloaded_items = [];
if ($selected_po_id) {
    foreach ($pending_pos as $p) {
        if ($p['id'] == $selected_po_id) {
            $preloaded_po = $p;
            break;
        }
    }
    $preloaded_items = $po_items_map[$selected_po_id] ?? [];
}

$inspectGrnId = !empty($_GET['inspect_grn']) ? (int)$_GET['inspect_grn'] : null;
$inspectGRN = null;
$inspectItems = [];
if ($inspectGrnId) {
    $stmtG = $pdo->prepare('SELECT g.*, s.name as sup_name, po.po_number FROM grns g LEFT JOIN suppliers s ON s.id=g.supplier_id LEFT JOIN purchase_orders po ON po.id=g.po_id WHERE g.id=? AND g.inspection_status="Pending"');
    $stmtG->execute([$inspectGrnId]);
    $inspectGRN = $stmtG->fetch(PDO::FETCH_ASSOC);
    if ($inspectGRN) {
        $stmtGI = $pdo->prepare('SELECT gi.*, i.code, i.name, i.unit as item_unit FROM grn_items gi JOIN items i ON i.id=gi.item_id WHERE gi.grn_id=? ORDER BY gi.id ASC');
        $stmtGI->execute([$inspectGrnId]);
        $inspectItems = $stmtGI->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<script>
window.SIOVS_PO_ITEMS = <?= json_encode($po_items_map, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
</script>

<!-- ═══════════ SECTION 1: APPROVED PO DELIVERY INWARD CARDS ═══════════ -->
<div class="panel" style="margin-bottom:1.25rem">
    <div class="panel-heading" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
        <div>
            <h2>🚚 Approved Purchase Orders Awaiting Delivery</h2>
            <span class="muted" style="font-size:12px">Click any PO card to instantly load its items into the GRN Receiving Form below</span>
        </div>
        <button type="button" id="btn-direct-receipt" class="button button-light" style="font-size:12px;padding:6px 14px">
            ＋ Direct Receipt (Without PO)
        </button>
    </div>
    <div style="padding:1rem">
        <?php if (empty($pending_pos)): ?>
            <div style="padding:1.5rem;text-align:center;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:8px;color:#64748b">
                <span style="font-size:26px;display:block;margin-bottom:4px">📦</span>
                <strong>No pending approved purchase orders awaiting delivery.</strong>
                <p style="margin:4px 0 0;font-size:12px">All approved orders have been received, or create a <a href="page.php?view=purchase_orders#add-po">New Purchase Order</a>.</p>
            </div>
        <?php else: ?>
            <div class="po-cards-grid" style="display:grid;grid-template-columns:repeat(auto-fill, minmax(280px, 1fr));gap:12px">
                <?php foreach ($pending_pos as $p): ?>
                <?php $isSelected = ($selected_po_id == $p['id']); ?>
                <div class="po-inward-card <?= $isSelected ? 'is-selected' : '' ?>"
                     data-po-id="<?= $p['id'] ?>"
                     data-supplier-id="<?= $p['supplier_id'] ?>"
                     data-po-number="<?= e($p['po_number']) ?>"
                     data-supplier-name="<?= e($p['supplier_name']) ?>"
                     style="background:<?= $isSelected ? '#eff6ff' : '#fff' ?>;border:2px solid <?= $isSelected ? '#2563eb' : '#e2e8f0' ?>;border-radius:10px;padding:12px 14px;cursor:pointer;transition:all .15s ease;display:flex;flex-direction:column;justify-content:space-between;box-shadow:<?= $isSelected ? '0 4px 12px rgba(37,99,235,0.15)' : 'none' ?>">
                    <div>
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:6px">
                            <span style="font-size:14px;font-weight:700;color:var(--brand-primary,#00897b)"><?= e($p['po_number']) ?></span>
                            <?= pill($p['status']) ?>
                        </div>
                        <div style="font-size:13px;font-weight:600;color:#1e293b;margin-bottom:4px"><?= e($p['supplier_name']) ?></div>
                        <div style="font-size:11px;color:#64748b;margin-bottom:8px;line-height:1.4">
                            <span>📅 Ordered: <?= e(date('d M Y', strtotime($p['po_date']))) ?></span>
                            <?php if ($p['expected_delivery']): ?>
                                <br><span>🚚 Expected: <?= e(date('d M Y', strtotime($p['expected_delivery']))) ?></span>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:11px;background:#f8fafc;padding:6px 8px;border-radius:6px;border:1px solid #edf2f7;margin-bottom:10px">
                            <strong><?= (int)$p['item_count'] ?> item(s)</strong> (<?= (int)$p['total_items_ordered'] ?> total units)<br>
                            Value: <b>Rs. <?= number_format((float)$p['total_value'], 2) ?></b>
                        </div>
                    </div>
                    <button type="button" class="button <?= $isSelected ? 'button-primary' : 'button-light' ?> btn-select-po" style="width:100%;font-size:12px;padding:6px 10px;justify-content:center">
                        <?= $isSelected ? '✓ Selected for GRN' : '📦 Receive This PO' ?>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="system-grid">
    <div class="panel">
        <div class="panel-heading"><h2>GRN register</h2><a class="button button-primary" href="#add-grn">＋ New GRN</a></div>
        <div class="table-wrap"><table>
            <thead><tr><th>GRN Number</th><th>PO Ref.</th><th>Supplier</th><th>Challan No. &amp; Date</th><th>Date Received</th><th>Manual Reg.</th><th>Inspection</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($pdo->query('SELECT g.*, po.po_number, s.name AS sup FROM grns g LEFT JOIN purchase_orders po ON po.id=g.po_id LEFT JOIN suppliers s ON s.id=g.supplier_id ORDER BY g.id DESC') as $g): ?>
            <tr>
                <td><b><?= e($g['grn_number']) ?></b></td>
                <td><?= e($g['po_number'] ?: 'Direct receipt') ?></td>
                <td><?= e($g['sup']) ?></td>
                <td><b><?= e($g['challan_number']) ?></b><br><span class="muted"><?= e($g['challan_date']) ?></span></td>
                <td><?= e($g['received_date']) ?></td>
                <td>
                    <?php if (!empty($g['manual_register_name'])): ?>
                        <small><b><?= e($g['manual_register_name']) ?></b><br>Pg: <?= e($g['manual_register_page'] ?: '-') ?> · Ent: <?= e($g['manual_register_entry'] ?: '-') ?></small>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td><?= pill($g['inspection_status']) ?></td>
                <td>
                    <?php if ($g['inspection_status'] === 'Pending' && (can('grn') || can('admin'))): ?>
                    <a href="<?= page_link('grn') ?>&inspect_grn=<?= $g['id'] ?>#inspect-grn" class="button button-primary" style="padding:.2rem .6rem;font-size:.8rem;text-decoration:none;display:inline-flex;align-items:center;gap:3px">
                        🔍 Inspect &amp; Post
                    </a>
                    <?php elseif ($g['inspection_status'] === 'Inspected'): ?>
                    <span class="badge" style="background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;font-size:11px;padding:3px 6px;border-radius:4px">
                        ✓ Stock Posted
                    </span>
                    <?php elseif ($g['inspection_status'] === 'Partially accepted'): ?>
                    <span class="badge" style="background:#fef3c7;color:#92400e;border:1px solid #fcd34d;font-size:11px;padding:3px 6px;border-radius:4px">
                        ⚠️ Partial Stock
                    </span>
                    <?php elseif ($g['inspection_status'] === 'Rejected'): ?>
                    <span class="badge" style="background:#fee2e2;color:#991b1b;border:1px solid #f87171;font-size:11px;padding:3px 6px;border-radius:4px">
                        ✕ Rejected
                    </span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <div class="form-card" id="add-grn">
        <h2>Goods Received Note (GRN)</h2>
        <form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="grn">
        <div class="form-grid one">
            <label>Linked Purchase Order (PO)
                <select name="po_id" id="grn-po-select">
                    <option value="">— Direct receipt (Without PO) —</option>
                    <?php foreach ($pdo->query('SELECT po.id, po.po_number, s.name AS sn, po.status FROM purchase_orders po LEFT JOIN suppliers s ON s.id=po.supplier_id WHERE po.status != "Delivered" ORDER BY po.id DESC') as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= ($selected_po_id == $p['id']) ? 'selected' : '' ?>>
                        <?= e($p['po_number']) ?> (<?= e($p['status']) ?>) — <?= e($p['sn']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Supplier
                <select name="supplier_id" id="grn-supplier-select">
                    <?php foreach ($supp_all as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= ($preloaded_po && $preloaded_po['supplier_id'] == $s['id']) ? 'selected' : '' ?>>
                        <?= e($s['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Delivery Challan number<input name="challan_number" id="grn-challan-number" value="<?= e('DC-' . date('Y') . '-' . sprintf('%04d', rand(1000, 9999))) ?>" required></label>
            <label>Delivery Challan date<input name="challan_date" type="date" value="<?= date('Y-m-d') ?>" required></label>
            <label>Date received in Store<input name="received_date" type="date" value="<?= date('Y-m-d') ?>" required></label>
            <label>Inspection / Verification status<select name="inspection_status"><option>Inspected</option><option>Pending</option><option>Partially accepted</option><option>Rejected</option></select></label>
            <label>Manual register reference
                <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:.4rem;margin-top:.25rem">
                    <input name="manual_register_name" value="Store Inward Register" placeholder="Register Name">
                    <input name="manual_register_page" placeholder="Page No.">
                    <input name="manual_register_entry" placeholder="Entry No.">
                </div>
            </label>
            <label>Upload Delivery Challan / Inspection note
                <input type="file" name="grn_document" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
            </label>
            <label>Inspection remarks<textarea name="remarks" rows="2">Material inspected, verified against challan, and accepted.</textarea></label>
        </div>
        <p class="eyebrow" style="margin:1.25rem 0 .4rem">RECEIVED ITEMS (Only accepted quantities post to Store stock)</p>
        <div id="grn-po-banner" style="<?= $preloaded_po ? 'display:flex;' : 'display:none;' ?>margin-bottom:.75rem;padding:.65rem .85rem;background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;border-radius:6px;font-size:12px;align-items:center;gap:8px">
            <span style="font-size:16px">✓</span>
            <span id="grn-po-banner-text">
                <?php if ($preloaded_po): ?>
                    <strong>Receiving delivery for <?= e($preloaded_po['po_number']) ?> (<?= e($preloaded_po['supplier_name']) ?>)</strong>. <?= count($preloaded_items) ?> item(s) auto-loaded. Please inspect physical boxes and verify batch numbers &amp; expiry dates.
                <?php else: ?>
                    Items loaded from Purchase Order. Verify physical delivery against Delivery Challan below.
                <?php endif; ?>
            </span>
        </div>
        <div id="grn-items">
            <?php if (!empty($preloaded_items)): ?>
                <?php foreach ($preloaded_items as $item): ?>
                <div class="grn-item-card" style="background:#ffffff;border:1px solid #cbd5e1;border-radius:8px;padding:12px 14px;margin-bottom:12px;box-shadow:0 1px 3px rgba(0,0,0,0.04)">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;padding-bottom:8px;border-bottom:1px solid #f1f5f9;gap:8px">
                        <div style="display:flex;align-items:center;gap:8px;flex:1">
                            <span style="background:#e0f2fe;color:#0369a1;font-weight:700;font-size:11px;padding:3px 8px;border-radius:4px">ITEM</span>
                            <input class="item-barcode" name="item_code[]" value="<?= e($item['code']) ?>" readonly style="width:110px;background:#f8fafc;font-weight:700" placeholder="Code">
                            <select name="item_id[]" style="flex:1;background:#f8fafc;pointer-events:none">
                                <option value="<?= $item['item_id'] ?>" selected><?= e($item['code']) ?> — <?= e($item['name']) ?> (<?= e($item['unit'] ?: $item['item_unit'] ?: 'Units') ?>)</option>
                            </select>
                        </div>
                        <button type="button" class="btn-remove-row" style="background:#fee2e2;color:#b91c1c;border:none;border-radius:4px;padding:2px 7px;font-size:12px;cursor:pointer;font-weight:700" title="Remove item">✕</button>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:8px;margin-bottom:10px">
                        <div>
                            <label style="display:block;font-size:11px;font-weight:600;color:#64748b;margin-bottom:3px">📋 Ordered Qty</label>
                            <input name="qty_ordered[]" type="number" min="0" value="<?= (int)$item['quantity_ordered'] ?>" readonly style="width:100%;background:#f1f5f9;font-weight:600;text-align:center">
                        </div>
                        <div>
                            <label style="display:block;font-size:11px;font-weight:700;color:#0284c7;margin-bottom:3px">📦 Received Qty</label>
                            <input name="qty_received[]" type="number" min="0" value="<?= (int)$item['quantity_ordered'] ?>" required style="width:100%;font-weight:700;text-align:center;border-color:#38bdf8">
                        </div>
                        <div>
                            <label style="display:block;font-size:11px;font-weight:700;color:#16a34a;margin-bottom:3px">✅ Accepted (Stock)</label>
                            <input name="qty_accepted[]" type="number" min="0" value="<?= (int)$item['quantity_ordered'] ?>" required style="width:100%;font-weight:700;text-align:center;border-color:#4ade80;background:#f0fdf4">
                        </div>
                        <div>
                            <label style="display:block;font-size:11px;font-weight:700;color:#dc2626;margin-bottom:3px">❌ Rejected / Damage</label>
                            <input name="qty_rejected[]" type="number" min="0" value="0" style="width:100%;font-weight:600;text-align:center">
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:1.3fr 1fr 1fr 1fr;gap:8px">
                        <div>
                            <label style="display:block;font-size:11px;font-weight:600;color:#334155;margin-bottom:3px">🏷️ Batch Number</label>
                            <input name="batch[]" value="B-<?= date('Y') ?>-<?= sprintf('%03d', rand(100, 999)) ?>" placeholder="Batch No." required style="width:100%;font-weight:600">
                        </div>
                        <div>
                            <label style="display:block;font-size:11px;font-weight:600;color:#64748b;margin-bottom:3px">📅 Mfg Date</label>
                            <input name="mfg_date[]" type="date" value="<?= date('Y-m-d', strtotime('-1 month')) ?>" style="width:100%">
                        </div>
                        <div>
                            <label style="display:block;font-size:11px;font-weight:700;color:#b45309;margin-bottom:3px">⏳ Expiry Date (FEFO)</label>
                            <input name="exp_date[]" type="date" value="<?= date('Y-m-d', strtotime('+1 year')) ?>" required style="width:100%;font-weight:600;border-color:#fbbf24;background:#fffbeb">
                        </div>
                        <div>
                            <label style="display:block;font-size:11px;font-weight:600;color:#334155;margin-bottom:3px">💰 Rate (PKR)</label>
                            <input name="rate[]" type="number" step="0.01" value="<?= (float)$item['rate'] ?>" placeholder="0.00" style="width:100%">
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <?php for ($i=0; $i<2; $i++): ?>
                <div class="grn-item-card" style="background:#ffffff;border:1px solid #cbd5e1;border-radius:8px;padding:12px 14px;margin-bottom:12px;box-shadow:0 1px 3px rgba(0,0,0,0.04)">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;padding-bottom:8px;border-bottom:1px solid #f1f5f9;gap:8px">
                        <div style="display:flex;align-items:center;gap:8px;flex:1">
                            <span style="background:#e0f2fe;color:#0369a1;font-weight:700;font-size:11px;padding:3px 8px;border-radius:4px">ITEM</span>
                            <input class="item-barcode" name="item_code[]" placeholder="Scan / Code" autocomplete="off" style="width:110px;font-weight:600">
                            <select name="item_id[]" style="flex:1"><option value="">— Select item —</option><?php foreach ($items_all as $it): ?><option value="<?= $it['id'] ?>" data-code="<?= e($it['code']) ?>" data-name="<?= e($it['name']) ?>" data-unit="<?= e($it['unit']) ?>"><?= e($it['code']) ?> — <?= e($it['name']) ?></option><?php endforeach; ?></select>
                        </div>
                        <button type="button" class="btn-remove-row" style="background:#fee2e2;color:#b91c1c;border:none;border-radius:4px;padding:2px 7px;font-size:12px;cursor:pointer;font-weight:700" title="Remove item">✕</button>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:8px;margin-bottom:10px">
                        <div>
                            <label style="display:block;font-size:11px;font-weight:600;color:#64748b;margin-bottom:3px">📋 Ordered Qty</label>
                            <input name="qty_ordered[]" type="number" min="0" placeholder="0" style="width:100%;background:#f1f5f9;font-weight:600;text-align:center">
                        </div>
                        <div>
                            <label style="display:block;font-size:11px;font-weight:700;color:#0284c7;margin-bottom:3px">📦 Received Qty</label>
                            <input name="qty_received[]" type="number" min="0" placeholder="0" required style="width:100%;font-weight:700;text-align:center;border-color:#38bdf8">
                        </div>
                        <div>
                            <label style="display:block;font-size:11px;font-weight:700;color:#16a34a;margin-bottom:3px">✅ Accepted (Stock)</label>
                            <input name="qty_accepted[]" type="number" min="0" placeholder="0" required style="width:100%;font-weight:700;text-align:center;border-color:#4ade80;background:#f0fdf4">
                        </div>
                        <div>
                            <label style="display:block;font-size:11px;font-weight:700;color:#dc2626;margin-bottom:3px">❌ Rejected / Damage</label>
                            <input name="qty_rejected[]" type="number" min="0" value="0" style="width:100%;font-weight:600;text-align:center">
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:1.3fr 1fr 1fr 1fr;gap:8px">
                        <div>
                            <label style="display:block;font-size:11px;font-weight:600;color:#334155;margin-bottom:3px">🏷️ Batch Number</label>
                            <input name="batch[]" value="B-<?= date('Y') ?>-<?= sprintf('%03d', rand(100, 999)) ?>" placeholder="Batch No." required style="width:100%;font-weight:600">
                        </div>
                        <div>
                            <label style="display:block;font-size:11px;font-weight:600;color:#64748b;margin-bottom:3px">📅 Mfg Date</label>
                            <input name="mfg_date[]" type="date" value="<?= date('Y-m-d', strtotime('-1 month')) ?>" style="width:100%">
                        </div>
                        <div>
                            <label style="display:block;font-size:11px;font-weight:700;color:#b45309;margin-bottom:3px">⏳ Expiry Date (FEFO)</label>
                            <input name="exp_date[]" type="date" value="<?= date('Y-m-d', strtotime('+1 year')) ?>" required style="width:100%;font-weight:600;border-color:#fbbf24;background:#fffbeb">
                        </div>
                        <div>
                            <label style="display:block;font-size:11px;font-weight:600;color:#334155;margin-bottom:3px">💰 Rate (PKR)</label>
                            <input name="rate[]" type="number" step="0.01" placeholder="0.00" style="width:100%">
                        </div>
                    </div>
                </div>
                <?php endfor; ?>
            <?php endif; ?>
        </div>
        <button class="button button-primary" type="submit">Approve GRN &amp; post to Main Store stock</button>
        </form>
    </div>

    <?php if ($inspectGRN): ?>
    <div class="form-card" id="inspect-grn">
        <h2>Physical Inspection &amp; Stock Posting: <?= e($inspectGRN['grn_number']) ?></h2>
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px 14px;margin-bottom:1rem;font-size:12px;color:#1e40af;line-height:1.5">
            <strong>SIOVS Quality Inspection Protocol:</strong> Verify physical cartons against vendor Delivery Challan <b>#<?= e($inspectGRN['challan_number']) ?></b> (Supplier: <b><?= e($inspectGRN['sup_name'] ?: 'Direct receipt') ?></b>). Inspect seals, verify batch numbers and expiry dates. <b>Only accepted quantities post to Main Store stock.</b>
        </div>
        <form method="post"><input type="hidden" name="action" value="complete_grn_inspection">
        <input type="hidden" name="grn_id" value="<?= $inspectGRN['id'] ?>">
        <div class="form-grid one" style="margin-bottom:1rem">
            <label>Inspection Decision / Final Status
                <select name="inspection_status">
                    <option value="Inspected">Pass Inspection (Accept good units &amp; Post to Main Store Stock)</option>
                    <option value="Partially accepted">Partially Accepted (Accept passed units, isolate rejected units)</option>
                    <option value="Rejected">Reject Entire Consignment (Return to Supplier)</option>
                </select>
            </label>
            <label>Inspection remarks &amp; Quality verification notes
                <textarea name="remarks" rows="2"><?= e($inspectGRN['remarks'] ?: 'Material physically inspected and verified against specifications.') ?></textarea>
            </label>
        </div>
        <p class="eyebrow" style="margin:1rem 0 .5rem">CONSIGNMENT ITEMS TO INSPECT</p>
        <div id="inspect-items">
            <?php foreach ($inspectItems as $k => $item): ?>
            <div class="grn-item-card" style="background:#ffffff;border:1px solid #cbd5e1;border-radius:8px;padding:12px 14px;margin-bottom:12px;box-shadow:0 1px 3px rgba(0,0,0,0.04)">
                <input type="hidden" name="grn_item_id[]" value="<?= $item['id'] ?>">
                <input type="hidden" name="item_id[]" value="<?= $item['item_id'] ?>">
                <input type="hidden" name="item_code[]" value="<?= e($item['code']) ?>">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;padding-bottom:6px;border-bottom:1px solid #f1f5f9">
                    <b style="font-size:13px;color:#0f172a"><?= e($item['code']) ?> — <?= e($item['name']) ?></b>
                    <span class="badge" style="background:#e0f2fe;color:#0369a1;font-size:11px">Unit: <?= e($item['item_unit'] ?? 'Units') ?></span>
                </div>
                <div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:8px;margin-bottom:10px">
                    <div>
                        <label style="display:block;font-size:11px;font-weight:600;color:#64748b;margin-bottom:3px">📋 Ordered Qty</label>
                        <input type="number" value="<?= (int)$item['quantity_ordered'] ?>" readonly style="width:100%;background:#f1f5f9;font-weight:600;text-align:center">
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;font-weight:700;color:#0284c7;margin-bottom:3px">📦 Received Qty</label>
                        <input type="number" value="<?= (int)$item['quantity_received'] ?>" readonly style="width:100%;font-weight:700;text-align:center;background:#f0f9ff">
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;font-weight:700;color:#16a34a;margin-bottom:3px">✅ Accepted (Stock)</label>
                        <input name="qty_accepted[]" type="number" min="0" max="<?= (int)$item['quantity_received'] ?>" value="<?= (int)$item['quantity_received'] ?>" required style="width:100%;font-weight:700;text-align:center;border-color:#4ade80;background:#f0fdf4">
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;font-weight:700;color:#dc2626;margin-bottom:3px">❌ Rejected / Damage</label>
                        <input name="qty_rejected[]" type="number" min="0" value="0" style="width:100%;font-weight:600;text-align:center">
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1.3fr 1fr 1fr 1fr;gap:8px">
                    <div>
                        <label style="display:block;font-size:11px;font-weight:600;color:#334155;margin-bottom:3px">🏷️ Batch Number</label>
                        <input name="batch[]" value="<?= e($item['batch_number']) ?>" placeholder="Batch No." required style="width:100%;font-weight:600">
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;font-weight:600;color:#64748b;margin-bottom:3px">📅 Mfg Date</label>
                        <input name="mfg_date[]" type="date" value="<?= e($item['manufacturing_date']) ?>" style="width:100%">
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;font-weight:700;color:#b45309;margin-bottom:3px">⏳ Expiry Date (FEFO)</label>
                        <input name="exp_date[]" type="date" value="<?= e($item['expiry_date']) ?>" required style="width:100%;font-weight:600;border-color:#fbbf24;background:#fffbeb">
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;font-weight:600;color:#334155;margin-bottom:3px">💰 Rate (PKR)</label>
                        <input name="rate[]" type="number" step="0.01" value="<?= (float)$item['rate'] ?>" style="width:100%">
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div style="display:flex;gap:8px;align-items:center;margin-top:1.25rem">
            <button class="button button-primary" type="submit">✓ Pass Inspection &amp; Post to Main Store Stock</button>
            <a href="<?= page_link('grn') ?>" class="button button-light" style="text-decoration:none">Cancel</a>
        </div>
        </form>
    </div>
    <?php endif; ?>
</div>

<?php /* ════════════════════ INVENTORY ═════════════════════════════════════ */ ?>
<?php elseif ($view === 'inventory'): ?>
<?php
$today = date('Y-m-d');
$isPOUser = ((current_user()['role'] ?? '') === 'purchase_officer');
$lowStockCount = (int)$pdo->query('SELECT COUNT(*) FROM items WHERE quantity <= reorder_level')->fetchColumn();
?>
<div class="system-grid">
    <div class="panel">
        <div class="panel-heading">
            <h2><?= $isPOUser ? 'Store Stock &amp; Reorder Thresholds (Procurement View)' : 'Item master (Main Store ledger)' ?></h2>
            <?php if ($isPOUser): ?>
            <a class="button button-primary" href="page.php?view=purchase_orders#add-po">＋ New Purchase Order</a>
            <?php else: ?>
            <a class="button button-primary" href="#add-item">＋ Add item</a>
            <?php endif; ?>
        </div>
        <div class="panel-heading" style="border-top:none;padding-top:0"><input type="search" id="item-search" placeholder="Search items by code or name…" style="width:260px"></div>
        <div class="table-wrap"><table id="item-table">
            <thead><tr><th>Code / Item</th><th>Category</th><th>Location</th><th>Store balance</th><th>Reorder / Min / Max</th><th>Avg. Monthly</th><th>Lead Time</th><th>Latest Batch</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($pdo->query('SELECT * FROM items ORDER BY name') as $it): ?>
            <tr>
                <td><b><?= e($it['code']) ?></b><br><span class="muted"><?= e($it['name']) ?></span></td>
                <td><?= e($it['category']) ?><?php if (!empty($it['sub_category'])): ?><br><small class="muted"><?= e($it['sub_category']) ?></small><?php endif; ?></td>
                <td><?= e($it['location']) ?></td>
                <td class="<?= $it['quantity'] == 0 ? 'text-danger' : ($it['quantity'] <= $it['reorder_level'] ? 'text-warn' : '') ?>">
                    <strong><?= e($it['quantity']) ?></strong> <?= e($it['unit']) ?>
                </td>
                <td class="muted"><?= $it['reorder_level'] ?> / <?= $it['min_level'] ?> / <?= $it['max_level'] ?></td>
                <td><?= $it['average_consumption'] ? e($it['average_consumption']) . ' / mo' : '—' ?></td>
                <td><?= $it['lead_time_days'] ? e($it['lead_time_days']) . ' d' : '—' ?></td>
                <td><?= e($it['batch_number'] ?: '—') ?><?php if ($it['expiry_date']): ?><br><small class="muted">Exp: <?= e($it['expiry_date']) ?></small><?php endif; ?></td>
                <td><?= pill($it['status']) ?></td>
                <td>
                    <?php if ($it['quantity'] <= $it['reorder_level']): ?>
                    <a href="page.php?view=purchase_orders#add-po" class="button button-primary" style="padding:2px 8px;font-size:11px;background:#ea580c;border-color:#ea580c;text-decoration:none;white-space:nowrap">
                        🛒 Raise PO
                    </a>
                    <?php else: ?>
                    <span class="muted" style="font-size:11px">Stock OK</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <?php if ($isPOUser): ?>
    <div class="form-card" id="reorder-guide" style="border-top:4px solid #16a34a">
        <h2>Procurement &amp; Replenishment Guide</h2>
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:12px;margin:1rem 0;font-size:12px;color:#166534;line-height:1.45">
            <strong>SOP Section 3 &amp; 29 Compliance:</strong><br>
            The Purchase Section monitors Store balance levels to raise Purchase Orders. Physical stock cannot be directly increased by procurement; stock is incremented only upon Store Officer inspection and GRN generation.
        </div>
        <div style="margin:1rem 0;padding:12px;background:#fff7ed;border:1px solid #fed7aa;border-radius:6px">
            <b style="color:#c2410c;font-size:14px;display:block;margin-bottom:4px">⚠️ <?= $lowStockCount ?> Item<?= $lowStockCount !== 1 ? 's' : '' ?> Below Reorder Level</b>
            <p class="muted" style="font-size:12px;margin:0 0 10px">These items have breached safety thresholds and require immediate purchase order creation to avoid clinic stockouts.</p>
            <a href="page.php?view=purchase_orders#add-po" class="button button-primary" style="width:100%;text-align:center;background:#ea580c;border-color:#ea580c;padding:8px 12px;text-decoration:none;display:block;font-size:13px;font-weight:600">
                🛒 Create Purchase Order for Low Stock
            </a>
        </div>
        <div style="padding:10px 0;font-size:12px;color:#64748b">
            <b>Authorized Actions for Purchase Officer:</b>
            <ul style="margin:6px 0 0 1.2rem;padding:0">
                <li>Create &amp; authorize Purchase Orders</li>
                <li>Monitor vendor delivery timelines</li>
                <li>Verify Store inward GRN receipts</li>
            </ul>
        </div>
    </div>
    <?php else: ?>
    <div class="form-card" id="add-item">
        <h2>Add item to master</h2>
        <form method="post"><input type="hidden" name="action" value="item">
        <div class="form-grid one">
            <label>Item code<input name="code" value="<?= e(next_number($pdo, 'ITEM', 'items', 'code')) ?>" required></label>
            <label>Item name<input name="name" placeholder="e.g. Moxifloxacin 0.5% eye drops" required></label>
            <label>Category<select name="category" id="item-category">
                <option>Medicines</option>
                <option>Ophthalmic Medicines</option>
                <option>Surgical Consumables</option>
                <option>Ophthalmic Consumables</option>
                <option>General Medical Consumables</option>
                <option>Laboratory Consumables</option>
                <option>Cleaning Materials</option>
                <option>Stationery</option>
                <option>Electrical Items</option>
                <option>Engineering Materials</option>
                <option>Other Consumable Items</option>
            </select></label>
            <label>Sub-category<input name="sub_category" placeholder="e.g. Antibiotics, Intraocular Lenses, Disposables"></label>
            <label>Unit<input name="unit" value="Box" placeholder="Box / Vial / Unit / Amp / Bottle" required></label>
            <label>Storage location / Bin<input name="location" value="Main Store · Rack A<?= rand(1, 9) ?>" required></label>
            <label>Opening quantity<input name="quantity" type="number" min="0" value="0"></label>
            <label>Reorder level (Min alert)<input name="reorder_level" type="number" min="0" value="20"></label>
            <label>Minimum stock level<input name="min_level" type="number" min="0" value="10"></label>
            <label>Maximum stock level<input name="max_level" type="number" min="0" value="200"></label>
            <label>Average monthly consumption<input name="average_consumption" type="number" min="0" value="45"></label>
            <label>Lead time (delivery days)<input name="lead_time_days" type="number" min="0" value="7"></label>
            <label>Batch number (if opening qty > 0)<input name="batch_number" value="B-<?= date('Y') ?>-<?= sprintf('%04d', rand(1000, 9999)) ?>"></label>
            <label>Expiry date<input name="expiry_date" id="item-expiry-date" type="date" value="<?= date('Y-m-d', strtotime('+1 year')) ?>"><small id="medicine-expiry-hint" class="muted">Mandatory for medicines &amp; surgical items.</small></label>
            <label style="flex-direction:row;align-items:center;gap:.5rem"><input name="batch_required" type="checkbox" checked style="width:auto"> Batch / expiry tracking required</label>
        </div>
        <button class="button button-primary" type="submit">Save item to master</button>
        </form>
    </div>
    <?php endif; ?>
</div>

<?php /* ════════════════════ BATCHES & EXPIRY ══════════════════════════════ */ ?>
<?php elseif ($view === 'batches'): ?>
<?php
$filter = $_GET['filter'] ?? 'all';
$today = date('Y-m-d');
$where = 'WHERE ib.quantity > 0';

if ($filter === 'expired') {
    $where .= " AND ib.expiry_date IS NOT NULL AND ib.expiry_date < '{$today}'";
} elseif ($filter === '30') {
    $where .= " AND ib.expiry_date IS NOT NULL AND ib.expiry_date >= '{$today}' AND ib.expiry_date <= date('{$today}', '+30 days')";
} elseif ($filter === '60') {
    $where .= " AND ib.expiry_date IS NOT NULL AND ib.expiry_date >= '{$today}' AND ib.expiry_date <= date('{$today}', '+60 days')";
} elseif ($filter === '90') {
    $where .= " AND ib.expiry_date IS NOT NULL AND ib.expiry_date >= '{$today}' AND ib.expiry_date <= date('{$today}', '+90 days')";
} elseif ($filter === '180') {
    $where .= " AND ib.expiry_date IS NOT NULL AND ib.expiry_date >= '{$today}' AND ib.expiry_date <= date('{$today}', '+180 days')";
}

$batchItems = $pdo->query(
    "SELECT ib.*, i.code AS icode, i.name AS iname, i.category, i.unit, i.location, s.name AS sname,
            CAST(julianday(ib.expiry_date) - julianday('now') AS INTEGER) as days_left
     FROM item_batches ib
     JOIN items i ON i.id = ib.item_id
     LEFT JOIN suppliers s ON s.id = ib.supplier_id
     {$where}
     ORDER BY CASE WHEN ib.expiry_date IS NULL OR ib.expiry_date = '' THEN '9999-99-99' ELSE ib.expiry_date END ASC"
)->fetchAll(PDO::FETCH_ASSOC);

// Counts for filter pills
$cExpired = (int)$pdo->query("SELECT COUNT(*) FROM item_batches WHERE quantity > 0 AND expiry_date IS NOT NULL AND expiry_date < '{$today}'")->fetchColumn();
$c30 = (int)$pdo->query("SELECT COUNT(*) FROM item_batches WHERE quantity > 0 AND expiry_date IS NOT NULL AND expiry_date >= '{$today}' AND expiry_date <= date('{$today}', '+30 days')")->fetchColumn();
$c60 = (int)$pdo->query("SELECT COUNT(*) FROM item_batches WHERE quantity > 0 AND expiry_date IS NOT NULL AND expiry_date >= '{$today}' AND expiry_date <= date('{$today}', '+60 days')")->fetchColumn();
$c90 = (int)$pdo->query("SELECT COUNT(*) FROM item_batches WHERE quantity > 0 AND expiry_date IS NOT NULL AND expiry_date >= '{$today}' AND expiry_date <= date('{$today}', '+90 days')")->fetchColumn();
$c180 = (int)$pdo->query("SELECT COUNT(*) FROM item_batches WHERE quantity > 0 AND expiry_date IS NOT NULL AND expiry_date >= '{$today}' AND expiry_date <= date('{$today}', '+180 days')")->fetchColumn();
?>
<div class="panel">
    <div class="panel-heading" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem">
        <h2>Batch &amp; expiry register (FEFO Tracked)</h2>
        <div style="display:flex;gap:.35rem;flex-wrap:wrap;font-size:.85rem">
            <a class="button <?= $filter === 'all' ? 'button-primary' : 'button-light' ?>" href="<?= page_link('batches') ?>">All batches</a>
            <a class="button <?= $filter === 'expired' ? 'button-primary' : 'button-light' ?>" href="<?= page_link('batches') ?>&filter=expired">Expired (<?= $cExpired ?>)</a>
            <a class="button <?= $filter === '30' ? 'button-primary' : 'button-light' ?>" href="<?= page_link('batches') ?>&filter=30">&le; 30d (<?= $c30 ?>)</a>
            <a class="button <?= $filter === '60' ? 'button-primary' : 'button-light' ?>" href="<?= page_link('batches') ?>&filter=60">&le; 60d (<?= $c60 ?>)</a>
            <a class="button <?= $filter === '90' ? 'button-primary' : 'button-light' ?>" href="<?= page_link('batches') ?>&filter=90">&le; 90d (<?= $c90 ?>)</a>
            <a class="button <?= $filter === '180' ? 'button-primary' : 'button-light' ?>" href="<?= page_link('batches') ?>&filter=180">&le; 180d (<?= $c180 ?>)</a>
        </div>
    </div>
    <div class="table-wrap"><table>
        <thead><tr><th>Item Code / Name</th><th>Batch No.</th><th>Category</th><th>Store Location</th><th>Remaining Qty</th><th>Unit Cost (PKR)</th><th>Mfg. Date</th><th>Expiry Date</th><th>Days Left</th><th>FEFO Alert</th></tr></thead>
        <tbody>
        <?php if (!$batchItems): ?>
        <tr><td colspan="10" class="muted" style="text-align:center;padding:2rem">No active batches found matching this filter criteria.</td></tr>
        <?php endif; ?>
        <?php foreach ($batchItems as $bi): ?>
        <tr>
            <td><b><?= e($bi['icode']) ?></b><br><span class="muted"><?= e($bi['iname']) ?></span></td>
            <td><code style="font-weight:600"><?= e($bi['batch_number']) ?></code></td>
            <td><?= e($bi['category']) ?></td>
            <td><?= e($bi['location'] ?: 'Main Store') ?></td>
            <td><strong><?= e($bi['quantity']) ?></strong> <?= e($bi['unit']) ?></td>
            <td><?= $bi['unit_cost'] > 0 ? 'Rs. ' . number_format((float)$bi['unit_cost'], 2) : '—' ?></td>
            <td><?= e($bi['manufacturing_date'] ?: '—') ?></td>
            <td><b><?= e($bi['expiry_date']) ?></b></td>
            <td class="<?= $bi['days_left'] <= 0 ? 'text-danger' : ($bi['days_left'] <= 30 ? 'text-danger' : ($bi['days_left'] <= 90 ? 'text-warn' : '')) ?>">
                <?= $bi['days_left'] <= 0 ? 'EXPIRED' : $bi['days_left'] . ' days' ?>
            </td>
            <td>
                <?php
                if ($bi['days_left'] <= 0) echo pill('Expired');
                elseif ($bi['days_left'] <= 30) echo pill('30-day alert');
                elseif ($bi['days_left'] <= 60) echo pill('60-day alert');
                elseif ($bi['days_left'] <= 90) echo pill('90-day alert');
                elseif ($bi['days_left'] <= 180) echo pill('180-day alert');
                else echo pill('Available');
                ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<?php /* ════════════════════ ADJUSTMENTS ═══════════════════════════════════ */ ?>
<?php elseif ($view === 'adjustments'): ?>
<?php
$adjRows = $pdo->query('
    SELECT a.*, i.name AS iname, i.code AS icode, i.unit, u.name AS uname
    FROM stock_adjustments a
    LEFT JOIN items i ON i.id = a.item_id
    LEFT JOIN users u ON u.id = a.requesting_user_id
    ORDER BY a.id DESC
')->fetchAll(PDO::FETCH_ASSOC);

$totalAdjs = count($adjRows);
$pendingAdjs = count(array_filter($adjRows, fn($a) => $a['status'] === 'Pending'));
$approvedAdjs = count(array_filter($adjRows, fn($a) => $a['status'] === 'Approved'));
$netVal = (float)array_sum(array_map(fn($a) => (float)$a['value'], $adjRows));
$isAuthorizedForAdj = can('dd_finance') || can('competent_authority') || (current_user()['role'] ?? '') === 'admin';
?>

<div class="kpi-grid four" style="margin-bottom:1.5rem">
    <div class="kpi-card">
        <p class="kpi-label">Total Adjustments Logged</p>
        <p class="kpi-val"><?= $totalAdjs ?></p>
        <p class="kpi-sub">Lifetime stock adjustments</p>
    </div>
    <div class="kpi-card" style="border-left:4px solid #f59e0b">
        <p class="kpi-label">Pending Authorization</p>
        <p class="kpi-val" style="color:#b45309"><?= $pendingAdjs ?></p>
        <p class="kpi-sub">Awaiting DD Finance approval</p>
    </div>
    <div class="kpi-card" style="border-left:4px solid #10b981">
        <p class="kpi-label">Approved & Posted</p>
        <p class="kpi-val" style="color:#047857"><?= $approvedAdjs ?></p>
        <p class="kpi-sub">Active in stock ledger</p>
    </div>
    <div class="kpi-card">
        <p class="kpi-label">Governance Control</p>
        <p class="kpi-val" style="font-size:1.05rem;color:#166534">DD Finance Sign-Off</p>
        <p class="kpi-sub">Double-entry ledger posting</p>
    </div>
</div>

<?php if ((current_user()['role'] ?? '') === 'dd_finance' && $pendingAdjs > 0): ?>
<div style="background:#fffbeb;border:1px solid #fde68a;border-left:4px solid #f59e0b;padding:12px 16px;border-radius:8px;margin-bottom:1.25rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
    <div>
        <strong style="color:#92400e;font-size:13px">Action Required: <?= $pendingAdjs ?> Stock Adjustment<?= $pendingAdjs > 1 ? 's' : '' ?> Awaiting Financial Authorization</strong>
        <p style="margin:2px 0 0;font-size:12px;color:#b45309">As Deputy Director Finance, review the variances below and authorize to update inventory balances or reject if unjustified.</p>
    </div>
    <span class="badge" style="background:#f59e0b;color:#fff;font-weight:700;font-size:12px;padding:4px 10px"><?= $pendingAdjs ?> Pending</span>
</div>
<?php endif; ?>

<div class="system-grid">
    <div class="panel">
        <div class="panel-heading">
            <h2>Stock Adjustments Register</h2>
            <?php if ((current_user()['role'] ?? '') !== 'dd_finance'): ?>
                <a class="button button-primary" href="#add-adj">＋ New Adjustment</a>
            <?php else: ?>
                <span class="badge" style="background:#0d9488;color:#fff;font-size:12px;padding:5px 12px;font-weight:600">⚖️ Financial Authorization Mode</span>
            <?php endif; ?>
        </div>
        <div class="table-wrap"><table>
            <thead>
                <tr>
                    <th>Adj. No. / Date</th>
                    <th>Item Code & Name</th>
                    <th style="max-width:240px">Reason &amp; Register Ref</th>
                    <th>Quantity</th>
                    <th>Est. Value</th>
                    <th>Status / Approver</th>
                    <th style="min-width:140px;text-align:right">Action / Decision</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($adjRows)): ?>
            <tr><td colspan="7" class="muted" style="text-align:center;padding:2.5rem">No stock adjustments recorded.</td></tr>
            <?php else: foreach ($adjRows as $adj): ?>
            <tr style="<?= $adj['status'] === 'Pending' ? 'background:#fffdf5' : '' ?>">
                <td>
                    <b><?= e($adj['adj_number']) ?></b><br>
                    <small class="muted"><?= e(date('d M Y', strtotime($adj['created_at']))) ?></small>
                </td>
                <td>
                    <b><?= e($adj['icode']) ?></b><br>
                    <span class="muted"><?= e($adj['iname']) ?></span>
                </td>
                <td style="max-width:240px">
                    <b><?= e($adj['reason']) ?></b>
                    <?php if (!empty($adj['notes'])): ?>
                        <br><small class="muted"><?= e($adj['notes']) ?></small>
                    <?php endif; ?>
                    <?php if (!empty($adj['manual_register_page']) || !empty($adj['manual_register_entry'])): ?>
                        <br><small style="color:#64748b">📖 <?= e($adj['manual_register_name'] ?: 'Reg.') ?> Pg: <b><?= e($adj['manual_register_page'] ?: '—') ?></b> · Ent: <b><?= e($adj['manual_register_entry'] ?: '—') ?></b></small>
                    <?php endif; ?>
                </td>
                <td style="font-weight:700;font-size:1rem" class="<?= $adj['quantity'] < 0 ? 'text-danger' : 'text-success' ?>">
                    <?= $adj['quantity'] > 0 ? '+' : '' ?><?= e($adj['quantity']) ?> <small><?= e($adj['unit'] ?? '') ?></small>
                </td>
                <td>
                    <?= $adj['value'] > 0 ? 'Rs. ' . number_format((float)$adj['value'], 2) : '—' ?>
                </td>
                <td>
                    <?= pill($adj['status']) ?><br>
                    <small class="muted">
                        Req: <?= e($adj['uname'] ?? 'Staff') ?><br>
                        <?= !empty($adj['authorizing_officer']) ? 'Auth: ' . e($adj['authorizing_officer']) : '' ?>
                    </small>
                </td>
                <td style="text-align:right">
                <?php if ($adj['status'] === 'Pending' && $isAuthorizedForAdj): ?>
                    <div style="display:inline-flex;gap:5px;align-items:center;justify-content:flex-end;flex-wrap:wrap">
                        <form method="post" style="display:inline;margin:0">
                            <input type="hidden" name="action" value="approve_adjustment">
                            <input type="hidden" name="adj_id" value="<?= $adj['id'] ?>">
                            <button class="button button-primary" type="submit" style="padding:4px 8px;font-size:11px;background:#047857;border-color:#065f46" onclick="return confirm('Authorize adjustment <?= e($adj['adj_number']) ?> and update stock ledger?')">✓ Approve</button>
                        </form>
                        <form method="post" style="display:inline;margin:0" onsubmit="var r = prompt('Reason for rejecting adjustment <?= e($adj['adj_number']) ?>:'); if(!r) return false; this.rejection_reason.value = r; return true;">
                            <input type="hidden" name="action" value="reject_adjustment">
                            <input type="hidden" name="adj_id" value="<?= $adj['id'] ?>">
                            <input type="hidden" name="rejection_reason" value="">
                            <button class="button button-light" type="submit" style="padding:4px 8px;font-size:11px;color:#b91c1c;border-color:#fecaca">✕ Reject</button>
                        </form>
                    </div>
                <?php elseif ($adj['status'] === 'Pending'): ?>
                    <span class="muted" style="font-size:11px">Awaiting DD Finance</span>
                <?php else: ?>
                    <span class="muted" style="font-size:11px">Completed</span>
                <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>

    <?php if ((current_user()['role'] ?? '') !== 'dd_finance'): ?>
    <div class="form-card" id="add-adj">
        <h2>Submit Stock Adjustment</h2>
        <p class="muted" style="margin-bottom:1rem">Requires authorization from DD Finance or Competent Authority before stock ledger is updated.</p>
        <form method="post">
            <input type="hidden" name="action" value="adjustment">
            <div class="form-grid one">
                <label>Item to Adjust
                    <select name="item_id" required>
                        <?php foreach ($items_all as $it): ?>
                        <option value="<?= $it['id'] ?>"><?= e($it['code']) ?> — <?= e($it['name']) ?> (Stock: <?= $it['quantity'] ?> <?= e($it['unit']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                    <label>Direction
                        <select name="direction">
                            <option value="out">Deduction (– Stock Drop)</option>
                            <option value="in">Addition (＋ Stock Increase)</option>
                        </select>
                    </label>
                    <label>Quantity
                        <input name="quantity" type="number" min="1" value="1" required>
                    </label>
                </div>
                <label>Adjustment Reason
                    <select name="reason">
                        <option value="Physical Verification Shortage">Physical Verification Shortage</option>
                        <option value="Physical Verification Excess">Physical Verification Excess</option>
                        <option value="Damage / Compromised Packaging">Damage / Compromised Packaging</option>
                        <option value="Expiry Write-Off">Expiry Write-Off</option>
                        <option value="Laboratory Test / Quality Sampling">Laboratory Test / Quality Sampling</option>
                        <option value="Manual Book Folio Correction">Manual Book Folio Correction</option>
                        <option value="Other Authorized Reason">Other Authorized Reason</option>
                    </select>
                </label>
                <label>Estimated Value (Rs.)
                    <input name="value" type="number" step="0.01" min="0" value="0.00">
                </label>
            </div>

            <p class="eyebrow" style="margin:1.2rem 0 .4rem">SINDH GOVT. MANUAL REGISTER CROSS-REFERENCE</p>
            <div class="form-grid one" style="background:var(--surface-sunken, #f8fafc);padding:.75rem;border-radius:6px;border:1px dashed var(--border-color,#e2e8f0)">
                <label>Manual Register Name
                    <input name="manual_register_name" value="Store Adjustment Register">
                </label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                    <label>Page No.<input name="manual_register_page" placeholder="e.g. 34"></label>
                    <label>Entry / Sr No.<input name="manual_register_entry" placeholder="e.g. 11"></label>
                </div>
            </div>

            <label style="margin-top:1rem">Detailed Notes / Supporting Justification
                <textarea name="notes" rows="3" placeholder="Provide detailed explanation for this adjustment request..."></textarea>
            </label>

            <button class="button button-primary" type="submit" style="margin-top:1.2rem;width:100%">Submit for DD Finance Authorization</button>
        </form>
    </div>
    <?php endif; ?>
</div>

<?php /* ════════════════════ VERIFICATION ═══════════════════════════════════ */ ?>
<?php elseif ($view === 'verification'): ?>
<?php
$verRecords = $pdo->query('
    SELECT v.*, u.name AS creator_name,
           (SELECT COUNT(*) FROM stock_verification_items WHERE verification_id = v.id) AS item_count,
           (SELECT COUNT(*) FROM stock_verification_items WHERE verification_id = v.id AND difference != 0) AS discrepancy_count,
           (SELECT COUNT(*) FROM stock_adjustments WHERE (verification_id = v.id OR notes LIKE "%" || v.ver_number || "%") AND status = "Approved") AS approved_adj_count,
           (SELECT COUNT(*) FROM stock_adjustments WHERE (verification_id = v.id OR notes LIKE "%" || v.ver_number || "%") AND status = "Pending") AS pending_adj_count
    FROM stock_verifications v
    LEFT JOIN users u ON u.id = v.created_by
    ORDER BY v.id DESC
')->fetchAll(PDO::FETCH_ASSOC);

$totalVerifications = count($verRecords);
$totalDiscrepancies = (int)array_sum(array_column($verRecords, 'discrepancy_count'));

// Check for edit mode
$editVer = null;
$editItemMap = [];
$isLocked = false;
if (!empty($_GET['edit_ver'])) {
    $eId = (int)$_GET['edit_ver'];
    $eStmt = $pdo->prepare('SELECT * FROM stock_verifications WHERE id = ?');
    $eStmt->execute([$eId]);
    $editVer = $eStmt->fetch(PDO::FETCH_ASSOC);
    if ($editVer) {
        $chkAppr = $pdo->prepare('SELECT COUNT(*) FROM stock_adjustments WHERE verification_id = ? AND status = "Approved"');
        $chkAppr->execute([$eId]);
        $isLocked = ((int)$chkAppr->fetchColumn() > 0) || ($editVer['status'] === 'Voided');

        $eiStmt = $pdo->prepare('SELECT * FROM stock_verification_items WHERE verification_id = ?');
        $eiStmt->execute([$eId]);
        foreach ($eiStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $editItemMap[$row['item_id']] = $row;
        }
    }
}

// Check for view details mode
$viewVer = null;
$viewItems = [];
$viewIsLocked = false;
if (!empty($_GET['view_ver'])) {
    $vId = (int)$_GET['view_ver'];
    $vStmt = $pdo->prepare('
        SELECT v.*, u.name AS creator_name
        FROM stock_verifications v
        LEFT JOIN users u ON u.id = v.created_by
        WHERE v.id = ?
    ');
    $vStmt->execute([$vId]);
    $viewVer = $vStmt->fetch(PDO::FETCH_ASSOC);
    if ($viewVer) {
        $chkAppr = $pdo->prepare('SELECT COUNT(*) FROM stock_adjustments WHERE verification_id = ? AND status = "Approved"');
        $chkAppr->execute([$vId]);
        $viewIsLocked = ((int)$chkAppr->fetchColumn() > 0) || ($viewVer['status'] === 'Voided');

        $viStmt = $pdo->prepare('
            SELECT vi.*, i.name AS item_name, i.code AS item_code, i.unit AS item_unit,
                   sa.adj_number, sa.status AS adj_status
            FROM stock_verification_items vi
            JOIN items i ON i.id = vi.item_id
            LEFT JOIN stock_adjustments sa ON sa.verification_id = vi.verification_id AND sa.item_id = vi.item_id
            WHERE vi.verification_id = ?
            ORDER BY i.name ASC
        ');
        $viStmt->execute([$vId]);
        $viewItems = $viStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>

<div class="kpi-grid four" style="margin-bottom:1.5rem">
    <div class="kpi-card">
        <p class="kpi-label">Verifications Conducted</p>
        <p class="kpi-val"><?= $totalVerifications ?></p>
        <p class="kpi-sub">Committee physical audits</p>
    </div>
    <div class="kpi-card" style="border-left:4px solid #ef4444">
        <p class="kpi-label">Discrepancy Items</p>
        <p class="kpi-val" style="color:#b91c1c"><?= $totalDiscrepancies ?></p>
        <p class="kpi-sub">Variances (Physical ≠ System)</p>
    </div>
    <div class="kpi-card">
        <p class="kpi-label">Audit Mechanism</p>
        <p class="kpi-val" style="font-size:1.05rem;color:#166534">Multi-Member Board</p>
        <p class="kpi-sub">Store, Audit & Admin committee</p>
    </div>
    <div class="kpi-card">
        <p class="kpi-label">Adjustment Workflow</p>
        <p class="kpi-val" style="font-size:1.05rem;color:#0f766e">Auto DD Finance Sync</p>
        <p class="kpi-sub">Auto-creates adjustment queue</p>
    </div>
</div>

<div class="system-grid">
    <div class="panel">
        <div class="panel-heading">
            <h2>Physical Stock Verification Audits</h2>
            <?php if ((current_user()['role'] ?? '') !== 'dd_finance'): ?>
                <a class="button button-primary" href="#add-ver">＋ New Physical Audit</a>
            <?php else: ?>
                <a class="button button-primary" href="page.php?view=adjustments" style="background:#0d9488;border-color:#0f766e;font-size:12px">⚖️ Review Adjustment Queue</a>
            <?php endif; ?>
        </div>
        <div class="table-wrap"><table>
            <thead>
                <tr>
                    <th>Ver. No. / Date</th>
                    <th>Committee Members</th>
                    <th>Items Audited</th>
                    <th>Discrepancies</th>
                    <th>Remarks</th>
                    <th>Status</th>
                    <th style="text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($verRecords)): ?>
            <tr><td colspan="7" class="muted" style="text-align:center;padding:2.5rem">No physical stock verification records found. Conduct a count using the form.</td></tr>
            <?php else: foreach ($verRecords as $v): ?>
            <tr>
                <td>
                    <b><?= e($v['ver_number']) ?></b><br>
                    <small class="muted"><?= e(date('d M Y', strtotime($v['verification_date']))) ?></small>
                </td>
                <td>
                    <b><?= e($v['committee_members']) ?></b><br>
                    <small class="muted">By: <?= e($v['creator_name'] ?? 'Officer') ?></small>
                </td>
                <td><b><?= (int)$v['item_count'] ?></b> items</td>
                <td>
                    <?php if ((int)$v['discrepancy_count'] === 0): ?>
                        <span style="color:#166534;font-weight:600">✓ 0 Variances</span>
                    <?php else: ?>
                        <span style="color:#b91c1c;font-weight:700">⚠️ <?= (int)$v['discrepancy_count'] ?> Variance(s)</span>
                    <?php endif; ?>
                </td>
                <td><?= e($v['remarks']) ?></td>
                <td>
                    <?= pill($v['status']) ?>
                    <?php if ((int)$v['approved_adj_count'] > 0): ?>
                        <br><span class="badge" style="background:#dcfce7;color:#166534;font-size:10px;padding:2px 6px">✓ DD Approved</span>
                    <?php elseif ((int)$v['pending_adj_count'] > 0): ?>
                        <br><span class="badge" style="background:#fef3c7;color:#92400e;font-size:10px;padding:2px 6px">⏳ DD Review</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:right">
                    <div style="display:inline-flex;gap:4px;align-items:center;justify-content:flex-end;flex-wrap:wrap">
                        <a class="button button-light" href="page.php?view=verification&view_ver=<?= $v['id'] ?>#view-ver-details" style="padding:4px 8px;font-size:11px" title="View Discrepancy Breakdown">👁️ View</a>
                        <?php if ((current_user()['role'] ?? '') === 'dd_finance'): ?>
                            <?php if ((int)$v['pending_adj_count'] > 0): ?>
                                <a class="button button-primary" href="page.php?view=adjustments" style="padding:4px 8px;font-size:11px;background:#0d9488;border-color:#0f766e" title="Review pending adjustments generated from this audit">⚖️ Authorize (<?= (int)$v['pending_adj_count'] ?>)</a>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php if ($v['status'] === 'Voided'): ?>
                                <span class="muted" style="font-size:11px">Voided</span>
                            <?php elseif ((int)$v['approved_adj_count'] > 0): ?>
                                <span class="badge" style="background:#f1f5f9;color:#475569;border:1px solid #cbd5e1;font-size:11px" title="Adjustments approved by DD Finance. Locked.">🔒 Locked</span>
                            <?php else: ?>
                                <a class="button button-light" href="page.php?view=verification&edit_ver=<?= $v['id'] ?>#add-ver" style="padding:4px 8px;font-size:11px" title="Edit audit counts">✏️ Edit</a>
                                <form method="post" style="display:inline;margin:0" onsubmit="return confirm('Void verification <?= e($v['ver_number']) ?>? Any linked pending adjustments will be cancelled.')">
                                    <input type="hidden" name="action" value="void_verification">
                                    <input type="hidden" name="verification_id" value="<?= $v['id'] ?>">
                                    <button type="submit" class="button button-light" style="padding:4px 8px;font-size:11px;color:#dc2626;border-color:#fecaca" title="Void audit and cancel pending adjustments">✕ Void</button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>

    <?php if ($viewVer): ?>
    <div class="form-card" id="view-ver-details">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;border-bottom:1px solid var(--border-color,#e2e8f0);padding-bottom:.75rem">
            <div>
                <h2 style="margin:0 0 4px">Audit Details: <?= e($viewVer['ver_number']) ?></h2>
                <div class="muted" style="font-size:12px">Conducted on <?= e(date('d M Y', strtotime($viewVer['verification_date']))) ?> · Status: <?= pill($viewVer['status']) ?></div>
            </div>
            <div>
                <?php if ($viewVer['status'] !== 'Voided' && !$viewIsLocked && (current_user()['role'] ?? '') !== 'dd_finance'): ?>
                    <a class="button button-primary" href="page.php?view=verification&edit_ver=<?= $viewVer['id'] ?>#add-ver" style="font-size:12px;padding:6px 12px">✏️ Edit Counts</a>
                <?php endif; ?>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.2rem;background:var(--surface-sunken,#f8fafc);padding:.75rem;border-radius:6px;border:1px solid var(--border-color,#e2e8f0);font-size:12px">
            <div>
                <b>Committee Members:</b><br><?= e($viewVer['committee_members']) ?><br>
                <span class="muted">Conducted by: <?= e($viewVer['creator_name'] ?? 'Officer') ?></span>
            </div>
            <div>
                <b>Scope &amp; Remarks:</b><br><?= e($viewVer['remarks']) ?>
            </div>
        </div>

        <p class="eyebrow" style="margin:1rem 0 .4rem">AUDITED INVENTORY &amp; VARIANCE BREAKDOWN</p>
        <div class="table-wrap"><table>
            <thead>
                <tr>
                    <th>Item</th>
                    <th style="text-align:right">System Qty</th>
                    <th style="text-align:right">Physical Count</th>
                    <th style="text-align:right">Variance</th>
                    <th>Discrepancy Note</th>
                    <th>Adjustment Status</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($viewItems as $vi): ?>
            <tr>
                <td>
                    <b><?= e($vi['item_code']) ?></b><br>
                    <span class="muted" style="font-size:.8rem"><?= e($vi['item_name']) ?></span>
                </td>
                <td style="text-align:right"><b><?= (int)$vi['system_quantity'] ?></b> <?= e($vi['item_unit']) ?></td>
                <td style="text-align:right"><b><?= (int)$vi['physical_quantity'] ?></b> <?= e($vi['item_unit']) ?></td>
                <td style="text-align:right">
                    <?php $d = (int)$vi['difference']; ?>
                    <?php if ($d === 0): ?>
                        <span style="color:#166534;font-weight:600">0</span>
                    <?php elseif ($d > 0): ?>
                        <span style="color:#2563eb;font-weight:700">+<?= $d ?> (Excess)</span>
                    <?php else: ?>
                        <span style="color:#dc2626;font-weight:700"><?= $d ?> (Shortage)</span>
                    <?php endif; ?>
                </td>
                <td><?= !empty($vi['explanation']) ? e($vi['explanation']) : '<span class="muted">—</span>' ?></td>
                <td>
                    <?php if (!empty($vi['adj_number'])): ?>
                        <b><?= e($vi['adj_number']) ?></b><br>
                        <?= pill($vi['adj_status']) ?>
                    <?php elseif ($d !== 0): ?>
                        <span class="muted" style="font-size:11px">No Adj Requested</span>
                    <?php else: ?>
                        <span style="color:#166534;font-size:11px">Match (No Adj)</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>

        <div style="margin-top:1.2rem;display:flex;justify-content:flex-end">
            <a class="button button-light" href="page.php?view=verification">Close Details</a>
        </div>
    </div>
    <?php endif; ?>

    <?php if ((current_user()['role'] ?? '') !== 'dd_finance'): ?>
    <div class="form-card" id="add-ver">
        <?php if ($editVer): ?>
            <h2>Edit Physical Stock Verification: <?= e($editVer['ver_number']) ?></h2>
            <?php if ($isLocked): ?>
                <div style="background:#fee2e2;border:1px solid #fca5a5;padding:12px;border-radius:6px;color:#991b1b;margin-bottom:1rem;font-size:12px">
                    <strong>🔒 Audit Locked:</strong> This physical verification cannot be edited because its adjustments have already been approved by DD Finance or it has been voided.
                </div>
                <p><a href="page.php?view=verification" class="button button-light">Return to Verification Register</a></p>
            <?php else: ?>
                <p class="muted" style="margin-bottom:1rem">Modify physical counts or explanations. Saving as Draft will stage counts; submitting will update pending adjustments for DD Finance review.</p>
                <form method="post">
                    <input type="hidden" name="action" value="verification">
                    <input type="hidden" name="verification_id" value="<?= $editVer['id'] ?>">
                    <div class="form-grid one">
                        <label>Verification Date
                            <input name="verification_date" type="date" value="<?= e($editVer['verification_date']) ?>" required>
                        </label>
                        <label>Committee Members / Board of Survey
                            <input name="committee_members" value="<?= e($editVer['committee_members']) ?>" required>
                        </label>
                        <label>Audit Scope / Remarks
                            <textarea name="remarks" rows="2" placeholder="e.g. Biannual physical stock verification of Main Drug Depot..."><?= e($editVer['remarks']) ?></textarea>
                        </label>
                        <div style="background:#f0fdf4;padding:.6rem;border-radius:4px;border:1px solid #bbf7d0;margin:.5rem 0">
                            <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-weight:600;color:#166534;margin:0">
                                <input type="checkbox" name="generate_adjustments" value="1" checked style="width:auto">
                                Auto-generate/update pending stock adjustments for DD Finance review on all items with variance
                            </label>
                        </div>
                    </div>

                    <p class="eyebrow" style="margin:1.2rem 0 .4rem">PHYSICAL COUNT (SYSTEM BALANCE COMPARISON)</p>
                    <div class="table-wrap"><table>
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>System Qty</th>
                                <th>Physical Count</th>
                                <th>Discrepancy Note</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($items_all as $it): ?>
                        <?php
                            $savedItem = $editItemMap[$it['id']] ?? null;
                            $pQty = $savedItem ? $savedItem['physical_quantity'] : $it['quantity'];
                            $sQty = $savedItem ? $savedItem['system_quantity'] : $it['quantity'];
                            $expl = $savedItem ? $savedItem['explanation'] : '';
                        ?>
                        <tr>
                            <td>
                                <b><?= e($it['code']) ?></b><br>
                                <span class="muted" style="font-size:.8rem"><?= e($it['name']) ?></span>
                                <input type="hidden" name="item_id[]" value="<?= $it['id'] ?>">
                                <input type="hidden" name="system_qty[]" value="<?= $sQty ?>">
                            </td>
                            <td><b><?= $sQty ?></b> <?= e($it['unit']) ?></td>
                            <td>
                                <input name="physical_qty[]" type="number" min="0" value="<?= $pQty ?>" style="width:75px;font-weight:600">
                            </td>
                            <td>
                                <input name="explanation[]" value="<?= e($expl) ?>" placeholder="Reason if different" style="font-size:.8rem;width:160px">
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>

                    <div style="display:flex;gap:10px;margin-top:1.2rem;flex-wrap:wrap">
                        <button class="button button-primary" type="submit" name="submission_mode" value="submitted" style="flex:1">✓ Finalize &amp; Submit Count</button>
                        <button class="button button-light" type="submit" name="submission_mode" value="draft" style="flex:1">💾 Save as Draft</button>
                    </div>
                </form>
            <?php endif; ?>
        <?php else: ?>
            <h2>Conduct Physical Stock Verification</h2>
            <p class="muted" style="margin-bottom:1rem">Enter the counted physical balances. Any differences can automatically generate pending adjustment requests for DD Finance.</p>
            <form method="post">
                <input type="hidden" name="action" value="verification">
                <div class="form-grid one">
                    <label>Verification Date
                        <input name="verification_date" type="date" value="<?= date('Y-m-d') ?>" required>
                    </label>
                    <label>Committee Members / Board of Survey
                        <input name="committee_members" value="Store Officer, Internal Auditor, Nursing Supdt." required>
                    </label>
                    <label>Audit Scope / Remarks
                        <textarea name="remarks" rows="2" placeholder="e.g. Biannual physical stock verification of Main Drug Depot...">Quarterly physical stock verification</textarea>
                    </label>
                    <div style="background:#f0fdf4;padding:.6rem;border-radius:4px;border:1px solid #bbf7d0;margin:.5rem 0">
                        <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-weight:600;color:#166534;margin:0">
                            <input type="checkbox" name="generate_adjustments" value="1" checked style="width:auto">
                            Auto-generate pending stock adjustments for DD Finance review on all items with variance
                        </label>
                    </div>
                </div>

                <p class="eyebrow" style="margin:1.2rem 0 .4rem">PHYSICAL COUNT (LIVE SYSTEM BALANCE COMPARISON)</p>
                <div class="table-wrap"><table>
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>System Qty</th>
                            <th>Physical Count</th>
                            <th>Discrepancy Note</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items_all as $it): ?>
                    <tr>
                        <td>
                            <b><?= e($it['code']) ?></b><br>
                            <span class="muted" style="font-size:.8rem"><?= e($it['name']) ?></span>
                            <input type="hidden" name="item_id[]" value="<?= $it['id'] ?>">
                            <input type="hidden" name="system_qty[]" value="<?= $it['quantity'] ?>">
                        </td>
                        <td><b><?= $it['quantity'] ?></b> <?= e($it['unit']) ?></td>
                        <td>
                            <input name="physical_qty[]" type="number" min="0" value="<?= $it['quantity'] ?>" style="width:75px;font-weight:600">
                        </td>
                        <td>
                            <input name="explanation[]" placeholder="Reason if different" style="font-size:.8rem;width:160px">
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>

                <div style="display:flex;gap:10px;margin-top:1.2rem;flex-wrap:wrap">
                    <button class="button button-primary" type="submit" name="submission_mode" value="submitted" style="flex:1">Save Physical Verification Audit</button>
                    <button class="button button-light" type="submit" name="submission_mode" value="draft" style="flex:1">💾 Save as Draft (Stage Counts)</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php /* ════════════════════ REQUISITIONS ════════════════════════════════════ */ ?>
<?php elseif ($view === 'requisitions'): ?>
<div class="system-grid">
    <div class="panel">
        <div class="panel-heading"><h2>Departmental requisition register</h2><a class="button button-primary" href="#add-req">＋ New requisition</a></div>
        <div class="table-wrap"><table>
            <thead><tr><th>Req. No.</th><th>Department</th><th>Date</th><th>Purpose</th><th>Manual Reg.</th><th>Requested by</th><th>Approver / Store</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($pdo->query('SELECT r.*, d.name AS dname FROM requisitions r LEFT JOIN departments d ON d.id=r.department_id ORDER BY r.id DESC') as $r): ?>
            <tr>
                <td><b><?= e($r['req_number']) ?></b></td>
                <td><b><?= e($r['dname']) ?></b></td>
                <td><?= e($r['req_date']) ?></td>
                <td><?= e($r['purpose']) ?></td>
                <td>
                    <?php if (!empty($r['manual_register_name'])): ?>
                        <small><b><?= e($r['manual_register_name']) ?></b><br>Pg: <?= e($r['manual_register_page'] ?: '-') ?> · Ent: <?= e($r['manual_register_entry'] ?: '-') ?></small>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td><?= e($r['requesting_officer']) ?></td>
                <td><small><?= e($r['approving_officer'] ? 'Appr: ' . $r['approving_officer'] : '') ?><?= e($r['store_officer'] ? '<br>Store: ' . $r['store_officer'] : '') ?></small></td>
                <td><?= pill($r['status']) ?></td>
                <td>
                <?php if ($r['status'] === 'Draft' && (can('requisitions') || can('admin'))): ?>
                <form method="post" style="display:inline">
                    <input type="hidden" name="action" value="approve_requisition">
                    <input type="hidden" name="req_id" value="<?= $r['id'] ?>">
                    <?php $riRows = $pdo->prepare('SELECT ri.*, i.name AS iname FROM requisition_items ri LEFT JOIN items i ON i.id=ri.item_id WHERE ri.req_id=?'); $riRows->execute([$r['id']]); ?>
                    <?php foreach ($riRows->fetchAll(PDO::FETCH_ASSOC) as $ri): ?>
                    <span class="muted" style="font-size:.8rem"><?= e($ri['iname']) ?>: </span>
                    <input type="number" name="approved_qty[<?= $ri['id'] ?>]" value="<?= $ri['required_quantity'] ?>" min="0" style="width:55px">
                    <?php endforeach; ?>
                    <button class="button button-light" type="submit" style="padding:.2rem .5rem;font-size:.8rem;margin-left:.3rem">Approve</button>
                </form>
                <?php elseif ($r['status'] === 'Approved' && (can('store_officer') || can('admin'))): ?>
                <form method="post" style="display:inline">
                    <input type="hidden" name="action" value="issue_requisition">
                    <input type="hidden" name="req_id" value="<?= $r['id'] ?>">
                    <button class="button button-primary" type="submit" style="padding:.2rem .6rem;font-size:.8rem">Issue stock (FEFO)</button>
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
        <form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="requisition">
        <div class="form-grid one">
            <label>Requesting department<select name="department_id" required><?php foreach ($depts_all as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?> (<?= e($d['type']) ?>)</option><?php endforeach; ?></select></label>
            <label>Requisition date<input name="req_date" type="date" value="<?= date('Y-m-d') ?>" required></label>
            <label>Purpose of requirement<input name="purpose" value="Monthly department stock replenishment" required></label>
            <label>Requesting officer name<input name="requesting_officer" value="<?= e(current_user()['name'] ?? 'Officer') ?>" required></label>
            <label>Manual register reference
                <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:.4rem;margin-top:.25rem">
                    <input name="manual_register_name" value="Department Indent Register" placeholder="Register Name">
                    <input name="manual_register_page" placeholder="Page No.">
                    <input name="manual_register_entry" placeholder="Entry No.">
                </div>
            </label>
            <label>Upload requisition note / indent slip (optional)
                <input type="file" name="req_document" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
            </label>
        </div>
        <p class="eyebrow" style="margin:1rem 0 .25rem">ITEMS REQUIRED</p>
        <p class="muted" style="font-size:12px;margin:0 0 .75rem">💡 <b>Quick Entry:</b> Scan/type an <b>Item code</b> to auto-select, or pick directly from the <b>Item dropdown</b> (both stay synchronized).</p>
        <div id="req-items">
            <?php for ($i=0; $i<4; $i++): ?>
            <div class="line-item-row">
                <input class="item-barcode" name="item_code[]" placeholder="Scan / Code" autocomplete="off" style="width:130px" title="Scan barcode or type item code for quick auto-lookup">
                <select name="item_id[]"><option value="">— Select item —</option><?php foreach ($items_all as $it): ?><option value="<?= $it['id'] ?>" data-code="<?= e($it['code']) ?>" data-name="<?= e($it['name']) ?>" data-unit="<?= e($it['unit']) ?>"><?= e($it['code']) ?> — <?= e($it['name']) ?> (Store: <?= $it['quantity'] ?> <?= e($it['unit']) ?>)</option><?php endforeach; ?></select>
                <input name="required_qty[]" type="number" min="1" placeholder="Required" style="width:90px">
            </div>
            <?php endfor; ?>
        </div>
        <button class="button button-primary" type="submit">Submit digital requisition</button>
        </form>
    </div>
</div>

<!-- ═══════════════════ DEPARTMENT VIRTUAL STOCK OVERVIEW ═══════════════════ -->
<div class="panel" style="margin-top:1.5rem">
    <div class="panel-heading">
        <h2>Department virtual stock balances (Live)</h2>
        <span class="muted" style="font-size:.85rem">Real-time stock held across OPD, OT, Emergency, and Wards</span>
    </div>
    <div class="table-wrap"><table>
        <thead><tr><th>Department</th><th>Type</th><th>Item Code / Name</th><th>Batch No.</th><th>Expiry Date</th><th>Department Qty</th><th>Last Updated</th></tr></thead>
        <tbody>
        <?php
        $deptStocks = $pdo->query('
            SELECT ds.*, d.name AS dname, d.type AS dtype, i.code AS icode, i.name AS iname, i.unit
            FROM department_stock ds
            JOIN departments d ON d.id=ds.department_id
            JOIN items i ON i.id=ds.item_id
            WHERE ds.quantity > 0
            ORDER BY d.type, d.name, i.name
        ')->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <?php if (!$deptStocks): ?>
        <tr><td colspan="7" class="muted" style="text-align:center;padding:2rem">No stock currently held in department virtual locations. Stock will appear here when requisitions are issued.</td></tr>
        <?php else: foreach ($deptStocks as $ds): ?>
        <tr>
            <td><b><?= e($ds['dname']) ?></b></td>
            <td><?= pill($ds['dtype']) ?></td>
            <td><?= e($ds['icode']) ?> — <b><?= e($ds['iname']) ?></b></td>
            <td><code><?= e($ds['batch_number'] ?: '—') ?></code></td>
            <td><?= e($ds['expiry_date'] ?: '—') ?></td>
            <td><strong style="color:var(--brand-primary, #00897b);font-size:1.05rem"><?= e($ds['quantity']) ?></strong> <?= e($ds['unit']) ?></td>
            <td><small class="muted"><?= e(date('d M Y, h:i A', strtotime($ds['updated_at']))) ?></small></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table></div>
</div>

<?php /* ════════════════════ OPD CONSUMPTION ══════════════════════════════════ */ ?>
<?php elseif ($view === 'opd_consumption'): ?>
<?php
$todayDate = date('Y-m-d');
$opdRecords = $pdo->query('
    SELECT c.*, i.name AS iname, i.code AS icode, i.unit, d.name AS dname, u.name AS poster_name
    FROM consumption_records c
    LEFT JOIN items i ON i.id = c.item_id
    LEFT JOIN departments d ON d.id = c.department_id
    LEFT JOIN users u ON u.id = c.posted_by
    WHERE c.type = "OPD"
    ORDER BY c.id DESC
    LIMIT 60
')->fetchAll(PDO::FETCH_ASSOC);

$opdDeptStock = $pdo->query('
    SELECT ds.*, d.name AS dname, i.name AS iname, i.code AS icode, i.unit
    FROM department_stock ds
    JOIN departments d ON d.id = ds.department_id
    JOIN items i ON i.id = ds.item_id
    WHERE d.type = "OPD" AND ds.quantity > 0
    ORDER BY d.name, i.name
')->fetchAll(PDO::FETCH_ASSOC);

$todayPatients = (int)$pdo->query("SELECT COUNT(DISTINCT patient_mr) FROM consumption_records WHERE type='OPD' AND visit_date='$todayDate'")->fetchColumn();
$todayUnits = (int)$pdo->query("SELECT COALESCE(SUM(quantity),0) FROM consumption_records WHERE type='OPD' AND visit_date='$todayDate'")->fetchColumn();
$totalOpdHolding = (int)array_sum(array_column($opdDeptStock, 'quantity'));
?>

<div class="kpi-grid four" style="margin-bottom:1.5rem">
    <div class="kpi-card">
        <p class="kpi-label">Today's OPD Patients</p>
        <p class="kpi-val"><?= number_format($todayPatients) ?></p>
        <p class="kpi-sub">Served on <?= date('d M Y') ?></p>
    </div>
    <div class="kpi-card">
        <p class="kpi-label">Units Dispensed Today</p>
        <p class="kpi-val" style="color:var(--brand-primary, #00897b)"><?= number_format($todayUnits) ?></p>
        <p class="kpi-sub">Eye drops, pads & medicines</p>
    </div>
    <div class="kpi-card">
        <p class="kpi-label">OPD Sub-Store Holding</p>
        <p class="kpi-val"><?= number_format($totalOpdHolding) ?></p>
        <p class="kpi-sub">Available in OPD clinics</p>
    </div>
    <div class="kpi-card">
        <p class="kpi-label">Dispensing Mode</p>
        <p class="kpi-val" style="font-size:1.05rem;color:#166534">High-Volume Fast Entry</p>
        <p class="kpi-sub">Real-time sub-store deduction</p>
    </div>
</div>

<div class="system-grid">
    <div class="panel">
        <div class="panel-heading">
            <h2>OPD Patient Dispensing Register</h2>
            <a class="button button-primary" href="#add-opd">＋ Fast Dispense</a>
        </div>
        <div class="table-wrap"><table>
            <thead>
                <tr>
                    <th>Date / Time</th>
                    <th>Patient MR / Name</th>
                    <th>Clinic</th>
                    <th>Dispensed Consumable</th>
                    <th>Batch # / Qty</th>
                    <th>Manual Register Ref</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($opdRecords)): ?>
            <tr><td colspan="6" class="muted" style="text-align:center;padding:2.5rem">No OPD consumption records found for this period.</td></tr>
            <?php else: foreach ($opdRecords as $c): ?>
            <tr>
                <td>
                    <b><?= e(date('d M Y', strtotime($c['created_at']))) ?></b><br>
                    <small class="muted"><?= e(date('h:i A', strtotime($c['created_at']))) ?></small>
                </td>
                <td>
                    <b><?= e($c['patient_mr']) ?></b><br>
                    <span class="muted"><?= e($c['patient_name']) ?></span>
                </td>
                <td>
                    <?= pill($c['dname'] ?? 'General OPD') ?>
                </td>
                <td>
                    <b><?= e($c['icode']) ?></b> — <?= e($c['iname']) ?>
                </td>
                <td>
                    <code style="font-size:.78rem"><?= e($c['batch_number'] ?: 'FEFO') ?></code><br>
                    <strong style="color:var(--brand-primary, #00897b)"><?= e($c['quantity']) ?></strong> <small><?= e($c['unit'] ?? 'units') ?></small>
                </td>
                <td>
                    <?php if (!empty($c['manual_register_page']) || !empty($c['manual_register_entry'])): ?>
                        <small><b><?= e($c['manual_register_name'] ?: 'OPD Register') ?></b><br>
                        Pg: <b><?= e($c['manual_register_page'] ?: '—') ?></b> · Entry: <b><?= e($c['manual_register_entry'] ?: '—') ?></b></small>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>

    <div class="form-card" id="add-opd">
        <h2>Record OPD Dispensing</h2>
        <p class="muted" style="margin-bottom:1rem">Deducts inventory directly from the OPD clinic's virtual sub-store balance using FEFO allocation.</p>
        <form method="post">
            <input type="hidden" name="action" value="opd_consumption">
            <div class="form-grid one">
                <label>Patient MR No.
                    <input name="patient_mr" value="<?= e('MR-' . date('Ymd') . '-' . sprintf('%03d', rand(100, 999))) ?>" required>
                </label>
                <label>Patient Full Name
                    <input name="patient_name" value="Patient <?= sprintf('%03d', rand(100, 999)) ?>" required>
                </label>
                <label>OPD Clinic / Station
                    <select name="department_id" required>
                        <?php foreach (array_filter($depts_all, fn($d)=>$d['type']==='OPD') as $d): ?>
                        <option value="<?= $d['id'] ?>"><?= e($d['name']) ?> (<?= e($d['type']) ?>)</option>
                        <?php endforeach; ?>
                        <?php foreach (array_filter($depts_all, fn($d)=>$d['type']!=='OPD') as $d): ?>
                        <option value="<?= $d['id'] ?>"><?= e($d['name']) ?> (<?= e($d['type']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Visit Date
                    <input name="visit_date" type="date" value="<?= date('Y-m-d') ?>" required>
                </label>
            </div>

            <p class="eyebrow" style="margin:1.2rem 0 .4rem">SINDH GOVT. MANUAL REGISTER CROSS-REFERENCE</p>
            <div class="form-grid one" style="background:var(--surface-sunken, #f8fafc);padding:.75rem;border-radius:6px;border:1px dashed var(--border-color,#e2e8f0)">
                <label>Manual Register Name
                    <input name="manual_register_name" value="OPD Daily Dispensing Register">
                </label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                    <label>Page No.<input name="manual_register_page" placeholder="e.g. 112"></label>
                    <label>Entry / Sr No.<input name="manual_register_entry" placeholder="e.g. 45"></label>
                </div>
            </div>

            <p class="eyebrow" style="margin:1.2rem 0 .4rem">ITEMS DISPENSED (UP TO 4 LINES)</p>
            <div style="display:flex;flex-direction:column;gap:.5rem">
            <?php for ($i = 0; $i < 4; $i++): ?>
                <div style="background:var(--surface-sunken, #f8fafc);padding:.6rem;border-radius:6px;border:1px solid var(--border-color, #e2e8f0);display:grid;grid-template-columns:2.5fr 1.2fr 1fr;gap:.4rem;align-items:center">
                    <div>
                        <small class="muted" style="display:block;margin-bottom:.2rem">Item</small>
                        <select name="item_id[]" style="width:100%;font-size:.85rem">
                            <option value="">— Select item —</option>
                            <?php foreach ($items_all as $it): ?>
                            <option value="<?= $it['id'] ?>"><?= e($it['code']) ?> — <?= e($it['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <small class="muted" style="display:block;margin-bottom:.2rem">Batch (Leave blank for FEFO)</small>
                        <input name="batch[]" placeholder="Auto-FEFO" style="width:100%;font-size:.85rem">
                    </div>
                    <div>
                        <small class="muted" style="display:block;margin-bottom:.2rem">Qty</small>
                        <input name="quantity[]" type="number" min="1" placeholder="Qty" style="width:100%;font-size:.85rem">
                    </div>
                </div>
            <?php endfor; ?>
            </div>

            <button class="button button-primary" type="submit" style="margin-top:1.2rem;width:100%">Post OPD Dispensing</button>
        </form>
    </div>
</div>

<div class="panel" style="margin-top:1.5rem">
    <div class="panel-heading">
        <h2>OPD Clinics Virtual Stock Balances (Available for Dispensing)</h2>
    </div>
    <div class="table-wrap"><table>
        <thead>
            <tr>
                <th>Clinic / Department</th>
                <th>Item Code & Name</th>
                <th>Batch Number</th>
                <th>Expiry Date</th>
                <th>Available in Clinic</th>
                <th>Last Updated</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($opdDeptStock)): ?>
        <tr><td colspan="6" class="muted" style="text-align:center;padding:2rem">No stock currently held in OPD clinic sub-stores. Issue requisitions from Main Store to supply the clinics.</td></tr>
        <?php else: foreach ($opdDeptStock as $ds): ?>
        <tr>
            <td><b><?= e($ds['dname']) ?></b></td>
            <td><?= e($ds['icode']) ?> — <b><?= e($ds['iname']) ?></b></td>
            <td><code><?= e($ds['batch_number'] ?: '—') ?></code></td>
            <td><?= e($ds['expiry_date'] ?: '—') ?></td>
            <td><strong style="color:var(--brand-primary, #00897b);font-size:1.05rem"><?= e($ds['quantity']) ?></strong> <?= e($ds['unit']) ?></td>
            <td><small class="muted"><?= e(date('d M Y, h:i A', strtotime($ds['updated_at']))) ?></small></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table></div>
</div>

<?php /* ════════════════════ IPD CONSUMPTION ══════════════════════════════════ */ ?>
<?php elseif ($view === 'ipd_consumption'): ?>
<?php
$todayDate = date('Y-m-d');
$ipdRecords = $pdo->query('
    SELECT c.*, i.name AS iname, i.code AS icode, i.unit, d.name AS dname, u.name AS poster_name
    FROM consumption_records c
    LEFT JOIN items i ON i.id = c.item_id
    LEFT JOIN departments d ON d.id = c.department_id
    LEFT JOIN users u ON u.id = c.posted_by
    WHERE c.type = "IPD"
    ORDER BY c.id DESC
    LIMIT 60
')->fetchAll(PDO::FETCH_ASSOC);

$ipdDeptStock = $pdo->query('
    SELECT ds.*, d.name AS dname, i.name AS iname, i.code AS icode, i.unit
    FROM department_stock ds
    JOIN departments d ON d.id = ds.department_id
    JOIN items i ON i.id = ds.item_id
    WHERE d.type = "IPD" AND ds.quantity > 0
    ORDER BY d.name, i.name
')->fetchAll(PDO::FETCH_ASSOC);

$todayIpdCases = (int)$pdo->query("SELECT COUNT(DISTINCT patient_mr) FROM consumption_records WHERE type='IPD' AND date(created_at)='$todayDate'")->fetchColumn();
$todayIpdUnits = (int)$pdo->query("SELECT COALESCE(SUM(quantity),0) FROM consumption_records WHERE type='IPD' AND date(created_at)='$todayDate'")->fetchColumn();
$totalIpdHolding = (int)array_sum(array_column($ipdDeptStock, 'quantity'));
?>

<div class="kpi-grid four" style="margin-bottom:1.5rem">
    <div class="kpi-card">
        <p class="kpi-label">Active Ward Cases</p>
        <p class="kpi-val"><?= number_format($todayIpdCases) ?></p>
        <p class="kpi-sub">Admitted patients served today</p>
    </div>
    <div class="kpi-card">
        <p class="kpi-label">Ward Consumables Issued</p>
        <p class="kpi-val" style="color:var(--brand-primary, #00897b)"><?= number_format($todayIpdUnits) ?></p>
        <p class="kpi-sub">Units dispensed on <?= date('d M Y') ?></p>
    </div>
    <div class="kpi-card">
        <p class="kpi-label">Ward Sub-Store Balance</p>
        <p class="kpi-val"><?= number_format($totalIpdHolding) ?></p>
        <p class="kpi-sub">Units held across inpatient wards</p>
    </div>
    <div class="kpi-card">
        <p class="kpi-label">Traceability</p>
        <p class="kpi-val" style="font-size:1.05rem;color:#166534">Bed & Ward Tracked</p>
        <p class="kpi-sub">Sindh manual register cross-ref</p>
    </div>
</div>

<div class="system-grid">
    <div class="panel">
        <div class="panel-heading">
            <h2>IPD / Inpatient Ward Consumption Log</h2>
            <a class="button button-primary" href="#add-ipd">＋ New Ward Entry</a>
        </div>
        <div class="table-wrap"><table>
            <thead>
                <tr>
                    <th>Date / Time</th>
                    <th>Patient MR / Name</th>
                    <th>Ward & Bed</th>
                    <th>Consumable Used</th>
                    <th>Batch / Qty</th>
                    <th>Manual Register Ref</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($ipdRecords)): ?>
            <tr><td colspan="6" class="muted" style="text-align:center;padding:2.5rem">No IPD ward consumption records found.</td></tr>
            <?php else: foreach ($ipdRecords as $c): ?>
            <tr>
                <td>
                    <b><?= e(date('d M Y', strtotime($c['created_at']))) ?></b><br>
                    <small class="muted"><?= e(date('h:i A', strtotime($c['created_at']))) ?></small>
                </td>
                <td>
                    <b><?= e($c['patient_mr']) ?></b><br>
                    <span class="muted"><?= e($c['patient_name']) ?></span>
                </td>
                <td>
                    <b><?= e($c['ward'] ?: 'Ward 1') ?></b><br>
                    <small class="muted"><?= e($c['bed'] ?: 'General Bed') ?></small>
                </td>
                <td>
                    <b><?= e($c['icode']) ?></b> — <?= e($c['iname']) ?>
                </td>
                <td>
                    <code style="font-size:.78rem"><?= e($c['batch_number'] ?: 'FEFO') ?></code><br>
                    <strong style="color:var(--brand-primary, #00897b)"><?= e($c['quantity']) ?></strong> <small><?= e($c['unit'] ?? 'units') ?></small>
                </td>
                <td>
                    <?php if (!empty($c['manual_register_page']) || !empty($c['manual_register_entry'])): ?>
                        <small><b><?= e($c['manual_register_name'] ?: 'Ward Register') ?></b><br>
                        Pg: <b><?= e($c['manual_register_page'] ?: '—') ?></b> · Entry: <b><?= e($c['manual_register_entry'] ?: '—') ?></b></small>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>

    <div class="form-card" id="add-ipd">
        <h2>Record Ward Consumption</h2>
        <p class="muted" style="margin-bottom:1rem">Deducts stock from the ward's virtual store holding and links usage directly to the admitted patient.</p>
        <form method="post">
            <input type="hidden" name="action" value="ipd_consumption">
            <div class="form-grid one">
                <label>Patient MR No.
                    <input name="patient_mr" value="<?= e('MR-' . date('Ymd') . '-' . sprintf('%03d', rand(100, 999))) ?>" required>
                </label>
                <label>Patient Full Name
                    <input name="patient_name" value="IPD Patient <?= sprintf('%03d', rand(100, 999)) ?>" required>
                </label>
                <label>Department / Inpatient Unit
                    <select name="department_id" required>
                        <?php foreach (array_filter($depts_all, fn($d)=>$d['type']==='IPD') as $d): ?>
                        <option value="<?= $d['id'] ?>"><?= e($d['name']) ?> (<?= e($d['type']) ?>)</option>
                        <?php endforeach; ?>
                        <?php foreach (array_filter($depts_all, fn($d)=>$d['type']!=='IPD') as $d): ?>
                        <option value="<?= $d['id'] ?>"><?= e($d['name']) ?> (<?= e($d['type']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                    <label>Ward Name<input name="ward" value="Female Eye Ward 1" required></label>
                    <label>Bed Number<input name="bed" value="Bed-<?= rand(1, 25) ?>" required></label>
                </div>
                <label>Admission Date
                    <input name="admission_date" type="date" value="<?= date('Y-m-d') ?>" required>
                </label>
            </div>

            <p class="eyebrow" style="margin:1.2rem 0 .4rem">SINDH GOVT. MANUAL REGISTER CROSS-REFERENCE</p>
            <div class="form-grid one" style="background:var(--surface-sunken, #f8fafc);padding:.75rem;border-radius:6px;border:1px dashed var(--border-color,#e2e8f0)">
                <label>Manual Register Name
                    <input name="manual_register_name" value="IPD Ward Stock Register">
                </label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                    <label>Page No.<input name="manual_register_page" placeholder="e.g. 58"></label>
                    <label>Entry / Sr No.<input name="manual_register_entry" placeholder="e.g. 14"></label>
                </div>
            </div>

            <p class="eyebrow" style="margin:1.2rem 0 .4rem">CONSUMABLES / MEDICINES USED (UP TO 4 LINES)</p>
            <div style="display:flex;flex-direction:column;gap:.5rem">
            <?php for ($i = 0; $i < 4; $i++): ?>
                <div style="background:var(--surface-sunken, #f8fafc);padding:.6rem;border-radius:6px;border:1px solid var(--border-color, #e2e8f0);display:grid;grid-template-columns:2.5fr 1.2fr 1fr;gap:.4rem;align-items:center">
                    <div>
                        <small class="muted" style="display:block;margin-bottom:.2rem">Item</small>
                        <select name="item_id[]" style="width:100%;font-size:.85rem">
                            <option value="">— Select item —</option>
                            <?php foreach ($items_all as $it): ?>
                            <option value="<?= $it['id'] ?>"><?= e($it['code']) ?> — <?= e($it['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <small class="muted" style="display:block;margin-bottom:.2rem">Batch</small>
                        <input name="batch[]" placeholder="Auto-FEFO" style="width:100%;font-size:.85rem">
                    </div>
                    <div>
                        <small class="muted" style="display:block;margin-bottom:.2rem">Qty</small>
                        <input name="quantity[]" type="number" min="1" placeholder="Qty" style="width:100%;font-size:.85rem">
                    </div>
                </div>
            <?php endfor; ?>
            </div>

            <button class="button button-primary" type="submit" style="margin-top:1.2rem;width:100%">Post IPD Ward Consumption</button>
        </form>
    </div>
</div>

<div class="panel" style="margin-top:1.5rem">
    <div class="panel-heading">
        <h2>Inpatient Ward Virtual Stock Balances</h2>
    </div>
    <div class="table-wrap"><table>
        <thead>
            <tr>
                <th>Ward / Department</th>
                <th>Item Code & Name</th>
                <th>Batch Number</th>
                <th>Expiry Date</th>
                <th>Available in Ward</th>
                <th>Last Updated</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($ipdDeptStock)): ?>
        <tr><td colspan="6" class="muted" style="text-align:center;padding:2rem">No stock currently held in IPD ward sub-stores. Issue requisitions from Main Store to supply the wards.</td></tr>
        <?php else: foreach ($ipdDeptStock as $ds): ?>
        <tr>
            <td><b><?= e($ds['dname']) ?></b></td>
            <td><?= e($ds['icode']) ?> — <b><?= e($ds['iname']) ?></b></td>
            <td><code><?= e($ds['batch_number'] ?: '—') ?></code></td>
            <td><?= e($ds['expiry_date'] ?: '—') ?></td>
            <td><strong style="color:var(--brand-primary, #00897b);font-size:1.05rem"><?= e($ds['quantity']) ?></strong> <?= e($ds['unit']) ?></td>
            <td><small class="muted"><?= e(date('d M Y, h:i A', strtotime($ds['updated_at']))) ?></small></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table></div>
</div>

<?php /* ════════════════════ OT CONSUMPTION ═══════════════════════════════════ */ ?>
<?php elseif ($view === 'ot_consumption'): ?>
<?php
$todayDate = date('Y-m-d');
$otRecords = $pdo->query('
    SELECT c.*, i.name AS iname, i.code AS icode, i.unit, d.name AS dname, u.name AS poster_name
    FROM consumption_records c
    LEFT JOIN items i ON i.id = c.item_id
    LEFT JOIN departments d ON d.id = c.department_id
    LEFT JOIN users u ON u.id = c.posted_by
    WHERE c.type = "OT"
    ORDER BY c.id DESC
    LIMIT 60
')->fetchAll(PDO::FETCH_ASSOC);

$otDeptStock = $pdo->query('
    SELECT ds.*, d.name AS dname, i.name AS iname, i.code AS icode, i.unit
    FROM department_stock ds
    JOIN departments d ON d.id = ds.department_id
    JOIN items i ON i.id = ds.item_id
    WHERE d.type = "OT" AND ds.quantity > 0
    ORDER BY i.name
')->fetchAll(PDO::FETCH_ASSOC);

$templates = $pdo->query('
    SELECT pt.id, pt.procedure_name, pt.description
    FROM procedure_templates pt
    WHERE pt.status = "Active"
    ORDER BY pt.id ASC
')->fetchAll(PDO::FETCH_ASSOC);

$templateItems = [];
$tiRows = $pdo->query('
    SELECT pti.template_id, pti.item_id, pti.default_qty, i.code, i.name, i.unit
    FROM procedure_template_items pti
    JOIN items i ON i.id = pti.item_id
    ORDER BY pti.id ASC
')->fetchAll(PDO::FETCH_ASSOC);
foreach ($tiRows as $tir) {
    $templateItems[$tir['template_id']][] = $tir;
}

$tplJson = [];
foreach ($templates as $t) {
    $tItems = $templateItems[$t['id']] ?? [];
    $tplJson[$t['id']] = [
        'name' => $t['procedure_name'],
        'items' => array_map(fn($ti) => [
            'item_id' => (int)$ti['item_id'],
            'code' => $ti['code'],
            'name' => $ti['name'],
            'qty' => (int)$ti['default_qty'],
        ], $tItems)
    ];
}

$todaySurgeries = (int)$pdo->query("SELECT COUNT(DISTINCT surgery_number) FROM consumption_records WHERE type='OT' AND date(created_at)='$todayDate'")->fetchColumn();
$todayOtUnits = (int)$pdo->query("SELECT COALESCE(SUM(quantity),0) FROM consumption_records WHERE type='OT' AND date(created_at)='$todayDate'")->fetchColumn();
$totalOtHolding = (int)array_sum(array_column($otDeptStock, 'quantity'));
?>

<div class="kpi-grid four" style="margin-bottom:1.5rem">
    <div class="kpi-card">
        <p class="kpi-label">Surgeries Logged Today</p>
        <p class="kpi-val"><?= number_format($todaySurgeries) ?></p>
        <p class="kpi-sub">30+ daily capacity</p>
    </div>
    <div class="kpi-card">
        <p class="kpi-label">IOLs & Consumables Used</p>
        <p class="kpi-val" style="color:var(--brand-primary, #00897b)"><?= number_format($todayOtUnits) ?></p>
        <p class="kpi-sub">Lenses, visco, sutures & blades</p>
    </div>
    <div class="kpi-card">
        <p class="kpi-label">OT Sub-Store Holding</p>
        <p class="kpi-val"><?= number_format($totalOtHolding) ?></p>
        <p class="kpi-sub">Units held in Operating Theatres</p>
    </div>
    <div class="kpi-card">
        <p class="kpi-label">1-Click Presets</p>
        <p class="kpi-val" style="font-size:1.05rem;color:#166534"><?= count($templates) ?> Surgery Packs</p>
        <p class="kpi-sub">Phaco, SICS, PPV, Glaucoma</p>
    </div>
</div>

<div class="system-grid">
    <div class="panel">
        <div class="panel-heading">
            <h2>OT / Surgery Consumption Log</h2>
            <a class="button button-primary" href="#add-ot">＋ Post Surgery Usage</a>
        </div>
        <div class="table-wrap"><table>
            <thead>
                <tr>
                    <th>Date / Surgery No.</th>
                    <th>Patient MR / Name</th>
                    <th>Surgeon / Procedure</th>
                    <th>Items & Batches Used</th>
                    <th>Manual Register Ref</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($otRecords)): ?>
            <tr><td colspan="5" class="muted" style="text-align:center;padding:2.5rem">No surgery consumption records found.</td></tr>
            <?php else: foreach ($otRecords as $c): ?>
            <tr>
                <td>
                    <b><?= e($c['surgery_number']) ?></b><br>
                    <small class="muted"><?= e(date('d M Y, h:i A', strtotime($c['created_at']))) ?></small>
                </td>
                <td>
                    <b><?= e($c['patient_mr']) ?></b><br>
                    <span class="muted"><?= e($c['patient_name']) ?></span>
                </td>
                <td>
                    <b><?= e($c['procedure_name']) ?></b><br>
                    <small class="muted">Surgeon: <?= e($c['surgeon']) ?></small>
                </td>
                <td>
                    <b><?= e($c['icode']) ?></b> — <?= e($c['iname']) ?><br>
                    <code style="font-size:.78rem"><?= e($c['batch_number'] ?: 'FEFO') ?></code> ×
                    <strong style="color:var(--brand-primary, #00897b)"><?= e($c['quantity']) ?></strong> <small><?= e($c['unit'] ?? 'units') ?></small>
                </td>
                <td>
                    <?php if (!empty($c['manual_register_page']) || !empty($c['manual_register_entry'])): ?>
                        <small><b><?= e($c['manual_register_name'] ?: 'OT Register') ?></b><br>
                        Pg: <b><?= e($c['manual_register_page'] ?: '—') ?></b> · Entry: <b><?= e($c['manual_register_entry'] ?: '—') ?></b></small>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>

    <div class="form-card" id="add-ot">
        <h2>Post Surgery Consumables</h2>
        <p class="muted" style="margin-bottom:1rem">1-Click packs populate all standard surgical consumables. Deductions apply directly to OT virtual sub-store balance.</p>

        <div style="background:var(--surface-sunken, #f8fafc);padding:.75rem;border-radius:6px;border:1px solid var(--border-color,#e2e8f0);margin-bottom:1rem">
            <small style="font-weight:600;display:block;margin-bottom:.4rem;color:#0f766e">⚡ 1-CLICK PROCEDURE PACK PRESETS:</small>
            <div style="display:flex;flex-wrap:wrap;gap:.4rem">
                <?php foreach ($templates as $tpl): ?>
                <button type="button" class="button button-light" style="padding:.25rem .6rem;font-size:.78rem;font-weight:600;background:#ffffff" onclick="loadProcedurePack(<?= $tpl['id'] ?>)">
                    📦 <?= e($tpl['procedure_name']) ?>
                </button>
                <?php endforeach; ?>
            </div>
        </div>

        <form method="post">
            <input type="hidden" name="action" value="ot_consumption">
            <div class="form-grid one">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                    <label>Surgery No.<input name="surgery_number" value="<?= e(next_number($pdo, 'OT', 'consumption_records', 'surgery_number')) ?>" required></label>
                    <label>Patient MR No.<input name="patient_mr" value="<?= e('MR-' . date('Ymd') . '-' . sprintf('%03d', rand(100, 999))) ?>" required></label>
                </div>
                <label>Patient Full Name
                    <input name="patient_name" value="Surgical Patient <?= sprintf('%03d', rand(100, 999)) ?>" required>
                </label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                    <label>Surgeon
                        <input name="surgeon" value="Dr. Asad Rizvi" required>
                    </label>
                    <label>Procedure / Surgery
                        <input id="ot_procedure_name" name="procedure_name" value="Phacoemulsification with IOL" required>
                    </label>
                </div>
                <label>Operating Theatre Department
                    <select name="department_id" required>
                        <?php foreach (array_filter($depts_all, fn($d)=>$d['type']==='OT') as $d): ?>
                        <option value="<?= $d['id'] ?>"><?= e($d['name']) ?> (<?= e($d['type']) ?>)</option>
                        <?php endforeach; ?>
                        <?php foreach (array_filter($depts_all, fn($d)=>$d['type']!=='OT') as $d): ?>
                        <option value="<?= $d['id'] ?>"><?= e($d['name']) ?> (<?= e($d['type']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <p class="eyebrow" style="margin:1.2rem 0 .4rem">SINDH GOVT. MANUAL REGISTER CROSS-REFERENCE</p>
            <div class="form-grid one" style="background:var(--surface-sunken, #f8fafc);padding:.75rem;border-radius:6px;border:1px dashed var(--border-color,#e2e8f0)">
                <label>Manual Register Name
                    <input name="manual_register_name" value="OT Surgery Consumption Register">
                </label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                    <label>Page No.<input name="manual_register_page" placeholder="e.g. 91"></label>
                    <label>Entry / Sr No.<input name="manual_register_entry" placeholder="e.g. 27"></label>
                </div>
            </div>

            <p class="eyebrow" style="margin:1.2rem 0 .4rem">SURGERY CONSUMABLES USED (UP TO 6 LINES)</p>
            <div style="display:flex;flex-direction:column;gap:.5rem">
            <?php for ($i = 0; $i < 6; $i++): ?>
                <div style="background:var(--surface-sunken, #f8fafc);padding:.6rem;border-radius:6px;border:1px solid var(--border-color, #e2e8f0);display:grid;grid-template-columns:2.5fr 1.2fr 1fr;gap:.4rem;align-items:center">
                    <div>
                        <small class="muted" style="display:block;margin-bottom:.2rem">Item</small>
                        <select name="item_id[]" class="ot-item-select" style="width:100%;font-size:.85rem">
                            <option value="">— Select item —</option>
                            <?php foreach ($items_all as $it): ?>
                            <option value="<?= $it['id'] ?>"><?= e($it['code']) ?> — <?= e($it['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <small class="muted" style="display:block;margin-bottom:.2rem">Batch (or blank for FEFO)</small>
                        <input name="batch[]" placeholder="Auto-FEFO" style="width:100%;font-size:.85rem">
                    </div>
                    <div>
                        <small class="muted" style="display:block;margin-bottom:.2rem">Qty</small>
                        <input name="quantity[]" class="ot-qty-input" type="number" min="1" placeholder="Qty" style="width:100%;font-size:.85rem">
                    </div>
                </div>
            <?php endfor; ?>
            </div>

            <button class="button button-primary" type="submit" style="margin-top:1.2rem;width:100%">Post OT Surgery Consumption</button>
        </form>
    </div>
</div>

<script>
const SIOVS_OT_PACKS = <?= json_encode($tplJson, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
function loadProcedurePack(templateId) {
    if (!templateId || !SIOVS_OT_PACKS[templateId]) return;
    const pack = SIOVS_OT_PACKS[templateId];
    const procInput = document.getElementById('ot_procedure_name');
    if (procInput) procInput.value = pack.name;
    const itemSelects = document.querySelectorAll('.ot-item-select');
    const qtyInputs = document.querySelectorAll('.ot-qty-input');
    itemSelects.forEach((sel, idx) => {
        sel.value = '';
        if (qtyInputs[idx]) qtyInputs[idx].value = '';
    });
    pack.items.forEach((item, idx) => {
        if (itemSelects[idx]) {
            itemSelects[idx].value = item.item_id;
            if (qtyInputs[idx]) qtyInputs[idx].value = item.qty;
        }
    });
}
</script>

<div class="panel" style="margin-top:1.5rem">
    <div class="panel-heading">
        <h2>Operating Theatre Virtual Stock Balances</h2>
    </div>
    <div class="table-wrap"><table>
        <thead>
            <tr>
                <th>OT Unit / Department</th>
                <th>Item Code & Name</th>
                <th>Batch Number</th>
                <th>Expiry Date</th>
                <th>Available in OT</th>
                <th>Last Updated</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($otDeptStock)): ?>
        <tr><td colspan="6" class="muted" style="text-align:center;padding:2rem">No stock currently held in OT sub-stores. Issue requisitions from Main Store to supply the theatres.</td></tr>
        <?php else: foreach ($otDeptStock as $ds): ?>
        <tr>
            <td><b><?= e($ds['dname']) ?></b></td>
            <td><?= e($ds['icode']) ?> — <b><?= e($ds['iname']) ?></b></td>
            <td><code><?= e($ds['batch_number'] ?: '—') ?></code></td>
            <td><?= e($ds['expiry_date'] ?: '—') ?></td>
            <td><strong style="color:var(--brand-primary, #00897b);font-size:1.05rem"><?= e($ds['quantity']) ?></strong> <?= e($ds['unit']) ?></td>
            <td><small class="muted"><?= e(date('d M Y, h:i A', strtotime($ds['updated_at']))) ?></small></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table></div>
</div>

<?php /* ════════════════════ EMERGENCY ═════════════════════════════════════ */ ?>
<?php elseif ($view === 'emergency'): ?>
<?php
$filterStatus = $_GET['status_filter'] ?? 'all';
$sql = '
    SELECT c.*, i.name AS iname, i.code AS icode, i.unit, d.name AS dname, u.name AS poster_name, ru.name AS regularizer_name
    FROM consumption_records c
    LEFT JOIN items i ON i.id = c.item_id
    LEFT JOIN departments d ON d.id = c.department_id
    LEFT JOIN users u ON u.id = c.posted_by
    LEFT JOIN users ru ON ru.id = c.regularized_by
    WHERE c.type = "Emergency"
';
if ($filterStatus === 'unregularized') {
    $sql .= ' AND c.status = "Unregularized"';
} elseif ($filterStatus === 'regularized') {
    $sql .= ' AND c.status = "Regularized"';
}
$sql .= ' ORDER BY c.id DESC LIMIT 60';
$emgRecords = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$unregCount = (int)$pdo->query('SELECT COUNT(*) FROM consumption_records WHERE type="Emergency" AND status="Unregularized"')->fetchColumn();
$regCount   = (int)$pdo->query('SELECT COUNT(*) FROM consumption_records WHERE type="Emergency" AND status="Regularized"')->fetchColumn();
$totalEmg   = (int)$pdo->query('SELECT COUNT(*) FROM consumption_records WHERE type="Emergency"')->fetchColumn();

$emgDeptStock = $pdo->query('
    SELECT ds.*, d.name AS dname, i.name AS iname, i.code AS icode, i.unit
    FROM department_stock ds
    JOIN departments d ON d.id = ds.department_id
    JOIN items i ON i.id = ds.item_id
    WHERE d.type = "Emergency" AND ds.quantity > 0
    ORDER BY i.name
')->fetchAll(PDO::FETCH_ASSOC);
$totalEmgHolding = (int)array_sum(array_column($emgDeptStock, 'quantity'));
?>

<div class="kpi-grid four" style="margin-bottom:1.5rem">
    <div class="kpi-card">
        <p class="kpi-label">Total Emergency Cases</p>
        <p class="kpi-val"><?= number_format($totalEmg) ?></p>
        <p class="kpi-sub">Rapid eye trauma/emergencies</p>
    </div>
    <div class="kpi-card" style="border-left:4px solid #f59e0b">
        <p class="kpi-label">Unregularized Cases</p>
        <p class="kpi-val" style="color:#b45309"><?= number_format($unregCount) ?></p>
        <p class="kpi-sub">Awaiting hospital MR & manual sync</p>
    </div>
    <div class="kpi-card">
        <p class="kpi-label">Regularized Cases</p>
        <p class="kpi-val" style="color:var(--brand-primary, #00897b)"><?= number_format($regCount) ?></p>
        <p class="kpi-sub">Fully audited & cross-referenced</p>
    </div>
    <div class="kpi-card">
        <p class="kpi-label">Emergency Sub-Store Holding</p>
        <p class="kpi-val"><?= number_format($totalEmgHolding) ?></p>
        <p class="kpi-sub">Available in ER room</p>
    </div>
</div>

<div class="system-grid">
    <div class="panel">
        <div class="panel-heading" style="flex-wrap:wrap;gap:.5rem">
            <h2>Emergency Rapid Consumption Log</h2>
            <div style="display:flex;gap:.3rem;align-items:center">
                <a class="button <?= $filterStatus==='all'?'button-primary':'button-light' ?>" href="<?= page_link('emergency') ?>&status_filter=all" style="padding:.2rem .6rem;font-size:.78rem">All (<?= $totalEmg ?>)</a>
                <a class="button <?= $filterStatus==='unregularized'?'button-primary':'button-light' ?>" href="<?= page_link('emergency') ?>&status_filter=unregularized" style="padding:.2rem .6rem;font-size:.78rem">⚠️ Unregularized (<?= $unregCount ?>)</a>
                <a class="button <?= $filterStatus==='regularized'?'button-primary':'button-light' ?>" href="<?= page_link('emergency') ?>&status_filter=regularized" style="padding:.2rem .6rem;font-size:.78rem">✓ Regularized (<?= $regCount ?>)</a>
                <a class="button button-primary" href="#add-emg" style="padding:.2rem .6rem;font-size:.78rem">＋ Rapid Entry</a>
            </div>
        </div>
        <div class="table-wrap"><table>
            <thead>
                <tr>
                    <th>Date / Time</th>
                    <th>Emergency Token / Patient</th>
                    <th>Consumable Used</th>
                    <th>Batch / Qty</th>
                    <th>Status</th>
                    <th>Audit & Regularization</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($emgRecords)): ?>
            <tr><td colspan="6" class="muted" style="text-align:center;padding:2.5rem">No emergency consumption records found matching this filter.</td></tr>
            <?php else: foreach ($emgRecords as $c): ?>
            <tr>
                <td>
                    <b><?= e(date('d M Y', strtotime($c['created_at']))) ?></b><br>
                    <small class="muted"><?= e(date('h:i A', strtotime($c['created_at']))) ?></small>
                </td>
                <td>
                    <b><?= e($c['patient_mr']) ?></b><br>
                    <span class="muted"><?= e($c['patient_name']) ?></span>
                </td>
                <td>
                    <b><?= e($c['icode']) ?></b> — <?= e($c['iname']) ?>
                </td>
                <td>
                    <code style="font-size:.78rem"><?= e($c['batch_number'] ?: 'FEFO') ?></code><br>
                    <strong style="color:var(--brand-primary, #00897b)"><?= e($c['quantity']) ?></strong> <small><?= e($c['unit'] ?? 'units') ?></small>
                </td>
                <td>
                    <?= pill($c['status']) ?>
                </td>
                <td>
                    <?php if ($c['status'] === 'Unregularized'): ?>
                        <details style="font-size:.8rem">
                            <summary style="cursor:pointer;color:#b45309;font-weight:600">⚡ Regularize Now</summary>
                            <form method="post" style="margin-top:.4rem;background:#fef3c7;padding:.5rem;border-radius:4px;border:1px solid #fde68a">
                                <input type="hidden" name="action" value="regularize_emergency">
                                <input type="hidden" name="record_id" value="<?= $c['id'] ?>">
                                <div style="display:flex;flex-direction:column;gap:.3rem">
                                    <input name="official_mr" placeholder="Official MR No." value="<?= str_starts_with($c['patient_mr'], 'EMG-') ? '' : e($c['patient_mr']) ?>" required style="font-size:.78rem;padding:.2rem .4rem">
                                    <input name="patient_name" placeholder="Patient Name" value="<?= e($c['patient_name']) ?>" style="font-size:.78rem;padding:.2rem .4rem">
                                    <div style="display:flex;gap:.3rem">
                                        <input name="manual_register_page" placeholder="Reg. Page #" style="width:50%;font-size:.78rem;padding:.2rem .4rem">
                                        <input name="manual_register_entry" placeholder="Entry #" style="width:50%;font-size:.78rem;padding:.2rem .4rem">
                                    </div>
                                    <button class="button button-primary" type="submit" style="font-size:.75rem;padding:.2rem .4rem">Confirm Regularization</button>
                                </div>
                            </form>
                        </details>
                    <?php else: ?>
                        <small><b><?= e($c['manual_register_name'] ?: 'Emergency Reg.') ?></b><br>
                        Pg: <b><?= e($c['manual_register_page'] ?: '—') ?></b> · Ent: <b><?= e($c['manual_register_entry'] ?: '—') ?></b><br>
                        <span class="muted">By: <?= e($c['regularizer_name'] ?? 'Officer') ?></span></small>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>

    <div class="form-card" id="add-emg">
        <h2>Emergency Rapid Entry</h2>
        <p class="muted" style="margin-bottom:1rem">Post material usage immediately without delaying emergency trauma care. Regularize with official MR paperwork after stabilization.</p>
        <form method="post">
            <input type="hidden" name="action" value="emergency">
            <div class="form-grid one">
                <label>Emergency Case / Token No.
                    <input name="patient_mr" value="<?= e('EMG-' . date('Ymd') . '-' . sprintf('%03d', rand(100, 999))) ?>" required>
                </label>
                <label>Patient Name / Trauma Tag
                    <input name="patient_name" value="Emergency Case <?= sprintf('%03d', rand(100, 999)) ?>" required>
                </label>
                <label>Emergency Department
                    <select name="department_id" required>
                        <?php foreach (array_filter($depts_all, fn($d)=>$d['type']==='Emergency') as $d): ?>
                        <option value="<?= $d['id'] ?>"><?= e($d['name']) ?> (<?= e($d['type']) ?>)</option>
                        <?php endforeach; ?>
                        <?php foreach (array_filter($depts_all, fn($d)=>$d['type']!=='Emergency') as $d): ?>
                        <option value="<?= $d['id'] ?>"><?= e($d['name']) ?> (<?= e($d['type']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <p class="eyebrow" style="margin:1.2rem 0 .4rem">SINDH GOVT. MANUAL REGISTER (OPTIONAL AT DISPENSING)</p>
            <div class="form-grid one" style="background:var(--surface-sunken, #f8fafc);padding:.75rem;border-radius:6px;border:1px dashed var(--border-color,#e2e8f0)">
                <label>Manual Register Name
                    <input name="manual_register_name" value="Emergency Rapid Dispensing Register">
                </label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                    <label>Page No.<input name="manual_register_page" placeholder="Optional now"></label>
                    <label>Entry / Sr No.<input name="manual_register_entry" placeholder="Optional now"></label>
                </div>
            </div>

            <p class="eyebrow" style="margin:1.2rem 0 .4rem">ITEMS DISPENSED (RAPID ENTRY)</p>
            <div style="display:flex;flex-direction:column;gap:.5rem">
            <?php for ($i = 0; $i < 4; $i++): ?>
                <div style="background:var(--surface-sunken, #f8fafc);padding:.6rem;border-radius:6px;border:1px solid var(--border-color, #e2e8f0);display:grid;grid-template-columns:2.5fr 1.2fr 1fr;gap:.4rem;align-items:center">
                    <div>
                        <small class="muted" style="display:block;margin-bottom:.2rem">Item</small>
                        <select name="item_id[]" style="width:100%;font-size:.85rem">
                            <option value="">— Select item —</option>
                            <?php foreach ($items_all as $it): ?>
                            <option value="<?= $it['id'] ?>"><?= e($it['code']) ?> — <?= e($it['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <small class="muted" style="display:block;margin-bottom:.2rem">Batch</small>
                        <input name="batch[]" placeholder="Auto-FEFO" style="width:100%;font-size:.85rem">
                    </div>
                    <div>
                        <small class="muted" style="display:block;margin-bottom:.2rem">Qty</small>
                        <input name="quantity[]" type="number" min="1" placeholder="Qty" style="width:100%;font-size:.85rem">
                    </div>
                </div>
            <?php endfor; ?>
            </div>

            <button class="button button-primary" type="submit" style="margin-top:1.2rem;width:100%;background:#d97706;border-color:#b45309">⚡ Dispense Immediately (Record Unregularized)</button>
        </form>
    </div>
</div>

<div class="panel" style="margin-top:1.5rem">
    <div class="panel-heading">
        <h2>Emergency Room Virtual Stock Balances</h2>
    </div>
    <div class="table-wrap"><table>
        <thead>
            <tr>
                <th>Emergency Unit</th>
                <th>Item Code & Name</th>
                <th>Batch Number</th>
                <th>Expiry Date</th>
                <th>Available in ER</th>
                <th>Last Updated</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($emgDeptStock)): ?>
        <tr><td colspan="6" class="muted" style="text-align:center;padding:2rem">No stock currently held in Emergency sub-stores. Issue requisitions from Main Store to supply the emergency room.</td></tr>
        <?php else: foreach ($emgDeptStock as $ds): ?>
        <tr>
            <td><b><?= e($ds['dname']) ?></b></td>
            <td><?= e($ds['icode']) ?> — <b><?= e($ds['iname']) ?></b></td>
            <td><code><?= e($ds['batch_number'] ?: '—') ?></code></td>
            <td><?= e($ds['expiry_date'] ?: '—') ?></td>
            <td><strong style="color:var(--brand-primary, #00897b);font-size:1.05rem"><?= e($ds['quantity']) ?></strong> <?= e($ds['unit']) ?></td>
            <td><small class="muted"><?= e(date('d M Y, h:i A', strtotime($ds['updated_at']))) ?></small></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table></div>
</div>

<?php /* ════════════════════ ASSETS & DEAD STOCK (PHASE 5) ═════════════════ */ ?>
<?php elseif ($view === 'assets'): ?>
<?php
$subview = $_GET['subview'] ?? 'all';
$selectedAssetId = !empty($_GET['asset_id']) ? (int)$_GET['asset_id'] : null;

// Query KPIs
$totalAssets = (int)$pdo->query('SELECT COUNT(*) FROM assets')->fetchColumn();
$totalValue  = (float)$pdo->query('SELECT COALESCE(SUM(cost),0) FROM assets')->fetchColumn();
$activeCount = (int)$pdo->query('SELECT COUNT(*) FROM assets WHERE status = "In service"')->fetchColumn();
$alertCount  = (int)$pdo->query('SELECT COUNT(*) FROM assets WHERE status IN ("AMC due soon", "Under repair")')->fetchColumn();
$deadCount   = (int)$pdo->query('SELECT COUNT(*) FROM assets WHERE status IN ("Condemned", "Disposed")')->fetchColumn();

// Asset options for dropdowns
$assetsAll = $pdo->query('SELECT id, tag, name, department, location, custodian, status FROM assets ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

// Subview 1: Fixed Asset Master
$filterDept = $_GET['dept'] ?? 'all';
$filterStatus = $_GET['status'] ?? 'all';
$filterCat = $_GET['category'] ?? 'all';

$where = [];
$params = [];
if ($filterDept !== 'all') { $where[] = 'department = ?'; $params[] = $filterDept; }
if ($filterStatus !== 'all') { $where[] = 'status = ?'; $params[] = $filterStatus; }
if ($filterCat !== 'all') { $where[] = 'category = ?'; $params[] = $filterCat; }

$sql = 'SELECT a.*, (SELECT COUNT(*) FROM asset_maintenance WHERE asset_id = a.id) AS mnt_count FROM assets a';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY a.id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$assetRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Subview 2: Transfers
$transferRows = $pdo->query('
    SELECT t.*, a.tag AS asset_tag, a.name AS asset_name, u.name AS creator_name
    FROM asset_transfers t
    JOIN assets a ON a.id = t.asset_id
    LEFT JOIN users u ON u.id = t.created_by
    ORDER BY t.id DESC
')->fetchAll(PDO::FETCH_ASSOC);

// Subview 3: Maintenance
$maintRows = $pdo->query('
    SELECT m.*, a.tag AS asset_tag, a.name AS asset_name, u.name AS creator_name
    FROM asset_maintenance m
    JOIN assets a ON a.id = m.asset_id
    LEFT JOIN users u ON u.id = m.created_by
    ORDER BY m.id DESC
')->fetchAll(PDO::FETCH_ASSOC);

// Subview 4: Dead Stock & Condemnations
$condemnRows = $pdo->query('
    SELECT c.*, a.cost AS original_cost, u.name AS creator_name
    FROM condemnations c
    LEFT JOIN assets a ON a.id = c.asset_id
    LEFT JOIN users u ON u.id = c.created_by
    ORDER BY c.id DESC
')->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="kpi-grid four" style="margin-bottom:1.5rem">
    <div class="kpi-card">
        <p class="kpi-label">Total Fixed Assets</p>
        <p class="kpi-val"><?= number_format($totalAssets) ?></p>
        <p class="kpi-sub">Rs. <?= number_format($totalValue, 2) ?> book value</p>
    </div>
    <div class="kpi-card" style="border-left:4px solid #10b981">
        <p class="kpi-label">Active / In Service</p>
        <p class="kpi-val" style="color:#047857"><?= number_format($activeCount) ?></p>
        <p class="kpi-sub">Fully operational in clinical units</p>
    </div>
    <div class="kpi-card" style="border-left:4px solid #f59e0b">
        <p class="kpi-label">Maintenance &amp; AMC Alerts</p>
        <p class="kpi-val" style="color:#b45309"><?= number_format($alertCount) ?></p>
        <p class="kpi-sub">Under repair or AMC due soon</p>
    </div>
    <div class="kpi-card" style="border-left:4px solid #64748b">
        <p class="kpi-label">Dead Stock / Condemned</p>
        <p class="kpi-val" style="color:#475569"><?= number_format($deadCount) ?></p>
        <p class="kpi-sub">Survey Board approved write-offs</p>
    </div>
</div>

<div class="report-tabs" style="margin-bottom:1.5rem">
    <a class="tab-link <?= $subview === 'all' ? 'active' : '' ?>" href="<?= page_link('assets') ?>&subview=all">▤ Fixed Asset Master (<?= $totalAssets ?>)</a>
    <a class="tab-link <?= $subview === 'transfers' ? 'active' : '' ?>" href="<?= page_link('assets') ?>&subview=transfers">⇄ Inter-Dept Transfers (<?= count($transferRows) ?>)</a>
    <a class="tab-link <?= $subview === 'maintenance' ? 'active' : '' ?>" href="<?= page_link('assets') ?>&subview=maintenance">🛠 Maintenance &amp; Repairs (<?= count($maintRows) ?>)</a>
    <a class="tab-link <?= $subview === 'condemnations' ? 'active' : '' ?>" href="<?= page_link('assets') ?>&subview=condemnations">⚠️ Dead Stock &amp; Condemnations (<?= count($condemnRows) ?>)</a>
</div>

<?php /* ── TAB 1: ASSET MASTER ── */ ?>
<?php if ($subview === 'all'): ?>
<div class="system-grid">
    <div class="panel">
        <div class="panel-heading" style="flex-wrap:wrap;gap:.5rem">
            <h2>Fixed Asset Register</h2>
            <div style="display:flex;gap:.4rem;align-items:center">
                <a class="button button-light" href="<?= page_link('reports') ?>&rtype=assets&export=csv" style="padding:.25rem .6rem;font-size:.78rem">📥 Export CSV</a>
                <a class="button button-primary" href="#add-asset" style="padding:.25rem .6rem;font-size:.78rem">＋ Register Asset</a>
            </div>
        </div>
        <div class="table-wrap"><table>
            <thead>
                <tr>
                    <th>Tag &amp; Equipment</th>
                    <th>Category &amp; Location</th>
                    <th>Department &amp; Custodian</th>
                    <th>Purchase Cost &amp; Date</th>
                    <th>AMC / Warranty</th>
                    <th>Status</th>
                    <th>Quick Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($assetRows)): ?>
            <tr><td colspan="7" class="muted" style="text-align:center;padding:2.5rem">No fixed assets found matching your criteria.</td></tr>
            <?php else: foreach ($assetRows as $a): ?>
            <tr>
                <td>
                    <b><?= e($a['tag']) ?></b><br>
                    <span style="font-weight:600;color:var(--brand-primary,#00897b)"><?= e($a['name']) ?></span><br>
                    <small class="muted"><?= e($a['make']) ?> <?= e($a['model']) ?> · SN: <?= e($a['serial_number'] ?: '—') ?></small>
                </td>
                <td>
                    <?= e($a['category']) ?><br>
                    <small class="muted"><?= e($a['location']) ?></small>
                </td>
                <td>
                    <b><?= e($a['department']) ?></b><br>
                    <small class="muted">Cust: <?= e($a['custodian']) ?></small>
                </td>
                <td>
                    <b>Rs. <?= number_format((float)$a['cost'], 2) ?></b><br>
                    <small class="muted"><?= !empty($a['purchase_date']) ? e(date('d M Y', strtotime($a['purchase_date']))) : '—' ?></small>
                </td>
                <td>
                    <?php if (!empty($a['amc_expiry'])): ?>
                        <small>AMC: <b><?= e($a['amc_expiry']) ?></b></small><br>
                    <?php endif; ?>
                    <?php if (!empty($a['warranty_until'])): ?>
                        <small class="muted">Warranty: <?= e($a['warranty_until']) ?></small>
                    <?php else: ?>
                        <small class="muted">No warranty</small>
                    <?php endif; ?>
                </td>
                <td>
                    <?= pill($a['status']) ?>
                </td>
                <td>
                    <div style="display:flex;gap:.3rem;flex-wrap:wrap">
                        <a class="button button-light" href="<?= page_link('assets') ?>&subview=transfers&asset_id=<?= $a['id'] ?>#add-transfer" title="Transfer Asset" style="padding:.15rem .4rem;font-size:.75rem">⇄</a>
                        <a class="button button-light" href="<?= page_link('assets') ?>&subview=maintenance&asset_id=<?= $a['id'] ?>#add-maintenance" title="Log Repair" style="padding:.15rem .4rem;font-size:.75rem">🛠</a>
                        <?php if ($a['status'] !== 'Condemned' && $a['status'] !== 'Disposed'): ?>
                        <a class="button button-light" href="<?= page_link('assets') ?>&subview=condemnations&asset_id=<?= $a['id'] ?>#add-condemn" title="Condemn Dead Stock" style="padding:.15rem .4rem;font-size:.75rem;color:#b91c1c">⚠️</a>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>

    <div class="form-card" id="add-asset">
        <h2>Register Fixed Asset</h2>
        <p class="muted" style="margin-bottom:1rem">Enter complete procurement and technical specifications to add durable machinery or clinical equipment to the register.</p>
        <form method="post">
            <input type="hidden" name="action" value="asset">
            <div class="form-grid one">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                    <label>Asset Tag<input name="tag" value="<?= e(next_number($pdo, 'AST', 'assets', 'tag')) ?>" required></label>
                    <label>Asset Type
                        <select name="asset_type">
                            <option value="Fixed Asset">Fixed Asset (Machinery / Equipment)</option>
                            <option value="IT Hardware">IT &amp; Computing Hardware</option>
                            <option value="Durable Furniture">Durable Furniture &amp; Fixture</option>
                        </select>
                    </label>
                </div>
                <label>Equipment / Asset Name<input name="name" placeholder="e.g. Slit Lamp Microscope SL 115" required></label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                    <label>Category
                        <select name="category">
                            <option>Medical Equipment</option>
                            <option>Surgical Instruments</option>
                            <option>Computers &amp; IT</option>
                            <option>Electrical Equipment</option>
                            <option>Furniture &amp; Fixtures</option>
                            <option>Laboratory</option>
                            <option>Vehicles</option>
                            <option>Other</option>
                        </select>
                    </label>
                    <label>Make / Manufacturer<input name="make" placeholder="e.g. Carl Zeiss" required></label>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                    <label>Model<input name="model" placeholder="e.g. SL 115"></label>
                    <label>Serial Number<input name="serial_number" placeholder="e.g. SN-884912"></label>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                    <label>Purchase Date<input name="purchase_date" type="date" value="<?= date('Y-m-d') ?>"></label>
                    <label>Cost / Book Value (Rs.)<input name="cost" type="number" step="0.01" min="0" value="150000.00" required></label>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                    <label>PO Number Ref<input name="po_number" placeholder="e.g. PO-2026-0042"></label>
                    <label>Delivery Challan / Inv.<input name="challan_number" placeholder="e.g. DC-4521"></label>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                    <label>Current Department
                        <select name="department" required>
                            <?php foreach ($depts_all as $d): ?>
                            <option value="<?= e($d['name']) ?>"><?= e($d['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Location / Room<input name="location" placeholder="e.g. Room 3, Bay 2" required></label>
                </div>
                <label>Custodian Officer<input name="custodian" value="<?= e(current_user()['name'] ?? 'Store Officer') ?>" required></label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                    <label>Warranty Expiry<input name="warranty_until" type="date" value="<?= date('Y-m-d', strtotime('+1 year')) ?>"></label>
                    <label>AMC Expiry<input name="amc_expiry" type="date" value="<?= date('Y-m-d', strtotime('+2 years')) ?>"></label>
                </div>
                <label>Operational Status
                    <select name="status">
                        <option value="In service">In service (Operational)</option>
                        <option value="AMC due soon">AMC due soon</option>
                        <option value="Under repair">Under repair</option>
                        <option value="Condemned">Condemned (Dead Stock)</option>
                    </select>
                </label>
            </div>
            <button class="button button-primary" type="submit" style="margin-top:1.2rem;width:100%">Save Fixed Asset</button>
        </form>
    </div>
</div>

<?php /* ── TAB 2: INTER-DEPARTMENT TRANSFERS ── */ ?>
<?php elseif ($subview === 'transfers'): ?>
<div class="system-grid">
    <div class="panel">
        <div class="panel-heading">
            <h2>Inter-Department Asset Transfers</h2>
            <a class="button button-primary" href="#add-transfer" style="padding:.25rem .6rem;font-size:.78rem">＋ New Transfer</a>
        </div>
        <div class="table-wrap"><table>
            <thead>
                <tr>
                    <th>Transfer # / Date</th>
                    <th>Asset Details</th>
                    <th>From Department</th>
                    <th>To Department</th>
                    <th>Reason / Order Ref</th>
                    <th>Authorized / Received By</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($transferRows)): ?>
            <tr><td colspan="6" class="muted" style="text-align:center;padding:2.5rem">No asset transfers logged.</td></tr>
            <?php else: foreach ($transferRows as $tr): ?>
            <tr>
                <td>
                    <b><?= e($tr['transfer_number']) ?></b><br>
                    <small class="muted"><?= e(date('d M Y', strtotime($tr['transfer_date']))) ?></small>
                </td>
                <td>
                    <b><?= e($tr['asset_tag']) ?></b><br>
                    <span class="muted"><?= e($tr['asset_name']) ?></span>
                </td>
                <td>
                    <b><?= e($tr['previous_department']) ?></b><br>
                    <small class="muted"><?= e($tr['previous_location']) ?> (<?= e($tr['previous_custodian']) ?>)</small>
                </td>
                <td>
                    <b style="color:var(--brand-primary,#00897b)"><?= e($tr['new_department']) ?></b><br>
                    <small class="muted"><?= e($tr['new_location']) ?> (<?= e($tr['new_custodian']) ?>)</small>
                </td>
                <td>
                    <?= e($tr['reason']) ?>
                </td>
                <td>
                    <small>Auth: <b><?= e($tr['authorized_by'] ?? 'MS') ?></b><br>
                    Recv: <b><?= e($tr['received_by'] ?? 'Custodian') ?></b></small>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>

    <div class="form-card" id="add-transfer">
        <h2>Initiate Asset Transfer</h2>
        <p class="muted" style="margin-bottom:1rem">Document the formal relocation of equipment between hospital departments. Updates location and custodian in asset master automatically.</p>
        <form method="post">
            <input type="hidden" name="action" value="transfer_asset">
            <div class="form-grid one">
                <label>Select Asset
                    <select name="asset_id" required>
                        <option value="">— Select asset to transfer —</option>
                        <?php foreach ($assetsAll as $ast): ?>
                        <option value="<?= $ast['id'] ?>" <?= $selectedAssetId === (int)$ast['id'] ? 'selected' : '' ?>>
                            <?= e($ast['tag']) ?> — <?= e($ast['name']) ?> (Current: <?= e($ast['department']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                    <label>Destination Department
                        <select name="new_department" required>
                            <?php foreach ($depts_all as $d): ?>
                            <option value="<?= e($d['name']) ?>"><?= e($d['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>New Room / Location<input name="new_location" placeholder="e.g. Ward 2 Bay 4" required></label>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                    <label>New Custodian Officer<input name="new_custodian" placeholder="e.g. Head Nurse Asma" required></label>
                    <label>Transfer Date<input name="transfer_date" type="date" value="<?= date('Y-m-d') ?>" required></label>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                    <label>Authorized By<input name="authorized_by" value="Medical Superintendent" required></label>
                    <label>Received By<input name="received_by" placeholder="Officer name"></label>
                </div>
                <label>Transfer Justification / Office Memo
                    <textarea name="reason" rows="2" placeholder="e.g. Relocated to meet patient load in Ward 2 as per MS Directive" required></textarea>
                </label>
            </div>
            <button class="button button-primary" type="submit" style="margin-top:1.2rem;width:100%">Confirm &amp; Relocate Asset</button>
        </form>
    </div>
</div>

<?php /* ── TAB 3: MAINTENANCE & REPAIRS ── */ ?>
<?php elseif ($subview === 'maintenance'): ?>
<div class="system-grid">
    <div class="panel">
        <div class="panel-heading">
            <h2>Maintenance &amp; Breakdown Log</h2>
            <a class="button button-primary" href="#add-maintenance" style="padding:.25rem .6rem;font-size:.78rem">＋ Log Maintenance</a>
        </div>
        <div class="table-wrap"><table>
            <thead>
                <tr>
                    <th>Mnt # / Reported</th>
                    <th>Equipment Details</th>
                    <th>Type &amp; Priority</th>
                    <th>Problem &amp; Actions</th>
                    <th>Vendor / Tech</th>
                    <th>Total Cost</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($maintRows)): ?>
            <tr><td colspan="7" class="muted" style="text-align:center;padding:2.5rem">No maintenance records logged.</td></tr>
            <?php else: foreach ($maintRows as $m): ?>
            <tr>
                <td>
                    <b><?= e($m['maintenance_no']) ?></b><br>
                    <small class="muted"><?= e(date('d M Y', strtotime($m['reported_date']))) ?></small>
                </td>
                <td>
                    <b><?= e($m['asset_tag']) ?></b><br>
                    <span class="muted"><?= e($m['asset_name']) ?></span>
                </td>
                <td>
                    <b><?= e($m['maintenance_type']) ?></b><br>
                    <small class="muted">Priority: <?= e($m['priority']) ?></small>
                </td>
                <td>
                    <b><?= e($m['problem_details']) ?></b>
                    <?php if (!empty($m['repair_details'])): ?>
                        <br><small class="muted"><?= e($m['repair_details']) ?></small>
                    <?php endif; ?>
                    <?php if (!empty($m['parts_replaced'])): ?>
                        <br><small style="color:var(--brand-primary,#00897b)">Parts: <?= e($m['parts_replaced']) ?></small>
                    <?php endif; ?>
                </td>
                <td>
                    <?= e($m['technician_vendor'] ?: 'In-house BioMed') ?>
                </td>
                <td>
                    <b>Rs. <?= number_format((float)$m['total_cost'], 2) ?></b><br>
                    <small class="muted">Labor: <?= number_format((float)$m['labor_cost']) ?> · Parts: <?= number_format((float)$m['parts_cost']) ?></small>
                </td>
                <td>
                    <?= pill($m['status']) ?><br>
                    <?php if ($m['warranty_covered']): ?>
                        <small style="color:#047857">✓ Warranty covered</small>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>

    <div class="form-card" id="add-maintenance">
        <h2>Log Asset Maintenance</h2>
        <p class="muted" style="margin-bottom:1rem">Record breakdown repairs, periodic AMC calibrations, or part replacements with vendor and expense tracking.</p>
        <form method="post">
            <input type="hidden" name="action" value="maintenance_asset">
            <div class="form-grid one">
                <label>Equipment
                    <select name="asset_id" required>
                        <option value="">— Select equipment —</option>
                        <?php foreach ($assetsAll as $ast): ?>
                        <option value="<?= $ast['id'] ?>" <?= $selectedAssetId === (int)$ast['id'] ? 'selected' : '' ?>>
                            <?= e($ast['tag']) ?> — <?= e($ast['name']) ?> (<?= e($ast['department']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                    <label>Maintenance Type
                        <select name="maintenance_type">
                            <option value="Breakdown">Breakdown Repair</option>
                            <option value="Preventive AMC">Preventive AMC Service</option>
                            <option value="Annual Calibration">Annual Optical Calibration</option>
                            <option value="Warranty Claim">Warranty Claim Service</option>
                        </select>
                    </label>
                    <label>Priority
                        <select name="priority">
                            <option value="Medium">Medium</option>
                            <option value="High">High</option>
                            <option value="Critical">Critical (Halts Surgeries/OT)</option>
                            <option value="Low">Low</option>
                        </select>
                    </label>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                    <label>Reported Date<input name="reported_date" type="date" value="<?= date('Y-m-d') ?>" required></label>
                    <label>Reported By<input name="reported_by" value="<?= e(current_user()['name'] ?? 'Staff') ?>" required></label>
                </div>
                <label>Problem Symptoms / Malfunction
                    <textarea name="problem_details" rows="2" placeholder="Describe the fault observed..." required></textarea>
                </label>
                <label>Technician / Biomedical Vendor<input name="technician_vendor" placeholder="e.g. Precision Biomedical Tech Karachi"></label>
                <label>Actions Taken &amp; Repair Details
                    <textarea name="repair_details" rows="2" placeholder="Work carried out, diagnostics, firmware flash..."></textarea>
                </label>
                <label>Spare Parts Replaced<input name="parts_replaced" placeholder="e.g. Halogen lamp, Power supply board"></label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                    <label>Labor Cost (Rs.)<input name="labor_cost" type="number" step="0.01" min="0" value="0.00"></label>
                    <label>Parts Cost (Rs.)<input name="parts_cost" type="number" step="0.01" min="0" value="0.00"></label>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                    <label>Repair Status
                        <select name="status">
                            <option value="Under repair">Under repair (In progress)</option>
                            <option value="Completed">Completed (Returned to clinical service)</option>
                        </select>
                    </label>
                    <label>Date Returned to Service<input name="date_returned" type="date"></label>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;align-items:center">
                    <label style="display:flex;align-items:center;gap:.5rem;margin-top:.8rem">
                        <input type="checkbox" name="warranty_covered" value="1"> Covered under OEM Warranty
                    </label>
                    <label>Next Due Calibration<input name="next_due_date" type="date" value="<?= date('Y-m-d', strtotime('+90 days')) ?>"></label>
                </div>
            </div>
            <button class="button button-primary" type="submit" style="margin-top:1.2rem;width:100%">Save Maintenance Record</button>
        </form>
    </div>
</div>

<?php /* ── TAB 4: DEAD STOCK & CONDEMNATIONS ── */ ?>
<?php elseif ($subview === 'condemnations'): ?>
<div class="system-grid">
    <div class="panel">
        <div class="panel-heading">
            <h2>Dead Stock &amp; Condemnation Register</h2>
            <a class="button button-primary" href="#add-condemn" style="padding:.25rem .6rem;font-size:.78rem">＋ Recommend Condemnation</a>
        </div>
        <div class="table-wrap"><table>
            <thead>
                <tr>
                    <th>Condemnation # / Date</th>
                    <th>Equipment Details</th>
                    <th>Survey Board Ref</th>
                    <th>Justification (BER / Reason)</th>
                    <th>Estimated Scrap Value</th>
                    <th>Disposal / Auction Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($condemnRows)): ?>
            <tr><td colspan="7" class="muted" style="text-align:center;padding:2.5rem">No dead stock or condemnation records found.</td></tr>
            <?php else: foreach ($condemnRows as $c): ?>
            <tr>
                <td>
                    <b><?= e($c['condemnation_no']) ?></b><br>
                    <small class="muted"><?= e(date('d M Y', strtotime($c['survey_board_date']))) ?></small>
                </td>
                <td>
                    <b><?= e($c['tag_or_code']) ?></b><br>
                    <span style="font-weight:600"><?= e($c['item_name']) ?></span><br>
                    <small class="muted">Dept: <?= e($c['department']) ?></small>
                </td>
                <td>
                    <b><?= e($c['survey_board_ref']) ?></b><br>
                    <small class="muted">Comm: <?= e($c['committee_members']) ?></small>
                </td>
                <td>
                    <b><?= e($c['reason']) ?></b><br>
                    <small class="muted"><?= e($c['recommendation']) ?></small>
                </td>
                <td>
                    <b>Rs. <?= number_format((float)$c['estimated_scrap_val'], 2) ?></b>
                </td>
                <td>
                    <?= pill($c['status']) ?><br>
                    <?php if ($c['status'] === 'Disposed/Auctioned'): ?>
                        <small style="color:#047857">Challan: <b><?= e($c['auction_challan_no']) ?></b><br>
                        Sold: <b>Rs. <?= number_format((float)$c['recovered_amount'], 2) ?></b></small>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($c['status'] === 'Approved by Board' || $c['status'] === 'Recommended'): ?>
                        <details style="font-size:.8rem">
                            <summary style="cursor:pointer;color:var(--brand-primary,#00897b);font-weight:600">⚡ Finalize Auction</summary>
                            <form method="post" style="margin-top:.4rem;background:#f8fafc;padding:.5rem;border-radius:4px;border:1px solid #e2e8f0;display:flex;flex-direction:column;gap:.3rem">
                                <input type="hidden" name="action" value="auction_disposal">
                                <input type="hidden" name="condemnation_id" value="<?= $c['id'] ?>">
                                <input name="auction_challan_no" placeholder="Auction Challan #" required style="font-size:.75rem;padding:.2rem .4rem">
                                <input name="recovered_amount" type="number" step="0.01" placeholder="Sold Amount (Rs.)" required style="font-size:.75rem;padding:.2rem .4rem">
                                <input name="buyer_vendor_name" placeholder="Scrap Contractor / Buyer" style="font-size:.75rem;padding:.2rem .4rem">
                                <input name="disposal_date" type="date" value="<?= date('Y-m-d') ?>" style="font-size:.75rem;padding:.2rem .4rem">
                                <button class="button button-primary" type="submit" style="font-size:.72rem;padding:.2rem .4rem" onclick="return confirm('Confirm auction disposal and write off asset?')">Complete Disposal</button>
                            </form>
                        </details>
                    <?php else: ?>
                        <span class="muted" style="font-size:.78rem">Disposed</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>

    <div class="form-card" id="add-condemn">
        <h2>Recommend for Condemnation</h2>
        <p class="muted" style="margin-bottom:1rem">Submit broken, obsolete, or Beyond Economic Repair (BER) hospital property to the Survey Board for official condemnation and public auction.</p>
        <form method="post">
            <input type="hidden" name="action" value="condemn_asset">
            <div class="form-grid one">
                <label>Select Fixed Asset (Optional if item from stock)
                    <select name="asset_id">
                        <option value="">— Select registered fixed asset —</option>
                        <?php foreach ($assetsAll as $ast): ?>
                        <option value="<?= $ast['id'] ?>" <?= $selectedAssetId === (int)$ast['id'] ? 'selected' : '' ?>>
                            <?= e($ast['tag']) ?> — <?= e($ast['name']) ?> (<?= e($ast['department']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                    <label>Item / Asset Name<input name="item_name" placeholder="Auto-filled or manual name"></label>
                    <label>Tag / Serial No.<input name="tag_or_code" placeholder="Tag or serial"></label>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                    <label>Current Department<input name="department" value="Central Store"></label>
                    <label>Estimated Scrap Value (Rs.)<input name="estimated_scrap_val" type="number" step="0.01" value="5000.00" required></label>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                    <label>Survey Board Notification Ref<input name="survey_board_ref" value="SIOVS/SB/<?= date('Y') ?>/<?= sprintf('%02d', rand(1, 15)) ?>" required></label>
                    <label>Survey Board Date<input name="survey_board_date" type="date" value="<?= date('Y-m-d') ?>" required></label>
                </div>
                <label>Technical Condemnation Justification
                    <textarea name="reason" rows="2" placeholder="e.g. Beyond Economic Repair (BER) - Optical prism cracked, optical silvering decayed. Original OEM spare parts obsolete." required></textarea>
                </label>
                <label>Committee Recommendation
                    <input name="recommendation" value="Condemn asset and dispose via open public auction under Sindh Financial Rules" required>
                </label>
                <label>Survey Board Committee Members
                    <input name="committee_members" value="DD Finance, Store Officer, Biomedical Engineer" required>
                </label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                    <label>Disposal Method
                        <select name="disposal_method">
                            <option value="Auction">Open Public Auction</option>
                            <option value="Scrap Sale">Scrap / Metal Salvage</option>
                            <option value="Return to OEM">Return to OEM / Supplier</option>
                            <option value="Hazardous Destruction">Safe Hazardous Waste Destruction</option>
                        </select>
                    </label>
                    <label>Board Approval Status
                        <select name="status">
                            <option value="Approved by Board">Approved by Board</option>
                            <option value="Recommended">Recommended (Pending Approval)</option>
                        </select>
                    </label>
                </div>
                <label>Approving Authority<input name="approved_by" value="Prof. Dr. Competent Authority" required></label>
            </div>
            <button class="button button-primary" type="submit" style="margin-top:1.2rem;width:100%;background:#b91c1c;border-color:#991b1b">Submit Condemnation Order</button>
        </form>
    </div>
</div>
<?php endif; ?>

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
$returnRows = $pdo->query('
    SELECT r.*, d.name AS dept_name, d.type AS dept_type, u.name AS creator_name,
           (SELECT COUNT(*) FROM department_return_items WHERE return_id = r.id) AS item_count,
           (SELECT SUM(quantity_returned) FROM department_return_items WHERE return_id = r.id) AS total_returned_qty
    FROM department_returns r
    LEFT JOIN departments d ON d.id = r.department_id
    LEFT JOIN users u ON u.id = r.created_by
    ORDER BY r.id DESC
')->fetchAll(PDO::FETCH_ASSOC);

$returnItemsMap = [];
if (!empty($returnRows)) {
    $rIds = array_column($returnRows, 'id');
    $placeholders = implode(',', array_fill(0, count($rIds), '?'));
    $itemsStmt = $pdo->prepare("
        SELECT ri.*, i.name AS item_name, i.code AS item_code, i.unit
        FROM department_return_items ri
        JOIN items i ON i.id = ri.item_id
        WHERE ri.return_id IN ($placeholders)
        ORDER BY ri.id ASC
    ");
    $itemsStmt->execute($rIds);
    foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $ritem) {
        $returnItemsMap[$ritem['return_id']][] = $ritem;
    }
}

$returnDocs = [];
$docStmt = $pdo->query("SELECT * FROM document_attachments WHERE entity_type='department_returns'");
foreach ($docStmt->fetchAll(PDO::FETCH_ASSOC) as $doc) {
    $returnDocs[$doc['entity_id']][] = $doc;
}

$deptStockRows = $pdo->query('
    SELECT ds.*, d.name AS dept_name, d.type AS dept_type, i.name AS item_name, i.code AS item_code, i.unit
    FROM department_stock ds
    JOIN departments d ON d.id = ds.department_id
    JOIN items i ON i.id = ds.item_id
    WHERE ds.quantity > 0
    ORDER BY d.name, i.name
')->fetchAll(PDO::FETCH_ASSOC);

$totalReturnsCount = count($returnRows);
$totalUnitsRestocked = (int)array_sum(array_column($returnRows, 'total_returned_qty'));
$totalDeptHoldings = (int)array_sum(array_column($deptStockRows, 'quantity'));
?>

<div class="kpi-grid four" style="margin-bottom:1.5rem">
    <div class="kpi-card">
        <p class="kpi-label">Total Returns Recorded</p>
        <p class="kpi-val"><?= $totalReturnsCount ?></p>
        <p class="kpi-sub">Sub-store return vouchers</p>
    </div>
    <div class="kpi-card">
        <p class="kpi-label">Units Restocked to Main</p>
        <p class="kpi-val" style="color:var(--brand-primary, #00897b)"><?= number_format($totalUnitsRestocked) ?></p>
        <p class="kpi-sub">Returned to central stock</p>
    </div>
    <div class="kpi-card">
        <p class="kpi-label">Active Sub-Store Stock</p>
        <p class="kpi-val"><?= number_format($totalDeptHoldings) ?></p>
        <p class="kpi-sub">Units held across departments</p>
    </div>
    <div class="kpi-card">
        <p class="kpi-label">Audit Reconciliation</p>
        <p class="kpi-val" style="font-size:1.1rem;color:#166534">Sindh Manual Sync</p>
        <p class="kpi-sub">Register & page tracked</p>
    </div>
</div>

<div class="system-grid">
    <div class="panel">
        <div class="panel-heading">
            <h2>Department Returns Register (Sub-Store to Main Store)</h2>
            <a class="button button-primary" href="#add-return">＋ New Return Voucher</a>
        </div>
        <div class="table-wrap"><table>
            <thead>
                <tr>
                    <th>Return # / Date</th>
                    <th>Department</th>
                    <th>Returned Items & Batch</th>
                    <th>Reason / Handover</th>
                    <th>Manual Register Ref</th>
                    <th>Status / Docs</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($returnRows)): ?>
            <tr><td colspan="6" class="muted" style="text-align:center;padding:2.5rem">No department returns recorded yet. Use the form to receive unused or surplus items back into Main Store.</td></tr>
            <?php else: foreach ($returnRows as $r): ?>
            <tr>
                <td>
                    <b><?= e($r['return_number']) ?></b><br>
                    <small class="muted"><?= e(date('d M Y', strtotime($r['return_date']))) ?></small>
                </td>
                <td>
                    <b><?= e($r['dept_name'] ?? 'Unknown Dept') ?></b><br>
                    <?= pill($r['dept_type'] ?? 'Sub-Store') ?>
                </td>
                <td>
                    <?php if (!empty($returnItemsMap[$r['id']])): ?>
                        <div style="font-size:0.85rem">
                        <?php foreach ($returnItemsMap[$r['id']] as $it): ?>
                            <div style="margin-bottom:0.25rem">
                                • <b><?= e($it['item_code']) ?></b>: <?= e($it['item_name']) ?>
                                <span style="color:var(--brand-primary, #00897b);font-weight:600">× <?= e($it['quantity_returned']) ?> <?= e($it['unit']) ?></span>
                                <?php if (!empty($it['batch_number'])): ?>
                                    <code style="font-size:0.75rem"><?= e($it['batch_number']) ?></code>
                                <?php endif; ?>
                                <?php if (!empty($it['remarks'])): ?>
                                    <span class="muted" style="font-size:0.75rem">(<?= e($it['remarks']) ?>)</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <span class="muted"><?= (int)$r['total_returned_qty'] ?> units</span>
                    <?php endif; ?>
                </td>
                <td>
                    <b><?= e($r['reason']) ?></b><br>
                    <small class="muted">
                        From: <?= e($r['returning_officer'] ?: 'Dept Staff') ?><br>
                        Recv: <?= e($r['receiving_officer'] ?: 'Store Officer') ?>
                    </small>
                </td>
                <td>
                    <?php if ($r['manual_register_page'] || $r['manual_register_entry']): ?>
                        <small><b><?= e($r['manual_register_name'] ?: 'Store Return Register') ?></b><br>
                        Pg: <b><?= e($r['manual_register_page'] ?: '—') ?></b> · Entry: <b><?= e($r['manual_register_entry'] ?: '—') ?></b></small>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?= pill($r['status']) ?>
                    <?php if (!empty($returnDocs[$r['id']])): ?>
                        <div style="margin-top:0.35rem">
                        <?php foreach ($returnDocs[$r['id']] as $d): ?>
                            <a href="<?= e($d['file_path']) ?>" download="<?= e($d['file_name']) ?>" style="display:inline-flex;align-items:center;gap:.25rem;font-size:.72rem;background:#f0fdf4;padding:.15rem .4rem;border-radius:4px;border:1px solid #bbf7d0;color:#166534;text-decoration:none;" title="Download <?= e($d['file_name']) ?>">📎 <?= e($d['file_name']) ?></a>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>

    <div class="form-card" id="add-return">
        <h2>Return Unused Material</h2>
        <p class="muted" style="margin-bottom:1rem">Directly credits Main Store inventory and updates item batch counts while decrementing the department virtual balance.</p>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="department_return">
            <div class="form-grid one">
                <label>Department returning items
                    <select name="dept_id" required>
                        <option value="">— Select Department —</option>
                        <?php foreach ($depts_all as $d): ?>
                        <option value="<?= $d['id'] ?>"><?= e($d['name']) ?> (<?= e($d['type']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Return date
                    <input name="return_date" type="date" value="<?= date('Y-m-d') ?>" required>
                </label>
                <label>Reason for return
                    <select name="reason">
                        <option value="Unused / surplus stock after procedure">Unused / surplus stock after procedure</option>
                        <option value="Excess requisitioned stock returned">Excess requisitioned stock returned</option>
                        <option value="Procedure cancelled / patient discharged">Procedure cancelled / patient discharged</option>
                        <option value="Near expiry reallocation back to Main Store">Near expiry reallocation back to Main Store</option>
                        <option value="Packaging damaged / sterile seal compromised">Packaging damaged / sterile seal compromised</option>
                        <option value="Department audit / stock rationalization">Department audit / stock rationalization</option>
                    </select>
                </label>
                <label>Returning officer (Department Staff)
                    <input name="returning_officer" placeholder="Name / Designation" value="Sister Incharge" required>
                </label>
                <label>Receiving store officer
                    <input name="receiving_officer" value="<?= e(current_user()['name'] ?? 'Store Officer') ?>" required>
                </label>
            </div>

            <p class="eyebrow" style="margin:1.2rem 0 .4rem">SINDH GOVT. MANUAL REGISTER CROSS-REFERENCE</p>
            <div class="form-grid one" style="background:var(--surface-sunken, #f8fafc);padding:.75rem;border-radius:6px;border:1px dashed var(--border-color,#e2e8f0)">
                <label>Manual Register Name
                    <input name="manual_register_name" value="Store Return Register">
                </label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                    <label>Page No.<input name="manual_register_page" placeholder="e.g. 74"></label>
                    <label>Entry / Sr No.<input name="manual_register_entry" placeholder="e.g. 19"></label>
                </div>
            </div>

            <p class="eyebrow" style="margin:1.2rem 0 .4rem">ATTACH STORE RETURN MEMO / VOUCHER (OPTIONAL)</p>
            <input type="file" name="return_document" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" style="font-size:0.85rem">

            <p class="eyebrow" style="margin:1.2rem 0 .4rem">ITEMS BEING RETURNED (UP TO 4 LINES)</p>
            <div style="display:flex;flex-direction:column;gap:.5rem">
            <?php for ($i = 0; $i < 4; $i++): ?>
                <div style="background:var(--surface-sunken, #f8fafc);padding:.6rem;border-radius:6px;border:1px solid var(--border-color, #e2e8f0);display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:.4rem;align-items:center">
                    <div>
                        <small class="muted" style="display:block;margin-bottom:.2rem">Item</small>
                        <select name="item_id[]" style="width:100%;font-size:.85rem">
                            <option value="">— Select item —</option>
                            <?php foreach ($items_all as $it): ?>
                            <option value="<?= $it['id'] ?>"><?= e($it['code']) ?> — <?= e($it['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <small class="muted" style="display:block;margin-bottom:.2rem">Batch No.</small>
                        <input name="batch[]" placeholder="Batch #" style="width:100%;font-size:.85rem">
                    </div>
                    <div>
                        <small class="muted" style="display:block;margin-bottom:.2rem">Return Qty</small>
                        <input name="quantity[]" type="number" min="1" placeholder="Qty" style="width:100%;font-size:.85rem">
                    </div>
                    <div>
                        <small class="muted" style="display:block;margin-bottom:.2rem">Condition / Note</small>
                        <input name="item_remarks[]" placeholder="Sealed / intact" style="width:100%;font-size:.85rem">
                    </div>
                </div>
            <?php endfor; ?>
            </div>

            <button class="button button-primary" type="submit" style="margin-top:1.2rem;width:100%">Receive & Restock to Main Store</button>
        </form>
    </div>
</div>

<div class="panel" style="margin-top:1.5rem">
    <div class="panel-heading">
        <h2>Department Virtual Stock Balances (Available for Return)</h2>
    </div>
    <div class="table-wrap"><table>
        <thead>
            <tr>
                <th>Department</th>
                <th>Type</th>
                <th>Item Code & Name</th>
                <th>Batch Number</th>
                <th>Expiry Date</th>
                <th>Quantity in Dept</th>
                <th>Last Issued / Updated</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($deptStockRows)): ?>
        <tr><td colspan="7" class="muted" style="text-align:center;padding:2rem">No stock currently held in department virtual locations. Stock appears when store requisitions are issued.</td></tr>
        <?php else: foreach ($deptStockRows as $ds): ?>
        <tr>
            <td><b><?= e($ds['dept_name']) ?></b></td>
            <td><?= pill($ds['dept_type']) ?></td>
            <td><?= e($ds['item_code']) ?> — <b><?= e($ds['item_name']) ?></b></td>
            <td><code><?= e($ds['batch_number'] ?: '—') ?></code></td>
            <td><?= e($ds['expiry_date'] ?: '—') ?></td>
            <td><strong style="color:var(--brand-primary, #00897b);font-size:1.05rem"><?= e($ds['quantity']) ?></strong> <?= e($ds['unit']) ?></td>
            <td><small class="muted"><?= e(date('d M Y, h:i A', strtotime($ds['updated_at']))) ?></small></td>
        </tr>
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

<?php /* ════════════════════ RECONCILIATION ═════════════════════════════ */ ?>
<?php elseif ($view === 'reconciliation'): ?>
<?php
$recFilter = $_GET['filter'] ?? 'all';
$sql = '
    SELECT r.*, u.name AS verifier_name
    FROM manual_reconciliations r
    LEFT JOIN users u ON u.id = r.verified_by
';
if ($recFilter === 'discrepancy') {
    $sql .= ' WHERE r.status = "Discrepancy" OR r.difference != 0';
} elseif ($recFilter === 'matched') {
    $sql .= ' WHERE r.status = "Matched" AND r.difference = 0';
}
$sql .= ' ORDER BY r.id DESC LIMIT 100';
$reconciliations = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$totalRecs = (int)$pdo->query('SELECT COUNT(*) FROM manual_reconciliations')->fetchColumn();
$matchedRecs = (int)$pdo->query('SELECT COUNT(*) FROM manual_reconciliations WHERE difference = 0')->fetchColumn();
$discrepancyRecs = (int)$pdo->query('SELECT COUNT(*) FROM manual_reconciliations WHERE difference != 0')->fetchColumn();
$matchRate = $totalRecs > 0 ? round(($matchedRecs / $totalRecs) * 100, 1) : 100;
?>

<div class="kpi-grid four" style="margin-bottom:1.5rem">
    <div class="kpi-card">
        <p class="kpi-label">Reconciliations Performed</p>
        <p class="kpi-val"><?= $totalRecs ?></p>
        <p class="kpi-sub">Manual register vs digital audits</p>
    </div>
    <div class="kpi-card">
        <p class="kpi-label">100% Matched Registers</p>
        <p class="kpi-val" style="color:var(--brand-primary, #00897b)"><?= $matchedRecs ?></p>
        <p class="kpi-sub"><?= $matchRate ?>% zero-variance sync</p>
    </div>
    <div class="kpi-card" style="border-left:4px solid #ef4444">
        <p class="kpi-label">Discrepancies Flagged</p>
        <p class="kpi-val" style="color:#b91c1c"><?= $discrepancyRecs ?></p>
        <p class="kpi-sub">Requiring inquiry / adjustment</p>
    </div>
    <div class="kpi-card">
        <p class="kpi-label">Sindh Govt. Audit Standard</p>
        <p class="kpi-val" style="font-size:1.05rem;color:#166534">Page & Sr. No. Tracked</p>
        <p class="kpi-sub">Dual paper-digital ledger</p>
    </div>
</div>

<div class="system-grid">
    <div class="panel">
        <div class="panel-heading" style="flex-wrap:wrap;gap:.5rem">
            <h2>Sindh Manual Register vs Digital Audit Register</h2>
            <div style="display:flex;gap:.3rem;align-items:center">
                <a class="button <?= $recFilter==='all'?'button-primary':'button-light' ?>" href="<?= page_link('reconciliation') ?>&filter=all" style="padding:.2rem .6rem;font-size:.78rem">All (<?= $totalRecs ?>)</a>
                <a class="button <?= $recFilter==='matched'?'button-primary':'button-light' ?>" href="<?= page_link('reconciliation') ?>&filter=matched" style="padding:.2rem .6rem;font-size:.78rem">✓ Matched (<?= $matchedRecs ?>)</a>
                <a class="button <?= $recFilter==='discrepancy'?'button-primary':'button-light' ?>" href="<?= page_link('reconciliation') ?>&filter=discrepancy" style="padding:.2rem .6rem;font-size:.78rem">⚠️ Discrepancies (<?= $discrepancyRecs ?>)</a>
                <a class="button button-primary" href="#add-rec" style="padding:.2rem .6rem;font-size:.78rem">＋ New Audit Log</a>
            </div>
        </div>
        <div class="table-wrap"><table>
            <thead>
                <tr>
                    <th>Rec # / Date</th>
                    <th>Manual Register Ref</th>
                    <th>Digital Module & Ref</th>
                    <th>Register vs System Qty</th>
                    <th>Variance</th>
                    <th>Status / Auditor</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($reconciliations)): ?>
            <tr><td colspan="6" class="muted" style="text-align:center;padding:2.5rem">No reconciliation records found for this view. Use the form to audit physical registers against digital entries.</td></tr>
            <?php else: foreach ($reconciliations as $r): ?>
            <tr>
                <td>
                    <b><?= e($r['rec_number']) ?></b><br>
                    <small class="muted"><?= e(date('d M Y', strtotime($r['verified_date']))) ?></small>
                </td>
                <td>
                    <b><?= e($r['register_name']) ?></b><br>
                    <small class="muted">Page: <b><?= e($r['page_no']) ?></b> · Entry / Sr: <b><?= e($r['entry_no']) ?></b></small>
                </td>
                <td>
                    <b><?= e($r['transaction_type']) ?></b><br>
                    <code><?= e($r['transaction_ref']) ?></code>
                </td>
                <td>
                    Manual: <b><?= e($r['register_qty']) ?></b> units<br>
                    System: <b><?= e($r['system_qty']) ?></b> units
                </td>
                <td>
                    <?php if ($r['difference'] == 0): ?>
                        <span style="color:#166534;font-weight:700">0 (Exact Match)</span>
                    <?php elseif ($r['difference'] > 0): ?>
                        <span style="color:#b45309;font-weight:700">+<?= e($r['difference']) ?> (Physical Excess)</span>
                    <?php else: ?>
                        <span style="color:#b91c1c;font-weight:700"><?= e($r['difference']) ?> (Physical Shortage)</span>
                    <?php endif; ?>
                    <?php if (!empty($r['reason'])): ?>
                        <br><small class="muted"><?= e($r['reason']) ?></small>
                    <?php endif; ?>
                </td>
                <td>
                    <?= pill($r['status']) ?><br>
                    <small class="muted">By: <?= e($r['verifier_name'] ?? 'Auditor') ?></small>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>

    <div class="form-card" id="add-rec">
        <h2>Reconcile Physical Register</h2>
        <p class="muted" style="margin-bottom:1rem">Cross-reference physical paper register pages and entries with digital transaction logs to certify synchronization.</p>
        <form method="post">
            <input type="hidden" name="action" value="reconcile_register">
            <div class="form-grid one">
                <label>Physical Register Name
                    <select name="register_name" required>
                        <option value="Store GRN Inward Register">Store GRN Inward Register</option>
                        <option value="Store Purchase Order Register">Store Purchase Order Register</option>
                        <option value="Store Requisition / Indent Issue Register">Store Requisition / Indent Issue Register</option>
                        <option value="OPD Daily Dispensing Register">OPD Daily Dispensing Register</option>
                        <option value="OT Surgery Consumption Register">OT Surgery Consumption Register</option>
                        <option value="IPD Ward Stock Register">IPD Ward Stock Register</option>
                        <option value="Emergency Rapid Dispensing Register">Emergency Rapid Dispensing Register</option>
                        <option value="Store Return Register">Store Return Register</option>
                        <option value="Store Adjustment Register">Store Adjustment Register</option>
                    </select>
                </label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                    <label>Page No.<input name="page_no" placeholder="e.g. 84" required></label>
                    <label>Entry / Sr No.<input name="entry_no" placeholder="e.g. 19" required></label>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                    <label>Transaction Module
                        <select name="transaction_type" required>
                            <option value="GRN Receipt">GRN Receipt</option>
                            <option value="Store Issue">Store Issue</option>
                            <option value="OPD Dispensing">OPD Dispensing</option>
                            <option value="OT Eye Surgery">OT Eye Surgery</option>
                            <option value="IPD Ward Usage">IPD Ward Usage</option>
                            <option value="Emergency Dispense">Emergency Dispense</option>
                            <option value="Department Return">Department Return</option>
                            <option value="Stock Adjustment">Stock Adjustment</option>
                        </select>
                    </label>
                    <label>Digital Ref No.
                        <input name="transaction_ref" placeholder="e.g. GRN-2026-0038" required>
                    </label>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                    <label>Physical Register Quantity<input name="register_qty" type="number" min="0" value="10" required></label>
                    <label>System Recorded Quantity<input name="system_qty" type="number" min="0" value="10" required></label>
                </div>
                <label>Discrepancy Explanation (if quantities differ)
                    <input name="reason" placeholder="e.g. Transcription error on physical folio">
                </label>
                <label>Corrective Action / Resolution
                    <input name="corrective_action" placeholder="e.g. Corrected manual book or created adjustment">
                </label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                    <label>Audit Date<input name="verified_date" type="date" value="<?= date('Y-m-d') ?>" required></label>
                    <label>Auditor / Incharge<input value="<?= e(current_user()['name'] ?? 'Internal Auditor') ?>" readonly></label>
                </div>
            </div>
            <button class="button button-primary" type="submit" style="margin-top:1.2rem;width:100%">Certify & Save Reconciliation</button>
        </form>
    </div>
</div>

<?php /* ════════════════════ DELETIONS ═════════════════════════════════════ */ ?>
<?php elseif ($view === 'deletions'): ?>
<?php
$delFilter = $_GET['filter'] ?? 'pending';
$sql = '
    SELECT d.*, u.name AS requester_name, ru.name AS reviewer_name
    FROM deletion_requests d
    LEFT JOIN users u ON u.id = d.requested_by
    LEFT JOIN users ru ON ru.id = d.reviewed_by
';
if ($delFilter === 'pending') {
    $sql .= ' WHERE d.status = "Pending"';
} elseif ($delFilter === 'approved') {
    $sql .= ' WHERE d.status = "Approved"';
} elseif ($delFilter === 'rejected') {
    $sql .= ' WHERE d.status = "Rejected"';
}
$sql .= ' ORDER BY d.id DESC LIMIT 100';
$deletions = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$pendingCount = (int)$pdo->query('SELECT COUNT(*) FROM deletion_requests WHERE status="Pending"')->fetchColumn();
$approvedCount = (int)$pdo->query('SELECT COUNT(*) FROM deletion_requests WHERE status="Approved"')->fetchColumn();
$rejectedCount = (int)$pdo->query('SELECT COUNT(*) FROM deletion_requests WHERE status="Rejected"')->fetchColumn();
$totalDeletions = (int)$pdo->query('SELECT COUNT(*) FROM deletion_requests')->fetchColumn();
$isDDFinance = can('dd_finance') || can('competent_authority') || (current_user()['role'] ?? '') === 'admin';
?>

<div class="kpi-grid four" style="margin-bottom:1.5rem">
    <div class="kpi-card" style="border-left:4px solid #f59e0b">
        <p class="kpi-label">Pending DD Finance Review</p>
        <p class="kpi-val" style="color:#b45309"><?= $pendingCount ?></p>
        <p class="kpi-sub">Awaiting executive authorization</p>
    </div>
    <div class="kpi-card" style="border-left:4px solid #10b981">
        <p class="kpi-label">Approved & Stock Reversed</p>
        <p class="kpi-val" style="color:#047857"><?= $approvedCount ?></p>
        <p class="kpi-sub">Double-entry ledger rollbacks</p>
    </div>
    <div class="kpi-card">
        <p class="kpi-label">Rejected Requests</p>
        <p class="kpi-val"><?= $rejectedCount ?></p>
        <p class="kpi-sub">Disapproved deletion requests</p>
    </div>
    <div class="kpi-card">
        <p class="kpi-label">Governance Authority</p>
        <p class="kpi-val" style="font-size:1.05rem;color:#166534">DD Finance Exclusive</p>
        <p class="kpi-sub">Strict Sindh Govt audit compliance</p>
    </div>
</div>

<div class="system-grid">
    <div class="panel">
        <div class="panel-heading" style="flex-wrap:wrap;gap:.5rem">
            <h2>Deletion & Stock Reversal Authorization Queue</h2>
            <div style="display:flex;gap:.3rem;align-items:center">
                <a class="button <?= $delFilter==='pending'?'button-primary':'button-light' ?>" href="<?= page_link('deletions') ?>&filter=pending" style="padding:.2rem .6rem;font-size:.78rem">Pending (<?= $pendingCount ?>)</a>
                <a class="button <?= $delFilter==='approved'?'button-primary':'button-light' ?>" href="<?= page_link('deletions') ?>&filter=approved" style="padding:.2rem .6rem;font-size:.78rem">✓ Approved (<?= $approvedCount ?>)</a>
                <a class="button <?= $delFilter==='rejected'?'button-primary':'button-light' ?>" href="<?= page_link('deletions') ?>&filter=rejected" style="padding:.2rem .6rem;font-size:.78rem">✕ Rejected (<?= $rejectedCount ?>)</a>
                <a class="button <?= $delFilter==='all'?'button-primary':'button-light' ?>" href="<?= page_link('deletions') ?>&filter=all" style="padding:.2rem .6rem;font-size:.78rem">All (<?= $totalDeletions ?>)</a>
            </div>
        </div>
        <div class="table-wrap"><table>
            <thead>
                <tr>
                    <th>Request # / Date</th>
                    <th>Target Record & Section</th>
                    <th>Qty / Value</th>
                    <th>Reason / Requested By</th>
                    <th>Status / Audit Trail</th>
                    <th>DD Finance Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($deletions)): ?>
            <tr><td colspan="6" class="muted" style="text-align:center;padding:2.5rem">No deletion requests matching this filter criteria.</td></tr>
            <?php else: foreach ($deletions as $d): ?>
            <tr>
                <td>
                    <b><?= e($d['request_no']) ?></b><br>
                    <small class="muted"><?= e(date('d M Y, h:i A', strtotime($d['requested_at']))) ?></small>
                </td>
                <td>
                    <b><?= e($d['section']) ?></b><br>
                    Ref: <code><?= e($d['reference_no']) ?></code> (Table: <?= e($d['table_name']) ?> #<?= $d['record_id'] ?>)<br>
                    <span class="muted" style="font-size:.8rem"><?= e($d['description']) ?></span>
                </td>
                <td>
                    <b><?= e($d['quantity']) ?></b> units<br>
                    <?= $d['value'] > 0 ? 'Rs. ' . number_format((float)$d['value'], 2) : '—' ?>
                </td>
                <td>
                    <b><?= e($d['reason']) ?></b><br>
                    <small class="muted">By: <?= e($d['requester_name'] ?? 'Staff') ?></small>
                    <?php if (!empty($d['supporting_doc'])): ?>
                        <div style="margin-top:.2rem">
                            <a href="<?= e($d['supporting_doc']) ?>" download style="font-size:.75rem;color:#047857">📎 Supporting Memo</a>
                        </div>
                    <?php endif; ?>
                </td>
                <td>
                    <?= pill($d['status']) ?><br>
                    <?php if ($d['status'] === 'Approved'): ?>
                        <small style="color:#047857">✓ Stock Reversed by <?= e($d['reviewer_name'] ?? 'DD Finance') ?><br><?= e($d['review_remarks']) ?></small>
                    <?php elseif ($d['status'] === 'Rejected'): ?>
                        <small style="color:#b91c1c">✕ Rejected by <?= e($d['reviewer_name'] ?? 'DD Finance') ?><br><?= e($d['review_remarks']) ?></small>
                    <?php else: ?>
                        <small class="muted">Awaiting DD Finance decision</small>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($d['status'] === 'Pending' && $isDDFinance): ?>
                        <form method="post" style="display:flex;flex-direction:column;gap:.3rem">
                            <input type="hidden" name="request_id" value="<?= $d['id'] ?>">
                            <input name="remarks" placeholder="Audit authorization note" style="font-size:.75rem;padding:.2rem .4rem" required>
                            <div style="display:flex;gap:.3rem">
                                <button class="button button-primary" name="action" value="approve_deletion" type="submit" style="font-size:.75rem;padding:.2rem .4rem;background:#047857;border-color:#065f46" onclick="return confirm('Authorize deletion and reverse stock balances in the ledger?')">✓ Approve & Reverse</button>
                                <button class="button button-light" name="action" value="reject_deletion" type="submit" style="font-size:.75rem;padding:.2rem .4rem;color:#b91c1c" onclick="return confirm('Reject this deletion request?')">✕ Reject</button>
                            </div>
                        </form>
                    <?php elseif ($d['status'] === 'Pending'): ?>
                        <span class="muted" style="font-size:.8rem">Requires DD Finance role</span>
                    <?php else: ?>
                        <span class="muted" style="font-size:.8rem">Completed</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>

    <?php if ((current_user()['role'] ?? '') !== 'dd_finance'): ?>
    <div class="form-card">
        <h2>Submit Deletion Request</h2>
        <p class="muted" style="margin-bottom:1rem">No records can be permanently deleted directly. All deletion requests must be submitted with justification for DD Finance authorization.</p>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="submit_deletion_request">
            <div class="form-grid one">
                <label>Section / Category
                    <select name="section" required>
                        <option value="OPD Patient Consumption">OPD Patient Consumption</option>
                        <option value="OT Surgery Consumption">OT Surgery Consumption</option>
                        <option value="IPD Ward Usage">IPD Ward Usage</option>
                        <option value="Emergency Rapid Consumption">Emergency Rapid Consumption</option>
                        <option value="Department Requisition">Department Requisition</option>
                        <option value="Goods Received Note (GRN)">Goods Received Note (GRN)</option>
                        <option value="Stock Adjustment">Stock Adjustment</option>
                    </select>
                </label>
                <label>Database Table
                    <select name="table_name" required>
                        <option value="consumption_records">consumption_records</option>
                        <option value="requisitions">requisitions</option>
                        <option value="grns">grns</option>
                        <option value="stock_adjustments">stock_adjustments</option>
                    </select>
                </label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                    <label>Record ID<input name="record_id" type="number" min="1" placeholder="e.g. 42" required></label>
                    <label>Reference No.<input name="reference_no" placeholder="e.g. OPD-2026-101" required></label>
                </div>
                <label>Item / Record Description
                    <input name="description" placeholder="e.g. 5 vials Moxifloxacin entered under wrong MR" required>
                </label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                    <label>Quantity to Reverse<input name="quantity" type="number" min="1" value="1" required></label>
                    <label>Financial Value (Rs.)<input name="value" type="number" step="0.01" min="0" value="0.00"></label>
                </div>
                <label>Reason for Deletion Request
                    <textarea name="reason" rows="3" placeholder="Explain why this entry is incorrect and requires removal..." required></textarea>
                </label>
                <label>Attach Supporting Office Memo / Doctor Note (Optional)
                    <input type="file" name="supporting_doc" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                </label>
            </div>
            <button class="button button-primary" type="submit" style="margin-top:1.2rem;width:100%">Submit for DD Finance Review</button>
        </form>
    </div>
    <?php endif; ?>
</div>

<?php /* ════════════════════ REPORTS ════════════════════════════════════════ */ ?>
<?php else: ?>
<?php
$isPOUser = ((current_user()['role'] ?? '') === 'purchase_officer');
$isDDFinanceUser = ((current_user()['role'] ?? '') === 'dd_finance');
if ($isPOUser) {
    $rtype = $_GET['rtype'] ?? 'purchase';
    $reportTabs = [
        'purchase'       => 'PO & Vendor Fulfillment Audit',
        'stock'          => 'Store Stock Movement Ledger',
        'reconciliation' => 'Manual Register Sync Certificate',
    ];
} elseif ($isDDFinanceUser) {
    $rtype = $_GET['rtype'] ?? 'valuation';
    $reportTabs = [
        'valuation'      => 'Inventory & Asset Valuation',
        'purchase'       => 'PO Commitments & Fulfillment',
        'assets'         => 'Fixed Assets & Capitalization',
        'condemnations'  => 'Dead Stock & Condemnations',
        'stock'          => 'Stock Ledger Audit',
        'reconciliation' => 'Manual Register Sync Certificate',
        'expiry'         => 'Expiry & Risk Buckets',
    ];
} else {
    $rtype = $_GET['rtype'] ?? 'stock';
    $reportTabs = [
        'stock'          => 'Stock Ledger Audit',
        'valuation'      => 'Inventory & Asset Valuation',
        'purchase'       => 'PO & Vendor Fulfillment Audit',
        'expiry'         => 'Expiry & Risk Buckets',
        'consumption'    => 'Patient Consumption Audit',
        'assets'         => 'Fixed Assets & Maintenance',
        'condemnations'  => 'Dead Stock & Condemnations',
        'reconciliation' => 'Manual Register Sync Certificate',
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// REPORT: Procurement & PO vs GRN Fulfillment Audit
// ─────────────────────────────────────────────────────────────────────────────
if ($rtype === 'purchase') {
    $from = $_GET['from_date'] ?? date('Y-01-01');
    $to   = $_GET['to_date'] ?? date('Y-m-d');
    $selSupp = !empty($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : null;
    $selStatus = !empty($_GET['po_status']) ? trim($_GET['po_status']) : null;

    $w = ['po.po_date BETWEEN ? AND ?'];
    $p = [$from, $to];
    if ($selSupp) { $w[] = 'po.supplier_id = ?'; $p[] = $selSupp; }
    if ($selStatus) { $w[] = 'po.status = ?'; $p[] = $selStatus; }

    $sql = 'SELECT po.id AS po_id, po.po_number, po.po_date, po.expected_delivery, po.status AS po_status,
                   po.total_value AS po_total_value, po.purchase_reference,
                   s.name AS supplier_name, s.code AS supplier_code, s.phone AS supplier_phone,
                   pi.item_id, pi.quantity_ordered, pi.unit, pi.rate, pi.total_value AS item_value,
                   i.code AS item_code, i.name AS item_name,
                   COALESCE((
                       SELECT SUM(gi.quantity_received)
                       FROM grn_items gi
                       JOIN grns g ON g.id = gi.grn_id
                       WHERE g.po_id = po.id AND gi.item_id = pi.item_id
                   ), 0) AS total_received,
                   COALESCE((
                       SELECT SUM(gi.quantity_accepted)
                       FROM grn_items gi
                       JOIN grns g ON g.id = gi.grn_id
                       WHERE g.po_id = po.id AND gi.item_id = pi.item_id
                   ), 0) AS total_accepted,
                   COALESCE((
                       SELECT SUM(gi.quantity_rejected)
                       FROM grn_items gi
                       JOIN grns g ON g.id = gi.grn_id
                       WHERE g.po_id = po.id AND gi.item_id = pi.item_id
                   ), 0) AS total_rejected,
                   (
                       SELECT GROUP_CONCAT(DISTINCT g.grn_number)
                       FROM grns g
                       WHERE g.po_id = po.id
                   ) AS linked_grns
            FROM purchase_orders po
            JOIN suppliers s ON s.id = po.supplier_id
            JOIN po_items pi ON pi.po_id = po.id
            JOIN items i ON i.id = pi.item_id
            WHERE ' . implode(' AND ', $w) . '
            ORDER BY po.id DESC, pi.id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($p);
    $poReportRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalLines = count($poReportRows);
    $distinctPOs = count(array_unique(array_column($poReportRows, 'po_id')));
    $sumOrderedQty = (int)array_sum(array_column($poReportRows, 'quantity_ordered'));
    $sumAcceptedQty = (int)array_sum(array_column($poReportRows, 'total_accepted'));
    $sumRejectedQty = (int)array_sum(array_column($poReportRows, 'total_rejected'));
    $sumCommittedVal = (float)array_sum(array_column($poReportRows, 'item_value'));
    $rateFulfillment = $sumOrderedQty > 0 ? min(100, round(($sumAcceptedQty / $sumOrderedQty) * 100, 1)) : 100.0;
}

// ─────────────────────────────────────────────────────────────────────────────
// REPORT 1: Stock Ledger Audit
// ─────────────────────────────────────────────────────────────────────────────
elseif ($rtype === 'stock') {
    $from = $_GET['from_date'] ?? date('Y-m-01');
    $to   = $_GET['to_date'] ?? date('Y-m-d');
    $selItem = !empty($_GET['item_id']) ? (int)$_GET['item_id'] : null;
    $selDept = !empty($_GET['dept_id']) ? (int)$_GET['dept_id'] : null;

    $w = ['date(sl.created_at) BETWEEN ? AND ?'];
    $p = [$from, $to];
    if ($selItem) { $w[] = 'sl.item_id = ?'; $p[] = $selItem; }
    if ($selDept) { $w[] = '(sl.source_id = ? OR sl.dest_id = ?)'; $p[] = $selDept; $p[] = $selDept; }

    $sql = 'SELECT sl.*, i.code AS icode, i.name AS iname, i.unit, u.name AS uname
            FROM stock_ledger sl
            JOIN items i ON i.id = sl.item_id
            LEFT JOIN users u ON u.id = sl.user_id
            WHERE ' . implode(' AND ', $w) . '
            ORDER BY sl.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($p);
    $stockRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalMovements = count($stockRows);
    $inTypes = ['GRN_RECEIPT', 'ADJUSTMENT_IN', 'DEPT_RETURN'];
    $outTypes = ['STORE_ISSUE', 'DEPT_CONSUMPTION', 'ADJUSTMENT_OUT'];
    $totalIn = array_sum(array_map(fn($r) => in_array($r['transaction_type'], $inTypes, true) ? (int)$r['quantity'] : 0, $stockRows));
    $totalOut = array_sum(array_map(fn($r) => in_array($r['transaction_type'], $outTypes, true) ? (int)$r['quantity'] : 0, $stockRows));
}

// ─────────────────────────────────────────────────────────────────────────────
// REPORT 2: Expiry & Risk Buckets
// ─────────────────────────────────────────────────────────────────────────────
elseif ($rtype === 'expiry') {
    $sql = 'SELECT ib.*, i.code AS icode, i.name AS iname, i.category, i.unit,
                   CAST(julianday(ib.expiry_date) - julianday("now") AS INTEGER) AS days_left
            FROM item_batches ib
            JOIN items i ON i.id = ib.item_id
            WHERE ib.quantity > 0
            ORDER BY ib.expiry_date ASC';
    $batchRows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $expiredRows = [];
    $criticalRows = [];
    $warningRows = [];
    $attentionRows = [];
    $safeRows = [];

    $expiredVal = 0.0;
    $criticalVal = 0.0;
    $totalRiskVal = 0.0;

    foreach ($batchRows as $b) {
        $days = (int)$b['days_left'];
        $val = (float)$b['quantity'] * (float)($b['unit_cost'] ?? 0);
        if ($days <= 0) {
            $expiredRows[] = $b;
            $expiredVal += $val;
            $totalRiskVal += $val;
        } elseif ($days <= 30) {
            $criticalRows[] = $b;
            $criticalVal += $val;
            $totalRiskVal += $val;
        } elseif ($days <= 60) {
            $warningRows[] = $b;
            $totalRiskVal += $val;
        } elseif ($days <= 90) {
            $attentionRows[] = $b;
        } else {
            $safeRows[] = $b;
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// REPORT 3: Patient Consumption Audit
// ─────────────────────────────────────────────────────────────────────────────
elseif ($rtype === 'consumption') {
    $subType = $_GET['ctype'] ?? 'all';
    $w = [];
    $p = [];
    if ($subType !== 'all') {
        $w[] = 'cr.type = ?';
        $p[] = $subType;
    }
    $sql = 'SELECT cr.*, d.name AS dname, i.code AS icode, i.name AS iname, i.unit, u.name AS uname
            FROM consumption_records cr
            LEFT JOIN departments d ON d.id = cr.department_id
            LEFT JOIN items i ON i.id = cr.item_id
            LEFT JOIN users u ON u.id = cr.posted_by';
    if ($w) $sql .= ' WHERE ' . implode(' AND ', $w);
    $sql .= ' ORDER BY cr.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($p);
    $consRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalConsEpisodes = count($consRows);
    $totalConsUnits = (int)array_sum(array_column($consRows, 'quantity'));
    $opdConsCount = (int)$pdo->query('SELECT COUNT(*) FROM consumption_records WHERE type="OPD"')->fetchColumn();
    $otConsCount  = (int)$pdo->query('SELECT COUNT(*) FROM consumption_records WHERE type="OT"')->fetchColumn();
    $ipdConsCount = (int)$pdo->query('SELECT COUNT(*) FROM consumption_records WHERE type="IPD"')->fetchColumn();
    $emgConsCount = (int)$pdo->query('SELECT COUNT(*) FROM consumption_records WHERE type="Emergency"')->fetchColumn();
}

// ─────────────────────────────────────────────────────────────────────────────
// REPORT 4: Fixed Assets & Maintenance
// ─────────────────────────────────────────────────────────────────────────────
elseif ($rtype === 'assets') {
    $sql = 'SELECT a.*,
                   (SELECT COALESCE(SUM(total_cost),0) FROM asset_maintenance WHERE asset_id = a.id) AS total_mnt_cost,
                   (SELECT COUNT(*) FROM asset_maintenance WHERE asset_id = a.id) AS mnt_count
            FROM assets a
            ORDER BY a.department, a.name';
    $assetReportRows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $totalAssetVal = (float)array_sum(array_column($assetReportRows, 'cost'));
    $totalMntCost  = (float)array_sum(array_column($assetReportRows, 'total_mnt_cost'));
}

// ─────────────────────────────────────────────────────────────────────────────
// REPORT 5: Dead Stock & Condemnations
// ─────────────────────────────────────────────────────────────────────────────
elseif ($rtype === 'condemnations') {
    $sql = 'SELECT c.*, a.cost AS original_cost, u.name AS uname
            FROM condemnations c
            LEFT JOIN assets a ON a.id = c.asset_id
            LEFT JOIN users u ON u.id = c.created_by
            ORDER BY c.id DESC';
    $condemnReportRows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $totalScrapEst = (float)array_sum(array_column($condemnReportRows, 'estimated_scrap_val'));
    $totalRecovered = (float)array_sum(array_column($condemnReportRows, 'recovered_amount'));
    $disposedCount = count(array_filter($condemnReportRows, fn($r) => $r['status'] === 'Disposed/Auctioned'));
}

// ─────────────────────────────────────────────────────────────────────────────
// REPORT 6: Manual Register Sync Certificate
// ─────────────────────────────────────────────────────────────────────────────
elseif ($rtype === 'reconciliation') {
    $sql = 'SELECT mr.*, u.name AS uname
            FROM manual_reconciliations mr
            LEFT JOIN users u ON u.id = mr.verified_by
            ORDER BY mr.id DESC';
    $reconReportRows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $totalRecon = count($reconReportRows);
    $matchedRecon = count(array_filter($reconReportRows, fn($r) => ($r['status'] ?? '') === 'Matched' || (int)($r['difference'] ?? 1) === 0));
    $discrepancyRecon = count(array_filter($reconReportRows, fn($r) => ($r['status'] ?? '') === 'Discrepancy' || (int)($r['difference'] ?? 0) !== 0));
    $syncHealth = $totalRecon > 0 ? round(($matchedRecon / $totalRecon) * 100, 1) : 100.0;
}

// ─────────────────────────────────────────────────────────────────────────────
// REPORT 7: Hospital Inventory & Capital Asset Valuation
// ─────────────────────────────────────────────────────────────────────────────
elseif ($rtype === 'valuation') {
    $catFilter = $_GET['cat'] ?? 'all';
    $statusFilter = $_GET['stock_status'] ?? 'all';

    $w = [];
    $p = [];
    if ($catFilter !== 'all') { $w[] = 'i.category = ?'; $p[] = $catFilter; }
    if ($statusFilter === 'out') { $w[] = 'i.quantity = 0'; }
    elseif ($statusFilter === 'low') { $w[] = 'i.quantity > 0 AND i.quantity <= i.reorder_level'; }
    elseif ($statusFilter === 'ok') { $w[] = 'i.quantity > i.reorder_level'; }

    $sql = "SELECT i.*,
                   COALESCE((SELECT g.rate FROM grn_items g WHERE g.item_id = i.id AND g.rate > 0 ORDER BY g.id DESC LIMIT 1), 0) AS unit_rate
            FROM items i";
    if ($w) $sql .= ' WHERE ' . implode(' AND ', $w);
    $sql .= ' ORDER BY (i.quantity * COALESCE((SELECT g.rate FROM grn_items g WHERE g.item_id = i.id AND g.rate > 0 ORDER BY g.id DESC LIMIT 1), 0)) DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($p);
    $valuationRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Institutional Financial Aggregates
    $totalStoreVal = (float)$pdo->query(
        "SELECT COALESCE(SUM(i.quantity * COALESCE(
            (SELECT g.rate FROM grn_items g WHERE g.item_id = i.id AND g.rate > 0 ORDER BY g.id DESC LIMIT 1), 0
        )), 0) FROM items i"
    )->fetchColumn();
    $totalAssetVal = (float)$pdo->query("SELECT COALESCE(SUM(cost),0) FROM assets WHERE status != 'Condemned'")->fetchColumn();
    $totalPOCommittedVal = (float)$pdo->query("SELECT COALESCE(SUM(total_value),0) FROM purchase_orders WHERE status IN ('Approved', 'Partially Received')")->fetchColumn();
    $grandTotalVal = $totalStoreVal + $totalAssetVal + $totalPOCommittedVal;

    $filteredStoreVal = (float)array_sum(array_map(fn($r) => (float)$r['quantity'] * (float)$r['unit_rate'], $valuationRows));
    $categoriesAll = $pdo->query('SELECT DISTINCT category FROM items WHERE category IS NOT NULL AND category != "" ORDER BY category')->fetchAll(PDO::FETCH_COLUMN);
}
?>

<div class="report-tabs" style="margin-bottom:1.5rem">
    <?php foreach ($reportTabs as $k => $label): ?>
    <a class="tab-link <?= $rtype === $k ? 'active' : '' ?>" href="?view=reports&rtype=<?= $k ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<div class="panel">
    <div class="panel-heading" style="flex-wrap:wrap;gap:.5rem">
        <div>
            <h2><?= e($reportTabs[$rtype] ?? 'Audit Report') ?></h2>
            <small class="muted">Sindh Institute of Ophthalmology &amp; Visual Sciences (SIOVS), Hyderabad</small>
        </div>
        <div style="display:flex;gap:.4rem;align-items:center">
            <a class="button button-light" href="?view=reports&rtype=<?= $rtype ?>&export=csv" style="padding:.25rem .6rem;font-size:.78rem">📥 Export CSV</a>
            <button class="button button-light" onclick="window.print()" style="padding:.25rem .6rem;font-size:.78rem">🖨 Print / PDF</button>
        </div>
    </div>

    <?php /* ═══════════════ TAB: PO & VENDOR FULFILLMENT AUDIT ═══════════════ */ ?>
    <?php if ($rtype === 'purchase'): ?>
    <div style="padding:1rem 1.25rem;background:var(--surface-sunken,#f8fafc);border-bottom:1px solid var(--border-color,#e2e8f0)">
        <form method="get" style="display:flex;flex-wrap:wrap;gap:.6rem;align-items:flex-end">
            <input type="hidden" name="view" value="reports">
            <input type="hidden" name="rtype" value="purchase">
            <div>
                <small class="muted" style="display:block;margin-bottom:.2rem">From Date</small>
                <input name="from_date" type="date" value="<?= e($from) ?>" style="font-size:.8rem;padding:.25rem .45rem">
            </div>
            <div>
                <small class="muted" style="display:block;margin-bottom:.2rem">To Date</small>
                <input name="to_date" type="date" value="<?= e($to) ?>" style="font-size:.8rem;padding:.25rem .45rem">
            </div>
            <div>
                <small class="muted" style="display:block;margin-bottom:.2rem">Filter Supplier</small>
                <select name="supplier_id" style="font-size:.8rem;padding:.25rem .45rem">
                    <option value="">All Suppliers</option>
                    <?php foreach ($supp_all as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $selSupp === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['code']) ?> — <?= e($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <small class="muted" style="display:block;margin-bottom:.2rem">PO Status</small>
                <select name="po_status" style="font-size:.8rem;padding:.25rem .45rem">
                    <option value="">All Statuses</option>
                    <option value="Draft" <?= $selStatus === 'Draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="Approved" <?= $selStatus === 'Approved' ? 'selected' : '' ?>>Approved</option>
                    <option value="Partially Received" <?= $selStatus === 'Partially Received' ? 'selected' : '' ?>>Partially Received</option>
                    <option value="Delivered" <?= $selStatus === 'Delivered' ? 'selected' : '' ?>>Delivered</option>
                    <option value="Cancelled" <?= $selStatus === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
            <button class="button button-primary" type="submit" style="font-size:.8rem;padding:.3rem .7rem">Apply Filter</button>
        </form>
    </div>

    <div class="kpi-grid four" style="display:grid!important;grid-template-columns:repeat(4,1fr)!important;gap:14px!important;margin:1rem 1.25rem!important">
        <div class="kpi-card" style="min-height:auto!important;background:#ffffff!important;border:1px solid #dcebe5!important;border-left:4px solid #2563eb!important;border-radius:10px!important;padding:14px 18px!important;box-shadow:0 2px 8px rgba(0,0,0,0.06)!important">
            <p class="kpi-label" style="color:#1e293b!important;font-size:11px!important;font-weight:700!important;text-transform:uppercase!important;letter-spacing:0.5px!important;margin:0 0 6px 0!important;opacity:1!important">Purchase Orders</p>
            <p class="kpi-val" style="font-size:24px!important;font-weight:700!important;color:#0f172a!important;margin:0 0 4px 0!important"><?= number_format($distinctPOs) ?></p>
            <p class="kpi-sub" style="font-size:11px!important;color:#64748b!important;margin:0!important"><?= $totalLines ?> line items ordered</p>
        </div>
        <div class="kpi-card" style="min-height:auto!important;background:#ffffff!important;border:1px solid #dcebe5!important;border-left:4px solid #10b981!important;border-radius:10px!important;padding:14px 18px!important;box-shadow:0 2px 8px rgba(0,0,0,0.06)!important">
            <p class="kpi-label" style="color:#1e293b!important;font-size:11px!important;font-weight:700!important;text-transform:uppercase!important;letter-spacing:0.5px!important;margin:0 0 6px 0!important;opacity:1!important">Committed Order Value</p>
            <p class="kpi-val" style="font-size:22px!important;font-weight:700!important;color:#047857!important;margin:0 0 4px 0!important">Rs. <?= number_format($sumCommittedVal, 0) ?></p>
            <p class="kpi-sub" style="font-size:11px!important;color:#64748b!important;margin:0!important">Procurement financial commitment</p>
        </div>
        <div class="kpi-card" style="min-height:auto!important;background:#ffffff!important;border:1px solid #dcebe5!important;border-left:4px solid #f59e0b!important;border-radius:10px!important;padding:14px 18px!important;box-shadow:0 2px 8px rgba(0,0,0,0.06)!important">
            <p class="kpi-label" style="color:#1e293b!important;font-size:11px!important;font-weight:700!important;text-transform:uppercase!important;letter-spacing:0.5px!important;margin:0 0 6px 0!important;opacity:1!important">Store Accepted Units</p>
            <p class="kpi-val" style="font-size:24px!important;font-weight:700!important;color:#b45309!important;margin:0 0 4px 0!important">+<?= number_format($sumAcceptedQty) ?> / <?= number_format($sumOrderedQty) ?></p>
            <p class="kpi-sub" style="font-size:11px!important;color:#64748b!important;margin:0!important"><?= number_format(max(0, $sumOrderedQty - $sumAcceptedQty)) ?> units pending delivery</p>
        </div>
        <div class="kpi-card" style="min-height:auto!important;background:#ffffff!important;border:1px solid #dcebe5!important;border-left:4px solid #218b68!important;border-radius:10px!important;padding:14px 18px!important;box-shadow:0 2px 8px rgba(0,0,0,0.06)!important">
            <p class="kpi-label" style="color:#1e293b!important;font-size:11px!important;font-weight:700!important;text-transform:uppercase!important;letter-spacing:0.5px!important;margin:0 0 6px 0!important;opacity:1!important">Overall Fulfillment</p>
            <p class="kpi-val" style="font-size:24px!important;font-weight:700!important;color:var(--brand-primary,#00897b)!important;margin:0 0 4px 0!important"><?= $rateFulfillment ?>%</p>
            <p class="kpi-sub" style="font-size:11px!important;color:#64748b!important;margin:0!important"><?= $sumRejectedQty > 0 ? "{$sumRejectedQty} units rejected" : 'Zero rejections' ?></p>
        </div>
    </div>

    <div class="table-wrap"><table>
        <thead>
            <tr>
                <th>PO Number / Date</th>
                <th>Supplier</th>
                <th>Item Ordered</th>
                <th>Qty Ordered</th>
                <th>Store Received (GRN)</th>
                <th>Accepted (Stock)</th>
                <th>Rejected</th>
                <th>Pending Shortage</th>
                <th>Rate (Rs.)</th>
                <th>Total Value</th>
                <th>Fulfillment Status</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($poReportRows)): ?>
        <tr><td colspan="11" class="muted" style="text-align:center;padding:2rem">No purchase order records found matching the filter criteria.</td></tr>
        <?php else: foreach ($poReportRows as $r): ?>
        <?php
        $qtyOrd = (int)$r['quantity_ordered'];
        $qtyRec = (int)$r['total_received'];
        $qtyAcc = (int)$r['total_accepted'];
        $qtyRej = (int)$r['total_rejected'];
        $shortage = max(0, $qtyOrd - $qtyAcc);
        $isOverdue = (!empty($r['expected_delivery']) && $r['expected_delivery'] < date('Y-m-d') && in_array($r['po_status'], ['Approved', 'Partially Received'], true));
        ?>
        <tr>
            <td>
                <b><?= e($r['po_number']) ?></b><br>
                <small class="muted"><?= e(date('d M Y', strtotime($r['po_date']))) ?></small>
                <?php if ($isOverdue): ?><br><span class="badge" style="background:#fee2e2;color:#991b1b;font-size:9px;padding:1px 4px;border-radius:3px">OVERDUE</span><?php endif; ?>
            </td>
            <td>
                <b><?= e($r['supplier_name']) ?></b><br>
                <small class="muted"><?= e($r['supplier_code']) ?></small>
            </td>
            <td>
                <b><?= e($r['item_code']) ?></b><br>
                <span><?= e($r['item_name']) ?></span>
            </td>
            <td><b><?= $qtyOrd ?></b> <?= e($r['unit']) ?></td>
            <td><?= $qtyRec ?> <?= e($r['unit']) ?></td>
            <td style="color:#15803d;font-weight:700">+<?= $qtyAcc ?> <?= e($r['unit']) ?></td>
            <td style="color:<?= $qtyRej > 0 ? '#dc2626' : '#64748b' ?>;font-weight:<?= $qtyRej > 0 ? '700' : 'normal' ?>"><?= $qtyRej ?></td>
            <td style="color:<?= $shortage > 0 ? '#ea580c' : '#15803d' ?>;font-weight:700">
                <?= $shortage > 0 ? "-{$shortage}" : '0' ?>
            </td>
            <td>Rs. <?= number_format((float)$r['rate'], 2) ?></td>
            <td><b>Rs. <?= number_format((float)$r['item_value'], 2) ?></b></td>
            <td>
                <?php if ($qtyAcc >= $qtyOrd && $qtyOrd > 0): ?>
                    <span class="pill good">100% Fulfilled</span>
                <?php elseif ($qtyAcc > 0): ?>
                    <span class="pill attention">Partially Received</span>
                <?php elseif ($r['po_status'] === 'Approved'): ?>
                    <span class="pill neutral" style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe">In-Transit</span>
                <?php else: ?>
                    <?= pill($r['po_status']) ?>
                <?php endif; ?>
                <?php if (!empty($r['linked_grns'])): ?>
                    <br><small style="color:#047857;font-size:10px">GRN: <?= e($r['linked_grns']) ?></small>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table></div>

    <?php /* ═══════════════ TAB 1: STOCK LEDGER AUDIT ═══════════════ */ ?>
    <?php elseif ($rtype === 'stock'): ?>
    <div style="padding:1rem 1.25rem;background:var(--surface-sunken,#f8fafc);border-bottom:1px solid var(--border-color,#e2e8f0)">
        <form method="get" style="display:flex;flex-wrap:wrap;gap:.6rem;align-items:flex-end">
            <input type="hidden" name="view" value="reports">
            <input type="hidden" name="rtype" value="stock">
            <div>
                <small class="muted" style="display:block;margin-bottom:.2rem">From Date</small>
                <input name="from_date" type="date" value="<?= e($from) ?>" style="font-size:.8rem;padding:.25rem .45rem">
            </div>
            <div>
                <small class="muted" style="display:block;margin-bottom:.2rem">To Date</small>
                <input name="to_date" type="date" value="<?= e($to) ?>" style="font-size:.8rem;padding:.25rem .45rem">
            </div>
            <div>
                <small class="muted" style="display:block;margin-bottom:.2rem">Filter Item</small>
                <select name="item_id" style="font-size:.8rem;padding:.25rem .45rem">
                    <option value="">All Items</option>
                    <?php foreach ($items_all as $it): ?>
                    <option value="<?= $it['id'] ?>" <?= $selItem === (int)$it['id'] ? 'selected' : '' ?>><?= e($it['code']) ?> — <?= e($it['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <small class="muted" style="display:block;margin-bottom:.2rem">Department</small>
                <select name="dept_id" style="font-size:.8rem;padding:.25rem .45rem">
                    <option value="">All Departments</option>
                    <?php foreach ($depts_all as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $selDept === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="button button-primary" type="submit" style="font-size:.8rem;padding:.3rem .7rem">Apply Filter</button>
        </form>
    </div>

    <div class="kpi-grid four" style="display:grid!important;grid-template-columns:repeat(4,1fr)!important;gap:14px!important;margin:1rem 1.25rem!important">
        <div class="kpi-card" style="min-height:auto!important;background:#ffffff!important;border:1px solid #dcebe5!important;border-left:4px solid #2563eb!important;border-radius:10px!important;padding:14px 18px!important;box-shadow:0 2px 8px rgba(0,0,0,0.06)!important">
            <p class="kpi-label" style="color:#1e293b!important;font-size:11px!important;font-weight:700!important;text-transform:uppercase!important;letter-spacing:0.5px!important;margin:0 0 6px 0!important;opacity:1!important">Ledger Movements</p>
            <p class="kpi-val" style="font-size:24px!important;font-weight:700!important;color:#0f172a!important;margin:0 0 4px 0!important"><?= number_format($totalMovements) ?></p>
            <p class="kpi-sub" style="font-size:11px!important;color:#64748b!important;margin:0!important">Total transactions recorded</p>
        </div>
        <div class="kpi-card" style="min-height:auto!important;background:#ffffff!important;border:1px solid #dcebe5!important;border-left:4px solid #10b981!important;border-radius:10px!important;padding:14px 18px!important;box-shadow:0 2px 8px rgba(0,0,0,0.06)!important">
            <p class="kpi-label" style="color:#1e293b!important;font-size:11px!important;font-weight:700!important;text-transform:uppercase!important;letter-spacing:0.5px!important;margin:0 0 6px 0!important;opacity:1!important">Total Receipts / Inward</p>
            <p class="kpi-val" style="font-size:24px!important;font-weight:700!important;color:#047857!important;margin:0 0 4px 0!important">+<?= number_format($totalIn) ?></p>
            <p class="kpi-sub" style="font-size:11px!important;color:#64748b!important;margin:0!important">Units received (GRNs &amp; vendor)</p>
        </div>
        <div class="kpi-card" style="min-height:auto!important;background:#ffffff!important;border:1px solid #dcebe5!important;border-left:4px solid #f59e0b!important;border-radius:10px!important;padding:14px 18px!important;box-shadow:0 2px 8px rgba(0,0,0,0.06)!important">
            <p class="kpi-label" style="color:#1e293b!important;font-size:11px!important;font-weight:700!important;text-transform:uppercase!important;letter-spacing:0.5px!important;margin:0 0 6px 0!important;opacity:1!important">Total Issues &amp; Dispensing</p>
            <p class="kpi-val" style="font-size:24px!important;font-weight:700!important;color:#b45309!important;margin:0 0 4px 0!important">-<?= number_format($totalOut) ?></p>
            <p class="kpi-sub" style="font-size:11px!important;color:#64748b!important;margin:0!important">Units issued to OPD/OT/Wards</p>
        </div>
        <div class="kpi-card" style="min-height:auto!important;background:#ffffff!important;border:1px solid #dcebe5!important;border-left:4px solid #218b68!important;border-radius:10px!important;padding:14px 18px!important;box-shadow:0 2px 8px rgba(0,0,0,0.06)!important">
            <p class="kpi-label" style="color:#1e293b!important;font-size:11px!important;font-weight:700!important;text-transform:uppercase!important;letter-spacing:0.5px!important;margin:0 0 6px 0!important;opacity:1!important">Net Movement</p>
            <p class="kpi-val" style="font-size:24px!important;font-weight:700!important;color:var(--brand-primary,#00897b)!important;margin:0 0 4px 0!important"><?= ($totalIn - $totalOut) >= 0 ? '+' : '' ?><?= number_format($totalIn - $totalOut) ?></p>
            <p class="kpi-sub" style="font-size:11px!important;color:#64748b!important;margin:0!important">Receipts minus issues</p>
        </div>
    </div>

    <div class="table-wrap"><table>
        <thead>
            <tr>
                <th>Date / Time</th>
                <th>Reference No.</th>
                <th>Item Code &amp; Name</th>
                <th>Batch</th>
                <th>Movement Type</th>
                <th>Source ➔ Dest</th>
                <th>Qty</th>
                <th>Balance After</th>
                <th>Officer</th>
                <th>Notes / Remarks</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($stockRows)): ?>
        <tr><td colspan="10" class="muted" style="text-align:center;padding:2.5rem">No stock movements found for the selected period and filters.</td></tr>
        <?php else: foreach ($stockRows as $sl): ?>
        <tr>
            <td>
                <b><?= e(date('d M Y', strtotime($sl['created_at']))) ?></b><br>
                <small class="muted"><?= e(date('h:i A', strtotime($sl['created_at']))) ?></small>
            </td>
            <td><code><?= e($sl['reference_no']) ?></code></td>
            <td>
                <b><?= e($sl['icode']) ?></b><br>
                <span class="muted"><?= e($sl['iname']) ?></span>
            </td>
            <td><code><?= e($sl['batch_number'] ?: '—') ?></code></td>
            <td><?= pill($sl['transaction_type']) ?></td>
            <td><small><?= e($sl['source_type']) ?> ➔ <b><?= e($sl['dest_type']) ?></b></small></td>
            <td style="font-weight:700" class="<?= in_array($sl['transaction_type'], $inTypes, true) ? 'text-success' : 'text-danger' ?>">
                <?= in_array($sl['transaction_type'], $inTypes, true) ? '+' : '-' ?><?= e($sl['quantity']) ?> <small><?= e($sl['unit']) ?></small>
            </td>
            <td><b><?= e($sl['balance_after']) ?></b></td>
            <td><small><?= e($sl['uname'] ?? 'System') ?></small></td>
            <td><small class="muted"><?= e($sl['notes'] ?: '—') ?></small></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table></div>

    <?php /* ═══════════════ TAB 2: EXPIRY BUCKETS ═══════════════ */ ?>
    <?php elseif ($rtype === 'expiry'): ?>
    <div class="kpi-grid four" style="margin:1rem 1.25rem">
        <div class="kpi-card" style="padding:.75rem;border-left:3px solid #ef4444">
            <p class="kpi-label">Expired Stock (Value)</p>
            <p class="kpi-val" style="font-size:1.3rem;color:#b91c1c">Rs. <?= number_format($expiredVal, 2) ?></p>
            <p class="kpi-sub"><?= count($expiredRows) ?> batches expired</p>
        </div>
        <div class="kpi-card" style="padding:.75rem;border-left:3px solid #f97316">
            <p class="kpi-label">Critical (&lt; 30 Days)</p>
            <p class="kpi-val" style="font-size:1.3rem;color:#c2410c">Rs. <?= number_format($criticalVal, 2) ?></p>
            <p class="kpi-sub"><?= count($criticalRows) ?> batches expiring soon</p>
        </div>
        <div class="kpi-card" style="padding:.75rem;border-left:3px solid #f59e0b">
            <p class="kpi-label">Warning (30–60 Days)</p>
            <p class="kpi-val" style="font-size:1.3rem;color:#b45309"><?= count($warningRows) ?> batches</p>
            <p class="kpi-sub">Priority for FEFO dispatch</p>
        </div>
        <div class="kpi-card" style="padding:.75rem;border-left:3px solid #10b981">
            <p class="kpi-label">Total Batches Monitored</p>
            <p class="kpi-val" style="font-size:1.3rem;color:#047857"><?= count($batchRows) ?></p>
            <p class="kpi-sub"><?= count($safeRows) ?> batches safe (&gt; 90d)</p>
        </div>
    </div>

    <div class="table-wrap"><table>
        <thead>
            <tr>
                <th>Risk Category</th>
                <th>Item Code &amp; Name</th>
                <th>Batch Number</th>
                <th>Expiry Date</th>
                <th>Days Remaining</th>
                <th>Available Quantity</th>
                <th>Est. Financial Risk</th>
                <th>Physical Location</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($batchRows)): ?>
        <tr><td colspan="8" class="muted" style="text-align:center;padding:2.5rem">No active batches with expiry dates found.</td></tr>
        <?php else: foreach ($batchRows as $b):
            $d = (int)$b['days_left'];
            $risk = (float)$b['quantity'] * (float)($b['unit_cost'] ?? 0);
        ?>
        <tr style="<?= $d <= 0 ? 'background:#fef2f2' : ($d <= 30 ? 'background:#fff7ed' : '') ?>">
            <td>
                <?php if ($d <= 0): ?>
                    <span class="badge" style="background:#ef4444;color:#fff;font-weight:700">🚨 Expired</span>
                <?php elseif ($d <= 30): ?>
                    <span class="badge" style="background:#ea580c;color:#fff;font-weight:700">⚠️ Critical (&lt; 30d)</span>
                <?php elseif ($d <= 60): ?>
                    <span class="badge" style="background:#f59e0b;color:#fff;font-weight:600">🔶 Warning (30-60d)</span>
                <?php elseif ($d <= 90): ?>
                    <span class="badge" style="background:#3b82f6;color:#fff;font-weight:600">🔷 Attention (60-90d)</span>
                <?php else: ?>
                    <span class="badge" style="background:#10b981;color:#fff">🟢 Safe (&gt; 90d)</span>
                <?php endif; ?>
            </td>
            <td>
                <b><?= e($b['icode']) ?></b><br>
                <span><?= e($b['iname']) ?></span>
            </td>
            <td><code><?= e($b['batch_number']) ?></code></td>
            <td><b><?= e(date('d M Y', strtotime($b['expiry_date']))) ?></b></td>
            <td>
                <b style="color:<?= $d <= 30 ? '#b91c1c' : ($d <= 60 ? '#b45309' : '#047857') ?>">
                    <?= $d <= 0 ? 'EXPIRED' : $d . ' days' ?>
                </b>
            </td>
            <td><b><?= e($b['quantity']) ?></b> <small><?= e($b['unit']) ?></small></td>
            <td><b>Rs. <?= number_format($risk, 2) ?></b></td>
            <td><small class="muted"><?= e($b['location'] ?: 'Main Store') ?></small></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table></div>

    <?php /* ═══════════════ TAB 3: PATIENT CONSUMPTION AUDIT ═══════════════ */ ?>
    <?php elseif ($rtype === 'consumption'): ?>
    <div style="padding:.75rem 1.25rem;background:var(--surface-sunken,#f8fafc);border-bottom:1px solid var(--border-color,#e2e8f0);display:flex;gap:.5rem;align-items:center">
        <span style="font-size:.82rem;font-weight:600">Department Filter:</span>
        <a class="button <?= ($subType ?? 'all') === 'all' ? 'button-primary' : 'button-light' ?>" href="?view=reports&rtype=consumption&ctype=all" style="padding:.2rem .6rem;font-size:.78rem">All (<?= $totalConsEpisodes ?>)</a>
        <a class="button <?= ($subType ?? '') === 'OPD' ? 'button-primary' : 'button-light' ?>" href="?view=reports&rtype=consumption&ctype=OPD" style="padding:.2rem .6rem;font-size:.78rem">OPD (<?= $opdConsCount ?>)</a>
        <a class="button <?= ($subType ?? '') === 'OT' ? 'button-primary' : 'button-light' ?>" href="?view=reports&rtype=consumption&ctype=OT" style="padding:.2rem .6rem;font-size:.78rem">OT Surgeries (<?= $otConsCount ?>)</a>
        <a class="button <?= ($subType ?? '') === 'IPD' ? 'button-primary' : 'button-light' ?>" href="?view=reports&rtype=consumption&ctype=IPD" style="padding:.2rem .6rem;font-size:.78rem">IPD Wards (<?= $ipdConsCount ?>)</a>
        <a class="button <?= ($subType ?? '') === 'Emergency' ? 'button-primary' : 'button-light' ?>" href="?view=reports&rtype=consumption&ctype=Emergency" style="padding:.2rem .6rem;font-size:.78rem">Emergency (<?= $emgConsCount ?>)</a>
    </div>

    <div class="kpi-grid four" style="margin:1rem 1.25rem">
        <div class="kpi-card" style="padding:.75rem">
            <p class="kpi-label">Total Dispensing Records</p>
            <p class="kpi-val" style="font-size:1.3rem"><?= number_format($totalConsEpisodes) ?></p>
        </div>
        <div class="kpi-card" style="padding:.75rem;border-left:3px solid #10b981">
            <p class="kpi-label">Total Consumables Dispensed</p>
            <p class="kpi-val" style="font-size:1.3rem;color:#047857"><?= number_format($totalConsUnits) ?> units</p>
        </div>
        <div class="kpi-card" style="padding:.75rem">
            <p class="kpi-label">OT Eye Surgeries Tracked</p>
            <p class="kpi-val" style="font-size:1.3rem;color:var(--brand-primary,#00897b)"><?= number_format($otConsCount) ?></p>
        </div>
        <div class="kpi-card" style="padding:.75rem;border-left:3px solid #f59e0b">
            <p class="kpi-label">Emergency Trauma Dispenses</p>
            <p class="kpi-val" style="font-size:1.3rem;color:#b45309"><?= number_format($emgConsCount) ?></p>
        </div>
    </div>

    <div class="table-wrap"><table>
        <thead>
            <tr>
                <th>Date / Time</th>
                <th>Dept / Type</th>
                <th>Patient MR &amp; Name</th>
                <th>Clinical Specifics</th>
                <th>Item Consumed</th>
                <th>Batch / Qty</th>
                <th>Sindh Manual Reg. Ref</th>
                <th>Status / Operator</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($consRows)): ?>
        <tr><td colspan="8" class="muted" style="text-align:center;padding:2.5rem">No consumption records found for this category.</td></tr>
        <?php else: foreach ($consRows as $cr): ?>
        <tr>
            <td>
                <b><?= e(date('d M Y', strtotime($cr['created_at']))) ?></b><br>
                <small class="muted"><?= e(date('h:i A', strtotime($cr['created_at']))) ?></small>
            </td>
            <td>
                <b><?= e($cr['dname'] ?? $cr['type']) ?></b><br>
                <span class="badge" style="font-size:.72rem"><?= e($cr['type']) ?></span>
            </td>
            <td>
                <b><?= e($cr['patient_mr']) ?></b><br>
                <span class="muted"><?= e($cr['patient_name'] ?: 'Patient') ?></span>
            </td>
            <td>
                <?php if ($cr['type'] === 'OT'): ?>
                    <small><b><?= e($cr['procedure_name'] ?: 'Surgery') ?></b><br>
                    Surgeon: <?= e($cr['surgeon'] ?: 'OT Team') ?></small>
                <?php elseif ($cr['type'] === 'IPD'): ?>
                    <small>Ward: <b><?= e($cr['ward']) ?></b> · Bed: <b><?= e($cr['bed']) ?></b></small>
                <?php else: ?>
                    <small class="muted">Outpatient / Rapid Care</small>
                <?php endif; ?>
            </td>
            <td>
                <b><?= e($cr['icode']) ?></b><br>
                <small><?= e($cr['iname']) ?></small>
            </td>
            <td>
                <code><?= e($cr['batch_number'] ?: 'FEFO') ?></code><br>
                <strong style="color:var(--brand-primary,#00897b)"><?= e($cr['quantity']) ?></strong> <?= e($cr['unit']) ?>
            </td>
            <td>
                <?php if (!empty($cr['manual_register_page']) || !empty($cr['manual_register_entry'])): ?>
                    <small><b><?= e($cr['manual_register_name'] ?: 'Reg.') ?></b><br>
                    Pg: <b><?= e($cr['manual_register_page']) ?></b> · Ent: <b><?= e($cr['manual_register_entry']) ?></b></small>
                <?php else: ?>
                    <span class="muted">—</span>
                <?php endif; ?>
            </td>
            <td>
                <?= pill($cr['status']) ?><br>
                <small class="muted">By: <?= e($cr['uname'] ?? 'Staff') ?></small>
            </td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table></div>

    <?php /* ═══════════════ TAB 4: FIXED ASSETS & MAINTENANCE ═══════════════ */ ?>
    <?php elseif ($rtype === 'assets'): ?>
    <div class="kpi-grid four" style="margin:1rem 1.25rem">
        <div class="kpi-card" style="padding:.75rem">
            <p class="kpi-label">Total Assets Listed</p>
            <p class="kpi-val" style="font-size:1.3rem"><?= count($assetReportRows) ?></p>
        </div>
        <div class="kpi-card" style="padding:.75rem;border-left:3px solid #10b981">
            <p class="kpi-label">Gross Capital Valuation</p>
            <p class="kpi-val" style="font-size:1.3rem;color:#047857">Rs. <?= number_format($totalAssetVal, 2) ?></p>
        </div>
        <div class="kpi-card" style="padding:.75rem;border-left:3px solid #f59e0b">
            <p class="kpi-label">Total Maintenance Expense</p>
            <p class="kpi-val" style="font-size:1.3rem;color:#b45309">Rs. <?= number_format($totalMntCost, 2) ?></p>
        </div>
        <div class="kpi-card" style="padding:.75rem">
            <p class="kpi-label">Asset Health Ratio</p>
            <p class="kpi-val" style="font-size:1.3rem;color:var(--brand-primary,#00897b)"><?= round(($totalMntCost / max(1, $totalAssetVal)) * 100, 1) ?>% mnt</p>
        </div>
    </div>

    <div class="table-wrap"><table>
        <thead>
            <tr>
                <th>Asset Tag &amp; Description</th>
                <th>Category</th>
                <th>Department &amp; Custodian</th>
                <th>Capital Cost</th>
                <th>Status</th>
                <th>Warranty / AMC Expiry</th>
                <th>Total Maintenance</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($assetReportRows)): ?>
        <tr><td colspan="7" class="muted" style="text-align:center;padding:2.5rem">No fixed assets registered.</td></tr>
        <?php else: foreach ($assetReportRows as $ar): ?>
        <tr>
            <td>
                <b><?= e($ar['tag']) ?></b> — <b><?= e($ar['name']) ?></b><br>
                <small class="muted"><?= e($ar['make']) ?> <?= e($ar['model']) ?> (SN: <?= e($ar['serial_number'] ?: '—') ?>)</small>
            </td>
            <td><?= e($ar['category']) ?></td>
            <td>
                <b><?= e($ar['department']) ?></b><br>
                <small class="muted"><?= e($ar['location']) ?> (<?= e($ar['custodian']) ?>)</small>
            </td>
            <td>
                <b>Rs. <?= number_format((float)$ar['cost'], 2) ?></b><br>
                <small class="muted"><?= e($ar['purchase_date'] ?: '—') ?></small>
            </td>
            <td><?= pill($ar['status']) ?></td>
            <td>
                <small>Warranty: <b><?= e($ar['warranty_until'] ?: 'None') ?></b><br>
                AMC: <b><?= e($ar['amc_expiry'] ?: 'None') ?></b></small>
            </td>
            <td>
                <b>Rs. <?= number_format((float)$ar['total_mnt_cost'], 2) ?></b><br>
                <small class="muted"><?= (int)$ar['mnt_count'] ?> service events</small>
            </td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table></div>

    <?php /* ═══════════════ TAB 5: DEAD STOCK & CONDEMNATIONS ═══════════════ */ ?>
    <?php elseif ($rtype === 'condemnations'): ?>
    <div class="kpi-grid four" style="margin:1rem 1.25rem">
        <div class="kpi-card" style="padding:.75rem">
            <p class="kpi-label">Condemned Assets</p>
            <p class="kpi-val" style="font-size:1.3rem"><?= count($condemnReportRows) ?></p>
        </div>
        <div class="kpi-card" style="padding:.75rem;border-left:3px solid #64748b">
            <p class="kpi-label">Estimated Scrap Value</p>
            <p class="kpi-val" style="font-size:1.3rem;color:#475569">Rs. <?= number_format($totalScrapEst, 2) ?></p>
        </div>
        <div class="kpi-card" style="padding:.75rem;border-left:3px solid #10b981">
            <p class="kpi-label">Recovered via Auction</p>
            <p class="kpi-val" style="font-size:1.3rem;color:#047857">Rs. <?= number_format($totalRecovered, 2) ?></p>
        </div>
        <div class="kpi-card" style="padding:.75rem">
            <p class="kpi-label">Auction Disposals Finalized</p>
            <p class="kpi-val" style="font-size:1.3rem;color:var(--brand-primary,#00897b)"><?= $disposedCount ?></p>
        </div>
    </div>

    <div class="table-wrap"><table>
        <thead>
            <tr>
                <th>Condemnation # &amp; Date</th>
                <th>Equipment Details</th>
                <th>Survey Board Order</th>
                <th>Condemnation Justification</th>
                <th>Est. Scrap Value</th>
                <th>Disposal Status</th>
                <th>Recovery / Challan</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($condemnReportRows)): ?>
        <tr><td colspan="7" class="muted" style="text-align:center;padding:2.5rem">No dead stock or condemnation records found.</td></tr>
        <?php else: foreach ($condemnReportRows as $cr): ?>
        <tr>
            <td>
                <b><?= e($cr['condemnation_no']) ?></b><br>
                <small class="muted"><?= e(date('d M Y', strtotime($cr['created_at']))) ?></small>
            </td>
            <td>
                <b><?= e($cr['tag_or_code']) ?></b><br>
                <b><?= e($cr['item_name']) ?></b> (<?= e($cr['department']) ?>)
            </td>
            <td>
                <b><?= e($cr['survey_board_ref']) ?></b><br>
                <small class="muted">Date: <?= e($cr['survey_board_date']) ?><br>Auth: <?= e($cr['approved_by']) ?></small>
            </td>
            <td>
                <b><?= e($cr['reason']) ?></b><br>
                <small class="muted"><?= e($cr['recommendation']) ?></small>
            </td>
            <td><b>Rs. <?= number_format((float)$cr['estimated_scrap_val'], 2) ?></b></td>
            <td><?= pill($cr['status']) ?></td>
            <td>
                <?php if ($cr['status'] === 'Disposed/Auctioned'): ?>
                    <b style="color:#047857">Rs. <?= number_format((float)$cr['recovered_amount'], 2) ?></b><br>
                    <small>Challan: <b><?= e($cr['auction_challan_no']) ?></b><br>Buyer: <?= e($cr['buyer_vendor_name']) ?></small>
                <?php else: ?>
                    <span class="muted">Pending Auction</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table></div>

    <?php /* ═══════════════ TAB 6: MANUAL REGISTER SYNC CERTIFICATE ═══════════════ */ ?>
    <?php elseif ($rtype === 'reconciliation'): ?>
    <div class="kpi-grid four" style="display:grid!important;grid-template-columns:repeat(4,1fr)!important;gap:14px!important;margin:1rem 1.25rem!important">
        <div class="kpi-card" style="min-height:auto!important;background:#ffffff!important;border:1px solid #dcebe5!important;border-left:4px solid #2563eb!important;border-radius:10px!important;padding:14px 18px!important;box-shadow:0 2px 8px rgba(0,0,0,0.06)!important">
            <p class="kpi-label" style="color:#1e293b!important;font-size:11px!important;font-weight:700!important;text-transform:uppercase!important;letter-spacing:0.5px!important;margin:0 0 6px 0!important;opacity:1!important">Audited Checkpoints</p>
            <p class="kpi-val" style="font-size:24px!important;font-weight:700!important;color:#0f172a!important;margin:0 0 4px 0!important"><?= $totalRecon ?></p>
            <p class="kpi-sub" style="font-size:11px!important;color:#64748b!important;margin:0!important">Physical entries audited</p>
        </div>
        <div class="kpi-card" style="min-height:auto!important;background:#ffffff!important;border:1px solid #dcebe5!important;border-left:4px solid #10b981!important;border-radius:10px!important;padding:14px 18px!important;box-shadow:0 2px 8px rgba(0,0,0,0.06)!important">
            <p class="kpi-label" style="color:#1e293b!important;font-size:11px!important;font-weight:700!important;text-transform:uppercase!important;letter-spacing:0.5px!important;margin:0 0 6px 0!important;opacity:1!important">100% Matched Registers</p>
            <p class="kpi-val" style="font-size:24px!important;font-weight:700!important;color:#047857!important;margin:0 0 4px 0!important"><?= $matchedRecon ?></p>
            <p class="kpi-sub" style="font-size:11px!important;color:#64748b!important;margin:0!important">Zero variance confirmed</p>
        </div>
        <div class="kpi-card" style="min-height:auto!important;background:#ffffff!important;border:1px solid #dcebe5!important;border-left:4px solid #ef4444!important;border-radius:10px!important;padding:14px 18px!important;box-shadow:0 2px 8px rgba(0,0,0,0.06)!important">
            <p class="kpi-label" style="color:#1e293b!important;font-size:11px!important;font-weight:700!important;text-transform:uppercase!important;letter-spacing:0.5px!important;margin:0 0 6px 0!important;opacity:1!important">Discrepancies Flagged</p>
            <p class="kpi-val" style="font-size:24px!important;font-weight:700!important;color:#b91c1c!important;margin:0 0 4px 0!important"><?= $discrepancyRecon ?></p>
            <p class="kpi-sub" style="font-size:11px!important;color:#64748b!important;margin:0!important">Variances under review</p>
        </div>
        <div class="kpi-card" style="min-height:auto!important;background:#ffffff!important;border:1px solid #dcebe5!important;border-left:4px solid #218b68!important;border-radius:10px!important;padding:14px 18px!important;box-shadow:0 2px 8px rgba(0,0,0,0.06)!important">
            <p class="kpi-label" style="color:#1e293b!important;font-size:11px!important;font-weight:700!important;text-transform:uppercase!important;letter-spacing:0.5px!important;margin:0 0 6px 0!important;opacity:1!important">Sync Alignment Health</p>
            <p class="kpi-val" style="font-size:24px!important;font-weight:700!important;color:var(--brand-primary,#00897b)!important;margin:0 0 4px 0!important"><?= $syncHealth ?>%</p>
            <p class="kpi-sub" style="font-size:11px!important;color:#64748b!important;margin:0!important">Physical-digital sync score</p>
        </div>
    </div>

    <!-- Official Certification Box -->
    <div style="margin:1rem 1.25rem;padding:1.25rem;background:#f0fdf4;border:2px solid #86efac;border-radius:6px">
        <h3 style="color:#166534;margin:0 0 .5rem">📜 SINDH HEALTH AUDIT COMPLIANCE CERTIFICATE</h3>
        <p style="font-size:.85rem;color:#14532d;line-height:1.5;margin:0">
            This document certifies that the electronic store transactions within this system have been cross-audited against the statutory Sindh Government physical stock and indent registers.
            Current synchronization alignment stands at <strong><?= $syncHealth ?>%</strong> across <?= $totalRecon ?> verified entries. All outstanding variances are placed under review with DD Finance.
        </p>
        <div style="display:flex;justify-content:space-between;margin-top:2rem;padding-top:1.5rem;border-top:1px dashed #86efac;font-size:.8rem;color:#166534">
            <div>
                ____________________________<br>
                <strong>Internal Audit Officer</strong><br>
                SIOVS Hyderabad
            </div>
            <div>
                ____________________________<br>
                <strong>Store Officer</strong><br>
                Central Store SIOVS
            </div>
            <div>
                ____________________________<br>
                <strong>Deputy Director (Finance)</strong><br>
                Competent Financial Authority
            </div>
        </div>
    </div>

    <div class="table-wrap"><table>
        <thead>
            <tr>
                <th>Rec # / Date</th>
                <th>Physical Sindh Register Ref</th>
                <th>Transaction Details</th>
                <th>Paper Count</th>
                <th>System Count</th>
                <th>Discrepancy</th>
                <th>Status</th>
                <th>Reason / Corrective Action</th>
                <th>Verified By</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($reconReportRows)): ?>
        <tr><td colspan="9" class="muted" style="text-align:center;padding:2.5rem">No manual vs digital reconciliation checkpoints logged.</td></tr>
        <?php else: foreach ($reconReportRows as $rr): ?>
        <tr>
            <td>
                <b><?= e($rr['rec_number']) ?></b><br>
                <small class="muted"><?= e(date('d M Y', strtotime($rr['verified_date']))) ?></small>
            </td>
            <td>
                <b><?= e($rr['register_name']) ?></b><br>
                <small>Page: <b><?= e($rr['page_no']) ?></b> · Entry: <b><?= e($rr['entry_no']) ?></b></small>
            </td>
            <td>
                <b><?= e($rr['transaction_type']) ?></b><br>
                <code><?= e($rr['transaction_ref']) ?></code>
            </td>
            <td><b><?= e($rr['register_qty']) ?></b></td>
            <td><b><?= e($rr['system_qty']) ?></b></td>
            <td style="font-weight:700" class="<?= $rr['difference'] == 0 ? 'text-success' : 'text-danger' ?>">
                <?= $rr['difference'] > 0 ? '+' : '' ?><?= e($rr['difference']) ?>
            </td>
            <td><?= pill($rr['status']) ?></td>
            <td>
                <?= e($rr['reason'] ?: '—') ?>
                <?php if (!empty($rr['corrective_action'])): ?>
                    <br><small class="muted">Action: <?= e($rr['corrective_action']) ?></small>
                <?php endif; ?>
            </td>
            <td><small><?= e($rr['uname'] ?? 'Audit Officer') ?></small></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table></div>

    <?php /* ═══════════════ TAB 7: INVENTORY & ASSET VALUATION ═══════════════ */ ?>
    <?php elseif ($rtype === 'valuation'): ?>
    <div class="kpi-grid four" style="display:grid!important;grid-template-columns:repeat(4,1fr)!important;gap:14px!important;margin:1rem 1.25rem!important">
        <div class="kpi-card" style="min-height:auto!important;background:#ffffff!important;border:1px solid #dcebe5!important;border-left:4px solid #0f766e!important;border-radius:10px!important;padding:14px 18px!important;box-shadow:0 2px 8px rgba(0,0,0,0.06)!important">
            <p class="kpi-label" style="color:#1e293b!important;font-size:11px!important;font-weight:700!important;text-transform:uppercase!important;letter-spacing:0.5px!important;margin:0 0 6px 0!important;opacity:1!important">Central Store Valuation</p>
            <p class="kpi-val" style="font-size:22px!important;font-weight:700!important;color:#0f766e!important;margin:0 0 4px 0!important">Rs. <?= number_format($totalStoreVal, 0) ?></p>
            <p class="kpi-sub" style="font-size:11px!important;color:#64748b!important;margin:0!important">Liquid store inventory at cost</p>
        </div>
        <div class="kpi-card" style="min-height:auto!important;background:#ffffff!important;border:1px solid #dcebe5!important;border-left:4px solid #2563eb!important;border-radius:10px!important;padding:14px 18px!important;box-shadow:0 2px 8px rgba(0,0,0,0.06)!important">
            <p class="kpi-label" style="color:#1e293b!important;font-size:11px!important;font-weight:700!important;text-transform:uppercase!important;letter-spacing:0.5px!important;margin:0 0 6px 0!important;opacity:1!important">Capital Assets Value</p>
            <p class="kpi-val" style="font-size:22px!important;font-weight:700!important;color:#1e40af!important;margin:0 0 4px 0!important">Rs. <?= number_format($totalAssetVal, 0) ?></p>
            <p class="kpi-sub" style="font-size:11px!important;color:#64748b!important;margin:0!important">Hospital equipment &amp; machinery</p>
        </div>
        <div class="kpi-card" style="min-height:auto!important;background:#ffffff!important;border:1px solid #dcebe5!important;border-left:4px solid #d97706!important;border-radius:10px!important;padding:14px 18px!important;box-shadow:0 2px 8px rgba(0,0,0,0.06)!important">
            <p class="kpi-label" style="color:#1e293b!important;font-size:11px!important;font-weight:700!important;text-transform:uppercase!important;letter-spacing:0.5px!important;margin:0 0 6px 0!important;opacity:1!important">Committed PO Deliveries</p>
            <p class="kpi-val" style="font-size:22px!important;font-weight:700!important;color:#b45309!important;margin:0 0 4px 0!important">Rs. <?= number_format($totalPOCommittedVal, 0) ?></p>
            <p class="kpi-sub" style="font-size:11px!important;color:#64748b!important;margin:0!important">Active PO purchase liability</p>
        </div>
        <div class="kpi-card" style="min-height:auto!important;background:#ffffff!important;border:1px solid #dcebe5!important;border-left:4px solid #10b981!important;border-radius:10px!important;padding:14px 18px!important;box-shadow:0 2px 8px rgba(0,0,0,0.06)!important">
            <p class="kpi-label" style="color:#1e293b!important;font-size:11px!important;font-weight:700!important;text-transform:uppercase!important;letter-spacing:0.5px!important;margin:0 0 6px 0!important;opacity:1!important">Institutional Net Exposure</p>
            <p class="kpi-val" style="font-size:22px!important;font-weight:700!important;color:#047857!important;margin:0 0 4px 0!important">Rs. <?= number_format($grandTotalVal, 0) ?></p>
            <p class="kpi-sub" style="font-size:11px!important;color:#64748b!important;margin:0!important">Total store stock + fixed assets</p>
        </div>
    </div>

    <!-- Filter Bar -->
    <div style="padding:1rem 1.25rem;background:var(--surface-sunken,#f8fafc);border-bottom:1px solid var(--border-color,#e2e8f0);display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:.75rem">
        <form method="get" style="display:flex;flex-wrap:wrap;gap:.6rem;align-items:flex-end">
            <input type="hidden" name="view" value="reports">
            <input type="hidden" name="rtype" value="valuation">
            <div>
                <small class="muted" style="display:block;margin-bottom:.2rem">Category</small>
                <select name="cat" style="font-size:.8rem;padding:.25rem .45rem">
                    <option value="all">All Categories</option>
                    <?php foreach ($categoriesAll as $cat): ?>
                    <option value="<?= e($cat) ?>" <?= $catFilter === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <small class="muted" style="display:block;margin-bottom:.2rem">Stock Status</small>
                <select name="stock_status" style="font-size:.8rem;padding:.25rem .45rem">
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Stock Levels</option>
                    <option value="ok" <?= $statusFilter === 'ok' ? 'selected' : '' ?>>Adequate Stock</option>
                    <option value="low" <?= $statusFilter === 'low' ? 'selected' : '' ?>>Low / Reorder Warning</option>
                    <option value="out" <?= $statusFilter === 'out' ? 'selected' : '' ?>>Stock Out (0 Qty)</option>
                </select>
            </div>
            <button class="button button-primary" type="submit" style="font-size:.78rem;padding:.3rem .7rem">Filter</button>
            <?php if ($catFilter !== 'all' || $statusFilter !== 'all'): ?>
            <a class="button button-light" href="?view=reports&rtype=valuation" style="font-size:.78rem;padding:.3rem .7rem">Reset</a>
            <?php endif; ?>
        </form>
        <a class="button button-light" href="?view=reports&rtype=valuation&export=csv" style="font-size:.78rem;padding:.3rem .7rem">📥 Export Valuation CSV</a>
    </div>

    <div class="table-wrap"><table>
        <thead>
            <tr>
                <th>Item Code &amp; Name</th>
                <th>Category</th>
                <th style="text-align:right">Current Stock</th>
                <th style="text-align:right">Unit Cost / GRN Rate</th>
                <th style="text-align:right">Total Item Value</th>
                <th style="text-align:right">% Store Share</th>
                <th style="text-align:center">Stock Status</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($valuationRows)): ?>
        <tr><td colspan="7" class="muted" style="text-align:center;padding:2.5rem">No inventory items found matching your filters.</td></tr>
        <?php else: foreach ($valuationRows as $vr):
            $itemVal = (float)$vr['quantity'] * (float)$vr['unit_rate'];
            $share = $totalStoreVal > 0 ? round(($itemVal / $totalStoreVal) * 100, 2) : 0;
        ?>
        <tr>
            <td>
                <b><?= e($vr['code']) ?></b><br>
                <span><?= e($vr['name']) ?></span>
            </td>
            <td><?= e($vr['category'] ?: 'General') ?></td>
            <td style="text-align:right">
                <b><?= number_format((int)$vr['quantity']) ?></b> <small class="muted"><?= e($vr['unit']) ?></small>
            </td>
            <td style="text-align:right">
                <?= $vr['unit_rate'] > 0 ? 'Rs. ' . number_format((float)$vr['unit_rate'], 2) : '<span class="muted">—</span>' ?>
            </td>
            <td style="text-align:right">
                <b style="color:#0f766e">Rs. <?= number_format($itemVal, 2) ?></b>
            </td>
            <td style="text-align:right">
                <span class="muted" style="font-size:11px"><?= $share ?>%</span>
            </td>
            <td style="text-align:center">
                <?php if ((int)$vr['quantity'] === 0): ?>
                    <span class="badge" style="background:#fee2e2;color:#991b1b;font-size:10px;padding:2px 6px">Out of Stock</span>
                <?php elseif ((int)$vr['quantity'] <= (int)$vr['reorder_level']): ?>
                    <span class="badge" style="background:#fef3c7;color:#92400e;font-size:10px;padding:2px 6px">Reorder Alert</span>
                <?php else: ?>
                    <span class="badge" style="background:#dcfce7;color:#166534;font-size:10px;padding:2px 6px">Adequate</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>
<?php endif; ?>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
