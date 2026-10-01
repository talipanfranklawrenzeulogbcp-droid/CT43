<?php
require_once __DIR__ . '/includes/error_handler.php';

require_once __DIR__.'/includes/helpers.php';
require_once __DIR__.'/includes/service_client.php';
require_login();

$reportDate=(string)($_GET['date']??'');
$u=current_user();

// Load dashboard data (stats and charts)
try {
    $data=service('reports')->dashboard($reportDate);
    $counts=$data['counts'];
    $healthChart=$data['health_chart'];
    $assetIssuanceChart=$data['asset_issuance_chart'];
} catch (Throwable $e) {
    error_log('Dashboard load error: ' . $e->getMessage());
    $counts=['incidents'=>0,'open_incidents'=>0,'health_records'=>0,'obligations'=>0,'overdue'=>0,'audits'=>0];
    $healthChart=['labels'=>[],'values'=>[]];
    $assetIssuanceChart=['labels'=>[],'values'=>[]];
}

// Admin login history (only for administrators)
$loginDate=(string)($_GET['login_date']??'');
$showAllLogins=isset($_GET['login_all']) && $_GET['login_all']==='1';
$loginHistory = [];
if (($u['role'] ?? '') === 'Administrator') {
    try {
        $loginHistory = service('reports')->loginHistory($showAllLogins?null:5,$loginDate);
    } catch (Throwable $e) {
        error_log('Login history load error: ' . $e->getMessage());
        $loginHistory = [];
    }
}

page_header('Reports, Analysis & Dashboard','dashboard'); show_flash(); ?>
<div class="gw-breadcrumb"><span class="material-symbols-outlined">home</span><span>Great Solomon Manpower Services Inc.</span><span>/</span><strong>Reports, Analysis &amp; Dashboard</strong></div>

<section class="gw-hero dashboard-hero">
  <div>
    <div class="eyebrow">CORE TRANSACTION 4</div>
    <div class="dashboard-title-brand">
      <div class="brand-logo-white dashboard-logo-wrap"><img src="<?= e(url('/assets/logo2.svg')) ?>" alt="Great Solomon Manpower Services Inc. logo" class="brand-logo-image"></div>
      <h1>Reports, Analysis &amp; Dashboard</h1>
    </div>
    <p>Central reports, analysis and dashboard for Core Transaction 4<?= $reportDate ? " — showing records for ".e($reportDate) : " — showing all report dates" ?>.</p>
  </div>
</section>

<section class="gw-quick-actions">
<a href="<?= e(url('/modules/health_safety/index.php')) ?>">Health &amp; Safety</a>
<a href="<?= e(url('/modules/legal_compliance/index.php')) ?>">Legal &amp; Compliance</a>
<?php if (($u['role'] ?? '') === 'Administrator'): ?><a href="<?= e(url('/modules/system_admin_security/index.php')) ?>">Security</a><?php endif; ?>
<a href="<?= e(url('/modules/asset_equipment/index.php')) ?>">Assets</a>
<form method="get" class="date-filter dashboard-date-filter" aria-label="Report date filter">
  <input type="date" name="date" value="<?=e($reportDate)?>" aria-label="Filter reports by date">
  <button class="gw-btn secondary" type="submit"><span class="material-symbols-outlined">filter_alt</span> Filter</button>
  <?php if($reportDate): ?><a class="gw-btn secondary" href="<?=e(url('/dashboard.php'))?>"><span class="material-symbols-outlined">close</span> Clear</a><?php endif; ?>
</form>
</section>

<section class="gw-stats">
<div class="gw-stat"><div class="gw-stat-top"><span class="gw-stat-label">Safety Incidents</span><div class="gw-stat-icon"><span class="material-symbols-outlined">health_and_safety</span></div></div><div class="gw-stat-value"><?=$counts['incidents']??0?></div><div class="gw-stat-meta"><?=$counts['open_incidents']??0?> open</div></div>
<div class="gw-stat"><div class="gw-stat-top"><span class="gw-stat-label">Compliance</span><div class="gw-stat-icon"><span class="material-symbols-outlined">gavel</span></div></div><div class="gw-stat-value"><?=$counts['obligations']??0?></div><div class="gw-stat-meta"><?=$counts['overdue']??0?> overdue</div></div>
<div class="gw-stat"><div class="gw-stat-top"><span class="gw-stat-label">Health Records</span><div class="gw-stat-icon"><span class="material-symbols-outlined">local_hospital</span></div></div><div class="gw-stat-value"><?=$counts['health_records']??0?></div><div class="gw-stat-meta">on record</div></div>
<div class="gw-stat"><div class="gw-stat-top"><span class="gw-stat-label">Audits</span><div class="gw-stat-icon"><span class="material-symbols-outlined">assignment_turned_in</span></div></div><div class="gw-stat-value"><?=$counts['audits']??0?></div><div class="gw-stat-meta">scheduled</div></div>
</section>

<section class="gw-chart-grid" aria-label="Reports and analytics charts">
  <article class="gw-panel gw-dashboard-chart-panel">
    <div class="gw-panel-head"><h2>Health, Safety &amp; Governance</h2><span>Live module tracking</span></div>
    <div class="gw-chart-wrap gw-chart-wrap-large"><canvas id="healthGovernanceBarChart"></canvas></div>
  </article>
  <article class="gw-panel gw-dashboard-chart-panel">
    <div class="gw-panel-head"><h2>Assets, Equipment &amp; Issuance</h2><span>Returned · Not Returned · Overdue · Issued — all dates</span></div>
    <div class="gw-chart-wrap gw-chart-wrap-large"><canvas id="assetIssuancePieChart"></canvas></div>
  </article>
