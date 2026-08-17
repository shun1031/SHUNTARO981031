<?php
/**
 * 【一度だけ実行するスクリプト】2026年1月 イベント案件 データ投入
 *
 * スプレッドシートの「2026年1月イベント稼働分」85件をイベント案件として登録します。
 *
 * 使い方: 管理者でログインした状態でこのURLを開き、内容を確認して「登録を実行」を押す。
 *         実行が終わったらこのファイルを削除してください。
 *
 * 常勤との違い:
 *   - 金額は 日単価 × 稼働日数 でシステムが自動計算する（gross_profit_direct は使わない）。
 *   - 開始日・終了日は日付そのまま。開始日〜終了日の日数＝稼働日数も全行一致。
 *   - イベント案件に光ADの項目は無い。
 *   - 同じスタッフが月内に何度も稼働するため、二重登録チェックは「スタッフ名＋開始日」で行う。
 *
 * 1月分の入力ミスの修正（ユーザー確認済み）:
 *   - 佐藤思杰（1/1・GA知立）: 元データの原価10,000は誤りで、支払単価12,000×1日＝12,000が正しい。
 *     システムが日単価から計算するため、正しい12,000で登録される。
 *     この1件のぶん、元データの合計より原価が+2,000／粗利が-2,000になる。
 *   - 稲田勇人（1/10〜12）: アライアンスなのに外注先が空だったので「渡邊拓斗」を補う。
 *   - 岩田宗士（1/3〜4）: スタッフ区分・外注先とも「アルバイト」だったので
 *     「アライアンス＋オアシス」にする（同じ人の他の行と揃える）。
 *   - スタッフ名が店舗名になっている1件（刈谷ハイウェイ／外注先=林航平）は元データのまま登録する。
 *
 * 名前の表記ゆれ（既存の登録に合わせる。碧山は別人なので統一しない）:
 *   - 金子慶太→金子、渡邉政幸→渡部政幸、山﨑慈英→山崎慈英、安田祐希→安田裕希
 *
 * 常勤・9-12月分と同じ扱い:
 *   - 管理者・採用者の「該当者なし」は空欄にして直営業扱いにする。
 *   - 区分は全件「2次以降」。
 *   - コード列はキャリアの選択肢にある SB のみキャリアへ、ED・JS は屋号へ。空欄はそのまま。
 *   - 店舗名の先頭の屋号を分ける（アピタ安城南→AT＋安城南）。AM・GA は分けない。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
if (!isAdmin()) { http_response_code(403); die('管理者のみ利用できます'); }

$db    = getDB();
$cid   = getCompanyId();
$csrf  = getCsrfToken();
$YEAR  = 2026;
$MONTH = 1;

// 列: 0取引先 1営業担当 2管理者 3採用者 4スタッフ区分 5外注先 6スタッフ名
//     7開始日 8終了日 9キャリア 10店舗名 11請求単価(日) 12支払単価(日) 13稼働日数 14屋号
$DATA = [
    ['ラネット','竹内陽','竹内陽','竹内陽','アライアンス','渡邊拓斗','小木曽太一','2026-01-01','2026-01-05','','GA知立',35000,29750,5,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','渡邊拓斗','高橋翔','2026-01-01','2026-01-02','SB','GA知立',35000,30000,2,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','渡邊拓斗','高橋翔','2026-01-06','2026-01-06','SB','GA知立',35000,30000,1,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','渡邊拓斗','高橋翔','2026-01-10','2026-01-12','SB','GA知立',35000,30000,3,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','渡邊拓斗','高橋翔','2026-01-31','2026-01-31','SB','GA知立',35000,30000,1,''],
    ['ラネット','竹内陽','','','自社外注','','東郷光啓','2026-01-01','2026-01-01','SB','GA知立',35000,12000,1,''],
    ['センターフロー','竹内陽','','','自社外注','','東郷光啓','2026-01-04','2026-01-04','','ららぽーと愛知東郷',32000,0,1,'ED'],
    ['ラネット','竹内陽','','','正社員','','佐藤思杰','2026-01-01','2026-01-01','SB','GA知立',35000,12000,1,''],
    ['ラネット','竹内陽','','','自社外注','','山根脩平','2026-01-01','2026-01-01','SB','GA知立',35000,12000,1,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','渡邊拓斗','小川洸太','2026-01-02','2026-01-02','SB','GA知立',35000,30000,1,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','渡邊拓斗','小川洸太','2026-01-05','2026-01-06','SB','GA知立',35000,30000,2,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','渡邊拓斗','斎藤源','2026-01-02','2026-01-02','SB','GA知立',35000,30000,1,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','渡邊拓斗','石原綾人','2026-01-02','2026-01-02','SB','GA知立',30000,25500,1,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','渡邊拓斗','渡邉拓斗','2026-01-03','2026-01-06','SB','GA知立',35000,30000,4,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','渡邊拓斗','鳩崎凌太','2026-01-03','2026-01-04','SB','GA知立',35000,30000,2,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','渡邊拓斗','鳩崎凌太','2026-01-18','2026-01-18','SB','GA知立',35000,30000,1,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','渡邊拓斗','森晴哉','2026-01-03','2026-01-03','SB','GA知立',35000,30000,1,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','渡邊拓斗','大町北斗','2026-01-04','2026-01-04','SB','GA知立',35000,30000,1,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','渡邊拓斗','大町北斗','2026-01-18','2026-01-18','SB','GA知立',35000,30000,1,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','渡邊拓斗','船橋透志','2026-01-04','2026-01-04','SB','GA知立',30000,25500,1,''],
    ['ラネット','竹内陽','竹内陽','','自社外注','','金子','2026-01-05','2026-01-05','SB','GA知立',35000,30000,1,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','渡邊拓斗','金子','2026-01-10','2026-01-12','SB','GA知立',35000,30000,3,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','渡邊拓斗','野口友希','2026-01-05','2026-01-05','SB','GA知立',30000,25500,1,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','渡邊拓斗','森英章','2026-01-06','2026-01-06','SB','GA知立',30000,25500,1,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','渡邊拓斗','森英章','2026-01-17','2026-01-18','SB','GA知立',30000,25500,2,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','渡邊拓斗','森英章','2026-01-24','2026-01-25','SB','GA知立',30000,25500,2,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','渡邊拓斗','森英章','2026-01-31','2026-01-31','SB','GA知立',30000,25500,1,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','オアシス','内藤聖稀','2026-01-10','2026-01-12','','メガトラ大府',35000,34000,3,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','オアシス','内藤聖稀','2026-01-16','2026-01-16','','メガトラ大府',35000,34000,1,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','オアシス','内藤聖稀','2026-01-19','2026-01-19','','安城南',35000,34000,1,'AT'],
    ['ラネット','竹内陽','竹内陽','','アライアンス','オアシス','内藤聖稀','2026-01-22','2026-01-23','','ドンキ碧南',35000,34000,2,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','オアシス','内藤聖稀','2026-01-26','2026-01-26','','ドンキ碧南',35000,34000,1,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','オアシス','牛島捷介','2026-01-10','2026-01-12','','メガトラ大府',35000,30000,3,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','オアシス','牛島捷介','2026-01-17','2026-01-18','','刈谷ハイウェイ',35000,30000,2,''],
    ['ベルパーク','竹内陽','竹内陽','','アライアンス','オアシス','牛島捷介','2026-01-24','2026-01-25','','トナリエ四日市',35000,30000,2,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','オアシス','辻村杏慈','2026-01-16','2026-01-16','','安城南',35000,27000,1,'AT'],
    ['ラネット','竹内陽','竹内陽','','アライアンス','オアシス','北岡空駆','2026-01-19','2026-01-19','','安城南',35000,27000,1,'AT'],
    ['ラネット','竹内陽','東郷光啓','','アライアンス','LIFIX','堀山時和','2026-01-17','2026-01-18','','安城南',35000,27000,2,'AT'],
    ['ラネット','竹内陽','東郷光啓','','アライアンス','LIFIX','真田秀大','2026-01-17','2026-01-18','','安城南',35000,27000,2,'AT'],
    ['ラネット','竹内陽','東郷光啓','','アライアンス','LIFIX','馬場皇誓','2026-01-17','2026-01-18','','安城南',35000,27000,2,'AT'],
    ['ベルパーク','竹内陽','竹内陽','','アライアンス','オアシス','岩田宗士','2026-01-03','2026-01-04','','トナリエ四日市',35000,30000,2,''],
    ['ベルパーク','竹内陽','竹内陽','','アライアンス','オアシス','岩田宗士','2026-01-10','2026-01-12','','トナリエ四日市',35000,30000,3,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','オアシス','岩田宗士','2026-01-17','2026-01-18','','刈谷ハイウェイ',35000,30000,2,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','オアシス','岩田宗士','2026-01-22','2026-01-26','','ドンキ碧南',35000,30000,5,''],
    ['ベルパーク','竹内陽','竹内陽','','アライアンス','オアシス','安田裕希','2026-01-17','2026-01-18','','トナリエ四日市',35000,25000,2,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','オアシス','安田裕希','2026-01-31','2026-01-31','','トナリエ四日市',35000,27000,1,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','オアシス','渡部政幸','2026-01-26','2026-01-26','','ドンキ碧南',35000,27000,1,''],
    ['ベルパーク','竹内陽','竹内陽','','個人外注','','林航平','2026-01-03','2026-01-04','','トナリエ四日市',35000,0,2,''],
    ['ベルパーク','竹内陽','竹内陽','','個人外注','','林航平','2026-01-10','2026-01-12','','トナリエ四日市',35000,0,3,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','林航平','刈谷ハイウェイ','2026-01-17','2026-01-18','','刈谷ハイウェイ',35000,25000,2,''],
    ['ベルパーク','竹内陽','竹内陽','','個人外注','','林航平','2026-01-24','2026-01-25','','トナリエ四日市',35000,0,2,''],
    ['ベルパーク','竹内陽','竹内陽','','個人外注','','林航平','2026-01-31','2026-01-31','','トナリエ四日市',35000,0,1,''],
    ['ベルパーク','竹内陽','竹内陽','','アライアンス','オアシス','矢野太雅','2026-01-17','2026-01-18','','トナリエ四日市',35000,27000,2,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','渡邊拓斗','稲田勇人','2026-01-10','2026-01-12','','GA知立',35000,30000,3,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','渡邊拓斗','稲田勇人','2026-01-31','2026-01-31','','GA知立',35000,30000,1,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','渡邊拓斗','山本蓮真','2026-01-10','2026-01-10','','GA知立',30000,25500,1,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','渡邊拓斗','山本蓮真','2026-01-12','2026-01-12','','GA知立',30000,25500,1,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','渡邊拓斗','山本蓮真','2026-01-17','2026-01-17','','GA知立',30000,25500,1,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','渡邊拓斗','碧山圭夏','2026-01-11','2026-01-11','','GA知立',15000,12750,1,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','渡邊拓斗','碧山圭夏','2026-01-17','2026-01-17','','GA知立',35000,30000,1,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','渡邊拓斗','碧山圭夏','2026-01-24','2026-01-25','','GA知立',35000,30000,2,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','渡邊拓斗','碧山圭夏','2026-01-31','2026-01-31','','GA知立',35000,30000,1,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','渡邊拓斗','沢崎寧央','2026-01-17','2026-01-18','','GA知立',35000,30000,2,''],
    ['センターフロー','竹内陽','','','正社員','','西山恭平','2026-01-03','2026-01-04','','大樹寺',32000,0,2,'ED'],
    ['センターフロー','竹内陽','','','正社員','','香川世舟','2026-01-01','2026-01-01','','AM木曽川',30000,0,1,'JS'],
    ['センターフロー','竹内陽','','','正社員','','香川世舟','2026-01-02','2026-01-02','','ららぽーと愛知東郷',30000,0,1,'ED'],
    ['センターフロー','竹内陽','','','正社員','','廣田晴哉','2026-01-01','2026-01-01','','モレラ岐阜',32000,0,1,'ED'],
    ['センターフロー','竹内陽','','','正社員','','廣田晴哉','2026-01-02','2026-01-04','','ららぽーと愛知東郷',32000,0,3,'ED'],
    ['センターフロー','竹内陽','','','自社外注','','山崎慈英','2026-01-01','2026-01-01','','モレラ岐阜',32000,0,1,'ED'],
    ['センターフロー','竹内陽','','','自社外注','','山崎慈英','2026-01-02','2026-01-04','','大樹寺',32000,0,3,'ED'],
    ['センターフロー','竹内陽','','','自社外注','','上床篤志','2026-01-01','2026-01-01','','モレラ岐阜',32000,0,1,'ED'],
    ['センターフロー','竹内陽','','','自社外注','','上床篤志','2026-01-02','2026-01-03','','ららぽーと愛知東郷',32000,0,2,'ED'],
    ['ASXEED','綾部航介','','','正社員','','近藤航','2026-01-01','2026-01-01','','ビックカメラ駅西',28000,0,1,''],
    ['センターフロー','竹内陽','','','正社員','','上村陸斗','2026-01-02','2026-01-02','','岡崎',30000,0,1,'ED'],
    ['センターフロー','竹内陽','','','正社員','','上村陸斗','2026-01-03','2026-01-04','','大樹寺',30000,0,2,'ED'],
    ['センターフロー','竹内陽','','','自社外注','','森岡裕貴','2026-01-02','2026-01-04','','岡崎',32000,0,3,'ED'],
    ['ラネット','竹内陽','竹内陽','','アライアンス','渡邊拓斗','足立空明','2026-01-24','2026-01-25','','GA知立',35000,30000,2,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','渡邊拓斗','小木曽太一','2026-01-24','2026-01-25','','GA知立',35000,30000,2,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','オアシス','寺本飛悠','2026-01-24','2026-01-25','','ドンキ碧南',35000,25000,2,''],
    ['テレニシ','竹内陽','竹内陽','','アライアンス','渡邊拓斗','渡邉拓斗','2026-01-26','2026-01-30','SB','モレラ岐阜',40000,30000,5,''],
    ['テレニシ','竹内陽','竹内陽','','アライアンス','渡邊拓斗','笛吹佳暉','2026-01-26','2026-01-30','SB','モレラ岐阜',35000,30000,5,''],
    ['テレニシ','竹内陽','竹内陽','竹内陽','アライアンス','渡邊拓斗','小川洸太','2026-01-26','2026-01-26','SB','モレラ岐阜',35000,30000,1,''],
    ['テレニシ','竹内陽','竹内陽','','アライアンス','渡邊拓斗','加藤蘭丸','2026-01-27','2026-01-28','SB','モレラ岐阜',35000,30000,2,''],
    ['テレニシ','竹内陽','竹内陽','','アライアンス','渡邊拓斗','小川洸太','2026-01-29','2026-01-30','SB','モレラ岐阜',35000,30000,2,''],
    ['ベルパーク','竹内陽','竹内陽','','アライアンス','オアシス','安田裕希','2026-01-31','2026-01-31','','トナリエ四日市',35000,25000,1,''],
];

// ── 既に当月に登録されているイベント案件（二重登録チェック用） ──
$exStmt = $db->prepare("SELECT sc.worker_name, sc.start_date, cl.client_name
    FROM sales_cases sc LEFT JOIN sales_clients cl ON sc.client_id = cl.id
    WHERE sc.company_id=? AND sc.case_year=? AND sc.case_month=? AND sc.case_type='event' AND sc.status!='cancelled'
    ORDER BY sc.id");
$exStmt->execute([$cid, $YEAR, $MONTH]);
$existing = $exStmt->fetchAll();
$existingNames = [];
// イベントは同じ人が月内に何度も稼働するため「スタッフ名＋開始日」で重複を見る
foreach ($existing as $e) { $existingNames[trim($e['worker_name']) . '|' . substr((string)$e['start_date'], 0, 10)] = true; }

// ── マスタ ──
// 無効化済みのマスタも含めて探す（第2引数 false）。
// 既定の getSalesClients($cid) は有効なものしか返さないため、
// 一度無効にした取引先・外注先を見落として同じ名前をもう1件作ってしまう。
$clientMap = [];
foreach (getSalesClients($cid, false) as $c) { $clientMap[trim($c['client_name'])] = (int)$c['id']; }
$allianceMap = [];
foreach (getSalesAlliances($cid, false) as $a) { $allianceMap[trim($a['alliance_name'])] = (int)$a['id']; }

// ── 担当者名の名簿チェック（営業マンIDと突き合わせて表記ゆれを洗い出す） ──
// 営業担当・管理者は「社員一覧で営業担当にチェックが入っている人」でないと
// イベント案件フォームのプルダウンに出てこないため、ここで事前に確認できるようにする。
$roles = [];   // 名前 => 役割の一覧
// 「直営業」は社員ではなく担当者なしを表す集計用の値なので、名簿チェックの対象外にする
$notPerson = ['直営業' => true, '該当者なし' => true];
foreach ($DATA as $d) {
    if ($d[1] !== '' && !isset($notPerson[$d[1]])) { $roles[$d[1]]['営業担当'] = true; }
    if ($d[2] !== '' && !isset($notPerson[$d[2]])) { $roles[$d[2]]['管理者']   = true; }
    if ($d[3] !== '' && !isset($notPerson[$d[3]])) { $roles[$d[3]]['採用者']   = true; }
}
$rosterRows = [];
foreach (array_keys($roles) as $personName) {
    $row = ['name' => $personName, 'roles' => implode('・', array_keys($roles[$personName])),
            'hits' => 0, 'active' => null, 'emp_type' => null, 'rep_flag' => null, 'id' => null];
    try {
        $rs = $db->prepare('SELECT id, is_active, employment_type, sales_rep_flag
                            FROM employees WHERE company_id = ? AND name = ?');
        $rs->execute([$cid, $personName]);
        $hits = $rs->fetchAll();
        $row['hits'] = count($hits);
        if (count($hits) === 1) {
            $row['id']       = (int)$hits[0]['id'];
            $row['active']   = (int)$hits[0]['is_active'];
            $row['emp_type'] = $hits[0]['employment_type'];
            $row['rep_flag'] = (int)$hits[0]['sales_rep_flag'];
        }
    } catch (PDOException $e) {
        error_log('[import_event_202601 roster] ' . $e->getMessage());
    }
    $rosterRows[] = $row;
}

$done = false; $created = 0; $skipped = []; $failed = []; $newClients = []; $newAlliances = [];

// ── 登録実行 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf'] ?? '')) {
    $includeDup = !empty($_POST['include_dup']);
    foreach ($DATA as $d) {
        [$clientName, $salesRep, $manager, $recruiter, $workerType, $allianceName,
         $workerName, $startDate, $endDate, $carrier, $storeName, $priceIn, $priceOut, $days,
         $tradeName] = $d;
        if (!$includeDup && isset($existingNames[$workerName . '|' . $startDate])) {
            $skipped[] = $workerName . '（' . $startDate . '）';
            continue;
        }
        try {
            $clientId = $clientMap[$clientName] ?? null;
            if (!$clientId && $clientName !== '') {
                $clientId = createSalesClient($cid, ['client_name' => $clientName]);
                $clientMap[$clientName] = $clientId;
                $newClients[] = $clientName;
            }
            $allianceId = null;
            if ($workerType === 'アライアンス' && $allianceName !== '') {
                $allianceId = $allianceMap[$allianceName] ?? null;
                if (!$allianceId) {
                    $allianceId = createSalesAlliance($cid, ['alliance_name' => $allianceName]);
                    $allianceMap[$allianceName] = $allianceId;
                    $newAlliances[] = $allianceName;
                }
            }
            createSalesCase($cid, [
                'case_type'           => 'event',
                'client_id'           => $clientId,
                'start_date'          => $startDate,
                'end_date'            => $endDate,
                'sales_rep'           => $salesRep,
                'manager'             => $manager,
                'recruiter'           => $recruiter,
                'worker_type'         => $workerType,
                'worker_name'         => $workerName,
                'alliance_id'         => $allianceId ?: null,
                'carrier'             => $carrier,
                'trade_name'          => $tradeName,
                'area_id'             => null,
                'store_name'          => $storeName,
                'unit_price_in'       => $priceIn,
                'unit_price_out'      => $priceOut,
                'days_worked'         => $days,
                'status'              => 'confirmed',
                'note'                => '',
                'case_division'       => '2次以降',
                'budget_division'     => null,
                // 通常のフォーム保存と同じように、担当者・稼働スタッフの社員IDも入れる
                // （名簿に一意に一致する名前だけ。一致しない場合はNULLのまま）
                'sales_rep_id'        => resolveEmployeeIdByName($cid, $salesRep),
                'manager_id'          => resolveEmployeeIdByName($cid, $manager),
                'recruiter_id'        => resolveEmployeeIdByName($cid, $recruiter),
                'worker_employee_id'  => resolveEmployeeIdByName($cid, $workerName),
            ]);
            $created++;
        } catch (Throwable $e) {
            $failed[] = $workerName . '（' . $startDate . '） — ' . $e->getMessage();
        }
    }
    $done = true;
}

// ── 確認用の集計 ──
$dupRows = 0; $totalIn = 0; $totalOut = 0; $totalDays = 0;
foreach ($DATA as $d) {
    if (isset($existingNames[$d[6] . '|' . $d[7]])) $dupRows++;
    $totalIn   += $d[11] * $d[13];   // 日単価 × 稼働日数
    $totalOut  += $d[12] * $d[13];
    $totalDays += $d[13];
}
// 新規作成になるマスタ
$willCreateClients = [];
$willCreateAlliances = [];
foreach ($DATA as $d) {
    if ($d[0] !== '' && !isset($clientMap[$d[0]]))   { $willCreateClients[$d[0]] = true; }
    if ($d[4] === 'アライアンス' && $d[5] !== '' && !isset($allianceMap[$d[5]])) { $willCreateAlliances[$d[5]] = true; }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $YEAR ?>年<?= $MONTH ?>月 イベント案件 データ投入</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css" rel="stylesheet">
<style>
body { background:#f8f9fa; font-family:'Hiragino Kaku Gothic ProN','Noto Sans JP',sans-serif; }
.wrap { max-width:1220px; margin:0 auto; padding:24px 16px 60px; }
td, th { font-size:.76rem; white-space:nowrap; }
</style>
</head>
<body>
<div class="wrap">

<h4 class="fw-bold mb-3"><i class="bi bi-calendar-event me-2"></i><?= $YEAR ?>年<?= $MONTH ?>月 イベント案件 データ投入</h4>

<?php if ($done): ?>

  <div class="alert alert-success">
    <div class="fw-bold"><i class="bi bi-check-circle me-1"></i><?= $created ?>件を登録しました</div>
    <?php if ($newClients): ?><div class="small mt-1">取引先を新規作成: <?= h(implode('、', array_unique($newClients))) ?></div><?php endif; ?>
    <?php if ($newAlliances): ?><div class="small">外注先を新規作成: <?= h(implode('、', array_unique($newAlliances))) ?></div><?php endif; ?>
  </div>

  <?php if ($skipped): ?>
  <div class="alert alert-secondary small">
    <strong><?= count($skipped) ?>件はスキップしました</strong>（同じスタッフ名が既に<?= $MONTH ?>月に登録されていたため）
    <div class="mt-1"><?= h(implode('、', $skipped)) ?></div>
  </div>
  <?php endif; ?>

  <?php if ($failed): ?>
  <div class="alert alert-danger small">
    <strong><?= count($failed) ?>件は登録できませんでした</strong>
    <ul class="mb-0 mt-1"><?php foreach ($failed as $f): ?><li><?= h($f) ?></li><?php endforeach; ?></ul>
  </div>
  <?php endif; ?>

  <a href="<?= BASE_PATH ?>/public/sales_regular.php?year=<?= $YEAR ?>&month=<?= $MONTH ?>" class="btn btn-primary">
    イベント案件（<?= $YEAR ?>年<?= $MONTH ?>月）を確認する
  </a>
  <div class="alert alert-warning mt-3 mb-0 small">
    <i class="bi bi-exclamation-triangle me-1"></i>登録が終わったら、このページ（import_event_202601.php）は削除してください。もう一度開いて実行すると二重登録の原因になります。
  </div>

<?php else: ?>

  <div class="card mb-3"><div class="card-body">
    <div class="fw-semibold mb-2">
      いま <?= $YEAR ?>年<?= $MONTH ?>月 に登録されているイベント案件：
      <span class="badge bg-secondary"><?= count($existing) ?>件</span>
    </div>
    <?php if ($existing): ?>
      <div class="small text-muted" style="line-height:2">
        <?php foreach ($existing as $e): ?>
          <span class="badge bg-light text-dark border me-1"><?= h($e['worker_name']) ?> <?= h(substr((string)$e['start_date'], 0, 10)) ?></span>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="small text-muted">まだ登録されていません。</div>
    <?php endif; ?>
  </div></div>

  <?php if ($dupRows): ?>
  <div class="alert alert-warning">
    <div class="fw-bold"><i class="bi bi-exclamation-triangle me-1"></i>
      これから登録する<?= count($DATA) ?>件のうち<?= $dupRows ?>件は、同じ「スタッフ名＋開始日」が既に<?= $MONTH ?>月に登録されています
    </div>
    <div class="small mt-1">
      二重登録を防ぐため、既定では<strong>その<?= $dupRows ?>件をスキップ</strong>し、
      <strong><?= count($DATA) - $dupRows ?>件</strong>だけ登録します。
    </div>
  </div>
  <?php endif; ?>

  <!-- 担当者名の名簿チェック -->
  <div class="card mb-3"><div class="card-body">
    <div class="fw-semibold mb-2"><i class="bi bi-person-badge me-1"></i>営業担当・管理者・採用者の名前が名簿と合っているか</div>
    <div class="small text-muted mb-2">
      「営業担当チェック」が入っていない人は、イベント案件フォームの<strong>営業担当・管理者のプルダウンに出てきません</strong>。
      このスクリプトは名前をそのまま登録できますが、後から画面で編集するときに選び直せなくなるため、
      ✗ が出た人は先に社員一覧でチェックを入れることをおすすめします。
    </div>
    <div class="table-responsive">
    <table class="table table-sm bg-white mb-0">
      <thead class="table-light"><tr>
        <th>名前</th><th>この月での役割</th><th>名簿</th><th>在籍</th><th>雇用区分</th><th>営業担当チェック</th><th>社員ID</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rosterRows as $r):
        $needsRepFlag = strpos($r['roles'], '営業担当') !== false || strpos($r['roles'], '管理者') !== false;
        $ng = $r['hits'] !== 1 || ($needsRepFlag && $r['rep_flag'] !== 1) || $r['active'] === 0;
      ?>
        <tr class="<?= $ng ? 'table-warning' : '' ?>">
          <td class="fw-medium"><?= h($r['name']) ?></td>
          <td><?= h($r['roles']) ?></td>
          <td><?php if ($r['hits'] === 1): ?><span class="text-success">✓ あり</span>
              <?php elseif ($r['hits'] === 0): ?><span class="text-danger fw-bold">✗ 名簿に無い</span>
              <?php else: ?><span class="text-danger fw-bold">✗ 同姓同名 <?= (int)$r['hits'] ?>人</span><?php endif; ?></td>
          <td><?= $r['active'] === null ? '-' : ($r['active'] ? '在籍' : '<span class="text-danger">退職</span>') ?></td>
          <td><?= h($r['emp_type'] ?? '-') ?></td>
          <td><?php if (!$needsRepFlag): ?><span class="text-muted">不要</span>
              <?php elseif ($r['rep_flag'] === 1): ?><span class="text-success">✓</span>
              <?php else: ?><span class="text-danger fw-bold">✗ 未チェック</span><?php endif; ?></td>
          <td class="text-muted"><?= $r['id'] ?? '-' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div></div>

  <div class="alert alert-info">
    <div class="fw-semibold">登録する内容</div>
    <div class="small mt-1" style="line-height:1.9">
      ・<?= count($DATA) ?>件を <strong><?= $YEAR ?>年<?= $MONTH ?>月</strong> のイベント案件として登録（ステータス=確定）<br>
      ・売上合計 <strong><?= number_format($totalIn) ?>円</strong> ／ 原価合計 <strong><?= number_format($totalOut) ?>円</strong> ／ 粗利合計 <strong><?= number_format($totalIn - $totalOut) ?>円</strong> ／ 延べ稼働 <strong><?= number_format($totalDays) ?>日</strong><br>
      ・金額は <strong>日単価 × 稼働日数</strong> でシステムが自動計算します（元データの売上・原価・粗利・粗利率と全<?= count($DATA) ?>行で一致することを確認済み）<br>
      ・開始日〜終了日は<strong>日付のまま</strong>登録します（元データの期間の日数＝稼働日数も全行一致）<br>
      ・コード列は <strong>SB</strong> のみキャリアへ、<strong>ED・JS は屋号</strong>へ（キャリアの選択肢に無いため）。空欄の行はどちらも空欄<br>
      ・店舗名の先頭に屋号が付いているものは<strong>屋号と店舗名に分けて登録</strong>（アピタ安城南→屋号AT＋店舗安城南）。AM・GA は分けません<br>
      ・区分は全件<strong>2次以降</strong>（予算区分は1次のときだけの項目なので設定なし）<br>
      ・管理者・採用者の「該当者なし」は<strong>空欄（直営業）</strong>にして登録<br>
      ・<strong>佐藤思杰</strong>（1/1・GA知立）は元データの原価10,000が誤りのため、支払単価12,000×1日＝<strong>12,000</strong>で登録します。この1件のぶん元データの合計より原価+2,000／粗利-2,000になります<br>
      ・<strong>稲田勇人</strong>（1/10〜12）は外注先が空だったので<strong>渡邊拓斗</strong>を補いました<br>
      ・<strong>岩田宗士</strong>（1/3〜4）は区分・外注先とも「アルバイト」だったので<strong>アライアンス＋オアシス</strong>にしました<br>
      ・スタッフ名が店舗名になっている1件（<strong>刈谷ハイウェイ</strong>／外注先=林航平）は元データのまま登録します<br>
      ・名前の表記ゆれを既存の登録に合わせました（金子慶太→<strong>金子</strong>、渡邉政幸→<strong>渡部政幸</strong>、山﨑慈英→<strong>山崎慈英</strong>、安田祐希→<strong>安田裕希</strong>）。碧山圭夏と碧山匠夏は別人なのでそのままです<br>
      ・イベント案件に光ADの項目はありません<br>
      ・二重登録チェックは<strong>「スタッフ名＋開始日」</strong>で行います（同じ人が月内に何度も稼働するため）<br>
      <?php if ($willCreateClients): ?>・取引先を新規作成します: <strong><?= h(implode('、', array_keys($willCreateClients))) ?></strong><br><?php endif; ?>
      <?php if ($willCreateAlliances): ?>・外注先を新規作成します: <strong><?= h(implode('、', array_keys($willCreateAlliances))) ?></strong><br><?php endif; ?>
    </div>
  </div>

  <div class="alert alert-success small">
    <i class="bi bi-info-circle me-1"></i>
    イベント案件の「金額反映」ボタン（🔄）は <strong>日単価 × 稼働日数</strong> で計算し直すだけなので、
    常勤案件と違って<strong>押しても金額は変わりません</strong>。
  </div>

  <div class="table-responsive mb-3" style="max-height:52vh">
    <table class="table table-sm table-hover bg-white mb-0">
      <thead class="table-light" style="position:sticky;top:0">
        <tr>
          <th>#</th><th>取引先</th><th>営業</th><th>管理者</th><th>採用者</th><th>ｽﾀｯﾌ区分</th><th>外注先</th>
          <th>スタッフ</th><th>開始日</th><th>終了日</th><th>ｷｬﾘｱ</th><th>屋号</th><th>店舗</th><th class="text-end">稼働</th>
          <th class="text-end">請求単価(日)</th><th class="text-end">支払単価(日)</th><th class="text-end">売上</th><th class="text-end">粗利</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($DATA as $i => $d): $isDup = isset($existingNames[$d[6] . '|' . $d[7]]); ?>
        <tr class="<?= $isDup ? 'table-warning' : '' ?>">
          <td class="text-muted"><?= $i + 1 ?></td>
          <td><?= h($d[0]) ?><?= isset($clientMap[$d[0]]) ? '' : ' <span class="badge bg-warning text-dark" style="font-size:.6rem">新規</span>' ?></td>
          <td><?= h($d[1]) ?></td>
          <td><?= h($d[2]) ?></td>
          <td><?= h($d[3]) ?></td>
          <td><?= h($d[4]) ?></td>
          <td><?= h($d[5]) ?><?= ($d[4] === 'アライアンス' && $d[5] !== '' && !isset($allianceMap[$d[5]])) ? ' <span class="badge bg-warning text-dark" style="font-size:.6rem">新規</span>' : '' ?></td>
          <td class="fw-medium"><?= h($d[6]) ?><?= $isDup ? ' <span class="badge bg-danger" style="font-size:.6rem">既存</span>' : '' ?></td>
          <td><?= h($d[7]) ?></td>
          <td><?= h($d[8]) ?></td>
          <td><?= h($d[9]) ?></td>
          <td><?= h($d[14]) ?></td>
          <td><?= h($d[10]) ?></td>
          <td class="text-end"><?= (int)$d[13] ?></td>
          <td class="text-end"><?= number_format($d[11]) ?></td>
          <td class="text-end"><?= number_format($d[12]) ?></td>
          <td class="text-end"><?= number_format($d[11] * $d[13]) ?></td>
          <td class="text-end <?= ($d[11] - $d[12]) < 0 ? 'text-danger fw-bold' : '' ?>"><?= number_format(($d[11] - $d[12]) * $d[13]) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <form method="post" onsubmit="var b=this.querySelector('button[type=submit]');b.disabled=true;b.textContent='登録中...';">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <?php if ($dupRows): ?>
    <div class="form-check mb-2">
      <input class="form-check-input" type="checkbox" name="include_dup" value="1" id="incDup">
      <label class="form-check-label small" for="incDup">
        同じ「スタッフ名＋開始日」が既にある<?= $dupRows ?>件も登録する（二重登録になります。通常はチェックしないでください）
      </label>
    </div>
    <?php endif; ?>
    <button type="submit" class="btn btn-success btn-lg"><i class="bi bi-check-circle me-1"></i>登録を実行</button>
    <a href="<?= BASE_PATH ?>/public/sales_regular.php?year=<?= $YEAR ?>&month=<?= $MONTH ?>" class="btn btn-outline-secondary ms-2">キャンセル</a>
  </form>

<?php endif; ?>

</div>
</body>
</html>
