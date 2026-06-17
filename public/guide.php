<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
requireAnyLogin();
$cid = getCompanyId();
$isAdmin = in_array($_SESSION['user_role'] ?? '', ['super_admin', 'company_admin']);
$pageTitle = '使い方ガイド';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div>
            <h1><i class="bi bi-book me-2"></i>bMS 使い方ガイド</h1>
            <p>タレントマネジメントシステムの全機能を解説します</p>
        </div>
    </div>

    <!-- 目次 -->
    <div class="card mb-4">
        <div class="card-header fw-bold"><i class="bi bi-list-ol me-2"></i>目次</div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <h6 class="text-primary">社員向け</h6>
                    <ol class="mb-3">
                        <li><a href="#login">ログイン方法</a></li>
                        <li><a href="#dashboard">ダッシュボード</a></li>
                        <li><a href="#sf-test">SF診断の受け方</a></li>
                        <li><a href="#spi-test">SPI検査の受け方</a></li>
                        <li><a href="#my-results">マイ診断結果の見方</a></li>
                        <li><a href="#eval-self">自己評価の入力方法</a></li>
                        <li><a href="#praise">褒めポイントの使い方</a></li>
                        <li><a href="#evp-book">EVP BOOKの見方</a></li>
                        <li><a href="#income-sim">年収シミュレーター</a></li>
                        <li><a href="#eval-sheet">人事評価表</a></li>
                    </ol>
                </div>
                <div class="col-md-6">
                    <h6 class="text-success">管理者向け</h6>
                    <ol start="9">
                        <li><a href="#admin-setup">初期セットアップ手順</a></li>
                        <li><a href="#admin-employee">社員・ユーザー管理</a></li>
                        <li><a href="#admin-eval">評価制度の設定</a></li>
                        <li><a href="#admin-eval-flow">評価ワークフロー</a></li>
                        <li><a href="#admin-salary">給与・賞与シミュレーション</a></li>
                        <li><a href="#admin-training">研修・育成連動</a></li>
                        <li><a href="#admin-report">レポート・分析</a></li>
                        <li><a href="#admin-onboarding">入社・扶養者連絡票の出力</a></li>
                        <li><a href="#admin-evp-settings">EVP制度の管理</a></li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== 社員向け ===== -->
    <h3 class="mb-3 text-primary"><i class="bi bi-person me-2"></i>社員向けガイド</h3>

    <!-- 1. ログイン -->
    <div class="card mb-4" id="login">
        <div class="card-header fw-bold"><i class="bi bi-box-arrow-in-right me-2"></i>1. ログイン方法</div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <h6>個人ログイン</h6>
                    <p>ログイン画面で、管理者から通知された<strong>ユーザーID</strong>と<strong>パスワード</strong>を入力してログインします。</p>
                    <div class="alert alert-info py-2 small">
                        <i class="bi bi-lightbulb me-1"></i>ログインページ（<code>login.php</code>）をブックマークしておくと便利です。
                    </div>
                </div>
                <div class="col-md-6">
                    <h6>パスワードについて</h6>
                    <p>パスワードは管理者が設定・変更します。</p>
                    <div class="alert alert-warning py-2 small">
                        <i class="bi bi-shield-lock me-1"></i>パスワードを忘れた場合は管理者に連絡してください。
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 2. ダッシュボード -->
    <div class="card mb-4" id="dashboard">
        <div class="card-header fw-bold"><i class="bi bi-house me-2"></i>2. ダッシュボード</div>
        <div class="card-body">
            <p>ログイン後の最初の画面です。以下の情報が表示されます：</p>
            <ul>
                <li><strong>社員一覧</strong>：同じ会社の社員カードが表示されます</li>
                <li><strong>チーム情報</strong>：所属チームとメンバー</li>
                <li><strong>診断状況</strong>：SF/SPIの受検状況</li>
            </ul>
        </div>
    </div>

    <!-- 3. SF診断 -->
    <div class="card mb-4" id="sf-test">
        <div class="card-header fw-bold"><i class="bi bi-lightning me-2"></i>3. ストレングスファインダー診断の受け方</div>
        <div class="card-body">
            <p>ナビ → <strong>分析</strong> → <strong>SF診断を受ける</strong> からアクセスします。</p>
            <div class="table-responsive"><table class="table table-bordered">
                <tr><th style="width:120px">問題数</th><td>68問（4ページに分割）</td></tr>
                <tr><th>カテゴリ</th><td>実行力 → 影響力 → 人間関係力 → 戦略的思考力</td></tr>
                <tr><th>回答方法</th><td>5段階（全く当てはまらない〜とても当てはまる）</td></tr>
                <tr><th>所要時間</th><td>約15〜20分</td></tr>
                <tr><th>中断・再開</th><td>可能。回答は自動保存されます</td></tr>
                <tr><th>完了後</th><td>Top5の強みが判定され、<strong>AIが自動分析コメントを生成</strong>します</td></tr>
            </table></div>
        </div>
    </div>

    <!-- 4. SPI検査 -->
    <div class="card mb-4" id="spi-test">
        <div class="card-header fw-bold"><i class="bi bi-activity me-2"></i>4. SPI性格検査の受け方</div>
        <div class="card-body">
            <p>ナビ → <strong>分析</strong> → <strong>SPI検査を受ける</strong> からアクセスします。</p>
            <div class="table-responsive"><table class="table table-bordered">
                <tr><th style="width:120px">問題数</th><td>78問（5ページに分割）</td></tr>
                <tr><th>カテゴリ</th><td>行動的側面 → 意欲的側面 → 情緒的側面 → 社会関係的側面 → 職場適応性</td></tr>
                <tr><th>回答方法</th><td>5段階（全く当てはまらない〜とても当てはまる）</td></tr>
                <tr><th>所要時間</th><td>約15〜20分</td></tr>
                <tr><th>中断・再開</th><td>可能。回答は自動保存されます</td></tr>
                <tr><th>完了後</th><td>26次元のスコアが算出され、<strong>AIが自動分析コメントを生成</strong>します</td></tr>
            </table></div>
        </div>
    </div>

    <!-- 5. マイ診断結果 -->
    <div class="card mb-4" id="my-results">
        <div class="card-header fw-bold"><i class="bi bi-person-badge me-2"></i>5. マイ診断結果の見方</div>
        <div class="card-body">
            <p>ナビ → <strong>分析</strong> → <strong>マイ診断結果</strong> からアクセスします。</p>
            <h6 class="mt-3">表示内容</h6>
            <ul>
                <li><strong>受検ステータス</strong>：SF/SPIそれぞれの受検状況（受検済み/未受検）</li>
                <li><strong>SF結果</strong>：Top5テーブル + ドメイン分布チャート + AI分析コメント</li>
                <li><strong>SPI結果</strong>：強み/伸びしろ比較テーブル + 職場適応性レーダーチャート + 全次元スコア一覧 + AI分析コメント</li>
            </ul>
            <div class="alert alert-success py-2 small">
                <i class="bi bi-arrow-repeat me-1"></i>再受検：何度でも受け直せます。最新の結果が上書きされます。
            </div>
        </div>
    </div>

    <!-- 6. 自己評価 -->
    <div class="card mb-4" id="eval-self">
        <div class="card-header fw-bold"><i class="bi bi-pencil-square me-2"></i>6. 自己評価の入力方法</div>
        <div class="card-body">
            <p>ナビ → <strong>評価</strong> → <strong>自己評価</strong> からアクセスします。</p>
            <p>評価期間が「自己評価受付中」のときに入力できます。</p>
            <h6 class="mt-3">3つのタブ</h6>
            <div class="table-responsive"><table class="table table-bordered">
                <thead class="table-light"><tr><th>タブ</th><th>内容</th><th>入力方法</th></tr></thead>
                <tbody>
                    <tr>
                        <td><span class="badge bg-primary">業績</span></td>
                        <td>売上・粗利などの数値目標</td>
                        <td>目標値・実績値を入力 → 達成率が自動計算</td>
                    </tr>
                    <tr>
                        <td><span class="badge bg-success">行動</span></td>
                        <td>訪問件数・架電数などのプロセス</td>
                        <td>実績値を入力 or チェック</td>
                    </tr>
                    <tr>
                        <td><span class="badge bg-purple" style="background:#9b59b6">コンピテンシー</span></td>
                        <td>チームワーク・主体性などの能力</td>
                        <td>1〜5段階で自己評価</td>
                    </tr>
                </tbody>
            </table></div>
            <div class="alert alert-info py-2 small">
                <i class="bi bi-save me-1"></i><strong>一時保存</strong>：途中で保存して後から再開できます。<br>
                <i class="bi bi-send me-1"></i><strong>提出</strong>：提出すると編集できなくなります。提出前によく確認してください。
            </div>
        </div>
    </div>

    <!-- 7. 褒めポイント -->
    <div class="card mb-4" id="praise">
        <div class="card-header fw-bold"><i class="bi bi-star me-2"></i>7. 褒めポイントの使い方</div>
        <div class="card-body">
            <p>ナビ → <strong>評価</strong> → <strong>褒めポイント</strong> からアクセスします。</p>
            <p>日常の良い行動を見つけたとき、その場で1行メモを記録できます。期末評価のときに参考データとして活用されます。</p>
            <h6>入力項目</h6>
            <ul>
                <li><strong>対象社員</strong>：褒めたい社員を選択</li>
                <li><strong>カテゴリ</strong>：チームワーク / 主体性 / 品質 / 成長 / その他</li>
                <li><strong>メモ</strong>：具体的な行動を記入（500文字以内）</li>
            </ul>
        </div>
    </div>

    <!-- 8. EVP BOOK -->
    <div class="card mb-4" id="evp-book">
        <div class="card-header fw-bold"><i class="bi bi-journal-text me-2"></i>8. EVP BOOKの見方</div>
        <div class="card-body">
            <p>ナビ → <strong>評価</strong> → <strong>EVP BOOK</strong> からアクセスします。</p>
            <p>会社の給与・評価・稼働ルールが一覧で確認できます。</p>
            <h6>掲載内容</h6>
            <ul>
                <li><strong>給与体系の全体構造</strong>：基本給 + 等級給 + 役職給 + インセンティブ + 賞与の構成</li>
                <li><strong>等級・役職の構造</strong>：等級（Grade）と役職（Position）の対応表</li>
                <li><strong>昇格・降格条件</strong>：各等級・役職の条件</li>
                <li><strong>営業インセンティブ</strong>：等級別の件数・粗利率による報酬</li>
                <li><strong>役職インセンティブ</strong>：部長・課長の売上連動報酬</li>
                <li><strong>賞与変動ルール</strong>：評価ランク（S〜C）による支給率</li>
                <li><strong>稼働スケジュール</strong>：営業日・休日・会議日・勤務時間</li>
            </ul>
        </div>
    </div>

    <!-- 10. 年収シミュレーター -->
    <div class="card mb-4" id="income-sim">
        <div class="card-header fw-bold"><i class="bi bi-calculator me-2"></i>9. 年収シミュレーター</div>
        <div class="card-body">
            <p>ナビ → <strong>評価</strong> → <strong>年収シミュレーター</strong> からアクセスします。</p>
            <p>等級・役職・月間実行数・評価ランクを入力すると、<strong>推定年収がリアルタイムで計算</strong>されます。</p>
            <h6>入力項目</h6>
            <ul>
                <li>等級（クローザー/サブクローザー/アポインター/研修アポインター）</li>
                <li>役職（部長/課長/主任/なし）</li>
                <li>月間実行数、1実行あたり粗利（万円）</li>
                <li>婚姻状況（独身/既婚）— 家賃補助の計算用</li>
                <li>評価ランク（S/A+/A/B/C）— 賞与の計算用</li>
            </ul>
            <h6>計算される内容</h6>
            <ul>
                <li>月給 = 基本給 + 等級給 + 役職給</li>
                <li>月収 = 月給 + 営業インセンティブ + 役職インセンティブ</li>
                <li>推定年収 = 月収×12 + 賞与 + 家賃補助 + 社用車</li>
            </ul>
        </div>
    </div>

    <!-- 11. 人事評価表 -->
    <div class="card mb-4" id="eval-sheet">
        <div class="card-header fw-bold"><i class="bi bi-clipboard-data me-2"></i>10. 人事評価表</div>
        <div class="card-body">
            <p>ナビ → <strong>評価</strong> → <strong>人事評価表</strong> からアクセスします。</p>
            <p>会社独自の評価テンプレートに沿って評価を実施できます。</p>
            <h6>使い方</h6>
            <ol>
                <li>テンプレートと被評価者を選択して「評価を開始」</li>
                <li>各評価項目に点数（5段階/10段階）を入力</li>
                <li>画面右下にリアルタイムで合計点が表示されます</li>
                <li>「下書き保存」で途中保存、「提出する」で確定</li>
                <li>「印刷 / PDF出力」ボタンで帳票形式で出力</li>
            </ol>
            <div class="alert alert-warning py-2 small">
                <i class="bi bi-exclamation-triangle me-1"></i>降格ルールが設定されている場合（例: 2回連続で20点以下は降格）、評価結果に注意してください。
            </div>
        </div>
    </div>

    <?php if ($isAdmin): ?>
    <!-- ===== 管理者向け ===== -->
    <h3 class="mb-3 mt-5 text-success"><i class="bi bi-shield-check me-2"></i>管理者向けガイド</h3>

    <!-- 9. 初期セットアップ -->
    <div class="card mb-4" id="admin-setup">
        <div class="card-header fw-bold"><i class="bi bi-gear me-2"></i>9. 初期セットアップ手順</div>
        <div class="card-body">
            <p>新しい会社でbMSを利用開始する際の手順です。</p>
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead class="table-dark"><tr><th style="width:50px">順</th><th>作業</th><th>画面</th><th>説明</th></tr></thead>
                    <tbody>
                        <tr><td class="text-center fw-bold">1</td><td>社員を登録</td><td>管理画面 → 社員管理</td><td>CSVインポートまたは1人ずつ登録</td></tr>
                        <tr><td class="text-center fw-bold">2</td><td>ログインアカウント作成</td><td>管理画面 → ユーザー管理</td><td>社員にユーザーID/パスワードを発行</td></tr>
                        <tr><td class="text-center fw-bold">3</td><td>チームを作成</td><td>管理画面 → チーム管理</td><td>チームを作りメンバーを割り当て</td></tr>
                        <tr><td class="text-center fw-bold">4</td><td>社員にSF/SPI受検を依頼</td><td>各社員がログイン → 分析</td><td>受検完了でAI分析が自動生成</td></tr>
                        <tr><td class="text-center fw-bold">5</td><td>評価制度を設定</td><td>管理画面 → 評価管理</td><td>下記「評価制度の設定」参照</td></tr>
                        <tr><td class="text-center fw-bold">6</td><td>等級・号俸テーブル設定</td><td>管理画面 → 等級テーブル</td><td>下記「給与・賞与」参照</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- 10. 社員・ユーザー管理 -->
    <div class="card mb-4" id="admin-employee">
        <div class="card-header fw-bold"><i class="bi bi-person-plus me-2"></i>10. 社員・ユーザー管理</div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <h6>社員管理</h6>
                    <p>管理画面 → <a href="<?= BASE_PATH ?>/admin/employees.php">社員管理</a></p>
                    <ul>
                        <li>社員の追加・編集・削除（非表示/完全削除を選択可）</li>
                        <li>CSVインポートで一括登録</li>
                        <li>SF/SPI未登録者のフィルタ</li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <h6>ユーザー管理</h6>
                    <p>管理画面 → <a href="<?= BASE_PATH ?>/admin/employee_users.php">ユーザー管理</a></p>
                    <ul>
                        <li>社員にログインアカウントを作成</li>
                        <li>ユーザーID・パスワードの設定・変更</li>
                        <li>権限設定（一般社員 / 会社管理者）</li>
                        <li>アカウントの有効化/無効化</li>
                    </ul>
                    <div class="alert alert-info py-2 small">
                        <i class="bi bi-key me-1"></i>パスワードは🔑ボタンから変更でき、新パスワードが画面に表示されます。
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 11. 評価制度の設定 -->
    <div class="card mb-4" id="admin-eval">
        <div class="card-header fw-bold"><i class="bi bi-clipboard-check me-2"></i>11. 評価制度の設定</div>
        <div class="card-body">
            <p>3軸評価（業績・行動・コンピテンシー）の設定手順です。</p>
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead class="table-light"><tr><th style="width:50px">順</th><th>設定</th><th>画面</th><th>説明</th></tr></thead>
                    <tbody>
                        <tr>
                            <td class="text-center fw-bold">1</td>
                            <td>部署別ウェイト</td>
                            <td><a href="<?= BASE_PATH ?>/admin/eval_weights.php">管理 → 部署別ウェイト</a></td>
                            <td>
                                部署ごとに3軸の比率を設定します（合計100%）<br>
                                <small class="text-muted">例：営業=業績60%/行動30%/コンピテンシー10%、事務=業績10%/行動40%/コンピテンシー50%</small>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-center fw-bold">2</td>
                            <td>コンピテンシー項目</td>
                            <td><a href="<?= BASE_PATH ?>/admin/eval_competency_items.php">管理 → コンピテンシー</a></td>
                            <td>
                                会社共通のコンピテンシー項目を定義します<br>
                                <small class="text-muted">各項目に5段階の行動定義（1点=改善が必要〜5点=卓越）を設定</small>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-center fw-bold">3</td>
                            <td>評価期間を作成</td>
                            <td><a href="<?= BASE_PATH ?>/admin/eval_periods.php">管理 → 評価期間</a></td>
                            <td>
                                評価期間（例：2026年度上期）を作成<br>
                                <small class="text-muted">年度、半期（上期/下期）、開始日・終了日を設定</small>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-center fw-bold">4</td>
                            <td>業績KPI項目</td>
                            <td>評価期間 → 業績項目</td>
                            <td>
                                期間ごとの業績評価項目（売上目標等）を定義<br>
                                <small class="text-muted">単位（円/件/%）、ウェイト、部署を設定</small>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-center fw-bold">5</td>
                            <td>行動チェック項目</td>
                            <td>評価期間 → 行動項目</td>
                            <td>
                                期間ごとの行動チェック項目を定義<br>
                                <small class="text-muted">目標値、頻度（日次/週次/月次）、部署を設定</small>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- 12. 評価ワークフロー -->
    <div class="card mb-4" id="admin-eval-flow">
        <div class="card-header fw-bold"><i class="bi bi-arrow-right-circle me-2"></i>12. 評価ワークフロー</div>
        <div class="card-body">
            <p>評価期間のステータスを進めることで、評価フローが進行します。</p>
            <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
                <span class="badge bg-secondary p-2">下書き</span>
                <i class="bi bi-arrow-right"></i>
                <span class="badge bg-primary p-2">受付開始</span>
                <i class="bi bi-arrow-right"></i>
                <span class="badge bg-info p-2">自己評価</span>
                <i class="bi bi-arrow-right"></i>
                <span class="badge bg-warning text-dark p-2">1次評価</span>
                <i class="bi bi-arrow-right"></i>
                <span class="badge bg-orange p-2" style="background:#e67e22">調整会議</span>
                <i class="bi bi-arrow-right"></i>
                <span class="badge bg-success p-2">フィードバック</span>
                <i class="bi bi-arrow-right"></i>
                <span class="badge bg-dark p-2">完了</span>
            </div>
            <div class="table-responsive"><table class="table table-bordered small">
                <thead class="table-light"><tr><th>ステータス</th><th>何が起きるか</th><th>誰が何をする</th></tr></thead>
                <tbody>
                    <tr><td><span class="badge bg-primary">受付開始</span></td><td>評価シートが全社員分生成される</td><td>管理者がステータスを変更</td></tr>
                    <tr><td><span class="badge bg-info">自己評価</span></td><td>社員が自分のシートに入力可能に</td><td>社員が業績/行動/コンピテンシーを入力</td></tr>
                    <tr><td><span class="badge bg-warning text-dark">1次評価</span></td><td>上司が部下のシートを評価可能に</td><td>上司が部下の自己評価を確認し1次評価を入力</td></tr>
                    <tr><td><span class="badge" style="background:#e67e22;color:#fff">調整会議</span></td><td>全管理職でグレードの甘辛を調整</td><td>管理者が<a href="<?= BASE_PATH ?>/admin/eval_adjustment.php">調整画面</a>で最終グレード確定</td></tr>
                    <tr><td><span class="badge bg-success">フィードバック</span></td><td>結果を社員に伝える面談</td><td>上司が面談シートに記入、研修を割り当て</td></tr>
                </tbody>
            </table></div>
        </div>
    </div>

    <!-- 13. 給与・賞与 -->
    <div class="card mb-4" id="admin-salary">
        <div class="card-header fw-bold"><i class="bi bi-calculator me-2"></i>13. 給与・賞与シミュレーション</div>
        <div class="card-body">
            <h6>Step 1: 等級・号俸テーブルを設定</h6>
            <p><a href="<?= BASE_PATH ?>/admin/eval_grade_table.php">管理画面 → 等級・号俸テーブル</a></p>
            <ul>
                <li>等級（G1〜G5など）を作成</li>
                <li>各等級に号俸（ステップ）と基本給額を設定</li>
                <li>評価グレード（S/A/B/C/D）→ 号俸変動ルールを設定</li>
            </ul>

            <h6 class="mt-3">Step 2: 給与シミュレーション</h6>
            <p><a href="<?= BASE_PATH ?>/admin/salary_simulation.php">管理画面 → 給与シミュレーション</a></p>
            <ul>
                <li>評価を確定する前に「この評価だと給与がいくらになるか」を確認</li>
                <li>各社員の現在給与 → 評価後の新給与 → 差額が一覧表示</li>
            </ul>

            <h6 class="mt-3">賞与の計算式</h6>
            <div class="alert alert-light border">
                <code>賞与額 = 基本給 × 基準月数 × 業績評価係数 × 会社業績指数</code>
                <ul class="mt-2 mb-0 small">
                    <li>S評価: 係数 1.5 / A評価: 1.2 / B評価: 1.0 / C評価: 0.8 / D評価: 0.6</li>
                    <li>会社業績指数: 業績好調なら1.2、通常なら1.0など管理者が設定</li>
                </ul>
            </div>

            <h6 class="mt-3">基本給への反映ルール</h6>
            <div class="table-responsive"><table class="table table-bordered small">
                <thead class="table-light"><tr><th>評価グレード</th><th>号俸変動</th><th>意味</th></tr></thead>
                <tbody>
                    <tr><td><span class="badge bg-danger">S</span></td><td>+2ステップ</td><td>大幅昇給</td></tr>
                    <tr><td><span class="badge bg-primary">A</span></td><td>+1ステップ</td><td>昇給</td></tr>
                    <tr><td><span class="badge bg-success">B</span></td><td>変動なし</td><td>現状維持</td></tr>
                    <tr><td><span class="badge bg-warning text-dark">C</span></td><td>-1ステップ</td><td>降給検討</td></tr>
                    <tr><td><span class="badge bg-secondary">D</span></td><td>-2ステップ</td><td>降給・再教育</td></tr>
                </tbody>
            </table></div>
        </div>
    </div>

    <!-- 14. 研修・育成 -->
    <div class="card mb-4" id="admin-training">
        <div class="card-header fw-bold"><i class="bi bi-mortarboard me-2"></i>14. 研修・育成連動</div>
        <div class="card-body">
            <h6>Step 1: 研修カタログを登録</h6>
            <p><a href="<?= BASE_PATH ?>/admin/training_catalog.php">管理画面 → 研修カタログ</a></p>
            <ul>
                <li>研修名・説明・カテゴリ（営業/プロセス/コンピテンシー/一般）</li>
                <li>所要時間・URLなど</li>
            </ul>

            <h6 class="mt-3">Step 2: 自動推奨ルールを設定</h6>
            <p><a href="<?= BASE_PATH ?>/admin/training_rules.php">管理画面 → 自動推奨ルール</a></p>
            <ul>
                <li>「業績スコアが60点未満 → クロージング研修を推奨」のようなルール</li>
                <li>トリガー軸（業績/行動/コンピテンシー）と閾値を設定</li>
            </ul>

            <h6 class="mt-3">Step 3: フィードバック面談で研修を割り当て</h6>
            <p>フィードバック面談画面で、その場で研修を社員に割り当てできます。</p>
        </div>
    </div>

    <!-- 15. レポート -->
    <div class="card mb-4" id="admin-report">
        <div class="card-header fw-bold"><i class="bi bi-graph-up me-2"></i>15. レポート・分析</div>
        <div class="card-body">
            <h6>評価レポート</h6>
            <p><a href="<?= BASE_PATH ?>/admin/eval_reports.php">管理画面 → 評価レポート</a></p>
            <ul>
                <li>グレード分布（S/A/B/C/D の人数と割合）</li>
                <li>部署別平均スコア</li>
                <li>CSV出力（全評価データのダウンロード）</li>
            </ul>

            <h6 class="mt-3">SF/SPI全体分析</h6>
            <ul>
                <li><a href="<?= BASE_PATH ?>/public/strengths.php">SF全体分析</a>：組織のテーマ傾向、ドメイン分布</li>
                <li><a href="<?= BASE_PATH ?>/public/spi.php">SPI全体分析</a>：組織の職場適応性レーダー、各次元の平均</li>
            </ul>
        </div>
    </div>
    <!-- 16. 入社・扶養者連絡票 -->
    <div class="card mb-4" id="admin-onboarding">
        <div class="card-header fw-bold"><i class="bi bi-file-earmark-text me-2"></i>16. 入社・扶養者連絡票の出力</div>
        <div class="card-body">
            <p>社員の入社情報を登録し、社労士向けの連絡票を自動出力できます。</p>
            <h6>Step 1: 入社情報を入力</h6>
            <p>社員管理 → 社員を選択 → <strong>「入社情報」タブ</strong></p>
            <ul>
                <li>個人情報（性別、住所、電話番号、マイナンバー）</li>
                <li>社会保険（基礎年金番号、被保険者番号）</li>
                <li>給与情報（基本給、手当、通勤手当）</li>
                <li>振込先（銀行名、支店名、口座番号）</li>
            </ul>
            <h6>Step 2: 連絡票を出力</h6>
            <p>「入社情報」タブの<strong>「連絡票を出力」</strong>ボタンをクリック → ブラウザの印刷機能でPDFに変換できます。</p>
        </div>
    </div>

    <!-- 17. EVP制度の管理 -->
    <div class="card mb-4" id="admin-evp-settings">
        <div class="card-header fw-bold"><i class="bi bi-journal-text me-2"></i>17. EVP制度（等級・給与体系）の管理</div>
        <div class="card-body">
            <h6>給与体系</h6>
            <p><a href="<?= BASE_PATH ?>/admin/salary_table.php">管理画面 → 給与体系</a></p>
            <ul>
                <li>事業部×ラダー別の給与テーブル（等級ごとの月売上閾値と月給）</li>
                <li>ゴールド/シルバー/ブロンズの等級カラー管理</li>
            </ul>
            <h6>EVP BOOK</h6>
            <p>EVP BOOKの内容（等級・役職・インセンティブ・賞与ルール等）はDBに登録されており、社員がログインすると自動的に最新のEVP BOOKが表示されます。</p>
            <h6>年収シミュレーター</h6>
            <p>EVP BOOKのデータを使って、社員が自分の等級・役職・実績で推定年収をシミュレーションできます。</p>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
