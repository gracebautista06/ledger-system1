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
        $breed_esc   = $conn->real_escape_string($breed);
        $size_esc    = $conn->real_escape_string($size_code);

        // If price is zero/empty, delete the row instead of saving a zero
        if ($price_tray <= 0) {
            $conn->query("
                DELETE FROM breed_prices
                WHERE breed = '$breed_esc' AND size_code = '$size_esc'
            ");
            continue;
        }

        $price_piece = round($price_tray / 30, 4);

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

// ── ENSURE hidden_breeds TABLE EXISTS ────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS hidden_breeds (
        breed VARCHAR(120) NOT NULL PRIMARY KEY
    )
");

// ── HANDLE HIDE BREED ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hide_breed'])) {
    $hide_breed = base64_decode($_POST['hide_breed']);
    if ($hide_breed) {
        $hide_esc = $conn->real_escape_string($hide_breed);
        $conn->query("INSERT IGNORE INTO hidden_breeds (breed) VALUES ('$hide_esc')");
        $message = "<div class='alert success'>
            <strong>" . htmlspecialchars($hide_breed) . "</strong> is now hidden from this page.
            <a href='?show_hidden=1' style='color:inherit;text-decoration:underline;margin-left:8px;'>Undo</a>
        </div>";
    }
}

// ── HANDLE UNHIDE (undo) ──────────────────────────────────────
if (isset($_GET['show_hidden'])) {
    $conn->query("DELETE FROM hidden_breeds");
    header("Location: prices.php"); exit();
}

// ── FETCH ALL DISTINCT BREEDS (excluding hidden) ──────────────
$breeds_q = $conn->query("
    SELECT DISTINCT breed FROM batches
    WHERE breed NOT IN (SELECT breed FROM hidden_breeds)
    ORDER BY breed ASC
");
$breeds = [];
if ($breeds_q) {
    while ($row = $breeds_q->fetch_assoc()) {
        $breeds[] = $row['breed'];
    }
}

// ── COUNT HIDDEN BREEDS ───────────────────────────────────────
$hidden_count_q = $conn->query("SELECT COUNT(*) AS n FROM hidden_breeds");
$hidden_count   = $hidden_count_q ? (int)$hidden_count_q->fetch_assoc()['n'] : 0;

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
            </p>
        </div>
    </div>

    <?php echo $message; ?>

    <?php if ($hidden_count > 0): ?>
    <div style="background:var(--bg-wood); border:1px solid var(--border-mid); border-radius:var(--radius-sm);
                padding:10px 16px; margin-bottom:1.2rem; font-size:0.82rem; color:var(--text-muted);
                display:flex; justify-content:space-between; align-items:center;">
        <span><?php echo $hidden_count; ?> breed table<?php echo $hidden_count > 1 ? 's' : ''; ?> hidden from this page.</span>
        <a href="?show_hidden=1" style="color:var(--gold); font-weight:700; text-decoration:none;">Restore all</a>
    </div>
    <?php endif; ?>

    <?php if (empty($breeds)): ?>
    <div class="card">
        <div class="empty-state">
            <span class="empty-icon"></span>
            <p>No breeds found.</p>
            <small>Add a flock batch first in
                <a href="batches.php" style="color:var(--gold);">Manage Batches</a>.
               
            </small>
        </div>
    </div>
    <?php else: ?>

    <form method="POST" id="price-form">
        <div class="breed-grid">
        <?php foreach ($breeds as $breed):
            $breed_b64  = base64_encode($breed);
            $breed_data = $saved_prices[$breed] ?? [];
            $any_set    = !empty(array_filter($breed_data, fn($r) => (float)$r['price_per_tray'] > 0));
        ?>

        <!-- ── ONE BREED SECTION ─────────────────────────────── -->
        <div class="price-card <?php echo $any_set ? 'configured' : 'not-configured'; ?>">

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
                <div style="display:flex; gap:6px;">
                    <!-- Reset breed prices button -->
                    <button type="button"
                            onclick="resetBreed('<?php echo addslashes($breed_b64); ?>')"
                            title="Clear all prices for this breed (does not delete from database)"
                            style="background:transparent; border:1px solid var(--danger, #e03131);
                                   color:var(--danger, #e03131); border-radius:6px;
                                   width:32px; height:32px; display:flex; align-items:center;
                                   justify-content:center; cursor:pointer; transition:all 0.15s;
                                   flex-shrink:0;"
                            onmouseover="this.style.background='var(--danger,#e03131)';this.style.color='#fff';"
                            onmouseout="this.style.background='transparent';this.style.color='var(--danger,#e03131)';">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15"
                             viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                            <path d="M3 3v5h5"/>
                        </svg>
                    </button>

                    <!-- Hide breed card button -->
                    <button type="button"
                            onclick="confirmHideBreed('<?php echo addslashes($breed_b64); ?>', '<?php echo addslashes(htmlspecialchars($breed, ENT_QUOTES)); ?>')"
                            title="Hide this breed's pricing table from the page"
                            style="background:transparent; border:1px solid var(--border-mid);
                                   color:var(--text-muted); border-radius:6px;
                                   width:32px; height:32px; display:flex; align-items:center;
                                   justify-content:center; cursor:pointer; transition:all 0.15s;
                                   flex-shrink:0;"
                            onmouseover="this.style.borderColor='var(--text-muted)';this.style.color='var(--text-primary)';"
                            onmouseout="this.style.borderColor='var(--border-mid)';this.style.color='var(--text-muted)';">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15"
                             viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
                            <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
                            <line x1="1" y1="1" x2="23" y2="23"/>
                        </svg>
                    </button>
                </div>
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
        </div><!-- /.breed-grid -->

        <!-- Info note -->
        <div style="background:var(--info-bg); padding:12px 16px; border-radius:var(--radius-sm);
                    font-size:0.83rem; color:#6AABDE; border-left:4px solid var(--info);
                    margin-bottom:1.4rem; margin-top:1.4rem;">
            Per-piece price = tray price ÷ 30. Updates live as you type. Prices are breed-specific —
            changing one breed's price does not affect other breeds.
        </div>

        <button type="submit" class="btn-farm btn-orange btn-full" style="padding:14px; font-size:1rem;">
            Save
        </button>
    </form>
    <?php endif; ?>

    <!-- Hide forms must live OUTSIDE the main price form (nested forms are invalid HTML) -->
    <?php foreach ($breeds as $breed):
        $breed_b64 = base64_encode($breed); ?>
    <form id="delete-form-<?php echo $breed_b64; ?>" method="POST" style="display:none;">
        <input type="hidden" name="hide_breed" value="<?php echo htmlspecialchars($breed_b64); ?>">
    </form>
    <?php endforeach; ?>
                           
    <a href="../dashboard.php" class="back-link">← Back to Dashboard</a>
</div>

<script>
function confirmHideBreed(breedB64, breedName) {
    if (!confirm('Hide the "' + breedName + '" pricing table?\n\nYou can restore it anytime from the notice at the top of this page.')) return;
    document.getElementById('delete-form-' + breedB64).submit();
}

function resetBreed(breedB64) {
    const sizes = ['PW', 'S', 'M', 'L', 'XL', 'J'];
    sizes.forEach(code => {
        const input   = document.getElementById('price_' + breedB64 + '_' + code);
        const pieceEl = document.getElementById('piece_' + breedB64 + '_' + code);
        if (input)   input.value = '';
        if (pieceEl) pieceEl.textContent = '—';
    });
}

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