</section>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function () {
  const health = <?=json_encode($healthChart, JSON_UNESCAPED_SLASHES)?>;
  const issuance = <?=json_encode($assetIssuanceChart, JSON_UNESCAPED_SLASHES)?>;
  const barCanvas = document.getElementById('healthGovernanceBarChart');
  const pieCanvas = document.getElementById('assetIssuancePieChart');
  if (barCanvas && window.Chart) {
    new Chart(barCanvas, {
      type: 'bar',
      data: {
        labels: health.labels,
        datasets: [{
          label: 'Health, Safety & Governance records',
          data: health.values,
          borderWidth: 1,
          borderRadius: 6
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
        plugins: { legend: { position: 'top' } }
      }
    });
  }
  if (pieCanvas && window.Chart) {
    new Chart(pieCanvas, {type:'pie',data:{labels:issuance.labels,datasets:[{label:'Issuance History',data:issuance.values,borderWidth:1}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'top'}}}});
  }
})();
</script>

<?php
try {
    $summary=service('reports')->operationalSummary();
    $operationalAlerts=$summary['alerts'];
    $recentActivity=$summary['activity'];
} catch (Throwable $e) {
    error_log('Operational summary load error: ' . $e->getMessage());
    $operationalAlerts=[];
    $recentActivity=[];
}
?>
<section class="gw-dashboard-ops">
  <article class="gw-panel">
    <div class="gw-panel-head"><div><h2>Operational Alerts</h2><span>Items that may require follow-up</span></div><span class="material-symbols-outlined">notification_important</span></div>
    <?php if (!$operationalAlerts): ?>
      <div class="gw-empty-state"><span class="material-symbols-outlined">task_alt</span><strong>No urgent operational alerts</strong><span>Overdue asset returns and near-term compliance deadlines will appear here.</span></div>
    <?php else: ?>
      <div class="gw-alert-list">
        <?php foreach (array_slice($operationalAlerts,0,8) as $alert): ?>
          <a class="gw-alert-item" href="<?=e(url($alert['href']??'/dashboard.php'))?>">
            <span class="gw-alert-icon material-symbols-outlined"><?=e($alert['icon']??'alert')?></span>
            <span class="gw-alert-copy"><strong><?=e($alert['title']??'')?></strong><small><?=e($alert['type']??'')?> · <?=e($alert['detail']??'')?></small></span>
            <span class="material-symbols-outlined gw-alert-arrow">chevron_right</span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </article>
  <article class="gw-panel">
    <div class="gw-panel-head"><div><h2>Recent Activity</h2><span>Latest system audit entries</span></div><span class="material-symbols-outlined">history</span></div>
    <?php if (!$recentActivity): ?>
      <div class="gw-empty-state"><span class="material-symbols-outlined">history</span><strong>No activity recorded yet</strong></div>
    <?php else: ?>
      <div class="gw-activity-list">
        <?php foreach ($recentActivity as $activity): ?>
          <div class="gw-activity-item">
            <span class="gw-activity-dot"></span>
            <div><strong><?=e($activity['action']??'')?></strong><small><?=e($activity['module']??'')?> · <?=e($activity['name'] ?: 'System')?><?=!empty($activity['details']) ? ' · '.e($activity['details']) : ''?></small></div>
            <time datetime="<?=e($activity['created_at']??'')?>"> <?=e($activity['created_at']??'')?></time>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </article>
</section>

<?php if (($u['role'] ?? '') === 'Administrator'): ?>
<section id="dashboard-login-history" class="gw-panel login-history-panel" style="margin-top:20px">
  <div class="gw-panel-head"><div><h2>Login History</h2><span><?= $showAllLogins ? 'Showing all matching login records' : 'Showing the latest 5 login records' ?><?= $loginDate ? ' for '.e($loginDate) : '' ?></span></div></div>
  <form method="get" class="date-filter" style="padding:0 18px 14px;justify-content:flex-end"><span class="dashboard-date-filter-label"><span class="material-symbols-outlined">filter_alt</span>Login date</span><input type="date" name="login_date" value="<?=e($loginDate)?>" aria-label="Filter logins by date"><button class="gw-btn secondary" type="submit">Filter</button><?php if($loginDate):?><a class="gw-btn secondary" href="<?=e(url('/dashboard.php'))?>#dashboard-login-history">Clear</a><?php endif;?><?php if($showAllLogins):?><a class="gw-btn secondary" href="<?=e(url('/dashboard.php'.($loginDate?'?login_date='.rawurlencode($loginDate):'')))?>#dashboard-login-history">Show latest 5</a><?php else:?><a class="gw-btn primary" href="<?=e(url('/dashboard.php?login_all=1'.($loginDate?'&login_date='.rawurlencode($loginDate):'')))?>#dashboard-login-history">See all logins</a><?php endif;?></form>
  <div class="table-wrap"><table class="data-table"><thead><tr><th>Name</th><th>Gmail</th><th>Role</th><th>Date &amp; Time</th></tr></thead><tbody><?php foreach($loginHistory as $login): ?><tr><td><?=e($login['user_name']??'Unknown')?></td><td><?=e($login['email']??'—')?></td><td><?=e($login['role']??'—')?></td><td><?=e($login['login_at']??'')?></td></tr><?php endforeach; if(!$loginHistory): ?><tr><td colspan="4" class="empty">No login records for the selected filter.</td></tr><?php endif; ?></tbody></table></div>
</section>
<?php endif; ?>

<?php page_footer(); ?>
