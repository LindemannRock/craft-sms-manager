(function(window) {
    'use strict';

    window.lrSmsAnalyticsInit = function(initConfig) {
        const config = initConfig || {};

        if (window.lrSmsAnalyticsBound) {
            if (window.lrAnalyticsInit) {
                window.lrAnalyticsInit(config);
            }
            return;
        }
        window.lrSmsAnalyticsBound = true;

        if (window.lrAnalyticsInit) {
            window.lrAnalyticsInit(config);
        }

    // Chart colors
    const chartColors = window.lrChartColors || [
        '#0d78f2', '#10b981', '#ef4444', '#f59e0b', '#8b5cf6', '#06b6d4',
        '#ec4899', '#84cc16', '#f97316', '#6366f1'
    ];

    // Encoding labels
    const encodingLabels = config.encodingLabels || {};
    const strings = config.strings || {};

    // Tab lazy-loading guard flags
    var senderIdLoaded = false;
    var encodingLoaded = false;

    function destroyChart(canvasId, prefix) {
        const chartKey = canvasId.replace(/-/g, '_');
        if (window.lrChartInstances && window.lrChartInstances[prefix] && window.lrChartInstances[prefix][chartKey]) {
            window.lrChartInstances[prefix][chartKey].destroy();
            delete window.lrChartInstances[prefix][chartKey];
        }
    }

    function resetChartState(canvas) {
        if (!canvas) return;
        canvas.style.display = '';
        const parent = canvas.parentElement || canvas.parentNode;
        if (!parent) return;
        parent.querySelectorAll('.zilch').forEach(el => el.remove());
    }

    function renderEmptyState(canvasId, message, prefix) {
        const ctx = document.getElementById(canvasId);
        if (!ctx) return;
        resetChartState(ctx);
        destroyChart(canvasId, prefix);
        ctx.style.display = 'none';
        const parent = ctx.parentElement || ctx.parentNode;
        if (!parent) return;
        const emptyMsg = document.createElement('div');
        emptyMsg.className = 'zilch';
        emptyMsg.style.padding = '48px 24px';
        emptyMsg.style.textAlign = 'center';
        emptyMsg.innerHTML = '<p>' + message + '</p>';
        parent.appendChild(emptyMsg);
    }

    function getActiveTabId() {
        var hash = window.location.hash ? window.location.hash.substring(1) : '';
        if (hash && document.getElementById(hash)) return hash;
        var visible = document.querySelector('.lr-tab-content:not(.hidden)');
        return visible ? visible.id : 'overview';
    }

    function getChartFilters() {
        var activeConfig = window.lrAnalyticsConfig || config || {};
        return Object.assign({}, activeConfig.customFilters || {});
    }

    // Per-tab loaders
    function loadOverviewCharts() {
        var prefix = window.lrSmsChartPrefix || 'analytics';

        window.lrLoadChartData('daily', function(data) {
            if (data && data.labels && data.labels.length) {
                var hasData = Array.isArray(data.sent) && data.sent.some(function(v) { return Number(v) > 0; }) ||
                    Array.isArray(data.failed) && data.failed.some(function(v) { return Number(v) > 0; });
                if (hasData) {
                    renderDailyChart(data);
                } else {
                    renderEmptyState('daily-trend-chart', strings.noActivity, prefix);
                }
            } else {
                renderEmptyState('daily-trend-chart', strings.noActivity, prefix);
            }
        }, getChartFilters());

        window.lrLoadChartData('providers', function(data) {
            if (data && data.labels && data.labels.length > 0 && Array.isArray(data.values) && data.values.some(function(v) { return Number(v) > 0; })) {
                renderProviderChart(data);
            } else {
                renderEmptyState('provider-chart', strings.noProvider, prefix);
            }
        }, getChartFilters());

        window.lrLoadChartData('languages', function(data) {
            if (data && data.labels && data.labels.length > 0 && Array.isArray(data.values) && data.values.some(function(v) { return Number(v) > 0; })) {
                renderLanguageChart(data);
            } else {
                renderEmptyState('language-chart', strings.noLanguage, prefix);
            }
        }, getChartFilters());

        window.lrLoadChartData('sites', function(data) {
            if (data && data.labels && data.labels.length > 0 && Array.isArray(data.values) && data.values.some(function(v) { return Number(v) > 0; })) {
                renderSiteChart('site-chart', data);
            } else {
                renderEmptyState('site-chart', strings.noActivity, prefix);
            }
        }, getChartFilters());
    }

    function loadSenderIdData() {
        var prefix = window.lrSmsChartPrefix || 'analytics';

        window.lrLoadChartData('senderids', function(data) {
            if (data && data.labels && data.labels.length > 0) {
                var hasData = Array.isArray(data.sent) && data.sent.some(function(v) { return Number(v) > 0; }) ||
                    Array.isArray(data.failed) && data.failed.some(function(v) { return Number(v) > 0; });
                if (hasData) {
                    renderSenderIdCharts(data);
                } else {
                    renderEmptyState('senderid-bar-chart', strings.noSenderId, prefix);
                    renderEmptyState('senderid-pie-chart', strings.noSenderId, prefix);
                    renderEmptyState('senderid-success-chart', strings.noSenderId, prefix);
                }
            } else {
                renderEmptyState('senderid-bar-chart', strings.noSenderId, prefix);
                renderEmptyState('senderid-pie-chart', strings.noSenderId, prefix);
                renderEmptyState('senderid-success-chart', strings.noSenderId, prefix);
            }
        }, getChartFilters());

        window.lrLoadChartData('sender-id-table', function(data) {
            renderSenderIdTable(data);
        }, getChartFilters());

        window.lrLoadChartData('senderid-sites', function(data) {
            if (data && data.labels && data.labels.length > 0 && Array.isArray(data.values) && data.values.some(function(v) { return Number(v) > 0; })) {
                renderSiteChart('senderid-site-chart', data);
            } else {
                renderEmptyState('senderid-site-chart', strings.noActivity, prefix);
            }
        }, getChartFilters());
    }

    function loadEncodingData() {
        var prefix = window.lrSmsChartPrefix || 'analytics';

        window.lrLoadChartData('encoding', function(data) {
            if (data && data.labels && data.labels.length > 0 && Array.isArray(data.values) && data.values.some(function(v) { return Number(v) > 0; })) {
                renderEncodingPieChart(data);
            } else {
                renderEmptyState('encoding-pie-chart', strings.noEncoding, prefix);
            }
        }, getChartFilters());

        window.lrLoadChartData('encoding-daily', function(data) {
            if (data && data.labels && data.labels.length > 0) {
                var hasData = Array.isArray(data.gsm7) && data.gsm7.some(function(v) { return Number(v) > 0; }) ||
                    Array.isArray(data.ucs2) && data.ucs2.some(function(v) { return Number(v) > 0; }) ||
                    Array.isArray(data.mixed) && data.mixed.some(function(v) { return Number(v) > 0; });
                if (hasData) {
                    renderEncodingDailyChart(data);
                } else {
                    renderEmptyState('encoding-daily-chart', strings.noEncoding, prefix);
                }
            } else {
                renderEmptyState('encoding-daily-chart', strings.noEncoding, prefix);
            }
        }, getChartFilters());
    }

    function loadTabData(tabName) {
        if (tabName === 'senderids' && !senderIdLoaded) {
            senderIdLoaded = true;
            loadSenderIdData();
        }
        if (tabName === 'encoding' && !encodingLoaded) {
            encodingLoaded = true;
            loadEncodingData();
        }
    }

    // Initialize on analytics init event
    document.addEventListener('lr:analyticsInit', function(e) {
        var eventConfig = e.detail && e.detail.config ? e.detail.config : (window.lrAnalyticsConfig || {});
        window.lrSmsChartPrefix = eventConfig.prefix || 'analytics';

        // Reset guard flags
        senderIdLoaded = false;
        encodingLoaded = false;

        // Load overview charts (default tab)
        loadOverviewCharts();

        // Reload currently active tab if not overview
        var activeTab = getActiveTabId();
        loadTabData(activeTab);
    });

    // Lazy-load on tab change
    document.addEventListener('lr:tabChanged', function(e) {
        var tabId = e.detail && e.detail.tabId ? e.detail.tabId : getActiveTabId();
        loadTabData(tabId);
    });

    // Sender ID table renderer
    function renderSenderIdTable(data) {
        var tbody = document.getElementById('senderid-table-body');
        if (!tbody) return;
        if (!data || !data.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="light lr-text-center">' +
                (strings.noSenderId || 'No data available') + '</td></tr>';
            return;
        }
        var html = '';
        for (var i = 0; i < data.length; i++) {
            var item = data[i];
            var total = item.sent + item.failed;
            var rate = total > 0 ? Math.round((item.sent / total) * 1000) / 10 : 0;
            var rateClass = rate >= 90 ? 'lr-text-green' : (rate >= 70 ? 'lr-text-amber' : 'lr-text-red');
            html += '<tr>' +
                '<td><strong>' + Craft.escapeHtml(item.senderIdName) + '</strong></td>' +
                '<td>' + Craft.escapeHtml(item.siteName) + '</td>' +
                '<td class="lr-text-end">' + total.toLocaleString() + '</td>' +
                '<td class="lr-text-end lr-text-green">' + item.sent.toLocaleString() + '</td>' +
                '<td class="lr-text-end lr-text-red">' + item.failed.toLocaleString() + '</td>' +
                '<td class="lr-text-end"><span class="' + rateClass + '">' + rate + '%</span></td>' +
                '</tr>';
        }
        tbody.innerHTML = html;
    }

    // Chart rendering functions
    function renderDailyChart(data) {
        const ctx = document.getElementById('daily-trend-chart');
        if (!ctx) return;
        resetChartState(ctx);
        window.lrCreateChart('daily-trend-chart', 'line', {
            labels: data.labels,
            datasets: [
                {
                    label: strings.sentLabel,
                    data: data.sent,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    tension: 0.3,
                    fill: true
                },
                {
                    label: strings.failedLabel,
                    data: data.failed,
                    borderColor: '#ef4444',
                    backgroundColor: 'rgba(239, 68, 68, 0.1)',
                    tension: 0.3,
                    fill: true
                }
            ]
        }, {
            maintainAspectRatio: false,
            plugins: { legend: { position: 'top' } }
        });
    }

    function renderProviderChart(data) {
        const ctx = document.getElementById('provider-chart');
        if (!ctx) return;
        resetChartState(ctx);
        window.lrCreateChart('provider-chart', 'doughnut', {
            labels: data.labels,
            datasets: [{
                data: data.values,
                backgroundColor: chartColors.slice(0, data.labels.length)
            }]
        });
    }

    function renderSiteChart(canvasId, data) {
        const ctx = document.getElementById(canvasId);
        if (!ctx) return;
        resetChartState(ctx);
        window.lrCreateChart(canvasId, 'doughnut', {
            labels: data.labels,
            datasets: [{
                data: data.values,
                backgroundColor: chartColors.slice(0, data.labels.length)
            }]
        });
    }

    function renderLanguageChart(data) {
        const ctx = document.getElementById('language-chart');
        if (!ctx) return;
        resetChartState(ctx);
        window.lrCreateChart('language-chart', 'doughnut', {
            labels: data.labels,
            datasets: [{
                data: data.values,
                backgroundColor: chartColors.slice(0, data.labels.length)
            }]
        });
    }

    function renderEncodingPieChart(data) {
        const ctx = document.getElementById('encoding-pie-chart');
        if (!ctx) return;
        resetChartState(ctx);
        window.lrCreateChart('encoding-pie-chart', 'pie', {
            labels: data.labels,
            datasets: [{
                data: data.values,
                backgroundColor: ['#3b82f6', '#8b5cf6', '#6b7280']
            }]
        });
    }

    function renderEncodingDailyChart(data) {
        const ctx = document.getElementById('encoding-daily-chart');
        if (!ctx) return;
        resetChartState(ctx);
        window.lrCreateChart('encoding-daily-chart', 'bar', {
            labels: data.labels,
            datasets: [
                {
                    label: encodingLabels.gsm7,
                    data: data.gsm7,
                    backgroundColor: '#3b82f6'
                },
                {
                    label: encodingLabels.ucs2,
                    data: data.ucs2,
                    backgroundColor: '#8b5cf6'
                },
                {
                    label: encodingLabels.mixed,
                    data: data.mixed,
                    backgroundColor: '#6b7280'
                }
            ]
        }, {
            maintainAspectRatio: false,
            plugins: { legend: { position: 'top' } },
            scales: {
                x: { stacked: true },
                y: { stacked: true, beginAtZero: true }
            }
        });
    }

    function renderSenderIdCharts(data) {
        const barCtx = document.getElementById('senderid-bar-chart');
        const pieCtx = document.getElementById('senderid-pie-chart');
        const successCtx = document.getElementById('senderid-success-chart');
        if (!barCtx || !pieCtx || !successCtx) return;
        resetChartState(barCtx);
        resetChartState(pieCtx);
        resetChartState(successCtx);
        // Bar chart
        window.lrCreateChart('senderid-bar-chart', 'bar', {
            labels: data.labels,
            datasets: [
                {
                    label: strings.sentLabel,
                    data: data.sent,
                    backgroundColor: '#10b981'
                },
                {
                    label: strings.failedLabel,
                    data: data.failed,
                    backgroundColor: '#ef4444'
                }
            ]
        }, {
            maintainAspectRatio: false,
            plugins: { legend: { position: 'top' } }
        });

        // Pie chart
        const totals = data.sent.map((s, i) => s + data.failed[i]);
        window.lrCreateChart('senderid-pie-chart', 'doughnut', {
            labels: data.labels,
            datasets: [{
                data: totals,
                backgroundColor: chartColors.slice(0, data.labels.length)
            }]
        });

        // Success rate chart
        const rates = data.sent.map((s, i) => {
            const total = s + data.failed[i];
            return total > 0 ? Math.round((s / total) * 100) : 0;
        });
        window.lrCreateChart('senderid-success-chart', 'bar', {
            labels: data.labels,
            datasets: [{
                    label: strings.successRateLabel,
                data: rates,
                backgroundColor: rates.map(r => r >= 90 ? '#10b981' : (r >= 70 ? '#f59e0b' : '#ef4444'))
            }]
        }, {
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, max: 100 } }
        });
    }
    };
})(window);
