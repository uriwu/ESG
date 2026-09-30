<?php
declare(strict_types=1);

use App\Services\AuditService;
use App\Services\CarbonEngine;
use App\Services\JwtService;
use App\Services\WorkflowService;
use Core\Database;
use Core\Env;

require dirname(__DIR__) . '/core/Env.php';
Env::load(dirname(__DIR__) . '/.env');
date_default_timezone_set((string) Env::get('APP_TIMEZONE', 'Asia/Taipei'));

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Core\\' => dirname(__DIR__) . '/core/',
        'App\\' => dirname(__DIR__) . '/app/',
    ];
    foreach ($prefixes as $prefix => $base) {
        if (str_starts_with($class, $prefix)) {
            $file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    }
});

require dirname(__DIR__) . '/core/helpers.php';

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.save_path', dirname(__DIR__) . '/storage/sessions');
if (str_starts_with((string) Env::get('APP_URL', ''), 'https://')) {
    ini_set('session.cookie_secure', '1');
}
session_name('ESGSESSID');
session_start();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = '/' . trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$path = $path === '//' ? '/' : $path;
$basePath = '/' . trim((string) parse_url((string) Env::get('APP_URL', ''), PHP_URL_PATH), '/');
if ($basePath !== '/' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
    $path = substr($path, strlen($basePath)) ?: '/';
}

