<?php
require_once '../config/init.php';
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

require_once '../includes/analytics_data.php';

// ---------- Inputs ----------
$type = $_GET['type'] ?? 'collection';
if (!in_array($type, ['collection', 'aging', 'followup', 'reliability'], true)) $type = 'collection';
$format = ($_GET['format'] ?? 'pdf') === 'csv' ? 'csv' : 'pdf';

$isYm = fn($v) => is_string($v) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $v);
$from = $isYm($_GET['from'] ?? '') ? $_GET['from'] : date('Y-m', strtotime('-5 months'));
$to   = $isYm($_GET['to'] ?? '') ? $_GET['to'] : $current_period;
if ($from > $to) { [$from, $to] = [$to, $from]; }

// Change this if your session stores the name under a different key
$generatedBy = $_SESSION['full_name'] ?? $_SESSION['name'] ?? $_SESSION['username'] ?? 'Admin';
$generatedAt = date('F j, Y g:i A');

function aging_label($streak) {
    if ($streak <= 0) return 'Current';
    if ($streak === 1) return 'Due This Month';
    if ($streak >= 4) return '4+ Months';
    return $streak . ' Month' . ($streak === 1 ? '' : 's');
}

// ---------- Build the report ----------
$title = '';
$subtitle = '';
$headers = [];
$rows = [];
$moneyCols = [];   // column indexes to format as pesos in HTML
$summary = [];     // [label => value] shown above the table
$totalRow = null;

switch ($type) {
    case 'collection':
        $title = 'Collection Summary Report';
        $subtitle = date('F Y', strtotime($from . '-01')) . ' to ' . date('F Y', strtotime($to . '-01'));
        $headers = ['Month', 'Total Collected'];
        $moneyCols = [1];
        $sum = 0;
        foreach (generate_period_range($from, $to) as $ym) {
            $amt = (float) ($monthlyTotals[$ym] ?? 0);
            $sum += $amt;
            $rows[] = [date('F Y', strtotime($ym . '-01')), $amt];
        }
        $totalRow = ['TOTAL', $sum];
        $summary = [
            'This Month' => '₱' . number_format($thisMonthTotal, 2),
            'vs Last Month' => $momChange === null
                ? 'No baseline'
                : (($momChange >= 0 ? '+' : '-') . abs(round($momChange)) . '%'),
            'Next Month Estimate' => '₱' . number_format($forecastNext, 2),
        ];
        break;

    case 'aging':
        $title = 'Delinquency Aging Report';
        $subtitle = 'As of ' . date('F j, Y');
        $headers = ['Homeowner', 'Property Address', 'Months Unpaid', 'Aging', 'Amount at Risk'];
        $moneyCols = [4];
        $flat = array_merge($agingBuckets['0-30'], $agingBuckets['31-60'], $agingBuckets['61-90'], $agingBuckets['90+']);
        usort($flat, fn($a, $b) => $b['streak'] <=> $a['streak']);
        $sum = 0;
        foreach ($flat as $e) {
            $sum += $e['amount_at_risk'];
            $rows[] = [$e['name'], homeowner_address_label($e['block'] ?? '', $e['lot'] ?? ''), $e['streak'], aging_label($e['streak']), $e['amount_at_risk']];
        }
        $totalRow = ['TOTAL', '', '', '', $sum];
        $summary = [
            '1 Month' => count($agingBuckets['0-30']),
            '2 Months' => count($agingBuckets['31-60']),
            '3 Months' => count($agingBuckets['61-90']),
            '4+ Months' => count($agingBuckets['90+']),
        ];
        break;

    case 'followup':
        $title = 'At-Risk Homeowners Follow-up List';
        $subtitle = 'As of ' . date('F j, Y') . ' — for the treasurer / collector';
        $headers = ['Homeowner', 'Property Address', 'Contact', 'Months Unpaid', 'Monthly Due', 'Amount at Risk'];
        $moneyCols = [4, 5];
        $list = array_filter($reliabilityList, fn($e) => $e['streak'] > 0);
        usort($list, fn($a, $b) => $b['streak'] <=> $a['streak'] ?: $b['amount_at_risk'] <=> $a['amount_at_risk']);
        $sum = 0;
        foreach ($list as $e) {
            $sum += $e['amount_at_risk'];
            $rows[] = [$e['name'], homeowner_address_label($e['block'] ?? '', $e['lot'] ?? ''), $e['contact'], $e['streak'], $e['monthly_due'], $e['amount_at_risk']];
        }
        $totalRow = ['TOTAL', '', '', '', '', $sum];
        $summary = ['Accounts to follow up' => count($rows)];
        break;

    case 'reliability':
        $title = 'Payment Reliability Report';
        $subtitle = '% of months paid since joining — lowest first';
        $headers = ['Homeowner', 'Property Address', 'Months Unpaid (current streak)', 'Reliability Score'];
        foreach ($reliabilityList as $e) {
            $rows[] = [$e['name'], homeowner_address_label($e['block'] ?? '', $e['lot'] ?? ''), $e['streak'], $e['score'] . '%'];
        }
        $summary = ['Homeowners' => count($rows)];
        break;
}

