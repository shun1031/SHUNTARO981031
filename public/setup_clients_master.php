<?php
/**
 * 【一度だけ実行するスクリプト】取引先一覧の整備（正式名称・表記名の一括登録）
 *
 * ご提供いただいた取引先・パートナー一覧（80社）＋ 正式名称が判明した既存17社
 * ＋ 商談報告にだけ存在する8社（暫定登録）＝ 合計105社を登録・更新します。
 *
 * ユーザー確認済みの取り決め:
 *   - 正式名称は一覧のまま。表記名は「株式会社」等を除いたもの
 *   - （）内の読み仮名は正式名称・表記名のどちらにも入れない
 *   - V.I.N（竹内・東郷）と ALBERA（竹内・東郷）はそれぞれ1社
 *   - 近藤SEED と SEED は同じ会社 → 正式名称・表記名ともに「SEED（近藤）」
 *   - SNAP と NAP は別会社
 *   - 58 Linoa Creation は表記名に「（Atmos）」を残す
 *   - 64 半田機工は表記名「半田機工」（石川亮次は入れない）
 *   - Laxum / LaXum / ラクサム → 「LaXum」に統一
 *   - オアシス → 正式名称「株式会社OASIS」／表記名「OASIS」
 *   - 一覧に無い既存の取引先は削除せずそのまま残す
 *
 * 使い方: 管理者でログインした状態でこのURLを開き、内容を確認して「登録を実行」を押す。
 *         実行が終わったらこのファイルを削除してください。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
if (!isAdmin()) { http_response_code(403); die('管理者のみ利用できます'); }

$db  = getDB();
$cid = getCompanyId();
if (!$cid) { die('会社が特定できません'); }

// ============================================================
// 登録する内容: [正式名称, 表記名, 出典]
// ============================================================
$TARGETS = [
    // ---- 竹内（①〜㊱）----
    ['株式会社フェローズ',            'フェローズ',            '竹内'],
    ['株式会社KTT',                   'KTT',                   '竹内'],
    ['株式会社Proud Partners',        'Proud Partners',        '竹内'],
    ['CloudAdent株式会社',            'CloudAdent',            '竹内'],
    ['株式会社LANGIS',                'LANGIS',                '竹内'],
    ['株式会社EXceed',                'EXceed',                '竹内'],
    ['株式会社魁組',                  '魁組',                  '竹内'],
    ['株式会社コンフィアンスグループ', 'コンフィアンスグループ', '竹内'],
    ['株式会社V.I.N',                 'V.I.N',                 '竹内・東郷'],
    ['株式会社T-Group',               'T-Group',               '竹内'],
    ['合同会社onetale',               'onetale',               '竹内'],
    ['渡邉拓斗',                      '渡邉拓斗',              '竹内'],
    ['株式会社Hope Village',          'Hope Village',          '竹内'],
    ['大塚',                          '大塚',                  '竹内'],
    ['polish',                        'polish',                '竹内'],
    ['株式会社Desafios',              'Desafios',              '竹内'],
    ['株式会社秀星',                  '秀星',                  '竹内'],
    ['株式会社FREEDOM',               'FREEDOM',               '竹内'],
    ['SEED（近藤）',                  'SEED（近藤）',          '竹内'],
    ['株式会社I’bis',                 'I’bis',                 '竹内'],
    ['株式会社スリーエス',            'スリーエス',            '竹内'],
    ['Attrus株式会社',                'Attrus',                '竹内'],
    ['株式会社wanny',                 'wanny',                 '竹内'],
    ['Y.W.C株式会社',                 'Y.W.C',                 '竹内'],
    ['株式会社エークラス',            'エークラス',            '竹内'],
    ['株式会社Retemper',              'Retemper',              '竹内'],
    ['株式会社ホルドブレイ',          'ホルドブレイ',          '竹内'],
    ['株式会社Free Professional',     'Free Professional',     '竹内'],
    ['株式会社ネクステラ',            'ネクステラ',            '竹内'],
    ['SUNNY株式会社',                 'SUNNY',                 '竹内'],
    ['株式会社Lecrin',                'Lecrin',                '竹内'],
    ['株式会社樹',                    '樹',                    '竹内'],
    ['株式会社LEAVES',                'LEAVES',                '竹内'],
    ['株式会社ALBERA',                'ALBERA',                '竹内・東郷'],
    ['株式会社ME',                    'ME',                    '竹内'],
    ['lotus株式会社',                 'lotus',                 '竹内'],

    // ---- 綾部（㊲〜㊿）----
    ['株式会社U-Plus',                'U-Plus',                '綾部'],
    ['株式会社AXSEED',                'AXSEED',                '綾部'],
    ['株式会社waplus',                'waplus',                '綾部'],
    ['株式会社nextassist',            'nextassist',            '綾部'],
    ['株式会社libridge',              'libridge',              '綾部'],
    ['伊藤夏哉',                      '伊藤夏哉',              '綾部'],
    ['株式会社Face to Faith',         'Face to Faith',         '綾部'],
    ['株式会社Pachira',               'Pachira',               '綾部'],
    ['株式会社GRUST',                 'GRUST',                 '綾部'],
    ['兵藤一考',                      '兵藤一考',              '綾部'],
    ['ASB株式会社',                   'ASB',                   '綾部'],
    ['スタートリンク株式会社',        'スタートリンク',        '綾部'],
    ['株式会社TIR',                   'TIR',                   '綾部'],
    ['株式会社ユウテック',            'ユウテック',            '綾部'],

    // ---- 名倉（51〜64）----
    ['株式会社CenterFlow',            'CenterFlow',            '名倉'],
    ['株式会社アナザーウェイブ',      'アナザーウェイブ',      '名倉'],
    ['株式会社MIC',                   'MIC',                   '名倉'],
    ['株式会社Thinks',                'Thinks',                '名倉'],
    ['株式会社D-MAK',                 'D-MAK',                 '名倉'],
    ['株式会社デックサポート',        'デックサポート',        '名倉'],
    ['株式会社Function',              'Function',              '名倉'],
    ['合同会社Linoa Creation（Atmos）', 'Linoa Creation（Atmos）', '名倉'],
    ['株式会社No.Limit',              'No.Limit',              '名倉'],
    ['株式会社FIRSTART',              'FIRSTART',              '名倉'],
    ['株式会社プレイミー',            'プレイミー',            '名倉'],
    ['LIBERTY株式会社',               'LIBERTY',               '名倉'],
    ['株式会社KunitokoAsset',         'KunitokoAsset',         '名倉'],
    ['有限会社半田機工',              '半田機工',              '名倉'],

    // ---- 東郷（65〜71。V.I.N・ALBERAは竹内と同一のため除く）----
    ['株式会社LIFIX',                 'LIFIX',                 '東郷'],
    ['株式会社オルフェーブル',        'オルフェーブル',        '東郷'],
    ['株式会社テレポートモバイル',    'テレポートモバイル',    '東郷'],
    ['合同会社COREN',                 'COREN',                 '東郷'],
    ['株式会社NextPlace',             'NextPlace',             '東郷'],

    // ---- 佐藤（72〜75）----
    ['株式会社SWACK',                 'SWACK',                 '佐藤'],
    ['合同会社baddass',               'baddass',               '佐藤'],
    ['合同会社UnderWill',             'UnderWill',             '佐藤'],
    ['株式会社DTC',                   'DTC',                   '佐藤'],

    // ---- 山根（76〜82）----
    ['株式会社center force',          'center force',          '山根'],
    ['株式会社wonder craft',          'wonder craft',          '山根'],
    ['株式会社NAP',                   'NAP',                   '山根'],
    ['株式会社ライクスタッフィング',  'ライクスタッフィング',  '山根'],
    ['株式会社PEACE',                 'PEACE',                 '山根'],
    ['株式会社GRACE',                 'GRACE',                 '山根'],
    ['株式会社F-tria',                'F-tria',                '山根'],

    // ---- 既存取引先で正式名称が判明したもの（17社）----
    ['株式会社プレイス',              'プレイス',              '正式名称判明'],
    ['株式会社ラネット',              'ラネット',              '正式名称判明'],
    ['ライフフレンド株式会社',        'ライフフレンド',        '正式名称判明'],
    ['株式会社OASIS',                 'OASIS',                 '正式名称判明'],
    ['株式会社テレニシ',              'テレニシ',              '正式名称判明'],
    ['株式会社ベルパーク',            'ベルパーク',            '正式名称判明'],
    ['株式会社アスカ',                'アスカ',                '正式名称判明'],
    ['株式会社オリエンス',            'オリエンス',            '正式名称判明'],
    ['株式会社humanR',                'humanR',                '正式名称判明'],
    ['株式会社MDC',                   'MDC',                   '正式名称判明'],
    ['株式会社SNAP',                  'SNAP',                  '正式名称判明'],
    ['株式会社WillAID',               'WillAID',               '正式名称判明'],
    ['株式会社LLC',                   'LLC',                   '正式名称判明'],
    ['株式会社LaXum',                 'LaXum',                 '正式名称判明'],
    ['株式会社Revive',                'Revive',                '正式名称判明'],
    ['株式会社quinx',                 'quinx',                 '正式名称判明'],
    ['株式会社ネクシア',              'ネクシア',              '正式名称判明'],

    // ---- 外注先にだけ存在する5件（取引先一覧にも登録して外注先と紐づける）----
    // 個人名は正式名称・表記名ともに同じにする（渡邉拓斗・大塚などと同じ扱い）
    ['合同会社ANTA',                  'ANTA',                  '外注先'],
    ['東峰グループ',                  '東峰グループ',          '外注先'],
    ['小林幹汰',                      '小林幹汰',              '外注先'],
    ['林航平',                        '林航平',                '外注先'],
    ['高田夢斗',                      '高田夢斗',              '外注先'],

    // ---- 商談報告にだけ存在する8社（正式名称が不明なため暫定登録。判明後に編集）----
    ['クリア',                        'クリア',                '暫定'],
    ['flueve',                        'flueve',                '暫定'],
    ['玉腰',                          '玉腰',                  '暫定'],
    ['リポン',                        'リポン',                '暫定'],
    ["T'sソリューション",             "T'sソリューション",     '暫定'],
    ['youth',                         'youth',                 '暫定'],
    ['アルティム',                    'アルティム',            '暫定'],
    ['Assh',                          'Assh',                  '暫定'],
];

// ============================================================
// 既存の取引先名 → どの正式名称に統合するかの対応表
// 自動照合（後述のキー）では結び付かない、綴りが違うものだけを列挙する
// ============================================================
$RENAME = [
    // 既存の名前            => 統合先の正式名称
    "l'bis"                  => '株式会社I’bis',
    'l’bis'                  => '株式会社I’bis',
    'ASXEED'                 => '株式会社AXSEED',
    'アスシード'             => '株式会社AXSEED',
    'Laxum'                  => '株式会社LaXum',
    'LaXum'                  => '株式会社LaXum',
    'ラクサム'               => '株式会社LaXum',
    'オアシス'               => '株式会社OASIS',
    'クラウドエージェント'   => 'CloudAdent株式会社',
    'グラスト'               => '株式会社GRUST',
    'センターフロー'         => '株式会社CenterFlow',
    'ネクストアシスト'       => '株式会社nextassist',
    'NextAssist'             => '株式会社nextassist',
    'フリーダム'             => '株式会社FREEDOM',
    'VIN'                    => '株式会社V.I.N',
    'コンフィアンス'         => '株式会社コンフィアンスグループ',
    'テレポート'             => '株式会社テレポートモバイル',
    'kunitoko asset'         => '株式会社KunitokoAsset',
    'クニトコアセット'       => '株式会社KunitokoAsset',
    'コーレン'               => '合同会社COREN',
    'ネクストプレイス'       => '株式会社NextPlace',
    'LinocaCreation'         => '合同会社Linoa Creation（Atmos）',
    '渡邊拓斗'               => '渡邉拓斗',
    'SEED'                   => 'SEED（近藤）',
    '近藤SEED'               => 'SEED（近藤）',
    'エクシード'             => '株式会社EXceed',
    'プラウドパートナー'     => '株式会社Proud Partners',
    'アトラス'               => 'Attrus株式会社',
    'ワニー'                 => '株式会社wanny',
    'YWC'                    => 'Y.W.C株式会社',
    'リテンパー'             => '株式会社Retemper',
    'フリプロ'               => '株式会社Free Professional',
    'サニー'                 => 'SUNNY株式会社',
    'レクラン'               => '株式会社Lecrin',
    'リーブス'               => '株式会社LEAVES',
    'アルベラ'               => '株式会社ALBERA',
    'loutus'                 => 'lotus株式会社',
    'Grase'                  => '株式会社GRACE',
    'ライブリッジ'           => '株式会社libridge',
    'FF'                     => '株式会社Face to Faith',
    'badass'                 => '合同会社baddass',
    'アンダーウィル'         => '合同会社UnderWill',
    'ANW（アナザーウェーブ）' => '株式会社アナザーウェイブ',
    'アナザーウェーブ'       => '株式会社アナザーウェイブ',
];

// ============================================================
// 照合キー
//   全角→半角・小文字・空白除去・法人格の除去・アポストロフィの統一 を行う。
//   「テレポート」と「テレポートモバイル」のように文字数が違うものは
//   別物として扱われるため、上の $RENAME で明示する
// ============================================================
function scmKey(string $name): string {
    $n = trim($name);
    if ($n === '') return '';
    if (function_exists('mb_convert_kana')) $n = mb_convert_kana($n, 'asKV');
    $n = mb_strtolower($n, 'UTF-8');
    // アポストロフィ・引用符の種類をそろえる
    $n = str_replace(['’', '‘', '`', '´', '＇'], "'", $n);
    // 法人格を取り除く
    $n = str_replace(['株式会社', '合同会社', '有限会社', '合資会社', '合名会社', '(株)', '㈱', '（株）'], '', $n);
    // 空白（全角・半角）を取り除く
    $n = preg_replace('/[\s\x{3000}]+/u', '', $n);
    return $n;
}

// ============================================================
// 現状の取引先を読み込む（削除済みも含める）
// ============================================================
$curStmt = $db->prepare('SELECT id, client_name, display_name, is_active FROM sales_clients WHERE company_id = ? ORDER BY id');
$curStmt->execute([$cid]);
$current = $curStmt->fetchAll(PDO::FETCH_ASSOC);

// 案件数（何件の案件がぶら下がっているか。残す会社の判断材料として表示する）
$caseCount = [];
try {
    $ccStmt = $db->prepare("SELECT client_id, COUNT(*) AS c FROM sales_cases
                            WHERE company_id = ? AND client_id IS NOT NULL AND status <> 'cancelled'
                            GROUP BY client_id");
    $ccStmt->execute([$cid]);
    foreach ($ccStmt->fetchAll(PDO::FETCH_ASSOC) as $r) $caseCount[(int)$r['client_id']] = (int)$r['c'];
} catch (PDOException $e) { /* 表示用なので失敗しても続行 */ }