try {
    $pdo = Database::connection();
    $audit = new AuditService($pdo);
    $carbon = new CarbonEngine();
    $workflow = new WorkflowService();

    if (str_starts_with($path, '/api/v1/')) {
        handle_api($method, $path, $pdo, $audit, $carbon, $workflow);
    }

    if ($method === 'GET' && $path === '/login') {
        if (current_user()) {
            redirect('/dashboard');
        }
        render('login', ['title' => '系統登入']);
    }

    if ($method === 'POST' && $path === '/login') {
        verify_csrf();
        $email = strtolower(trim((string) input('email')));
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $limit = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE email=? AND ip_address=? AND succeeded=0 AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
        $limit->execute([$email, $ip]);
        if ((int) $limit->fetchColumn() >= 5) {
            flash('danger', '登入嘗試次數過多，請 15 分鐘後再試。');
            redirect('/login');
        }
        $stmt = $pdo->prepare('SELECT u.*, r.role_code, r.role_name, o.org_name FROM users u JOIN roles r ON r.role_id=u.role_id LEFT JOIN organizations o ON o.org_id=u.org_id WHERE u.email=? AND u.status=1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        $ok = $user && password_verify((string) input('password'), $user['password_hash']);
        $pdo->prepare('INSERT INTO login_attempts (email, ip_address, succeeded) VALUES (?, ?, ?)')->execute([$email, $ip, $ok ? 1 : 0]);
        if (!$ok) {
            $audit->record($user['user_id'] ?? null, 'LOGIN_FAILED', 'users', $user['user_id'] ?? null);
            flash('danger', '帳號或密碼錯誤。');
            redirect('/login');
        }
        session_regenerate_id(true);
        unset($user['password_hash']);
        $_SESSION['user'] = $user;
        $pdo->prepare('UPDATE users SET last_login_at=NOW() WHERE user_id=?')->execute([$user['user_id']]);
        $audit->record((int) $user['user_id'], 'LOGIN', 'users', $user['user_id']);
        redirect(!empty($user['must_change_password']) ? '/password' : '/dashboard');
    }

    if ($method === 'POST' && $path === '/logout') {
        verify_csrf();
        $user = current_user();
        if ($user) {
            $audit->record((int) $user['user_id'], 'LOGOUT', 'users', $user['user_id']);
        }
        $_SESSION = [];
        session_destroy();
        redirect('/login');
    }

    $user = require_auth();
    if (!empty($user['must_change_password']) && !in_array($path, ['/password','/logout'], true)) {
        flash('danger','首次登入請先設定新的高強度密碼。');
        redirect('/password');
    }

    if ($method === 'GET' && $path === '/users') {
        require_role('ROLE_SUPER_ADMIN');
        $users = $pdo->query('SELECT u.user_id,u.name,u.email,u.status,u.must_change_password,u.last_login_at,o.org_name,r.role_code,r.role_name FROM users u JOIN roles r ON r.role_id=u.role_id LEFT JOIN organizations o ON o.org_id=u.org_id ORDER BY u.status DESC,u.name')->fetchAll();
        $organizations = $pdo->query('SELECT org_id,org_name FROM organizations WHERE status=1 ORDER BY org_name')->fetchAll();
        $roles = $pdo->query('SELECT role_id,role_code,role_name FROM roles ORDER BY role_id')->fetchAll();
        render('page', compact('users','organizations','roles','user') + ['page'=>'users','title'=>'帳號與權限']);
    }

    if ($method === 'POST' && $path === '/users') {
        require_role('ROLE_SUPER_ADMIN');
        verify_csrf();
        $email = strtolower(trim((string) input('email')));
        $name = trim((string) input('name'));
        $password = (string) input('password');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '' || strlen($password) < 12) {
            throw new InvalidArgumentException('請輸入有效電子郵件、姓名及至少 12 字元的暫時密碼。');
        }
        $data=[(int)input('org_id')?:null,(int)input('role_id'),$name,$email,password_hash($password,PASSWORD_DEFAULT)];
        $pdo->prepare('INSERT INTO users (org_id,role_id,name,email,password_hash,must_change_password) VALUES (?,?,?,?,?,1)')->execute($data);
        $audit->record((int)$user['user_id'],'CREATE','users',(int)$pdo->lastInsertId(),null,['email'=>$email,'role_id'=>$data[1]]);
        flash('success','使用者帳號已建立，首次登入必須修改密碼。');
        redirect('/users');
    }

    if ($method === 'GET' && $path === '/password') {
        render('page', compact('user') + ['page'=>'password','title'=>'變更密碼']);
    }

    if ($method === 'POST' && $path === '/password') {
        verify_csrf();
        $current=(string)input('current_password'); $new=(string)input('new_password'); $confirm=(string)input('new_password_confirmation');
        $stmt=$pdo->prepare('SELECT password_hash FROM users WHERE user_id=?');$stmt->execute([$user['user_id']]);$hash=(string)$stmt->fetchColumn();
        if(!password_verify($current,$hash)) throw new InvalidArgumentException('目前密碼不正確。');
        if(strlen($new)<12||$new!==$confirm||!preg_match('/[A-Z]/',$new)||!preg_match('/[a-z]/',$new)||!preg_match('/\d/',$new)) throw new InvalidArgumentException('新密碼至少 12 字元，且需包含英文大小寫與數字，兩次輸入必須一致。');
        $pdo->prepare('UPDATE users SET password_hash=?,must_change_password=0 WHERE user_id=?')->execute([password_hash($new,PASSWORD_DEFAULT),$user['user_id']]);
        $_SESSION['user']['must_change_password']=0;
        $audit->record((int)$user['user_id'],'PASSWORD_CHANGE','users',$user['user_id']);
        flash('success','密碼已安全更新。');
        redirect('/dashboard');
    }

    if ($method === 'GET' && preg_match('#^/attachments/(\d+)$#',$path,$m)) {
        $stmt=$pdo->prepare('SELECT att.*,s.org_id FROM attachments att JOIN activity_data a ON a.data_id=att.data_id JOIN ghg_sources s ON s.source_id=a.source_id WHERE att.attachment_id=?');$stmt->execute([(int)$m[1]]);$file=$stmt->fetch();
        if(!$file) { http_response_code(404); exit('找不到附件。'); }
        if(!has_role('ROLE_SUPER_ADMIN','ROLE_ESG_COMMITTEE','ROLE_AUDITOR') && (int)$file['org_id'] !== (int)$user['org_id']) { http_response_code(403); exit('不得存取其他組織的附件。'); }
        $target=base_path('public/uploads/'.$file['stored_name']);
        if(!is_file($target)||!hash_equals($file['sha256'],hash_file('sha256',$target))) { http_response_code(410); exit('附件不存在或完整性檢查失敗。'); }
        $audit->record((int)$user['user_id'],'DOWNLOAD','attachments',(int)$file['attachment_id']);
        header('Content-Type: '.$file['mime_type']);
        header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode($file['original_name']));
        header('Content-Length: '.filesize($target));
        readfile($target); exit;
    }

    if ($method === 'GET' && ($path === '/' || $path === '/dashboard')) {
        $year = (int) input('year', date('Y'));
        $orgScope = organization_scope('s.org_id');
        $summaryStmt = $pdo->prepare("SELECT COALESCE(SUM(a.calculated_tco2e),0) total,
            COALESCE(SUM(CASE WHEN s.scope='scope1' THEN a.calculated_tco2e ELSE 0 END),0) scope1,
            COALESCE(SUM(CASE WHEN s.scope='scope2' THEN a.calculated_tco2e ELSE 0 END),0) scope2,
            COALESCE(SUM(CASE WHEN s.scope='scope3' THEN a.calculated_tco2e ELSE 0 END),0) scope3,
            COUNT(*) approved_count
            FROM activity_data a JOIN ghg_sources s ON s.source_id=a.source_id
            WHERE a.status='approved' AND a.period_year=? AND {$orgScope['sql']}");
        $summaryStmt->execute(array_merge([$year], $orgScope['params']));
        $summary = $summaryStmt->fetch();
        $trendStmt = $pdo->prepare("SELECT a.period_month month, ROUND(SUM(a.calculated_tco2e),6) value FROM activity_data a JOIN ghg_sources s ON s.source_id=a.source_id WHERE a.status='approved' AND a.period_year=? AND {$orgScope['sql']} GROUP BY a.period_month ORDER BY a.period_month");
        $trendStmt->execute(array_merge([$year], $orgScope['params']));
        $trend = $trendStmt->fetchAll();
        $orgStmt = $pdo->prepare("SELECT o.org_name, ROUND(SUM(a.calculated_tco2e),6) value FROM activity_data a JOIN ghg_sources s ON s.source_id=a.source_id JOIN organizations o ON o.org_id=s.org_id WHERE a.status='approved' AND a.period_year=? AND {$orgScope['sql']} GROUP BY o.org_id,o.org_name ORDER BY value DESC LIMIT 8");
        $orgStmt->execute(array_merge([$year], $orgScope['params']));
        $orgRanking = $orgStmt->fetchAll();
        $pending = (int) $pdo->query("SELECT COUNT(*) FROM activity_data WHERE status IN ('pending_review','pending_approval')")->fetchColumn();
        $anomalies = (int) $pdo->query("SELECT COUNT(*) FROM activity_data WHERE anomaly_flag=1 AND status<>'approved'")->fetchColumn();
        render('page', compact('user', 'year', 'summary', 'trend', 'orgRanking', 'pending', 'anomalies') + ['page' => 'dashboard', 'title' => 'ESG 數位戰情室']);
    }

    if ($path === '/organizations' && $method === 'GET') {
        require_role('ROLE_SUPER_ADMIN', 'ROLE_ESG_COMMITTEE', 'ROLE_AUDITOR');
        $organizations = $pdo->query('SELECT o.*, p.org_name parent_name FROM organizations o LEFT JOIN organizations p ON p.org_id=o.parent_id ORDER BY COALESCE(o.parent_id,0), o.org_name')->fetchAll();
        render('page', compact('organizations', 'user') + ['page' => 'organizations', 'title' => '組織與盤查邊界']);
    }

    if ($path === '/organizations' && $method === 'POST') {
        require_role('ROLE_SUPER_ADMIN');
        verify_csrf();
        $data = [
            'parent_id' => input('parent_id') ?: null,
            'org_code' => strtoupper(trim((string) input('org_code'))),
            'org_name' => trim((string) input('org_name')),
            'boundary_type' => (string) input('boundary_type'),
            'base_year' => (int) input('base_year'),
        ];
        if ($data['org_code'] === '' || $data['org_name'] === '' || !in_array($data['boundary_type'], ['operational','financial','equity'], true)) {
            throw new InvalidArgumentException('組織代碼、名稱與盤查邊界必填。');
        }
        $stmt = $pdo->prepare('INSERT INTO organizations (parent_id,org_code,org_name,boundary_type,base_year) VALUES (:parent_id,:org_code,:org_name,:boundary_type,:base_year)');
        $stmt->execute($data);
        $audit->record((int) $user['user_id'], 'CREATE', 'organizations', (int) $pdo->lastInsertId(), null, $data);
        flash('success', '組織節點已建立。');
        redirect('/organizations');
    }

    if ($path === '/sources' && $method === 'GET') {
        $orgScope=organization_scope('s.org_id');$stmt=$pdo->prepare("SELECT s.*, o.org_name FROM ghg_sources s JOIN organizations o ON o.org_id=s.org_id WHERE {$orgScope['sql']} ORDER BY o.org_name,s.scope,s.source_name");$stmt->execute($orgScope['params']);$sources=$stmt->fetchAll();
        $orgScope2=organization_scope('org_id');$stmt=$pdo->prepare("SELECT org_id,org_name FROM organizations WHERE status=1 AND {$orgScope2['sql']} ORDER BY org_name");$stmt->execute($orgScope2['params']);$organizations=$stmt->fetchAll();
        render('page', compact('sources', 'organizations', 'user') + ['page' => 'sources', 'title' => '排放源管理']);
    }

    if ($path === '/sources' && $method === 'POST') {
        require_role('ROLE_SUPER_ADMIN', 'ROLE_ESG_COMMITTEE', 'ROLE_DEPT_REVIEWER');
        verify_csrf();
        $data = [(int) input('org_id'), input('scope'), (int) input('iso_category'), trim((string) input('source_type')), trim((string) input('source_name')), trim((string) input('activity_unit'))];
        if (!has_role('ROLE_SUPER_ADMIN','ROLE_ESG_COMMITTEE') && $data[0] !== (int)$user['org_id']) {
            throw new DomainException('只能建立所屬組織的排放源。');
        }
        if ($data[0] < 1 || !in_array($data[1], ['scope1','scope2','scope3'], true) || $data[2] < 1 || $data[2] > 6 || $data[3] === '' || $data[4] === '' || $data[5] === '') {
            throw new InvalidArgumentException('請完整填寫排放源資料。');
        }
        $pdo->prepare('INSERT INTO ghg_sources (org_id,scope,iso_category,source_type,source_name,activity_unit) VALUES (?,?,?,?,?,?)')->execute($data);
        $audit->record((int) $user['user_id'], 'CREATE', 'ghg_sources', (int) $pdo->lastInsertId(), null, ['source_name' => $data[4]]);
        flash('success', '排放源已建立。');
        redirect('/sources');
    }

    if ($path === '/factors' && $method === 'GET') {
        $factors = $pdo->query('SELECT * FROM emission_factors ORDER BY effective_start_date DESC,factor_name')->fetchAll();
        render('page', compact('factors', 'user') + ['page' => 'factors', 'title' => '碳排係數版本庫']);
    }

    if ($path === '/factors' && $method === 'POST') {
        require_role('ROLE_SUPER_ADMIN', 'ROLE_ESG_COMMITTEE');
        verify_csrf();
        $data = [
            strtoupper(trim((string) input('factor_code'))), trim((string) input('factor_name')), trim((string) input('activity_unit')),
            (float) input('co2_factor'), (float) input('ch4_factor', 0), (float) input('n2o_factor', 0),
            (float) input('ch4_gwp', 28), (float) input('n2o_gwp', 265), trim((string) input('source_agency')),
            trim((string) input('version_label')), (string) input('effective_start_date'), (string) input('effective_end_date'),
        ];
        if ($data[0] === '' || $data[1] === '' || $data[2] === '' || $data[8] === '' || $data[10] === '' || $data[11] === '' || $data[11] < $data[10]) {
            throw new InvalidArgumentException('係數代碼、名稱、單位、來源與有效日期必填。');
        }
        $stmt = $pdo->prepare('INSERT INTO emission_factors (factor_code,factor_name,activity_unit,co2_factor,ch4_factor,n2o_factor,ch4_gwp,n2o_gwp,source_agency,version_label,effective_start_date,effective_end_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute($data);
        $audit->record((int) $user['user_id'], 'CREATE', 'emission_factors', (int) $pdo->lastInsertId(), null, ['factor_code' => $data[0]]);
        flash('success', '排放係數版本已建立。');
        redirect('/factors');
    }

    if ($path === '/activity' && $method === 'GET') {
        $year = (int) input('year', date('Y'));
        $orgScope=organization_scope('s.org_id');
        $stmt = $pdo->prepare("SELECT a.*,s.source_name,s.scope,s.activity_unit,o.org_name,f.factor_name,COUNT(att.attachment_id) attachment_count,GROUP_CONCAT(CONCAT(att.attachment_id,'::',att.original_name) SEPARATOR '||') attachments
            FROM activity_data a JOIN ghg_sources s ON s.source_id=a.source_id JOIN organizations o ON o.org_id=s.org_id JOIN emission_factors f ON f.factor_id=a.factor_id LEFT JOIN attachments att ON att.data_id=a.data_id
            WHERE a.period_year=? AND {$orgScope['sql']} GROUP BY a.data_id ORDER BY a.period_month DESC,a.updated_at DESC");
        $stmt->execute(array_merge([$year],$orgScope['params']));
        $activities = $stmt->fetchAll();
        $stmt=$pdo->prepare("SELECT s.*,o.org_name FROM ghg_sources s JOIN organizations o ON o.org_id=s.org_id WHERE s.status=1 AND {$orgScope['sql']} ORDER BY o.org_name,s.source_name");$stmt->execute($orgScope['params']);$sources=$stmt->fetchAll();
        $factors = $pdo->query('SELECT * FROM emission_factors ORDER BY effective_start_date DESC,factor_name')->fetchAll();
        render('page', compact('activities', 'sources', 'factors', 'year', 'user') + ['page' => 'activity', 'title' => '活動數據填報']);
    }

    if ($path === '/activity' && $method === 'POST') {
        require_role('ROLE_SUPER_ADMIN', 'ROLE_DATA_OPERATOR', 'ROLE_DEPT_REVIEWER', 'ROLE_ESG_COMMITTEE');
        verify_csrf();
        $sourceId = (int) input('source_id');
        $factorId = (int) input('factor_id');
        $year = (int) input('period_year');
        $month = (int) input('period_month');
        $usage = (float) input('usage_amount');
        $sourceStmt = $pdo->prepare('SELECT * FROM ghg_sources WHERE source_id=? AND status=1');
        $sourceStmt->execute([$sourceId]);
        $source = $sourceStmt->fetch();
        if ($source && !has_role('ROLE_SUPER_ADMIN','ROLE_ESG_COMMITTEE','ROLE_AUDITOR') && (int)$source['org_id'] !== (int)$user['org_id']) {
            throw new DomainException('不得填報其他組織的排放源。');
        }
        $factorStmt = $pdo->prepare('SELECT * FROM emission_factors WHERE factor_id=? AND ? BETWEEN effective_start_date AND effective_end_date');
        $periodDate = sprintf('%04d-%02d-01', $year, $month);
        $factorStmt->execute([$factorId, $periodDate]);
        $factor = $factorStmt->fetch();
        if (!$source || !$factor || $source['activity_unit'] !== $factor['activity_unit'] || $usage < 0 || $month < 1 || $month > 12) {
            throw new InvalidArgumentException('排放源、係數、單位、期間或用量不正確；係數必須在填報月份有效。');
        }
        $prevStmt = $pdo->prepare("SELECT usage_amount FROM activity_data WHERE source_id=? AND (period_year*100+period_month) < ? AND status='approved' ORDER BY period_year DESC,period_month DESC LIMIT 3");
        $prevStmt->execute([$sourceId, $year * 100 + $month]);
        $anomaly = $carbon->anomaly($usage, $prevStmt->fetchAll(PDO::FETCH_COLUMN));
        $note = trim((string) input('anomaly_note'));
        if ($anomaly['is_anomaly'] && $note === '') {
            throw new InvalidArgumentException('本期數值較前三期平均波動超過 ±30%，請填寫異常原因。');
        }
        $tco2e = $carbon->calculate($usage, (float) $factor['co2_factor'], (float) $factor['ch4_factor'], (float) $factor['n2o_factor'], (float) $factor['ch4_gwp'], (float) $factor['n2o_gwp']);
        if (empty($_FILES['evidence']['tmp_name'])) {
            throw new InvalidArgumentException('每筆活動數據必須上傳一份原始單據或佐證附件。');
        }
        $dataId = Database::transaction(function (PDO $pdo) use ($sourceId, $factorId, $year, $month, $usage, $tco2e, $anomaly, $note, $user): int {
            $stmt = $pdo->prepare('INSERT INTO activity_data (source_id,factor_id,period_year,period_month,usage_amount,calculated_tco2e,anomaly_flag,anomaly_deviation_pct,anomaly_note,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$sourceId,$factorId,$year,$month,$usage,$tco2e,$anomaly['is_anomaly'] ? 1 : 0,$anomaly['deviation_pct'],$note ?: null,$user['user_id']]);
            return (int) $pdo->lastInsertId();
        });
        save_attachment($pdo, $dataId, (int) $user['user_id'], $_FILES['evidence']);
        $audit->record((int) $user['user_id'], 'CREATE', 'activity_data', $dataId, null, ['usage_amount' => $usage, 'calculated_tco2e' => $tco2e]);
        flash('success', '活動數據已儲存為草稿，碳排放量為 ' . number_format($tco2e, 6) . ' tCO₂e。');
        redirect('/activity?year=' . $year);
    }

    if ($method === 'POST' && preg_match('#^/activity/(\d+)/submit$#', $path, $m)) {
        require_role('ROLE_SUPER_ADMIN', 'ROLE_DATA_OPERATOR', 'ROLE_DEPT_REVIEWER', 'ROLE_ESG_COMMITTEE');
        verify_csrf();
        $id = (int) $m[1];
        $stmt = $pdo->prepare('SELECT a.*,s.org_id FROM activity_data a JOIN ghg_sources s ON s.source_id=a.source_id WHERE a.data_id=? FOR UPDATE');
        Database::transaction(function (PDO $pdo) use ($stmt, $id, $workflow, $audit, $user): void {
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) throw new RuntimeException('找不到填報資料。');
            if (!has_role('ROLE_SUPER_ADMIN','ROLE_ESG_COMMITTEE','ROLE_AUDITOR') && (int)$row['org_id'] !== (int)$user['org_id']) throw new DomainException('不得送審其他組織的資料。');
            $count = $pdo->prepare('SELECT COUNT(*) FROM attachments WHERE data_id=?');
            $count->execute([$id]);
            if ((int) $count->fetchColumn() === 0) throw new DomainException('送審前必須上傳至少一份佐證附件。');
            $next = $workflow->transition($row['status'], 'submit');
            $pdo->prepare('UPDATE activity_data SET status=?,rejection_reason=NULL WHERE data_id=?')->execute([$next,$id]);
            $pdo->prepare('INSERT INTO workflow_tasks (data_id,action,from_status,to_status,comment,actor_id) VALUES (?,?,?,?,?,?)')->execute([$id,'submit',$row['status'],$next,'提交審核',$user['user_id']]);
            $audit->record((int) $user['user_id'], 'SUBMIT', 'activity_data', $id, $row, ['status' => $next]);
        });
        flash('success', '資料已提交初審。');
        redirect('/activity');
    }

    if ($path === '/workflow' && $method === 'GET') {
        require_role('ROLE_SUPER_ADMIN', 'ROLE_DEPT_REVIEWER', 'ROLE_ESG_COMMITTEE', 'ROLE_AUDITOR');
        $orgScope=organization_scope('s.org_id');$stmt=$pdo->prepare("SELECT a.*,s.source_name,s.scope,o.org_name,f.factor_name,u.name creator_name,COUNT(att.attachment_id) attachment_count,GROUP_CONCAT(CONCAT(att.attachment_id,'::',att.original_name) SEPARATOR '||') attachments
            FROM activity_data a JOIN ghg_sources s ON s.source_id=a.source_id JOIN organizations o ON o.org_id=s.org_id JOIN emission_factors f ON f.factor_id=a.factor_id JOIN users u ON u.user_id=a.created_by LEFT JOIN attachments att ON att.data_id=a.data_id
            WHERE a.status IN ('pending_review','pending_approval','rejected') AND {$orgScope['sql']} GROUP BY a.data_id ORDER BY a.updated_at");$stmt->execute($orgScope['params']);$tasks=$stmt->fetchAll();
        render('page', compact('tasks', 'user') + ['page' => 'workflow', 'title' => '多階層審核中心']);
    }

    if ($method === 'POST' && preg_match('#^/workflow/(\d+)/review$#', $path, $m)) {
        require_role('ROLE_SUPER_ADMIN', 'ROLE_DEPT_REVIEWER', 'ROLE_ESG_COMMITTEE');
        verify_csrf();
        $id = (int) $m[1];
        $action = (string) input('action');
        $comment = trim((string) input('comment'));
        Database::transaction(function (PDO $pdo) use ($id, $action, $comment, $workflow, $audit, $user): void {
            $stmt = $pdo->prepare('SELECT a.*,s.org_id FROM activity_data a JOIN ghg_sources s ON s.source_id=a.source_id WHERE a.data_id=? FOR UPDATE');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) throw new RuntimeException('找不到填報資料。');
            if ($row['status'] === 'pending_review' && !has_role('ROLE_SUPER_ADMIN','ROLE_ESG_COMMITTEE') && (int)$row['org_id'] !== (int)$user['org_id']) throw new DomainException('不得審核其他組織的資料。');
            if ($row['status'] === 'pending_review' && !has_role('ROLE_SUPER_ADMIN','ROLE_DEPT_REVIEWER')) throw new DomainException('此筆資料正在初審階段。');
            if ($row['status'] === 'pending_approval' && !has_role('ROLE_SUPER_ADMIN','ROLE_ESG_COMMITTEE')) throw new DomainException('此筆資料正在委員會複審階段。');
            if ($action === 'reject' && $comment === '') throw new InvalidArgumentException('退件必須填寫原因。');
            $next = $workflow->transition($row['status'], $action);
            $pdo->prepare('UPDATE activity_data SET status=?,rejection_reason=?,approved_by=?,locked_at=? WHERE data_id=?')->execute([
                $next, $action === 'reject' ? $comment : null, $next === 'approved' ? $user['user_id'] : null, $next === 'approved' ? date('Y-m-d H:i:s') : null, $id,
            ]);
            $pdo->prepare('INSERT INTO workflow_tasks (data_id,action,from_status,to_status,comment,actor_id) VALUES (?,?,?,?,?,?)')->execute([$id,$action,$row['status'],$next,$comment ?: null,$user['user_id']]);
            $audit->record((int) $user['user_id'], strtoupper($action), 'activity_data', $id, $row, ['status' => $next, 'comment' => $comment]);
        });
        flash('success', $action === 'approve' ? '審核已完成。' : '資料已退件補正。');
        redirect('/workflow');
    }

    if ($path === '/social' && $method === 'GET') {
        $metrics = $pdo->query('SELECT s.*,o.org_name FROM social_metrics s JOIN organizations o ON o.org_id=s.org_id ORDER BY metric_year DESC,category,metric_name')->fetchAll();
        $organizations = $pdo->query('SELECT org_id,org_name FROM organizations WHERE status=1 ORDER BY org_name')->fetchAll();
        render('page', compact('metrics','organizations','user') + ['page' => 'social', 'title' => '社會責任指標']);
    }

    if ($path === '/social' && $method === 'POST') {
        require_role('ROLE_SUPER_ADMIN','ROLE_ESG_COMMITTEE','ROLE_DATA_OPERATOR','ROLE_DEPT_REVIEWER');
        verify_csrf();
        $data = [(int) input('org_id'),(int) input('metric_year'),trim((string) input('metric_code')),trim((string) input('metric_name')),(string) input('category'),(float) input('value'),trim((string) input('unit')),trim((string) input('note')) ?: null,(int) $user['user_id']];
        if (!$data[0] || !$data[1] || $data[2] === '' || $data[3] === '' || !in_array($data[4], ['diversity','safety','training','supply_chain'], true) || $data[6] === '') throw new InvalidArgumentException('請完整填寫社會指標。');
        $pdo->prepare('INSERT INTO social_metrics (org_id,metric_year,metric_code,metric_name,category,value,unit,note,created_by) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE metric_name=VALUES(metric_name),category=VALUES(category),value=VALUES(value),unit=VALUES(unit),note=VALUES(note)')->execute($data);
        $audit->record((int) $user['user_id'], 'UPSERT', 'social_metrics', $data[2], null, ['value'=>$data[5]]);
        flash('success','社會責任指標已儲存。');
        redirect('/social');
    }

    if ($path === '/governance' && $method === 'GET') {
        $records = $pdo->query('SELECT g.*,o.org_name FROM governance_records g JOIN organizations o ON o.org_id=g.org_id ORDER BY record_year DESC,category,metric_name')->fetchAll();
        $topics = $pdo->query('SELECT *,ROUND(stakeholder_score*business_impact_score*weight,4) priority_score FROM materiality_topics ORDER BY topic_year DESC,priority_score DESC')->fetchAll();
        $organizations = $pdo->query('SELECT org_id,org_name FROM organizations WHERE status=1 ORDER BY org_name')->fetchAll();
        render('page', compact('records','topics','organizations','user') + ['page' => 'governance', 'title' => '公司治理與重大性']);
    }

    if ($path === '/governance' && $method === 'POST') {
        require_role('ROLE_SUPER_ADMIN','ROLE_ESG_COMMITTEE','ROLE_DATA_OPERATOR');
        verify_csrf();
        $kind = (string) input('kind','record');
        if ($kind === 'topic') {
            $data=[(int)input('topic_year'),trim((string)input('topic_name')),(float)input('stakeholder_score'),(float)input('business_impact_score'),(float)input('weight',1)];
            if(!$data[0]||$data[1]===''||$data[2]<0||$data[2]>5||$data[3]<0||$data[3]>5||$data[4]<=0) throw new InvalidArgumentException('重大主題分數需介於 0 至 5。');
            $pdo->prepare('INSERT INTO materiality_topics (topic_year,topic_name,stakeholder_score,business_impact_score,weight) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE stakeholder_score=VALUES(stakeholder_score),business_impact_score=VALUES(business_impact_score),weight=VALUES(weight)')->execute($data);
            $audit->record((int)$user['user_id'],'UPSERT','materiality_topics',$data[1],null,['scores'=>[$data[2],$data[3]]]);
        } else {
            $data=[(int)input('org_id'),(int)input('record_year'),(string)input('category'),trim((string)input('metric_name')),trim((string)input('metric_value')),trim((string)input('note'))?:null,(int)$user['user_id']];
            if(!$data[0]||!$data[1]||!in_array($data[2],['board','ethics','anti_corruption','training','whistleblower'],true)||$data[3]===''||$data[4]==='') throw new InvalidArgumentException('請完整填寫治理指標。');
            $pdo->prepare('INSERT INTO governance_records (org_id,record_year,category,metric_name,metric_value,note,created_by) VALUES (?,?,?,?,?,?,?)')->execute($data);
            $audit->record((int)$user['user_id'],'CREATE','governance_records',(int)$pdo->lastInsertId(),null,['metric_name'=>$data[3]]);
        }
        flash('success','治理資料已儲存。');
        redirect('/governance');
    }

    if ($path === '/reports' && $method === 'GET') {
        $years = $pdo->query('SELECT DISTINCT period_year FROM activity_data ORDER BY period_year DESC')->fetchAll(PDO::FETCH_COLUMN);
        render('page', compact('years','user') + ['page' => 'reports', 'title' => '合規報表中心']);
    }

    if ($method === 'GET' && in_array($path, ['/reports/iso.csv','/reports/gri.csv'], true)) {
        $year = (int) input('year', date('Y'));
        if ($path === '/reports/iso.csv') export_iso_csv($pdo, $year);
        export_gri_csv($pdo, $year);
    }

    if ($path === '/audit' && $method === 'GET') {
        require_role('ROLE_SUPER_ADMIN','ROLE_ESG_COMMITTEE','ROLE_AUDITOR');
        $logs = $pdo->query('SELECT a.*,u.name user_name FROM audit_logs a LEFT JOIN users u ON u.user_id=a.user_id ORDER BY a.audit_id DESC LIMIT 500')->fetchAll();
        render('page', compact('logs','user') + ['page' => 'audit', 'title' => '不可竄改稽核軌跡']);
    }

    http_response_code(404);
    render('page', ['page'=>'not_found','title'=>'找不到頁面','user'=>$user]);
} catch (Throwable $e) {
    $status = $e instanceof InvalidArgumentException || $e instanceof DomainException ? 422 : 500;
    http_response_code($status);
    if (str_starts_with($path, '/api/')) {
        json_response(['code'=>$status,'status'=>'error','message'=>$e->getMessage()],$status);
    }
    $errorMessage = Env::get('APP_ENV') === 'development' ? $e->getMessage() : '系統處理失敗，請聯絡管理員。';
    error_log($e->__toString());
    render('page', ['page'=>'error','title'=>'系統訊息','errorMessage'=>$errorMessage,'user'=>current_user()]);
}

function save_attachment(PDO $pdo, int $dataId, int $userId, array $file): void
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('附件上傳失敗。');
    $max = (int) env('UPLOAD_MAX_BYTES', 52428800);
    if ((int) $file['size'] > $max) throw new InvalidArgumentException('附件超過 50MB 上限。');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = ['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'=>'xlsx'];
    if (!isset($allowed[$mime])) throw new InvalidArgumentException('附件僅允許 PDF、JPG、PNG、XLSX。');
    $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', random_int(0,65535),random_int(0,65535),random_int(0,65535),random_int(16384,20479),random_int(32768,49151),random_int(0,65535),random_int(0,65535),random_int(0,65535));
    $stored = $uuid . '.' . $allowed[$mime];
    $target = base_path('public/uploads/' . $stored);
    if (!move_uploaded_file($file['tmp_name'], $target)) throw new RuntimeException('無法儲存附件。');
    $pdo->prepare('INSERT INTO attachments (data_id,original_name,stored_name,mime_type,file_size,sha256,uploaded_by) VALUES (?,?,?,?,?,?,?)')->execute([$dataId,basename((string)$file['name']),$stored,$mime,(int)$file['size'],hash_file('sha256',$target),$userId]);
}

function bearer_user(PDO $pdo): array
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) json_response(['code'=>401,'status'=>'error','message'=>'缺少 Bearer Token'],401);
    $claims = (new JwtService((string) env('APP_KEY')))->verify($m[1]);
    $stmt=$pdo->prepare('SELECT u.*,r.role_code FROM users u JOIN roles r ON r.role_id=u.role_id WHERE u.user_id=? AND u.status=1');
    $stmt->execute([(int)$claims['sub']]);
    $user=$stmt->fetch();
    if(!$user) json_response(['code'=>401,'status'=>'error','message'=>'Token 使用者不存在'],401);
    return $user;
}

