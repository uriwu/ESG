# ESG 企業永續智慧管理系統

依據 `SPEC-ESG-2026-V1.0` 建置的 PHP 8.1+、MySQL 8.0+ 企業 ESG 管理系統核心版本。系統採用單一公開入口、PDO 參數化查詢、Session 與 CSRF 保護、RBAC 權限控制及不可竄改操作軌跡，適合部署於 XAMPP 或相容的 LAMP/WAMP 環境。

## 線上系統

公開執行網址：<https://darksalmon-eagle-978314.hostingersite.com/uri8764>

此專案需要 PHP 與 MySQL／MariaDB 執行環境，因此正式系統部署於 Hostinger；GitHub 儲存庫提供公開原始碼、資料庫結構、測試與安裝說明。GitHub Pages 僅支援靜態網站，不能直接執行本專案的後端與資料庫功能。

## 已實作範圍

- MOD-01：組織樹、盤查邊界、基期年度、五種標準角色、帳號建立、首次登入強制改密碼、登入防暴力嘗試與稽核日誌。
- MOD-02：Scope 1–3／ISO 類別 1–6 排放源、版本化係數、有效期間與活動單位匹配、七氣體中的 CO2／CH4／N2O 核心換算、月活動數據及 ±30% 波動警示。
- MOD-03：多元平等、職安、訓練及供應鏈社會指標資料庫。
- MOD-04：董事會、誠信、反貪腐、訓練、吹哨案件及重大性主題加權排序。
- MOD-05：附件 MIME 白名單、UUID 檔名、SHA-256 完整性、填報 → 廠級初審 → 委員會複審 → 核准鎖定／退件補正。
- MOD-06：碳排總量、Scope 分布、月趨勢、廠區排行、待辦中心、ISO 14064 清冊與 GRI 索引 CSV 匯出。
- REST API：JWT 登入、排放源清單、活動數據建立、工作流審核、戰情室摘要及 IoT 抄表草稿。

種子資料中的排放係數標明為 `DEMO`，只供流程驗證。正式上線前必須由 ESG 專責人員以環境部、能源署或經查證的企業專屬係數覆核，不得直接用於正式揭露。

## XAMPP 安裝

1. 將整個資料夾複製至 `C:\xampp\htdocs\esg_system`。
2. 複製 `.env.example` 為 `.env`，設定資料庫密碼、至少 32 字元的 `APP_KEY` 與 IoT API Key。
   若主機要求資料表前綴，設定例如 `DB_PREFIX=uri8764_`；程式、安裝腳本、外鍵與查詢都會自動套用此前綴。
3. 建立專用資料庫帳號；帳號需要 `esg_system` 資料庫的 DDL 與 DML 權限，勿讓網站使用 MySQL `root`。
4. 在命令提示字元執行：

```bat
C:\xampp\php\php.exe database\install.php admin@your-company.com "TemporaryPass123!"
C:\xampp\php\php.exe tests\CarbonEngineTest.php
C:\xampp\php\php.exe tests\WorkflowServiceTest.php
```

5. 將 Apache VirtualHost 的 `DocumentRoot` 指向 `C:/xampp/htdocs/esg_system/public`，並啟用 `mod_rewrite` 與 `mod_headers`：

```apache
<VirtualHost *:80>
    ServerName esg.local
    DocumentRoot "C:/xampp/htdocs/esg_system/public"
    <Directory "C:/xampp/htdocs/esg_system/public">
        AllowOverride All
        Require all granted
        Options -Indexes
    </Directory>
</VirtualHost>
```

6. 在 Windows hosts 檔加入 `127.0.0.1 esg.local`，重啟 Apache 後開啟 `http://esg.local`。
7. 首次登入會強制變更暫時密碼。正式環境需配置 HTTPS，並將 `APP_URL` 改為 HTTPS 網址。

## 重要目錄

```text
app/Services/       碳排、工作流、JWT 與稽核業務服務
app/Views/          中文響應式操作介面
core/               環境、資料庫與共用安全函式
database/           MySQL Schema 與安裝程式
public/             Apache 唯一對外目錄、入口與靜態資源
public/uploads/     佐證附件，禁止 PHP 執行
storage/            日誌與報表暫存
tests/              不需 PHPUnit 的核心邏輯測試
```

## API 範例

取得 JWT：

```http
POST /api/v1/auth/login
Content-Type: application/json

{"email":"admin@your-company.com","password":"your-password"}
```

後續請求加入 `Authorization: Bearer <token>`。IoT 端點使用獨立的 `X-API-Key`，抄表資料只會建立為草稿，必須由人員補上原始佐證後才能送審。

## 上線前驗收

- 以官方試算表交叉驗證用電、柴油等至少 20 組基準數據，誤差須符合規格書要求。
- 以不同年度資料確認係數有效期間隔離。
- 使用五種角色逐一測試資料範圍、送審、退件、複審與核准後鎖定。
- 使用無毒測試字串驗證 SQL Injection、XSS、CSRF、上傳副檔名與 MIME 白名單。
- 設定每日差異備份、每週全備份及異機加密留存，再實際執行一次還原演練。