// 既存を照合キーで引けるようにする（会社名・表記名の両方から引く）
$byKey = [];
foreach ($current as $c) {
    foreach ([$c['client_name'], $c['display_name']] as $n) {
        $k = scmKey((string)$n);
        if ($k !== '' && !isset($byKey[$k])) $byKey[$k] = $c;
    }
}
// $RENAME の対応表も照合キーに載せる
$renameKeyToOfficial = [];
foreach ($RENAME as $oldName => $official) {
    $k = scmKey($oldName);
    if ($k !== '') $renameKeyToOfficial[$k] = $official;
}

// ============================================================
// 1件ごとに「更新 / 新規追加 / 変更なし」を判定
// ============================================================
$plan     = [];   // 実行する内容
$warnings = [];   // 実行前に人が判断すべきこと
$usedIds  = [];   // すでに割り当てた既存レコード（1レコードが2社に割り当たるのを防ぐ）

// 表記名の重複チェック（登録リスト内）
$dispSeen = [];
foreach ($TARGETS as [$official, $display, $src]) {
    $dk = scmKey($display);
    if (isset($dispSeen[$dk])) {
        $warnings[] = ['表記名が重複', "「{$display}」（{$official}）は「{$dispSeen[$dk]}」と表記名が重複しています"];
    } else {
        $dispSeen[$dk] = $official;
    }
}