function request_json(): array
{
    return json_decode(file_get_contents('php://input') ?: '{}', true, 512, JSON_THROW_ON_ERROR);
}

function handle_api(string $method,string $path,PDO $pdo,AuditService $audit,CarbonEngine $carbon,WorkflowService $workflow): never
{
    if($method==='POST'&&$path==='/api/v1/auth/login'){
        $d=request_json(); $stmt=$pdo->prepare('SELECT u.*,r.role_code FROM users u JOIN roles r ON r.role_id=u.role_id WHERE u.email=? AND u.status=1'); $stmt->execute([strtolower(trim((string)($d['email']??'')))]); $u=$stmt->fetch();
        if(!$u||!password_verify((string)($d['password']??''),$u['password_hash'])) json_response(['code'=>401,'status'=>'error','message'=>'帳號或密碼錯誤'],401);
        $token=(new JwtService((string)env('APP_KEY')))->issue(['sub'=>(int)$u['user_id'],'role'=>$u['role_code']]);
        json_response(['code'=>200,'status'=>'success','message'=>'登入成功','data'=>['token'=>$token,'expires_in'=>3600]]);
    }
    if($method==='POST'&&$path==='/api/v1/iot/meter-data'){
        $key=(string)($_SERVER['HTTP_X_API_KEY']??''); $stmt=$pdo->prepare('SELECT api_key_id FROM api_keys WHERE key_hash=? AND active=1');$stmt->execute([hash('sha256',$key)]);
        if(!$stmt->fetchColumn()) json_response(['code'=>401,'status'=>'error','message'=>'IoT API Key 無效'],401);
        $d=request_json(); $sourceId=(int)($d['source_id']??0);$year=(int)($d['period_year']??0);$month=(int)($d['period_month']??0);$usage=(float)($d['usage_amount']??-1);
        $s=$pdo->prepare('SELECT * FROM ghg_sources WHERE source_id=? AND status=1');$s->execute([$sourceId]);$source=$s->fetch();
        if(!$source||$usage<0||$month<1||$month>12) json_response(['code'=>422,'status'=>'error','message'=>'來源、期間或數值無效'],422);
        $f=$pdo->prepare('SELECT * FROM emission_factors WHERE activity_unit=? AND ? BETWEEN effective_start_date AND effective_end_date ORDER BY is_custom DESC,effective_start_date DESC LIMIT 1');$f->execute([$source['activity_unit'],sprintf('%04d-%02d-01',$year,$month)]);$factor=$f->fetch();
        if(!$factor) json_response(['code'=>422,'status'=>'error','message'=>'找不到當期有效排放係數'],422);
        $tco2e=$carbon->calculate($usage,(float)$factor['co2_factor'],(float)$factor['ch4_factor'],(float)$factor['n2o_factor'],(float)$factor['ch4_gwp'],(float)$factor['n2o_gwp']);
        $systemUser=(int)$pdo->query("SELECT user_id FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.role_code='ROLE_SUPER_ADMIN' ORDER BY user_id LIMIT 1")->fetchColumn();
        $pdo->prepare('INSERT INTO activity_data (source_id,factor_id,period_year,period_month,usage_amount,calculated_tco2e,status,anomaly_note,created_by) VALUES (?,?,?,?,?,?,' . "'draft'" . ',?,?) ON DUPLICATE KEY UPDATE usage_amount=VALUES(usage_amount),calculated_tco2e=VALUES(calculated_tco2e),factor_id=VALUES(factor_id),updated_at=NOW()')->execute([$sourceId,$factor['factor_id'],$year,$month,$usage,$tco2e,'IoT 自動抄表，待人工補充佐證並送審',$systemUser]);
        $id=(int)$pdo->lastInsertId();$audit->record($systemUser,'IOT_UPSERT','activity_data',$id?:$sourceId,null,['usage_amount'=>$usage,'tco2e'=>$tco2e]);
        json_response(['code'=>200,'status'=>'success','message'=>'抄表資料已建立為草稿，待補佐證送審','data'=>['data_id'=>$id,'source_name'=>$source['source_name'],'period'=>sprintf('%04d-%02d',$year,$month),'usage_amount'=>$usage,'unit'=>$source['activity_unit'],'calculated_tco2e'=>$tco2e,'status'=>'draft']]);
    }
    $user=bearer_user($pdo);
    if($method==='GET'&&$path==='/api/v1/ghg/sources'){
        json_response(['code'=>200,'status'=>'success','message'=>'排放源清單','data'=>$pdo->query('SELECT * FROM ghg_sources WHERE status=1')->fetchAll()]);
    }
    if($method==='GET'&&$path==='/api/v1/dashboard/summary'){
        $year=(int)($_GET['year']??date('Y')); $stmt=$pdo->prepare("SELECT s.scope,ROUND(SUM(a.calculated_tco2e),6) tco2e FROM activity_data a JOIN ghg_sources s ON s.source_id=a.source_id WHERE a.status='approved' AND a.period_year=? GROUP BY s.scope");$stmt->execute([$year]);
        json_response(['code'=>200,'status'=>'success','message'=>'戰情室摘要','data'=>$stmt->fetchAll()]);
    }
    if($method==='POST'&&$path==='/api/v1/ghg/activity-data'){
        if(!in_array($user['role_code'],['ROLE_SUPER_ADMIN','ROLE_DATA_OPERATOR','ROLE_DEPT_REVIEWER','ROLE_ESG_COMMITTEE'],true)) json_response(['code'=>403,'status'=>'error','message'=>'沒有填報權限'],403);
        $d=request_json();$sourceId=(int)($d['source_id']??0);$factorId=(int)($d['factor_id']??0);$year=(int)($d['period_year']??0);$month=(int)($d['period_month']??0);$usage=(float)($d['usage_amount']??-1);
        $s=$pdo->prepare('SELECT * FROM ghg_sources WHERE source_id=? AND status=1');$s->execute([$sourceId]);$source=$s->fetch();$f=$pdo->prepare('SELECT * FROM emission_factors WHERE factor_id=? AND ? BETWEEN effective_start_date AND effective_end_date');$f->execute([$factorId,sprintf('%04d-%02d-01',$year,$month)]);$factor=$f->fetch();
        if(!$source||!$factor||$source['activity_unit']!==$factor['activity_unit']||$usage<0||$month<1||$month>12) json_response(['code'=>422,'status'=>'error','message'=>'排放源、係數、單位、期間或數值無效'],422);
        $tco2e=$carbon->calculate($usage,(float)$factor['co2_factor'],(float)$factor['ch4_factor'],(float)$factor['n2o_factor'],(float)$factor['ch4_gwp'],(float)$factor['n2o_gwp']);
        $stmt=$pdo->prepare('INSERT INTO activity_data (source_id,factor_id,period_year,period_month,usage_amount,calculated_tco2e,status,anomaly_note,created_by) VALUES (?,?,?,?,?,?,' . "'draft'" . ',?,?)');$stmt->execute([$sourceId,$factorId,$year,$month,$usage,$tco2e,'API 建立，待補佐證送審',$user['user_id']]);$id=(int)$pdo->lastInsertId();
        $audit->record((int)$user['user_id'],'API_CREATE','activity_data',$id,null,['usage_amount'=>$usage,'calculated_tco2e'=>$tco2e]);
        json_response(['code'=>201,'status'=>'success','message'=>'盤查數據已計算並建立為草稿；請由網頁補上佐證後送審','data'=>['data_id'=>$id,'source_name'=>$source['source_name'],'period'=>sprintf('%04d-%02d',$year,$month),'usage_amount'=>$usage,'unit'=>$source['activity_unit'],'calculated_tco2e'=>$tco2e,'status'=>'draft']],201);
    }
    if($method==='PUT'&&preg_match('#^/api/v1/workflow/review/(\d+)$#',$path,$m)){
        $d=request_json();$action=(string)($d['action']??'');$comment=trim((string)($d['comment']??''));$id=(int)$m[1];
        $stmt=$pdo->prepare('SELECT * FROM activity_data WHERE data_id=?');$stmt->execute([$id]);$row=$stmt->fetch();if(!$row) json_response(['code'=>404,'status'=>'error','message'=>'找不到資料'],404);
        $allowed=($row['status']==='pending_review'&&in_array($user['role_code'],['ROLE_SUPER_ADMIN','ROLE_DEPT_REVIEWER'],true))||($row['status']==='pending_approval'&&in_array($user['role_code'],['ROLE_SUPER_ADMIN','ROLE_ESG_COMMITTEE'],true));
        if(!$allowed) json_response(['code'=>403,'status'=>'error','message'=>'角色與目前審核階段不符'],403);if($action==='reject'&&$comment==='') json_response(['code'=>422,'status'=>'error','message'=>'退件原因必填'],422);
        $next=$workflow->transition($row['status'],$action);$pdo->prepare('UPDATE activity_data SET status=?,rejection_reason=?,approved_by=?,locked_at=? WHERE data_id=?')->execute([$next,$action==='reject'?$comment:null,$next==='approved'?$user['user_id']:null,$next==='approved'?date('Y-m-d H:i:s'):null,$id]);$pdo->prepare('INSERT INTO workflow_tasks (data_id,action,from_status,to_status,comment,actor_id) VALUES (?,?,?,?,?,?)')->execute([$id,$action,$row['status'],$next,$comment?:null,$user['user_id']]);$audit->record((int)$user['user_id'],'API_'.strtoupper($action),'activity_data',$id,$row,['status'=>$next]);
        json_response(['code'=>200,'status'=>'success','message'=>'審核狀態已更新','data'=>['data_id'=>$id,'status'=>$next]]);
    }
    json_response(['code'=>404,'status'=>'error','message'=>'API 端點不存在'],404);
}

