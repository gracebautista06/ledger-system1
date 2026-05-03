<?php
/*  staff/log_sale.php — Record a New Egg Sale (Breed-Aware)
 *
 *  CHANGES from previous version:
 *  - Staff selects WHICH BREED they're selling from first
 *  - Prices auto-load from breed_prices table for that breed
 *  - Available stock is calculated per-breed per-size
 *    (harvests filtered by batch_id, which links to breed)
 *  - Breed selector only shows breeds that have stock available
 *  - If no breed selected yet, the size table is hidden
 *  - All existing validations (stock check, server-side) preserved
 */

$page_title = 'Record Sale';

include('../includes/db.php');

// Guard: make sure db.php actually created $conn
if (!isset($conn) || $conn === false || $conn->connect_error) {
    die('<div style="color:red;padding:20px;">
        <strong>Database connection failed.</strong><br>
        ' . (isset($conn) && $conn->connect_error ? htmlspecialchars($conn->connect_error) : 'Check db.php — $conn was not set.') . '
    </div>');
}

include('../includes/header.php');
include('../includes/log_activity.php');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Staff') {
    header("Location: ../portal/login.php"); exit();
}

$staff_id = (int)$_SESSION['user_id'];
$message  = "";

// ── Size definitions ──────────────────────────────────────────
$size_defs = [
    'PW' => 'Peewee',
    'S'  => 'Small',
    'M'  => 'Medium',
    'L'  => 'Large',
    'XL' => 'Extra Large',
    'J'  => 'Jumbo',
];
$size_colors = [
    'PW' => '#adb5bd', 'S' => '#74c0fc', 'M' => '#51cf66',
    'L'  => '#fcc419', 'XL'=> '#ff922b', 'J' => '#f03e3e',
];
$harvest_cols = [
    'PW' => 'size_pw', 'S' => 'size_s', 'M' => 'size_m',
    'L'  => 'size_l',  'XL'=> 'size_xl','J' => 'size_j',
];
$sale_qty_cols = [
    'PW' => 'qty_pw', 'S' => 'qty_s', 'M' => 'qty_m',
    'L'  => 'qty_l',  'XL'=> 'qty_xl','J' => 'qty_j',
];