foreach ($TARGETS as [$official, $display, $src]) {
    // 統合先がこの正式名称になっている既存レコードを探す
    $match = null;
    foreach ($current as $c) {
        if (isset($usedIds[(int)$c['id']])) continue;
        foreach ([$c['client_name'], $c['display_name']] as $n) {
            $k = scmKey((string)$n);
            if ($k === '') continue;
            if (($renameKeyToOfficial[$k] ?? null) === $official) { $match = $c; break 2; }
            if ($k === scmKey($official) || $k === scmKey($display)) { $match = $c; break 2; }
        }
    }

    if ($match) {
        $usedIds[(int)$match['id']] = true;
        $sameName = trim((string)$match['client_name']) === $official;
        $sameDisp = trim((string)$match['display_name']) === $display;
        $plan[] = [
            'mode'      => ($sameName && $sameDisp && (int)$match['is_active'] === 1) ? 'nochange' : 'update',
            'id'        => (int)$match['id'],
            'official'  => $official,
            'display'   => $display,
            'src'       => $src,
            'old_name'  => (string)$match['client_name'],
            'old_disp'  => (string)$match['display_name'],
            'reactivate'=> (int)$match['is_active'] === 0,
            'cases'     => $caseCount[(int)$match['id']] ?? 0,
        ];
    } else {
        $plan[] = [
            'mode'     => 'insert',
            'id'       => 0,
            'official' => $official,
            'display'  => $display,
            'src'      => $src,
            'old_name' => '',
            'old_disp' => '',
            'reactivate' => false,
            'cases'    => 0,
        ];
    }
}