function csv_output(string $filename,array $headers,array $rows): never
{
    header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="'.$filename.'"'); echo "\xEF\xBB\xBF"; $out=fopen('php://output','wb'); fputcsv($out,$headers); foreach($rows as $r) fputcsv($out,$r); fclose($out); exit;
}

function export_iso_csv(PDO $pdo,int $year): never
{
    $stmt=$pdo->prepare("SELECT o.org_name,s.scope,s.iso_category,s.source_name,a.period_month,a.usage_amount,s.activity_unit,f.factor_code,f.factor_name,f.source_agency,a.calculated_tco2e,a.status FROM activity_data a JOIN ghg_sources s ON s.source_id=a.source_id JOIN organizations o ON o.org_id=s.org_id JOIN emission_factors f ON f.factor_id=a.factor_id WHERE a.period_year=? AND a.status='approved' ORDER BY o.org_name,s.scope,s.source_name,a.period_month");$stmt->execute([$year]);
    csv_output("iso-14064-inventory-{$year}.csv",['組織','範疇','ISO 類別','排放源','月份','活動數據','單位','係數代碼','係數名稱','係數來源','tCO2e','狀態'],$stmt->fetchAll(PDO::FETCH_NUM));
}

function export_gri_csv(PDO $pdo,int $year): never
{
    $stmt=$pdo->prepare('SELECT o.org_name,s.metric_code,s.metric_name,s.category,s.value,s.unit,s.note FROM social_metrics s JOIN organizations o ON o.org_id=s.org_id WHERE s.metric_year=? ORDER BY s.metric_code');$stmt->execute([$year]);
    csv_output("gri-content-index-{$year}.csv",['組織','GRI/指標代碼','揭露項目','主題','數值','單位','備註'],$stmt->fetchAll(PDO::FETCH_NUM));
}
