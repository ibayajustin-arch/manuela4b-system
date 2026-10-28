<?php
/**
 * Builds the Visual Data Analytics overview payload.
 * Expects $conn, analytics_query(), and $filters from Admin/api/analytics.php.
 */
function analytics_build_overview(mysqli $conn, array $filters, string $lastUpdated): array
{
    require_once __DIR__ . '/archive_helpers.php';
    require_once __DIR__ . '/payment_methods.php';
    require_once __DIR__ . '/homeowner_helpers.php';

    ensure_archive_columns($conn, 'homeowners');
    ensure_archive_columns($conn, 'payments');
    ensure_archive_columns($conn, 'reservations');

    $analyticsFrom = $filters['from']->format('Y-m');
    $analyticsTo = $filters['to']->format('Y-m');
    $selectedBlock = $filters['block'];
    $blockPaymentHomeownerIds = [];

    require __DIR__ . '/analytics_data.php';

    $periodHomeowners = array_values(array_filter($homeowners, static function ($h) use ($current_period) {
        return homeowner_dues_start_ym($h) <= $current_period;
    }));
    $expectedThisMonth = 0.0;
    $paidThisMonth = 0.0;
    foreach ($periodHomeowners as $h) {
        $due = (float) $h['monthly_due'];
        $expectedThisMonth += $due;
        $paidThisMonth += min($due, (float) ($paymentsByHomeowner[(int) $h['id']][$current_period] ?? 0));
    }
    $collectionRate = $expectedThisMonth > 0 ? round(($paidThisMonth / $expectedThisMonth) * 100) : null;
    $fullyPaid = 0;
    foreach ($periodHomeowners as $h) {
        if ((float) ($paymentsByHomeowner[(int) $h['id']][$current_period] ?? 0) >= (float) $h['monthly_due']) {
            $fullyPaid++;
        }
    }

    $agingMeta = [
        ['key' => '0-30', 'label' => '1 month unpaid'],
        ['key' => '31-60', 'label' => '2 months unpaid'],
        ['key' => '61-90', 'label' => '3 months unpaid'],
        ['key' => '90+', 'label' => '4+ months unpaid'],
    ];
    $agingLabels = [];
    $agingCounts = [];
    $agingAmounts = [];
    $overdueRows = [];
    foreach ($agingMeta as $bucket) {
        $rows = $agingBuckets[$bucket['key']] ?? [];
        $agingLabels[] = $bucket['label'];
        $agingCounts[] = count($rows);
        $agingAmounts[] = round(array_sum(array_column($rows, 'amount_at_risk')), 2);
        foreach ($rows as $row) {
            $overdueRows[] = [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'street' => $row['block'],
                'lot' => $row['lot'],
                'address' => homeowner_address_label($row['block'] ?? '', $row['lot'] ?? ''),
                'streak' => (int) $row['streak'],
                'amount' => round((float) $row['amount_at_risk'], 2),
                'bucket' => $bucket['label'],
            ];
        }
    }
    usort($overdueRows, static fn($a, $b) => $b['streak'] <=> $a['streak'] ?: $b['amount'] <=> $a['amount']);
    $outstanding = round(array_sum($agingAmounts), 2);

    $streetLabels = [];
    $streetRates = [];
    $streetPaid = [];
    $streetTotal = [];
    $currentStreetStats = $blockStatsByPeriod[$current_period] ?? [];
    ksort($currentStreetStats);
    foreach ($currentStreetStats as $street => $stats) {
        $total = (int) $stats['total'];
        $paid = (int) $stats['paid'];
        $streetLabels[] = $street;
        $streetTotal[] = $total;
        $streetPaid[] = $paid;
        $streetRates[] = $total > 0 ? (int) round(($paid / $total) * 100) : 0;
    }

    $heatmapStreets = $streetLabels;
    $heatmapPeriods = $trendPeriods;
    $heatmapValues = [];
    foreach ($heatmapStreets as $street) {
        $row = [];
        foreach ($heatmapPeriods as $period) {
            $stats = $blockStatsByPeriod[$period][$street] ?? ['total' => 0, 'paid' => 0];
            $row[] = !empty($stats['total']) ? (int) round(($stats['paid'] / $stats['total']) * 100) : null;
        }
        $heatmapValues[] = $row;
    }

    $billingLabels = [];
    $billingExpected = [];
    $billingCollected = [];
    foreach ($trendPeriods as $period) {
        $billingLabels[] = date('M Y', strtotime($period . '-01'));
        $billingExpected[] = round((float) ($expectedByPeriod[$period] ?? 0), 2);
        $billingCollected[] = round((float) ($billedCollectedByPeriod[$period] ?? 0), 2);
    }

    $cashLabels = $trendLabels;
    $cashValues = $trendData;
    $useDailyCash = $filters['days'] <= 62;
    if ($useDailyCash) {
        $join = $filters['block'] !== '' ? ' INNER JOIN homeowners h ON h.id = p.homeowner_id AND h.archived_at IS NULL' : '';
        $blockWhere = $filters['block'] !== '' ? ' AND LOWER(TRIM(h.block)) = LOWER(TRIM(?))' : '';
        $sql = "SELECT DATE_FORMAT(p.payment_date, '%Y-%m-%d') AS bucket, COALESCE(SUM(p.amount_paid), 0) AS total
                FROM payments p{$join}
                WHERE p.archived_at IS NULL AND p.payment_date >= ? AND p.payment_date < ?{$blockWhere}
                GROUP BY bucket ORDER BY bucket";
        $params = [$filters['from']->format('Y-m-d'), $filters['end_exclusive']->format('Y-m-d')];
        $types = 'ss';
        if ($filters['block'] !== '') {
            $params[] = $filters['block'];
            $types .= 's';
        }
        $daily = [];
        foreach (analytics_query($conn, $sql, $types, $params) as $row) {
            $daily[(string) $row['bucket']] = (float) $row['total'];
        }
        $cashLabels = [];
        $cashValues = [];
        for ($point = $filters['from']; $point <= $filters['to']; $point = $point->modify('+1 day')) {
            $key = $point->format('Y-m-d');
            $cashLabels[] = $key;
            $cashValues[] = round((float) ($daily[$key] ?? 0), 2);
        }
    }

    $cashTotal = round(array_sum($cashValues), 2);
    $previousCash = 0.0;
    $join = $filters['block'] !== '' ? ' INNER JOIN homeowners h ON h.id = p.homeowner_id AND h.archived_at IS NULL' : '';
    $blockWhere = $filters['block'] !== '' ? ' AND LOWER(TRIM(h.block)) = LOWER(TRIM(?))' : '';
    $prevSql = "SELECT COALESCE(SUM(p.amount_paid), 0) AS total FROM payments p{$join}
                WHERE p.archived_at IS NULL AND p.payment_date >= ? AND p.payment_date < ?{$blockWhere}";
    $prevParams = [$filters['previous_from']->format('Y-m-d'), $filters['previous_end_exclusive']->format('Y-m-d')];
    $prevTypes = 'ss';
    if ($filters['block'] !== '') {
        $prevParams[] = $filters['block'];
        $prevTypes .= 's';
    }
    $previousCash = round((float) (analytics_query($conn, $prevSql, $prevTypes, $prevParams)[0]['total'] ?? 0), 2);

    $methodExpr = payment_method_sql_expression('p');
    $methodSql = "SELECT {$methodExpr} AS label, COUNT(*) AS total_count, COALESCE(SUM(p.amount_paid), 0) AS total_amount
                  FROM payments p
                  WHERE p.archived_at IS NULL AND p.payment_date >= ? AND p.payment_date < ?";
    $methodParams = [$filters['from']->format('Y-m-d'), $filters['end_exclusive']->format('Y-m-d')];
    $methodTypes = 'ss';
    if ($filters['block'] !== '') {
        $methodSql .= ' AND EXISTS (SELECT 1 FROM homeowners h WHERE h.id = p.homeowner_id AND h.archived_at IS NULL AND LOWER(TRIM(h.block)) = LOWER(TRIM(?)))';
        $methodParams[] = $filters['block'];
        $methodTypes .= 's';
    }
    $methodSql .= ' GROUP BY label ORDER BY total_amount DESC, label';
    $methodLabels = [];
    $methodCounts = [];
    $methodAmounts = [];
    $methodsPrevious = 0;
    foreach (analytics_query($conn, $methodSql, $methodTypes, $methodParams) as $row) {
        $methodLabels[] = (string) $row['label'];
        $methodCounts[] = (int) $row['total_count'];
        $methodAmounts[] = round((float) $row['total_amount'], 2);
    }
    $prevMethodSql = "SELECT COUNT(*) AS total FROM payments p
                      WHERE p.archived_at IS NULL AND p.payment_date >= ? AND p.payment_date < ?";
    $prevMethodParams = [$filters['previous_from']->format('Y-m-d'), $filters['previous_end_exclusive']->format('Y-m-d')];
    $prevMethodTypes = 'ss';
    if ($filters['block'] !== '') {
        $prevMethodSql .= ' AND EXISTS (SELECT 1 FROM homeowners h WHERE h.id = p.homeowner_id AND h.archived_at IS NULL AND LOWER(TRIM(h.block)) = LOWER(TRIM(?)))';
        $prevMethodParams[] = $filters['block'];
        $prevMethodTypes .= 's';
    }
    $methodsPrevious = (int) (analytics_query($conn, $prevMethodSql, $prevMethodTypes, $prevMethodParams)[0]['total'] ?? 0);

    $facilityStatus = $filters['status'] !== '' ? $filters['status'] : 'approved';
    if (!in_array($facilityStatus, ['approved', 'pending', 'rejected'], true)) {
        $facilityStatus = 'approved';
    }
    $facilitySql = "SELECT r.facility AS label, COUNT(*) AS total
                    FROM reservations r
                    WHERE r.archived_at IS NULL AND r.res_date >= ? AND r.res_date < ? AND r.status = ?";
    $facilityParams = [$filters['from']->format('Y-m-d'), $filters['end_exclusive']->format('Y-m-d'), $facilityStatus];
    $facilityTypes = 'sss';
    if ($filters['block'] !== '') {
        $facilitySql .= ' AND EXISTS (SELECT 1 FROM homeowners h WHERE h.user_id = r.user_id AND h.archived_at IS NULL AND LOWER(TRIM(h.block)) = LOWER(TRIM(?)))';
        $facilityParams[] = $filters['block'];
        $facilityTypes .= 's';
    }
    $facilitySql .= ' GROUP BY r.facility ORDER BY total DESC, r.facility';
    $facilityLabels = [];
    $facilityValues = [];
    foreach (analytics_query($conn, $facilitySql, $facilityTypes, $facilityParams) as $row) {
        $facilityLabels[] = (string) $row['label'];
        $facilityValues[] = (int) $row['total'];
    }
    $prevFacilitySql = "SELECT COUNT(*) AS total FROM reservations r
                        WHERE r.archived_at IS NULL AND r.res_date >= ? AND r.res_date < ? AND r.status = ?";
    $prevFacilityParams = [$filters['previous_from']->format('Y-m-d'), $filters['previous_end_exclusive']->format('Y-m-d'), $facilityStatus];
    $prevFacilityTypes = 'sss';
    if ($filters['block'] !== '') {
        $prevFacilitySql .= ' AND EXISTS (SELECT 1 FROM homeowners h WHERE h.user_id = r.user_id AND h.archived_at IS NULL AND LOWER(TRIM(h.block)) = LOWER(TRIM(?)))';
        $prevFacilityParams[] = $filters['block'];
        $prevFacilityTypes .= 's';
    }
    $bookingsPrevious = (int) (analytics_query($conn, $prevFacilitySql, $prevFacilityTypes, $prevFacilityParams)[0]['total'] ?? 0);

    $facilityTrendSql = "SELECT DATE_FORMAT(r.res_date, '%Y-%m') AS period, r.facility, COUNT(*) AS total
                         FROM reservations r
                         WHERE r.archived_at IS NULL AND r.res_date >= ? AND r.res_date < ? AND r.status = ?";
    $facilityTrendParams = [$filters['from']->format('Y-m-d'), $filters['end_exclusive']->format('Y-m-d'), $facilityStatus];
    $facilityTrendTypes = 'sss';
    if ($filters['block'] !== '') {
        $facilityTrendSql .= ' AND EXISTS (SELECT 1 FROM homeowners h WHERE h.user_id = r.user_id AND h.archived_at IS NULL AND LOWER(TRIM(h.block)) = LOWER(TRIM(?)))';
        $facilityTrendParams[] = $filters['block'];
        $facilityTrendTypes .= 's';
    }
    $facilityTrendSql .= ' GROUP BY period, r.facility ORDER BY period, r.facility';
    $facilityTrendMap = [];
    foreach (analytics_query($conn, $facilityTrendSql, $facilityTrendTypes, $facilityTrendParams) as $row) {
        $facilityTrendMap[(string) $row['facility']][(string) $row['period']] = (int) $row['total'];
    }
    $topFacilities = array_slice($facilityLabels, 0, 5);
    $facilityTrendDatasets = [];
    foreach ($topFacilities as $facilityName) {
        $series = [];
        foreach ($trendPeriods as $period) {
            $series[] = (int) ($facilityTrendMap[$facilityName][$period] ?? 0);
        }
        $facilityTrendDatasets[] = ['label' => $facilityName, 'values' => $series];
    }

    $statusSql = "SELECT LOWER(status) AS label, COUNT(*) AS total FROM homeowners WHERE archived_at IS NULL";
    $statusParams = [];
    $statusTypes = '';
    if ($filters['block'] !== '') {
        $statusSql .= ' AND LOWER(TRIM(block)) = LOWER(TRIM(?))';
        $statusParams[] = $filters['block'];
        $statusTypes .= 's';
    }
    $statusSql .= ' GROUP BY LOWER(status) ORDER BY label';
    $statusLabels = [];
    $statusValues = [];
    foreach (analytics_query($conn, $statusSql, $statusTypes, $statusParams) as $row) {
        $statusLabels[] = ucfirst((string) $row['label']);
        $statusValues[] = (int) $row['total'];
    }

    $lowestStreet = null;
    if ($streetRates) {
        $minRate = min($streetRates);
        $index = array_search($minRate, $streetRates, true);
        if ($index !== false) {
            $lowestStreet = ['street' => $streetLabels[$index], 'rate' => $minRate];
        }
    }
    $topMethod = $methodLabels[0] ?? null;
    $insights = [];
    $asOf = date('F Y', strtotime($current_period . '-01'));
    if ($collectionRate !== null) {
        if ($collectionRate < 70) {
            $insights[] = ['tone' => 'alert', 'text' => "Collection rate is {$collectionRate}% for {$asOf}. Less than 70% of billed dues have been received."];
        } elseif ($collectionRate < 90) {
            $insights[] = ['tone' => 'warn', 'text' => "Collection rate is {$collectionRate}% for {$asOf}. Follow up on remaining unpaid households."];
        } else {
            $insights[] = ['tone' => 'ok', 'text' => "Collection rate is {$collectionRate}% for {$asOf}."];
        }
    }
    if ($momChange !== null && $momChange <= -10) {
        $insights[] = ['tone' => 'alert', 'text' => 'Cash received in ' . $asOf . ' is ' . abs((int) round($momChange)) . '% lower than the previous month.'];
    } elseif ($momChange !== null && $momChange >= 10) {
        $insights[] = ['tone' => 'ok', 'text' => 'Cash received in ' . $asOf . ' is ' . (int) round($momChange) . '% higher than the previous month.'];
    }
    $late90 = count($agingBuckets['90+'] ?? []);
    if ($late90 > 0) {
        $insights[] = ['tone' => 'alert', 'text' => $late90 . ' household' . ($late90 === 1 ? '' : 's') . ' unpaid for 4 or more months.'];
    }
    if ($lowestStreet && $lowestStreet['rate'] < 80 && count($streetLabels) > 1) {
        $insights[] = ['tone' => 'warn', 'text' => $lowestStreet['street'] . ' Street has the lowest paid rate this month at ' . $lowestStreet['rate'] . '%.'];
    }
    if ($topMethod && $methodAmounts) {
        $share = $cashTotal > 0 ? (int) round(($methodAmounts[0] / max($cashTotal, 0.01)) * 100) : 0;
        $insights[] = ['tone' => 'info', 'text' => $topMethod . ' accounts for about ' . $share . '% of money received in this date range.'];
    }
    if ($facilityLabels) {
        $insights[] = ['tone' => 'info', 'text' => $facilityLabels[0] . ' is the most booked facility for the selected dates.'];
    }
    if (!$insights) {
        $insights[] = ['tone' => 'info', 'text' => 'Apply a date range and street filter, then select a chart to inspect the records behind each figure.'];
    }

    $atRiskPayload = [];
    foreach ($atRiskList as $entry) {
        $atRiskPayload[] = [
            'id' => (int) $entry['id'],
            'name' => $entry['name'],
            'street' => $entry['block'],
            'lot' => $entry['lot'],
            'streak' => (int) $entry['streak'],
            'amount' => round((float) $entry['amount_at_risk'], 2),
        ];
    }

    $pctChange = static function ($current, $previous) {
        if ($previous === null) return null;
        $previous = (float) $previous;
        $current = (float) $current;
        if ($previous == 0.0) return $current > 0 ? 100.0 : 0.0;
        return round((($current - $previous) / $previous) * 100, 1);
    };

    return [
        'metric' => 'overview',
        'range' => [
            'type' => $filters['range'],
            'from' => $filters['from']->format('Y-m-d'),
            'to' => $filters['to']->format('Y-m-d'),
        ],
        'as_of_period' => $current_period,
        'as_of_label' => $asOf,
        'last_updated' => $lastUpdated,
        'kpis' => [
            'collections' => ['value' => $cashTotal, 'previous' => $previousCash, 'change_pct' => $pctChange($cashTotal, $previousCash)],
            'collection_rate' => ['value' => $collectionRate, 'collected' => round($paidThisMonth, 2), 'expected' => round($expectedThisMonth, 2)],
            'fully_paid' => ['value' => $fullyPaid, 'total' => count($periodHomeowners)],
            'outstanding' => ['value' => $outstanding, 'accounts' => count($overdueRows)],
            'bookings' => ['value' => array_sum($facilityValues), 'previous' => $bookingsPrevious, 'change_pct' => $pctChange(array_sum($facilityValues), $bookingsPrevious)],
            'payments' => ['value' => array_sum($methodCounts), 'previous' => $methodsPrevious, 'change_pct' => $pctChange(array_sum($methodCounts), $methodsPrevious)],
        ],
        'cashflow' => [
            'labels' => array_values($cashLabels),
            'values' => array_values($cashValues),
            'granularity' => $useDailyCash ? 'day' : 'month',
            'forecast_next' => round((float) $forecastNext, 2),
            'this_month_total' => round((float) $thisMonthTotal, 2),
            'mom_change' => $momChange === null ? null : round((float) $momChange, 1),
        ],
        'billing' => [
            'labels' => $billingLabels,
            'periods' => $trendPeriods,
            'expected' => $billingExpected,
            'collected' => $billingCollected,
        ],
        'aging' => [
            'labels' => $agingLabels,
            'counts' => $agingCounts,
            'amounts' => $agingAmounts,
        ],
        'streets' => [
            'labels' => $streetLabels,
            'rates' => $streetRates,
            'paid' => $streetPaid,
            'total' => $streetTotal,
        ],
        'heatmap' => [
            'streets' => $heatmapStreets,
            'periods' => $heatmapPeriods,
            'period_labels' => array_map(static fn($period) => date('M Y', strtotime($period . '-01')), $heatmapPeriods),
            'values' => $heatmapValues,
        ],
        'methods' => [
            'labels' => $methodLabels,
            'counts' => $methodCounts,
            'amounts' => $methodAmounts,
        ],
        'facilities' => [
            'labels' => $facilityLabels,
            'values' => $facilityValues,
            'status' => $facilityStatus,
            'trend' => [
                'labels' => array_map(static fn($period) => date('M Y', strtotime($period . '-01')), $trendPeriods),
                'periods' => $trendPeriods,
                'datasets' => $facilityTrendDatasets,
            ],
        ],
        'accounts' => [
            'labels' => $statusLabels,
            'values' => $statusValues,
        ],
        'reliability' => [
            'labels' => array_keys($scoreBuckets),
            'values' => array_values($scoreBuckets),
        ],
        'overdue' => $overdueRows,
        'follow_up' => $atRiskPayload,
        'insights' => $insights,
        'labels' => $cashLabels,
        'values' => $cashValues,
        'totals' => $cashTotal,
        'previous_period_value' => $previousCash,
    ];
}
