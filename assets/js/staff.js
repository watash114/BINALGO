/* ===== STAFF PORTAL SHARED ENHANCEMENTS ===== */
(function () {
    'use strict';

    var TEAL = '#0c6e5e';

    function onReady(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    function themeColors() {
        var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        return {
            isDark: isDark,
            grid: isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.05)',
            text: isDark ? '#94a3b8' : '#64748b',
            card: isDark ? '#1e293b' : '#ffffff',
            border: isDark ? '#334155' : '#e2e8f0'
        };
    }

    function chartDefaults() {
        var c = themeColors();
        if (window.Chart) {
            Chart.defaults.color = c.text;
            Chart.defaults.font.family = "'Segoe UI', sans-serif";
        }
        return c;
    }

    /* ---------- Theme-aware gradient bar/line chart helper ---------- */
    window.BINALGO_STAFF = {
        TEAL: TEAL,
        themeColors: themeColors,
        chartDefaults: chartDefaults,
        buildGradient: function (ctx, height, from, to) {
            var g = ctx.createLinearGradient(0, 0, 0, height || 260);
            g.addColorStop(0, from || 'rgba(12,110,94,0.8)');
            g.addColorStop(1, to || 'rgba(20,184,166,0.25)');
            return g;
        },
        renderBar: function (canvasId, labels, data, opts) {
            if (!window.Chart) return;
            chartDefaults();
            var canvas = document.getElementById(canvasId);
            if (!canvas) return;
            var ctx = canvas.getContext('2d');
            var c = themeColors();
            var grad = this.buildGradient(ctx, opts && opts.height);
            var cfg = {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: (opts && opts.label) || 'Count',
                        data: data,
                        backgroundColor: grad,
                        borderRadius: 8,
                        borderSkipped: false,
                        barThickness: (opts && opts.barThickness) || 'flex'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: c.card,
                            titleColor: c.text,
                            bodyColor: c.text,
                            borderColor: c.border,
                            borderWidth: 1,
                            cornerRadius: 10,
                            padding: 10,
                            displayColors: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: c.grid },
                            ticks: { precision: 0, font: { size: 11 } }
                        },
                        x: { grid: { display: false }, ticks: { font: { size: 11 } } }
                    }
                }
            };
            if (opts && opts.onClick) cfg.options.onClick = opts.onClick;
            return new Chart(ctx, cfg);
        },
        renderLine: function (canvasId, labels, data, opts) {
            if (!window.Chart) return;
            chartDefaults();
            var canvas = document.getElementById(canvasId);
            if (!canvas) return;
            var ctx = canvas.getContext('2d');
            var c = themeColors();
            var grad = this.buildGradient(ctx, opts && opts.height, 'rgba(12,110,94,0.25)', 'rgba(12,110,94,0.01)');
            var cfg = {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: (opts && opts.label) || 'Value',
                        data: data,
                        borderColor: TEAL,
                        backgroundColor: grad,
                        fill: true,
                        tension: 0.4,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        pointBackgroundColor: '#fff',
                        pointBorderColor: TEAL,
                        pointBorderWidth: 2,
                        borderWidth: 2.5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: c.card,
                            titleColor: c.text,
                            bodyColor: c.text,
                            borderColor: c.border,
                            borderWidth: 1,
                            cornerRadius: 10,
                            padding: 10,
                            displayColors: false
                        }
                    },
                    scales: {
                        y: { beginAtZero: true, grid: { color: c.grid }, ticks: { font: { size: 11 } } },
                        x: { grid: { display: false }, ticks: { font: { size: 11 } } }
                    }
                }
            };
            return new Chart(ctx, cfg);
        },
        destroyChart: function (canvasId) {
            var chart = Chart.getChart && Chart.getChart(canvasId);
            if (chart) chart.destroy();
        }
    };

    /* ---------- Off-canvas drawer ---------- */
    function openDrawer(id) {
        var el = document.getElementById(id);
        var backdrop = document.getElementById(id + '-backdrop');
        if (!el) return;
        el.classList.add('open');
        if (backdrop) backdrop.classList.add('show');
        document.body.style.overflow = 'hidden';
    }
    function closeDrawer(id) {
        var el = document.getElementById(id);
        var backdrop = document.getElementById(id + '-backdrop');
        if (!el) return;
        el.classList.remove('open');
        if (backdrop) backdrop.classList.remove('show');
        document.body.style.overflow = '';
    }

    onReady(function () {
        document.querySelectorAll('[data-drawer-target]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                openDrawer(btn.getAttribute('data-drawer-target'));
            });
        });
        document.querySelectorAll('[data-drawer-close]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                closeDrawer(btn.getAttribute('data-drawer-close'));
            });
        });
        document.querySelectorAll('.drawer-backdrop').forEach(function (bd) {
            bd.addEventListener('click', function () {
                var id = bd.id.replace('-backdrop', '');
                closeDrawer(id);
            });
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.drawer.open').forEach(function (d) {
                    closeDrawer(d.id);
                });
            }
        });
    });

    /* ---------- Chart range toggles (7d / 30d / YTD) ---------- */
    onReady(function () {
        document.querySelectorAll('.chart-toolbar .chart-range-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var toolbar = btn.closest('.chart-toolbar');
                if (!toolbar) return;
                toolbar.querySelectorAll('.chart-range-btn').forEach(function (b) {
                    b.classList.remove('active');
                });
                btn.classList.add('active');
                var handler = toolbar.getAttribute('data-chart-handler');
                var fn = window[handler];
                if (typeof fn === 'function') fn(btn.getAttribute('data-range'));
            });
        });
    });

    /* ---------- Confirmation-friendly action buttons ---------- */
    onReady(function () {
        document.querySelectorAll('[data-confirm]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                if (!window.confirm(btn.getAttribute('data-confirm'))) {
                    e.preventDefault();
                    e.stopPropagation();
                }
            });
        });
    });
})();
