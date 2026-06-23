<?php
/**
 * 2026年5月・6月 常勤案件 金額修正インポート（月額合計で再登録）
 * isAdmin のみ実行可
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
if (!isAdmin()) { http_response_code(403); die('管理者のみ'); }

$db  = getDB();
$cid = getCompanyId();
if (!$cid) die('会社IDが取得できません');

function getOrCreateClient(PDO $db, int $cid, string $name): int {
    if ($name === '') return 0;
    $s = $db->prepare('SELECT id FROM sales_clients WHERE company_id=? AND client_name=? LIMIT 1');
    $s->execute([$cid, $name]);
    if ($id = $s->fetchColumn()) return (int)$id;
    $db->prepare('INSERT INTO sales_clients (company_id, client_name) VALUES (?,?)')->execute([$cid, $name]);
    return (int)$db->lastInsertId();
}
function getOrCreateAlliance(PDO $db, int $cid, string $name): int {
    if ($name === '') return 0;
    $s = $db->prepare('SELECT id FROM sales_alliances WHERE company_id=? AND alliance_name=? LIMIT 1');
    $s->execute([$cid, $name]);
    if ($id = $s->fetchColumn()) return (int)$id;
    $db->prepare('INSERT INTO sales_alliances (company_id, alliance_name) VALUES (?,?)')->execute([$cid, $name]);
    return (int)$db->lastInsertId();
}
function mapWorkerType(string $t): string {
    return ['自社外注'=>'自社外注','外注'=>'個人外注','個人外注'=>'個人外注',
            'アライアンス'=>'アライアンス','正社員'=>'正社員','アルバイト'=>'アルバイト'][$t] ?? '正社員';
}

// 5月・6月の常勤案件のみ削除（イベントは既に正しい）
$db->prepare("DELETE FROM sales_cases WHERE company_id=? AND case_year=2026 AND case_month IN (5,6) AND case_type='regular'")->execute([$cid]);

$log = []; $errors = [];

/**
 * 常勤案件データ（月額合計を使用）
 * [月フラグ, 取引先, 営業, 管理者, リクルーター, 区分, アライアンス, スタッフ名,
 *  稼働日数, キャリア, 店舗名, 月額売上(unit_price_in), 月額原価(unit_price_out), 備考]
 *
 * 月フラグ: 5=5月のみ, 6=6月のみ, 56=両月
 */
$regular = [
// ===== Dec2025開始（5・6月共通） =====
[56,'プレイス','竹内陽','','','自社外注','','山根脩平',21,'ED','安城',450000,300000,''],
[56,'プレイス','竹内陽','','','自社外注','','後藤太一',21,'ED','安城',450000,300000,''],
[56,'プレイス','竹内陽','','','自社外注','','綾部航介',21,'SB','美濃加茂',470000,400000,''],
[56,'プレイミー','名倉雅貴','名倉雅貴','','自社外注','','日置航暉',21,'SB','半田亀崎',420000,336000,''],
[56,'LANGIS','竹内陽','','','個人外注','','鈴木拓弥',21,'SB','平針',420000,399000,''],
[56,'LANGIS','竹内陽','','鈴木真矢','個人外注','','飛田拓斗',21,'SB','AT各務原鵜沼',409500,315000,''],
[56,'プレイス','竹内陽','綾部航介','','アライアンス','ASXEED','増岡あかり',21,'ED','安城',450000,420000,''],
[56,'ラネット','竹内陽','','','アライアンス','小林幹汰','長岡拓也',21,'SB','AM豊川',525000,483000,''],
[56,'ラネット','竹内陽','竹内陽','','アライアンス','ASB','大石託実',21,'SB','AM豊川',525000,420000,''],
// ===== Jan2026開始（5・6月共通） =====
[56,'プレイス','竹内陽','綾部航介','','自社外注','','堀恭彰',21,'SB','芥見',470000,315000,''],
[56,'LLC','綾部航介','綾部航介','','アライアンス','U-plus','サトウレイジ',21,'ED','安城',462000,378000,''],
[56,'ラネット','竹内陽','','近藤航','自社外注','','清水一平',21,'SB','碧南',514500,300000,''],
[56,'プレイス','竹内陽','','鈴木真矢','自社外注','','西澤克輝',21,'ED','安城',450000,300000,''],
[56,'ライフフレンド','竹内陽','竹内陽','','アライアンス','onetale','青木大輝',1,'KS','岡崎大樹寺',100000,0,''],
// ===== Feb2026開始 =====
[5, 'プレイス','竹内陽','綾部航介','綾部航介','アライアンス','U-plus','加藤洋亮',21,'JS','小牧',450000,378000,'5/31'],
[56,'Pachira','綾部航介','綾部航介','綾部航介','アライアンス','U-plus','鬼頭優輔',21,'ED','弥富',388500,357000,''],
// ===== Apr2026開始（5・6月共通） =====
[56,'プレイス','竹内陽','','','正社員','','佐藤思杰',21,'SB','安城',470000,260000,''],
[56,'プレイス','竹内陽','','佐藤思杰','個人外注','','佐藤悠太',21,'ED','安城',450000,210000,''],
[56,'LANGIS','竹内陽','','竹内陽','個人外注','','倉地樂',21,'SB','イーアス春日井',546000,409500,''],
[56,'LANGIS','竹内陽','','竹内陽','個人外注','','竹内丈治',21,'SB','カメリアガーデン幸田',504000,378000,''],
[56,'LANGIS','竹内陽','','竹内陽','個人外注','','小栗瑞貴',21,'SB','鈴鹿',483000,362250,''],
[56,'LANGIS','竹内陽','綾部航介','','アライアンス','Pachira','柴田一心',21,'SB','アイモール三好',462000,441000,''],
[56,'LANGIS','竹内陽','','近藤航','自社外注','','丹後心来',21,'SB','野並',441000,300000,''],
[56,'クラウドエージェント','竹内陽','','','個人外注','','安山祐亮',21,'SB','ららぽーと安城',441000,336000,''],
[56,'クラウドエージェント','竹内陽','','','個人外注','','小林友裕',21,'SB','共和',441000,350000,''],
[56,'waplus','綾部航介','綾部航介','','アライアンス','東峰グループ','安樂斐悠馬',21,'SB','一宮妙興寺',441000,378000,''],
[56,'プレイス','竹内陽','','','アライアンス','T-Group','石谷悠真',21,'SB','多治見南',470000,399000,''],
[56,'プレイス','竹内陽','','','個人外注','','田中敦之',21,'SB','碧南',470000,352500,''],
[56,'プレイス','竹内陽','','','自社外注','','山内文月',21,'ED','安城',450000,336000,''],
[56,'プレイス','竹内陽','','','アライアンス','U-plus','長井優斗',21,'SB','TG',492000,420000,''],
// ===== May2026開始 =====
[56,'プレイス','竹内陽','','','自社外注','','押野俊太郎',21,'SB','名古屋サンロード',470000,290000,''],
[56,'プレイス','竹内陽','','','自社外注','','東郷光啓',21,'SB','GA知立',470000,290000,''],
[56,'プレイス','竹内陽','','','自社外注','','竹内陽',21,'SB','知立',470000,500000,''],
[5, 'プレイス','竹内陽','','','自社外注','','名倉雅貴',21,'SB','安城住吉',470000,300000,'5/31'],
// ===== Jun2026開始（6月のみ） =====
[6, 'LANGIS','竹内陽','綾部航介','','アライアンス','合同会社ANTA','平手達也',21,'SB','四軒家',441000,378000,''],
[6, 'プレイス','竹内陽','竹内陽','','アライアンス','LANGIS','板倉久美子',21,'SB','安城住吉',470000,441000,''],
[6, 'プレイス','竹内陽','綾部航介','','アライアンス','U-plus','加藤洋亮',21,'SB','森山幸心',470000,378000,''],
[6, 'プレイス','竹内陽','','','自社外注','','名倉雅貴',21,'SB','TG',492000,344400,''],
];

