(function () {
    'use strict';
    if (typeof Chart === 'undefined') return;
    var money = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', maximumFractionDigits: 2 });
    var count = new Intl.NumberFormat('en-PH');

    function fetchMetric(metric) {
        var query = new URLSearchParams({ metric: metric, range: 'month' });
        return fetch('api/analytics.php?' + query.toString(), { credentials: 'same-origin', cache: 'no-store' }).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok || body.error) throw new Error('Analytics unavailable');
                return body;
            });
        });
    }

    function changeText(current, previous) {
        if (previous == null || Number(previous) === 0) return 'No data for last month';
        var change = (Number(current) - Number(previous)) / Number(previous) * 100;
        var arrow = change > 0 ? '↑' : (change < 0 ? '↓' : '→');
        return arrow + ' ' + Math.abs(change).toFixed(0) + '% compared with the same dates last month';
    }

    Promise.all([fetchMetric('collections'), fetchMetric('dues'), fetchMetric('account_status')]).then(function (data) {
        var collections = data[0];
        var dues = data[1];
        var accounts = data[2];
        var revenue = document.getElementById('dashboardRevenue');
        var revenueChange = document.getElementById('dashboardRevenueChange');
        var homeownerCount = document.getElementById('dashboardHomeowners');
        if (revenue) revenue.textContent = money.format(collections.totals || 0);
        if (revenueChange) {
            revenueChange.textContent = changeText(collections.totals, collections.previous_period_value);
            revenueChange.classList.toggle('text-success', Number(collections.totals) >= Number(collections.previous_period_value || 0));
            revenueChange.classList.toggle('text-danger', Number(collections.totals) < Number(collections.previous_period_value || 0));
        }
        if (homeownerCount) homeownerCount.textContent = count.format(accounts.totals || 0);

        var statuses = dues.all_status_counts || {};
        var paid = Number(statuses.Paid || 0);
        var partlyPaid = Number(statuses['Partly paid'] || 0);
        var unpaid = Number(statuses.Unpaid || 0);
        var eligible = Number(dues.eligible_homeowners || 0);
        var percent = eligible ? Math.round(paid / eligible * 100) : 0;
        var percentNode = document.getElementById('dashboardCollectionPercent');
        var paidNode = document.getElementById('dashboardPaidAccounts');
        var unpaidNode = document.getElementById('dashboardUnpaidAccounts');
        var amountNode = document.getElementById('dashboardCollectionAmounts');
        var outstandingNode = document.getElementById('dashboardOutstandingDues');
        if (percentNode) percentNode.textContent = eligible ? percent + '%' : '—';
        if (paidNode) paidNode.textContent = count.format(paid) + ' account' + (paid === 1 ? '' : 's');
        if (unpaidNode) unpaidNode.textContent = count.format(partlyPaid + unpaid) + ' account' + (partlyPaid + unpaid === 1 ? '' : 's');
        if (amountNode) amountNode.textContent = money.format(dues.collected_amount || 0) + ' collected of ' + money.format(dues.expected_amount || 0) + ' expected';
        if (outstandingNode) outstandingNode.textContent = money.format(Math.max(0, Number(dues.expected_amount || 0) - Number(dues.collected_amount || 0)));
        if (window.dashboardCollectionChart) {
            window.dashboardCollectionChart.data.datasets[0].data = [paid, partlyPaid + unpaid];
            window.dashboardCollectionChart.update();
        }
    }).catch(function () {
        // Keep the server-rendered figures visible if the live refresh fails.
    });
}());