// ---------- CSV output ----------
if ($format === 'csv') {
    $filename = 'manuela4b_' . $type . '_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads it correctly
    fputcsv($out, ['Manuela 4B Management System']);
    fputcsv($out, [$title]);
    fputcsv($out, [$subtitle]);
    fputcsv($out, ['Generated: ' . $generatedAt . ' by ' . $generatedBy]);
    fputcsv($out, []);
    fputcsv($out, $headers);
    foreach ($rows as $r) fputcsv($out, $r);
    if ($totalRow) fputcsv($out, $totalRow);
    fclose($out);
    exit;
}

// ---------- Printable HTML (Save as PDF) ----------
function fmt_cell($v, $isMoney) {
    if ($isMoney && is_numeric($v)) return '₱' . number_format((float) $v, 2);
    return htmlspecialchars((string) $v);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($title) ?> - Manuela 4B</title>
<link rel="icon" type="image/png" href="../Assets/css/images/logo2.png">
<style>
    * { box-sizing: border-box; }
    body { font-family: Arial, Helvetica, sans-serif; color: #1c2b24; margin: 0; padding: 32px; font-size: 13px; }
    .toolbar { margin-bottom: 20px; }
    .toolbar button { padding: 8px 16px; border: 0; border-radius: 6px; background: #0F172A; color: #fff; cursor: pointer; font-size: 13px; font-weight: 600; }
    .header { border-bottom: 3px solid #0F172A; padding-bottom: 12px; margin-bottom: 18px; }
    .header .org { font-size: 12px; letter-spacing: .05em; text-transform: uppercase; color: #475569; }
    .header h1 { margin: 4px 0 2px; font-size: 22px; color: #09090B; }
    .header .sub { color: #475569; }
    .summary { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 18px; }
    .summary .box { border: 1px solid #CBD5E1; border-radius: 8px; padding: 10px 16px; min-width: 130px; background: #fff; }
    .summary .box .lbl { font-size: 11px; text-transform: uppercase; color: #475569; }
    .summary .box .val { font-size: 18px; font-weight: bold; margin-top: 2px; color: #09090B; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #0F172A; color: #fff; text-align: left; padding: 8px 10px; font-size: 12px; }
    td { padding: 7px 10px; border-bottom: 1px solid #E2E8F0; color: #1E293B; }
    tr:nth-child(even) td { background: #F8FAFC; }
    .right { text-align: right; }
    tr.total td { font-weight: bold; border-top: 2px solid #0F172A; background: #fff; }
    .empty { text-align: center; color: #5b6b62; padding: 24px; }
    .footer { margin-top: 28px; font-size: 11px; color: #5b6b62; display: flex; justify-content: space-between; }
    .signatures { display: flex; gap: 40px; margin-top: 48px; }
    .signatures div { flex: 1; border-top: 1px solid #1c2b24; padding-top: 6px; text-align: center; font-size: 12px; }
    @media print {
        .toolbar { display: none; }
        body { padding: 0; }
        th, tr:nth-child(even) td, .header { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        tr { page-break-inside: avoid; }
    }
</style>
</head>
<body>
    <div class="toolbar">
        <button onclick="window.print()">Print / Save as PDF</button>
    </div>

    <div class="header">
        <div class="org">Manuela 4B Management System</div>
        <h1><?= htmlspecialchars($title) ?></h1>
        <div class="sub"><?= htmlspecialchars($subtitle) ?></div>
    </div>

    <?php if ($summary): ?>
    <div class="summary">
        <?php foreach ($summary as $label => $value): ?>
        <div class="box">
            <div class="lbl"><?= htmlspecialchars($label) ?></div>
            <div class="val"><?= htmlspecialchars((string) $value) ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <?php foreach ($headers as $i => $h): ?>
                <th class="<?= in_array($i, $moneyCols, true) ? 'right' : '' ?>"><?= htmlspecialchars($h) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
                <?php foreach ($r as $i => $v): $m = in_array($i, $moneyCols, true); ?>
                <td class="<?= $m ? 'right' : '' ?>"><?= fmt_cell($v, $m) ?></td>
                <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?>
            <tr><td colspan="<?= count($headers) ?>" class="empty">No records found for this report.</td></tr>
            <?php endif; ?>
            <?php if ($totalRow && $rows): ?>
            <tr class="total">
                <?php foreach ($totalRow as $i => $v): $m = in_array($i, $moneyCols, true); ?>
                <td class="<?= $m ? 'right' : '' ?>"><?= fmt_cell($v, $m) ?></td>
                <?php endforeach; ?>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="signatures">
        <div>Prepared by</div>
        <div>Noted by (Treasurer)</div>
        <div>Approved by (President)</div>
    </div>

    <div class="footer">
        <span>Generated on <?= htmlspecialchars($generatedAt) ?> by <?= htmlspecialchars($generatedBy) ?></span>
        <span>Manuela 4B MIS</span>
    </div>

    <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });</script>
</body>
</html>
