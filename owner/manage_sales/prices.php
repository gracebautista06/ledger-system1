<?php
/* ============================================================
   owner/manage_flock/prices.php — Breed-Based Egg Pricing

   HOW IT WORKS:
   - Prices are now per BREED × per SIZE (6 sizes × N breeds)
   - Each breed that exists in the batches table automatically
     gets its own pricing section. No manual setup needed.
   - When a new breed is added in manage_batches.php, it appears
     here automatically with empty prices waiting to be set.
   - Saves to breed_prices table (breed + size_code = unique key)
   ============================================================ */

$page_title = 'Manage Prices';

session_start();
include('../../includes/db.php');
include('../../includes/header.php');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Owner') {
    header("Location: ../../portal/login.php"); exit();
}

$message = "";

// ── HANDLE SAVE ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $allowed_sizes = ['PW','S','M','L','XL','J'];
    $updated = 0;

    // POST fields are named: price_{breed_slug}_{size}
    // breed_slug = base64_encode(breed) to safely handle special chars in field names
    foreach ($_POST as $key => $val) {
        if (!str_starts_with($key, 'price_')) continue;

        // Extract encoded breed + size from field name
        // Format: price_{base64breed}_{SIZE}
        $parts = explode('_', $key, 3); // ['price', base64breed, SIZE]
        if (count($parts) !== 3) continue;

        $breed_b64 = $parts[1];
        $size_code = strtoupper($parts[2]);

        if (!in_array($size_code, $allowed_sizes)) continue;

        $breed = base64_decode($breed_b64);
        if (!$breed) continue;

        $price_tray  = max(0, floatval($val));
        $price_piece = round($price_tray / 30, 4);
        $breed_esc   = $conn->real_escape_string($breed);
        $size_esc    = $conn->real_escape_string($size_code);

        // Upsert: update if exists, insert if new
        $conn->query("
            INSERT INTO breed_prices (breed, size_code, price_per_tray, price_per_piece)
            VALUES ('$breed_esc', '$size_esc', $price_tray, $price_piece)
            ON DUPLICATE KEY UPDATE
                price_per_tray  = $price_tray,
                price_per_piece = $price_piece
        ");
        $updated++;
    }

    if ($updated > 0) {
        $message = "<div class='alert success'>Prices saved for $updated size-breed combination(s).</div>";
    }
}

