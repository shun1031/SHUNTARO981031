<?php
/**
 * 案件人員一覧
 *
 * 左＝案件 / 右＝人員 を並べて、営業担当ごとの状況を1画面で見る。
 * 案件側と人員側の営業担当プルダウンは完全に独立していて、
 * 片方を切り替えてももう片方は変わらない（この画面の一番の目的）。
 *
 * 案件は既存の sales_cases をそのまま読み、追加も既存の api/save_case.php を使う。
 * 人員だけは「まだ案件に入っていない候補」を case_staff_candidates に持つ。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
// 営業マン用画面: 管理者または営業担当のみ閲覧可（URL直打ちでも弾く）
requireSalesPageView();
$cid = getCompanyId();
if (!$cid) { redirect(BASE_PATH . '/public/index.php'); }

$pageTitle = '案件人員一覧';
$extraCss  = ['sales.css'];

$csrf = getCsrfToken();

// 営業担当の候補（案件フォームとまったく同じ基準にして表記ゆれを防ぐ）
$repCandidates = getSalesRepCandidates($cid);
// 案件追加フォーム用のマスタ
$clients   = getSalesClients($cid);
$alliances = getSalesAlliances($cid);
// 人員追加フォームで「社員一覧から選ぶ」ための名簿（二重管理を避けるため）
$staffCandidates = getStaffNameCandidates($cid, false);

$carriers   = ['docomo', 'au', 'SB', '楽天', 'CATV', 'コミュファ'];
$skillTypes = ['キャッチャー', 'クローザー'];
$workerTypes= ['正社員', '自社外注', 'アライアンス', '個人外注', 'アルバイト'];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid csb-wrap">
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="bi bi-diagram-3 me-2"></i>案件人員一覧</h1>
                <p>営業担当ごとの案件と人員を並べて確認できます。左右の担当者は別々に選べます。</p>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <!-- ============ 左：案件 ============ -->
        <div class="col-lg-7">
            <div class="card csb-panel h-100">
                <div class="card-header csb-head">
                    <div class="d-flex align-items-center flex-wrap gap-2">
                        <h2 class="csb-title"><i class="bi bi-briefcase me-1"></i>案件</h2>
                        <div class="csb-filter ms-auto d-flex gap-1">
                            <button type="button" class="csb-filter-btn is-active" data-status="draft"
                                    onclick="csbSetStatus('draft')">未確定</button>
                            <button type="button" class="csb-filter-btn" data-status="confirmed"
                                    onclick="csbSetStatus('confirmed')">確定</button>
                        </div>
                    </div>
                    <div class="d-flex align-items-center flex-wrap gap-2 mt-2">
                        <label class="csb-label">営業担当</label>
                        <select class="form-select form-select-sm csb-rep" id="csbCaseRep" onchange="csbLoadCases()">
                            <option value="">すべて</option>
                            <?php foreach ($repCandidates as $_rc): ?>
                            <option value="<?= h($_rc) ?>"><?= h($_rc) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="input-group input-group-sm csb-search">
                            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                            <input type="text" id="csbCaseQ" class="form-control border-start-0 ps-0"
                                   placeholder="取引先・店舗で検索" oninput="csbCaseSearch()">
                        </div>
                        <?php if (isAdmin()): ?>
                        <button type="button" class="btn btn-primary btn-sm ms-auto" onclick="csbOpenCaseForm()">
                            <i class="bi bi-plus-lg me-1"></i>案件を追加
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="csb-list" id="csbCaseList">
                        <div class="csb-empty"><span class="spinner-border spinner-border-sm me-2"></span>読み込み中...</div>
                    </div>
                    <div class="csb-more">
                        <span class="csb-count" id="csbCaseCount"></span>
                        <button type="button" class="csb-more-btn d-none" id="csbCaseMore" onclick="csbToggleMore('case')">
                            <i class="bi bi-chevron-down"></i><span>すべての案件を表示</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============ 右：人員 ============ -->
        <div class="col-lg-5">
            <div class="card csb-panel h-100">
                <div class="card-header csb-head">
                    <div class="d-flex align-items-center flex-wrap gap-2">
                        <h2 class="csb-title"><i class="bi bi-people me-1"></i>人員</h2>
                        <div class="csb-filter ms-auto d-flex gap-1">
                            <button type="button" class="csb-filter-btn is-active" data-show="pending"
                                    onclick="csbSetStaffShow('pending')">検討中</button>
                            <button type="button" class="csb-filter-btn" data-show="assigned"
                                    onclick="csbSetStaffShow('assigned')">アサイン済</button>
                        </div>
                    </div>
                    <div class="d-flex align-items-center flex-wrap gap-2 mt-2">
                        <label class="csb-label">営業担当</label>
                        <select class="form-select form-select-sm csb-rep" id="csbStaffRep" onchange="csbLoadStaff()">
                            <option value="">すべて</option>
                            <?php foreach ($repCandidates as $_rc): ?>
                            <option value="<?= h($_rc) ?>"><?= h($_rc) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isAdmin()): ?>
                        <button type="button" class="btn btn-primary btn-sm ms-auto" onclick="csbOpenStaffForm()">
                            <i class="bi bi-plus-lg me-1"></i>人員を追加
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="csb-list" id="csbStaffList">
                        <div class="csb-empty"><span class="spinner-border spinner-border-sm me-2"></span>読み込み中...</div>
                    </div>
                    <div class="csb-more">
                        <span class="csb-count" id="csbStaffCount"></span>
                        <button type="button" class="csb-more-btn d-none" id="csbStaffMore" onclick="csbToggleMore('staff')">
                            <i class="bi bi-chevron-down"></i><span>すべての人員を表示</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="alert alert-info d-flex align-items-start gap-2 mt-3" role="alert" style="font-size:.82rem">
        <i class="bi bi-info-circle mt-1"></i>
        <div>
            左の案件を選んでから、右の人員の「アサイン」を押すと、その人の稼働として案件が登録されます。<br>
            登録された案件は常勤案件・イベント案件の画面にもそのまま表示されます。
        </div>
    </div>
</div>

<!-- ============ 人員 追加・編集モーダル ============ -->
<div class="modal fade" id="csbStaffModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title fw-bold" id="csbStaffModalTitle">人員を追加</h6>
                <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="csbStaffId">
                <div class="row g-2">
                    <div class="col-12">
                        <label class="form-label small fw-medium mb-1">氏名 <span class="text-danger">*</span></label>
                        <input type="text" id="csbStaffName" class="form-control form-control-sm"
                               list="csbStaffNameList" placeholder="社員一覧から選択、または入力" autocomplete="off">
                        <datalist id="csbStaffNameList">
                            <?php foreach ($staffCandidates as $_sc): ?>
                            <option value="<?= h($_sc) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                        <div class="form-text" style="font-size:.72rem">
                            社員一覧に居る人は候補から選んでください（同じ人が二重に登録されるのを防げます）。
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-medium mb-1">担当営業 <span class="text-danger">*</span></label>
                        <select id="csbStaffRepSel" class="form-select form-select-sm">
                            <option value="">-- 選択してください --</option>
                            <?php foreach ($repCandidates as $_rc): ?>
                            <option value="<?= h($_rc) ?>"><?= h($_rc) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text" style="font-size:.72rem">
                            ここで選んだ人が、この人員の営業担当になります（案件側の担当とは別です）。
                        </div>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-medium mb-1">所属／外注先</label>
                        <select id="csbStaffAlliance" class="form-select form-select-sm">
                            <option value="">-- 選択なし --</option>
                            <?php foreach ($alliances as $_al): ?>
                            <option value="<?= (int)$_al['id'] ?>"><?= h(allianceLabel($_al)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-medium mb-1">所属（自由入力）</label>
                        <input type="text" id="csbStaffAffil" class="form-control form-control-sm" placeholder="外注先に無い場合">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-medium mb-1">スキル感</label>
                        <select id="csbStaffSkill" class="form-select form-select-sm">
                            <option value="">-- 未設定 --</option>
                            <?php foreach ($skillTypes as $_st): ?>
                            <option value="<?= h($_st) ?>"><?= h($_st) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-medium mb-1">希望キャリア</label>
                        <select id="csbStaffCarrier" class="form-select form-select-sm">
                            <option value="">-- 未設定 --</option>
                            <?php foreach ($carriers as $_cr): ?>
                            <option value="<?= h($_cr) ?>"><?= h($_cr) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-medium mb-1">希望単価（円）</label>
                        <input type="number" id="csbStaffPrice" class="form-control form-control-sm" min="0" step="1000">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-medium mb-1">稼働開始可能日</label>
                        <input type="date" id="csbStaffFrom" class="form-control form-control-sm">
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-medium mb-1">備考</label>
                        <textarea id="csbStaffNote" class="form-control form-control-sm" rows="2"></textarea>
                    </div>
                </div>
                <div id="csbStaffError" class="text-danger small mt-2" style="white-space:pre-line"></div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-outline-danger btn-sm me-auto" id="csbStaffDelete"
                        onclick="csbDeleteStaff()" style="display:none">削除</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">キャンセル</button>
                <button type="button" class="btn btn-primary btn-sm" id="csbStaffSave" onclick="csbSaveStaff()">保存</button>
            </div>
        </div>
    </div>
</div>

<!-- ============ アサイン モーダル ============ -->
<div class="modal fade" id="csbAssignModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title fw-bold">案件にアサイン</h6>
                <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="csbAssignStaffId">
                <div class="csb-assign-info mb-3" id="csbAssignInfo"></div>
                <div class="row g-2">
                    <div class="col-12">
                        <label class="form-label small fw-medium mb-1">請求単価（円）</label>
                        <input type="number" id="csbAssignIn" class="form-control form-control-sm" min="0" step="1000" value="0">
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-medium mb-1">支払単価（円）</label>
                        <input type="number" id="csbAssignOut" class="form-control form-control-sm" min="0" step="1000" value="0">
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-medium mb-1" id="csbAssignDaysLabel">稼働日数</label>
                        <input type="number" id="csbAssignDays" class="form-control form-control-sm" min="0" value="21">
                    </div>
                </div>
                <div class="form-text mt-2" style="font-size:.72rem">
                    常勤は月の合計額を単価欄に入れてください。登録すると常勤案件・イベント案件の画面にも表示されます。
                </div>
                <div id="csbAssignError" class="text-danger small mt-2" style="white-space:pre-line"></div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">キャンセル</button>
                <button type="button" class="btn btn-primary btn-sm" id="csbAssignSave" onclick="csbDoAssign()">アサインする</button>
            </div>
        </div>
    </div>
</div>

<!-- ============ 案件 追加モーダル（既存の案件フォームと同じ作り） ============ -->
<div class="modal fade" id="csbCaseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form class="modal-content" id="csbCaseForm">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="id" value="">
            <div class="modal-header py-2">
                <h6 class="modal-title fw-bold" id="csbCaseModalTitle">案件を追加</h6>
                <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label small fw-medium mb-1">案件種別 <span class="text-danger">*</span></label>
                        <select name="case_type" id="csbCaseType" class="form-select form-select-sm" onchange="csbCaseTypeChanged()">
                            <option value="regular">常勤</option>
                            <option value="event">イベント</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-medium mb-1">確定状態</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="draft">未確定</option>
                            <option value="confirmed">確定</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-medium mb-1">必要人数</label>
                        <input type="number" name="recruitment_count" class="form-control form-control-sm" min="0" placeholder="例: 3">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-medium mb-1">取引先 <span class="text-danger">*</span></label>
                        <input type="text" id="csbCaseClient" class="form-control form-control-sm"
                               list="csbClientList" placeholder="選択または直接入力" autocomplete="off">
                        <input type="hidden" name="client_name_input" id="csbCaseClientHidden">
                        <datalist id="csbClientList">
                            <?php foreach ($clients as $_cl): ?>
                            <option value="<?= h(clientLabel($_cl)) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-medium mb-1">営業担当 <span class="text-danger">*</span></label>
                        <select name="sales_rep" id="csbCaseRepSel" class="form-select form-select-sm">
                            <option value="">-- 選択してください --</option>
                            <?php foreach ($repCandidates as $_rc): ?>
                            <option value="<?= h($_rc) ?>"><?= h($_rc) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label small fw-medium mb-1">キャリア</label>
                        <select name="carrier" class="form-select form-select-sm">
                            <option value="">-- 未設定 --</option>
                            <?php foreach ($carriers as $_cr): ?>
                            <option value="<?= h($_cr) ?>"><?= h($_cr) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-medium mb-1">屋号</label>
                        <input type="text" name="trade_name" class="form-control form-control-sm" placeholder="ED / YMD など">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-medium mb-1">店舗名</label>
                        <input type="text" name="store_name" class="form-control form-control-sm">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label small fw-medium mb-1">スタッフ区分</label>
                        <select name="worker_type" class="form-select form-select-sm">
                            <?php foreach ($workerTypes as $_wt): ?>
                            <option value="<?= h($_wt) ?>"><?= h($_wt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-medium mb-1" id="csbCaseStartLabel">開始月 <span class="text-danger">*</span></label>
                        <input type="month" name="start_date" id="csbCaseStart" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-medium mb-1" id="csbCaseEndLabel">終了月</label>
                        <input type="month" name="end_date" id="csbCaseEnd" class="form-control form-control-sm">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label small fw-medium mb-1">区分 <span class="text-danger">*</span></label>
                        <select name="case_division" class="form-select form-select-sm">
                            <option value="1次">1次</option>
                            <option value="2次以降">2次以降</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-medium mb-1">請求単価（円）</label>
                        <input type="number" name="unit_price_in" class="form-control form-control-sm" min="0" step="1000" value="0">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-medium mb-1">支払単価（円）</label>
                        <input type="number" name="unit_price_out" class="form-control form-control-sm" min="0" step="1000" value="0">
                    </div>

                    <div class="col-12">
                        <label class="form-label small fw-medium mb-1">備考</label>
                        <textarea name="notes" class="form-control form-control-sm" rows="2"></textarea>
                    </div>
                </div>
                <div class="form-text mt-2" style="font-size:.72rem">
                    人員がまだ決まっていない案件は「未確定」で登録し、人員が決まったら右の一覧からアサインしてください。
                </div>
                <div class="alert alert-secondary py-2 px-3 mt-2 d-none" id="csbCaseEditNote" style="font-size:.74rem">
                    ここに無い項目（稼働者名・管理者・採用者・稼働日数・光ADなど）は<strong>今の値がそのまま残ります</strong>。
                    それらを直すときは常勤案件・イベント案件の画面で編集してください。
                </div>
                <div id="csbCaseError" class="text-danger small mt-2" style="white-space:pre-line"></div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">キャンセル</button>
                <button type="submit" class="btn btn-primary btn-sm" id="csbCaseSave">登録</button>
            </div>
        </form>
    </div>
</div>

<style>
.csb-panel { border-radius: 12px; }
.csb-head { background: #fff; border-bottom: 1px solid #e9ecef; padding: .75rem 1rem; }
.csb-title { font-size: .95rem; font-weight: 700; color: #1e293b; margin: 0; }
.csb-label { font-size: .75rem; color: #64748b; white-space: nowrap; }
.csb-rep { max-width: 170px; }
.csb-search { max-width: 200px; }

/* 区分の切替（戦略会議と同じ見た目） */
.csb-filter-btn {
    border: 1px solid #e2e8f0; background: #fff; color: #64748b;
    font-size: .75rem; font-weight: 600; padding: .25rem .75rem;
    border-radius: 8px; line-height: 1.4;
}
.csb-filter-btn:hover { background: #f8fafc; }
.csb-filter-btn.is-active { background: #2563eb; border-color: #2563eb; color: #fff; }

/* 一覧。開いてもページが伸びきらないようスクロール領域にする */
.csb-list { max-height: 460px; overflow-y: auto; }
.csb-row {
    display: flex; gap: .6rem; align-items: flex-start;
    padding: .65rem 1rem; border-bottom: 1px solid #f1f5f9; cursor: default;
}
.csb-row:last-child { border-bottom: none; }
.csb-row.is-hidden { display: none; }
.csb-row-main { flex: 1 1 auto; min-width: 0; }
.csb-row-side { flex: 0 0 auto; text-align: right; }
.csb-name { font-size: .84rem; font-weight: 600; color: #1e293b; }
.csb-sub { font-size: .74rem; color: #64748b; margin-top: .15rem; }
.csb-sub span + span::before { content: '／'; color: #cbd5e1; margin: 0 .35rem; }

/* 案件は選択できる（アサイン先になる） */
.csb-row.csb-selectable { cursor: pointer; }
.csb-row.csb-selectable:hover { background: #f8fafc; }
.csb-row.is-selected { background: #eff6ff; box-shadow: inset 3px 0 0 #2563eb; }

.csb-chip {
    display: inline-block; padding: .05rem .45rem; border-radius: .7rem;
    font-size: .68rem; font-weight: 600; white-space: nowrap;
}
.csb-chip-type { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
.csb-chip-ok   { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
.csb-chip-warn { background: #fef9c3; color: #854d0e; border: 1px solid #fde68a; }
.csb-chip-lack { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

/* 行ごとの編集・削除（常勤案件一覧と同じボタンを使う） */
.csb-actions { display: flex; gap: .25rem; justify-content: flex-end; margin-top: .3rem; }
.csb-actions .btn { padding: .15rem .45rem; line-height: 1.1; }
.csb-actions .btn i { font-size: .78rem; }

.csb-empty { padding: 1.6rem 1rem; text-align: center; color: #94a3b8; font-size: .82rem; }
.csb-more {
    display: flex; align-items: center; justify-content: space-between;
    gap: .5rem; padding: .5rem 1rem; border-top: 1px solid #f1f5f9;
}
.csb-count { font-size: .74rem; color: #94a3b8; }
.csb-more-btn {
    border: 1px solid #e2e8f0; background: #fff; color: #2563eb;
    font-size: .75rem; font-weight: 600; padding: .25rem .8rem; border-radius: 8px;
}
.csb-more-btn:hover { background: #eff6ff; }
.csb-more-btn i { margin-right: .3rem; }
.csb-assign-info {
    background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;
    padding: .5rem .65rem; font-size: .78rem; color: #334155;
}
</style>

<?php
$csbApi     = json_encode(BASE_PATH . '/public/api/case_staff_board.php');
$csbSaveApi = json_encode(BASE_PATH . '/public/api/save_case.php');
$csbIsAdmin = isAdmin() ? 'true' : 'false';
$inlineJs = <<<CSBJS
var CSB_API      = {$csbApi};
var CSB_SAVE_API = {$csbSaveApi};
var CSB_CSRF     = '{$csrf}';
var CSB_CAN_EDIT = {$csbIsAdmin};
CSBJS;
$inlineJs .= <<<'CSBJS2'

var csbStatus = 'draft';        // 案件側: draft(未確定) / confirmed(確定)
var csbStaffShow = 'pending';   // 人員側: pending(検討中) / assigned(アサイン済)
var csbCaseOpen = false, csbStaffOpen = false;   // 「すべて表示」の状態
var csbCases = [], csbStaff = [];
var csbSelectedCase = null;     // アサイン先に選んだ案件
var csbCaseTimer = null;
var csbStaffModal = null, csbAssignModal = null, csbCaseModal = null;

var CSB_LIMIT = 5;              // 初期表示の件数

function csbEsc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

/** 2026-04-01 → 26/4 のように短く表示する */
function csbDate(s) {
    if (!s) return '';
    var p = String(s).split('-');
    if (p.length < 3) return s;
    return p[0].slice(2) + '/' + parseInt(p[1], 10) + '/' + parseInt(p[2], 10);
}

// ============================================================
// 案件側
// ============================================================
function csbSetStatus(st) {
    if (csbStatus === st) return;
    csbStatus = st;
    csbSelectedCase = null;
    document.querySelectorAll('.csb-filter-btn[data-status]').forEach(function (b) {
        b.classList.toggle('is-active', b.dataset.status === st);
    });
    csbLoadCases();
}

function csbCaseSearch() {
    clearTimeout(csbCaseTimer);
    csbCaseTimer = setTimeout(csbLoadCases, 250);
}

function csbLoadCases() {
    var rep = document.getElementById('csbCaseRep').value;
    var q   = document.getElementById('csbCaseQ').value.trim();
    var url = CSB_API + '?side=cases&status=' + csbStatus
            + (rep ? '&rep=' + encodeURIComponent(rep) : '')
            + (q   ? '&q='   + encodeURIComponent(q)   : '');
    fetch(url, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.ok) throw new Error('failed');
            csbCases = d.cases || [];
            csbCaseOpen = false;
            csbRenderCases();
        })
        .catch(function () {
            document.getElementById('csbCaseList').innerHTML =
                '<div class="csb-empty text-danger">読み込みに失敗しました</div>';
        });
}

function csbRenderCases() {
    var box = document.getElementById('csbCaseList');
    if (!csbCases.length) {
        box.innerHTML = '<div class="csb-empty">該当する案件がありません</div>';
        document.getElementById('csbCaseCount').textContent = '0件';
        document.getElementById('csbCaseMore').classList.add('d-none');
        return;
    }
    var html = '';
    csbCases.forEach(function (c, i) {
        var hidden = (!csbCaseOpen && i >= CSB_LIMIT) ? ' is-hidden' : '';
        var sel    = (csbSelectedCase && csbSelectedCase.id === c.id) ? ' is-selected' : '';

        // 必要人数に対する充足バッジ
        var badge = '';
        if (c.need_count !== null && c.need_count !== undefined) {
            var cls = c.remaining === 0 ? 'csb-chip-ok' : (c.remaining <= 1 ? 'csb-chip-warn' : 'csb-chip-lack');
            badge = '<span class="csb-chip ' + cls + '">必要' + c.need_count + '名'
                  + '（済' + c.assigned + '／残' + c.remaining + '）</span>';
        } else if (c.worker_name) {
            badge = '<span class="csb-chip csb-chip-type">稼働者あり</span>';
        }

        var sub = [];
        if (c.carrier)    sub.push('<span>' + csbEsc(c.carrier) + '</span>');
        if (c.trade_name) sub.push('<span>' + csbEsc(c.trade_name) + '</span>');
        if (c.store_name) sub.push('<span>' + csbEsc(c.store_name) + '</span>');

        var period = c.start_date ? csbDate(c.start_date) + (c.end_date ? '〜' + csbDate(c.end_date) : '〜') : '';

        // 編集・削除（管理者のみ）。行の選択と重ならないよう、押したときは選択を止める
        var actions = CSB_CAN_EDIT
            ? '<div class="csb-actions" onclick="event.stopPropagation()">'
            +   '<button type="button" class="btn btn-sm btn-outline-primary" title="編集" onclick="csbOpenCaseForm(' + c.id + ')"><i class="bi bi-pencil"></i></button>'
            +   '<button type="button" class="btn btn-sm btn-outline-danger" title="削除" onclick="csbDeleteCase(' + c.id + ')"><i class="bi bi-trash"></i></button>'
            + '</div>'
            : '';

        html += '<div class="csb-row csb-selectable' + hidden + sel + '" data-id="' + c.id + '" onclick="csbSelectCase(' + c.id + ')">'
             +    '<div class="csb-row-main">'
             +      '<div class="csb-name">'
             +        '<span class="csb-chip csb-chip-type me-1">' + csbEsc(c.case_type) + '</span>'
             +        csbEsc(c.client_name || '（取引先未設定）')
             +      '</div>'
             +      '<div class="csb-sub">' + sub.join('') + '</div>'
             +      '<div class="csb-sub">' + csbEsc(period)
             +        (c.worker_name ? ' <span>' + csbEsc(c.worker_name) + '</span>' : '') + '</div>'
             +    '</div>'
             +    '<div class="csb-row-side">'
             +      badge
             +      '<div class="csb-sub">' + csbEsc(c.rep_name || '担当なし') + '</div>'
             +      actions
             +    '</div>'
             +  '</div>';
    });
    box.innerHTML = html;

    document.getElementById('csbCaseCount').textContent =
        csbCases.length + '件' + (csbCases.length > CSB_LIMIT && !csbCaseOpen ? '（5件を表示中）' : '');
    var more = document.getElementById('csbCaseMore');
    more.classList.toggle('d-none', csbCases.length <= CSB_LIMIT);
    more.querySelector('span').textContent = csbCaseOpen ? '5件だけ表示' : 'すべての案件を表示（全' + csbCases.length + '件）';
    more.querySelector('i').className = csbCaseOpen ? 'bi bi-chevron-up' : 'bi bi-chevron-down';
}

/** 案件を選ぶ（アサイン先になる）。営業担当のプルダウンには一切影響しない */
function csbSelectCase(id) {
    var c = csbCases.filter(function (x) { return x.id === id; })[0];
    if (!c) return;
    csbSelectedCase = (csbSelectedCase && csbSelectedCase.id === id) ? null : c;
    csbRenderCases();
    csbRenderStaff();
}

// ============================================================
// 人員側
// ============================================================
function csbSetStaffShow(s) {
    if (csbStaffShow === s) return;
    csbStaffShow = s;
    document.querySelectorAll('.csb-filter-btn[data-show]').forEach(function (b) {
        b.classList.toggle('is-active', b.dataset.show === s);
    });
    csbLoadStaff();
}

function csbLoadStaff() {
    var rep = document.getElementById('csbStaffRep').value;
    var url = CSB_API + '?side=staff'
            + (csbStaffShow === 'assigned' ? '&show=assigned' : '')
            + (rep ? '&rep=' + encodeURIComponent(rep) : '');
    fetch(url, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.ok) throw new Error('failed');
            csbStaff = d.staff || [];
            csbStaffOpen = false;
            csbRenderStaff();
        })
        .catch(function () {
            document.getElementById('csbStaffList').innerHTML =
                '<div class="csb-empty text-danger">読み込みに失敗しました</div>';
        });
}

function csbRenderStaff() {
    var box = document.getElementById('csbStaffList');
    if (!csbStaff.length) {
        box.innerHTML = '<div class="csb-empty">'
                      + (csbStaffShow === 'assigned' ? 'アサイン済みの人員はいません'
                                                     : '検討中の人員がいません')
                      + '</div>';
        document.getElementById('csbStaffCount').textContent = '0名';
        document.getElementById('csbStaffMore').classList.add('d-none');
        return;
    }
    var html = '';
    csbStaff.forEach(function (s, i) {
        var hidden = (!csbStaffOpen && i >= CSB_LIMIT) ? ' is-hidden' : '';
        var sub = [];
        if (s.affiliation)   sub.push('<span>' + csbEsc(s.affiliation) + '</span>');
        if (s.skill_type)    sub.push('<span>' + csbEsc(s.skill_type) + '</span>');
        if (s.carrier)       sub.push('<span>' + csbEsc(s.carrier) + '</span>');

        var sub2 = [];
        if (s.available_from) sub2.push('<span>' + csbDate(s.available_from) + '〜</span>');
        if (s.desired_price)  sub2.push('<span>' + Number(s.desired_price).toLocaleString() + '円</span>');

        // 編集・削除（管理者のみ）。アサイン済みの人は候補リストを直せないので出さない
        var actions = (CSB_CAN_EDIT && csbStaffShow !== 'assigned')
            ? '<div class="csb-actions">'
            +   '<button type="button" class="btn btn-sm btn-outline-primary" title="編集" onclick="csbOpenStaffForm(' + s.id + ')"><i class="bi bi-pencil"></i></button>'
            +   '<button type="button" class="btn btn-sm btn-outline-danger" title="削除" onclick="csbDeleteStaffRow(' + s.id + ')"><i class="bi bi-trash"></i></button>'
            + '</div>'
            : '';

        var side = '';
        if (csbStaffShow === 'assigned') {
            side = '<span class="csb-chip csb-chip-ok">アサイン済</span>'
                 + '<div class="csb-sub">' + csbEsc(s.assigned_client || '') + '</div>'
                 + (CSB_CAN_EDIT ? '<button type="button" class="btn btn-link btn-sm p-0 text-secondary" style="font-size:.72rem" onclick="csbUnassign(' + s.id + ')">取り消し</button>' : '');
        } else if (CSB_CAN_EDIT) {
            var can = !!csbSelectedCase;
            side = '<button type="button" class="btn btn-sm ' + (can ? 'btn-primary' : 'btn-outline-secondary')
                 + '" style="font-size:.72rem;padding:.15rem .5rem" '
                 + (can ? '' : 'disabled title="先に左の案件を選んでください" ')
                 + 'onclick="csbOpenAssign(' + s.id + ')">アサイン</button>'
                 + '<div class="csb-sub">' + csbEsc(s.rep_name || '担当なし') + '</div>'
                 + actions;
        } else {
            side = '<div class="csb-sub">' + csbEsc(s.rep_name || '担当なし') + '</div>';
        }

        html += '<div class="csb-row' + hidden + '">'
             +    '<div class="csb-row-main">'
             +      '<div class="csb-name">' + csbEsc(s.staff_name) + '</div>'
             +      '<div class="csb-sub">' + sub.join('') + '</div>'
             +      (sub2.length ? '<div class="csb-sub">' + sub2.join('') + '</div>' : '')
             +    '</div>'
             +    '<div class="csb-row-side">' + side + '</div>'
             +  '</div>';
    });
    box.innerHTML = html;

    document.getElementById('csbStaffCount').textContent =
        csbStaff.length + '名' + (csbStaff.length > CSB_LIMIT && !csbStaffOpen ? '（5名を表示中）' : '');
    var more = document.getElementById('csbStaffMore');
    more.classList.toggle('d-none', csbStaff.length <= CSB_LIMIT);
    more.querySelector('span').textContent = csbStaffOpen ? '5名だけ表示' : 'すべての人員を表示（全' + csbStaff.length + '名）';
    more.querySelector('i').className = csbStaffOpen ? 'bi bi-chevron-up' : 'bi bi-chevron-down';
}

/** すべて表示 / 5件に戻す。通信はせず、隠している行を出し入れするだけ */
function csbToggleMore(which) {
    if (which === 'case') { csbCaseOpen = !csbCaseOpen; csbRenderCases(); }
    else                  { csbStaffOpen = !csbStaffOpen; csbRenderStaff(); }
}

// ============================================================
// 人員の追加・編集
// ============================================================
function csbOpenStaffForm(id) {
    var s = id ? csbStaff.filter(function (x) { return x.id === id; })[0] : null;
    document.getElementById('csbStaffError').textContent = '';
    document.getElementById('csbStaffModalTitle').textContent = s ? '人員を編集' : '人員を追加';
    document.getElementById('csbStaffId').value       = s ? s.id : '';
    document.getElementById('csbStaffName').value     = s ? s.staff_name : '';
    document.getElementById('csbStaffRepSel').value   = s ? s.rep_name : '';
    document.getElementById('csbStaffAlliance').value = s && s.alliance_id ? s.alliance_id : '';
    document.getElementById('csbStaffAffil').value    = s && !s.alliance_id ? (s.affiliation || '') : '';
    document.getElementById('csbStaffSkill').value    = s ? (s.skill_type || '') : '';
    document.getElementById('csbStaffCarrier').value  = s ? (s.carrier || '') : '';
    document.getElementById('csbStaffPrice').value    = s && s.desired_price ? s.desired_price : '';
    document.getElementById('csbStaffFrom').value     = s ? (s.available_from || '') : '';
    document.getElementById('csbStaffNote').value     = s ? (s.note || '') : '';
    document.getElementById('csbStaffDelete').style.display = s ? '' : 'none';
    if (!csbStaffModal) csbStaffModal = new bootstrap.Modal(document.getElementById('csbStaffModal'));
    csbStaffModal.show();
}

function csbSaveStaff() {
    var fd = new FormData();
    fd.append('csrf', CSB_CSRF);
    fd.append('action', 'save_staff');
    fd.append('id',             document.getElementById('csbStaffId').value);
    fd.append('staff_name',     document.getElementById('csbStaffName').value.trim());
    fd.append('rep_name',       document.getElementById('csbStaffRepSel').value);
    fd.append('alliance_id',    document.getElementById('csbStaffAlliance').value);
    fd.append('affiliation',    document.getElementById('csbStaffAffil').value.trim());
    fd.append('skill_type',     document.getElementById('csbStaffSkill').value);
    fd.append('carrier',        document.getElementById('csbStaffCarrier').value);
    fd.append('desired_price',  document.getElementById('csbStaffPrice').value);
    fd.append('available_from', document.getElementById('csbStaffFrom').value);
    fd.append('note',           document.getElementById('csbStaffNote').value.trim());

    var btn = document.getElementById('csbStaffSave');
    btn.disabled = true;
    fetch(CSB_API, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            btn.disabled = false;
            if (!d || !d.ok) { document.getElementById('csbStaffError').textContent = (d && d.error) || '保存に失敗しました'; return; }
            csbStaffModal.hide();
            csbLoadStaff();
        })
        .catch(function () { btn.disabled = false; document.getElementById('csbStaffError').textContent = '通信エラーが発生しました'; });
}

function csbDeleteStaff() {
    csbDeleteStaffRow(document.getElementById('csbStaffId').value, true);
}

/** 一覧のゴミ箱ボタンからも、編集画面の削除ボタンからも同じ処理を使う */
function csbDeleteStaffRow(id, fromModal) {
    if (!id) return;
    var s = csbStaff.filter(function (x) { return x.id === Number(id); })[0];
    var name = s ? s.staff_name : 'この人員';
    if (!confirm(name + ' を一覧から削除しますか？\n（案件データには影響しません）')) return;
    var fd = new FormData();
    fd.append('csrf', CSB_CSRF); fd.append('action', 'delete_staff'); fd.append('id', id);
    fetch(CSB_API, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.ok) { alert((d && d.error) || '削除に失敗しました'); return; }
            if (fromModal && csbStaffModal) csbStaffModal.hide();
            csbLoadStaff();
        })
        .catch(function () { alert('通信エラーが発生しました'); });
}

// ============================================================
// アサイン
// ============================================================
function csbOpenAssign(staffId) {
    if (!csbSelectedCase) { alert('先に左の案件を選んでください'); return; }
    var s = csbStaff.filter(function (x) { return x.id === staffId; })[0];
    if (!s) return;
    var c = csbSelectedCase;
    document.getElementById('csbAssignError').textContent = '';
    document.getElementById('csbAssignStaffId').value = staffId;
    document.getElementById('csbAssignInfo').innerHTML =
        '<div class="fw-semibold">' + csbEsc(s.staff_name) + '</div>'
      + '<div class="text-muted" style="font-size:.74rem">'
      + '↓ ' + csbEsc(c.client_name || '') + '（' + csbEsc(c.case_type) + '）'
      + (c.store_name ? ' ' + csbEsc(c.store_name) : '') + '</div>';
    document.getElementById('csbAssignIn').value  = s.desired_price || 0;
    document.getElementById('csbAssignOut').value = 0;
    document.getElementById('csbAssignDaysLabel').textContent = c.case_type === '常勤' ? '稼働月数' : '稼働日数';
    document.getElementById('csbAssignDays').value = c.case_type === '常勤' ? 1 : 1;
    if (!csbAssignModal) csbAssignModal = new bootstrap.Modal(document.getElementById('csbAssignModal'));
    csbAssignModal.show();
}

function csbDoAssign() {
    var fd = new FormData();
    fd.append('csrf', CSB_CSRF);
    fd.append('action', 'assign');
    fd.append('staff_id',       document.getElementById('csbAssignStaffId').value);
    fd.append('case_id',        csbSelectedCase ? csbSelectedCase.id : '');
    fd.append('unit_price_in',  document.getElementById('csbAssignIn').value || 0);
    fd.append('unit_price_out', document.getElementById('csbAssignOut').value || 0);
    fd.append('days_worked',    document.getElementById('csbAssignDays').value || 0);

    var btn = document.getElementById('csbAssignSave');
    btn.disabled = true;
    fetch(CSB_API, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            btn.disabled = false;
            if (!d || !d.ok) { document.getElementById('csbAssignError').textContent = (d && d.error) || 'アサインに失敗しました'; return; }
            csbAssignModal.hide();
            csbLoadCases();    // 充足バッジを更新
            csbLoadStaff();    // アサインした人が検討中から消える
        })
        .catch(function () { btn.disabled = false; document.getElementById('csbAssignError').textContent = '通信エラーが発生しました'; });
}

function csbUnassign(staffId) {
    if (!confirm('アサインを取り消して検討中に戻しますか？\n（この画面から登録した案件は削除されます）')) return;
    var fd = new FormData();
    fd.append('csrf', CSB_CSRF); fd.append('action', 'unassign'); fd.append('staff_id', staffId);
    fetch(CSB_API, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.ok) { alert((d && d.error) || '取り消しに失敗しました'); return; }
            csbLoadCases(); csbLoadStaff();
        })
        .catch(function () { alert('通信エラーが発生しました'); });
}

// ============================================================
// 案件の追加（既存の案件保存APIをそのまま使う）
// ============================================================
function csbOpenCaseForm(id) {
    var f = document.getElementById('csbCaseForm');
    var c = id ? csbCases.filter(function (x) { return x.id === id; })[0] : null;
    document.getElementById('csbCaseError').textContent = '';
    f.reset();

    document.getElementById('csbCaseModalTitle').textContent = c ? '案件を編集' : '案件を追加';
    document.getElementById('csbCaseSave').textContent       = c ? '保存' : '登録';
    f.elements['id'].value = c ? c.id : '';
    document.getElementById('csbCaseEditNote').classList.toggle('d-none', !c);

    if (c) {
        document.getElementById('csbCaseType').value  = c.raw_case_type || 'regular';
        f.elements['status'].value                    = c.raw_status || 'draft';
        f.elements['recruitment_count'].value         = (c.need_count === null || c.need_count === undefined) ? '' : c.need_count;
        document.getElementById('csbCaseClient').value        = c.client_name || '';
        document.getElementById('csbCaseClientHidden').value  = c.client_name || '';
        document.getElementById('csbCaseRepSel').value = c.rep_name || '';
        f.elements['carrier'].value        = c.carrier || '';
        f.elements['trade_name'].value     = c.trade_name || '';
        f.elements['store_name'].value     = c.store_name || '';
        f.elements['worker_type'].value    = c.worker_type || '正社員';
        f.elements['case_division'].value  = c.case_division || '1次';
        f.elements['unit_price_in'].value  = c.unit_price_in || 0;
        f.elements['unit_price_out'].value = c.unit_price_out || 0;
        f.elements['notes'].value          = c.note || '';
    }
    csbCaseTypeChanged();
    // 日付の型を切り替えたあとに入れる（型が変わると値がクリアされるため）
    if (c) {
        var isRegular = (c.raw_case_type || 'regular') === 'regular';
        document.getElementById('csbCaseStart').value = isRegular ? (c.start_date || '').slice(0, 7) : (c.start_date || '');
        document.getElementById('csbCaseEnd').value   = isRegular ? (c.end_date   || '').slice(0, 7) : (c.end_date   || '');
    }

    if (!csbCaseModal) csbCaseModal = new bootstrap.Modal(document.getElementById('csbCaseModal'));
    csbCaseModal.show();
}

function csbDeleteCase(id) {
    var c = csbCases.filter(function (x) { return x.id === id; })[0];
    var name = c ? (c.client_name || 'この案件') : 'この案件';
    if (!confirm(name + ' を完全に削除します。\n取り消しはできません。よろしいですか？')) return;
    var fd = new FormData();
    fd.append('csrf', CSB_CSRF); fd.append('action', 'delete_case'); fd.append('id', id);
    fetch(CSB_API, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.ok) { alert((d && d.error) || '削除に失敗しました'); return; }
            if (csbSelectedCase && csbSelectedCase.id === id) csbSelectedCase = null;
            csbLoadCases();
            csbLoadStaff();   // アサイン済みだった人が検討中に戻ることがある
        })
        .catch(function () { alert('通信エラーが発生しました'); });
}

/** 常勤は「月」、イベントは「日」で入力する（既存の案件フォームと同じ） */
function csbCaseTypeChanged() {
    var isRegular = document.getElementById('csbCaseType').value === 'regular';
    var start = document.getElementById('csbCaseStart');
    var end   = document.getElementById('csbCaseEnd');
    start.type = isRegular ? 'month' : 'date';
    end.type   = isRegular ? 'month' : 'date';
    document.getElementById('csbCaseStartLabel').textContent = isRegular ? '開始月' : '開始日';
    document.getElementById('csbCaseEndLabel').textContent   = isRegular ? '終了月' : '終了日';
}

document.addEventListener('DOMContentLoaded', function () {
    // 取引先は入力欄の値をそのまま hidden へ（既存の案件フォームと同じ作り）
    var cl = document.getElementById('csbCaseClient');
    if (cl) cl.addEventListener('input', function () {
        document.getElementById('csbCaseClientHidden').value = this.value.trim();
    });

    var form = document.getElementById('csbCaseForm');
    if (form) form.addEventListener('submit', function (e) {
        e.preventDefault();
        document.getElementById('csbCaseError').textContent = '';
        var btn = document.getElementById('csbCaseSave');
        var editing = !!form.elements['id'].value;
        var fd = new FormData(form);

        // 新規は既存の案件登録APIをそのまま使う（常勤・イベント案件の画面と同じ経路）。
        // 編集はこの画面のAPIで、画面に無い項目を消さないよう今の値に重ねて保存する
        var url = CSB_SAVE_API;
        if (editing) { fd.set('action', 'update_case'); url = CSB_API; }

        btn.disabled = true;
        fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                btn.disabled = false;
                var ok = editing ? (d && d.ok) : (d && d.success);
                if (!ok) { document.getElementById('csbCaseError').textContent = (d && d.error) || '保存に失敗しました'; return; }
                csbCaseModal.hide();
                csbSelectedCase = null;
                csbLoadCases();
            })
            .catch(function () { btn.disabled = false; document.getElementById('csbCaseError').textContent = '通信エラーが発生しました'; });
    });

    csbLoadCases();
    csbLoadStaff();
});
CSBJS2;
require_once __DIR__ . '/../includes/footer.php';
?>