// 2つ以上の既存レコードが同じ正式名称に統合されようとしていないか
$officialToOld = [];
foreach ($current as $c) {
    foreach ([$c['client_name'], $c['display_name']] as $n) {
        $k = scmKey((string)$n);
        if ($k === '' || !isset($renameKeyToOfficial[$k])) continue;
        $officialToOld[$renameKeyToOfficial[$k]][(int)$c['id']] = (string)$c['client_name'];
    }
}
foreach ($officialToOld as $official => $olds) {
    if (count($olds) > 1) {
        $warnings[] = [
            '既存レコードの統合が必要',
            "「{$official}」に対して既存レコードが" . count($olds) . '件あります（'
            . implode(' / ', array_map(fn($id, $n) => "#{$id} {$n}", array_keys($olds), $olds))
            . '）。1件だけを更新し、残りは案件の付け替えが必要です',
        ];
    }
}

// 更新後の会社名が、更新対象以外の既存レコードとぶつからないか（DBの重複禁止に引っかかる）
$nameOwner = [];
foreach ($current as $c) { $nameOwner[trim((string)$c['client_name'])] = (int)$c['id']; }
foreach ($plan as $p) {
    if ($p['mode'] === 'nochange') continue;
    $owner = $nameOwner[$p['official']] ?? null;
    if ($owner !== null && $owner !== $p['id']) {
        $warnings[] = ['会社名がぶつかります', "「{$p['official']}」は既存レコード #{$owner} が使用中です"];
    }
}

