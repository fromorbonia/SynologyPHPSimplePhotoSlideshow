<?php
/**
 * Slideshow Statistics Dashboard
 *
 * Server-side: scans the temp directory and collects stats from all index files.
 * Client-side: visualises the data with Chart.js (open-source, loaded from CDN).
 */

// ─────────────────────────────────────────────────────────────────────────────
//  Server-side data collection
// ─────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . DIRECTORY_SEPARATOR . 'statsfunctions.php';

$tempDir = __DIR__ . DIRECTORY_SEPARATOR . 'temp';

// ─────────────────────────────────────────────────────────────────────────────
//  If the request is for JSON data only (AJAX), return early
// ─────────────────────────────────────────────────────────────────────────────
if (isset($_GET['data']) && $_GET['data'] === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    // Basic protection: only serve to same origin
    echo json_encode(buildStatsPayload($tempDir), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
//  Stream the spinner shell immediately so the browser shows feedback
//  while PHP scans the index files.
// ─────────────────────────────────────────────────────────────────────────────

// Disable any output compression that would delay the first flush
if (function_exists('apache_setenv')) {
    apache_setenv('no-gzip', '1');
}
@ini_set('zlib.output_compression', 'Off');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Slideshow Statistics</title>

    <!-- Chart.js – open-source, MIT licence -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>

    <style>
        :root {
            --bg:        #1a1a2e;
            --surface:   #16213e;
            --card:      #0f3460;
            --accent:    #e94560;
            --accent2:   #53d8fb;
            --text:      #e0e0e0;
            --subtext:   #a0a0b0;
            --border:    #2a2a4e;
            --radius:    10px;
        }

        /* ── loading overlay ─────────────────────────────────────── */
        #loading-overlay {
            position: fixed;
            inset: 0;
            background: var(--bg);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 20px;
            z-index: 9999;
            transition: opacity 0.3s ease;
        }

        #loading-overlay.hidden {
            opacity: 0;
            pointer-events: none;
        }

        .spinner {
            width: 52px;
            height: 52px;
            border: 5px solid var(--border);
            border-top-color: var(--accent2);
            border-radius: 50%;
            animation: spin 0.9s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .loading-msg {
            color: var(--subtext);
            font-size: 0.95rem;
            letter-spacing: 0.04em;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: 'Segoe UI', system-ui, sans-serif;
            min-height: 100vh;
            padding: 20px;
        }

        h1 {
            font-size: 1.7rem;
            font-weight: 600;
            letter-spacing: 0.03em;
            margin-bottom: 4px;
        }

        .subtitle {
            color: var(--subtext);
            font-size: 0.85rem;
            margin-bottom: 24px;
        }

        /* ── summary cards ──────────────────────────────────────── */
        .summary-cards {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            margin-bottom: 28px;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px 22px;
            flex: 1 1 160px;
        }

        .card .label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--subtext);
            margin-bottom: 6px;
        }

        .card .value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--accent2);
        }

        /* ── errors ─────────────────────────────────────────────── */
        .error-box {
            background: #3d0a0a;
            border: 1px solid #8b0000;
            border-radius: var(--radius);
            padding: 14px 18px;
            margin-bottom: 20px;
            color: #ff9999;
        }

        /* ── chart sections ──────────────────────────────────────── */
        .section {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 20px;
            margin-bottom: 24px;
        }

        .section h2 {
            font-size: 1.05rem;
            font-weight: 600;
            margin-bottom: 16px;
            color: var(--accent2);
        }

        .chart-wrap {
            position: relative;
            width: 100%;
            max-height: 320px;
        }

        /* ── drill-down panel ────────────────────────────────────── */
        #drilldown-panel {
            display: none;
        }

        #drilldown-title {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 16px;
        }

        #drilldown-title span {
            color: var(--accent);
        }

        .back-btn {
            background: none;
            border: 1px solid var(--accent2);
            color: var(--accent2);
            border-radius: 6px;
            padding: 6px 14px;
            cursor: pointer;
            font-size: 0.85rem;
            margin-bottom: 16px;
        }

        .back-btn:hover {
            background: var(--accent2);
            color: var(--bg);
        }

        /* ── table ───────────────────────────────────────────────── */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
            margin-top: 16px;
        }

        .data-table th {
            text-align: left;
            padding: 8px 12px;
            background: var(--card);
            color: var(--subtext);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.73rem;
            letter-spacing: 0.06em;
        }

        .data-table td {
            padding: 8px 12px;
            border-top: 1px solid var(--border);
        }

        .data-table tr:hover td {
            background: #1f2a4a;
        }

        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            background: var(--accent);
            color: #fff;
        }

        /* ── tab bar ─────────────────────────────────────────────── */
        .tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .tab-btn {
            background: none;
            border: 1px solid var(--border);
            color: var(--subtext);
            border-radius: 6px;
            padding: 7px 16px;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all 0.15s;
        }

        .tab-btn.active, .tab-btn:hover {
            border-color: var(--accent2);
            color: var(--accent2);
            background: rgba(83, 216, 251, 0.06);
        }

        /* ── responsive ──────────────────────────────────────────── */
        @media (max-width: 600px) {
            .summary-cards { flex-direction: column; }
            .chart-wrap    { max-height: 260px; }
        }
    </style>