// ── FETCH ALL DISTINCT BREEDS FROM ACTIVE + RETIRED BATCHES ──
// This ensures any breed with a batch shows up, even if no prices set yet.
$breeds_q = $conn->query("
    SELECT DISTINCT breed FROM batches
    ORDER BY breed ASC
");
$breeds = [];
if ($breeds_q) {
    while ($row = $breeds_q->fetch_assoc()) {
        $breeds[] = $row['breed'];
    }
}

// ── FETCH EXISTING PRICES (keyed breed → size → row) ─────────
$prices_q = $conn->query("SELECT * FROM breed_prices ORDER BY breed ASC, FIELD(size_code,'PW','S','M','L','XL','J')");
$saved_prices = []; // $saved_prices[$breed][$size_code] = row
if ($prices_q) {
    while ($row = $prices_q->fetch_assoc()) {
        $saved_prices[$row['breed']][$row['size_code']] = $row;
    }
}

$size_meta = [
    'PW' => ['label' => 'Peewee',      'color' => '#adb5bd'],
    'S'  => ['label' => 'Small',        'color' => '#74c0fc'],
    'M'  => ['label' => 'Medium',       'color' => '#51cf66'],
    'L'  => ['label' => 'Large',        'color' => '#fcc419'],
    'XL' => ['label' => 'Extra Large',  'color' => '#ff922b'],
    'J'  => ['label' => 'Jumbo',        'color' => '#f03e3e'],
];
?>

<div style="max-width:860px; margin:2rem auto;">

    <div class="page-header">
        <div>
            <h2> Egg Pricing by Breed</h2>
            <p>
                Set prices per tray (30 eggs) for each egg size, per breed.
                New breeds added in Flock Batches appear here automatically.
            </p>
        </div>
    </div>

    <?php echo $message; ?>

    <?php if (empty($breeds)): ?>
    <div class="card">
        <div class="empty-state">
            <span class="empty-icon"></span>
            <p>No breeds found.</p>
            <small>Add a flock batch first in
                <a href="batches.php" style="color:var(--gold);">Manage Batches</a>.
                Each breed you add will automatically appear here for pricing.
            </small>
        </div>
    </div>
    <?php else: ?>

    <form method="POST" id="price-form">
        <div class="breed-grid">
        <?php foreach ($breeds as $breed):
            $breed_b64  = base64_encode($breed);
            $breed_data = $saved_prices[$breed] ?? [];
            $any_set    = !empty($breed_data);
        ?>

        <!-- ── ONE BREED SECTION ─────────────────────────────── -->
        <div class="price-card <?php echo $is_configured ? 'configured' : 'not-configured'; ?>">

            <!-- Breed header -->
            <div style="display:flex; justify-content:space-between; align-items:center;
                        margin-bottom:1.2rem; flex-wrap:wrap; gap:8px;">
                <div>
                    <h3 style="margin:0; font-size:1rem; color:var(--text-primary);">
                         <?php echo htmlspecialchars($breed); ?>
                    </h3>
                    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:3px;">
                        <?php if ($any_set): ?>
                            <span style="color:var(--success);">✓ Prices configured</span>
                        <?php else: ?>
                            <span style="color:var(--warning);"> No prices set yet</span>
                        <?php endif; ?>
                    </div>
                </div>
                <!-- Copy from another breed button — future enhancement placeholder -->
            </div>

            <!-- Size rows -->
            <div class="price-grid">
                <?php foreach ($size_meta as $code => $meta):
                    $field_name    = 'price_' . $breed_b64 . '_' . $code;
                    $saved_row     = $breed_data[$code] ?? null;
                    $current_tray  = $saved_row ? (float)$saved_row['price_per_tray']  : 0;
                    $current_piece = $saved_row ? (float)$saved_row['price_per_piece'] : 0;
                    $updated_time  = $saved_row ? $saved_row['updated_at'] : null;
                    $field_js_id   = 'price_' . base64_encode($breed) . '_' . $code;
                    $piece_id      = 'piece_' . $breed_b64 . '_' . $code;
                ?>
                <div class="price-item">

                    <!-- Size dot + label -->
                    <div style="display:flex; align-items:center; gap:9px; width:120px; flex-shrink:0;">
                        <span style="width:11px; height:11px; border-radius:50%; flex-shrink:0;
                                     background:<?php echo $meta['color']; ?>;
                                     box-shadow:0 0 5px <?php echo $meta['color']; ?>66;
                                     display:inline-block;"></span>
                        <div>
                            <div style="font-weight:700; font-size:0.88rem; color:var(--text-primary);">
                                <?php echo $meta['label']; ?>
                            </div>
                            <div style="font-size:0.68rem; color:var(--text-muted); font-weight:700;">
                                <?php echo $code; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Price input -->
                    <div style="flex:1;">
                        <label style="font-size:0.68rem; font-weight:700; color:var(--text-muted);
                                      text-transform:uppercase; letter-spacing:0.6px; display:block; margin-bottom:4px;">
                            Per Tray (₱)
                        </label>
                        <input type="number"
                               name="<?php echo $field_name; ?>"
                               id="<?php echo htmlspecialchars($field_js_id); ?>"
                               class="form-input" style="margin:0;"
                               step="0.01" min="0" placeholder="0.00"
                               value="<?php echo $current_tray > 0 ? number_format($current_tray, 2, '.', '') : ''; ?>"
                               oninput="updatePiece('<?php echo addslashes($breed_b64); ?>', '<?php echo $code; ?>')">
                    </div>

                    <!-- Per-piece live preview -->
                    <div style="text-align:right; min-width:110px; flex-shrink:0;">
                        <div style="font-size:0.65rem; font-weight:700; color:var(--text-muted);
                                    text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;">
                            Per Piece
                        </div>
                        <div id="<?php echo $piece_id; ?>"
                             style="font-size:0.9rem; font-weight:700; color:var(--gold);">
                            <?php echo $current_piece > 0 ? '≈ ₱' . number_format($current_piece, 4) : '—'; ?>
                        </div>
                        <?php if ($updated_time): ?>
                        <div style="font-size:0.65rem; color:var(--text-muted); margin-top:3px;">
                            <?php echo date('M d, Y', strtotime($updated_time)); ?>
                        </div>
                        <?php endif; ?>
                    </div>

                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </form>
    <?php endif; ?>
    </div>
    
        <!-- Info note -->
    <div style="background:var(--info-bg); padding:12px 16px; border-radius:var(--radius-sm);
                    font-size:0.83rem; color:#6AABDE; border-left:4px solid var(--info);
                    margin-bottom:1.4rem;">
             Per-piece price = tray price ÷ 30. Updates live as you type. Prices are breed-specific —
            changing one breed's price does not affect other breeds.
    </div>

        <button type="submit" class="btn-farm btn-orange btn-full" style="padding:14px; font-size:1rem;">
             Save
        </button>                        
    <a href="../dashboard.php" class="back-link">← Back to Dashboard</a>
</div>

<script>
function updatePiece(breedB64, code) {
    const fieldId = 'price_' + breedB64 + '_' + code;
    const pieceId = 'piece_' + breedB64 + '_' + code;
    const input   = document.getElementById(fieldId);
    const pieceEl = document.getElementById(pieceId);
    if (!input || !pieceEl) return;
    const tray = parseFloat(input.value) || 0;
    pieceEl.textContent = tray > 0 ? '≈ ₱' + (tray / 30).toFixed(4) : '—';
}
</script>

<?php include('../../includes/footer.php'); ?>