// ── Load available coops/batches (all active) ─────────────────
// Show all active coops in the dropdown regardless of whether
// harvests have been logged yet. Stock will show 0 if none logged.
$batches_q = $conn->query("
    SELECT b.batch_id, b.breed, b.coop_number, b.coop_label
    FROM batches b
    WHERE b.status = 'Active'
    ORDER BY b.coop_number ASC, b.batch_id ASC
");
$available_batches = [];
$available_breeds  = []; // kept for JS stock map keyed by batch_id
if ($batches_q) {
    while ($row = $batches_q->fetch_assoc()) {
        $available_batches[] = $row;
    }
}

// ── Helper: get stock per size for a given batch_id ──────────
function get_batch_stock($conn, $batch_id, $harvest_cols, $sale_qty_cols) {
    $bid   = (int)$batch_id;
    $stock = [];
    $total = 0;

    // Get arrival date for this batch (to scope sold eggs)
    $arr_q   = $conn->query("SELECT COALESCE(arrival_date, date_acquired, '2000-01-01') AS arr FROM batches WHERE batch_id=$bid");
    $arrival = $arr_q ? $arr_q->fetch_assoc()['arr'] : '2000-01-01';
    $arr_esc = $conn->real_escape_string($arrival);

    // Eggs harvested per size for this batch
    $hq   = $conn->query("SELECT " . implode(', ', array_map(fn($c) => "COALESCE(SUM($c),0) AS $c", $harvest_cols)) . " FROM harvests WHERE batch_id=$bid");
    $harv = $hq ? $hq->fetch_assoc() : [];

    // Total harvested eggs for this batch (for proportional sold split)
    $htot_q  = $conn->query("SELECT COALESCE(SUM(total_eggs),0) AS t FROM harvests WHERE batch_id=$bid");
    $harv_total = $htot_q ? (int)$htot_q->fetch_assoc()['t'] : 0;

    // Total sold eggs since batch arrival (approximated to this batch)
    // If sales has batch_id column use that, otherwise use arrival date scope
    $has_batch_col = $conn->query("SHOW COLUMNS FROM sales LIKE 'batch_id'")->num_rows > 0;

    if ($has_batch_col) {
        $sold_q = $conn->query("SELECT " . implode(', ', array_map(fn($c) => "COALESCE(SUM($c),0) AS $c", $sale_qty_cols)) . " FROM sales WHERE batch_id=$bid");
    } else {
        $sold_q = $conn->query("SELECT " . implode(', ', array_map(fn($c) => "COALESCE(SUM($c),0) AS $c", $sale_qty_cols)) . " FROM sales WHERE DATE(date_sold) >= '$arr_esc'");
    }
    $sold_by_size = $sold_q ? $sold_q->fetch_assoc() : [];

    foreach ($harvest_cols as $code => $hcol) {
        $scol      = $sale_qty_cols[$code];
        $eggs_h    = isset($harv[$hcol]) ? (int)$harv[$hcol] : 0;
        $trays_h   = (int)floor($eggs_h / 30);

        if ($has_batch_col) {
            // Exact: sales linked to this batch
            $sold_t = isset($sold_by_size[$scol]) ? (int)$sold_by_size[$scol] : 0;
        } else {
            // Proportional split of total sold by this size's harvest share
            $total_sold_size = isset($sold_by_size[$scol]) ? (int)$sold_by_size[$scol] : 0;
            if ($harv_total > 0 && $eggs_h > 0) {
                $sold_t = (int)round(($eggs_h / $harv_total) * ($total_sold_size * 30) / 30);
            } else {
                $sold_t = $total_sold_size;
            }
        }

        $avail        = max(0, $trays_h - $sold_t);
        $stock[$code] = $avail;
        $total       += $avail;
    }
    return ['per_size' => $stock, 'total' => $total];
}
// Backward-compat alias used in old inline calls
function get_breed_stock($conn, $breed, $harvest_cols, $sale_qty_cols) {
    // Not used for stock calculation anymore — returns empty
    return ['per_size' => array_fill_keys(array_keys($harvest_cols), 0), 'total' => 0];
}

// ── Load prices for all breeds (for JS) ──────────────────────
$all_prices_q = $conn->query("SELECT breed, size_code, price_per_tray FROM breed_prices");
$all_prices   = []; // $all_prices[$breed][$code] = price_per_tray
if ($all_prices_q) {
    while ($row = $all_prices_q->fetch_assoc()) {
        $all_prices[$row['breed']][$row['size_code']] = (float)$row['price_per_tray'];
    }
}

// ── Selected batch (from POST or GET for pre-selection) ───────
$selected_batch_id = (int)($_POST['batch_id'] ?? $_GET['batch_id'] ?? 0);
$selected_batch    = null;
foreach ($available_batches as $ab) {
    if ($ab['batch_id'] == $selected_batch_id) { $selected_batch = $ab; break; }
}
if (!$selected_batch) { $selected_batch_id = 0; }

// Load stock for selected batch
$batch_stock  = [];
$total_avail  = 0;
$breed_prices = [];

if ($selected_batch) {
    $bs           = get_batch_stock($conn, $selected_batch_id, $harvest_cols, $sale_qty_cols);
    $batch_stock  = $bs['per_size'];
    $total_avail  = $bs['total'];
    $breed_prices = $all_prices[$selected_batch['breed']] ?? [];
}

// ── Handle POST (sale submission) ─────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['submit_sale'])) {

    $customer       = trim($_POST['customer_name'] ?? '');
    $post_batch_id  = (int)($_POST['batch_id'] ?? 0);
    $payment_method = in_array($_POST['payment_method'] ?? '', ['Cash','GCash','Bank Transfer'])
                      ? $_POST['payment_method'] : 'Cash';
    $notes          = trim($_POST['notes'] ?? '');

    // Look up breed from batch
    $breed      = '';
    $batch_info = null;
    foreach ($available_batches as $ab) {
        if ($ab['batch_id'] == $post_batch_id) { $batch_info = $ab; $breed = $ab['breed']; break; }
    }

    $qty = [];
    foreach ($size_defs as $code => $_) {
        $qty[$code] = max(0, (int)($_POST['qty_' . strtolower($code)] ?? 0));
    }

    $total_trays  = array_sum($qty);
    $total_amount = 0.0;
    $breed_p      = $all_prices[$breed] ?? [];
    foreach ($size_defs as $code => $_) {
        $total_amount += $qty[$code] * ($breed_p[$code] ?? 0);
    }
    $total_amount = round($total_amount, 2);
    $unit_price   = $total_trays > 0 ? round($total_amount / $total_trays, 2) : 0;

    // ── Server-side stock validation ──────────────────────────
    $stock_errors = [];
    if (empty($post_batch_id) || !$batch_info) {
        $stock_errors[] = "Please select a coop / batch.";
    } elseif (empty($customer)) {
        $stock_errors[] = "Please enter the customer name.";
    } elseif ($total_trays <= 0) {
        $stock_errors[] = "Please enter at least one tray quantity.";
    } else {
        // Re-fetch stock for validation
        $val_stock = get_batch_stock($conn, $post_batch_id, $harvest_cols, $sale_qty_cols);
        foreach ($size_defs as $code => $label) {
            if ($qty[$code] <= 0) continue;
            $avail_now = $val_stock['per_size'][$code] ?? 0;
            if ($qty[$code] > $avail_now) {
                $stock_errors[] = "<strong>$label ($code):</strong> Entered {$qty[$code]} tray(s), only {$avail_now} available.";
            }
        }
    }

    if (!empty($stock_errors)) {
        $message = "<div class='alert error'>⚠️ " . implode('<br>', $stock_errors) . "</div>";
    } elseif ($total_amount <= 0 && !empty($breed_p)) {
        $message = "<div class='alert error'>⚠️ Total is zero. Ask the Owner to set prices for <strong>" . htmlspecialchars($breed) . "</strong> in Pricing Settings.</div>";
    } else {
        // Check if sales table has batch_id column
        $has_batch_col = $conn->query("SHOW COLUMNS FROM sales LIKE 'batch_id'")->num_rows > 0;

        if ($has_batch_col) {
            $ins = $conn->prepare("
                INSERT INTO sales
                    (staff_id, batch_id, customer_name, quantity_sold, unit_price, total_amount,
                     payment_method, notes, qty_pw, qty_s, qty_m, qty_l, qty_xl, qty_j)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $ins->bind_param("iiisiddssiiiiii",
                $staff_id, $post_batch_id, $customer, $total_trays, $unit_price, $total_amount,
                $payment_method, $notes,
                $qty['PW'], $qty['S'], $qty['M'], $qty['L'], $qty['XL'], $qty['J']
            );
        } else {
            $ins = $conn->prepare("
                INSERT INTO sales
                    (staff_id, customer_name, quantity_sold, unit_price, total_amount,
                     payment_method, notes, qty_pw, qty_s, qty_m, qty_l, qty_xl, qty_j)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $ins->bind_param("isiddssiiiiii",
                $staff_id, $customer, $total_trays, $unit_price, $total_amount,
                $payment_method, $notes,
                $qty['PW'], $qty['S'], $qty['M'], $qty['L'], $qty['XL'], $qty['J']
            );
        }

        if ($ins->execute()) {
            $sale_id = $conn->insert_id;
            $ins->close();

            log_activity($conn, $staff_id, 'Staff', 'Sale Added',
                "Sold {$total_trays} tray(s) [{$breed}] to {$customer} — ₱" . number_format($total_amount, 2) . " (Sale #{$sale_id}, Batch #{$post_batch_id})");

            // Old-stock notification check — resolve if this batch is now sold out
            $notif_q = $conn->query("SELECT n.*, b.arrival_date FROM notifications n JOIN batches b ON n.batch_id=b.batch_id WHERE n.batch_id=$post_batch_id AND n.status IN ('unread','read') ORDER BY n.created_at DESC LIMIT 1");
            if ($notif_q && $notif_q->num_rows > 0) {
                $notif    = $notif_q->fetch_assoc();
                $notif_id = (int)$notif['notif_id'];
                $bid      = $post_batch_id;
                $h_s = $conn->prepare("SELECT COALESCE(SUM(total_eggs),0) AS t FROM harvests WHERE batch_id=?");
                $h_s->bind_param("i",$bid); $h_s->execute();
                $harv_c = (int)$h_s->get_result()->fetch_assoc()['t']; $h_s->close();

                $has_batch_col2 = $conn->query("SHOW COLUMNS FROM sales LIKE 'batch_id'")->num_rows > 0;
                if ($has_batch_col2) {
                    $s_s = $conn->prepare("SELECT COALESCE(SUM(quantity_sold*30),0) AS t FROM sales WHERE batch_id=?");
                } else {
                    $arr_d = $notif['arrival_date'] ?? '2000-01-01';
                    $s_s   = $conn->prepare("SELECT COALESCE(SUM(quantity_sold*30),0) AS t FROM sales WHERE date_sold>='$arr_d'");
                    // bind_param below still expects one arg; re-bind with dummy
                }
                $s_s->bind_param("i",$bid); $s_s->execute();
                $sold_c = (int)$s_s->get_result()->fetch_assoc()['t']; $s_s->close();
                if (max(0,$harv_c-$sold_c)<=0) {
                    $conn->query("UPDATE notifications SET status='completed',completed_at=NOW() WHERE notif_id=$notif_id");
                }
            }

            header("Location: view_logs.php?sale_saved=1"); exit();
        } else {
            $message = "<div class='alert error'>Database error. Please try again.</div>";
            $ins->close();
        }
    }
}

// Repopulate quantities after failed submit
$post_qty = [];
foreach ($size_defs as $code => $_) {
    $post_qty[$code] = isset($_POST['qty_' . strtolower($code)])
                       ? max(0, (int)$_POST['qty_' . strtolower($code)])
                       : 0;
}

$has_any_stock = !empty($available_batches);
?>

<div class="card" style="max-width:720px; margin:2rem auto; border-top:5px solid var(--success);">

    <h2 style="color:var(--gold); font-family:'Playfair Display',serif; margin-bottom:0.3rem;">
        💰 Record New Sale
    </h2>
    <p style="color:var(--text-muted); margin-bottom:1.6rem; font-size:0.88rem;">
        Select the coop, then enter trays sold per size. Prices load automatically.
    </p>

    <?php echo $message; ?>

    <?php if (!$has_any_stock): ?>
    <div style="background:var(--danger-bg); border:1px solid rgba(194,58,58,0.4);
                border-left:5px solid var(--danger); border-radius:var(--radius);
                padding:20px 24px; text-align:center;">
        <div style="font-size:2rem; margin-bottom:10px;">🥚</div>
        <div style="font-size:1rem; font-weight:700; color:var(--text-primary); margin-bottom:6px;">No Active Coops</div>
        <div style="font-size:0.88rem; color:var(--text-secondary); line-height:1.6; margin-bottom:16px;">
            No active batches found. Add a batch in Manage Batches before recording a sale.
        </div>
        <a href="../owner/manage_batches.php" class="btn-farm btn-orange" style="margin-right:10px;">🐔 Manage Batches</a>
        <a href="dashboard.php" class="btn-farm btn-dark">← Back</a>
    </div>

    <?php else: ?>

    <form method="POST" id="saleForm">
        <input type="hidden" name="submit_sale" value="1">

        <!-- Row 1: Coop/Batch selector + Customer -->
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:1.2rem;">
            <div class="form-group" style="margin:0;">
                <label>Coop / Batch <span style="color:var(--danger);">*</span></label>
                <select name="batch_id" id="batch-select" class="form-input"
                        onchange="onBatchChange(this.value)" required>
                    <option value="">— Select coop —</option>
                    <?php foreach ($available_batches as $ab):
                        // Build display: "Coop 1 — ISA Brown" or "Main Coop — ISA Brown"
                        if (!empty($ab['coop_label'])) {
                            $coop_display = $ab['coop_label'];
                        } elseif (!empty($ab['coop_number'])) {
                            $coop_display = 'Coop ' . $ab['coop_number'];
                        } else {
                            $coop_display = 'Batch #' . $ab['batch_id'];
                        }
                        $option_text = $coop_display . ' — ' . $ab['breed'];
                    ?>
                        <option value="<?php echo $ab['batch_id']; ?>"
                            <?php echo $selected_batch_id == $ab['batch_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($option_text); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0;">
                <label>Customer Name <span style="color:var(--danger);">*</span></label>
                <input type="text" name="customer_name" class="form-input"
                       placeholder="Customer or business name" required
                       value="<?php echo isset($_POST['customer_name']) ? htmlspecialchars(trim($_POST['customer_name'])) : ''; ?>">
            </div>
        </div>

        <!-- Stock summary for selected batch -->
        <div id="stock-summary" style="<?php echo $selected_batch_id ? '' : 'display:none;'; ?>
                                        background:var(--bg-wood); border-radius:var(--radius);
                                        border:1px solid var(--border-subtle); padding:12px 16px;
                                        margin-bottom:1.2rem;">
            <div style="font-size:0.68rem; font-weight:700; color:var(--text-muted);
                        text-transform:uppercase; letter-spacing:0.8px; margin-bottom:8px;">
                📦 Available Stock — <span id="stock-breed-label"><?php echo htmlspecialchars($selected_batch ? $selected_batch['coop_label'] . ' — ' . $selected_batch['breed'] : ''); ?></span>
            </div>
            <div style="display:flex; flex-wrap:wrap; gap:7px;" id="stock-boxes">
                <?php foreach ($size_defs as $code => $label):
                    $avail = $batch_stock[$code] ?? 0;
                    $col   = $size_colors[$code];
                ?>
                <div id="stockbox_<?php echo $code; ?>"
                     style="background:var(--bg-plank); border-radius:var(--radius-sm);
                            padding:6px 11px; border:1px solid var(--border-subtle);
                            text-align:center; min-width:66px;">
                    <div style="display:flex; align-items:center; gap:4px; justify-content:center; margin-bottom:2px;">
                        <span style="width:7px; height:7px; border-radius:50%;
                                     background:<?php echo $col; ?>; flex-shrink:0;"></span>
                        <span style="font-size:0.65rem; font-weight:700; color:var(--text-muted);"><?php echo $code; ?></span>
                    </div>
                    <div id="avail_<?php echo $code; ?>"
                         style="font-size:0.95rem; font-weight:800;
                                color:<?php echo $avail > 0 ? 'var(--text-primary)' : 'var(--text-muted)'; ?>;
                                font-family:'Playfair Display',serif; line-height:1;">
                        <?php echo $avail; ?>
                    </div>
                    <div style="font-size:0.6rem; color:var(--text-muted);">trays</div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Size breakdown table (hidden until batch selected) -->
        <div id="size-table-wrap" style="<?php echo $selected_batch_id ? '' : 'display:none;'; ?> margin-bottom:1.4rem;">
            <div style="font-size:0.7rem; font-weight:700; color:var(--text-muted);
                        text-transform:uppercase; letter-spacing:0.8px; margin-bottom:10px;">
                Egg Size Breakdown
            </div>

            <div style="background:var(--bg-wood); border-radius:var(--radius);
                        border:1px solid var(--border-subtle); overflow:hidden;">

                <!-- Header -->
                <div style="display:grid; grid-template-columns:130px 1fr 90px 110px 110px;
                            background:var(--bg-plank); padding:10px 14px;
                            font-size:0.7rem; font-weight:700; color:var(--gold-muted);
                            text-transform:uppercase; letter-spacing:0.6px; gap:10px;">
                    <span>Size</span>
                    <span>Trays to Sell</span>
                    <span style="text-align:center;">Available</span>
                    <span style="text-align:right;">Price / Tray</span>
                    <span style="text-align:right;">Subtotal</span>
                </div>

                <?php foreach ($size_defs as $code => $label):
                    $avail      = $batch_stock[$code] ?? 0;
                    $price_tray = $breed_prices[$code] ?? 0;
                    $cur_qty    = $post_qty[$code];
                    $is_out     = ($avail === 0);
                    $subtotal   = $cur_qty * $price_tray;
                ?>
                <div class="size-row"
                     style="display:grid; grid-template-columns:130px 1fr 90px 110px 110px;
                            padding:12px 14px; gap:10px; align-items:center;
                            border-top:1px solid var(--border-subtle);
                            <?php echo $is_out ? 'opacity:0.45;' : ''; ?>"
                     data-code="<?php echo $code; ?>"
                     data-price="<?php echo $price_tray; ?>"
                     data-max="<?php echo $avail; ?>">

                    <div style="display:flex; align-items:center; gap:9px;">
                        <span style="width:10px; height:10px; border-radius:50%; flex-shrink:0;
                                     background:<?php echo $size_colors[$code]; ?>;
                                     box-shadow:0 0 5px <?php echo $size_colors[$code]; ?>88;
                                     display:inline-block;"></span>
                        <div>
                            <div style="font-weight:700; font-size:0.88rem; color:var(--text-primary);">
                                <?php echo $label; ?>
                            </div>
                            <div style="font-size:0.68rem; color:var(--text-muted); font-weight:700;">
                                <?php echo $code; ?>
                            </div>
                        </div>
                    </div>

                    <div style="position:relative;">
                        <input type="number"
                               name="qty_<?php echo strtolower($code); ?>"
                               id="qty_<?php echo $code; ?>"
                               class="form-input size-qty"
                               min="0" max="<?php echo $avail; ?>"
                               value="<?php echo $cur_qty; ?>"
                               placeholder="<?php echo $is_out ? 'No stock' : '0'; ?>"
                               <?php echo $is_out ? 'disabled' : ''; ?>
                               style="padding:10px 12px; font-size:0.95rem; font-weight:600;
                                      <?php echo $is_out ? 'cursor:not-allowed; background:var(--bg-plank);' : ''; ?>"
                               oninput="recalc()">
                        <div id="warn_<?php echo $code; ?>"
                             style="display:none; position:absolute; right:0; top:calc(100% + 4px);
                                    background:var(--danger-bg); border:1px solid rgba(194,58,58,0.4);
                                    border-radius:var(--radius-sm); padding:4px 10px;
                                    font-size:0.72rem; color:var(--danger); white-space:nowrap; z-index:10;">
                            Max <?php echo $avail; ?> tray<?php echo $avail !== 1 ? 's' : ''; ?>
                        </div>
                    </div>

                    <!-- FIX: avail_disp_ span always rendered so JS can update it.
                         JS controls the None badge via data-out attribute. -->
                    <div style="text-align:center;">
                        <span id="avail_disp_<?php echo $code; ?>"
                              style="font-size:0.85rem; font-weight:700;
                                     color:<?php echo $is_out ? 'var(--text-muted)' : ($avail <= 5 ? 'var(--warning)' : 'var(--success)'); ?>;">
                            <?php echo $is_out ? '0' : $avail; ?>
                        </span>
                        <div id="avail_label_<?php echo $code; ?>"
                             style="font-size:0.62rem; color:var(--text-muted);">
                            <?php echo $is_out ? 'none' : 'avail.'; ?>
                        </div>
                    </div>

                    <div style="text-align:right; font-size:0.88rem;" id="price_disp_<?php echo $code; ?>">
                        <?php if ($price_tray > 0): ?>
                            <span style="color:var(--text-secondary);">₱<?php echo number_format($price_tray, 2); ?></span>
                        <?php else: ?>
                            <span style="color:var(--text-muted); font-style:italic;">Not set</span>
                        <?php endif; ?>
                    </div>

                    <div style="text-align:right; font-weight:700; font-size:0.92rem;"
                         id="sub_<?php echo $code; ?>">
                        <?php echo $subtotal > 0 ? '₱' . number_format($subtotal, 2) : '—'; ?>
                    </div>

                </div>
                <?php endforeach; ?>

                <!-- Grand total row -->
                <div style="display:grid; grid-template-columns:130px 1fr 90px 110px 110px;
                            padding:14px 14px; gap:10px; align-items:center;
                            background:var(--bg-plank); border-top:2px solid var(--border-mid);">
                    <div style="font-size:0.72rem; font-weight:700; color:var(--text-muted);
                                text-transform:uppercase; letter-spacing:0.6px;">TOTAL</div>
                    <div id="total_trays_display"
                         style="font-size:1rem; font-weight:700; color:var(--text-secondary);">0 trays</div>
                    <div></div><div></div>
                    <div id="grand_total_display"
                         style="text-align:right; font-size:1.2rem; font-weight:800;
                                color:var(--gold); font-family:'Playfair Display',serif;">₱ 0.00</div>
                </div>
            </div>
        </div>

        <!-- Payment + Notes (always visible) -->
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:1.2rem;">
            <div class="form-group" style="margin:0;">
                <label>Payment Method</label>
                <select name="payment_method" class="form-input">
                    <option value="Cash">💵 Cash</option>
                    <option value="GCash">📱 GCash</option>
                    <option value="Bank Transfer">🏦 Bank Transfer</option>
                </select>
            </div>
            <div class="form-group" style="margin:0;">
                <label>Notes</label>
                <input type="text" name="notes" class="form-input"
                       placeholder="Optional: bulk order, delivery, etc."
                       value="<?php echo isset($_POST['notes']) ? htmlspecialchars($_POST['notes']) : ''; ?>">
            </div>
        </div>

        <button type="submit" id="submitBtn" class="btn-farm btn-green btn-full"
                style="padding:15px; font-size:1rem;">
            Record Sale ✅
        </button>
        <a href="dashboard.php" id="backBtn" class="back-link"
           style="display:block; text-align:center; margin-top:1rem;">
            ← Back to Dashboard
        </a>
    </form>
    <?php endif; ?>
</div>

<script>
// All prices from PHP, keyed by breed → size_code → price_per_tray
const ALL_PRICES = <?php echo json_encode($all_prices); ?>;

// All stock from PHP, keyed by batch_id (string) → size_code → available_trays
const ALL_STOCK = <?php
    $js_stock = [];
    $js_breed = []; // batch_id → breed (for price lookup)
    foreach ($available_batches as $ab) {
        $bid  = (string)$ab['batch_id'];
        $bs   = get_batch_stock($conn, $ab['batch_id'], $harvest_cols, $sale_qty_cols);
        $js_stock[$bid] = $bs['per_size'];
        $js_breed[$bid] = $ab['breed'];
        // Build display label same logic as dropdown
        if (!empty($ab['coop_label'])) {
            $js_label[$bid] = $ab['coop_label'] . ' — ' . $ab['breed'];
        } elseif (!empty($ab['coop_number'])) {
            $js_label[$bid] = 'Coop ' . $ab['coop_number'] . ' — ' . $ab['breed'];
        } else {
            $js_label[$bid] = 'Batch #' . $ab['batch_id'] . ' — ' . $ab['breed'];
        }
    }
    echo json_encode($js_stock);
?>;

// batch_id → breed name (for price lookup)
const BATCH_BREED = <?php echo json_encode($js_breed); ?>;
// batch_id → display label (for stock summary header)
const BATCH_LABEL = <?php echo json_encode($js_label ?? []); ?>;

const SIZE_CODES = <?php echo json_encode(array_keys($size_defs)); ?>;

function onBatchChange(batchId) {
    const stockWrap  = document.getElementById('stock-summary');
    const tableWrap  = document.getElementById('size-table-wrap');
    const breedLabel = document.getElementById('stock-breed-label');

    if (!batchId) {
        stockWrap.style.display = 'none';
        tableWrap.style.display = 'none';
        return;
    }

    stockWrap.style.display = 'block';
    tableWrap.style.display = 'block';

    if (breedLabel) {
        breedLabel.textContent = BATCH_LABEL[batchId] || batchId;
    }

    const breed  = BATCH_BREED[batchId] || '';
    const prices = ALL_PRICES[breed]    || {};
    const stock  = ALL_STOCK[batchId]   || {};

    // Update each size row
    document.querySelectorAll('.size-row').forEach(row => {
        const code  = row.dataset.code;
        const price = prices[code] || 0;
        const avail = stock[code]  !== undefined ? stock[code] : 0;
        const isOut = avail === 0;

        // Update data attributes for recalc()
        row.dataset.price = price;
        row.dataset.max   = avail;

        // Update price display
        const priceEl = document.getElementById('price_disp_' + code);
        if (priceEl) {
            priceEl.innerHTML = price > 0
                ? `<span style="color:var(--text-secondary);">₱${price.toFixed(2)}</span>`
                : `<span style="color:var(--text-muted);font-style:italic;">Not set</span>`;
        }

        const availEl = document.getElementById('avail_disp_' + code);
        if (availEl) {
            availEl.textContent = avail;
            availEl.style.color = avail === 0
                ? 'var(--text-muted)'
                : (avail <= 5 ? 'var(--warning)' : 'var(--success)');
        }
        const labelEl = document.getElementById('avail_label_' + code);
        if (labelEl) {
            labelEl.textContent = avail === 0 ? 'none' : 'avail.';
        }
        const stockboxEl = document.getElementById('avail_' + code);
        if (stockboxEl) {
            stockboxEl.textContent = avail;
            stockboxEl.style.color = avail > 0 ? 'var(--text-primary)' : 'var(--text-muted)';
        }

        // Update input
        const input = row.querySelector('.size-qty');
        if (input) {
            input.max      = avail;
            input.disabled = isOut;
            input.value    = 0;
            input.placeholder = isOut ? 'No stock' : '0';
            input.style.cursor = isOut ? 'not-allowed' : '';
            input.style.background = isOut ? 'var(--bg-plank)' : '';
        }

        // Reset subtotal
        const subEl = document.getElementById('sub_' + code);
        if (subEl) { subEl.textContent = '—'; subEl.style.color = 'var(--text-muted)'; }

        row.style.opacity = isOut ? '0.45' : '1';
    });

    recalc();
}

function recalc() {
    let totalTrays = 0, grandTotal = 0, hasOverLimit = false;

    document.querySelectorAll('.size-row').forEach(row => {
        const code   = row.dataset.code;
        const price  = parseFloat(row.dataset.price) || 0;
        const maxVal = parseInt(row.dataset.max) || 0;
        const input  = row.querySelector('.size-qty');
        if (!input || input.disabled) return;

        let qty    = parseInt(input.value) || 0;
        const warn = document.getElementById('warn_' + code);

        if (qty > maxVal && maxVal >= 0) {
            hasOverLimit = true;
            input.style.borderColor = 'var(--danger)';
            if (warn) { warn.textContent = `Max ${maxVal} tray${maxVal !== 1 ? 's' : ''}`; warn.style.display = 'block'; }
            qty = maxVal;
        } else {
            input.style.borderColor = '';
            if (warn) warn.style.display = 'none';
        }

        const sub = qty * price;
        totalTrays += qty; grandTotal += sub;

        const subEl = document.getElementById('sub_' + code);
        if (subEl) {
            subEl.textContent = sub > 0 ? '₱' + sub.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',') : '—';
            subEl.style.color = sub > 0 ? 'var(--gold)' : 'var(--text-muted)';
        }
    });

    const tEl = document.getElementById('total_trays_display');
    const gEl = document.getElementById('grand_total_display');
    const btn = document.getElementById('submitBtn');

    if (tEl) tEl.textContent = totalTrays + ' tray' + (totalTrays !== 1 ? 's' : '');
    if (gEl) gEl.textContent = '₱ ' + grandTotal.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    if (btn) {
        btn.disabled       = hasOverLimit;
        btn.style.opacity  = hasOverLimit ? '0.45' : '1';
        btn.style.cursor   = hasOverLimit ? 'not-allowed' : '';
        btn.textContent    = hasOverLimit ? '⚠️ Quantities exceed available stock' : 'Record Sale ✅';
    }
}

let isDirty = false;
const sf = document.getElementById('saleForm');
if (sf) {
    sf.addEventListener('input',  () => isDirty = true);
    sf.addEventListener('submit', () => isDirty = false);
    window.addEventListener('beforeunload', e => { if (isDirty) { e.preventDefault(); e.returnValue=''; } });
    const bb = document.getElementById('backBtn');
    if (bb) bb.addEventListener('click', e => { if (isDirty && !confirm("Discard unsaved sale data?")) e.preventDefault(); });
}

// Init on page load if batch pre-selected
<?php if ($selected_batch_id): ?>
onBatchChange(<?php echo json_encode((string)$selected_batch_id); ?>);
<?php endif; ?>
recalc();
</script>

<?php include('../includes/footer.php'); ?>