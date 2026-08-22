<?php
/**
 * 【一度だけ実行するスクリプト】2026年8月 イベント案件 データ投入
 *
 * スプレッドシートの「2026年8月イベント稼働分」86行をイベント案件として登録します。
 *
 * 使い方: 管理者でログインした状態でこのURLを開き、内容を確認して「登録を実行」を押す。
 *         実行が終わったらこのファイルを削除してください。
 *
 * 常勤との違い:
 *   - 金額は 日単価 × 稼働日数 でシステムが自動計算する（gross_profit_direct は使わない）。
 *     元データの売上・原価・粗利と全86行で一致することを確認済み。
 *   - 開始日・終了日は日付そのまま。開始日〜終了日の日数＝稼働日数も全86行一致。
 *   - イベント案件に光ADの項目は無い。
 *   - 同じスタッフが月内に何度も稼働するため、二重登録チェックは「スタッフ名＋開始日」で行う。
 *
 * 8月分の読み替え（ユーザー確認済み）:
 *   - 表記ゆれを多数派に統一: 牛嶋捷介→牛島捷介（1件）、渡辺大空→渡部大空（1件）。
 *   - コード列が AM の1行（LIBERTY／北岡空駆／小牧／8-20〜8-23）は、
 *     屋号=AM・店舗=小牧 とし、キャリアは他のLIBERTY案件に合わせて au にする。
 *
 * 9月〜4月分と同じ扱い:
 *   - 管理者・採用者の「該当者なし」は空欄にして直営業扱いにする。
 *   - 区分は全件「2次以降」。コード列は au・SB のみキャリアへ、AM は屋号へ。
 *   - 店舗名の先頭の ED・YMD・AM・GA・Joshin・YAMADA などは分けずに店舗名のまま。
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

requireAnyLogin();
if (!isAdmin()) { http_response_code(403); die('管理者のみ利用できます'); }

$db    = getDB();
$cid   = getCompanyId();
$csrf  = getCsrfToken();
$YEAR  = 2026;
$MONTH = 8;

// 列: 0取引先 1営業担当 2管理者 3採用者 4スタッフ区分 5外注先 6スタッフ名
//     7開始日 8終了日 9キャリア 10店舗名 11請求単価(日) 12支払単価(日) 13稼働日数 14屋号
$DATA = [
    ['グラスト','綾部航介','綾部航介','','アライアンス','U-plus','藤吉太一','2026-08-01','2026-08-02','au','イオン浜松志都呂',25000,22000,2,''],
    ['グラスト','綾部航介','綾部航介','','アライアンス','U-plus','横田華愛','2026-08-01','2026-08-02','au','イオン浜松志都呂',24000,20000,2,''],
    ['センターフロー','名倉雅貴','竹内陽','','アライアンス','近藤SEED','廣瀬空恩','2026-08-01','2026-08-02','SB','ED千音寺',25000,23000,2,''],
    ['センターフロー','名倉雅貴','竹内陽','','アライアンス','近藤SEED','近藤煌晃','2026-08-01','2026-08-01','SB','ED千音寺',23000,21000,1,''],
    ['センターフロー','名倉雅貴','竹内陽','','アライアンス','近藤SEED','小島光流','2026-08-01','2026-08-02','SB','ED日進竹の山',25000,23000,2,''],
    ['kunitoko asset','名倉雅貴','名倉雅貴','','アライアンス','ネクシア','山田翔太','2026-08-01','2026-08-02','au','ED豊川',18000,15000,2,''],
    ['kunitoko asset','名倉雅貴','竹内陽','','アライアンス','近藤SEED','伊藤光星','2026-08-01','2026-08-01','au','ビックカメラ駅西',18000,16000,1,''],
    ['kunitoko asset','名倉雅貴','竹内陽','','アライアンス','近藤SEED','堀井翔英','2026-08-01','2026-08-02','au','ED高針',16000,15000,2,''],
    ['kunitoko asset','名倉雅貴','竹内陽','','アライアンス','近藤SEED','蝦名くるみ','2026-08-02','2026-08-02','au','Joshinみなと',16000,15000,1,''],
    ['フリーダム','竹内陽','竹内陽','','自社外注','','林航平','2026-08-01','2026-08-02','SB','AM茶屋',33000,0,2,''],
    ['フリーダム','竹内陽','竹内陽','','アライアンス','オアシス','岩田宗士','2026-08-01','2026-08-02','SB','AM茶屋',33000,30000,2,''],
    ['フリーダム','竹内陽','竹内陽','','アライアンス','オアシス','牛島捷介','2026-08-01','2026-08-02','SB','AM茶屋',33000,30000,2,''],
    ['フリーダム','竹内陽','竹内陽','','アライアンス','オアシス','青川萌夏','2026-08-01','2026-08-02','SB','AM茶屋',25000,20000,2,''],
    ['LANGIS','竹内陽','','','正社員','','近藤航','2026-08-01','2026-08-02','SB','AM鈴鹿',23000,0,2,''],
    ['LANGIS','竹内陽','','','正社員','','近藤航','2026-08-08','2026-08-09','SB','AM鈴鹿',23000,0,2,''],
    ['LANGIS','竹内陽','','','正社員','','近藤航','2026-08-11','2026-08-11','SB','AM鈴鹿',23000,0,1,''],
    ['ASXEED','綾部航介','','竹内陽','アライアンス','近藤SEED','安井健斗','2026-08-08','2026-08-09','SB','ED多治見',27000,25000,2,''],
    ['ASXEED','綾部航介','竹内陽','','アライアンス','近藤SEED','安井健斗','2026-08-10','2026-08-10','SB','Joshin赤池',27000,25000,1,''],
    ['ASXEED','綾部航介','竹内陽','','アライアンス','近藤SEED','安井健人','2026-08-11','2026-08-11','SB','ED多治見',27000,25000,1,''],
    ['センターフロー','名倉雅貴','竹内陽','','アライアンス','近藤SEED','廣瀬空恩','2026-08-08','2026-08-09','SB','YAMADA豊橋',25000,23000,2,''],
    ['センターフロー','名倉雅貴','竹内陽','','アライアンス','近藤SEED','近藤煌晃','2026-08-08','2026-08-09','SB','YAMADA豊橋',25000,23000,2,''],
    ['センターフロー','名倉雅貴','竹内陽','','アライアンス','近藤SEED','伊藤光星','2026-08-08','2026-08-09','SB','ED豊田本店',18000,16000,2,''],
    ['センターフロー','名倉雅貴','竹内陽','','アライアンス','近藤SEED','森将人','2026-08-08','2026-08-09','SB','ED豊田本店',18000,16000,2,''],
    ['kunitoko asset','名倉雅貴','竹内陽','','アライアンス','近藤SEED','杉本龍星','2026-08-08','2026-08-09','au','Joshin多治見',16000,15000,2,''],
    ['kunitoko asset','名倉雅貴','竹内陽','','アライアンス','近藤SEED','堀井翔英','2026-08-09','2026-08-09','au','ED安城',16000,15000,1,''],
    ['kunitoko asset','名倉雅貴','竹内陽','','アライアンス','HopeVillage','柴田雄晴','2026-08-08','2026-08-08','au','ED安城',16000,15000,1,''],
    ['kunitoko asset','名倉雅貴','竹内陽','','アライアンス','onetale','櫨元一夢','2026-08-09','2026-08-09','au','ED安城',18000,15000,1,''],
    ['kunitoko asset','名倉雅貴','竹内陽','','アライアンス','ゼニソ','岩田光司','2026-08-08','2026-08-09','au','YAMADA一宮',18000,17000,2,''],
    ['kunitoko asset','名倉雅貴','竹内陽','','アライアンス','ゼニソ','赤塚基矢','2026-08-08','2026-08-09','au','YAMADA一宮',18000,17000,2,''],
    ['kunitoko asset','名倉雅貴','東郷光啓','','アライアンス','LIFIX','栗田ひかり','2026-08-08','2026-08-09','au','YAMADA四日市',18000,17000,2,''],
    ['LIBERTY','名倉雅貴','竹内陽','','アライアンス','オアシス','渡部大空','2026-08-01','2026-08-02','au','イオン瀬戸みずの',30000,25000,2,''],
    ['LIBERTY','名倉雅貴','竹内陽','','アライアンス','オアシス','北岡空駆','2026-08-01','2026-08-02','au','イオン瀬戸みずの',30000,25000,2,''],
    ['LIBERTY','名倉雅貴','竹内陽','','アライアンス','オアシス','渡部大空','2026-08-07','2026-08-11','au','イオン名古屋東',30000,25000,5,''],
    ['グラスト','綾部航介','綾部航介','','アライアンス','U-plus','藤吉太一','2026-08-08','2026-08-09','au','イオン北方',25000,22000,2,''],
    ['グラスト','綾部航介','綾部航介','','アライアンス','U-plus','藤吉太一','2026-08-11','2026-08-11','au','ドラッグユタカ各務原',25000,22000,1,''],
    ['フリーダム','竹内陽','竹内陽','','自社外注','','林航平','2026-08-08','2026-08-11','SB','AMドーム前',33000,0,4,''],
    ['フリーダム','竹内陽','竹内陽','','アライアンス','オアシス','岩田宗士','2026-08-08','2026-08-11','SB','AMドーム前',33000,30000,4,''],
    ['フリーダム','竹内陽','竹内陽','','アライアンス','オアシス','牛島捷介','2026-08-08','2026-08-11','SB','AMドーム前',33000,30000,4,''],
    ['フリーダム','竹内陽','竹内陽','','アライアンス','オアシス','青川萌夏','2026-08-08','2026-08-11','SB','AMドーム前',25000,20000,4,''],
    ['kunitoko asset','名倉雅貴','竹内陽','','アライアンス','ゼニソ','岩田光司','2026-08-13','2026-08-14','au','Joshin木曽川',18000,17000,2,''],
    ['センターフロー','名倉雅貴','竹内陽','','アライアンス','近藤SEED','小島光流','2026-08-13','2026-08-13','SB','YMDららぽーと安城',25000,23000,1,''],
    ['センターフロー','名倉雅貴','竹内陽','','アライアンス','近藤SEED','廣瀬空恩','2026-08-14','2026-08-14','SB','YMDららぽーと安城',25000,23000,1,''],
    ['LIBERTY','名倉雅貴','竹内陽','','アライアンス','オアシス','渡部大空','2026-08-13','2026-08-16','au','ららぽーとアクルスみなと',30000,25000,4,''],
    ['LIBERTY','名倉雅貴','竹内陽','','アライアンス','オアシス','北岡空駆','2026-08-13','2026-08-16','au','ららぽーとアクルスみなと',30000,25000,4,''],
    ['グラスト','綾部航介','綾部航介','','アライアンス','U-plus','藤吉太一','2026-08-14','2026-08-14','au','ドラッグユタカ則武',25000,22000,1,''],
    ['グラスト','綾部航介','綾部航介','','アライアンス','U-plus','藤吉太一','2026-08-17','2026-08-17','au','ドラッグユタカ則武',25000,22000,1,''],
    ['グラスト','綾部航介','綾部航介','','アライアンス','U-plus','横田華愛','2026-08-14','2026-08-14','au','ドラッグユタカ則武',24000,20000,1,''],
    ['グラスト','綾部航介','綾部航介','','アライアンス','U-plus','横田華愛','2026-08-17','2026-08-17','au','ドラッグユタカ則武',24000,20000,1,''],
    ['コンフィアンス','竹内陽','竹内陽','','アライアンス','オアシス','岩田宗士','2026-08-12','2026-08-12','SB','AM木曽川',28000,25000,1,''],
    ['コンフィアンス','竹内陽','竹内陽','','アライアンス','オアシス','内藤聖稀','2026-08-13','2026-08-13','SB','AM木曽川',32000,29000,1,''],
    ['コンフィアンス','竹内陽','竹内陽','','アライアンス','オアシス','辻村杏慈','2026-08-14','2026-08-14','SB','AM木曽川',28000,23000,1,''],
    ['コンフィアンス','竹内陽','竹内陽','','アライアンス','オアシス','渡邉晏','2026-08-14','2026-08-14','SB','AM木曽川',20000,18000,1,''],
    ['コンフィアンス','竹内陽','竹内陽','','自社外注','','林航平','2026-08-14','2026-08-16','SB','ゲートタワー',32000,0,3,''],
    ['コンフィアンス','竹内陽','竹内陽','','アライアンス','オアシス','岩田宗士','2026-08-14','2026-08-16','SB','ゲートタワー',28000,25000,3,''],
    ['コンフィアンス','竹内陽','竹内陽','','アライアンス','オアシス','牛島捷介','2026-08-14','2026-08-16','SB','ゲートタワー',28000,25000,3,''],
    ['コンフィアンス','竹内陽','竹内陽','','アライアンス','オアシス','内藤聖稀','2026-08-14','2026-08-14','SB','ゲートタワー',32000,31000,1,''],
    ['コンフィアンス','竹内陽','竹内陽','','アライアンス','オアシス','渡邉晏','2026-08-15','2026-08-16','SB','ゲートタワー',25000,20000,2,''],
    ['コンフィアンス','竹内陽','竹内陽','','アライアンス','オアシス','早川佳織','2026-08-14','2026-08-14','SB','ゲートタワー',20000,18000,1,''],
    ['コンフィアンス','竹内陽','竹内陽','','アライアンス','オアシス','矢野夢彩','2026-08-14','2026-08-14','SB','ゲートタワー',20000,18000,1,''],
    ['コンフィアンス','竹内陽','竹内陽','','アライアンス','オアシス','達城美結','2026-08-14','2026-08-14','SB','ゲートタワー',20000,18000,1,''],
    ['コンフィアンス','竹内陽','竹内陽','','アライアンス','オアシス','達城美結','2026-08-16','2026-08-16','SB','ゲートタワー',20000,18000,1,''],
    ['コンフィアンス','竹内陽','竹内陽','','アライアンス','オアシス','吉野幸来','2026-08-15','2026-08-15','SB','ゲートタワー',20000,18000,1,''],
    ['コンフィアンス','竹内陽','竹内陽','','アライアンス','オアシス','青川萌夏','2026-08-15','2026-08-16','SB','ゲートタワー',20000,18000,2,''],
    ['コンフィアンス','竹内陽','竹内陽','','アライアンス','魁組','中原魁','2026-08-15','2026-08-16','SB','鈴鹿玉垣',30000,29000,2,''],
    ['コンフィアンス','竹内陽','竹内陽','','アライアンス','KTT','岩月花樹','2026-08-16','2026-08-16','SB','なるパーク',20000,18000,1,''],
    ['センターフロー','名倉雅貴','竹内陽','','アライアンス','近藤SEED','廣瀬空恩','2026-08-15','2026-08-16','SB','YMD豊川',25000,23000,2,''],
    ['センターフロー','名倉雅貴','竹内陽','','アライアンス','近藤SEED','森将人','2026-08-15','2026-08-16','SB','YMD豊川',25000,23000,2,''],
    ['センターフロー','名倉雅貴','竹内陽','','アライアンス','近藤SEED','伊藤光星','2026-08-15','2026-08-16','SB','ED鈴鹿',18000,16000,2,''],
    ['kunitoko asset','名倉雅貴','竹内陽','','アライアンス','HopeVillage','青井響','2026-08-15','2026-08-16','au','ケーズ美濃加茂',16000,15000,2,''],
    ['kunitoko asset','名倉雅貴','竹内陽','','アライアンス','onetale','櫨元一夢','2026-08-15','2026-08-16','au','ED豊田本店',18000,15000,2,''],
    ['LANGIS','竹内陽','','','正社員','','近藤航','2026-08-15','2026-08-16','SB','AM鈴鹿',23000,0,2,''],
    ['VIN','竹内陽','竹内陽','','アライアンス','オアシス','牛島捷介','2026-08-18','2026-08-21','SB','AM常滑',29000,28000,4,''],
    ['VIN','竹内陽','竹内陽','','アライアンス','オアシス','牛島捷介','2026-08-22','2026-08-23','SB','AM春日井',29000,28000,2,''],
    ['LIBERTY','竹内陽','竹内陽','','アライアンス','オアシス','渡部大空','2026-08-21','2026-08-24','au','ピアゴ三河安城',30000,25000,4,''],
    ['LIBERTY','竹内陽','竹内陽','','アライアンス','オアシス','北岡空駆','2026-08-20','2026-08-23','au','小牧',30000,25000,4,'AM'],
    ['ラネット','竹内陽','竹内陽','','アライアンス','渡邊拓斗','小木曽太一','2026-08-21','2026-08-24','SB','GA知立',35000,30000,4,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','渡邊拓斗','小野峻登','2026-08-21','2026-08-24','SB','GA知立',35000,30000,4,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','渡邊拓斗','立澤怜央','2026-08-21','2026-08-21','SB','GA知立',35000,30000,1,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','渡邊拓斗','三野悠','2026-08-22','2026-08-23','SB','GA知立',35000,30000,2,''],
    ['ラネット','竹内陽','竹内陽','','アライアンス','渡邊拓斗','大町北斗','2026-08-24','2026-08-24','SB','GA知立',35000,30000,1,''],
    ['kunitoko asset','名倉雅貴','綾部航介','','アライアンス','U-plus','松山優菜','2026-08-22','2026-08-23','au','YAMADA岐阜本店',16000,15000,2,''],
    ['kunitoko asset','名倉雅貴','綾部航介','','アライアンス','U-plus','安山昆生','2026-08-22','2026-08-23','au','YAMADA岐阜本店',16000,15000,2,''],
    ['LANGIS','竹内陽','','','正社員','','近藤航','2026-08-22','2026-08-23','SB','AM鈴鹿',23000,0,2,''],
    ['LANGIS','竹内陽','','','正社員','','近藤航','2026-08-29','2026-08-30','SB','AM鈴鹿',23000,0,2,''],
    ['kunitoko asset','名倉雅貴','竹内陽','','アライアンス','onetale','櫨元一夢','2026-08-22','2026-08-22','au','ケーズ大樹寺',18000,15000,1,''],
    ['センターフロー','名倉雅貴','竹内陽','','アライアンス','近藤SEED','廣瀬空恩','2026-08-22','2026-08-23','SB','EDなるぱーく',25000,23000,2,''],
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
//
// 取引先・外注先は 2026-08-19 から「正式名称（株式会社◯◯）＋表記名（◯◯）」の2列構成。
// 元データはスプレッドシートの呼び名なので、正式名称だけで照合すると全部が新規に見えてしまう。
// そこで 正式名称・表記名の両方を、法人格と記号を落とした形に正規化して突き合わせる。
function impNormName(string $s): string {
    $s = trim($s);
    $s = mb_convert_kana($s, 'asKV');                       // 全角英数→半角、半角カナ→全角
    $s = preg_replace('/(株式会社|有限会社|合同会社|一般社団法人|\(株\)|㈱)/u', '', $s);
    $s = preg_replace('/[\s\x{3000}・（）\(\)ー\-]/u', '', $s); // 空白・中黒・カッコ・長音/ハイフン
    return mb_strtolower($s);
}

// スプレッドシートの呼び名と、マスタ側の名前が別物になっているもの（2026-08-19 の統合で改名）
// ここに書いた名前でもマスタを探す。見つからなければ「新規」として画面に出す
$CLIENT_ALIAS = [];
$ALLIANCE_ALIAS = [
    'オアシス'   => ['OASIS', '株式会社OASIS'],
    '近藤SEED'   => ['SEED（近藤）', 'SEED(近藤)'],
    '渡邊拓斗'   => ['渡邉拓斗'],
];

$clientRows = getSalesClients($cid, false);
$allianceRows = getSalesAlliances($cid, false);

/** 正規化した名前 => マスタ行 の索引を作る（正式名称・表記名の両方を鍵にする） */
function impBuildIndex(array $rows, string $nameCol): array {
    $idx = [];
    foreach ($rows as $r) {
        foreach ([$r[$nameCol] ?? '', $r['display_name'] ?? ''] as $n) {
            $k = impNormName((string)$n);
            if ($k !== '' && !isset($idx[$k])) $idx[$k] = $r;
        }
    }
    return $idx;
}
$clientIndex   = impBuildIndex($clientRows, 'client_name');
$allianceIndex = impBuildIndex($allianceRows, 'alliance_name');

