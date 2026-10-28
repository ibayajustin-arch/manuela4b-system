(function () {
    'use strict';
    if (typeof Chart === 'undefined') return;
    var money = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', maximumFractionDigits: 2 });
    var count = new Intl.NumberFormat('en-PH');

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
            grad.addColorStop(0, 'rgba(165, 180, 252, 0.28)');
            grad.addColorStop(1, 'rgba(165, 180, 252, 0.0)');

            if (revenueChart) revenueChart.destroy();
            revenueChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: series.labels,
                    datasets: [{
                        label: 'Money collected',
                        data: series.values,
                        borderColor: '#A5B4FC',
                        backgroundColor: grad,
                        borderWidth: 3,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        pointBackgroundColor: '#A5B4FC',
                        pointBorderColor: '#0B1220',
                        pointBorderWidth: 2,
                        fill: true,
                        tension: .25
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { intersect: false, mode: 'index' },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#070C18',
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
                        x: { grid: { display: false }, ticks: { color: '#94A3B8', maxRotation: 0 } },
                        y: { beginAtZero: true, grid: { color: 'rgba(255, 255, 255, 0.06)' }, ticks: { color: '#94A3B8', callback: function (v) { return '₱' + count.format(v); } } }
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