foreach ($regular as $r) {
    [$months, $client, $sales_rep, $manager, $recruiter, $wtype, $alliance, $worker,
     $days, $carrier, $store, $price_in, $price_out, $note] = $r;

    $months_list = ($months===56) ? [5,6] : [$months];
    $client_id   = getOrCreateClient($db, $cid, $client);
    $alliance_id = getOrCreateAlliance($db, $cid, $alliance);
    $wt          = mapWorkerType($wtype);
    $gp_direct   = $price_in - $price_out;

    foreach ($months_list as $m) {
        $start = "2026-0{$m}-01";
        try {
            $db->prepare("INSERT INTO sales_cases
                (company_id, case_type, case_year, case_month,
                 client_id, sales_rep, manager, recruiter,
                 worker_type, alliance_id, worker_name,
                 start_date, unit_price_in, unit_price_out, days_worked,
                 revenue, cost, gross_profit, margin, status, note,
                 carrier, store_name)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([
                $cid,'regular',2026,$m,
                $client_id,$sales_rep,$manager,$recruiter,
                $wt,$alliance_id ?: null,$worker,
                $start,$price_in,$price_out,$days,
                $price_in,$price_out,$gp_direct,
                $price_in > 0 ? round($gp_direct/$price_in,4) : 0,
                'confirmed',$note,$carrier,$store
            ]);
            $log[] = "✓ 常勤{$m}月 {$worker} {$store} 売上".number_format($price_in);
        } catch (PDOException $e) {
            $errors[] = "✗ 常勤{$m}月 {$worker}: ".$e->getMessage();
        }
    }
}
?><!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><title>Fix Import</title>
<style>body{font-family:monospace;padding:20px;background:#1e1e2e;color:#cdd6f4}
.ok{color:#a6e3a1}.err{color:#f38ba8}h1{color:#89b4fa}h2{color:#fab387}
</style></head><body>
<h1>2026年5月・6月 常勤案件 金額修正完了</h1>
<p>常勤: <span class="ok"><?= count($log) ?>件 成功</span> / <span class="err"><?= count($errors) ?>件 エラー</span></p>
<?php if($errors):?><h2>エラー</h2><?php foreach($errors as $e):?><p class="err"><?=h($e)?></p><?php endforeach;?><?php endif;?>
<h2>成功ログ</h2>
<?php foreach($log as $l):?><p class="ok"><?=h($l)?></p><?php endforeach;?>
<p style="margin-top:2em;color:#f38ba8;font-weight:bold">⚠️ 実行後このファイルを削除してください</p>
<p><a href="<?=BASE_PATH?>/public/sales_regular.php?year=2026&month=5" style="color:#89b4fa">常勤案件5月へ</a></p>
</body></html>
