<?php $flashes = consume_flash(); $isLogin = ($page ?? '') === 'login'; ?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="color-scheme" content="light">
    <title><?= e($title ?? '') ?>｜<?= e(env('APP_NAME','ESG 管理系統')) ?></title>
    <link rel="stylesheet" href="<?= e(url('/assets/app.css')) ?>">
    <script defer src="<?= e(url('/assets/app.js')) ?>"></script>
</head>
<body class="<?= $isLogin ? 'login-body' : '' ?>">
<?php if (!$isLogin && current_user()): ?>
<div class="shell">
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="<?= e(url('/dashboard')) ?>">
            <span class="brand-mark">青</span>
            <span><strong>青山 ESG</strong><small>永續智慧管理系統</small></span>
        </a>
        <nav>
            <?php
            $nav=[
                ['/dashboard','dashboard','總覽戰情室','⌂'],['/organizations','organizations','組織與邊界','◇'],['/users','users','帳號與權限','♙'],['/sources','sources','排放源管理','♧'],['/factors','factors','排放係數庫','ƒ'],['/activity','activity','活動數據填報','＋'],['/workflow','workflow','審核工作流','✓'],['/social','social','社會責任','◎'],['/governance','governance','公司治理','▦'],['/reports','reports','合規報表','⇩'],['/audit','audit','稽核軌跡','◷']
            ];
            foreach($nav as [$href,$key,$label,$icon]):
                if($key==='organizations'&&!has_role('ROLE_SUPER_ADMIN','ROLE_ESG_COMMITTEE','ROLE_AUDITOR')) continue;
                if($key==='users'&&!has_role('ROLE_SUPER_ADMIN')) continue;
                if($key==='workflow'&&!has_role('ROLE_SUPER_ADMIN','ROLE_DEPT_REVIEWER','ROLE_ESG_COMMITTEE','ROLE_AUDITOR')) continue;
                if($key==='audit'&&!has_role('ROLE_SUPER_ADMIN','ROLE_ESG_COMMITTEE','ROLE_AUDITOR')) continue;
            ?>
            <a href="<?= e(url($href)) ?>" class="<?= ($page??'')===$key?'active':'' ?>"><span><?= $icon ?></span><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-foot">
            <div class="user-row"><span class="avatar"><?= e(mb_substr((string)current_user()['name'],0,1)) ?></span><span><strong><?= e(current_user()['name']) ?></strong><small><?= e(current_user()['role_name']) ?></small></span></div>
            <a class="text-button" href="<?= e(url('/password')) ?>">變更密碼</a>
            <form method="post" action="<?= e(url('/logout')) ?>"><?= csrf_field() ?><button class="text-button">登出系統</button></form>
        </div>
    </aside>
    <main class="main">
        <header class="topbar"><button class="menu-button" data-menu aria-label="開啟選單">☰</button><div><span class="eyebrow">ENTERPRISE SUSTAINABILITY</span><h1><?= e($title ?? '') ?></h1></div><span class="period-pill">盤查年度 <?= e((string)($year??date('Y'))) ?></span></header>
        <?php foreach($flashes as $flash): ?><div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endforeach; ?>
        <?= $content ?>
    </main>
</div>
<?php else: ?>
    <?php foreach($flashes as $flash): ?><div class="alert login-alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endforeach; ?>
    <?= $content ?>
<?php endif; ?>
</body>
</html>
