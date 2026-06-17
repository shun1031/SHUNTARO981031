/**
 * bMS 評価システム JavaScript
 */
document.addEventListener('DOMContentLoaded', function() {

    // ========================================
    // 達成率 自動計算
    // ========================================
    document.querySelectorAll('.eval-actual, .eval-target').forEach(function(input) {
        input.addEventListener('input', function() {
            var row = this.closest('tr') || this.closest('.eval-row');
            if (!row) return;
            var target = parseFloat(row.querySelector('.eval-target')?.value) || 0;
            var actual = parseFloat(row.querySelector('.eval-actual')?.value) || 0;
            var rateEl = row.querySelector('.eval-rate');
            if (rateEl && target > 0) {
                var rate = Math.min((actual / target * 100), 200).toFixed(1);
                rateEl.textContent = rate + '%';
                rateEl.className = 'eval-rate badge ' +
                    (rate >= 100 ? 'bg-success' : rate >= 80 ? 'bg-warning text-dark' : 'bg-danger');
            }
        });
    });

    // ========================================
    // コンピテンシー レベル説明トグル
    // ========================================
    document.querySelectorAll('.competency-levels input[type=radio]').forEach(function(radio) {
        radio.addEventListener('change', function() {
            var card = this.closest('.competency-card');
            if (!card) return;
            card.querySelectorAll('.level-desc').forEach(function(d) { d.style.display = 'none'; });
            var desc = card.querySelector('.level-desc-' + this.value);
            if (desc) desc.style.display = 'block';
        });
    });

    // ========================================
    // スコアプレビュー更新
    // ========================================
    function updateScorePreview() {
        var previewEl = document.getElementById('scorePreview');
        if (!previewEl) return;

        var scores = document.querySelectorAll('.eval-self-score');
        var perf = [], action = [], comp = [];
        scores.forEach(function(s) {
            var val = parseFloat(s.value);
            if (isNaN(val)) return;
            var axis = s.dataset.axis;
            if (axis === 'performance') perf.push(val);
            else if (axis === 'action') action.push(val);
            else if (axis === 'competency') comp.push(val * 20);
        });

        var avg = function(arr) {
            return arr.length ? arr.reduce(function(a,b){return a+b;},0) / arr.length : 0;
        };

        var perfEl = previewEl.querySelector('.perf-score');
        var actEl  = previewEl.querySelector('.action-score');
        var compEl = previewEl.querySelector('.comp-score');
        if (perfEl)  perfEl.textContent = avg(perf).toFixed(1);
        if (actEl)   actEl.textContent  = avg(action).toFixed(1);
        if (compEl)  compEl.textContent = avg(comp).toFixed(1);
    }

    document.querySelectorAll('.eval-self-score').forEach(function(input) {
        input.addEventListener('input', updateScorePreview);
    });

    // ========================================
    // タブ永続化 (URL hash)
    // ========================================
    var hash = window.location.hash;
    if (hash) {
        var tab = document.querySelector('a[href="' + hash + '"]');
        if (tab && typeof bootstrap !== 'undefined') {
            new bootstrap.Tab(tab).show();
        }
    }
    document.querySelectorAll('a[data-bs-toggle="tab"]').forEach(function(tab) {
        tab.addEventListener('shown.bs.tab', function(e) {
            history.replaceState(null, null, e.target.getAttribute('href'));
        });
    });

    // ========================================
    // 等級自動判定 (調整画面)
    // ========================================
    document.querySelectorAll('.final-score-input').forEach(function(input) {
        input.addEventListener('input', function() {
            var score = parseFloat(this.value) || 0;
            var grade;
            if (score >= 90) grade = 'S';
            else if (score >= 80) grade = 'A';
            else if (score >= 60) grade = 'B';
            else if (score >= 40) grade = 'C';
            else grade = 'D';

            var container = this.closest('.card') || this.closest('tr');
            var gradeSelect = container?.querySelector('.grade-select');
            if (gradeSelect) gradeSelect.value = grade;
        });
    });

    // ========================================
    // 提出確認
    // ========================================
    document.querySelectorAll('.eval-submit-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            if (!confirm('評価を提出しますか？提出後は編集できません。')) {
                e.preventDefault();
            }
        });
    });

    // ========================================
    // 自動保存 (5分間隔)
    // ========================================
    var autoSaveForm = document.getElementById('evalForm');
    if (autoSaveForm && autoSaveForm.dataset.autosave === 'true') {
        setInterval(function() {
            var saveBtn = autoSaveForm.querySelector('[value="save_draft"]');
            if (saveBtn) {
                var indicator = document.getElementById('autoSaveIndicator');
                if (indicator) indicator.textContent = '保存中...';
                saveBtn.click();
            }
        }, 300000); // 5min
    }

});
