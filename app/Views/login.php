<?php $page='login'; ?>
<main class="login-shell">
    <section class="login-story">
        <div class="login-brand"><span class="brand-mark">青</span><span>青山 ESG</span></div>
        <div class="story-content">
            <span class="eyebrow light">SUSTAINABILITY INTELLIGENCE</span>
            <h1>讓每一筆永續數據<br>都能被信任</h1>
            <p>從跨廠區填報、碳排計算到查證佐證，建立可量化、可追溯、可決策的 ESG 資料鏈。</p>
            <div class="story-stats"><span><b>ISO</b> 14064-1</span><span><b>GRI</b> 2021</span><span><b>Scope</b> 1–3</span></div>
        </div>
        <small>Enterprise ESG System · Internal Use Only</small>
    </section>
    <section class="login-panel">
        <form method="post" action="<?= e(url('/login')) ?>" class="login-card">
            <?= csrf_field() ?>
            <span class="eyebrow">SECURE ACCESS</span>
            <h2>歡迎回來</h2>
            <p>請使用企業帳號登入永續管理平台。</p>
            <label>電子郵件<input type="email" name="email" autocomplete="username" required placeholder="name@company.com"></label>
            <label>密碼<input type="password" name="password" autocomplete="current-password" required placeholder="輸入密碼"></label>
            <button class="btn primary wide" type="submit">安全登入 <span>→</span></button>
            <small>連續登入失敗 5 次將暫停 15 分鐘</small>
        </form>
    </section>
</main>

