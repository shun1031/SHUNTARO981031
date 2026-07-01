<?php
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$currentDir  = basename(dirname($_SERVER['PHP_SELF']));
$user = getCurrentUser();
$userRole = $user['role'] ?? '';
$isAdmin = in_array($userRole, ['super_admin', 'company_admin']);

function navLink(string $href, string $icon, string $label, bool $active, string $badge = ''): string {
    $cls = $active ? 'sidebar-link active' : 'sidebar-link';
    $b = $badge ? ' <span class="badge bg-danger ms-auto" style="font-size:.6rem">' . $badge . '</span>' : '';
    return '<a class="' . $cls . '" href="' . $href . '"><i class="bi ' . $icon . '"></i><span>' . $label . '</span>' . $b . '</a>';
}
function navSection(string $label): string {
    return '<div class="sidebar-section">' . $label . '</div>';
}
?>
<!-- モバイルトップバー -->
<div class="mobile-topbar d-lg-none">
    <button class="btn btn-sm" onclick="document.getElementById('sidebar').classList.toggle('show')" aria-label="メニュー">
        <i class="bi bi-list fs-4"></i>
    </button>
    <span class="fw-bold">
        <img src="<?= BASE_PATH ?>/public/assets/images/logos/portal_logo.png" alt="" style="height:24px;width:auto;margin-right:6px;object-fit:contain">
        社内ポータル
    </span>
    <a href="<?= BASE_PATH ?>/logout.php" class="btn btn-sm text-muted"><i class="bi bi-box-arrow-right"></i></a>
</div>

<!-- オーバーレイ -->
<div class="sidebar-overlay d-lg-none" onclick="document.getElementById('sidebar').classList.remove('show')"></div>

