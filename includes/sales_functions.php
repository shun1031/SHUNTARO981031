<?php
// ============================================================
// 売上管理 ヘルパー関数 (ローダー)
// ============================================================
// 2026-04-15 リファクタ: 機能別に includes/sales/ 配下へ分割
// 呼び出し側は従来通り `require_once 'sales_functions.php'` で
// すべての売上関連関数が利用可能（後方互換保持）
// ============================================================

$__salesDir = __DIR__ . '/sales';
require_once $__salesDir . '/masters.php';        // マスタ (クライアント等)
require_once $__salesDir . '/cases.php';          // 案件 CRUD
require_once $__salesDir . '/reports.php';        // 集計・ダッシュボード・担当者
require_once $__salesDir . '/transport.php';      // 交通費管理
require_once $__salesDir . '/invoices.php';       // 請求書
require_once $__salesDir . '/shifts.php';         // シフト管理
require_once $__salesDir . '/daily_reports.php';  // 日報管理
require_once $__salesDir . '/attendance.php';     // 出勤管理
require_once $__salesDir . '/calendar.php';       // イベントカレンダー・月末総会
require_once $__salesDir . '/change_requests.php'; // 申請（シフト変更・出退勤時間変更）
require_once $__salesDir . '/sga.php';             // 販管費管理
unset($__salesDir);
