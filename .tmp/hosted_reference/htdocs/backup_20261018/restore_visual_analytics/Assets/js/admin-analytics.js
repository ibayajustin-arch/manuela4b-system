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
    var tableState = null;
    var tableRequestId = 0;
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
        if (previous == null) return 'No earlier period to compare';
        if (Number(previous) === 0) return Number(current) > 0 ? '↑ New this period (previous period: 0)' : '→ No change from previous period';
        var change = (Number(current) - Number(previous)) / Number(previous) * 100;
        if (!Number.isFinite(change)) return 'No earlier period to compare';
        var direction = change > 0 ? '↑' : (change < 0 ? '↓' : '→');
        var comparison = change === 0 ? 'No change' : (Math.abs(change).toFixed(0) + '% ' + (change > 0 ? 'higher' : 'lower'));
        return direction + ' ' + comparison + ' than the previous period';
    }

    function setComparison(id, current, previous, lowerIsBetter) {
        var element = document.getElementById(id);
        var currentValue = Number(current || 0);
        var previousValue = Number(previous || 0);
        element.textContent = formatChange(currentValue, previousValue, '');
        element.classList.toggle('analytics-change-up', previousValue > 0 && (lowerIsBetter ? currentValue <= previousValue : currentValue >= previousValue));
        element.classList.toggle('analytics-change-down', previousValue > 0 && (lowerIsBetter ? currentValue > previousValue : currentValue < previousValue));
    }

    function updateSummary(collections, facilities, methods, dues) {
        document.getElementById('summaryCollections').textContent = moneyFormat.format(collections.totals || 0);
        setComparison('summaryCollectionsChange', collections.totals, collections.previous_period_value, false);
        document.getElementById('summaryPaymentCount').textContent = numberFormat.format(methods.totals || 0);
        setComparison('summaryPaymentCountChange', methods.totals, methods.previous_period_value, false);
        document.getElementById('summaryFacilityBookings').textContent = numberFormat.format(facilities.totals || 0);
        setComparison('summaryFacilityBookingsChange', facilities.totals, facilities.previous_period_value, false);
        var paid = Number((dues.all_status_counts || {}).Paid || 0);
        document.getElementById('summaryPaid').textContent = numberFormat.format(paid);
        setComparison('summaryPaidChange', paid, dues.previous_period_value, false);
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
        var facilityStatus = document.getElementById('facilityStatus').value;
        var jobs = [
            { metric: 'collections', canvas: 'chartCollections', kind: 'line', extras: {} },
            { metric: 'dues', canvas: 'chartDues', kind: 'doughnut', extras: {} },
            { metric: 'facilities', canvas: 'chartFacilities', kind: 'bar', extras: { status: facilityStatus, category: document.getElementById('facilityCategory').value } },
            { metric: 'payment_methods', canvas: 'chartMethods', kind: 'doughnut', extras: { category: document.getElementById('paymentMethodCategory').value } }
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
            if (results.collections && results.facilities && results.payment_methods && results.dues) {
                updateSummary(results.collections, results.facilities, results.payment_methods, results.dues);
                if (tableState && results[tableState.metric]) {
                    tableState.range = results[tableState.metric].range;
                    tableState.page = 1;
                    loadDetails();
                } else if (!tableState) {
                    showRecords('collections', {}, results.collections.range, 'Payment records matching the dates and street filters');
                }
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
        var extras = currentQueryExtras(metric, label);
        showRecords(metric, extras, data.range, 'Matching records: ' + label);
    }

    function showRecords(metric, extras, range, description) {
        tableState = { metric: metric, extras: extras || {}, range: range, page: 1, sort: 0, direction: 'desc', search: '' };
        document.getElementById('analyticsRecordsDescription').textContent = description;
        document.getElementById('recordsSearch').value = '';
        document.getElementById('recordsSearch').disabled = false;
        document.getElementById('analyticsRecords').scrollIntoView({ behavior: 'smooth', block: 'start' });
        loadDetails();
    }

    function loadDetails() {
        if (!tableState) return;
        var loading = document.getElementById('recordsLoading');
        var error = document.getElementById('recordsError');
        var head = document.getElementById('analyticsDetailHead');
        var body = document.getElementById('analyticsDetailBody');
        var params = new URLSearchParams({ metric: tableState.metric, range: 'custom', from: tableState.range.from, to: tableState.range.to, page: tableState.page, per_page: 25, sort: tableState.sort, direction: tableState.direction, search: tableState.search });
        if (block.value) params.set('block', block.value);
        Object.keys(tableState.extras).forEach(function (key) { if (tableState.extras[key] !== '' && tableState.extras[key] != null) params.set(key, tableState.extras[key]); });
        if (tableState.metric === 'facilities' && !tableState.extras.status) params.set('status', document.getElementById('facilityStatus').value);
        lastDetail = page.dataset.recordsApi + '?' + params.toString();
        var requestId = ++tableRequestId;
        loading.hidden = false; error.hidden = true;
        fetch(lastDetail, { credentials: 'same-origin', cache: 'no-store' }).then(function (response) {
            return response.json().then(function (data) { if (!response.ok || data.error) throw new Error('Could not load records'); return data; });
        }).then(function (data) {
            if (requestId !== tableRequestId) return;
            loading.hidden = true;
            var tableRows = data.rows || [];
            var tableColumns = data.columns || [];
            head.innerHTML = '<tr>' + tableColumns.map(function (column, index) { return '<th scope="col"><button class="analytics-sort-button" type="button" data-sort-column="' + index + '">' + escapeHtml(column) + (tableState.sort === index ? (tableState.direction === 'asc' ? ' ↑' : ' ↓') : '') + '</button></th>'; }).join('') + '</tr>';
            document.getElementById('recordsCount').textContent = data.total + ' matching records';
            document.getElementById('recordsPageLabel').textContent = 'Page ' + data.page + ' of ' + Math.max(1, data.pages);
            document.getElementById('recordsPrevious').disabled = data.page <= 1;
            document.getElementById('recordsNext').disabled = data.page >= data.pages;
            if (!tableRows.length) {
                body.innerHTML = '<tr><td class="analytics-table-placeholder" colspan="' + Math.max(1, tableColumns.length) + '">No matching records found for these filters.</td></tr>';
                return;
            }
            body.innerHTML = data.rows.map(function (row) {
                return '<tr>' + row.map(function (value) {
                    var text = typeof value === 'number' ? (Number.isInteger(value) ? numberFormat.format(value) : moneyFormat.format(value)) : escapeHtml(value);
                    return '<td>' + text + '</td>';
                }).join('') + '</tr>';
            }).join('');
            head.querySelectorAll('[data-sort-column]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var nextSort = Number(button.dataset.sortColumn);
                    if (tableState.sort === nextSort) tableState.direction = tableState.direction === 'asc' ? 'desc' : 'asc';
                    else { tableState.sort = nextSort; tableState.direction = 'asc'; }
                    tableState.page = 1;
                    loadDetails();
                });
            });
        }).catch(function () { if (requestId !== tableRequestId) return; loading.hidden = true; error.hidden = false; });
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
    document.getElementById('facilityStatus').addEventListener('change', loadAll);
    document.getElementById('facilityCategory').addEventListener('change', loadAll);
    document.getElementById('paymentMethodCategory').addEventListener('change', loadAll);
    document.getElementById('analyticsRetry').addEventListener('click', loadAll);
    document.getElementById('analyticsDetailRetry').addEventListener('click', loadDetails);
    document.querySelectorAll('[data-kpi-metric]').forEach(function (card) {
        card.addEventListener('click', function () {
            var metric = card.dataset.kpiMetric;
            if (!results[metric]) return;
            var extras = {};
            if (metric === 'facilities') extras.status = document.getElementById('facilityStatus').value;
            if (metric === 'dues') extras.status = 'paid';
            showRecords(metric, extras, results[metric].range, 'Matching records for ' + card.querySelector('h2').textContent.toLowerCase());
        });
    });
    document.getElementById('recordsPrevious').addEventListener('click', function () { if (tableState && tableState.page > 1) { tableState.page--; loadDetails(); } });
    document.getElementById('recordsNext').addEventListener('click', function () { if (tableState) { tableState.page++; loadDetails(); } });
    var searchTimer;
    document.getElementById('recordsSearch').addEventListener('input', function () {
        if (!tableState) return;
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () { tableState.search = document.getElementById('recordsSearch').value.trim(); tableState.page = 1; loadDetails(); }, 250);
    });
    document.querySelectorAll('[data-retry]').forEach(function (button) { button.addEventListener('click', loadAll); });

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
