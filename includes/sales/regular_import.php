<?php
// ============================================================
// 常勤案件 一括インポート: 貼り付けデータの解釈（DB非依存）
// api/import_regular_cases.php から利用
// ============================================================
// 列順（タブ区切り／スプレッドシートからのコピーを想定）
//  1 取引先 / 2 営業担当 / 3 管理者 / 4 採用者 / 5 スタッフ区分 / 6 外注先
//  7 スタッフ名 / 8 稼働開始日 / 9 稼働日数 / 10 キャリア / 11 店舗名
//  12-15 未使用 / 16 売上(月額) / 17 原価(月額) / 18以降 未使用
// ============================================================

if (!function_exists('impInt')) {
    /** 金額・数値の取り出し（カンマや¥を除去） */
    function impInt(string $s): int {
        $s = str_replace([',', '¥', '￥', ' ', '　'], '', trim($s));
        if ($s === '' || !preg_match('/^-?\d+(\.\d+)?$/', $s)) return 0;
        return (int)round((float)$s);
    }
}

if (!function_exists('impWorkerType')) {
    /** スタッフ区分をフォームの選択肢に合わせる（「外注」は個人外注として扱う） */
    function impWorkerType(string $s, array &$warn): string {
        $s = trim($s);
        $allowed = ['正社員', '自社外注', 'アライアンス', '個人外注', 'アルバイト'];
        if (in_array($s, $allowed, true)) return $s;
        if ($s === '外注') return '個人外注';
        if ($s === '') { $warn[] = 'スタッフ区分が空のため「正社員」で登録します'; return '正社員'; }
        $warn[] = 'スタッフ区分「' . $s . '」は選択肢にないため「正社員」で登録します';
        return '正社員';
    }
}

if (!function_exists('impStartNote')) {
    /** 稼働開始日を備考用の文字列にする（例: 2025/12/01 → 稼働開始 2025/12） */
    function impStartNote(string $s): string {
        $s = trim($s);
        if ($s === '') return '';
        if (preg_match('#(\d{4})\s*[/\-年]\s*(\d{1,2})#', $s, $m)) {
            return '稼働開始 ' . $m[1] . '/' . str_pad($m[2], 2, '0', STR_PAD_LEFT);
        }
        return '稼働開始 ' . $s;
    }
}

if (!function_exists('parseRegularImport')) {
    /**
     * 貼り付けデータを解釈する
     * @param string $raw         貼り付けテキスト
     * @param array  $clientMap   既存取引先 名前 => id
     * @param array  $allianceMap 既存外注先 名前 => id
     * @return array{rows:array, skipped:array, new_clients:array, new_alliances:array}
     */
    function parseRegularImport(string $raw, array $clientMap, array $allianceMap): array {
        $lines        = preg_split('/\r\n|\r|\n/', $raw);
        $rows         = [];
        $skipped      = [];
        $newClients   = [];
        $newAlliances = [];
        $nameCount    = [];

        foreach ($lines as $i => $line) {
            $lineNo = $i + 1;
            if (trim($line) === '') continue;
            // タブ区切り。タブが無い行はカンマ区切りも試す
            $c = (strpos($line, "\t") !== false) ? explode("\t", $line) : explode(',', $line);
            $col = function (int $n) use ($c): string { return isset($c[$n - 1]) ? trim($c[$n - 1]) : ''; };

            $clientName = $col(1);
            $workerName = $col(7);
            if ($clientName === '' && $workerName === '') {
                $skipped[] = ['line' => $lineNo, 'reason' => '取引先・スタッフ名がどちらも空', 'text' => mb_substr(trim($line), 0, 40)];
                continue;
            }
            if (count($c) < 11) {
                $skipped[] = ['line' => $lineNo, 'reason' => '列が足りません（' . count($c) . '列）', 'text' => mb_substr(trim($line), 0, 40)];
                continue;
            }

            $warn         = [];
            $workerType   = impWorkerType($col(5), $warn);
            $allianceName = $col(6);
            $carrier      = $col(10);
            $storeName    = $col(11);
            $priceIn      = impInt($col(16));   // 売上（月額）→ 請求単価(月)
            $priceOut     = impInt($col(17));   // 原価（月額）→ 支払単価(月)
            $days         = impInt($col(9)) ?: 1;

            // 取引先
            $clientId = null;
            if ($clientName !== '') {
                if (isset($clientMap[$clientName])) {
                    $clientId = $clientMap[$clientName];
                } else {
                    if (!in_array($clientName, $newClients, true)) $newClients[] = $clientName;
                    $warn[] = '取引先「' . $clientName . '」を新規作成します';
                }
            } else {
                $warn[] = '取引先が空です';
            }

            // 外注先（アライアンスのときだけ使う。フォームと同じ挙動）
            $allianceId = null;
            if ($workerType === 'アライアンス') {
                if ($allianceName === '') {
                    $warn[] = 'アライアンスですが外注先が空です';
                } elseif (isset($allianceMap[$allianceName])) {
                    $allianceId = $allianceMap[$allianceName];
                } else {
                    if (!in_array($allianceName, $newAlliances, true)) $newAlliances[] = $allianceName;
                    $warn[] = '外注先「' . $allianceName . '」を新規作成します';
                }
            } elseif ($allianceName !== '') {
                $warn[] = 'スタッフ区分がアライアンス以外のため外注先「' . $allianceName . '」は登録しません';
            }

            if ($workerName === '') $warn[] = 'スタッフ名が空です';
            if ($carrier === '')    $warn[] = 'キャリアが空です';
            if ($storeName === '')  $warn[] = '店舗名が空です';
            if ($priceIn === 0)     $warn[] = '売上が0です';
            if ($priceIn - $priceOut < 0) $warn[] = '粗利がマイナスです';

            if ($workerName !== '') {
                $nameCount[$workerName] = ($nameCount[$workerName] ?? 0) + 1;
            }

            $rows[] = [
                'line'           => $lineNo,
                'client_name'    => $clientName,
                'client_id'      => $clientId,
                'sales_rep'      => $col(2),
                'manager'        => $col(3),
                'recruiter'      => $col(4),
                'worker_type'    => $workerType,
                'alliance_name'  => ($workerType === 'アライアンス') ? $allianceName : '',
                'alliance_id'    => $allianceId,
                'worker_name'    => $workerName,
                'carrier'        => $carrier,
                'store_name'     => $storeName,
                'days_worked'    => $days,
                'unit_price_in'  => $priceIn,
                'unit_price_out' => $priceOut,
                'gross_profit'   => $priceIn - $priceOut,
                'note'           => impStartNote($col(8)),
                'warnings'       => $warn,
            ];
        }

        // 同一スタッフ名が複数ある場合は注意表示
        foreach ($rows as &$r) {
            if ($r['worker_name'] !== '' && ($nameCount[$r['worker_name']] ?? 0) > 1) {
                $r['warnings'][] = '同じスタッフ名が' . $nameCount[$r['worker_name']] . '件あります（重複でないか確認してください）';
            }
        }
        unset($r);

        return [
            'rows'          => $rows,
            'skipped'       => $skipped,
            'new_clients'   => $newClients,
            'new_alliances' => $newAlliances,
        ];
    }
}
