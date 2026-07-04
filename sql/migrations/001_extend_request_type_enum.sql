-- Migration: sales_change_requests.request_type ENUM を6種に拡張
-- 実行対象: Railway MySQL
-- 実行方法: Railway の MySQL パネルから貼り付けるか、mysql CLI で実行

ALTER TABLE sales_change_requests
    MODIFY COLUMN request_type ENUM(
        'shift_change',
        'attendance_change',
        'checkin_change',
        'checkout_change',
        'attendance_add',
        'daily_report_edit',
        'transport_edit'
    ) NOT NULL;
