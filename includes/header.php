<?php
/**
 * Reusable header — included at the top of every page.
 * Expects $pageTitle to be set before include (optional).
 */
$pageTitle = $pageTitle ?? 'Student Portal';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($pageTitle) ?> | Student Portal</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@700&display=swap" rel="stylesheet">

    <!-- Stylesheet -->
    <link rel="stylesheet" href="/student-auth/assets/css/style.css">
    <script src="/student-auth/assets/js/auth.js" defer></script>
</head>
<body>
    <div class="page-wrapper">
        <div class="brand">
            <div class="brand-logo">S</div>
            <div class="brand-name">Student Portal</div>
            <div class="brand-tagline">Access your academic dashboard</div>
        </div>