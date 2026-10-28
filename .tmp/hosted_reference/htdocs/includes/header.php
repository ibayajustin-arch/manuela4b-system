<?php 
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Manuela 4B MIS - Dashboard</title>
    <link rel="icon" type="image/png" href="../Assets/css/images/logo2.png">
    <meta name="theme-color" content="#0B1220">

    <!-- Apply the saved collapsed state before the page paints (prevents a flash) -->
    <script>
    (function () {
        try {
            var d = document.documentElement;
            if (localStorage.getItem('sb_collapsed') === '1') d.classList.add('sb-collapsed');
        } catch (e) {}
    })();
    </script>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="../Assets/css/global.css?v=20">
    <link rel="stylesheet" href="../Assets/css/components.css?v=21">
    <link rel="stylesheet" href="../Assets/css/admin.css?v=26">
    <link rel="stylesheet" href="../Assets/css/resident.css?v=22">
    <link rel="stylesheet" href="../Assets/css/reference-theme.css?v=24">
</head>
<body>
