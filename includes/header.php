<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle ?? '') ?> | <?= h(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= BASE_PATH ?>/public/assets/css/style.css" rel="stylesheet">
    <?php if (!empty($extraCss)): ?>
        <?php foreach ($extraCss as $css): ?>
            <?php
            if (str_starts_with($css, 'http') || str_starts_with($css, '/')) {
                $cssUrl = $css;
            } elseif (str_contains($css, '/')) {
                $cssUrl = BASE_PATH . '/public/' . $css;
            } else {
                $cssUrl = BASE_PATH . '/public/assets/css/' . $css;
            }
            ?>
            <link href="<?= h($cssUrl) ?>?v=20260409" rel="stylesheet">
        <?php endforeach; ?>
    <?php endif; ?>
    <script>window.BMS_BASE_PATH = "<?= BASE_PATH ?>";</script>
</head>
<body>
<div class="app-layout">
    <?php require_once __DIR__ . '/nav.php'; ?>
    <main class="app-main">
        <div class="main-wrapper">