/**
 * 元データの名前から既存マスタを探す。
 * 戻り値: [マスタ行 または null, 一致の種類]
 */
function impFindMaster(string $name, array $index, array $aliasMap): array {
    $k = impNormName($name);
    if ($k !== '' && isset($index[$k])) return [$index[$k], '一致'];
    foreach ($aliasMap[$name] ?? [] as $alias) {
        $ak = impNormName($alias);
        if ($ak !== '' && isset($index[$ak])) return [$index[$ak], '別名で一致'];
    }
    return [null, '新規'];
}

/** 一致しなかった名前について、似ている既存マスタを最大3件挙げる（画面の確認用） */
function impCandidates(string $name, array $rows, string $nameCol): array {
    $k = impNormName($name);
    if ($k === '') return [];
    $hits = [];
    foreach ($rows as $r) {
        foreach ([$r[$nameCol] ?? '', $r['display_name'] ?? ''] as $n) {
            $nk = impNormName((string)$n);
            if ($nk === '') continue;
            if (mb_strpos($nk, $k) !== false || mb_strpos($k, $nk) !== false) {
                $hits[(int)$r['id']] = $r;
            }
        }
        if (count($hits) >= 3) break;
    }
    return array_values($hits);
}

// 元データに出てくる名前ごとの照合結果（画面表示と登録の両方でこれを使う）
$clientMatch = [];    // 取引先名 => ['row'=>?, 'kind'=>..., 'cands'=>[], 'count'=>件数]
$allianceMatch = [];
foreach ($DATA as $d) {
    if ($d[0] !== '' && !isset($clientMatch[$d[0]])) {
        [$row, $kind] = impFindMaster($d[0], $clientIndex, $CLIENT_ALIAS);
        $clientMatch[$d[0]] = ['row' => $row, 'kind' => $kind, 'count' => 0,
                               'cands' => $row ? [] : impCandidates($d[0], $clientRows, 'client_name')];
    }
    if ($d[0] !== '') $clientMatch[$d[0]]['count']++;

    if ($d[4] === 'アライアンス' && $d[5] !== '') {
        if (!isset($allianceMatch[$d[5]])) {
            [$row, $kind] = impFindMaster($d[5], $allianceIndex, $ALLIANCE_ALIAS);
            $allianceMatch[$d[5]] = ['row' => $row, 'kind' => $kind, 'count' => 0,
                                     'cands' => $row ? [] : impCandidates($d[5], $allianceRows, 'alliance_name')];
        }
        $allianceMatch[$d[5]]['count']++;
    }
}
$newClientNames   = array_keys(array_filter($clientMatch,   fn($m) => $m['row'] === null));
$newAllianceNames = array_keys(array_filter($allianceMatch, fn($m) => $m['row'] === null));

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
        error_log('[import_event_202608 roster] ' . $e->getMessage());
    }
    $rosterRows[] = $row;
}