<!-- サイドバー -->
<aside id="sidebar" class="sidebar">
    <!-- ロゴ -->
    <div class="sidebar-brand">
        <img src="<?= BASE_PATH ?>/public/assets/images/logos/portal_logo.png" alt="社内ポータル" class="sidebar-portal-logo"
             onerror="this.style.display='none';document.getElementById('sidebarDefaultIcon').style.display='flex'">
        <div class="sidebar-logo-icon" id="sidebarDefaultIcon" style="display:none"><i class="bi bi-people-fill"></i></div>
        <div class="sidebar-brand-text">
            <span class="fw-bold">社内ポータル</span>
            <?php if (!empty($_SESSION['company_name'])): ?>
            <small class="d-block text-muted" style="font-size:.65rem;line-height:1.2"><?= h($_SESSION['company_name']) ?></small>
            <?php endif; ?>
        </div>
    </div>

    <!-- ナビゲーション -->
    <nav class="sidebar-nav">

        <?php if (!$isAdmin): ?>
        <?php
            $pendingMyReq = function_exists('countPendingChangeRequests') && ($cid = getCompanyId())
                ? countPendingChangeRequests($cid, getEmployeeNameFilter()) : 0;
        ?>
        <?= navSection('一般メニュー') ?>
        <?= navLink(BASE_PATH . '/employee/dashboard.php', 'bi-grid-1x2', 'ダッシュボード', $currentDir === 'employee' && $currentPage === 'dashboard') ?>
        <?= navLink(BASE_PATH . '/employee/shift.php', 'bi-calendar3', 'シフト提出', $currentDir === 'employee' && $currentPage === 'shift') ?>
        <?= navLink(BASE_PATH . '/public/sales_daily_report.php', 'bi-journal-check', '日報提出', $currentPage === 'sales_daily_report') ?>
        <?= navLink(BASE_PATH . '/employee/attendance.php', 'bi-clock-history', '出退勤報告', $currentDir === 'employee' && $currentPage === 'attendance') ?>
        <?= navLink(BASE_PATH . '/public/sales_transport.php', 'bi-car-front', '交通費申請', $currentPage === 'sales_transport') ?>
        <?= navLink(BASE_PATH . '/employee/requests.php', 'bi-pencil-square', '申請', $currentDir === 'employee' && $currentPage === 'requests', $pendingMyReq ? (string)$pendingMyReq : '') ?>

        <?php else: ?>

        <?= navSection('売上管理') ?>
        <?= navLink(BASE_PATH . '/public/sales_dashboard.php', 'bi-graph-up-arrow', '総合ダッシュボード', $currentPage === 'sales_dashboard') ?>
        <?= navLink(BASE_PATH . '/public/sales_dashboard_regular.php', 'bi-person-workspace', '常勤ダッシュボード', $currentPage === 'sales_dashboard_regular') ?>
        <?= navLink(BASE_PATH . '/public/sales_dashboard_event.php', 'bi-calendar-event', 'イベントダッシュボード', $currentPage === 'sales_dashboard_event') ?>
        <?= navLink(BASE_PATH . '/public/sales_events.php', 'bi-calendar-event', 'イベント案件', $currentPage === 'sales_events') ?>
        <?= navLink(BASE_PATH . '/public/sales_regular.php', 'bi-person-workspace', '常勤案件', $currentPage === 'sales_regular') ?>
        <?= navLink(BASE_PATH . '/public/sales_event_calendar.php', 'bi-calendar-range', 'イベントカレンダー', $currentPage === 'sales_event_calendar') ?>
        <?= navLink(BASE_PATH . '/public/alliance_staff.php', 'bi-people', 'アライアンス人員管理', $currentPage === 'alliance_staff') ?>
        <?= navLink(BASE_PATH . '/public/sales_shifts.php', 'bi-calendar3', 'シフト管理', $currentPage === 'sales_shifts') ?>
        <?= navLink(BASE_PATH . '/public/sales_daily_report.php', 'bi-journal-check', '日報管理', $currentPage === 'sales_daily_report') ?>
        <?= navLink(BASE_PATH . '/public/sales_transport.php', 'bi-car-front', '交通費', $currentPage === 'sales_transport') ?>
        <?= navLink(BASE_PATH . '/public/sales_client_report.php', 'bi-building', '取引先別売上', $currentPage === 'sales_client_report') ?>
        <?= navLink(BASE_PATH . '/public/sales_rep_report.php', 'bi-person-badge', '担当者別売上', $currentPage === 'sales_rep_report') ?>
        <?= navLink(BASE_PATH . '/public/sales_carrier_report.php', 'bi-reception-4', 'キャリア別売上', $currentPage === 'sales_carrier_report') ?>
        <?= navLink(BASE_PATH . '/admin/change_requests.php', 'bi-inbox', '申請承認', $currentDir === 'admin' && $currentPage === 'change_requests', ($n = countPendingChangeRequests(getCompanyId() ?? 0)) ? (string)$n : '') ?>
        <?= navLink(BASE_PATH . '/public/salary.php', 'bi-cash-stack', '給与管理', $currentPage === 'salary') ?>
        <?= navLink(BASE_PATH . '/public/employees.php', 'bi-person-lines-fill', '社員一覧', $currentPage === 'employees' && $currentDir === 'public') ?>


        <?php endif; ?>

    </nav>

    <!-- ユーザー -->
    <div class="sidebar-user">
        <div class="d-flex align-items-center gap-2">
            <div class="sidebar-user-avatar">
                <?= mb_substr($user['display_name'] ?? 'U', 0, 1) ?>
            </div>
            <div class="flex-grow-1 overflow-hidden">
                <div class="fw-medium text-truncate" style="font-size:.8rem"><?= h($user['display_name'] ?? '') ?></div>
                <div class="text-muted" style="font-size:.65rem">
                    <?= $userRole === 'super_admin' ? 'システム管理者' : ($userRole === 'company_admin' ? '管理者' : '社員') ?>
                </div>
            </div>
            <a href="<?= BASE_PATH ?>/logout.php" class="btn btn-sm text-muted p-0" title="ログアウト">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
    </div>
</aside>