</head>
<body>

<!-- Loading overlay – visible immediately, hidden once JS runs -->
<div id="loading-overlay">
    <div class="spinner"></div>
    <div class="loading-msg">Scanning index files&hellip;</div>
</div>

<?php
// ── Flush the spinner to the browser BEFORE scanning ──────────────────────
// This ensures the user sees the animation while PHP reads the index files.
ob_flush();
flush();

// ── Do the actual file scanning now ───────────────────────────────────────
$stats     = buildStatsPayload($tempDir);
$statsJson = json_encode($stats, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
?>

<h1>📊 Slideshow Statistics</h1>
<p class="subtitle" id="generated-at"></p>

<!-- Error box (shown only when PHP detected problems) -->
<?php if (!empty($stats['errors'])): ?>
<div class="error-box">
    <?php foreach ($stats['errors'] as $err): ?>
        <div>⚠ <?= htmlspecialchars($err) ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Summary cards -->
<div class="summary-cards" id="summary-cards"></div>

<!-- Tab bar -->
<div class="tabs" id="tab-bar">
    <button class="tab-btn active" data-tab="overview">Overview</button>
    <button class="tab-btn" data-tab="views">Views</button>
    <button class="tab-btn" data-tab="photos">Photos</button>
    <button class="tab-btn" data-tab="table">Table</button>
</div>

<!-- Overview tab: playlist play counts -->
<div id="tab-overview" class="tab-panel">
    <div class="section">
        <h2>Playlist play counts <span style="font-weight:400;font-size:0.8em;color:var(--subtext)">(click a bar to drill into folders)</span></h2>
        <div class="chart-wrap"><canvas id="chart-playlists-plays"></canvas></div>
    </div>
</div>

<!-- Views tab: photo views per playlist -->
<div id="tab-views" class="tab-panel" style="display:none">
    <div class="section">
        <h2>Total photo views per playlist</h2>
        <div class="chart-wrap"><canvas id="chart-playlists-views"></canvas></div>
    </div>
</div>

<!-- Photos tab: photo count per playlist -->
<div id="tab-photos" class="tab-panel" style="display:none">
    <div class="section">
        <h2>Photo count per playlist</h2>
        <div class="chart-wrap"><canvas id="chart-playlists-photos"></canvas></div>
    </div>
</div>

<!-- Table tab -->
<div id="tab-table" class="tab-panel" style="display:none">
    <div class="section">
        <h2>Playlist summary table</h2>
        <table class="data-table" id="summary-table">
            <thead>
                <tr>
                    <th>Playlist</th>
                    <th>Folders</th>
                    <th>Photos</th>
                    <th>Photo views</th>
                    <th>Playlist plays</th>
                </tr>
            </thead>
            <tbody id="summary-tbody"></tbody>
        </table>
    </div>
</div>

<!-- Drill-down panel (replaces active tab content) -->
<div id="drilldown-panel" class="section">
    <button class="back-btn" id="back-btn">← Back</button>
    <div id="drilldown-title">Folders in <span></span></div>
    <div class="chart-wrap"><canvas id="chart-folders"></canvas></div>
    <table class="data-table" id="folder-table">
        <thead>
            <tr>
                <th>Folder</th>
                <th>Photos</th>
                <th>Photo views</th>
                <th>Folder plays</th>
            </tr>
        </thead>
        <tbody id="folder-tbody"></tbody>
    </table>
</div>

<script>
// ─────────────────────────────────────────────────────────────────────────────
//  Data injected by PHP
// ─────────────────────────────────────────────────────────────────────────────
const STATS = <?= $statsJson ?>;

// ─────────────────────────────────────────────────────────────────────────────
//  Colour palette (accessible, open-source inspired)
// ─────────────────────────────────────────────────────────────────────────────
const PALETTE = [
    '#e94560','#53d8fb','#f7b731','#26de81','#fd9644',
    '#a55eea','#2bcbba','#fc5c65','#45aaf2','#fed330',
];
function color(i) { return PALETTE[i % PALETTE.length]; }

// ─────────────────────────────────────────────────────────────────────────────
//  Helpers
// ─────────────────────────────────────────────────────────────────────────────
function fmt(n) { return Number(n).toLocaleString(); }

function makeBarChart(canvasId, labels, datasets, onClickCb) {
    const ctx = document.getElementById(canvasId).getContext('2d');
    return new Chart(ctx, {
        type: 'bar',
        data: { labels, datasets },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            onClick: onClickCb || null,
            plugins: {
                legend: { display: datasets.length > 1,
                    labels: { color: '#e0e0e0', font: { size: 12 } } },
                tooltip: {
                    callbacks: {
                        label: ctx => ` ${ctx.dataset.label || ''}: ${fmt(ctx.parsed.y)}`
                    }
                }
            },
            scales: {
                x: {
                    ticks: { color: '#a0a0b0', maxRotation: 35, font: { size: 11 } },
                    grid:  { color: '#2a2a4e' }
                },
                y: {
                    beginAtZero: true,
                    ticks: { color: '#a0a0b0', font: { size: 11 },
                             callback: v => fmt(v) },
                    grid:  { color: '#2a2a4e' }
                }
            }
        }
    });
}

