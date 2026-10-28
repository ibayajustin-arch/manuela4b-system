(function () {
    'use strict';

    var page = document.getElementById('analyticsPage');
    if (!page || typeof Chart === 'undefined') return;

    var form = document.getElementById('analyticsFilters');
    var preset = document.getElementById('datePreset');
    var block = document.getElementById('blockFilter');
    var charts = {};
    var results = {};
    var lastDetail = null;
    var updatedAt = '';
    var colors = ['#176B45', '#2368A0', '#B45F06', '#7551A6', '#087E8B', '#A53860', '#5B6770'];
    var moneyFormat = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', maximumFractionDigits: 2 });
    var numberFormat = new Intl.NumberFormat('en-PH');

    Chart.defaults.font.family = 'Inter, Arial, sans-serif';
    Chart.defaults.font.size = 16;
    Chart.defaults.color = '#263941';
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Chart.defaults.plugins.legend.labels.padding = 18;

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (char) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char];
        });
    }

    function cleanFilters() {
        var range = preset.value;
        var params = new URLSearchParams();
        params.set('range', range);
        if (range === 'custom') {
            params.set('from', document.getElementById('dateFrom').value);
            params.set('to', document.getElementById('dateTo').value);
        }
        if (block.value) params.set('block', block.value);
        return params;
    }

    function setLoading(metric, loading, failed, empty) {
        var card = document.querySelector('[data-chart-card="' + metric + '"]');
        if (!card) return;
        card.querySelector('.analytics-loading').hidden = !loading;
        card.querySelector('.analytics-error').hidden = !failed;
        card.querySelector('.analytics-empty').hidden = !(empty && !loading && !failed);
        var canvas = card.querySelector('canvas');
        canvas.hidden = loading || failed || empty;
    }

    function requestMetric(metric, extras) {
        var params = cleanFilters();
        params.set('metric', metric);
        Object.keys(extras || {}).forEach(function (key) {
            if (extras[key] !== '' && extras[key] != null) params.set(key, extras[key]);
        });
        return fetch(page.dataset.api + '?' + params.toString(), { credentials: 'same-origin', cache: 'no-store' }).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok || body.error) throw new Error((body.error && body.error.message) || 'Request failed');
                return body;
            });
        });
    }

    function chartOptions(metric, kind) {
        var options = {
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 350 },
            interaction: { intersect: false, mode: 'index' },
            plugins: {
                legend: { display: kind === 'doughnut', position: 'bottom' },
                tooltip: {
                    callbacks: {
                        label: function (context) {
                            var value = context.parsed;
                            if (typeof value === 'object' && value !== null) value = value.x != null ? value.x : value.y;
                            var name = context.dataset.label || context.label || '';
                            var formatted = metric === 'collections' ? moneyFormat.format(value || 0) : numberFormat.format(value || 0);
                            if (kind === 'doughnut') {
                                var values = context.dataset.data || [];
                                var total = values.reduce(function (sum, item) { return sum + Number(item || 0); }, 0);
                                var percent = total ? Math.round(Number(value || 0) / total * 100) : 0;
                                return name + ': ' + formatted + ' (' + percent + '%)';
                            }
                            return name ? name + ': ' + formatted : formatted;
                        }
                    }
                }
            },
            onClick: function (event, elements) {
                if (!elements.length) return;
                openDetails(metric, elements[0].index);
            }
        };
        if (kind === 'doughnut') {
            options.cutout = '58%';
        } else {
            options.scales = {
                x: { beginAtZero: true, grid: { color: '#edf1f2' }, ticks: { precision: 0 } },
                y: { grid: { display: false }, ticks: { autoSkip: true, maxRotation: 0, minRotation: 0 } }
            };
            if (kind === 'line') {
                options.interaction = { intersect: false, mode: 'index' };
                options.scales.y.ticks.callback = function (value) { return moneyFormat.format(value); };
            }
            if (kind === 'bar') options.indexAxis = 'y';
        }
        return options;
    }

    function labelColor(metric, label, index) {
        var key = String(label || '').toLowerCase();
        if (metric === 'dues') {
            if (key === 'paid') return '#176B45';
            if (key === 'partly paid') return '#B45F06';
            return '#B42318';
        }
        if (metric === 'account_status') {
            if (key === 'active') return '#176B45';
            if (key === 'pending') return '#B45F06';
            if (key === 'inactive') return '#68757C';
        }
        return colors[index % colors.length];
    }

    function draw(metric, canvasId, result, kind) {
        var canvas = document.getElementById(canvasId);
        var card = canvas.closest('[data-chart-card]');
        var noValues = !result.values.length || result.values.every(function (value) { return Number(value) === 0; });
        setLoading(metric, false, false, noValues);
        results[metric] = result;
        if (metric === 'facilities') populateCategories('facilityCategory', 'All facilities', result.labels);
        if (metric === 'payment_methods') populateCategories('paymentMethodCategory', 'All payment methods', result.labels);
        if (noValues) {
            if (charts[metric]) { charts[metric].destroy(); delete charts[metric]; }
            return;
        }
        if (charts[metric]) charts[metric].destroy();
        var background = result.labels.map(function (label, index) { return labelColor(metric, label, index); });
        var isLine = kind === 'line';
        charts[metric] = new Chart(canvas.getContext('2d'), {
            type: isLine ? 'line' : (kind === 'doughnut' ? 'doughnut' : 'bar'),
            data: {
                labels: result.labels,
                datasets: [{
                    label: metric === 'collections' ? 'Money received' : 'Number of records',
                    data: result.values,
                    backgroundColor: isLine ? 'rgba(23, 107, 69, .16)' : background,
                    borderColor: isLine ? '#176B45' : background,
                    borderWidth: isLine ? 3 : 0,
                    borderRadius: kind === 'bar' ? 6 : 0,
                    fill: isLine,
                    tension: .28,
                    pointRadius: isLine ? 4 : 0,
                    pointHoverRadius: isLine ? 6 : 0
                }]
            },
            options: chartOptions(metric, kind)
        });
        if (card) card.dataset.total = String(result.totals);
    }

    function populateCategories(id, allLabel, labels) {
        var select = document.getElementById(id);
        var selected = select.value;
        var options = ['<option value="">' + escapeHtml(allLabel) + '</option>'];
        labels.forEach(function (label) {
            options.push('<option value="' + escapeHtml(label) + '">' + escapeHtml(label) + '</option>');
        });
        select.innerHTML = options.join('');
        if (labels.indexOf(selected) !== -1) select.value = selected;
    }

    function formatChange(current, previous, prefix) {
        if (previous == null || Number(previous) === 0) return 'No earlier period to compare';
        var change = (Number(current) - Number(previous)) / Number(previous) * 100;
        if (!Number.isFinite(change)) return 'No earlier period to compare';
        var direction = change > 0 ? '↑' : (change < 0 ? '↓' : '→');
        var comparison = change === 0 ? 'No change' : (Math.abs(change).toFixed(0) + '% ' + (change > 0 ? 'higher' : 'lower'));
        return direction + ' ' + comparison + ' than the previous period';
    }

    function updateSummary(collections, facilities, methods) {
        document.getElementById('summaryCollections').textContent = moneyFormat.format(collections.totals || 0);
        var collectionChange = document.getElementById('summaryCollectionsChange');
        collectionChange.textContent = formatChange(collections.totals, collections.previous_period_value, '');
        collectionChange.classList.toggle('analytics-change-up', Number(collections.totals) >= Number(collections.previous_period_value || 0));
        collectionChange.classList.toggle('analytics-change-down', Number(collections.totals) < Number(collections.previous_period_value || 0));

        var topMethodIndex = (methods.values || []).reduce(function (best, value, index, values) {
            return best < 0 || Number(value) > Number(values[best]) ? index : best;
        }, -1);
        var topMethod = topMethodIndex >= 0 ? methods.labels[topMethodIndex] : 'No payments yet';
        document.getElementById('summaryTopMethod').textContent = topMethod;
        document.getElementById('summaryTopMethodNote').textContent = topMethodIndex >= 0
            ? numberFormat.format(methods.values[topMethodIndex]) + ' payments in these dates'
            : 'Try another date range';
        document.getElementById('summaryPaymentCount').textContent = numberFormat.format(methods.totals || 0);
        document.getElementById('summaryPaymentCountNote').textContent = 'Across the shown payment methods';

        document.getElementById('summaryFacilityBookings').textContent = numberFormat.format(facilities.totals || 0);
        var facilityChange = document.getElementById('summaryFacilityBookingsChange');
        facilityChange.textContent = formatChange(facilities.totals, facilities.previous_period_value, '');
        facilityChange.classList.toggle('analytics-change-up', Number(facilities.totals) >= Number(facilities.previous_period_value || 0));
        facilityChange.classList.toggle('analytics-change-down', Number(facilities.totals) < Number(facilities.previous_period_value || 0));
    }

    function updateLastUpdated(data) {
        if (data.last_updated) updatedAt = data.last_updated;
        var label = 'Updated just now';
        if (updatedAt) {
            var date = new Date(updatedAt);
            label = 'Updated ' + new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' }).format(date) + ' (Manila time)';
        }
        document.getElementById('analyticsUpdated').textContent = label;
    }

    function loadAll() {
        var globalError = document.getElementById('analyticsGlobalError');
        globalError.hidden = true;
        results = {};
        var duesStatus = document.getElementById('duesStatus').value;
        var facilityStatus = document.getElementById('facilityStatus').value;
        var jobs = [
            { metric: 'collections', canvas: 'chartCollections', kind: 'line', extras: {} },
            { metric: 'dues', canvas: 'chartDues', kind: 'doughnut', extras: { status: duesStatus } },
            { metric: 'facilities', canvas: 'chartFacilities', kind: 'bar', extras: { status: facilityStatus, category: document.getElementById('facilityCategory').value } },
            { metric: 'payment_methods', canvas: 'chartMethods', kind: 'doughnut', extras: { category: document.getElementById('paymentMethodCategory').value } },
            { metric: 'account_status', canvas: 'chartAccounts', kind: 'doughnut', extras: {} },
            { metric: 'homeowners_by_block', canvas: 'chartBlocks', kind: 'bar', extras: {} }
        ];
        jobs.forEach(function (job) { setLoading(job.metric, true, false, false); });
        var failed = 0;
        return Promise.all(jobs.map(function (job) {
            return requestMetric(job.metric, job.extras).then(function (data) {
                draw(job.metric, job.canvas, data, job.kind);
                updateLastUpdated(data);
            }).catch(function () {
                failed++;
                setLoading(job.metric, false, true, false);
            });
        })).then(function () {
            if (results.collections && results.facilities && results.payment_methods) {
                updateSummary(results.collections, results.facilities, results.payment_methods);
            }
            globalError.hidden = failed === 0 || failed < jobs.length;
            if (failed === jobs.length) globalError.hidden = false;
            return failed === 0;
        });
    }

    function currentQueryExtras(metric, label) {
        var extras = {};
        if (metric === 'facilities') {
            extras.status = document.getElementById('facilityStatus').value;
            extras.category = label;
        } else if (metric === 'dues') {
            extras.status = String(label).toLowerCase().replace('partly paid', 'partial');
        } else if (metric === 'payment_methods') {
            extras.category = label;
        } else if (metric === 'account_status') {
            extras.status = String(label).toLowerCase();
        } else if (metric === 'homeowners_by_block') {
            extras.category = label;
        } else if (metric === 'collections') {
            extras.bucket = label;
        }
        return extras;
    }

    function openDetails(metric, index) {
        var data = results[metric];
        if (!data || data.labels[index] == null) return;
        var label = data.labels[index];
        var params = cleanFilters();
        params.set('range', 'custom');
        params.set('from', data.range.from);
        params.set('to', data.range.to);
        params.set('metric', metric);
        var extras = currentQueryExtras(metric, label);
        Object.keys(extras).forEach(function (key) { if (extras[key]) params.set(key, extras[key]); });
        if (metric === 'dues') params.set('status', String(label).toLowerCase().replace('partly paid', 'partial'));
        lastDetail = page.dataset.recordsApi + '?' + params.toString();
        document.getElementById('analyticsDetailTitle').textContent = 'Details: ' + label;
        document.getElementById('analyticsDetailDescription').textContent = 'Matching records for ' + label + '. Up to 100 records are shown.';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('analyticsDetailModal')).show();
        loadDetails();
    }

    function loadDetails() {
        var loading = document.querySelector('.analytics-detail-loading');
        var error = document.querySelector('.analytics-detail-error');
        var empty = document.querySelector('.analytics-detail-empty');
        var head = document.getElementById('analyticsDetailHead');
        var body = document.getElementById('analyticsDetailBody');
        loading.hidden = false; error.hidden = true; empty.hidden = true;
        head.innerHTML = ''; body.innerHTML = '';
        fetch(lastDetail, { credentials: 'same-origin', cache: 'no-store' }).then(function (response) {
            return response.json().then(function (data) { if (!response.ok || data.error) throw new Error('Could not load records'); return data; });
        }).then(function (data) {
            loading.hidden = true;
            if (!data.rows.length) { empty.hidden = false; return; }
            head.innerHTML = '<tr>' + data.columns.map(function (column) { return '<th scope="col">' + escapeHtml(column) + '</th>'; }).join('') + '</tr>';
            body.innerHTML = data.rows.map(function (row) {
                return '<tr>' + row.map(function (value) {
                    var text = typeof value === 'number' ? (Number.isInteger(value) ? numberFormat.format(value) : moneyFormat.format(value)) : escapeHtml(value);
                    return '<td>' + text + '</td>';
                }).join('') + '</tr>';
            }).join('');
            document.getElementById('analyticsDetailLimit').hidden = data.rows.length < data.limit;
        }).catch(function () { loading.hidden = true; error.hidden = false; });
    }

    function csvCell(value) {
        var text = String(value == null ? '' : value);
        if (/^[\s]*[=+@\-]/.test(text)) text = "'" + text;
        return '"' + text.replace(/"/g, '""') + '"';
    }

    function exportCsv(metric) {
        var data = results[metric];
        if (!data) return;
        var title = document.querySelector('[data-chart-card="' + metric + '"] h2').textContent;
        var rows = [['Chart', title], ['Date range', data.range.from + ' to ' + data.range.to], ['Total', data.totals], [], ['Label', 'Value']];
        data.labels.forEach(function (label, index) { rows.push([label, data.values[index]]); });
        var csv = '\uFEFF' + rows.map(function (row) { return row.map(csvCell).join(','); }).join('\r\n');
        var link = document.createElement('a');
        link.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
        link.download = 'manuela-' + metric + '-' + data.range.from + '-to-' + data.range.to + '.csv';
        link.click();
        setTimeout(function () { URL.revokeObjectURL(link.href); }, 1000);
    }

    function printTable(metric) {
        var data = results[metric];
        if (!data) return;
        var title = document.querySelector('[data-chart-card="' + metric + '"] h2').textContent;
        var rows = data.labels.map(function (label, index) {
            return '<tr><td>' + escapeHtml(label) + '</td><td>' + escapeHtml(data.values[index]) + '</td></tr>';
        }).join('');
        var win = window.open('', '_blank');
        if (!win) { window.print(); return; }
        win.opener = null;
        win.document.write('<!doctype html><html><head><meta charset="utf-8"><title>' + escapeHtml(title) + '</title><style>body{font:16px Arial,sans-serif;color:#17232b;margin:32px}h1{font-size:24px}table{width:100%;border-collapse:collapse;margin-top:20px}th,td{padding:10px;border:1px solid #ccd5d9;text-align:left}th{background:#edf3f4}@media print{button{display:none}}</style></head><body><h1>' + escapeHtml(title) + '</h1><p>' + escapeHtml(data.range.from + ' to ' + data.range.to) + '</p><table><thead><tr><th>Label</th><th>Value</th></tr></thead><tbody>' + rows + '</tbody><tfoot><tr><th>Total</th><th>' + escapeHtml(data.totals) + '</th></tr></tfoot></table><button onclick="window.print()">Print / Save as PDF</button></body></html>');
        win.document.close();
        win.focus();
        win.onload = function () { win.print(); };
    }

    function updateCustomDateControls() {
        var custom = preset.value === 'custom';
        document.querySelectorAll('.analytics-custom-date').forEach(function (el) { el.hidden = !custom; });
    }

    preset.addEventListener('change', updateCustomDateControls);
    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (preset.value === 'custom' && (!document.getElementById('dateFrom').value || !document.getElementById('dateTo').value)) {
            document.getElementById('analyticsGlobalError').querySelector('span').textContent = 'Choose both a start date and an end date.';
            document.getElementById('analyticsGlobalError').hidden = false;
            return;
        }
        loadAll();
    });
    document.getElementById('duesStatus').addEventListener('change', loadAll);
    document.getElementById('facilityStatus').addEventListener('change', loadAll);
    document.getElementById('facilityCategory').addEventListener('change', loadAll);
    document.getElementById('paymentMethodCategory').addEventListener('change', loadAll);
    document.getElementById('analyticsRetry').addEventListener('click', loadAll);
    document.getElementById('analyticsDetailRetry').addEventListener('click', loadDetails);
    document.querySelectorAll('[data-retry]').forEach(function (button) { button.addEventListener('click', loadAll); });
    document.querySelectorAll('[data-export]').forEach(function (button) { button.addEventListener('click', function () { exportCsv(button.dataset.export); }); });
    document.querySelectorAll('[data-print]').forEach(function (button) { button.addEventListener('click', function () { printTable(button.dataset.print); }); });

    var guide = document.getElementById('readingGuide');
    var guideToggle = document.getElementById('guideToggle');
    var guideDismissed = false;
    try { guideDismissed = localStorage.getItem('analytics_guide_dismissed') === '1'; } catch (e) {}
    if (!guideDismissed) {
        guide.hidden = false;
        guideToggle.setAttribute('aria-expanded', 'true');
    }
    function dismissGuide() { guide.hidden = true; guideToggle.setAttribute('aria-expanded', 'false'); try { localStorage.setItem('analytics_guide_dismissed', '1'); } catch (e) {} }
    guideToggle.addEventListener('click', function () { guide.hidden = !guide.hidden; guideToggle.setAttribute('aria-expanded', String(!guide.hidden)); });
    document.getElementById('guideDismiss').addEventListener('click', dismissGuide);
    document.getElementById('reportModal').addEventListener('show.bs.modal', function () {
        var data = results.collections;
        if (data && data.range) {
            document.getElementById('reportFrom').value = data.range.from.slice(0, 7);
            document.getElementById('reportTo').value = data.range.to.slice(0, 7);
        }
    });
    loadAll();
}());