// 今回の対象にならず、そのまま残る既存レコード
$untouched = [];
foreach ($current as $c) {
    if (isset($usedIds[(int)$c['id']])) continue;
    $untouched[] = $c + ['cases' => $caseCount[(int)$c['id']] ?? 0];
}

// 残る会社の表記名が、今回登録する表記名とぶつからないか
foreach ($untouched as $u) {
    $k = scmKey((string)$u['display_name']);
    if ($k !== '' && isset($dispSeen[$k])) {
        $warnings[] = [
            '表記名がぶつかります',
            "そのまま残る「{$u['client_name']}」（表記名 {$u['display_name']}）は、"
            . "今回登録する「{$dispSeen[$k]}」と表記名が同じです",
        ];
    }
}

$cntUpdate   = count(array_filter($plan, fn($p) => $p['mode'] === 'update'));
$cntInsert   = count(array_filter($plan, fn($p) => $p['mode'] === 'insert'));
$cntNoChange = count(array_filter($plan, fn($p) => $p['mode'] === 'nochange'));

// ============================================================
// 実行
// ============================================================
$done = false;
$msg  = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf'] ?? '')) {
    if ($warnings) {
        $msg = '確認が必要な項目が残っているため実行しませんでした。上の警告をご確認ください。';
    } else {
        try {
            $db->beginTransaction();
            $up = $db->prepare('UPDATE sales_clients
                                SET client_name = ?, display_name = ?, is_active = 1, updated_at = NOW()
                                WHERE id = ? AND company_id = ?');
            $ins = $db->prepare('INSERT INTO sales_clients
                                (company_id, client_name, display_name, is_active, sort_order)
                                VALUES (?,?,?,1,0)');
            $nUp = 0; $nIn = 0;
            foreach ($plan as $p) {
                if ($p['mode'] === 'update') {
                    $up->execute([$p['official'], $p['display'], $p['id'], $cid]);
                    $nUp++;
                } elseif ($p['mode'] === 'insert') {
                    $ins->execute([$cid, $p['official'], $p['display']]);
                    $nIn++;
                }
            }
            $db->commit();
            $msg  = "取引先を更新 {$nUp}件 / 新規追加 {$nIn}件 で登録しました。";
            $done = true;
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('[setup_clients_master] ' . $e->getMessage());
            $msg = '登録に失敗したため、変更をすべて取り消しました。' . $e->getMessage();
        }
    }
}

$pageTitle = '取引先一覧の整備';
$csrf = getCsrfToken();
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid">
    <div class="page-header">
        <h1><i class="bi bi-building-gear me-2"></i>取引先一覧の整備</h1>
        <p>正式名称と表記名を一覧のとおりに揃えます。案件データは取引先のIDで結びついているため、
           表記名を変えても過去の案件はそのまま残り、画面の表示名だけが切り替わります。</p>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $done ? 'success' : 'danger' ?>"><?= h($msg) ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="card"><div class="card-body text-center">
            <div class="text-muted small">登録リスト</div>
            <div class="fs-3 fw-bold"><?= count($TARGETS) ?></div><div class="small text-muted">社</div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body text-center">
            <div class="text-muted small">名前を更新</div>
            <div class="fs-3 fw-bold text-primary"><?= $cntUpdate ?></div><div class="small text-muted">社</div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body text-center">
            <div class="text-muted small">新規追加</div>
            <div class="fs-3 fw-bold text-success"><?= $cntInsert ?></div><div class="small text-muted">社</div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body text-center">
            <div class="text-muted small">変更なし</div>
            <div class="fs-3 fw-bold text-muted"><?= $cntNoChange ?></div><div class="small text-muted">社</div></div></div></div>
    </div>

    <?php if ($warnings): ?>
    <div class="card border-danger mb-4">
        <div class="card-header bg-danger text-white fw-bold">
            <i class="bi bi-exclamation-triangle me-1"></i>実行前に確認が必要（<?= count($warnings) ?>件）
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light"><tr><th style="width:200px">種類</th><th>内容</th></tr></thead>
                <tbody>
                <?php foreach ($warnings as [$kind, $text]): ?>
                    <tr><td class="small fw-medium text-danger"><?= h($kind) ?></td>
                        <td class="small"><?= h($text) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer small text-muted">
            この一覧が空になるまで「登録を実行」は動きません。
        </div>
    </div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header fw-bold">名前を更新する取引先（<?= $cntUpdate ?>社）</div>
        <div class="table-responsive" style="max-height:520px;overflow-y:auto">
            <table class="table table-sm table-hover mb-0" style="font-size:.82rem">
                <thead class="table-light" style="position:sticky;top:0">
                    <tr><th>今の会社名</th><th>今の表記名</th><th style="width:24px"></th>
                        <th>新・正式名称</th><th>新・表記名</th><th class="text-end">案件数</th><th>出典</th></tr>
                </thead>
                <tbody>
                <?php foreach ($plan as $p): if ($p['mode'] !== 'update') continue; ?>
                    <tr>
                        <td class="text-muted"><?= h($p['old_name']) ?></td>
                        <td class="text-muted"><?= h($p['old_disp']) ?></td>
                        <td class="text-center text-primary">→</td>
                        <td class="fw-medium"><?= h($p['official']) ?></td>
                        <td class="fw-medium text-primary"><?= h($p['display']) ?></td>
                        <td class="text-end"><?= $p['cases'] ?: '-' ?></td>
                        <td class="small text-muted">
                            <?= h($p['src']) ?>
                            <?php if ($p['reactivate']): ?>
                                <span class="badge bg-warning text-dark ms-1">削除済み→復元</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header fw-bold">新しく追加する取引先（<?= $cntInsert ?>社）</div>
        <div class="table-responsive" style="max-height:520px;overflow-y:auto">
            <table class="table table-sm table-hover mb-0" style="font-size:.82rem">
                <thead class="table-light" style="position:sticky;top:0">
                    <tr><th>正式名称</th><th>表記名</th><th>出典</th></tr>
                </thead>
                <tbody>
                <?php foreach ($plan as $p): if ($p['mode'] !== 'insert') continue; ?>
                    <tr>
                        <td class="fw-medium"><?= h($p['official']) ?></td>
                        <td class="fw-medium text-success"><?= h($p['display']) ?></td>
                        <td class="small text-muted"><?= h($p['src']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($cntNoChange): ?>
    <div class="card mb-4">
        <div class="card-header fw-bold">すでに同じ内容で登録されている取引先（<?= $cntNoChange ?>社）</div>
        <div class="table-responsive" style="max-height:320px;overflow-y:auto">
            <table class="table table-sm mb-0" style="font-size:.82rem">
                <thead class="table-light"><tr><th>正式名称</th><th>表記名</th><th class="text-end">案件数</th></tr></thead>
                <tbody>
                <?php foreach ($plan as $p): if ($p['mode'] !== 'nochange') continue; ?>
                    <tr><td><?= h($p['official']) ?></td><td><?= h($p['display']) ?></td>
                        <td class="text-end"><?= $p['cases'] ?: '-' ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header fw-bold">
            今回の対象外でそのまま残る取引先（<?= count($untouched) ?>社）
            <span class="text-muted fw-normal small ms-1">削除も変更もしません</span>
        </div>
        <div class="table-responsive" style="max-height:420px;overflow-y:auto">
            <table class="table table-sm mb-0" style="font-size:.82rem">
                <thead class="table-light" style="position:sticky;top:0">
                    <tr><th>会社名</th><th>表記名</th><th class="text-end">案件数</th><th>状態</th></tr>
                </thead>
                <tbody>
                <?php if (!$untouched): ?>
                    <tr><td colspan="4" class="text-center text-muted py-3">ありません</td></tr>
                <?php endif; ?>
                <?php foreach ($untouched as $u): ?>
                    <tr>
                        <td><?= h($u['client_name']) ?></td>
                        <td><?= h($u['display_name']) ?></td>
                        <td class="text-end"><?= $u['cases'] ?: '-' ?></td>
                        <td class="small text-muted"><?= (int)$u['is_active'] === 1 ? '登録中' : '削除済み' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer small text-muted">
            ここに見覚えのない会社や、一覧にあるはずの会社が入っていないかご確認ください。
        </div>
    </div>

    <?php if (!$done): ?>
        <form method="post" onsubmit="return confirm('更新 <?= $cntUpdate ?>社 / 新規追加 <?= $cntInsert ?>社 を登録します。よろしいですか？');">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <button type="submit" class="btn btn-primary btn-lg"
                    <?= ($warnings || ($cntUpdate + $cntInsert) === 0) ? 'disabled' : '' ?>>
                <i class="bi bi-check2-circle me-1"></i>登録を実行
            </button>
            <?php if ($warnings): ?>
                <span class="text-danger small ms-2">確認が必要な項目が残っています</span>
            <?php endif; ?>
        </form>
    <?php else: ?>
        <div class="alert alert-info mb-0">
            完了しました。取引先一覧・常勤案件一覧・イベント案件一覧・取引先別売上で表示名をご確認ください。
        </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
