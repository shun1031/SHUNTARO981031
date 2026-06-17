/* ============================================================
   bMS - メインJavaScript
   ============================================================ */

document.addEventListener('DOMContentLoaded', () => {

    // ---- ツールチップの初期化 ----
    const tooltipEls = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    tooltipEls.forEach(el => new bootstrap.Tooltip(el, { trigger: 'hover' }));

    // ---- スコアバーのアニメーション ----
    const scoreObserver = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const fill = entry.target.querySelector('.score-fill');
                if (fill) {
                    const width = fill.dataset.width || '0';
                    setTimeout(() => { fill.style.width = width + '%'; }, 100);
                }
                scoreObserver.unobserve(entry.target);
            }
        });
    }, { threshold: 0.2 });

    document.querySelectorAll('.score-bar').forEach(el => scoreObserver.observe(el));

    // ---- 社員検索フィルター ----
    const searchInput = document.getElementById('employeeSearch');
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            const q = searchInput.value.toLowerCase();
            document.querySelectorAll('.employee-filter-item').forEach(card => {
                const text = card.dataset.search || '';
                card.closest('.col')?.classList.toggle('d-none', !text.includes(q));
            });
        });
    }
});

// BASE_PATH をグローバルに（PHPから埋め込む）
let BASE_PATH = window.BMS_BASE_PATH || '';

/* ---- AI相談 ---- */
const ConsultationChat = {
    chatArea: null,
    form: null,
    input: null,
    submitBtn: null,

    init() {
        this.chatArea  = document.getElementById('chatArea');
        this.form      = document.getElementById('consultationForm');
        this.input     = document.getElementById('consultationInput');
        this.submitBtn = document.getElementById('consultationSubmit');
        if (!this.form) return;

        this.form.addEventListener('submit', e => {
            e.preventDefault();
            this.sendMessage();
        });
    },

    sendMessage() {
        const question = this.input.value.trim();
        if (!question) return;

        const employees = Array.from(
            document.querySelectorAll('.employee-checkbox:checked')
        ).map(cb => cb.value);

        this.appendUserBubble(question);
        this.input.value = '';
        this.submitBtn.disabled = true;
        this.showTyping();

        fetch(BASE_PATH + '/public/api/consultation.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ question, employees }),
        })
        .then(r => {
            // リダイレクト（ログイン切れ）をチェック
            if (r.redirected || r.url.includes('login.php')) {
                throw new Error('セッションが切れました。ページを再読み込みしてログインしてください。');
            }
            const ct = r.headers.get('content-type') || '';
            if (!ct.includes('application/json')) {
                throw new Error('セッションが切れた可能性があります。ページを再読み込みしてください。');
            }
            return r.json();
        })
        .then(data => {
            this.hideTyping();
            if (data.answer) {
                this.appendAiBubble(data.answer);
            } else if (data.error) {
                this.appendAiBubble('⚠️ ' + data.error + (data.detail ? '\n\n詳細: ' + data.detail : ''));
            } else {
                this.appendAiBubble('エラーが発生しました。もう一度お試しください。');
            }
        })
        .catch(err => {
            this.hideTyping();
            this.appendAiBubble('⚠️ ' + (err.message || '通信エラーが発生しました。ネットワーク接続を確認してください。'));
        })
        .finally(() => { this.submitBtn.disabled = false; });
    },

    appendUserBubble(text) {
        const div = document.createElement('div');
        div.className = 'chat-bubble chat-user';
        div.textContent = text;
        this.chatArea.appendChild(div);
        this.scrollToBottom();
    },

    appendAiBubble(text) {
        const div = document.createElement('div');
        div.className = 'chat-bubble chat-ai';
        div.innerHTML = marked.parse ? marked.parse(text) : text.replace(/\n/g, '<br>');
        this.chatArea.appendChild(div);
        this.scrollToBottom();
    },

    showTyping() {
        const div = document.createElement('div');
        div.id = 'typingIndicator';
        div.className = 'chat-typing d-inline-block';
        div.innerHTML = '<span class="typing-dot"></span><span class="typing-dot"></span><span class="typing-dot"></span>';
        this.chatArea.appendChild(div);
        this.scrollToBottom();
    },

    hideTyping() {
        const el = document.getElementById('typingIndicator');
        if (el) el.remove();
    },

    scrollToBottom() {
        if (this.chatArea) {
            this.chatArea.scrollTop = this.chatArea.scrollHeight;
        }
    }
};

ConsultationChat.init();
