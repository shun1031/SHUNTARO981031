/**
 * 売上管理モジュール JavaScript
 */
(function() {
    'use strict';

    const BASE = window.BMS_BASE_PATH || '';

    // ============================================================
    // 金額フォーマット
    // ============================================================
    window.salesFormatNumber = function(n) {
        if (n == null || isNaN(n)) return '0';
        return Number(n).toLocaleString('ja-JP');
    };

    window.salesFormatYen = function(n) {
        return '¥' + salesFormatNumber(n);
    };

    window.salesFormatPercent = function(n, decimals = 1) {
        return Number(n).toFixed(decimals) + '%';
    };

    // ============================================================
    // 案件フォーム自動計算
    // ============================================================
    window.salesCalcAmounts = function() {
        const priceIn  = parseFloat(document.getElementById('unit_price_in')?.value || 0);
        const priceOut = parseFloat(document.getElementById('unit_price_out')?.value || 0);
        const days     = parseFloat(document.getElementById('days_worked')?.value || 0);

        const revenue = Math.round(priceIn * days);
        const cost    = Math.round(priceOut * days);
        const profit  = revenue - cost;
        const margin  = revenue > 0 ? (profit / revenue * 100).toFixed(1) : '0.0';

        const revEl = document.getElementById('calc_revenue');
        const costEl = document.getElementById('calc_cost');
        const profitEl = document.getElementById('calc_profit');
        const marginEl = document.getElementById('calc_margin');

        if (revEl) revEl.textContent = salesFormatYen(revenue);
        if (costEl) costEl.textContent = salesFormatYen(cost);
        if (profitEl) {
            profitEl.textContent = salesFormatYen(profit);
            profitEl.className = profit >= 0 ? 'amount-positive' : 'amount-negative';
        }
        if (marginEl) {
            marginEl.textContent = margin + '%';
            marginEl.className = parseFloat(margin) >= 20 ? 'amount-positive' : (parseFloat(margin) >= 0 ? '' : 'amount-negative');
        }

        // hidden fields
        const hRev = document.getElementById('revenue'); if (hRev) hRev.value = revenue;
        const hCost = document.getElementById('cost'); if (hCost) hCost.value = cost;
        const hProfit = document.getElementById('gross_profit'); if (hProfit) hProfit.value = profit;
        const hMargin = document.getElementById('margin'); if (hMargin) hMargin.value = (revenue > 0 ? profit / revenue : 0).toFixed(4);
    };

    // イベントバインド
    document.addEventListener('DOMContentLoaded', function() {
        ['unit_price_in', 'unit_price_out', 'days_worked'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.addEventListener('input', salesCalcAmounts);
        });

        // worker_type変更時にalliance選択の表示切替
        const wtSelect = document.getElementById('worker_type');
        const alGroup = document.getElementById('alliance_group');
        if (wtSelect && alGroup) {
            wtSelect.addEventListener('change', function() {
                const needsAlliance = ['アライアンス', '個人外注'].includes(this.value);
                alGroup.style.display = needsAlliance ? 'block' : 'none';
            });
            wtSelect.dispatchEvent(new Event('change'));
        }

        // 初期計算
        if (document.getElementById('unit_price_in')) {
            salesCalcAmounts();
        }
    });

    // ============================================================
    // API呼び出し
    // ============================================================
    window.salesApi = {
        async saveCase(data) {
            const res = await fetch(BASE + '/public/api/sales_case.php', {
                method: data.id ? 'PUT' : 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            return res.json();
        },

        async deleteCase(id) {
            const res = await fetch(BASE + '/public/api/sales_case.php?id=' + id, {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' }
            });
            return res.json();
        },

        async getSummary(params) {
            const qs = new URLSearchParams(params).toString();
            const res = await fetch(BASE + '/public/api/sales_summary.php?' + qs);
            return res.json();
        }
    };

    // ============================================================
    // ダッシュボードチャート描画
    // ============================================================
    window.salesDrawTrendChart = function(canvasId, data, targets, customLabels) {
        const ctx = document.getElementById(canvasId);
        if (!ctx) return;

        const months = customLabels || Array.from({length: 12}, (_, i) => (i + 1) + '月');
        const revenues = months.map((_, i) => data[i + 1]?.revenue || 0);
        const profits  = months.map((_, i) => data[i + 1]?.profit || 0);
        const tgts     = months.map((_, i) => targets[i + 1] || 0);

        return new Chart(ctx, {
            type: 'line',
            data: {
                labels: months,
                datasets: [
                    {
                        label: '目標',
                        data: tgts,
                        borderColor: '#9ca3af',
                        borderDash: [5, 5],
                        borderWidth: 2,
                        pointRadius: 0,
                        fill: false,
                    },
                    {
                        label: '売上',
                        data: revenues,
                        borderColor: '#059669',
                        backgroundColor: 'rgba(5,150,105,.08)',
                        borderWidth: 2.5,
                        pointRadius: 3,
                        pointBackgroundColor: '#059669',
                        fill: false,
                        tension: .3,
                    },
                    {
                        label: '粗利',
                        data: profits,
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59,130,246,.06)',
                        borderWidth: 2,
                        pointRadius: 2,
                        fill: true,
                        tension: .3,
                    },
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top', labels: { font: { size: 11 }, usePointStyle: true } },
                    tooltip: {
                        callbacks: {
                            label: ctx => ctx.dataset.label + ': ' + salesFormatYen(ctx.raw)
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: v => (v / 1000000).toFixed(0) + 'M',
                            font: { size: 10 }
                        },
                        grid: { color: '#f3f4f6' }
                    },
                    x: { grid: { display: false }, ticks: { font: { size: 10 } } }
                }
            }
        });
    };

    window.salesDrawDonutChart = function(canvasId, labels, values, colors) {
        const ctx = document.getElementById(canvasId);
        if (!ctx) return;

        return new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: values,
                    backgroundColor: colors || ['#3b82f6','#06b6d4','#059669','#f59e0b','#9ca3af'],
                    borderWidth: 2,
                    borderColor: '#fff',
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '65%',
                plugins: {
                    legend: { position: 'bottom', labels: { font: { size: 10 }, padding: 10, usePointStyle: true } },
                    tooltip: {
                        callbacks: {
                            label: ctx => ctx.label + ': ' + salesFormatYen(ctx.raw)
                        }
                    }
                }
            }
        });
    };

    // ============================================================
    // フィルタ適用
    // ============================================================
    window.salesApplyFilters = function() {
        const form = document.getElementById('salesFilterForm');
        if (form) form.submit();
    };

})();
