/**
 * bMS SPI・SF受検 JavaScript
 */
document.addEventListener('DOMContentLoaded', function() {

    // ========================================
    // Likertボタン選択
    // ========================================
    document.querySelectorAll('.likert-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var group = this.closest('.likert-group');
            var radio = this.querySelector('input[type=radio]');
            if (!radio) return;

            // 選択状態を更新
            group.querySelectorAll('.likert-btn').forEach(function(b) { b.classList.remove('selected'); });
            this.classList.add('selected');
            radio.checked = true;

            // 質問カードにanswered状態を追加
            var card = this.closest('.question-card');
            if (card) card.classList.add('answered');

            // 進捗更新
            updateProgress();

            // 自動保存
            autoSave(radio);
        });
    });

    // ========================================
    // 進捗カウント更新
    // ========================================
    function updateProgress() {
        var total = document.querySelectorAll('.question-card').length;
        var answered = document.querySelectorAll('.question-card.answered').length;
        var progressFill = document.querySelector('.assessment-progress .fill');
        var progressText = document.getElementById('progressCount');

        if (progressFill && total > 0) {
            progressFill.style.width = (answered / total * 100) + '%';
        }
        if (progressText) {
            progressText.textContent = answered + ' / ' + total;
        }
    }

    // 初期状態のカウント
    updateProgress();

    // ========================================
    // 自動保存（API送信）
    // ========================================
    function autoSave(radio) {
        var form = radio.closest('form');
        if (!form) return;

        var type = form.dataset.type;
        var attemptId = form.dataset.attemptId;
        var questionId = radio.name.replace('q_', '');
        var answer = radio.value;
        var basePath = window.BMS_BASE_PATH || '';

        fetch(basePath + '/public/api/assessment_save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                type: type,
                attempt_id: parseInt(attemptId),
                question_id: parseInt(questionId),
                answer: parseInt(answer)
            })
        }).catch(function(err) {
            console.log('Auto-save failed:', err);
        });
    }

    // ========================================
    // ページ送信バリデーション
    // ========================================
    var submitBtns = document.querySelectorAll('.assessment-next-btn, .assessment-complete-btn');
    submitBtns.forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            var total = document.querySelectorAll('.question-card').length;
            var answered = document.querySelectorAll('.question-card.answered').length;

            if (answered < total) {
                e.preventDefault();
                var unanswered = document.querySelector('.question-card:not(.answered)');
                if (unanswered) {
                    unanswered.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    unanswered.style.animation = 'shake 0.5s';
                    setTimeout(function() { unanswered.style.animation = ''; }, 500);
                }
                alert('すべての質問に回答してください（残り ' + (total - answered) + ' 問）');
                return false;
            }

            // 完了ボタンの場合は確認
            if (btn.classList.contains('assessment-complete-btn')) {
                if (!confirm('診断を完了し、結果を確定しますか？')) {
                    e.preventDefault();
                    return false;
                }
            }
        });
    });

});
