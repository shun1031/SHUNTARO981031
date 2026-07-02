-- ============================================================
-- bMS 完全版スキーマ（構造）  自動統合: 既存sql/ + 不足マイグレーション
-- 文字コード: utf8mb4 / 対象: MariaDB(Xserver)
-- ============================================================


-- ========== sql/schema.sql （既存・基底） ==========
-- ============================================================
-- タレントマネジメントシステム (bMS) データベーススキーマ
-- Xserver MySQL 用
-- ============================================================

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- ============================================================
-- 管理者ユーザー
-- ============================================================
CREATE TABLE IF NOT EXISTS admin_users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 部署・チーム
-- ============================================================
CREATE TABLE IF NOT EXISTS teams (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    manager_id INT NULL,
    sub_manager_id INT NULL,
    strengths_analysis TEXT COMMENT 'ストレングスファインダー分析（AI生成）',
    spi_analysis TEXT COMMENT 'SPI分析（AI生成）',
    team_strengths TEXT COMMENT 'チームの強み',
    team_challenges TEXT COMMENT 'チームの課題',
    management_points TEXT COMMENT 'マネジメントの要点',
    ideal_recruit TEXT COMMENT 'このチームに合う人',
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 社員マスター
-- ============================================================
CREATE TABLE IF NOT EXISTS employees (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_number VARCHAR(20) UNIQUE COMMENT '社員番号',
    name VARCHAR(100) NOT NULL COMMENT '氏名',
    name_kana VARCHAR(100) COMMENT '氏名（かな）',
    email VARCHAR(200) UNIQUE,
    department VARCHAR(100) COMMENT '部署',
    job_title VARCHAR(100) COMMENT '職種・役職',
    hire_date DATE COMMENT '入社日',
    birth_date DATE COMMENT '生年月日',
    photo_path VARCHAR(255) COMMENT '顔写真パス',
    bio TEXT COMMENT '自己紹介・プロフィール',
    career_summary TEXT COMMENT 'キャリアサマリー（AI生成）',
    strengths_text TEXT COMMENT '得意なこと',
    weaknesses_text TEXT COMMENT '苦手なこと',
    how_to_utilize TEXT COMMENT 'この人の活かし方（AI生成）',
    is_active TINYINT DEFAULT 1 COMMENT '在籍フラグ',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- チームメンバー（中間テーブル）
-- ============================================================
CREATE TABLE IF NOT EXISTS team_members (
    team_id INT NOT NULL,
    employee_id INT NOT NULL,
    joined_date DATE,
    PRIMARY KEY (team_id, employee_id),
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- キャリア履歴
-- ============================================================
CREATE TABLE IF NOT EXISTS career_history (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL,
    start_year INT,
    start_month INT,
    end_year INT NULL,
    end_month INT NULL,
    is_current TINYINT DEFAULT 0 COMMENT '現在の職歴フラグ',
    is_internal TINYINT DEFAULT 0 COMMENT '社内キャリアフラグ',
    company VARCHAR(200) COMMENT '会社名（社外の場合）',
    position VARCHAR(150) COMMENT '役職・職種',
    description TEXT COMMENT '業務内容',
    sort_order INT DEFAULT 0,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- ストレングスファインダー
-- ============================================================
CREATE TABLE IF NOT EXISTS strengths_finder (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL UNIQUE,
    -- 34資質のランク（1位が最高）
    achiever INT NULL COMMENT '達成欲',
    activator INT NULL COMMENT '活発性',
    adaptability INT NULL COMMENT '適応性',
    analytical INT NULL COMMENT '分析思考',
    arranger INT NULL COMMENT '運命思考',
    belief INT NULL COMMENT '信念',
    command INT NULL COMMENT '指令性',
    communication INT NULL COMMENT 'コミュニケーション',
    competition INT NULL COMMENT '競争性',
    connectedness INT NULL COMMENT '運命思考',
    consistency INT NULL COMMENT '公平性',
    context INT NULL COMMENT '原点思考',
    deliberative INT NULL COMMENT '慎重さ',
    developer INT NULL COMMENT '成長促進',
    discipline INT NULL COMMENT '規律性',
    empathy INT NULL COMMENT '共感性',
    focus INT NULL COMMENT '着想',
    futuristic INT NULL COMMENT '未来志向',
    harmony INT NULL COMMENT '調和性',
    ideation INT NULL COMMENT '着想',
    includer INT NULL COMMENT '包含',
    individualization INT NULL COMMENT '個別化',
    input INT NULL COMMENT '収集心',
    intellection INT NULL COMMENT '内省',
    learner INT NULL COMMENT '学習欲',
    maximizer INT NULL COMMENT '最上志向',
    positivity INT NULL COMMENT 'ポジティブ',
    relator INT NULL COMMENT '親密性',
    responsibility INT NULL COMMENT '責任感',
    restorative INT NULL COMMENT '回復志向',
    self_assurance INT NULL COMMENT '自己確信',
    significance INT NULL COMMENT '自我',
    strategic INT NULL COMMENT '戦略性',
    woo INT NULL COMMENT '社交性',
    top5_text TEXT COMMENT 'トップ5資質の日本語説明',
    analysis TEXT COMMENT 'AI生成分析文',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SPI結果
-- ============================================================
CREATE TABLE IF NOT EXISTS spi_results (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL UNIQUE,
    -- 行動的側面
    social_introversion INT NULL COMMENT '社会的内向性',
    introspection INT NULL COMMENT '内省性',
    physical_activity INT NULL COMMENT '身体活動性',
    persistence INT NULL COMMENT '持続性',
    caution INT NULL COMMENT '慎重性',
    -- 意欲的側面
    achievement_drive INT NULL COMMENT '達成意欲',
    activity_drive INT NULL COMMENT '活動意欲',
    -- 情緒的側面
    sensitivity INT NULL COMMENT '敏感性',
    self_blame INT NULL COMMENT '自責性',
    mood_variation INT NULL COMMENT '気分性',
    uniqueness INT NULL COMMENT '独自性',
    self_confidence INT NULL COMMENT '自信性',
    elation INT NULL COMMENT '高揚性',
    -- 社会関係的側面
    compliance INT NULL COMMENT '従順性',
    avoidance INT NULL COMMENT '回避性',
    criticism INT NULL COMMENT '批判性',
    self_respect INT NULL COMMENT '自己尊重性',
    skepticism INT NULL COMMENT '懐疑思考性',
    -- 職場適応性
    leadership INT NULL COMMENT 'リーダーシップ',
    teamwork INT NULL COMMENT 'チームワーク',
    relationship_building INT NULL COMMENT '関係構築力',
    creative_thinking INT NULL COMMENT '創造的思考力',
    problem_solving INT NULL COMMENT '問題解決力',
    situation_adaptability INT NULL COMMENT '状況適応力',
    ownership INT NULL COMMENT '当事者意識',
    energetic_action INT NULL COMMENT '精力的行動力',
    analysis TEXT COMMENT 'AI生成分析文',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- AI相談履歴
-- ============================================================
CREATE TABLE IF NOT EXISTS consultation_history (
    id INT PRIMARY KEY AUTO_INCREMENT,
    question TEXT NOT NULL,
    answer TEXT,
    related_employee_ids JSON COMMENT '関連する社員IDリスト',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 外部キー（チームマネージャー）
-- ============================================================
ALTER TABLE teams
    ADD CONSTRAINT fk_teams_manager FOREIGN KEY (manager_id) REFERENCES employees(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_teams_sub_manager FOREIGN KEY (sub_manager_id) REFERENCES employees(id) ON DELETE SET NULL;

-- ============================================================
-- 初期管理者ユーザー（パスワード: admin123 ※必ず変更してください）
-- ============================================================
INSERT INTO admin_users (username, password_hash, display_name) VALUES
('admin', '$2y$10$zcTL5ptInlyEwPci17I0buJCZ.mDYNDiMkEvLr8MGnxdT8iJ0jYYS', 'システム管理者');

-- ============================================================
-- サンプルデータ（開発用）
-- ============================================================
INSERT INTO teams (id, name, description, sort_order) VALUES
(1, '営業部', '法人・個人向け営業を担当するチーム', 1),
(2, 'デザイン部', 'クリエイティブ制作を担当するチーム', 2),
(3, 'エンジニアリング部', 'システム開発・インフラを担当するチーム', 3),
(4, 'マーケティング部', 'マーケティング・広報を担当するチーム', 4);

INSERT INTO employees (id, employee_number, name, name_kana, email, department, job_title, hire_date, bio) VALUES
(1, 'E001', '山田 太郎', 'やまだ たろう', 'yamada@example.com', '営業部', 'シニアセールス', '2018-04-01', '法人営業10年以上の経験を持つ。大手顧客との関係構築が得意。'),
(2, 'E002', '佐藤 花子', 'さとう はなこ', 'sato@example.com', 'デザイン部', 'リードデザイナー', '2019-07-01', 'UI/UXデザインを専門とし、複数のプロダクトのデザインシステムを構築。'),
(3, 'E003', '鈴木 一郎', 'すずき いちろう', 'suzuki@example.com', 'エンジニアリング部', 'バックエンドエンジニア', '2020-01-15', 'PHP/Laravelを中心にWebシステム開発を担当。'),
(4, 'E004', '田中 美咲', 'たなか みさき', 'tanaka@example.com', 'マーケティング部', 'マーケティングマネージャー', '2017-10-01', 'デジタルマーケティング全般を統括。SNS運用、SEO施策を主導。'),
(5, 'E005', '伊藤 健二', 'いとう けんじ', 'ito@example.com', '営業部', 'セールスマネージャー', '2015-04-01', '営業部門を統括するマネージャー。チーム育成に定評がある。');

INSERT INTO team_members (team_id, employee_id) VALUES
(1, 1), (1, 5),
(2, 2),
(3, 3),
(4, 4);

UPDATE teams SET manager_id = 5 WHERE id = 1;
UPDATE teams SET manager_id = 2 WHERE id = 2;
UPDATE teams SET manager_id = 3 WHERE id = 3;
UPDATE teams SET manager_id = 4 WHERE id = 4;

INSERT INTO strengths_finder (employee_id, achiever, strategic, learner, maximizer, relator, futuristic, analytical, arranger, responsibility, positivity, top5_text, analysis) VALUES
(1, 1, 2, 3, 4, 5, 8, 12, 15, 6, 10,
'達成欲・戦略性・学習欲・最上志向・親密性',
'山田さんは高い達成欲と戦略的思考を持ち、目標に向けて着実に歩みを進めるタイプです。学習欲が強く、常に自己成長を追求します。親密性により、信頼関係の構築が得意で、長期的な顧客関係の維持に優れています。'),
(2, 10, 5, 8, 1, 15, 3, 20, 12, 18, 2,
'最上志向・未来志向・戦略性・学習欲・達成欲',
'佐藤さんは最上志向が強く、クオリティへのこだわりが人一倍です。未来志向と戦略性を組み合わせた長期的なビジョン設計が得意で、デザインシステムの構築など体系的な仕事に力を発揮します。');

INSERT INTO spi_results (employee_id, social_introversion, persistence, achievement_drive, leadership, teamwork, problem_solving, analysis) VALUES
(1, 3, 8, 9, 7, 6, 7,
'山田さんは達成意欲・持続性が高く、困難な状況でも粘り強く取り組む傾向があります。社会的外向性はやや低めですが、信頼関係を築いてからの深い関わりを好みます。リーダーシップは平均的で、チームワークを重視した協調型のスタイルです。'),
(2, 7, 7, 8, 5, 8, 9,
'佐藤さんは内省的で、じっくりと考えてから行動するタイプです。問題解決力とチームワークが高く、メンバーとの協働を通じて最高のアウトプットを出す傾向があります。創造的思考力が特に優れています。');

-- ========== sql/migration_v2.sql （既存・基底） ==========
-- ============================================================
-- bMS マルチテナント対応マイグレーション v2
-- ============================================================

-- 1. 会社テーブル作成
CREATE TABLE IF NOT EXISTS companies (
    id INT PRIMARY KEY AUTO_INCREMENT,
    login_id VARCHAR(50) NOT NULL UNIQUE COMMENT '企業識別ID',
    company_name VARCHAR(200) NOT NULL COMMENT '企業名',
    is_active TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. 統合ユーザーテーブル作成
CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL UNIQUE COMMENT 'ログインID',
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(100),
    role ENUM('super_admin', 'company_admin', 'employee') NOT NULL DEFAULT 'employee',
    company_id INT NULL COMMENT 'NULL=スーパー管理者',
    employee_id INT NULL COMMENT '社員ロールの場合のみ',
    is_active TINYINT DEFAULT 1,
    last_login_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. employees に company_id 追加
ALTER TABLE employees ADD COLUMN company_id INT NULL AFTER id;

-- 4. teams に company_id 追加
ALTER TABLE teams ADD COLUMN company_id INT NULL AFTER id;

-- 5. consultation_history に company_id 追加
ALTER TABLE consultation_history ADD COLUMN company_id INT NULL AFTER id;

-- 6. デフォルト会社を作成し、既存データを紐付け
INSERT INTO companies (id, login_id, company_name) VALUES (1, 'default', '既存データ（デフォルト）')
ON DUPLICATE KEY UPDATE company_name = company_name;

UPDATE employees SET company_id = 1 WHERE company_id IS NULL;
UPDATE teams SET company_id = 1 WHERE company_id IS NULL;
UPDATE consultation_history SET company_id = 1 WHERE company_id IS NULL;

-- 7. FK追加（既存データ紐付け後）
-- ALTER TABLE employees ADD CONSTRAINT fk_employees_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE;
-- ALTER TABLE teams ADD CONSTRAINT fk_teams_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE;
-- ALTER TABLE consultation_history ADD CONSTRAINT fk_consultation_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE;

-- 8. admin_users の既存データを users テーブルに移行
INSERT INTO users (username, password_hash, display_name, role, company_id)
SELECT username, password_hash, display_name, 'super_admin', NULL
FROM admin_users
ON DUPLICATE KEY UPDATE display_name = VALUES(display_name);

-- 9. UNIQUE制約変更（会社単位でユニーク）
ALTER TABLE employees DROP INDEX IF EXISTS employee_number;
ALTER TABLE employees DROP INDEX IF EXISTS email;
-- 再作成（company_id + employee_number / email でユニーク）
-- NOTE: NULLのcompany_idがある場合はユニーク制約が効かないため、全データにcompany_idを設定した後に実行
CREATE UNIQUE INDEX idx_emp_company_number ON employees(company_id, employee_number);
CREATE UNIQUE INDEX idx_emp_company_email ON employees(company_id, email);

-- 10. インデックス追加
CREATE INDEX IF NOT EXISTS idx_employees_company ON employees(company_id);
CREATE INDEX IF NOT EXISTS idx_teams_company ON teams(company_id);
CREATE INDEX IF NOT EXISTS idx_users_company ON users(company_id);
CREATE INDEX IF NOT EXISTS idx_users_role ON users(role);
CREATE INDEX IF NOT EXISTS idx_consultation_company ON consultation_history(company_id);

-- ========== sql/migration_v3_evaluation.sql （既存・基底） ==========
-- ============================================================
-- bMS 人事評価システム マイグレーション v3
-- ============================================================

-- 1. 評価期間
CREATE TABLE IF NOT EXISTS eval_periods (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    name VARCHAR(100) NOT NULL COMMENT '例: 2026年上期',
    fiscal_year INT NOT NULL,
    half ENUM('first','second') NOT NULL COMMENT '上期/下期',
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status ENUM('draft','open','self_eval','primary_eval','adjustment','feedback','closed') NOT NULL DEFAULT 'draft',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ep_company (company_id),
    INDEX idx_ep_status (company_id, status),
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. 部署別ウェイト
CREATE TABLE IF NOT EXISTS eval_axis_weights (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    department_key VARCHAR(50) NOT NULL,
    department_label VARCHAR(100) NOT NULL,
    weight_performance INT NOT NULL DEFAULT 40,
    weight_action INT NOT NULL DEFAULT 40,
    weight_competency INT NOT NULL DEFAULT 20,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_company_dept (company_id, department_key),
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. 業績KPI項目
CREATE TABLE IF NOT EXISTS eval_performance_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    period_id INT NOT NULL,
    department_key VARCHAR(50) NULL COMMENT 'NULL=全部門共通',
    name VARCHAR(200) NOT NULL,
    unit VARCHAR(30) NOT NULL DEFAULT '円',
    weight INT NOT NULL DEFAULT 100,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_epi_period (period_id, department_key),
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (period_id) REFERENCES eval_periods(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. 行動チェック項目
CREATE TABLE IF NOT EXISTS eval_action_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    period_id INT NOT NULL,
    department_key VARCHAR(50) NULL,
    name VARCHAR(200) NOT NULL,
    target_value DECIMAL(10,2) NULL,
    target_unit VARCHAR(30) NULL,
    frequency ENUM('daily','weekly','monthly','half') DEFAULT 'half',
    weight INT NOT NULL DEFAULT 100,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_eai_period (period_id, department_key),
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (period_id) REFERENCES eval_periods(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. コンピテンシー項目
CREATE TABLE IF NOT EXISTS eval_competency_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    name VARCHAR(200) NOT NULL,
    description TEXT,
    level1_desc VARCHAR(500),
    level2_desc VARCHAR(500),
    level3_desc VARCHAR(500),
    level4_desc VARCHAR(500),
    level5_desc VARCHAR(500),
    weight INT NOT NULL DEFAULT 100,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_eci_company (company_id),
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. 評価シート（社員×期間）
CREATE TABLE IF NOT EXISTS eval_sheets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    period_id INT NOT NULL,
    employee_id INT NOT NULL,
    evaluator_id INT NULL COMMENT '1次評価者',
    department_key VARCHAR(50) NOT NULL,
    status ENUM('draft','self_submitted','primary_submitted','adjusted','feedback_done') NOT NULL DEFAULT 'draft',
    self_score_performance DECIMAL(5,2) NULL,
    self_score_action DECIMAL(5,2) NULL,
    self_score_competency DECIMAL(5,2) NULL,
    self_score_total DECIMAL(5,2) NULL,
    primary_score_performance DECIMAL(5,2) NULL,
    primary_score_action DECIMAL(5,2) NULL,
    primary_score_competency DECIMAL(5,2) NULL,
    primary_score_total DECIMAL(5,2) NULL,
    final_score_performance DECIMAL(5,2) NULL,
    final_score_action DECIMAL(5,2) NULL,
    final_score_competency DECIMAL(5,2) NULL,
    final_score_total DECIMAL(5,2) NULL,
    final_grade VARCHAR(10) NULL COMMENT 'S/A/B/C/D',
    self_comment TEXT,
    primary_comment TEXT,
    adjustment_comment TEXT,
    feedback_comment TEXT,
    next_goals TEXT,
    ai_analysis TEXT,
    ai_training_suggestion TEXT,
    self_submitted_at TIMESTAMP NULL,
    primary_submitted_at TIMESTAMP NULL,
    adjusted_at TIMESTAMP NULL,
    feedback_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_period_employee (period_id, employee_id),
    INDEX idx_es_company (company_id),
    INDEX idx_es_evaluator (evaluator_id),
    INDEX idx_es_status (company_id, period_id, status),
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (period_id) REFERENCES eval_periods(id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (evaluator_id) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. 業績スコア
CREATE TABLE IF NOT EXISTS eval_performance_scores (
    id INT PRIMARY KEY AUTO_INCREMENT,
    sheet_id INT NOT NULL,
    item_id INT NOT NULL,
    target_value DECIMAL(15,2) NULL,
    actual_value DECIMAL(15,2) NULL,
    achievement_rate DECIMAL(5,2) NULL,
    self_score DECIMAL(5,2) NULL,
    primary_score DECIMAL(5,2) NULL,
    final_score DECIMAL(5,2) NULL,
    self_comment TEXT,
    primary_comment TEXT,
    INDEX idx_eps_sheet (sheet_id),
    FOREIGN KEY (sheet_id) REFERENCES eval_sheets(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES eval_performance_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. 行動スコア
CREATE TABLE IF NOT EXISTS eval_action_scores (
    id INT PRIMARY KEY AUTO_INCREMENT,
    sheet_id INT NOT NULL,
    item_id INT NOT NULL,
    actual_value DECIMAL(10,2) NULL,
    is_achieved TINYINT DEFAULT 0,
    achievement_rate DECIMAL(5,2) NULL,
    self_score DECIMAL(5,2) NULL,
    primary_score DECIMAL(5,2) NULL,
    final_score DECIMAL(5,2) NULL,
    self_comment TEXT,
    primary_comment TEXT,
    INDEX idx_eas_sheet (sheet_id),
    FOREIGN KEY (sheet_id) REFERENCES eval_sheets(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES eval_action_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. コンピテンシースコア
CREATE TABLE IF NOT EXISTS eval_competency_scores (
    id INT PRIMARY KEY AUTO_INCREMENT,
    sheet_id INT NOT NULL,
    item_id INT NOT NULL,
    self_level TINYINT NULL,
    self_comment TEXT,
    primary_level TINYINT NULL,
    primary_comment TEXT,
    final_level TINYINT NULL,
    INDEX idx_ecs_sheet (sheet_id),
    FOREIGN KEY (sheet_id) REFERENCES eval_sheets(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES eval_competency_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. 褒めポイント
CREATE TABLE IF NOT EXISTS praise_points (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    employee_id INT NOT NULL,
    author_id INT NOT NULL,
    memo VARCHAR(500) NOT NULL,
    category VARCHAR(50) NULL,
    praised_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pp_employee (employee_id, praised_date),
    INDEX idx_pp_company (company_id),
    INDEX idx_pp_author (author_id),
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (author_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. 研修マスタ
CREATE TABLE IF NOT EXISTS training_catalog (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    name VARCHAR(200) NOT NULL,
    description TEXT,
    category VARCHAR(50) NOT NULL DEFAULT 'general',
    duration_hours DECIMAL(4,1) NULL,
    url VARCHAR(500) NULL,
    is_active TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tc_company (company_id),
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. 研修推奨ルール
CREATE TABLE IF NOT EXISTS training_rules (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    training_id INT NOT NULL,
    trigger_axis ENUM('performance','action','competency') NOT NULL,
    trigger_item_keyword VARCHAR(200) NULL,
    trigger_condition ENUM('below_threshold','low_achievement') NOT NULL DEFAULT 'below_threshold',
    threshold_value DECIMAL(5,2) NOT NULL DEFAULT 60.00,
    priority INT DEFAULT 0,
    is_active TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tr_company (company_id),
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (training_id) REFERENCES training_catalog(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. 研修推奨結果
CREATE TABLE IF NOT EXISTS training_recommendations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    sheet_id INT NOT NULL,
    training_id INT NOT NULL,
    rule_id INT NULL,
    reason TEXT,
    status ENUM('suggested','accepted','completed','declined') DEFAULT 'suggested',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_trec_sheet (sheet_id),
    FOREIGN KEY (sheet_id) REFERENCES eval_sheets(id) ON DELETE CASCADE,
    FOREIGN KEY (training_id) REFERENCES training_catalog(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ========== companies: ロゴ列追加 ==========
ALTER TABLE companies ADD COLUMN logo_path VARCHAR(300) NULL AFTER company_name;

-- ========== employees: オンボーディング/給与列追加 ==========
ALTER TABLE employees ADD COLUMN gender ENUM('male','female') NULL COMMENT '性別' AFTER name_kana;
ALTER TABLE employees ADD COLUMN postal_code VARCHAR(10) NULL COMMENT '郵便番号' AFTER birth_date;
ALTER TABLE employees ADD COLUMN address VARCHAR(300) NULL COMMENT '現住所' AFTER postal_code;
ALTER TABLE employees ADD COLUMN address_kana VARCHAR(300) NULL COMMENT '現住所（フリガナ）' AFTER address;
ALTER TABLE employees ADD COLUMN phone VARCHAR(20) NULL COMMENT '電話番号' AFTER address_kana;
ALTER TABLE employees ADD COLUMN my_number VARCHAR(12) NULL COMMENT '個人番号（マイナンバー）' AFTER phone;
ALTER TABLE employees ADD COLUMN pension_number VARCHAR(30) NULL COMMENT '基礎年金番号' AFTER my_number;
ALTER TABLE employees ADD COLUMN insurance_number VARCHAR(30) NULL COMMENT '被保険者番号' AFTER pension_number;
ALTER TABLE employees ADD COLUMN has_insurance_card TINYINT DEFAULT 0 COMMENT '被保険者証 有無' AFTER insurance_number;
ALTER TABLE employees ADD COLUMN salary_type ENUM('monthly','daily','hourly') DEFAULT 'monthly' COMMENT '給与形態' AFTER has_insurance_card;
ALTER TABLE employees ADD COLUMN monthly_salary INT NULL COMMENT '1ヶ月の給与総額' AFTER salary_type;
ALTER TABLE employees ADD COLUMN base_pay INT NULL COMMENT '基本給' AFTER monthly_salary;
ALTER TABLE employees ADD COLUMN allowance1_name VARCHAR(50) NULL COMMENT '手当1名' AFTER base_pay;
ALTER TABLE employees ADD COLUMN allowance1_amount INT NULL COMMENT '手当1額' AFTER allowance1_name;
ALTER TABLE employees ADD COLUMN allowance2_name VARCHAR(50) NULL COMMENT '手当2名' AFTER allowance1_amount;
ALTER TABLE employees ADD COLUMN allowance2_amount INT NULL COMMENT '手当2額' AFTER allowance2_name;
ALTER TABLE employees ADD COLUMN allowance3_name VARCHAR(50) NULL COMMENT '手当3名' AFTER allowance2_amount;
ALTER TABLE employees ADD COLUMN allowance3_amount INT NULL COMMENT '手当3額' AFTER allowance3_name;
ALTER TABLE employees ADD COLUMN commute_allowance INT NULL COMMENT '通勤手当' AFTER allowance3_amount;
ALTER TABLE employees ADD COLUMN bank_name VARCHAR(100) NULL COMMENT '銀行名' AFTER commute_allowance;
ALTER TABLE employees ADD COLUMN bank_branch VARCHAR(100) NULL COMMENT '支店名' AFTER bank_name;
ALTER TABLE employees ADD COLUMN bank_account_type ENUM('ordinary','current') DEFAULT 'ordinary' COMMENT '口座種別' AFTER bank_branch;
ALTER TABLE employees ADD COLUMN bank_account_number VARCHAR(20) NULL COMMENT '口座番号' AFTER bank_account_type;
ALTER TABLE employees ADD COLUMN employment_type VARCHAR(30) NULL COMMENT '雇用形態（自社/アライアンス）' AFTER is_active;
ALTER TABLE employees ADD COLUMN employment_subtype VARCHAR(30) NULL COMMENT '雇用区分（社員/外注/アルバイト）' AFTER employment_type;
ALTER TABLE employees ADD COLUMN work_style VARCHAR(30) NULL COMMENT '勤務形態（常勤/イベント）' AFTER employment_subtype;
ALTER TABLE employees ADD COLUMN retirement_date DATE NULL COMMENT '退職日' AFTER hire_date;
ALTER TABLE employees ADD COLUMN skills_json TEXT NULL COMMENT 'スキル（JSON配列）' AFTER bio;


-- ========== 追加テーブル（売上/給与/EVP/SPI・SF/オンボーディング） ==========

-- grade_table
CREATE TABLE IF NOT EXISTS grade_table (
        id INT PRIMARY KEY AUTO_INCREMENT,
        company_id INT NOT NULL,
        grade_rank VARCHAR(10) NOT NULL COMMENT '等級 (G1,G2,...)',
        grade_label VARCHAR(50) NOT NULL COMMENT '等級名 (一般1, 主任, 係長...)',
        step INT NOT NULL DEFAULT 1 COMMENT '号俸',
        base_salary INT NOT NULL DEFAULT 0 COMMENT '基本給（月額）',
        sort_order INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_grade_step (company_id, grade_rank, step),
        INDEX idx_gt_company (company_id),
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- employee_grades
CREATE TABLE IF NOT EXISTS employee_grades (
        id INT PRIMARY KEY AUTO_INCREMENT,
        company_id INT NOT NULL,
        employee_id INT NOT NULL,
        grade_rank VARCHAR(10) NOT NULL,
        step INT NOT NULL DEFAULT 1,
        effective_date DATE NOT NULL,
        reason VARCHAR(200) NULL COMMENT '昇格理由',
        eval_grade VARCHAR(10) NULL COMMENT '評価等級(S/A/B/C/D)',
        period_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_eg_employee (employee_id, effective_date DESC),
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- bonus_settings
CREATE TABLE IF NOT EXISTS bonus_settings (
        id INT PRIMARY KEY AUTO_INCREMENT,
        company_id INT NOT NULL,
        period_id INT NULL,
        base_months DECIMAL(3,1) NOT NULL DEFAULT 2.0 COMMENT '基準月数',
        company_performance_index DECIMAL(4,2) NOT NULL DEFAULT 1.00 COMMENT '会社業績指数',
        min_guarantee_rate DECIMAL(4,2) NOT NULL DEFAULT 0.50 COMMENT '最低保証率',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_bs_company (company_id),
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- salary_rules
CREATE TABLE IF NOT EXISTS salary_rules (
        id INT PRIMARY KEY AUTO_INCREMENT,
        company_id INT NOT NULL,
        eval_grade VARCHAR(10) NOT NULL COMMENT '評価等級(S/A/B/C/D)',
        step_change INT NOT NULL DEFAULT 0 COMMENT '号俸変動 (+2,+1,0,-1)',
        bonus_coefficient DECIMAL(4,2) NOT NULL DEFAULT 1.00 COMMENT '賞与係数',
        description VARCHAR(200) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_sr_grade (company_id, eval_grade),
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- salary_simulations
CREATE TABLE IF NOT EXISTS salary_simulations (
        id INT PRIMARY KEY AUTO_INCREMENT,
        company_id INT NOT NULL,
        period_id INT NOT NULL,
        employee_id INT NOT NULL,
        sheet_id INT NULL,
        current_grade_rank VARCHAR(10) NULL,
        current_step INT NULL,
        current_salary INT NULL,
        eval_grade VARCHAR(10) NULL,
        new_grade_rank VARCHAR(10) NULL,
        new_step INT NULL,
        new_salary INT NULL,
        salary_diff INT NULL,
        bonus_base INT NULL COMMENT '基準賞与額',
        bonus_coefficient DECIMAL(4,2) NULL,
        bonus_amount INT NULL COMMENT '算出賞与額',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ss_period (company_id, period_id),
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- interview_notes
CREATE TABLE IF NOT EXISTS interview_notes (
        id INT PRIMARY KEY AUTO_INCREMENT,
        company_id INT NOT NULL,
        employee_id INT NOT NULL,
        interviewer_id INT NOT NULL,
        note_type ENUM('one_on_one','mid_term','end_term','career','other') NOT NULL DEFAULT 'one_on_one',
        interview_date DATE NOT NULL,
        positives TEXT COMMENT '今期のできたこと',
        challenges TEXT COMMENT '課題と原因',
        challenge_causes TEXT COMMENT '原因の深掘り',
        action_plan TEXT COMMENT '次期のアクションプラン',
        career_aspiration TEXT COMMENT 'キャリア・意欲',
        manager_memo TEXT COMMENT '管理者メモ（昇給・昇進に向けた課題等）',
        mood TINYINT NULL COMMENT '面談時の印象 1-5',
        period_id INT NULL,
        is_private TINYINT DEFAULT 0 COMMENT '管理者のみ閲覧',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_in_employee (employee_id, interview_date DESC),
        INDEX idx_in_interviewer (interviewer_id),
        INDEX idx_in_company (company_id),
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
        FOREIGN KEY (interviewer_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- training_assignments
CREATE TABLE IF NOT EXISTS training_assignments (
        id INT PRIMARY KEY AUTO_INCREMENT,
        company_id INT NOT NULL,
        employee_id INT NOT NULL,
        training_id INT NOT NULL,
        assigned_by INT NOT NULL,
        reason TEXT COMMENT '割当理由',
        due_date DATE NULL,
        status ENUM('assigned','in_progress','completed','declined') DEFAULT 'assigned',
        completed_at TIMESTAMP NULL,
        interview_note_id INT NULL COMMENT '面談メモから割り当てた場合',
        sheet_id INT NULL COMMENT '評価シートから割り当てた場合',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ta_employee (employee_id, status),
        INDEX idx_ta_company (company_id),
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
        FOREIGN KEY (training_id) REFERENCES training_catalog(id) ON DELETE CASCADE,
        FOREIGN KEY (assigned_by) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- spi_questions
CREATE TABLE IF NOT EXISTS spi_questions (
        id INT PRIMARY KEY AUTO_INCREMENT,
        dimension_key VARCHAR(50) NOT NULL COMMENT 'SPI次元キー',
        category VARCHAR(30) NOT NULL COMMENT 'behavioral/motivational/emotional/social/workplace',
        question_text TEXT NOT NULL COMMENT '質問文',
        is_reverse TINYINT NOT NULL DEFAULT 0 COMMENT '逆転項目フラグ',
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_sq_dimension (dimension_key),
        INDEX idx_sq_category (category)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- spi_attempts
CREATE TABLE IF NOT EXISTS spi_attempts (
        id INT PRIMARY KEY AUTO_INCREMENT,
        company_id INT NULL,
        employee_id INT NOT NULL,
        status ENUM('in_progress','completed') NOT NULL DEFAULT 'in_progress',
        started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        completed_at TIMESTAMP NULL,
        INDEX idx_sa_employee (employee_id, status),
        INDEX idx_sa_company (company_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- spi_answers
CREATE TABLE IF NOT EXISTS spi_answers (
        id INT PRIMARY KEY AUTO_INCREMENT,
        company_id INT NULL,
        employee_id INT NOT NULL,
        attempt_id INT NOT NULL,
        question_id INT NOT NULL,
        answer TINYINT NOT NULL COMMENT '1-5のリッカート尺度',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_spi_ans (attempt_id, question_id),
        INDEX idx_spia_employee (employee_id),
        FOREIGN KEY (attempt_id) REFERENCES spi_attempts(id) ON DELETE CASCADE,
        FOREIGN KEY (question_id) REFERENCES spi_questions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- sf_questions
CREATE TABLE IF NOT EXISTS sf_questions (
        id INT PRIMARY KEY AUTO_INCREMENT,
        theme_key VARCHAR(50) NOT NULL COMMENT 'SFテーマキー',
        domain VARCHAR(30) NOT NULL COMMENT '実行力/影響力/人間関係力/戦略的思考力',
        question_text TEXT NOT NULL COMMENT '質問文',
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_sfq_theme (theme_key),
        INDEX idx_sfq_domain (domain)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- sf_attempts
CREATE TABLE IF NOT EXISTS sf_attempts (
        id INT PRIMARY KEY AUTO_INCREMENT,
        company_id INT NULL,
        employee_id INT NOT NULL,
        status ENUM('in_progress','completed') NOT NULL DEFAULT 'in_progress',
        started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        completed_at TIMESTAMP NULL,
        INDEX idx_sfa_employee (employee_id, status),
        INDEX idx_sfa_company (company_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- sf_answers
CREATE TABLE IF NOT EXISTS sf_answers (
        id INT PRIMARY KEY AUTO_INCREMENT,
        company_id INT NULL,
        employee_id INT NOT NULL,
        attempt_id INT NOT NULL,
        question_id INT NOT NULL,
        answer TINYINT NOT NULL COMMENT '1-5のリッカート尺度',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_sf_ans (attempt_id, question_id),
        INDEX idx_sfan_employee (employee_id),
        FOREIGN KEY (attempt_id) REFERENCES sf_attempts(id) ON DELETE CASCADE,
        FOREIGN KEY (question_id) REFERENCES sf_questions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- sales_clients
CREATE TABLE IF NOT EXISTS sales_clients (
        id INT PRIMARY KEY AUTO_INCREMENT,
        company_id INT NOT NULL,
        client_name VARCHAR(100) NOT NULL,
        client_code VARCHAR(20) DEFAULT NULL,
        contact_person VARCHAR(100) DEFAULT NULL,
        phone VARCHAR(30) DEFAULT NULL,
        note TEXT DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_client (company_id, client_name),
        INDEX idx_sc_company (company_id),
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- sales_alliances
CREATE TABLE IF NOT EXISTS sales_alliances (
        id INT PRIMARY KEY AUTO_INCREMENT,
        company_id INT NOT NULL,
        alliance_name VARCHAR(100) NOT NULL,
        alliance_type ENUM('アライアンス','個人外注') NOT NULL DEFAULT 'アライアンス',
        contact_person VARCHAR(100) DEFAULT NULL,
        phone VARCHAR(30) DEFAULT NULL,
        note TEXT DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_alliance (company_id, alliance_name),
        INDEX idx_sa_company (company_id),
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- sales_store_brands
CREATE TABLE IF NOT EXISTS sales_store_brands (
        id INT PRIMARY KEY AUTO_INCREMENT,
        company_id INT NOT NULL,
        brand_name VARCHAR(100) NOT NULL,
        brand_code VARCHAR(10) DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_brand (company_id, brand_name),
        INDEX idx_sb_company (company_id),
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- sales_areas
CREATE TABLE IF NOT EXISTS sales_areas (
        id INT PRIMARY KEY AUTO_INCREMENT,
        company_id INT NOT NULL,
        area_name VARCHAR(100) NOT NULL,
        region VARCHAR(50) DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_area (company_id, area_name),
        INDEX idx_sarea_company (company_id),
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- sales_workers
CREATE TABLE IF NOT EXISTS sales_workers (
        id INT PRIMARY KEY AUTO_INCREMENT,
        company_id INT NOT NULL,
        worker_name VARCHAR(100) NOT NULL,
        worker_type ENUM('正社員','自社外注','アライアンス','個人外注','アルバイト') NOT NULL DEFAULT '正社員',
        alliance_id INT DEFAULT NULL,
        employee_id INT DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_sw_company (company_id),
        INDEX idx_sw_alliance (alliance_id),
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
        FOREIGN KEY (alliance_id) REFERENCES sales_alliances(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- sales_targets
CREATE TABLE IF NOT EXISTS sales_targets (
        id INT PRIMARY KEY AUTO_INCREMENT,
        company_id INT NOT NULL,
        target_year INT NOT NULL,
        target_month INT NOT NULL,
        target_type ENUM('total','regular','event') NOT NULL DEFAULT 'total',
        revenue_target BIGINT NOT NULL DEFAULT 0,
        profit_target BIGINT NOT NULL DEFAULT 0,
        note TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_target (company_id, target_year, target_month, target_type),
        INDEX idx_st_company (company_id),
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- sales_cases
CREATE TABLE IF NOT EXISTS sales_cases (
        id INT PRIMARY KEY AUTO_INCREMENT,
        company_id INT NOT NULL,
        case_type ENUM('event','regular') NOT NULL,
        case_year INT NOT NULL,
        case_month INT NOT NULL,
        client_id INT DEFAULT NULL,
        sales_rep VARCHAR(100) DEFAULT NULL,
        manager VARCHAR(100) DEFAULT NULL,
        recruiter VARCHAR(100) DEFAULT NULL,
        worker_type ENUM('正社員','自社外注','アライアンス','個人外注','アルバイト') NOT NULL DEFAULT '正社員',
        alliance_id INT DEFAULT NULL,
        worker_id INT DEFAULT NULL,
        worker_name VARCHAR(100) DEFAULT NULL,
        store_brand_id INT DEFAULT NULL,
        area_id INT DEFAULT NULL,
        store_name VARCHAR(200) DEFAULT NULL,
        start_date DATE DEFAULT NULL,
        end_date DATE DEFAULT NULL,
        unit_price_in DECIMAL(12,2) NOT NULL DEFAULT 0,
        unit_price_out DECIMAL(12,2) NOT NULL DEFAULT 0,
        days_worked DECIMAL(6,2) NOT NULL DEFAULT 0,
        revenue BIGINT NOT NULL DEFAULT 0,
        cost BIGINT NOT NULL DEFAULT 0,
        gross_profit BIGINT NOT NULL DEFAULT 0,
        margin DECIMAL(8,4) NOT NULL DEFAULT 0,
        status ENUM('draft','confirmed','cancelled') NOT NULL DEFAULT 'confirmed',
        note TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_cases_company (company_id),
        INDEX idx_cases_type_month (company_id, case_type, case_year, case_month),
        INDEX idx_cases_client (client_id),
        INDEX idx_cases_worker (worker_id),
        INDEX idx_cases_status (company_id, status),
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
        FOREIGN KEY (client_id) REFERENCES sales_clients(id) ON DELETE SET NULL,
        FOREIGN KEY (alliance_id) REFERENCES sales_alliances(id) ON DELETE SET NULL,
        FOREIGN KEY (worker_id) REFERENCES sales_workers(id) ON DELETE SET NULL,
        FOREIGN KEY (store_brand_id) REFERENCES sales_store_brands(id) ON DELETE SET NULL,
        FOREIGN KEY (area_id) REFERENCES sales_areas(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE sales_cases ADD COLUMN carrier VARCHAR(50) NULL COMMENT 'キャリア' AFTER note;
ALTER TABLE sales_cases ADD COLUMN new_transactions INT NOT NULL DEFAULT 0 COMMENT '新規件数' AFTER carrier;
ALTER TABLE sales_cases ADD COLUMN negotiations_count INT NOT NULL DEFAULT 0 COMMENT '商談件数' AFTER new_transactions;
ALTER TABLE sales_cases ADD COLUMN contracts_count INT NOT NULL DEFAULT 0 COMMENT '契約件数' AFTER negotiations_count;

-- sales_transport_costs
CREATE TABLE IF NOT EXISTS sales_transport_costs (
        id INT PRIMARY KEY AUTO_INCREMENT,
        company_id INT NOT NULL,
        employee_name VARCHAR(100) NOT NULL,
        target_year INT NOT NULL,
        target_month INT NOT NULL,
        total_amount INT NOT NULL DEFAULT 0,
        evidence_url_1 VARCHAR(500) DEFAULT NULL,
        distance_km_1 DECIMAL(8,1) DEFAULT NULL,
        work_days_1 INT DEFAULT NULL,
        cost_1 INT DEFAULT NULL,
        evidence_url_2 VARCHAR(500) DEFAULT NULL,
        distance_km_2 DECIMAL(8,1) DEFAULT NULL,
        work_days_2 INT DEFAULT NULL,
        cost_2 INT DEFAULT NULL,
        evidence_url_3 VARCHAR(500) DEFAULT NULL,
        distance_km_3 DECIMAL(8,1) DEFAULT NULL,
        work_days_3 INT DEFAULT NULL,
        cost_3 INT DEFAULT NULL,
        highway_cost INT NOT NULL DEFAULT 0,
        submitted_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_transport (company_id, employee_name, target_year, target_month),
        INDEX idx_tc_company (company_id),
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- sales_personal_invoices
CREATE TABLE IF NOT EXISTS sales_personal_invoices (
        id INT PRIMARY KEY AUTO_INCREMENT,
        company_id INT NOT NULL,
        invoice_year INT NOT NULL,
        invoice_month INT NOT NULL,
        employee_name VARCHAR(100) NOT NULL,
        grade VARCHAR(10) DEFAULT NULL,
        alliance_type VARCHAR(30) DEFAULT NULL,
        alliance_name VARCHAR(100) DEFAULT NULL,
        base_fee INT NOT NULL DEFAULT 0,
        transport_cost INT NOT NULL DEFAULT 0,
        transport_cost_client INT NOT NULL DEFAULT 0,
        shift_days INT NOT NULL DEFAULT 0,
        actual_work_days INT NOT NULL DEFAULT 0,
        client_ladder INT NOT NULL DEFAULT 0,
        agency_ladder INT NOT NULL DEFAULT 0,
        extra_work_revenue INT NOT NULL DEFAULT 0,
        absence_deduction INT NOT NULL DEFAULT 0,
        other_charges INT NOT NULL DEFAULT 0,
        subtotal_fee INT NOT NULL DEFAULT 0,
        event_fee INT NOT NULL DEFAULT 0,
        sales_incentive INT NOT NULL DEFAULT 0,
        mgmt_incentive INT NOT NULL DEFAULT 0,
        recruit_incentive INT NOT NULL DEFAULT 0,
        role_allowance INT NOT NULL DEFAULT 0,
        incentive_total INT NOT NULL DEFAULT 0,
        welfare INT NOT NULL DEFAULT 0,
        social_insurance INT NOT NULL DEFAULT 0,
        invoice_amount INT NOT NULL DEFAULT 0,
        end_date DATE DEFAULT NULL,
        note TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_pinvoice (company_id, invoice_year, invoice_month, employee_name),
        INDEX idx_pi_company (company_id),
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- sales_company_invoices
CREATE TABLE IF NOT EXISTS sales_company_invoices (
        id INT PRIMARY KEY AUTO_INCREMENT,
        company_id INT NOT NULL,
        client_id INT DEFAULT NULL,
        client_name VARCHAR(100) NOT NULL,
        invoice_year INT NOT NULL,
        invoice_month INT NOT NULL,
        base_revenue INT NOT NULL DEFAULT 0,
        extra_revenue INT NOT NULL DEFAULT 0,
        total_revenue INT NOT NULL DEFAULT 0,
        note TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_cinvoice (company_id, client_name, invoice_year, invoice_month),
        INDEX idx_ci_company (company_id),
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- sales_shifts
CREATE TABLE IF NOT EXISTS sales_shifts (
        id INT PRIMARY KEY AUTO_INCREMENT,
        company_id INT NOT NULL,
        employee_name VARCHAR(100) NOT NULL,
        shift_date DATE NOT NULL,
        shift_year INT NOT NULL,
        shift_month INT NOT NULL,
        scheduled_time VARCHAR(25) DEFAULT NULL COMMENT '表示用(start_time~end_time)',
        start_time VARCHAR(10) DEFAULT NULL COMMENT '出勤予定時間',
        end_time VARCHAR(10) DEFAULT NULL COMMENT '退勤予定時間',
        is_day_off TINYINT(1) DEFAULT 0 COMMENT '休みフラグ',
        checkin_time VARCHAR(10) DEFAULT NULL,
        checkout_time VARCHAR(10) DEFAULT NULL COMMENT '退勤時刻',
        attendance_status ENUM('出勤','欠勤','早退','遅刻') DEFAULT NULL COMMENT '出退勤報告ステータス',
        report_status VARCHAR(5) DEFAULT '',
        location VARCHAR(100) DEFAULT NULL,
        note TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_shift (company_id, employee_name, shift_date),
        INDEX idx_shift_ym (company_id, shift_year, shift_month),
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- sales_change_requests（一般ユーザーからのシフト変更・出退勤時間変更申請）
CREATE TABLE IF NOT EXISTS sales_change_requests (
        id INT PRIMARY KEY AUTO_INCREMENT,
        company_id INT NOT NULL,
        employee_name VARCHAR(100) NOT NULL,
        request_type ENUM('shift_change','attendance_change') NOT NULL,
        target_date DATE NOT NULL,
        current_value VARCHAR(100) DEFAULT NULL,
        requested_value VARCHAR(100) NOT NULL,
        reason TEXT DEFAULT NULL,
        status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        reviewed_by VARCHAR(100) DEFAULT NULL,
        reviewed_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_cr_company_status (company_id, status),
        INDEX idx_cr_employee (company_id, employee_name),
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- sales_daily_reports
CREATE TABLE IF NOT EXISTS sales_daily_reports (
        id INT PRIMARY KEY AUTO_INCREMENT,
        company_id INT NOT NULL,
        employee_name VARCHAR(100) NOT NULL,
        work_date DATE NOT NULL,
        location VARCHAR(100) DEFAULT NULL,
        carrier VARCHAR(50) DEFAULT NULL,
        contacts INT NOT NULL DEFAULT 0,
        consultations INT NOT NULL DEFAULT 0,
        seated INT NOT NULL DEFAULT 0,
        sb_mnp INT NOT NULL DEFAULT 0,
        sb_new INT NOT NULL DEFAULT 0,
        sb_change INT NOT NULL DEFAULT 0,
        sb_upgrade INT NOT NULL DEFAULT 0,
        ym_mnp INT NOT NULL DEFAULT 0,
        ym_new INT NOT NULL DEFAULT 0,
        ym_change INT NOT NULL DEFAULT 0,
        ym_downgrade INT NOT NULL DEFAULT 0,
        sb_hikari INT NOT NULL DEFAULT 0,
        sb_air INT NOT NULL DEFAULT 0,
        ouchi_denwa INT NOT NULL DEFAULT 0,
        paypay_card INT NOT NULL DEFAULT 0,
        ouchi_denki INT NOT NULL DEFAULT 0,
        selection_amount INT NOT NULL DEFAULT 0,
        acquisition_points INT NOT NULL DEFAULT 0,
        au_mnp INT NOT NULL DEFAULT 0,
        au_new INT NOT NULL DEFAULT 0,
        au_change INT NOT NULL DEFAULT 0,
        au_upgrade INT NOT NULL DEFAULT 0,
        uq_mnp INT NOT NULL DEFAULT 0,
        uq_new INT NOT NULL DEFAULT 0,
        uq_change INT NOT NULL DEFAULT 0,
        uq_downgrade INT NOT NULL DEFAULT 0,
        note TEXT DEFAULT NULL,
        submitted_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_report (company_id, employee_name, work_date),
        INDEX idx_dr_ym (company_id, work_date),
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- sales_attendance
CREATE TABLE IF NOT EXISTS sales_attendance (
        id INT PRIMARY KEY AUTO_INCREMENT,
        company_id INT NOT NULL,
        employee_name VARCHAR(100) NOT NULL,
        work_date DATE NOT NULL,
        checkin_time VARCHAR(10) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_attendance (company_id, employee_name, work_date),
        INDEX idx_att_date (company_id, work_date),
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- salary_ladder
CREATE TABLE IF NOT EXISTS salary_ladder (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    department_key VARCHAR(50) NOT NULL COMMENT '事業部キー',
    ladder_name VARCHAR(100) NOT NULL COMMENT 'ラダー名（ショップラダー等）',
    grade_name VARCHAR(50) NOT NULL COMMENT '等級名（ゴールド/シルバー/ブロンズ）',
    grade_level INT NOT NULL COMMENT '等級内の段階（1=最上位）',
    sales_threshold INT NOT NULL DEFAULT 0 COMMENT '月売上閾値（円）',
    salary INT NOT NULL COMMENT '月給（円）',
    grade_color VARCHAR(20) DEFAULT NULL COMMENT '表示色',
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_ladder (company_id, department_key, ladder_name, grade_name, grade_level),
    INDEX idx_sl_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- evp_grades
CREATE TABLE IF NOT EXISTS evp_grades (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    grade_key VARCHAR(50) NOT NULL,
    grade_name VARCHAR(100) NOT NULL,
    grade_pay INT NOT NULL DEFAULT 0 COMMENT '等級給（月額）',
    sort_order INT DEFAULT 0,
    promotion_condition TEXT COMMENT '昇格条件',
    demotion_condition TEXT COMMENT '降格条件',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_cg (company_id, grade_key),
    INDEX idx_evpg_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- evp_positions
CREATE TABLE IF NOT EXISTS evp_positions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    position_key VARCHAR(50) NOT NULL,
    position_name VARCHAR(100) NOT NULL,
    position_pay INT NOT NULL DEFAULT 0 COMMENT '役職給（月額）',
    incentive_type VARCHAR(50) COMMENT '役職インセンティブ種別',
    incentive_rate DECIMAL(5,2) DEFAULT 0 COMMENT '役職インセンティブ率(%)',
    incentive_base VARCHAR(100) COMMENT '計算ベース（全体売上/自課売上等）',
    sort_order INT DEFAULT 0,
    promotion_condition TEXT COMMENT '昇格条件',
    demotion_condition TEXT COMMENT '降格条件',
    housing_allowance_single INT DEFAULT 0 COMMENT '家賃補助（独身）',
    housing_allowance_married INT DEFAULT 0 COMMENT '家賃補助（既婚）',
    company_car TINYINT DEFAULT 0 COMMENT '社用車支給',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_cp (company_id, position_key),
    INDEX idx_evpp_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- evp_sales_incentives
CREATE TABLE IF NOT EXISTS evp_sales_incentives (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    grade_key VARCHAR(50) NOT NULL,
    min_deals INT NOT NULL DEFAULT 1 COMMENT '件数下限',
    max_deals INT NULL COMMENT '件数上限（NULLは以上）',
    incentive_type ENUM('fixed','per_deal','gross_profit_rate') NOT NULL DEFAULT 'fixed',
    amount INT DEFAULT 0 COMMENT '固定額（円）or 件あたり額',
    rate DECIMAL(5,2) DEFAULT 0 COMMENT '粗利率（%）',
    description VARCHAR(200),
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_esi_company (company_id, grade_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- evp_bonus_rules
CREATE TABLE IF NOT EXISTS evp_bonus_rules (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    position_group VARCHAR(50) NOT NULL COMMENT '部長課長/主任一般',
    eval_rank VARCHAR(10) NOT NULL COMMENT 'S/A+/A/B/C',
    min_score INT NOT NULL DEFAULT 0,
    max_score INT DEFAULT 100,
    bonus_rate DECIMAL(4,2) NOT NULL COMMENT '賞与支給率(%)',
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_cbr (company_id, position_group, eval_rank),
    INDEX idx_ebr_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- evp_base_settings
CREATE TABLE IF NOT EXISTS evp_base_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    setting_key VARCHAR(50) NOT NULL,
    setting_value VARCHAR(200) NOT NULL,
    description VARCHAR(200),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_cbs (company_id, setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- eval_sheet_templates
CREATE TABLE IF NOT EXISTS eval_sheet_templates (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    template_name VARCHAR(200) NOT NULL COMMENT 'テンプレート名',
    department_key VARCHAR(100) NULL COMMENT '対象部署（NULLは全社共通）',
    company_vision TEXT COMMENT '自社の存在価値',
    three_year_goal TEXT COMMENT '3年後の会社ビジョン',
    ideal_person TEXT COMMENT '社長・会社の理想とする人材',
    company_challenge TEXT COMMENT '会社が解決すべき課題',
    demotion_rule TEXT COMMENT '降格ルール',
    is_active TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_est_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- eval_sheet_categories
CREATE TABLE IF NOT EXISTS eval_sheet_categories (
    id INT PRIMARY KEY AUTO_INCREMENT,
    template_id INT NOT NULL,
    category_number INT NOT NULL COMMENT 'カテゴリ番号(1-5)',
    title VARCHAR(200) NOT NULL COMMENT 'カテゴリタイトル',
    question TEXT COMMENT '問い（例: 自社の存在価値は？）',
    answer TEXT COMMENT '回答（例: 人として...）',
    sub_question1 TEXT COMMENT 'サブ問い1',
    sub_answer1 TEXT COMMENT 'サブ回答1',
    sub_question2 TEXT COMMENT 'サブ問い2',
    sub_answer2 TEXT COMMENT 'サブ回答2',
    sub_question3 TEXT COMMENT 'サブ問い3',
    sub_answer3 TEXT COMMENT 'サブ回答3',
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_esc_template (template_id),
    FOREIGN KEY (template_id) REFERENCES eval_sheet_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- eval_sheet_items
CREATE TABLE IF NOT EXISTS eval_sheet_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    category_id INT NOT NULL,
    item_number INT NOT NULL COMMENT '項目番号(①②...⑨)',
    item_name VARCHAR(300) NOT NULL COMMENT '評価項目名',
    item_description TEXT COMMENT '評価項目の説明',
    score_type ENUM('5point','10point','custom') DEFAULT '5point' COMMENT '配点タイプ',
    max_score INT DEFAULT 5 COMMENT '最大点数',
    criteria_5 VARCHAR(300) NULL COMMENT '5点の基準',
    criteria_3 VARCHAR(300) NULL COMMENT '3点の基準',
    criteria_1 VARCHAR(300) NULL COMMENT '1点の基準',
    criteria_custom TEXT NULL COMMENT 'カスタム基準（10点制等）',
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_esi_category (category_id),
    FOREIGN KEY (category_id) REFERENCES eval_sheet_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- eval_sheet_responses
CREATE TABLE IF NOT EXISTS eval_sheet_responses (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT NOT NULL,
    template_id INT NOT NULL,
    employee_id INT NOT NULL COMMENT '被評価者',
    evaluator_id INT NULL COMMENT '評価者',
    approver_id INT NULL COMMENT '承認者',
    eval_date DATE NULL COMMENT '評価日',
    period_label VARCHAR(100) NULL COMMENT '評価期間ラベル',
    status ENUM('draft','submitted','approved') DEFAULT 'draft',
    total_score INT NULL COMMENT '合計点',
    evaluator_comment TEXT COMMENT '評価者コメント',
    approver_comment TEXT COMMENT '承認者コメント',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_esr_company (company_id),
    INDEX idx_esr_employee (employee_id),
    FOREIGN KEY (template_id) REFERENCES eval_sheet_templates(id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- eval_sheet_scores
CREATE TABLE IF NOT EXISTS eval_sheet_scores (
    id INT PRIMARY KEY AUTO_INCREMENT,
    response_id INT NOT NULL,
    item_id INT NOT NULL,
    score INT NULL COMMENT '評価点',
    comment TEXT COMMENT 'コメント',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_response_item (response_id, item_id),
    INDEX idx_ess_response (response_id),
    FOREIGN KEY (response_id) REFERENCES eval_sheet_responses(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES eval_sheet_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- employee_dependents
CREATE TABLE IF NOT EXISTS employee_dependents (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL,
    relationship ENUM('spouse','child','parent','other') NOT NULL COMMENT '続柄',
    relationship_label VARCHAR(50) NULL COMMENT '続柄表示名',
    name VARCHAR(100) NOT NULL COMMENT '氏名',
    name_kana VARCHAR(100) NULL COMMENT 'フリガナ',
    gender ENUM('male','female') NULL COMMENT '性別',
    birth_date DATE NULL COMMENT '生年月日',
    annual_income INT NULL COMMENT '年間収入（万円）',
    occupation VARCHAR(100) NULL COMMENT '職業・学年',
    my_number VARCHAR(12) NULL COMMENT 'マイナンバー',
    pension_number VARCHAR(30) NULL COMMENT '基礎年金番号',
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ed_employee (employee_id),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- employee_transfers
CREATE TABLE IF NOT EXISTS employee_transfers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL,
    transfer_date DATE NOT NULL COMMENT '異動日',
    transfer_type ENUM('add','remove') NOT NULL COMMENT '追加/削除',
    reason TEXT COMMENT '理由',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_et_employee (employee_id),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- employee_work_history
CREATE TABLE IF NOT EXISTS employee_work_history (
    id INT PRIMARY KEY AUTO_INCREMENT,
    employee_id INT NOT NULL,
    company_name VARCHAR(200) NOT NULL COMMENT '会社名',
    start_year INT NULL,
    start_month INT NULL,
    end_year INT NULL,
    end_month INT NULL,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ewh_employee (employee_id),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ========== sales_daily_reports: 商材内訳カラム追加 ==========
ALTER TABLE sales_daily_reports ADD COLUMN location_type VARCHAR(20) DEFAULT NULL AFTER carrier;
ALTER TABLE sales_daily_reports ADD COLUMN mobile_external INT NOT NULL DEFAULT 0;
ALTER TABLE sales_daily_reports ADD COLUMN mobile_change_count INT NOT NULL DEFAULT 0;
ALTER TABLE sales_daily_reports ADD COLUMN sb_hikari_new INT NOT NULL DEFAULT 0;
ALTER TABLE sales_daily_reports ADD COLUMN sb_hikari_provider_change INT NOT NULL DEFAULT 0;
ALTER TABLE sales_daily_reports ADD COLUMN sb_hikari_transfer INT NOT NULL DEFAULT 0;
ALTER TABLE sales_daily_reports ADD COLUMN air_new INT NOT NULL DEFAULT 0;
ALTER TABLE sales_daily_reports ADD COLUMN air_change INT NOT NULL DEFAULT 0;
ALTER TABLE sales_daily_reports ADD COLUMN biglobe_hikari INT NOT NULL DEFAULT 0;
ALTER TABLE sales_daily_reports ADD COLUMN commufa_hikari INT NOT NULL DEFAULT 0;
ALTER TABLE sales_daily_reports ADD COLUMN aupay_card INT NOT NULL DEFAULT 0;
ALTER TABLE sales_daily_reports ADD COLUMN au_denki INT NOT NULL DEFAULT 0;
ALTER TABLE sales_daily_reports ADD COLUMN au_smartpass INT NOT NULL DEFAULT 0;
ALTER TABLE sales_daily_reports ADD COLUMN fixed_new INT NOT NULL DEFAULT 0;
ALTER TABLE sales_daily_reports ADD COLUMN fixed_provider_change INT NOT NULL DEFAULT 0;
ALTER TABLE sales_daily_reports ADD COLUMN fixed_transfer INT NOT NULL DEFAULT 0;
ALTER TABLE sales_daily_reports ADD COLUMN home_router_new INT NOT NULL DEFAULT 0;
ALTER TABLE sales_daily_reports ADD COLUMN home_router_change INT NOT NULL DEFAULT 0;
ALTER TABLE sales_daily_reports ADD COLUMN visit_groups INT NOT NULL DEFAULT 0;
ALTER TABLE sales_daily_reports ADD COLUMN consultation_groups INT NOT NULL DEFAULT 0;
ALTER TABLE sales_daily_reports ADD COLUMN mobile_acquisitions INT NOT NULL DEFAULT 0;
ALTER TABLE sales_daily_reports ADD COLUMN setup_support INT NOT NULL DEFAULT 0;
ALTER TABLE sales_daily_reports ADD COLUMN sim_mnp INT NOT NULL DEFAULT 0;
ALTER TABLE sales_daily_reports ADD COLUMN sim_new INT NOT NULL DEFAULT 0;
ALTER TABLE sales_daily_reports ADD COLUMN sim_change INT NOT NULL DEFAULT 0;
ALTER TABLE sales_daily_reports ADD COLUMN sim_fixed INT NOT NULL DEFAULT 0;
ALTER TABLE sales_daily_reports ADD COLUMN sim_router INT NOT NULL DEFAULT 0;


-- ============================================================
-- KLG 会社レコード（マスタデータの帰属先） + 変数 @klg
-- ※ login_id 'KLG' はログイン画面の単一テナント設定と一致
-- ============================================================
INSERT INTO companies (login_id, company_name, is_active)
SELECT 'KLG', 'KLG HOLDINGS', 1
WHERE NOT EXISTS (SELECT 1 FROM companies WHERE login_id = 'KLG');
SET @klg := (SELECT id FROM companies WHERE login_id = 'KLG' LIMIT 1);

-- ============================================================
-- マスタデータ（給与体系 / EVP / SPI・ストレングス設問 / 売上マスタ / 評価シート）
-- ※ 個人情報・サンプル取引データは含みません
-- ============================================================

-- ===== master: spi_questions (1件) =====
INSERT INTO spi_questions (dimension_key, category, question_text, is_reverse, sort_order) VALUES ('social_introversion', 'behavioral', '大勢の人が集まる場では、自分から積極的に話しかけるよりも聞き役に回ることが多い', 0, 1), ('social_introversion', 'behavioral', '初対面の人と会話するときは、緊張して言葉が出にくくなることがある', 0, 2), ('social_introversion', 'behavioral', '知らない人ばかりのパーティーでも、すぐに打ち解けて楽しむことができる', 1, 3), ('introspection', 'behavioral', '何か行動を起こす前に、じっくりと考えてから取りかかるほうだ', 0, 4), ('introspection', 'behavioral', '一人で静かに考える時間を持つことが、自分にとって大切だと感じる', 0, 5), ('introspection', 'behavioral', '深く考えるよりも、まず行動してから考えるタイプだ', 1, 6), ('physical_activity', 'behavioral', '体を動かすことが好きで、休日はアウトドア活動をすることが多い', 0, 7), ('physical_activity', 'behavioral', 'デスクワークが続くと、体を動かしたくなる衝動を感じる', 0, 8), ('physical_activity', 'behavioral', '運動やスポーツにはあまり興味がなく、静かに過ごすほうが好きだ', 1, 9), ('persistence', 'behavioral', '一度始めたことは、困難があっても最後までやり遂げようとする', 0, 10), ('persistence', 'behavioral', '目標を達成するまで、粘り強く努力を続けることができる', 0, 11), ('persistence', 'behavioral', '途中で飽きてしまい、別のことに手を出してしまうことがよくある', 1, 12), ('caution', 'behavioral', '重要な決断をするときは、十分な情報を集めてから慎重に判断する', 0, 13), ('caution', 'behavioral', 'リスクがある場合は、事前に対策を考えてから行動に移す', 0, 14), ('caution', 'behavioral', '細かいことを気にせず、直感で素早く判断することが多い', 1, 15), ('achievement_drive', 'motivational', '高い目標を設定して、それを達成することに強いやりがいを感じる', 0, 16), ('achievement_drive', 'motivational', '周囲から期待される以上の成果を出したいという気持ちが常にある', 0, 17), ('achievement_drive', 'motivational', '特に高い目標を持たなくても、日々の仕事を淡々とこなせれば満足だ', 1, 18), ('activity_drive', 'motivational', '常に何かに取り組んでいないと落ち着かず、忙しくしているほうが好きだ', 0, 19), ('activity_drive', 'motivational', '新しいプロジェクトやタスクが与えられると、ワクワクして取り組める', 0, 20), ('activity_drive', 'motivational', 'のんびりと過ごす時間が多いほうが、自分には合っていると思う', 1, 21), ('sensitivity', 'emotional', '他人のちょっとした言動や態度の変化に敏感に気づくほうだ', 0, 22), ('sensitivity', 'emotional', '周囲の雰囲気や空気の変化を、他の人より早く感じ取ることが多い', 0, 23), ('sensitivity', 'emotional', '多少きつい言葉を言われても、あまり気にならないほうだ', 1, 24), ('self_blame', 'emotional', '失敗したとき、原因は自分にあると考えて反省することが多い', 0, 25), ('self_blame', 'emotional', 'チームの成果が悪いとき、自分がもっと頑張れたのではないかと思う', 0, 26), ('self_blame', 'emotional', '物事がうまくいかなくても、自分を責めることはほとんどない', 1, 27), ('mood_variation', 'emotional', '気分の浮き沈みが激しく、日によってモチベーションに差がある', 0, 28), ('mood_variation', 'emotional', '些細なことで気分が大きく変わることがある', 0, 29), ('mood_variation', 'emotional', '気分は常に安定しており、感情に左右されることはほとんどない', 1, 30), ('uniqueness', 'emotional', '他の人とは違った独自の視点や考え方を持っていると思う', 0, 31), ('uniqueness', 'emotional', '一般的な方法よりも、自分なりのやり方を見つけることを好む', 0, 32), ('uniqueness', 'emotional', '周囲と同じやり方で進めるほうが安心できる', 1, 33), ('self_confidence', 'emotional', '自分の能力や判断に自信を持っており、それを周囲にも示せる', 0, 34), ('self_confidence', 'emotional', '困難な状況でも、自分なら乗り越えられるという確信がある', 0, 35), ('self_confidence', 'emotional', '自分の意見に自信が持てず、他人の意見に流されやすい', 1, 36), ('elation', 'emotional', '嬉しいことがあると、感情を抑えきれずに表現してしまうことがある', 0, 37), ('elation', 'emotional', '楽しいと感じると、周囲を巻き込んで盛り上がるタイプだ', 0, 38), ('elation', 'emotional', '嬉しいことがあっても、感情を表に出さず冷静でいられる', 1, 39), ('compliance', 'social', '上司や先輩の指示には素直に従い、忠実に実行するほうだ', 0, 40), ('compliance', 'social', '組織のルールや方針には、たとえ納得できなくても従うべきだと思う', 0, 41), ('compliance', 'social', '納得できない指示に対しては、自分の意見をはっきり主張するほうだ', 1, 42), ('avoidance', 'social', '対立や摩擦が生じそうな場面では、なるべく避けるようにしている', 0, 43), ('avoidance', 'social', '意見が対立したとき、自分が譲ることで場を丸く収めることが多い', 0, 44), ('avoidance', 'social', '意見の対立を恐れず、必要なときは正面からぶつかることができる', 1, 45), ('criticism', 'social', '物事の問題点や改善点を見つけるのが得意だと思う', 0, 46), ('criticism', 'social', '他人の仕事や提案に対して、論理的に問題点を指摘することが多い', 0, 47), ('criticism', 'social', '他人の仕事の問題点に気づいても、あえて指摘しないことが多い', 1, 48), ('self_respect', 'social', '自分の価値観や信念を大切にし、簡単には曲げないようにしている', 0, 49), ('self_respect', 'social', '他人からどう思われようと、自分の信じる道を進むことが重要だと思う', 0, 50), ('self_respect', 'social', '周囲の評価を気にして、自分の意見を変えてしまうことがよくある', 1, 51), ('skepticism', 'social', '新しい提案や情報に対して、まず疑問を持って検証するようにしている', 0, 52), ('skepticism', 'social', '「みんながそう言っている」という理由だけでは、簡単に信じない', 0, 53), ('skepticism', 'social', '人の言うことは基本的に信頼し、あまり疑うことはしない', 1, 54), ('leadership', 'workplace', 'グループで活動するとき、自然とまとめ役やリーダーの立場になることが多い', 0, 55), ('leadership', 'workplace', '方向性が定まらない場面では、率先して方針を示すことができる', 0, 56), ('leadership', 'workplace', 'リーダーの役割よりも、メンバーとして支える役割のほうが自分には合っている', 1, 57), ('teamwork', 'workplace', 'チームメンバーと協力して成果を出すことに大きなやりがいを感じる', 0, 58), ('teamwork', 'workplace', '自分の担当範囲だけでなく、チーム全体の成功のために行動できる', 0, 59), ('teamwork', 'workplace', 'チームで動くよりも、一人で黙々と作業するほうが成果を出せる', 1, 60), ('relationship_building', 'workplace', '初対面の人とでもすぐに信頼関係を築くことができる', 0, 61), ('relationship_building', 'workplace', '部署や立場が違う人とも、積極的にコミュニケーションを取るようにしている', 0, 62), ('relationship_building', 'workplace', '新しい人間関係を築くことには消極的で、既存の関係を大切にするほうだ', 1, 63), ('creative_thinking', 'workplace', '既存のやり方にとらわれず、新しいアイデアを考えることが得意だ', 0, 64), ('creative_thinking', 'workplace', '問題に直面したとき、従来とは異なるアプローチを試みることが多い', 0, 65), ('creative_thinking', 'workplace', '前例のある方法を確実に実行するほうが、新しい方法を考えるより得意だ', 1, 66), ('problem_solving', 'workplace', '複雑な問題でも、原因を分析して解決策を見つけ出すことができる', 0, 67), ('problem_solving', 'workplace', '予期しないトラブルが発生しても、冷静に対処方法を考えられる', 0, 68), ('problem_solving', 'workplace', '難しい問題に直面すると、誰かに助けを求めることが多い', 1, 69), ('situation_adaptability', 'workplace', '環境や状況の変化に対して、柔軟に対応できるほうだ', 0, 70), ('situation_adaptability', 'workplace', '予定外の仕事が入っても、優先順位を調整してうまく対応できる', 0, 71), ('situation_adaptability', 'workplace', '急な変更や予定外の出来事があると、対応に戸惑ってしまうことが多い', 1, 72), ('ownership', 'workplace', '担当業務だけでなく、組織全体の課題にも当事者意識を持って取り組める', 0, 73), ('ownership', 'workplace', '問題が発生したとき、自分事として捉えて解決に動くことができる', 0, 74), ('ownership', 'workplace', '自分の担当範囲外の問題には、あまり関心を持たないほうだ', 1, 75), ('energetic_action', 'workplace', 'やるべきことが決まったら、すぐに行動に移すことができる', 0, 76), ('energetic_action', 'workplace', '忙しい状況でもエネルギッシュに複数のタスクをこなすことができる', 0, 77), ('energetic_action', 'workplace', '行動を起こす前に準備期間が必要で、すぐには動き出せないことが多い', 1, 78);

-- ===== master: sf_questions (1件) =====
INSERT INTO sf_questions (theme_key, domain, question_text, sort_order) VALUES ('achiever', '実行力', '一日の終わりに「今日はこれだけ達成した」と振り返ることで充実感を得る', 1), ('achiever', '実行力', '休日でも何もしないで過ごすと罪悪感を覚え、何かを成し遂げたいと感じる', 2), ('activator', '影響力', '議論ばかりが続く会議では「とにかくやってみよう」と提案したくなる', 3), ('activator', '影響力', 'アイデアを思いついたら、計画を練るよりもまず小さく試してみたいと思う', 4), ('adaptability', '人間関係力', '予定が急に変わっても動揺せず、むしろ新しい状況を楽しめるほうだ', 5), ('adaptability', '人間関係力', '長期計画を立てるよりも、その場の状況に応じて柔軟に対応するほうが得意だ', 6), ('analytical', '戦略的思考力', '重要な意思決定では、感覚よりもデータや客観的な根拠を重視する', 7), ('analytical', '戦略的思考力', '他人の主張を聞くとき、論理的な裏付けがあるかどうかを確認したくなる', 8), ('arranger', '実行力', '複数のタスクや人員を効率よく配置して、最大の成果を出す調整が得意だ', 9), ('arranger', '実行力', '状況が変わったとき、リソースの再配分を素早く行って対応できる', 10), ('belief', '実行力', '自分の中に揺るぎない価値観があり、それに基づいて行動の指針を決めている', 11), ('belief', '実行力', '報酬よりも、自分が意義を感じる仕事に携わることのほうが重要だ', 12), ('command', '影響力', '混乱した状況では率先して主導権を握り、方向性を示すことができる', 13), ('command', '影響力', '意見の対立があるとき、自分の立場を明確にして堂々と主張できる', 14), ('communication', '影響力', '複雑な内容でも、相手にわかりやすく伝えることが得意だと思う', 15), ('communication', '影響力', 'プレゼンテーションや説明の場面で、聞き手を引き込む話し方ができる', 16), ('competition', '影響力', '他の人と競い合う状況のほうが、自分の力を最大限に発揮できる', 17), ('competition', '影響力', 'ランキングや順位がつく場面では、上位を目指して燃えるほうだ', 18), ('connectedness', '人間関係力', '一見無関係に見える出来事にも、何かしらのつながりや意味があると感じる', 19), ('connectedness', '人間関係力', 'すべての人はどこかでつながっているという感覚を持っている', 20), ('consistency', '実行力', '誰に対しても同じルールを適用し、公平に扱うことが重要だと考える', 21), ('consistency', '実行力', '特定の人だけが優遇される状況には、強い違和感を覚える', 22), ('context', '戦略的思考力', '新しいプロジェクトに取り組む前に、過去の経緯や背景を調べたくなる', 23), ('context', '戦略的思考力', '過去の成功例や失敗例を分析することで、現在の意思決定に活かすことが多い', 24), ('deliberative', '実行力', '行動する前にリスクを慎重に見極め、準備を万全にしてから臨むほうだ', 25), ('deliberative', '実行力', '重要な判断を下すとき、あらゆる選択肢のメリット・デメリットを比較検討する', 26), ('developer', '人間関係力', '後輩や部下の小さな成長や進歩に気づくと、心から嬉しくなる', 27), ('developer', '人間関係力', '人の潜在能力を見出し、その成長を手助けすることにやりがいを感じる', 28), ('discipline', '実行力', '日々のルーティンや手順を決めて、計画的に行動することで安心感を得る', 29), ('discipline', '実行力', '予定やタスクを整理して管理することが苦にならず、むしろ楽しいと感じる', 30), ('empathy', '人間関係力', '相手が言葉にしなくても、その人の気持ちや感情を察することができる', 31), ('empathy', '人間関係力', '周囲の人が悲しんでいるとき、自分もその感情を共有してしまうことがある', 32), ('focus', '実行力', '明確な目標を設定し、そこに向かって一直線に努力を集中させることが得意だ', 33), ('focus', '実行力', '複数のことを同時にやるよりも、一つのことに集中して取り組むほうが成果が出る', 34), ('futuristic', '戦略的思考力', '将来のビジョンを鮮明にイメージでき、それを語ることで周囲を鼓舞できる', 35), ('futuristic', '戦略的思考力', '「将来こうなったらいいな」という未来の姿を考えることに時間を費やすことが多い', 36), ('harmony', '人間関係力', 'グループ内で意見の衝突があるとき、共通点を見つけて合意形成を図るほうだ', 37), ('harmony', '人間関係力', '不必要な対立は避け、チームの一体感を大切にしたいと思う', 38), ('ideation', '戦略的思考力', '一見関連のない事柄の間に新しいつながりを見つけると、とてもワクワクする', 39), ('ideation', '戦略的思考力', '常識にとらわれない新しいアイデアを考えることが、何よりも楽しいと感じる', 40), ('includer', '人間関係力', 'グループの輪から外れている人がいると、声をかけて仲間に入れたくなる', 41), ('includer', '人間関係力', '誰もが受け入れられ、居場所があると感じられる環境を作ることが大切だと思う', 42), ('individualization', '人間関係力', '一人ひとりの強みや個性の違いを見抜き、それぞれに合った接し方をするのが得意だ', 43), ('individualization', '人間関係力', 'チームメンバーには画一的な対応よりも、個々に合わせたアプローチが効果的だと思う', 44), ('input', '戦略的思考力', '興味を引く情報や知識を見つけると、つい集めてしまう癖がある', 45), ('input', '戦略的思考力', '本、記事、データなど、将来役に立ちそうな情報をストックしておくのが好きだ', 46), ('intellection', '戦略的思考力', '一人で静かに深く考える時間を確保することが、自分にとって不可欠だ', 47), ('intellection', '戦略的思考力', '物事の本質や根本的な原理について、じっくり思索することが好きだ', 48), ('learner', '戦略的思考力', '新しい分野やスキルを学ぶプロセス自体に、強い喜びを感じる', 49), ('learner', '戦略的思考力', '何かを学んでいるときが最も生き生きとしており、学びのない日々は退屈に感じる', 50), ('maximizer', '影響力', '弱点を克服するよりも、既に優れている部分をさらに磨くことに注力したい', 51), ('maximizer', '影響力', '「良い」で満足せず、「最高」を目指して品質を高めることにこだわる', 52), ('positivity', '人間関係力', '周囲の人を励まし、前向きな雰囲気を作ることが自然にできる', 53), ('positivity', '人間関係力', 'どんな状況でも良い面を見つけ出し、楽観的に捉えることができるほうだ', 54), ('relator', '人間関係力', '広く浅い人間関係よりも、少数の人と深い信頼関係を築くことを好む', 55), ('relator', '人間関係力', '親しい友人や同僚との率直なやり取りの中で、最も自分らしくいられる', 56), ('responsibility', '実行力', '一度引き受けた仕事は、どんな困難があっても必ずやり遂げるという強い意志がある', 57), ('responsibility', '実行力', '約束を守れなかったとき、強い罪悪感を感じ、挽回しようと全力を尽くす', 58), ('restorative', '実行力', '問題やトラブルを見つけると、原因を突き止めて解決したいという衝動に駆られる', 59), ('restorative', '実行力', '壊れたものを修理したり、うまくいっていない状況を立て直すことにやりがいを感じる', 60), ('self_assurance', '影響力', '重要な局面でも自分の判断を信じて、迷わず決断を下すことができる', 61), ('self_assurance', '影響力', '他人の評価に左右されず、自分の道を確信を持って歩むことができる', 62), ('significance', '影響力', '自分の仕事や存在が、周囲に認められ評価されることが大きなモチベーションになる', 63), ('significance', '影響力', '他者にとって重要な存在でありたいという思いが、日々の行動の原動力になっている', 64), ('strategic', '戦略的思考力', '複雑な状況でも、複数のシナリオを想定して最適な道筋を見つけることが得意だ', 65), ('strategic', '戦略的思考力', '障害に直面したとき、すぐに代替ルートや別のアプローチを思いつくことができる', 66), ('woo', '影響力', '初対面の人と会話するのが得意で、すぐに打ち解けることができる', 67), ('woo', '影響力', '新しい人と出会うことが楽しく、人脈を広げることにエネルギーを感じる', 68);

-- ===== master: evp_base_settings (9件) =====
INSERT INTO evp_base_settings (company_id, setting_key, setting_value, description) VALUES (@klg,'base_salary','230000','基本給（全員共通）') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
INSERT INTO evp_base_settings (company_id, setting_key, setting_value, description) VALUES (@klg,'avg_gross_profit_per_deal','1100000','1実行あたり平均粗利（円）') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
INSERT INTO evp_base_settings (company_id, setting_key, setting_value, description) VALUES (@klg,'company_car_annual','360000','社用車年間換算（月3万円×12）') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
INSERT INTO evp_base_settings (company_id, setting_key, setting_value, description) VALUES (@klg,'bonus_frequency','2','賞与回数（年）') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
INSERT INTO evp_base_settings (company_id, setting_key, setting_value, description) VALUES (@klg,'bonus_months','6,12','賞与支給月') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
INSERT INTO evp_base_settings (company_id, setting_key, setting_value, description) VALUES (@klg,'work_days','木,金,土,日','営業稼働日') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
INSERT INTO evp_base_settings (company_id, setting_key, setting_value, description) VALUES (@klg,'day_off','月,火','休日') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
INSERT INTO evp_base_settings (company_id, setting_key, setting_value, description) VALUES (@klg,'meeting_day','水','会議日') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
INSERT INTO evp_base_settings (company_id, setting_key, setting_value, description) VALUES (@klg,'work_hours','10:00-19:00','稼働時間') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);

-- ===== master: evp_grades (4件) =====
INSERT INTO evp_grades (company_id, grade_key, grade_name, grade_pay, sort_order, promotion_condition, demotion_condition) VALUES (@klg,'closer','クローザー',50000,1,'6ヶ月平均 契約率65%以上','6ヶ月平均 契約率60%未満') ON DUPLICATE KEY UPDATE grade_name=VALUES(grade_name), grade_pay=VALUES(grade_pay), promotion_condition=VALUES(promotion_condition), demotion_condition=VALUES(demotion_condition);
INSERT INTO evp_grades (company_id, grade_key, grade_name, grade_pay, sort_order, promotion_condition, demotion_condition) VALUES (@klg,'sub_closer','サブクローザー',30000,2,'クローザーテスト合格','6ヶ月平均 契約率50%未満') ON DUPLICATE KEY UPDATE grade_name=VALUES(grade_name), grade_pay=VALUES(grade_pay), promotion_condition=VALUES(promotion_condition), demotion_condition=VALUES(demotion_condition);
INSERT INTO evp_grades (company_id, grade_key, grade_name, grade_pay, sort_order, promotion_condition, demotion_condition) VALUES (@klg,'appointer','アポインター',20000,3,'3ヶ月平均 8アポ4契約以上 ※12月度のみ2アポ1契約加算','3ヶ月平均 5.5アポ2.5契約未満') ON DUPLICATE KEY UPDATE grade_name=VALUES(grade_name), grade_pay=VALUES(grade_pay), promotion_condition=VALUES(promotion_condition), demotion_condition=VALUES(demotion_condition);
INSERT INTO evp_grades (company_id, grade_key, grade_name, grade_pay, sort_order, promotion_condition, demotion_condition) VALUES (@klg,'trainee','研修アポインター',0,4,NULL,NULL) ON DUPLICATE KEY UPDATE grade_name=VALUES(grade_name), grade_pay=VALUES(grade_pay), promotion_condition=VALUES(promotion_condition), demotion_condition=VALUES(demotion_condition);

-- ===== master: evp_positions (4件) =====
INSERT INTO evp_positions (company_id, position_key, position_name, position_pay, incentive_type, incentive_rate, incentive_base, sort_order, promotion_condition, demotion_condition, housing_allowance_single, housing_allowance_married, company_car) VALUES (@klg,'director','部長',100000,'sales_rate',1,'全体売上の1%/月',1,'等級クローザー保持 + 役員判断','役員判断',30000,50000,1) ON DUPLICATE KEY UPDATE position_name=VALUES(position_name), position_pay=VALUES(position_pay), incentive_rate=VALUES(incentive_rate), incentive_base=VALUES(incentive_base), promotion_condition=VALUES(promotion_condition), demotion_condition=VALUES(demotion_condition), housing_allowance_single=VALUES(housing_allowance_single), housing_allowance_married=VALUES(housing_allowance_married), company_car=VALUES(company_car);
INSERT INTO evp_positions (company_id, position_key, position_name, position_pay, incentive_type, incentive_rate, incentive_base, sort_order, promotion_condition, demotion_condition, housing_allowance_single, housing_allowance_married, company_car) VALUES (@klg,'manager','課長',50000,'sales_rate',2,'自課売上の2%/月',2,'等級サブクローザー保持 + 部長判断 + 役員承認','部長判断 + 役員承認',30000,50000,1) ON DUPLICATE KEY UPDATE position_name=VALUES(position_name), position_pay=VALUES(position_pay), incentive_rate=VALUES(incentive_rate), incentive_base=VALUES(incentive_base), promotion_condition=VALUES(promotion_condition), demotion_condition=VALUES(demotion_condition), housing_allowance_single=VALUES(housing_allowance_single), housing_allowance_married=VALUES(housing_allowance_married), company_car=VALUES(company_car);
INSERT INTO evp_positions (company_id, position_key, position_name, position_pay, incentive_type, incentive_rate, incentive_base, sort_order, promotion_condition, demotion_condition, housing_allowance_single, housing_allowance_married, company_car) VALUES (@klg,'chief','主任',20000,NULL,0,NULL,3,'等級アポインター1年以上 + 課長推薦 + 部長・役員承認','課長判断 + 部長・役員判断',0,0,0) ON DUPLICATE KEY UPDATE position_name=VALUES(position_name), position_pay=VALUES(position_pay), incentive_rate=VALUES(incentive_rate), incentive_base=VALUES(incentive_base), promotion_condition=VALUES(promotion_condition), demotion_condition=VALUES(demotion_condition), housing_allowance_single=VALUES(housing_allowance_single), housing_allowance_married=VALUES(housing_allowance_married), company_car=VALUES(company_car);
INSERT INTO evp_positions (company_id, position_key, position_name, position_pay, incentive_type, incentive_rate, incentive_base, sort_order, promotion_condition, demotion_condition, housing_allowance_single, housing_allowance_married, company_car) VALUES (@klg,'none','なし',0,NULL,0,NULL,4,NULL,NULL,0,0,0) ON DUPLICATE KEY UPDATE position_name=VALUES(position_name), position_pay=VALUES(position_pay), incentive_rate=VALUES(incentive_rate), incentive_base=VALUES(incentive_base), promotion_condition=VALUES(promotion_condition), demotion_condition=VALUES(demotion_condition), housing_allowance_single=VALUES(housing_allowance_single), housing_allowance_married=VALUES(housing_allowance_married), company_car=VALUES(company_car);

-- ===== master: evp_sales_incentives (8件) =====
INSERT INTO evp_sales_incentives (company_id, grade_key, min_deals, max_deals, incentive_type, amount, rate, description, sort_order) VALUES (@klg,'trainee',1,1,'fixed',30000,0,'1件: ¥30,000',1);
INSERT INTO evp_sales_incentives (company_id, grade_key, min_deals, max_deals, incentive_type, amount, rate, description, sort_order) VALUES (@klg,'trainee',2,2,'per_deal',50000,0,'2件: ¥50,000/件',2);
INSERT INTO evp_sales_incentives (company_id, grade_key, min_deals, max_deals, incentive_type, amount, rate, description, sort_order) VALUES (@klg,'trainee',3,NULL,'per_deal',70000,0,'3件以上: ¥70,000/件',3);
INSERT INTO evp_sales_incentives (company_id, grade_key, min_deals, max_deals, incentive_type, amount, rate, description, sort_order) VALUES (@klg,'appointer',1,1,'fixed',40000,0,'1件: ¥40,000',4);
INSERT INTO evp_sales_incentives (company_id, grade_key, min_deals, max_deals, incentive_type, amount, rate, description, sort_order) VALUES (@klg,'appointer',2,2,'per_deal',70000,0,'2件: ¥70,000/件',5);
INSERT INTO evp_sales_incentives (company_id, grade_key, min_deals, max_deals, incentive_type, amount, rate, description, sort_order) VALUES (@klg,'appointer',3,NULL,'per_deal',100000,0,'3件以上: ¥100,000/件',6);
INSERT INTO evp_sales_incentives (company_id, grade_key, min_deals, max_deals, incentive_type, amount, rate, description, sort_order) VALUES (@klg,'sub_closer',1,NULL,'gross_profit_rate',0,3,'一律粗利3%',7);
INSERT INTO evp_sales_incentives (company_id, grade_key, min_deals, max_deals, incentive_type, amount, rate, description, sort_order) VALUES (@klg,'closer',1,NULL,'gross_profit_rate',0,5,'一律粗利5%',8);

-- ===== master: evp_bonus_rules (10件) =====
INSERT INTO evp_bonus_rules (company_id, position_group, eval_rank, min_score, max_score, bonus_rate, sort_order) VALUES (@klg,'manager_director','S',90,100,1.2,1) ON DUPLICATE KEY UPDATE min_score=VALUES(min_score), max_score=VALUES(max_score), bonus_rate=VALUES(bonus_rate);
INSERT INTO evp_bonus_rules (company_id, position_group, eval_rank, min_score, max_score, bonus_rate, sort_order) VALUES (@klg,'manager_director','A+',85,89,1.1,2) ON DUPLICATE KEY UPDATE min_score=VALUES(min_score), max_score=VALUES(max_score), bonus_rate=VALUES(bonus_rate);
INSERT INTO evp_bonus_rules (company_id, position_group, eval_rank, min_score, max_score, bonus_rate, sort_order) VALUES (@klg,'manager_director','A',80,84,1,3) ON DUPLICATE KEY UPDATE min_score=VALUES(min_score), max_score=VALUES(max_score), bonus_rate=VALUES(bonus_rate);
INSERT INTO evp_bonus_rules (company_id, position_group, eval_rank, min_score, max_score, bonus_rate, sort_order) VALUES (@klg,'manager_director','B',70,79,0.9,4) ON DUPLICATE KEY UPDATE min_score=VALUES(min_score), max_score=VALUES(max_score), bonus_rate=VALUES(bonus_rate);
INSERT INTO evp_bonus_rules (company_id, position_group, eval_rank, min_score, max_score, bonus_rate, sort_order) VALUES (@klg,'manager_director','C',0,69,0.8,5) ON DUPLICATE KEY UPDATE min_score=VALUES(min_score), max_score=VALUES(max_score), bonus_rate=VALUES(bonus_rate);
INSERT INTO evp_bonus_rules (company_id, position_group, eval_rank, min_score, max_score, bonus_rate, sort_order) VALUES (@klg,'staff','S',90,100,1.5,6) ON DUPLICATE KEY UPDATE min_score=VALUES(min_score), max_score=VALUES(max_score), bonus_rate=VALUES(bonus_rate);
INSERT INTO evp_bonus_rules (company_id, position_group, eval_rank, min_score, max_score, bonus_rate, sort_order) VALUES (@klg,'staff','A+',85,89,1.2,7) ON DUPLICATE KEY UPDATE min_score=VALUES(min_score), max_score=VALUES(max_score), bonus_rate=VALUES(bonus_rate);
INSERT INTO evp_bonus_rules (company_id, position_group, eval_rank, min_score, max_score, bonus_rate, sort_order) VALUES (@klg,'staff','A',80,84,1,8) ON DUPLICATE KEY UPDATE min_score=VALUES(min_score), max_score=VALUES(max_score), bonus_rate=VALUES(bonus_rate);
INSERT INTO evp_bonus_rules (company_id, position_group, eval_rank, min_score, max_score, bonus_rate, sort_order) VALUES (@klg,'staff','B',70,79,0.8,9) ON DUPLICATE KEY UPDATE min_score=VALUES(min_score), max_score=VALUES(max_score), bonus_rate=VALUES(bonus_rate);
INSERT INTO evp_bonus_rules (company_id, position_group, eval_rank, min_score, max_score, bonus_rate, sort_order) VALUES (@klg,'staff','C',0,69,0.5,10) ON DUPLICATE KEY UPDATE min_score=VALUES(min_score), max_score=VALUES(max_score), bonus_rate=VALUES(bonus_rate);

-- ===== master: salary_ladder (16件) =====
INSERT INTO salary_ladder (company_id, department_key, ladder_name, grade_name, grade_level, sales_threshold, salary, grade_color, sort_order) VALUES (@klg,'LiberTeen','ショップラダー','ゴールド',1,600000,360000,'#FFD700',1) ON DUPLICATE KEY UPDATE sales_threshold=VALUES(sales_threshold), salary=VALUES(salary);
INSERT INTO salary_ladder (company_id, department_key, ladder_name, grade_name, grade_level, sales_threshold, salary, grade_color, sort_order) VALUES (@klg,'LiberTeen','ショップラダー','ゴールド',2,500000,290000,'#FFD700',2) ON DUPLICATE KEY UPDATE sales_threshold=VALUES(sales_threshold), salary=VALUES(salary);
INSERT INTO salary_ladder (company_id, department_key, ladder_name, grade_name, grade_level, sales_threshold, salary, grade_color, sort_order) VALUES (@klg,'LiberTeen','ショップラダー','シルバー',1,480000,260000,'#C0C0C0',3) ON DUPLICATE KEY UPDATE sales_threshold=VALUES(sales_threshold), salary=VALUES(salary);
INSERT INTO salary_ladder (company_id, department_key, ladder_name, grade_name, grade_level, sales_threshold, salary, grade_color, sort_order) VALUES (@klg,'LiberTeen','ショップラダー','シルバー',2,460000,250000,'#C0C0C0',4) ON DUPLICATE KEY UPDATE sales_threshold=VALUES(sales_threshold), salary=VALUES(salary);
INSERT INTO salary_ladder (company_id, department_key, ladder_name, grade_name, grade_level, sales_threshold, salary, grade_color, sort_order) VALUES (@klg,'LiberTeen','ショップラダー','シルバー',3,440000,240000,'#C0C0C0',5) ON DUPLICATE KEY UPDATE sales_threshold=VALUES(sales_threshold), salary=VALUES(salary);
INSERT INTO salary_ladder (company_id, department_key, ladder_name, grade_name, grade_level, sales_threshold, salary, grade_color, sort_order) VALUES (@klg,'LiberTeen','ショップラダー','シルバー',4,420000,230000,'#C0C0C0',6) ON DUPLICATE KEY UPDATE sales_threshold=VALUES(sales_threshold), salary=VALUES(salary);
INSERT INTO salary_ladder (company_id, department_key, ladder_name, grade_name, grade_level, sales_threshold, salary, grade_color, sort_order) VALUES (@klg,'LiberTeen','ショップラダー','シルバー',5,400000,220000,'#C0C0C0',7) ON DUPLICATE KEY UPDATE sales_threshold=VALUES(sales_threshold), salary=VALUES(salary);
INSERT INTO salary_ladder (company_id, department_key, ladder_name, grade_name, grade_level, sales_threshold, salary, grade_color, sort_order) VALUES (@klg,'LiberTeen','ショップラダー','ブロンズ',1,0,200000,'#CD7F32',8) ON DUPLICATE KEY UPDATE sales_threshold=VALUES(sales_threshold), salary=VALUES(salary);
INSERT INTO salary_ladder (company_id, department_key, ladder_name, grade_name, grade_level, sales_threshold, salary, grade_color, sort_order) VALUES (@klg,'LiberTeen','家電量販店ラダー','ゴールド',1,620000,350000,'#FFD700',1) ON DUPLICATE KEY UPDATE sales_threshold=VALUES(sales_threshold), salary=VALUES(salary);
INSERT INTO salary_ladder (company_id, department_key, ladder_name, grade_name, grade_level, sales_threshold, salary, grade_color, sort_order) VALUES (@klg,'LiberTeen','家電量販店ラダー','ゴールド',2,570000,300000,'#FFD700',2) ON DUPLICATE KEY UPDATE sales_threshold=VALUES(sales_threshold), salary=VALUES(salary);
INSERT INTO salary_ladder (company_id, department_key, ladder_name, grade_name, grade_level, sales_threshold, salary, grade_color, sort_order) VALUES (@klg,'LiberTeen','家電量販店ラダー','シルバー',1,530000,290000,'#C0C0C0',3) ON DUPLICATE KEY UPDATE sales_threshold=VALUES(sales_threshold), salary=VALUES(salary);
INSERT INTO salary_ladder (company_id, department_key, ladder_name, grade_name, grade_level, sales_threshold, salary, grade_color, sort_order) VALUES (@klg,'LiberTeen','家電量販店ラダー','シルバー',2,500000,280000,'#C0C0C0',4) ON DUPLICATE KEY UPDATE sales_threshold=VALUES(sales_threshold), salary=VALUES(salary);
INSERT INTO salary_ladder (company_id, department_key, ladder_name, grade_name, grade_level, sales_threshold, salary, grade_color, sort_order) VALUES (@klg,'LiberTeen','家電量販店ラダー','シルバー',3,470000,250000,'#C0C0C0',5) ON DUPLICATE KEY UPDATE sales_threshold=VALUES(sales_threshold), salary=VALUES(salary);
INSERT INTO salary_ladder (company_id, department_key, ladder_name, grade_name, grade_level, sales_threshold, salary, grade_color, sort_order) VALUES (@klg,'LiberTeen','家電量販店ラダー','シルバー',4,440000,230000,'#C0C0C0',6) ON DUPLICATE KEY UPDATE sales_threshold=VALUES(sales_threshold), salary=VALUES(salary);
INSERT INTO salary_ladder (company_id, department_key, ladder_name, grade_name, grade_level, sales_threshold, salary, grade_color, sort_order) VALUES (@klg,'LiberTeen','家電量販店ラダー','シルバー',5,415000,210000,'#C0C0C0',7) ON DUPLICATE KEY UPDATE sales_threshold=VALUES(sales_threshold), salary=VALUES(salary);
INSERT INTO salary_ladder (company_id, department_key, ladder_name, grade_name, grade_level, sales_threshold, salary, grade_color, sort_order) VALUES (@klg,'LiberTeen','家電量販店ラダー','ブロンズ',1,320000,200000,'#CD7F32',8) ON DUPLICATE KEY UPDATE sales_threshold=VALUES(sales_threshold), salary=VALUES(salary);

-- ===== master: sales_clients (24件) =====
INSERT IGNORE INTO sales_clients (company_id, client_name, sort_order) VALUES (@klg, 'ラネット', 0);
INSERT IGNORE INTO sales_clients (company_id, client_name, sort_order) VALUES (@klg, 'プレイス', 1);
INSERT IGNORE INTO sales_clients (company_id, client_name, sort_order) VALUES (@klg, 'プレイミー', 2);
INSERT IGNORE INTO sales_clients (company_id, client_name, sort_order) VALUES (@klg, 'LANGIS', 3);
INSERT IGNORE INTO sales_clients (company_id, client_name, sort_order) VALUES (@klg, 'クラウドエージェント', 4);
INSERT IGNORE INTO sales_clients (company_id, client_name, sort_order) VALUES (@klg, 'ASXEED', 5);
INSERT IGNORE INTO sales_clients (company_id, client_name, sort_order) VALUES (@klg, 'SNAP', 6);
INSERT IGNORE INTO sales_clients (company_id, client_name, sort_order) VALUES (@klg, 'KunitokoAsset', 7);
INSERT IGNORE INTO sales_clients (company_id, client_name, sort_order) VALUES (@klg, 'waplus', 8);
INSERT IGNORE INTO sales_clients (company_id, client_name, sort_order) VALUES (@klg, 'アスカ', 9);
INSERT IGNORE INTO sales_clients (company_id, client_name, sort_order) VALUES (@klg, 'humanR', 10);
INSERT IGNORE INTO sales_clients (company_id, client_name, sort_order) VALUES (@klg, 'オリエンス', 11);
INSERT IGNORE INTO sales_clients (company_id, client_name, sort_order) VALUES (@klg, 'MDC', 12);
INSERT IGNORE INTO sales_clients (company_id, client_name, sort_order) VALUES (@klg, 'テレポート', 13);
INSERT IGNORE INTO sales_clients (company_id, client_name, sort_order) VALUES (@klg, 'テレニシ', 14);
INSERT IGNORE INTO sales_clients (company_id, client_name, sort_order) VALUES (@klg, 'ベルパーク', 15);
INSERT IGNORE INTO sales_clients (company_id, client_name, sort_order) VALUES (@klg, 'センターフロー', 16);
INSERT IGNORE INTO sales_clients (company_id, client_name, sort_order) VALUES (@klg, 'LLC', 17);
INSERT IGNORE INTO sales_clients (company_id, client_name, sort_order) VALUES (@klg, 'Pachira', 18);
INSERT IGNORE INTO sales_clients (company_id, client_name, sort_order) VALUES (@klg, 'Fleuve', 19);
INSERT IGNORE INTO sales_clients (company_id, client_name, sort_order) VALUES (@klg, 'WillAID', 20);
INSERT IGNORE INTO sales_clients (company_id, client_name, sort_order) VALUES (@klg, 'kunitoko asset', 21);
INSERT IGNORE INTO sales_clients (company_id, client_name, sort_order) VALUES (@klg, '光AD', 22);
INSERT IGNORE INTO sales_clients (company_id, client_name, sort_order) VALUES (@klg, 'ライフフレンド', 23);

-- ===== master: sales_alliances (31件) =====
INSERT IGNORE INTO sales_alliances (company_id, alliance_name, sort_order) VALUES (@klg, 'NextAssist', 0);
INSERT IGNORE INTO sales_alliances (company_id, alliance_name, sort_order) VALUES (@klg, 'ネクストプレイス', 1);
INSERT IGNORE INTO sales_alliances (company_id, alliance_name, sort_order) VALUES (@klg, 'RE', 2);
INSERT IGNORE INTO sales_alliances (company_id, alliance_name, sort_order) VALUES (@klg, '小林幹汰', 3);
INSERT IGNORE INTO sales_alliances (company_id, alliance_name, sort_order) VALUES (@klg, 'LaXum', 4);
INSERT IGNORE INTO sales_alliances (company_id, alliance_name, sort_order) VALUES (@klg, 'ASXEED', 5);
INSERT IGNORE INTO sales_alliances (company_id, alliance_name, sort_order) VALUES (@klg, 'EXceed', 6);
INSERT IGNORE INTO sales_alliances (company_id, alliance_name, sort_order) VALUES (@klg, '渡邊拓斗', 7);
INSERT IGNORE INTO sales_alliances (company_id, alliance_name, sort_order) VALUES (@klg, '株式会社 樹', 8);
INSERT IGNORE INTO sales_alliances (company_id, alliance_name, sort_order) VALUES (@klg, 'オリエンス', 9);
INSERT IGNORE INTO sales_alliances (company_id, alliance_name, sort_order) VALUES (@klg, 'quinx', 10);
INSERT IGNORE INTO sales_alliances (company_id, alliance_name, sort_order) VALUES (@klg, 'ハーヴェスト', 11);
INSERT IGNORE INTO sales_alliances (company_id, alliance_name, sort_order) VALUES (@klg, '高橋暁力', 12);
INSERT IGNORE INTO sales_alliances (company_id, alliance_name, sort_order) VALUES (@klg, 'T-Group', 13);
INSERT IGNORE INTO sales_alliances (company_id, alliance_name, sort_order) VALUES (@klg, 'WINX', 14);
INSERT IGNORE INTO sales_alliances (company_id, alliance_name, sort_order) VALUES (@klg, 'FLEX''s', 15);
INSERT IGNORE INTO sales_alliances (company_id, alliance_name, sort_order) VALUES (@klg, 'エッセンス', 16);
INSERT IGNORE INTO sales_alliances (company_id, alliance_name, sort_order) VALUES (@klg, 'グラスト', 17);
INSERT IGNORE INTO sales_alliances (company_id, alliance_name, sort_order) VALUES (@klg, 'オアシス', 18);
INSERT IGNORE INTO sales_alliances (company_id, alliance_name, sort_order) VALUES (@klg, 'Pachira', 19);
INSERT IGNORE INTO sales_alliances (company_id, alliance_name, sort_order) VALUES (@klg, 'KTT', 20);
INSERT IGNORE INTO sales_alliances (company_id, alliance_name, sort_order) VALUES (@klg, 'LIFIX', 21);
INSERT IGNORE INTO sales_alliances (company_id, alliance_name, sort_order) VALUES (@klg, 'U-plus', 22);
INSERT IGNORE INTO sales_alliances (company_id, alliance_name, sort_order) VALUES (@klg, 'V.I.N', 23);
INSERT IGNORE INTO sales_alliances (company_id, alliance_name, sort_order) VALUES (@klg, 'ASB', 24);
INSERT IGNORE INTO sales_alliances (company_id, alliance_name, sort_order) VALUES (@klg, '東峰グループ', 25);
INSERT IGNORE INTO sales_alliances (company_id, alliance_name, sort_order) VALUES (@klg, '魁組', 26);
INSERT IGNORE INTO sales_alliances (company_id, alliance_name, sort_order) VALUES (@klg, 'onetale', 27);
INSERT IGNORE INTO sales_alliances (company_id, alliance_name, sort_order) VALUES (@klg, '佐藤悠太', 28);
INSERT IGNORE INTO sales_alliances (company_id, alliance_name, sort_order) VALUES (@klg, '林航平', 29);
INSERT IGNORE INTO sales_alliances (company_id, alliance_name, sort_order) VALUES (@klg, '高田夢斗', 30);

-- ===== master: sales_store_brands (15件) =====
INSERT IGNORE INTO sales_store_brands (company_id, brand_name, brand_code, sort_order) VALUES (@klg, 'ソフトバンク', 'SB', 0);
INSERT IGNORE INTO sales_store_brands (company_id, brand_name, brand_code, sort_order) VALUES (@klg, 'ワイモバイル', 'YM', 1);
INSERT IGNORE INTO sales_store_brands (company_id, brand_name, brand_code, sort_order) VALUES (@klg, 'ドコモ', 'docomo', 2);
INSERT IGNORE INTO sales_store_brands (company_id, brand_name, brand_code, sort_order) VALUES (@klg, 'au', 'au', 3);
INSERT IGNORE INTO sales_store_brands (company_id, brand_name, brand_code, sort_order) VALUES (@klg, 'エディオン', 'ED', 4);
INSERT IGNORE INTO sales_store_brands (company_id, brand_name, brand_code, sort_order) VALUES (@klg, 'ヤマダ電機', 'YMD', 5);
INSERT IGNORE INTO sales_store_brands (company_id, brand_name, brand_code, sort_order) VALUES (@klg, 'ケーズデンキ', 'KS', 6);
INSERT IGNORE INTO sales_store_brands (company_id, brand_name, brand_code, sort_order) VALUES (@klg, 'ビックカメラ', 'BC', 7);
INSERT IGNORE INTO sales_store_brands (company_id, brand_name, brand_code, sort_order) VALUES (@klg, 'ジョーシン', 'JS', 8);
INSERT IGNORE INTO sales_store_brands (company_id, brand_name, brand_code, sort_order) VALUES (@klg, 'ヨドバシカメラ', 'YD', 9);
INSERT IGNORE INTO sales_store_brands (company_id, brand_name, brand_code, sort_order) VALUES (@klg, 'ノジマ', 'NJ', 10);
INSERT IGNORE INTO sales_store_brands (company_id, brand_name, brand_code, sort_order) VALUES (@klg, 'ドン・キホーテ', 'DK', 11);
INSERT IGNORE INTO sales_store_brands (company_id, brand_name, brand_code, sort_order) VALUES (@klg, 'イオンモール', 'AM', 12);
INSERT IGNORE INTO sales_store_brands (company_id, brand_name, brand_code, sort_order) VALUES (@klg, 'イオンタウン', 'AT', 13);
INSERT IGNORE INTO sales_store_brands (company_id, brand_name, brand_code, sort_order) VALUES (@klg, 'アピタ', 'AP', 14);

-- ===== master: sales_areas (65件) =====
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, 'GA知立', 0);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, '安城', 1);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, '安城住吉', 2);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, '知立', 3);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, '碧南', 4);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, '半田亀崎', 5);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, '豊川八幡', 6);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, 'AM豊川', 7);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, '岡崎', 8);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, '岡崎南', 9);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, '岡崎大樹寺', 10);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, '大樹寺', 11);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, '西尾シャオ', 12);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, '豊橋ミラまち', 13);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, '豊田本店', 14);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, '一ッ木', 15);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, '一宮尾西', 16);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, '一宮開明', 17);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, '一ノ宮尾西', 18);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, '中津川', 19);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, '名古屋本店', 20);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, '千種', 21);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, 'イオン千種', 22);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, '千音寺', 23);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, '南陽', 24);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, '吹上', 25);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, '新瑞橋', 26);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, '平針', 27);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, '長久手', 28);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, '尾張旭', 29);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, '小牧', 30);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, '弥富', 31);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, '野並', 32);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, '芥見', 33);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, '美濃加茂', 34);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, '多治見南', 35);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, 'AT各務原鵜沼', 36);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, '北方', 37);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, 'モレラ岐阜', 38);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, '岐南', 39);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, '大垣', 40);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, 'AM大垣', 41);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, 'AM木曽川', 42);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, 'ららぽーと愛知東郷', 43);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, 'ららぽーと東郷', 44);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, 'アピタ安城南', 45);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, 'メガトライアル大府', 46);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, 'メガトラ大府', 47);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, 'MT大府', 48);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, '東浦', 49);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, '光', 50);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, 'コーナン松坂', 51);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, 'トナリエ四日市', 52);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, 'トナリ四日市', 53);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, 'ビックカメラ駅西', 54);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, 'ドンキ碧南', 55);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, '刈谷ハイウェイ', 56);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, 'サンロード', 57);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, 'ジャズ', 58);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, 'Tポート', 59);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, 'トップガン', 60);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, 'そよら上飯田', 61);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, 'ヤマナカ 西枇杷島', 62);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, '豊川', 63);
INSERT IGNORE INTO sales_areas (company_id, area_name, sort_order) VALUES (@klg, '未定', 64);

-- ===== master: eval_sheet_templates (1件) =====
INSERT INTO eval_sheet_templates (company_id, template_name, department_key, company_vision, three_year_goal, ideal_person, company_challenge, demotion_rule) VALUES (@klg, 'LiberTeen 通信事業部 人事評価表', 'LiberTeen/通信事業部', '\"人として\"を通じてすべての人に感動と居場所を', '営業部全体で年商5億', '明確な目標を持ち、ともに会社を成長させていくことに尽力できる人材', '管理者排出のための営業力、マネジメント力、ヒューマンスキル向上', '2回連続で20点以下の場合は降格') ON DUPLICATE KEY UPDATE template_name=VALUES(template_name);

-- ===== master: eval_sheet_categories (5件) =====
INSERT INTO eval_sheet_categories (template_id, category_number, title, question, answer, sub_question1, sub_answer1, sub_question2, sub_answer2, sub_question3, sub_answer3, sort_order) VALUES (1,1,'自社の存在価値','自社の存在価値は？','\"人として\"を通じてすべての人に感動と居場所を','自社の存在価値を実現させるために必要な人材は？','「利他思考」と「自責思考」を持ち合わせている人材','人材が身につけるべき能力は？','社会でのコミュニケーションの中で必要な"ヒューマンスキル"（心理的成熟、技術的成熟）','その能力が発揮されるには：2つ',NULL,1);
INSERT INTO eval_sheet_categories (template_id, category_number, title, question, answer, sub_question1, sub_answer1, sub_question2, sub_answer2, sub_question3, sub_answer3, sort_order) VALUES (1,2,'3年後の会社ビジョン','3年後の会社をどのようにしていきたいか？','営業部全体で年商5億','そのためにはどのような人材が必要なのか？','B to C営業、B to B営業のスキルがあり、人事として人材管理ができる人材','その人材が身につけるべき能力は？','営業力、採用力、マネジメント力','その能力が発揮されるには：2つ',NULL,2);
INSERT INTO eval_sheet_categories (template_id, category_number, title, question, answer, sub_question1, sub_answer1, sub_question2, sub_answer2, sub_question3, sub_answer3, sort_order) VALUES (1,3,'社長・会社の理想とする人材','社長・会社の理想とする人材は？','明確な目標を持ち、ともに会社を成長させていくことに尽力できる人材','その人材が身につけるべき考え方は？','日々の業務について「企業理念」を「自分ごと」として捉える考え方','その考え方が発揮されるには：2つ',NULL,NULL,NULL,3);
INSERT INTO eval_sheet_categories (template_id, category_number, title, question, answer, sub_question1, sub_answer1, sub_question2, sub_answer2, sub_question3, sub_answer3, sort_order) VALUES (1,4,'業務姿勢評価',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,4);
INSERT INTO eval_sheet_categories (template_id, category_number, title, question, answer, sub_question1, sub_answer1, sub_question2, sub_answer2, sub_question3, sub_answer3, sort_order) VALUES (1,5,'会社の課題解決','会社が解決すべき課題は？','管理者排出のための営業力、マネジメント力、ヒューマンスキル向上','通信事業部が解決しべき課題は？','実績を重要視しない風潮から結果（営業実績）に対する拘りの無さ','課題を解決するためにやるべきことは？','明確な実績（営業実績）目標を策定し、何が何でも達成する','"5-2"のやるべきことから個人目標を決定する',NULL,5);

-- ===== master: eval_sheet_items (9件) =====
INSERT INTO eval_sheet_items (category_id, item_number, item_name, score_type, max_score, criteria_5, criteria_3, criteria_1, criteria_custom, sort_order) VALUES (1,1,'弊社スタッフと十分なコミュニケーションを取り、「報連相」の徹底、事前報告ができていたか','5point',5,'指摘無し','指摘1回','指摘2回以上',NULL,1);
INSERT INTO eval_sheet_items (category_id, item_number, item_name, score_type, max_score, criteria_5, criteria_3, criteria_1, criteria_custom, sort_order) VALUES (1,2,'活動店舗やクライアントから身だしなみや勤務態度、人間関係、業務的な内容で指摘がないか','5point',5,'無し','1回','2回以上',NULL,2);
INSERT INTO eval_sheet_items (category_id, item_number, item_name, score_type, max_score, criteria_5, criteria_3, criteria_1, criteria_custom, sort_order) VALUES (2,3,'店舗精査達成率 ※複数名店舗の場合は全体の精査','5point',5,'平均120%以上','平均100%〜120%未満','100%未満',NULL,3);
INSERT INTO eval_sheet_items (category_id, item_number, item_name, score_type, max_score, criteria_5, criteria_3, criteria_1, criteria_custom, sort_order) VALUES (2,4,'店舗予算達成率 ※複数名店舗の場合、個人獲得件数＝店舗予算÷人数','5point',5,'100%以上','80%以上100%未満','80%未満',NULL,4);
INSERT INTO eval_sheet_items (category_id, item_number, item_name, score_type, max_score, criteria_5, criteria_3, criteria_1, criteria_custom, sort_order) VALUES (3,5,'個人の課題を改善し、スキルアップをしようとする姿勢が見られたか','5point',5,'よく見受けられる','たまに見受けられる','見受けられない',NULL,5);
INSERT INTO eval_sheet_items (category_id, item_number, item_name, score_type, max_score, criteria_5, criteria_3, criteria_1, criteria_custom, sort_order) VALUES (3,6,'どうすれば組織の成果が最大化できるか、主体的に判断・行動ができているか','5point',5,'出来ている','時々出来ていない事がある','出来ていない事が多い',NULL,6);
INSERT INTO eval_sheet_items (category_id, item_number, item_name, score_type, max_score, criteria_5, criteria_3, criteria_1, criteria_custom, sort_order) VALUES (4,7,'遅刻しない。やむを得ない理由で遅刻欠勤の場合、指定時刻より前に上長に報告の徹底（稼働、会議）','5point',5,'無し','1回','2回以上',NULL,7);
INSERT INTO eval_sheet_items (category_id, item_number, item_name, score_type, max_score, criteria_5, criteria_3, criteria_1, criteria_custom, sort_order) VALUES (4,8,'指定時刻より前に「稼働日報」を記入漏れなく送信徹底','5point',5,'指摘無し','指摘1回','指摘2回以上',NULL,8);
INSERT INTO eval_sheet_items (category_id, item_number, item_name, score_type, max_score, criteria_5, criteria_3, criteria_1, criteria_custom, sort_order) VALUES (5,9,'各店舗メイン商材の獲得件数（BB、モバイル等）※3ヵ月ごとに目標件数を上長と設定','10point',10,NULL,NULL,NULL,'平均達成率: 10点=100%、6点=90%以上100%未満、2点=80%以上90%未満、0点=80%未満',9);