$done = false; $created = 0; $skipped = []; $failed = []; $newClients = []; $newAlliances = [];

// ── 登録実行 ──
$blockedByNewMaster = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf'] ?? '')) {
    $includeDup = !empty($_POST['include_dup']);
    // 取引先・外注先を新しく作るのは、明示的にチェックを入れたときだけ。
    // 呼び名の違いでマスタが二重にできるのを防ぐための歯止め
    if (($newClientNames || $newAllianceNames) && empty($_POST['allow_new_masters'])) {
        $blockedByNewMaster = true;
    }
}
if (!$blockedByNewMaster && $_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrfToken($_POST['csrf'] ?? '')) {
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
            // 既存マスタが見つかっていればそのIDを使う（呼び名が違っても同じ会社に紐づく）
            $clientId = null;
            if ($clientName !== '') {
                $m = $clientMatch[$clientName];
                if ($m['row']) {
                    $clientId = (int)$m['row']['id'];
                } else {
                    $clientId = createSalesClient($cid, ['client_name' => $clientName]);
                    // 同じ名前が2行目以降で再び新規作成されないよう、照合結果に書き戻す
                    $clientMatch[$clientName]['row'] = ['id' => $clientId, 'client_name' => $clientName, 'display_name' => $clientName];
                    $newClients[] = $clientName;
                }
            }
            $allianceId = null;
            if ($workerType === 'アライアンス' && $allianceName !== '') {
                $m = $allianceMatch[$allianceName];
                if ($m['row']) {
                    $allianceId = (int)$m['row']['id'];
                } else {
                    $allianceId = createSalesAlliance($cid, ['alliance_name' => $allianceName]);
                    $allianceMatch[$allianceName]['row'] = ['id' => $allianceId, 'alliance_name' => $allianceName, 'display_name' => $allianceName];
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
    <strong><?= count($skipped) ?>件はスキップしました</strong>（同じ「スタッフ名＋開始日」が既に<?= $MONTH ?>月に登録されていたため）
    <div class="mt-1"><?= h(implode('、', $skipped)) ?></div>
  </div>
  <?php endif; ?>

  <?php if ($failed): ?>
  <div class="alert alert-danger small">
    <strong><?= count($failed) ?>件は登録できませんでした</strong>
    <ul class="mb-0 mt-1"><?php foreach ($failed as $f): ?><li><?= h($f) ?></li><?php endforeach; ?></ul>
  </div>
  <?php endif; ?>

  <a href="<?= BASE_PATH ?>/public/sales_events.php?year=<?= $YEAR ?>&month=<?= $MONTH ?>" class="btn btn-primary">
    イベント案件（<?= $YEAR ?>年<?= $MONTH ?>月）を確認する
  </a>
  <div class="alert alert-warning mt-3 mb-0 small">
    <i class="bi bi-exclamation-triangle me-1"></i>登録が終わったら、このページ（import_event_202608.php）は削除してください。もう一度開いて実行すると二重登録の原因になります。
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
      ・金額は <strong>日単価 × 稼働日数</strong> でシステムが自動計算します（元データの売上・原価・粗利と全<?= count($DATA) ?>行で一致することを確認済み）<br>
      ・開始日〜終了日は<strong>日付のまま</strong>登録します（元データの期間の日数＝稼働日数も全行一致）<br>
      ・コード列は <strong>au・SB</strong> をキャリアへ。<strong>AM の1行</strong>（LIBERTY／北岡空駆／8-20〜8-23）は屋号=AM・店舗=小牧とし、キャリアは他のLIBERTY案件に合わせて <strong>au</strong> にしました<br>
      ・店舗名の先頭の ED・YMD・AM・GA・Joshin・YAMADA などは<strong>分けずに店舗名のまま</strong>登録します<br>
      ・区分は全件<strong>2次以降</strong>（予算区分は1次のときだけの項目なので設定なし）<br>
      ・管理者・採用者の「該当者なし」は<strong>空欄（直営業）</strong>にして登録<br>
      ・スタッフ名の表記ゆれを多数派に統一しました（2件）：牛嶋捷介→<strong>牛島捷介</strong>、渡辺大空→<strong>渡部大空</strong><br>
      ・イベント案件に光ADの項目はありません<br>
      ・二重登録チェックは<strong>「スタッフ名＋開始日」</strong>で行います（同じ人が月内に何度も稼働するため）<br>
      ・取引先・外注先は<strong>正式名称と表記名の両方</strong>で照合します（「株式会社」などを外して突き合わせ）。下の照合表を確認してください<br>
    </div>
  </div>

  <!-- 取引先・外注先のマスタ照合 -->
  <div class="card mb-3"><div class="card-body">
    <div class="fw-semibold mb-2"><i class="bi bi-link-45deg me-1"></i>取引先・外注先が既存マスタと結びつくか</div>
    <div class="small text-muted mb-2">
      元データの呼び名と、取引先一覧に登録されている<strong>正式名称・表記名</strong>を突き合わせた結果です。
      <strong>「新規」の行があるとそのまま実行できません</strong>（同じ会社が二重に作られるのを防ぐため）。
      既に登録がある会社なら、候補欄の名前を私に伝えてください。読み替えを追加します。
    </div>
    <div class="table-responsive">
    <table class="table table-sm bg-white mb-0">
      <thead class="table-light"><tr>
        <th>種別</th><th>元データの呼び名</th><th class="text-end">件数</th><th>判定</th><th>結びつく既存マスタ</th><th>ID</th><th>似ている既存マスタ（候補）</th>
      </tr></thead>
      <tbody>
      <?php
      $matchTable = [];
      foreach ($clientMatch as $nm => $m)   { $matchTable[] = ['取引先', $nm, $m]; }
      foreach ($allianceMatch as $nm => $m) { $matchTable[] = ['外注先', $nm, $m]; }
      foreach ($matchTable as [$kindLabel, $nm, $m]):
          $isNew = $m['row'] === null;
      ?>
        <tr class="<?= $isNew ? 'table-danger' : '' ?>">
          <td><?= h($kindLabel) ?></td>
          <td class="fw-medium"><?= h($nm) ?></td>
          <td class="text-end"><?= (int)$m['count'] ?></td>
          <td><?php if ($isNew): ?><span class="text-danger fw-bold">✗ 新規</span>
              <?php elseif ($m['kind'] === '別名で一致'): ?><span class="text-primary fw-bold">△ 別名で一致</span>
              <?php else: ?><span class="text-success">✓ 一致</span><?php endif; ?></td>
          <td><?php if ($m['row']): ?>
                <?= h((string)($m['row']['client_name'] ?? $m['row']['alliance_name'] ?? '')) ?>
                <span class="text-muted">／表記名 <?= h((string)($m['row']['display_name'] ?? '')) ?></span>
              <?php else: ?><span class="text-muted">-</span><?php endif; ?></td>
          <td class="text-muted"><?= $m['row'] ? (int)$m['row']['id'] : '-' ?></td>
          <td class="small text-muted">
            <?php if ($m['cands']): foreach ($m['cands'] as $c): ?>
              <span class="badge bg-light text-dark border me-1">
                <?= h((string)($c['client_name'] ?? $c['alliance_name'] ?? '')) ?>（ID<?= (int)$c['id'] ?>）
              </span>
            <?php endforeach; elseif ($isNew): ?>似た名前は見つかりませんでした<?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div></div>

  <?php if ($newClientNames || $newAllianceNames): ?>
  <div class="alert alert-danger">
    <div class="fw-bold"><i class="bi bi-exclamation-octagon me-1"></i>既存マスタと結びつかない名前が
      <?= count($newClientNames) + count($newAllianceNames) ?>件あります</div>
    <div class="small mt-1">
      <?php if ($newClientNames): ?>取引先: <strong><?= h(implode('、', $newClientNames)) ?></strong><br><?php endif; ?>
      <?php if ($newAllianceNames): ?>外注先: <strong><?= h(implode('、', $newAllianceNames)) ?></strong><br><?php endif; ?>
      本当に新しい会社であれば、下のチェックを入れれば<strong>この呼び名のまま新規作成</strong>して登録できます。
      ただし正式名称（株式会社◯◯）で登録したい場合は、先に取引先一覧で追加してからこのページを開き直してください。
    </div>
  </div>
  <?php endif; ?>

  <?php if ($blockedByNewMaster): ?>
  <div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-1"></i>
    新規作成になるマスタがあるため、登録を中止しました。<strong>1件も登録していません。</strong>
    上のチェックを入れて実行するか、先に取引先一覧へ登録してください。
  </div>
  <?php endif; ?>

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
          <td><?= h($d[0]) ?><?= ($d[0] !== '' && $clientMatch[$d[0]]['row'] === null) ? ' <span class="badge bg-danger" style="font-size:.6rem">新規</span>' : '' ?></td>
          <td><?= h($d[1]) ?></td>
          <td><?= h($d[2]) ?></td>
          <td><?= h($d[3]) ?></td>
          <td><?= h($d[4]) ?></td>
          <td><?= h($d[5]) ?><?= ($d[4] === 'アライアンス' && $d[5] !== '' && $allianceMatch[$d[5]]['row'] === null) ? ' <span class="badge bg-danger" style="font-size:.6rem">新規</span>' : '' ?></td>
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
    <?php if ($newClientNames || $newAllianceNames): ?>
    <div class="form-check mb-2">
      <input class="form-check-input" type="checkbox" name="allow_new_masters" value="1" id="allowNew">
      <label class="form-check-label small" for="allowNew">
        上の<?= count($newClientNames) + count($newAllianceNames) ?>件を<strong>元データの呼び名のまま新規のマスタとして作成する</strong>
        （既に別の名前で登録がある会社の場合は、チェックせず先に読み替えを追加してください）
      </label>
    </div>
    <?php endif; ?>
    <button type="submit" class="btn btn-success btn-lg"><i class="bi bi-check-circle me-1"></i>登録を実行</button>
    <a href="<?= BASE_PATH ?>/public/sales_events.php?year=<?= $YEAR ?>&month=<?= $MONTH ?>" class="btn btn-outline-secondary ms-2">キャンセル</a>
  </form>

<?php endif; ?>

</div>
</body>
</html>
