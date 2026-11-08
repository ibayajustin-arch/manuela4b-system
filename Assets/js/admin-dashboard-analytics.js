(function () {
    'use strict';
    if (typeof Chart === 'undefined') return;
    var money = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', maximumFractionDigits: 2 });
    var count = new Intl.NumberFormat('en-PH');
    Chart.defaults.font.family = 'Inter, system-ui, sans-serif';

    var revenueChart = null;

    function loadRevenueTrend() {
        var canvas = document.getElementById('dashboardRevenueChart');
        var state = document.getElementById('dashboardRevenueChartState');
        if (!canvas || !state) return;
        state.hidden = false;
        state.textContent = 'Loading this year’s revenue…';

        fetch('api/analytics_finance.php?range=year', {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json' }
        }).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok || body.error) throw new Error(body.error || 'Analytics unavailable');
                return body;
            });
        }).then(function (data) {
            var series = (data.sales && data.sales.months) || { labels: [], values: [] };
            var hasValues = (series.values || []).some(function (v) { return Number(v) > 0; });
            if (!hasValues) {
                canvas.hidden = true;
                state.hidden = false;
                state.textContent = 'No revenue has been recorded this year yet.';
                return;
            }
            canvas.hidden = false;
            state.hidden = true;
            var ctx = canvas.getContext('2d');
            var grad = ctx.createLinearGradient(0, 0, 0, 300);
            grad.addColorStop(0, 'rgba(15, 107, 90, 0.22)');
            grad.addColorStop(1, 'rgba(15, 107, 90, 0.0)');

            if (revenueChart) revenueChart.destroy();
            revenueChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: series.labels,
                    datasets: [{
                        label: 'Money collected',
                        data: series.values,
                        borderColor: '#0F6B5A',
                        backgroundColor: grad,
                        borderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        pointBackgroundColor: '#0F6B5A',
                        pointBorderColor: '#FFFFFF',
                        pointBorderWidth: 2,
                        fill: true,
                        // Straight segments: a curve would draw values that were never collected.
                        tension: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { intersect: false, mode: 'index' },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#0F4C3F',
                            titleColor: '#FFFFFF',
                            bodyColor: '#E2E8F0',
                            borderColor: 'rgba(255, 255, 255, 0.12)',
                            borderWidth: 1,
                            padding: 10,
                            cornerRadius: 8,
                            callbacks: { label: function (c) { return 'Money collected: ' + money.format(c.parsed.y || 0); } }
                        }
                    },
                    scales: {
                        x: { border: { display: false }, grid: { display: false }, ticks: { color: '#4A5C57', maxRotation: 0, minRotation: 0, autoSkipPadding: 14, callback: function (v) { return String(this.getLabelForValue(v)).replace(/ \d{4}$/, ''); } } },
                        y: { beginAtZero: true, border: { display: false }, grid: { color: 'rgba(213, 224, 221, 0.7)', drawTicks: false }, ticks: { color: '#4A5C57', padding: 6, precision: 0, maxTicksLimit: 6, callback: function (v) { return this.max >= 10000 ? '₱' + (+(v / 1000).toFixed(1)) + 'K' : '₱' + count.format(v); } } }
                    }
                }
            });
        }).catch(function () {
            canvas.hidden = true;
            state.hidden = false;
            state.innerHTML = 'We couldn’t load the revenue chart. <button type="button" class="btn btn-link p-0" id="dashboardRevenueRetry">Try again</button>';
            document.getElementById('dashboardRevenueRetry').addEventListener('click', loadRevenueTrend);
        });
    }

    // The KPI cards and Collection Progress ring are already rendered by Dashboard.php,
    // so nothing here overwrites them anymore.
    loadRevenueTrend();
}());