// ─────────────────────────────────────────────────────────────────────────────
//  Summary cards
// ─────────────────────────────────────────────────────────────────────────────
function renderSummaryCards() {
    const playlists = STATS.playlists;
    const totalPlays  = playlists.reduce((s, p) => s + p.play_count,        0);
    const totalPhotos = playlists.reduce((s, p) => s + p.total_photos,      0);
    const totalViews  = playlists.reduce((s, p) => s + p.total_photo_views, 0);
    const totalFolders= playlists.reduce((s, p) => s + p.folder_count,      0);

    const cards = [
        { label: 'Playlists',     value: playlists.length },
        { label: 'Folders',       value: totalFolders },
        { label: 'Photos',        value: totalPhotos },
        { label: 'Playlist plays',value: totalPlays },
        { label: 'Photo views',   value: totalViews },
    ];

    const container = document.getElementById('summary-cards');
    container.innerHTML = cards.map(c => `
        <div class="card">
            <div class="label">${c.label}</div>
            <div class="value">${fmt(c.value)}</div>
        </div>`).join('');

    document.getElementById('generated-at').textContent =
        'Data as of ' + new Date(STATS.generated_at).toLocaleString();
}

// ─────────────────────────────────────────────────────────────────────────────
//  Overview charts
// ─────────────────────────────────────────────────────────────────────────────
let playlistChart = null;

function renderPlaylistCharts() {
    const playlists = STATS.playlists;
    const labels     = playlists.map(p => p.name);

    // Tab: overview – playlist plays
    const playsData = playlists.map(p => p.play_count);
    playlistChart = makeBarChart(
        'chart-playlists-plays',
        labels,
        [{
            label: 'Playlist plays',
            data:  playsData,
            backgroundColor: playlists.map((_, i) => color(i) + 'cc'),
            borderColor:     playlists.map((_, i) => color(i)),
            borderWidth: 1,
            borderRadius: 4,
        }],
        (event, elements) => {
            if (elements.length > 0) {
                showDrilldown(playlists[elements[0].index]);
            }
        }
    );

    // Tab: views – photo views
    makeBarChart(
        'chart-playlists-views',
        labels,
        [{
            label: 'Total photo views',
            data:  playlists.map(p => p.total_photo_views),
            backgroundColor: playlists.map((_, i) => color(i) + 'cc'),
            borderColor:     playlists.map((_, i) => color(i)),
            borderWidth: 1,
            borderRadius: 4,
        }]
    );

    // Tab: photos – photo count
    makeBarChart(
        'chart-playlists-photos',
        labels,
        [{
            label: 'Photo count',
            data:  playlists.map(p => p.total_photos),
            backgroundColor: playlists.map((_, i) => color(i) + 'cc'),
            borderColor:     playlists.map((_, i) => color(i)),
            borderWidth: 1,
            borderRadius: 4,
        }]
    );
}

