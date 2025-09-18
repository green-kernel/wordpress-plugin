<?php
/**
 * Plugin Name: WP Sustainability Monitor
 * Description: Adds a Sustainability admin page that reads /proc/energy/cgroup and graphs energy values in real time (1s).
 * Version: 1.0.0
 * Author: Didi Hoffmann  <didi@green-coding.io>
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSustainabilityMonitor {
    const CAPABILITY = 'manage_options';
    const DEFAULT_FILE = '/proc/energy/cgroup';
    const NONCE_ACTION = 'wp_sustainability_energy_nonce';
    const AJAX_ACTION  = 'wp_sustainability_get_energy';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'ajax_get_energy']);
    }

    public function add_menu() {
        add_menu_page(
            __('Sustainability', 'wp-sustainability-monitor'),
            __('Sustainability', 'wp-sustainability-monitor'),
            self::CAPABILITY,
            'wp-sustainability-monitor',
            [$this, 'render_page'],
            'dashicons-chart-line',
            65
        );
    }

    public function enqueue($hook) {
        if ($hook !== 'toplevel_page_wp-sustainability-monitor') {
            return;
        }
        // Chart.js from CDN
        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js',
            [],
            null,
            true
        );

        // Our page JS (inline for simplicity)
        $nonce = wp_create_nonce(self::NONCE_ACTION);
        $ajax_url = admin_url('admin-ajax.php');

        $config = [
            'ajaxUrl'   => $ajax_url,
            'action'    => self::AJAX_ACTION,
            'nonce'     => $nonce,
            'interval'  => 1000,   // ms
            'maxPoints' => 300     // keep last 5 minutes at 1s interval
        ];
        wp_register_script('wp-sustainability-monitor-js', '');
        wp_enqueue_script('wp-sustainability-monitor-js');

        wp_add_inline_script('wp-sustainability-monitor-js', 'window.WPSustainabilityConfig = ' . wp_json_encode($config) . ';', 'before');
        wp_add_inline_script('wp-sustainability-monitor-js', $this->page_js());
    }

    public function render_page() {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Sustainability', 'wp-sustainability-monitor'); ?></h1>
            <p>Your server uses a lot of resources to run WordPress. Monitoring this will give you insights into how to optimize your installation.</p>
            <p>You need to install the <a href="https://github.com/green-kernel/procpower">ProcPower</a> kernel extension on the host system. This page then reads <code><?php echo esc_html($this->get_energy_file_path()); ?></code> once per second and graphs per-PID <code>energy</code> values.</p>
            <div id="wp-sustainability-status" style="margin:8px 0; font-weight:600;">Status: <span id="wp-sustainability-status-text">Initializing…</span></div>

            <div style="display:flex; gap:24px; align-items:flex-start; flex-wrap:wrap;">
                <div style="flex:1 1 700px; min-width:360px;">
                    <canvas id="wp-sustainability-chart" height="260"></canvas>
                </div>
                <div style="flex:1 1 420px; min-width:320px;">
                    <div style="background:#fff;border:1px solid #ccd0d4;border-radius:6px;padding:12px;">
                        <h2 style="margin-top:0;">Latest readings (per PID)</h2>
                        <div><strong>Timestamp:</strong> <span id="wp-sustainability-ts">—</span></div>
                        <div style="margin-top:8px;overflow:auto;max-height:360px;">
                            <table class="widefat striped" style="margin-top:8px;">
                                <thead>
                                    <tr>
                                        <th>PID</th>
                                        <th>comm</th>
                                        <th style="text-align:right;">energy (kWh)</th>
                                    </tr>
                                </thead>
                                <tbody id="wp-sustainability-table-body">
                                    <tr><td colspan="3">—</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <details style="margin-top:12px;">
                            <summary>Raw file</summary>
                            <pre id="wp-sustainability-raw" style="background:#f6f7f7;padding:8px;border-radius:4px;overflow:auto;max-height:180px;">—</pre>
                        </details>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function ajax_get_energy() {
        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $file = $this->get_energy_file_path();

        if (!is_readable($file)) {
            wp_send_json_error(['message' => "File not readable: $file"], 500);
        }

        $content = @file_get_contents($file);
        if ($content === false) {
            wp_send_json_error(['message' => "Unable to read: $file"], 500);
        }

        $lines = preg_split('/\r?\n/', trim($content));
        $entries = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            // Parse key=value pairs (values can be non-space tokens)
            if (preg_match_all('/(\w+)=([^\s]+)/', $line, $m, PREG_SET_ORDER)) {
                $kv = [];
                foreach ($m as $pair) {
                    $k = strtolower($pair[1]);
                    $v = $pair[2];
                    // numeric cast when appropriate
                    if (is_numeric($v)) {
                        $v = $v + 0; // int or float
                    }
                    $kv[$k] = $v;
                }
                // Require at least pid, comm, energy
                if (isset($kv['pid']) && isset($kv['comm']) && isset($kv['energy'])) {
                    $energy_uJ  = (float)$kv['energy'];
                    $energy_kWh = $energy_uJ * 2.7777777777778e-13; // µJ -> kWh

                    $entries[] = [
                        'pid'    => (int)$kv['pid'],
                        'comm'   => (string)$kv['comm'],
                        'energy' => $energy_kWh,
                        // include all fields in case you want them on the page/table later
                        'all'    => $kv,
                        'raw'    => $line,
                    ];
                }
            }
        }

        if (empty($entries)) {
            wp_send_json_error([
                'message' => 'Could not parse any entries (expected lines of key=value with pid, comm, energy).',
                'raw'     => $content,
            ], 422);
        }

        wp_send_json_success([
            'timestamp' => time(),
            'file'      => $file,
            'raw'       => $content,
            'entries'   => $entries
        ]);
    }
    private function get_energy_file_path() {
        $default = self::DEFAULT_FILE;
        // This is some logic to make this configurable in the future
        // $path = apply_filters('wp_sustainability_energy_file_path', $default);

        // // Basic safety: only allow absolute paths and keep length sane
        // if (!is_string($path) || strlen($path) > 512 || strpos($path, '..') !== false || $path[0] !== '/') {
        //     return $default;
        // }
        return $default;
    }

    private function page_js() {
        return <<<JS
            (function start() {
            'use strict';

            function init() {
                const cfg = window.WPSustainabilityConfig || {};
                const elStatus = document.getElementById('wp-sustainability-status-text');
                const elTS = document.getElementById('wp-sustainability-ts');
                const elRaw = document.getElementById('wp-sustainability-raw');
                const elTableBody = document.getElementById('wp-sustainability-table-body');
                const canvas = document.getElementById('wp-sustainability-chart');

                // Basic guards
                if (!canvas) {
                if (elStatus) elStatus.textContent = 'Error: chart canvas not found';
                return;
                }
                if (typeof Chart === 'undefined') {
                if (elStatus) elStatus.textContent = 'Error: Chart.js not loaded';
                return;
                }

                const ctx = canvas.getContext('2d');

                // Global x-axis labels (times) shared by all datasets
                const labels = [];
                // Map pid -> { dataset, lastSeenTick }
                const seriesByPid = new Map();
                let tickCount = 0;
                let timer = null;

                const chart = new Chart(ctx, {
                type: 'line',
                data: { labels, datasets: [] },
                options: {
                    animation: false,
                    responsive: true,
                    parsing: true,
                    interaction: { mode: 'nearest', intersect: false },
                    scales: {
                    x: { ticks: { maxRotation: 0, autoSkip: true }, grid: { display: false } },
                    y: { beginAtZero: false }
                    },
                    plugins: { legend: { display: true } }
                }
                });

                function fmtClock(ts) {
                const d = new Date(ts * 1000);
                return d.toLocaleTimeString([], { hour12: false });
                }

                function ensureDataset(pid, comm) {
                if (seriesByPid.has(pid)) {
                    const s = seriesByPid.get(pid);
                    const want = `\${comm} (pid \${pid})`;
                    if (s.dataset.label !== want) s.dataset.label = want;
                    return s;
                }
                const ds = {
                    label: `\${comm} (pid \${pid})`,
                    data: [],
                    tension: 0.2,
                    pointRadius: 0,
                    borderWidth: 2,
                    spanGaps: true
                };
                // backfill existing points with nulls for alignment
                for (let i = 0; i < labels.length; i++) ds.data.push(null);
                chart.data.datasets.push(ds);
                const entry = { dataset: ds, lastSeenTick: -1, seenThisTick: false };
                seriesByPid.set(pid, entry);
                return entry;
                }

                function pushTick(timestamp, entries) {
                labels.push(fmtClock(timestamp));
                tickCount++;

                // mark all unseen
                for (const s of seriesByPid.values()) s.seenThisTick = false;

                // add values for pids present this tick
                for (const e of entries) {
                    const pid = e.pid;
                    const comm = e.comm;
                    const energy = (typeof e.energy === 'number' && isFinite(e.energy)) ? e.energy : null;
                    const s = ensureDataset(pid, comm);
                    s.dataset.data.push(energy);
                    s.lastSeenTick = tickCount;
                    s.seenThisTick = true;
                }

                // for datasets not seen this tick, push null
                for (const s of seriesByPid.values()) {
                    if (!s.seenThisTick) s.dataset.data.push(null);
                }

                // Trim to maxPoints
                const maxPoints = Number.isFinite(cfg.maxPoints) ? cfg.maxPoints : 300;
                if (labels.length > maxPoints) {
                    const drop = labels.length - maxPoints;
                    labels.splice(0, drop);
                    for (const s of seriesByPid.values()) {
                    s.dataset.data.splice(0, drop);
                    }
                }

                chart.update('none');
                }

                function renderTable(entries) {
                const rows = entries
                    .slice()
                    .sort((a, b) => (b.energy || 0) - (a.energy || 0))
                    .map(e => {
                    const pid = String(e.pid);
                    const comm = String(e.comm);
                    const energy = (e.energy != null && isFinite(e.energy))
                        ? e.energy.toFixed(3)
                        : '—';                    return (
                        '<tr>' +
                        '<td>' + pid + '</td>' +
                        '<td>' + comm + '</td>' +
                        '<td style="text-align:right;">' + energy + '</td>' +
                        '</tr>'
                    );
                    });

                elTableBody.innerHTML = rows.join('') || '<tr><td colspan="3">No entries</td></tr>';
                }

                async function tick() {
                try {
                    const form = new URLSearchParams();
                    form.set('action', cfg.action || 'wp_sustainability_get_energy');
                    form.set('nonce', cfg.nonce || '');

                    const res = await fetch(cfg.ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: form.toString(),
                    credentials: 'same-origin',
                    cache: 'no-store'
                    });

                    const payload = await res.json();
                    if (!payload || (payload.success !== true && payload.success !== false)) {
                    throw new Error('Unexpected response');
                    }

                    if (!payload.success) {
                    if (elStatus) elStatus.textContent = 'Error: ' + (payload.data && payload.data.message ? payload.data.message : 'Unknown');
                    if (payload.data && payload.data.raw && elRaw) elRaw.textContent = payload.data.raw;
                    return;
                    }

                    const { timestamp, entries, raw } = payload.data;

                    if (elStatus) elStatus.textContent = 'OK';
                    if (elTS) elTS.textContent = new Date(timestamp * 1000).toLocaleString();
                    if (elRaw) elRaw.textContent = raw;

                    pushTick(timestamp, entries);
                    renderTable(entries);
                } catch (e) {
                    if (elStatus) elStatus.textContent = 'Fetch error: ' + e.message;
                }
                }

                // start polling
                tick();
                const interval = Number.isFinite(cfg.interval) ? cfg.interval : 1000;
                timer = setInterval(tick, interval);

                // cleanup
                window.addEventListener('beforeunload', () => {
                if (timer) clearInterval(timer);
                });
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', init, { once: true });
            } else {
                init();
            }
            })();
    JS;
    }
}


new WPSustainabilityMonitor();
