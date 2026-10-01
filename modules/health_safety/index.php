<?php
require_once __DIR__.'/../../includes/helpers.php';
require_once __DIR__.'/../../includes/service_client.php';

require_login();

$svc=service('health');

if($_SERVER['REQUEST_METHOD']==='POST'){
    try {
        $message=$svc->handle(
            (string)($_POST['action']??''),
            $_POST,
            current_user()
        );
        flash('success',$message);
    } catch(Throwable $e) {
        flash('error','Unable to save record: '.$e->getMessage());
    }

    redirect('/modules/health_safety/index.php');
}

$filterDate=(string)($_GET['date']??'');
$showAllHealth=isset($_GET['health_all']) && $_GET['health_all']==='1';
$showAllIncidents=isset($_GET['incident_all']) && $_GET['incident_all']==='1';

$incidents=$svc->incidents($filterDate);
$health=$svc->healthRecords($filterDate);

page_header('Health, Safety & Welfare','health');
show_flash();
?>

<div class="gw-breadcrumb">
    <span>Great Solomon Manpower Services Inc.</span>
    <span>/</span>
    <strong>Health, Safety &amp; Welfare</strong>
</div>

<section class="gw-hero">
    <div>
        <div class="eyebrow">MODULE 1</div>
        <h1>Health, Safety &amp; Welfare</h1>
        <p>Review health records and safety incidents.</p>
    </div>
</section>

<section class="gw-stats">
    <div class="gw-stat">
        <span class="gw-stat-label">Incidents</span>
        <div class="gw-stat-value"><?=count($incidents)?></div>
        <div class="gw-stat-meta">Recorded</div>
    </div>

    <div class="gw-stat">
        <span class="gw-stat-label">Health Records</span>
        <div class="gw-stat-value"><?=count($health)?></div>
        <div class="gw-stat-meta">Recorded</div>
    </div>
</section>

<section class="gw-panel" style="margin-top:20px" id="health-records">
    <div class="gw-panel-head">
        <div>
            <h2>Health Records</h2>
            <span>
                <?= $showAllHealth ? 'Showing all matching records' : 'Showing the latest 5 records' ?>
                <?= $filterDate ? ' for '.e($filterDate) : '' ?>
            </span>
        </div>

        <span class="material-symbols-outlined">manage_search</span>
    </div>

    <form method="get"
          class="date-filter"
          style="padding:0 18px 14px;justify-content:flex-end">

        <span class="dashboard-date-filter-label">
            <span class="material-symbols-outlined">filter_alt</span>
            Record date
        </span>

        <input type="date"
               name="date"
               value="<?=e($filterDate)?>">

        <button class="gw-btn secondary">
            Filter
        </button>

        <?php if($filterDate):?>
            <a class="gw-btn secondary"
               href="<?=e(url('/modules/health_safety/index.php'))?>#health-records">
                Clear
            </a>
        <?php endif;?>

        <?php if($showAllHealth):?>

            <a class="gw-btn secondary"
               href="<?=e(url('/modules/health_safety/index.php'.($filterDate?'?date='.rawurlencode($filterDate):'')))?>#health-records">
                Show latest 5
            </a>

        <?php else:?>

            <a class="gw-btn primary"
               href="<?=e(url('/modules/health_safety/index.php?health_all=1'.($filterDate?'&date='.rawurlencode($filterDate):'')))?>#health-records">
                See all health records
            </a>

        <?php endif;?>
    </form>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Checkup Date</th>
                    <th>Record Type</th>
                    <th>Fitness Status</th>
                    <th>Notes</th>
                </tr>
            </thead>

            <tbody>
                <?php
                $healthView=$showAllHealth
                    ? $svc->healthRecords($filterDate)
                    : array_slice($health,0,5);

                foreach($healthView as $r):
                ?>

                <tr>
                    <td><?=e($r['employee_name'])?></td>
                    <td><?=e($r['checkup_date'])?></td>
                    <td><?=e($r['record_type'])?></td>
                    <td><?=e($r['fitness_status'])?></td>
                    <td><?=e($r['notes'])?></td>
                </tr>

                <?php endforeach;?>

                <?php if(!$healthView):?>

                <tr>
                    <td colspan="5" class="empty">
                        No health records for the selected filter.
                    </td>
                </tr>

                <?php endif;?>
            </tbody>
        </table>
    </div>
</section>

<section class="gw-panel" style="margin-top:20px" id="incident-reports">
    <div class="gw-panel-head">
        <div>
            <h2>Safety Incident Reports</h2>

            <span>
                <?= $showAllIncidents ? 'Showing all matching records' : 'Showing the latest 5 records' ?>
                <?= $filterDate ? ' for '.e($filterDate) : '' ?>
            </span>
        </div>

        <span class="material-symbols-outlined">manage_search</span>
    </div>

    <form method="get"
          class="date-filter"
          style="padding:0 18px 14px;justify-content:flex-end">

        <span class="dashboard-date-filter-label">
            <span class="material-symbols-outlined">filter_alt</span>
            Incident date
        </span>

        <input type="date"
               name="date"
               value="<?=e($filterDate)?>">

        <button class="gw-btn secondary">
            Filter
        </button>

        <?php if($filterDate):?>

            <a class="gw-btn secondary"
               href="<?=e(url('/modules/health_safety/index.php'))?>#incident-reports">
                Clear
            </a>

        <?php endif;?>

        <?php if($showAllIncidents):?>

            <a class="gw-btn secondary"
               href="<?=e(url('/modules/health_safety/index.php'.($filterDate?'?date='.rawurlencode($filterDate):'')))?>#incident-reports">
                Show latest 5
            </a>

        <?php else:?>

            <a class="gw-btn primary"
               href="<?=e(url('/modules/health_safety/index.php?incident_all=1'.($filterDate?'&date='.rawurlencode($filterDate):'')))?>#incident-reports">
                See all safety incident reports
            </a>

        <?php endif;?>
    </form>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Employee</th>
                    <th>Date</th>
                    <th>Severity</th>
                    <th>Status</th>
                </tr>
            </thead>

            <tbody>
                <?php
                $incidentView=$showAllIncidents
                    ? $incidents
                    : array_slice($incidents,0,5);

                foreach($incidentView as $r):
                ?>

                <tr>
                    <td><?=e($r['title'])?></td>
                    <td><?=e($r['employee_name'])?></td>
                    <td><?=e($r['incident_date'])?></td>
                    <td><?=e($r['severity'])?></td>
                    <td><?=e($r['status'])?></td>
                </tr>

                <?php endforeach;?>

                <?php if(!$incidentView):?>

                <tr>
                    <td colspan="5" class="empty">
                        No safety incident reports for the selected filter.
                    </td>
                </tr>

                <?php endif;?>
            </tbody>
        </table>
    </div>
</section>

<?php page_footer(); ?>