// ─────────────────────────────────────────────────────────────────────────────
//  Summary table
// ─────────────────────────────────────────────────────────────────────────────
function renderSummaryTable() {
    const tbody = document.getElementById('summary-tbody');
    tbody.innerHTML = STATS.playlists.map((p, i) => `
        <tr>
            <td><span class="badge" style="background:${color(i)}">${p.name}</span></td>
            <td>${fmt(p.folder_count)}</td>
            <td>${fmt(p.total_photos)}</td>
            <td>${fmt(p.total_photo_views)}</td>
            <td>${fmt(p.play_count)}</td>
        </tr>`).join('');
}

// ─────────────────────────────────────────────────────────────────────────────
//  Drill-down: folder view for a specific playlist
// ─────────────────────────────────────────────────────────────────────────────
let folderChart = null;

function showDrilldown(playlist) {
    // Hide tab panels, show drilldown
    document.querySelectorAll('.tab-panel').forEach(el => el.style.display = 'none');
    const panel = document.getElementById('drilldown-panel');
    panel.style.display = 'block';

    document.querySelector('#drilldown-title span').textContent = playlist.name;

    const folders = playlist.folders;
    const labels  = folders.map(f => f.name);

    // Destroy previous chart if any
    if (folderChart) { folderChart.destroy(); folderChart = null; }

    folderChart = makeBarChart(
        'chart-folders',
        labels,
        [
            {
                label: 'Folder plays',
                data:  folders.map(f => f.play_count),
                backgroundColor: '#e9456099',
                borderColor:     '#e94560',
                borderWidth: 1,
                borderRadius: 4,
            },
            {
                label: 'Photo views',
                data:  folders.map(f => f.photo_views),
                backgroundColor: '#53d8fb99',
                borderColor:     '#53d8fb',
                borderWidth: 1,
                borderRadius: 4,
            },
            {
                label: 'Photo count',
                data:  folders.map(f => f.photo_count),
                backgroundColor: '#f7b73199',
                borderColor:     '#f7b731',
                borderWidth: 1,
                borderRadius: 4,
            },
        ]
    );

    const tbody = document.getElementById('folder-tbody');
    tbody.innerHTML = folders.map(f => `
        <tr>
            <td>${escapeHtml(f.name)}</td>
            <td>${fmt(f.photo_count)}</td>
            <td>${fmt(f.photo_views)}</td>
            <td>${fmt(f.play_count)}</td>
        </tr>`).join('');
}

function hideDrilldown() {
    document.getElementById('drilldown-panel').style.display = 'none';
    // Restore currently active tab
    const activeTab = document.querySelector('.tab-btn.active');
    if (activeTab) showTab(activeTab.dataset.tab);
}

// ─────────────────────────────────────────────────────────────────────────────
//  Tab switching
// ─────────────────────────────────────────────────────────────────────────────
function showTab(name) {
    document.querySelectorAll('.tab-panel').forEach(el => el.style.display = 'none');
    const panel = document.getElementById('tab-' + name);
    if (panel) panel.style.display = '';

    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.tab === name);
    });
}

document.getElementById('tab-bar').addEventListener('click', e => {
    const btn = e.target.closest('.tab-btn');
    if (!btn) return;
    document.getElementById('drilldown-panel').style.display = 'none';
    showTab(btn.dataset.tab);
});

document.getElementById('back-btn').addEventListener('click', hideDrilldown);

// ─────────────────────────────────────────────────────────────────────────────
//  Utility
// ─────────────────────────────────────────────────────────────────────────────
function escapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

// ─────────────────────────────────────────────────────────────────────────────
//  Bootstrap
// ─────────────────────────────────────────────────────────────────────────────

// Hide the loading overlay first (fade out via CSS transition)
(function () {
    const overlay = document.getElementById('loading-overlay');
    if (overlay) {
        overlay.classList.add('hidden');
        overlay.addEventListener('transitionend', () => overlay.remove(), { once: true });
    }
})();

renderSummaryCards();
renderPlaylistCharts();
renderSummaryTable();
</script>
</body>
</html>
