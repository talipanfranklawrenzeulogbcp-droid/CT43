<?php
require_once __DIR__.'/../../includes/helpers.php';
require_once __DIR__.'/../../includes/service_client.php';
require_login();
$svc=service('health');
if($_SERVER['REQUEST_METHOD']==='POST'){ verify_csrf(); try{$message=$svc->handle((string)($_POST['action']??''),$_POST,current_user());flash('success',$message);}catch(Throwable $e){flash('error','Unable to save record: '.$e->getMessage());} redirect('/modules/health_safety/index.php'.(!empty($_POST['focus'])?'#'.rawurlencode((string)$_POST['focus']):'')); }
$filterDate=(string)($_GET['date']??'');$showAllHealth=isset($_GET['health_all']);$showAllIncidents=isset($_GET['incident_all']);
$incidents=$svc->incidents($filterDate);$health=$svc->healthRecords($filterDate);$followups=$svc->followups();$incidentActionMap=[];foreach($incidents as $incidentRow){$incidentActionMap[(int)$incidentRow['id']]=$svc->incidentActions((int)$incidentRow['id']);}
$stats=$svc->stats($filterDate);
page_header('Health, Safety & Welfare','health');show_flash(); ?>
<div class="gw-breadcrumb"><span>Great Solomon Manpower Services Inc.</span><span>/</span><strong>Health, Safety &amp; Welfare</strong></div>
<section class="gw-hero"><div><div class="eyebrow">MODULE 1</div><h1>Health, Safety &amp; Welfare</h1><p>Maintain employee health records, safety incident investigations, follow-ups and corrective actions in one workflow.</p></div></section>
<section class="gw-quick-actions">
<a href="#health-record-form"><span class="material-symbols-outlined">medical_information</span> Add Health Record</a>
<a href="#incident-form"><span class="material-symbols-outlined">report_problem</span> Report Safety Incident</a>
<a href="#followup-form"><span class="material-symbols-outlined">event_repeat</span> Add Follow-up</a>
<a href="#health-records"><span class="material-symbols-outlined">list_alt</span> Health Records</a>
<a href="#incident-reports"><span class="material-symbols-outlined">manage_search</span> Incident Reports</a>
</section>
<section class="gw-stats">
<div class="gw-stat"><span class="gw-stat-label">Incidents</span><div class="gw-stat-value"><?=e($stats['incidents'])?></div><div class="gw-stat-meta warning"><?=e($stats['open_incidents'])?> open / under investigation</div></div>
<div class="gw-stat"><span class="gw-stat-label">Health Records</span><div class="gw-stat-value"><?=e($stats['health_records'])?></div><div class="gw-stat-meta">Recorded health checks</div></div>
<div class="gw-stat"><span class="gw-stat-label">Critical Incidents</span><div class="gw-stat-value"><?=e($stats['critical_incidents'])?></div><div class="gw-stat-meta warning">Not closed</div></div>
<div class="gw-stat"><span class="gw-stat-label">Overdue Follow-ups</span><div class="gw-stat-value"><?=e($stats['overdue_followups'])?></div><div class="gw-stat-meta warning">Requires attention</div></div>
</section>

<section class="record-form" id="health-record-form"><div class="gw-panel-head"><h2>Health Record</h2><span>Employee health &amp; fitness information</span></div>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="add_health"><input type="hidden" name="focus" value="health-records">
<div class="form-grid">
<div><label>Employee Name</label><input name="employee_name" required></div><div><label>Employee ID</label><input name="employee_id"></div>
<div><label>Checkup Date</label><input type="date" name="checkup_date" required></div><div><label>Record Type</label><input name="record_type" placeholder="Annual, Pre-employment, Follow-up"></div>
<div><label>Department</label><input name="department"></div><div><label>Position</label><input name="position_title"></div>
<div><label>Contact No.</label><input name="contact_no"></div><div><label>Blood Type</label><input name="blood_type" maxlength="10"></div>
<div><label>Medical Provider</label><input name="medical_provider"></div><div><label>Fitness Status</label><select name="fitness_status"><option>Pending</option><option>Fit</option><option>Fit with Restrictions</option><option>Unfit</option></select></div>
<div><label>Next Checkup</label><input type="date" name="next_checkup_date"></div><div><label>Clearance Expiry</label><input type="date" name="clearance_expiry"></div>
<div><label>Emergency Contact</label><input name="emergency_contact"></div><div><label>Emergency Contact No.</label><input name="emergency_contact_no"></div>
<div class="full"><label>Work Restrictions</label><textarea name="work_restrictions" placeholder="Restrictions, accommodations or return-to-work notes"></textarea></div>
<div class="full"><label>Clinical / Record Notes</label><textarea name="notes" placeholder="Relevant non-sensitive record notes"></textarea></div>
<div><label>Document Reference</label><input name="document_ref" placeholder="File/reference number"></div>
</div><div class="record-actions"><button type="submit" class="gw-btn primary">Save Health Record</button></div></form></section>

<section class="record-form" id="incident-form"><div class="gw-panel-head"><h2>Safety Incident Report</h2><span>Report → investigate → correct → close</span></div>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="add_incident"><input type="hidden" name="focus" value="incident-reports">
<div class="form-grid">
<div><label>Incident Title</label><input name="title" required></div><div><label>Employee / Affected Person</label><input name="employee_name" required></div>
<div><label>Incident Date</label><input type="date" name="incident_date" required></div><div><label>Incident Type</label><input name="incident_type" placeholder="Injury, near miss, property damage"></div>
<div><label>Location</label><input name="location"></div><div><label>Reported By</label><input name="reported_by"></div>
<div><label>Severity</label><select name="severity"><option>Low</option><option selected>Medium</option><option>High</option><option>Critical</option></select></div>
<div><label>Status</label><select name="status"><option>Open</option><option>Under Investigation</option><option>Closed</option></select></div>
<div><label>Injury Type</label><input name="injury_type"></div><div><label>Days Lost</label><input type="number" min="0" name="days_lost" value="0"></div>
<div class="full"><label>Witnesses</label><textarea name="witnesses"></textarea></div>
<div class="full"><label>Description</label><textarea name="description" required></textarea></div>
<div class="full"><label>Immediate Action / First Response</label><textarea name="immediate_action"></textarea></div>
<div class="full"><label>Treatment / Medical Response</label><textarea name="treatment"></textarea></div>
<div class="full"><label>Root Cause / Investigation Finding</label><textarea name="root_cause"></textarea></div>
<div class="full"><label>Corrective Action</label><textarea name="corrective_action"></textarea></div>
<div><label>Investigation Date</label><input type="date" name="investigation_date"></div><div><label>External Report Reference</label><input name="external_report_ref"></div>
</div><div class="record-actions"><button type="submit" class="gw-btn primary">Save Safety Incident</button></div></form></section>

<section class="record-form" id="followup-form"><div class="gw-panel-head"><h2>Health Follow-up</h2><span>Track medical or return-to-work follow-ups</span></div>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="add_followup">
<div class="form-grid"><div><label>Employee</label><input name="employee_name" required></div><div><label>Follow-up Type</label><input name="followup_type" placeholder="Recheck, clearance, accommodation" required></div><div><label>Due Date</label><input type="date" name="due_date" required></div><div><label>Status</label><select name="status"><option>Pending</option><option>Completed</option><option>Cancelled</option><option>Overdue</option></select></div><div class="full"><label>Notes</label><textarea name="notes"></textarea></div></div>
<div class="record-actions"><button type="submit" class="gw-btn primary">Save Follow-up</button></div></form></section>

<section class="gw-panel" id="health-records" style="margin-top:20px"><div class="gw-panel-head"><div><h2>Health Records</h2><span><?= $showAllHealth?'Showing all matching records':'Showing the latest 5 records' ?><?= $filterDate?' for '.e($filterDate):'' ?></span></div><span class="material-symbols-outlined">manage_search</span></div>
<form method="get" class="date-filter" style="padding:0 18px 14px;justify-content:flex-end"><span class="dashboard-date-filter-label"><span class="material-symbols-outlined">filter_alt</span>Record date</span><input type="date" name="date" value="<?=e($filterDate)?>"><button type="submit" class="gw-btn secondary">Filter</button><?php if($filterDate):?><a class="gw-btn secondary" href="<?=e(url('/modules/health_safety/index.php'))?>#health-records">Clear</a><?php endif;?><?php if($showAllHealth):?><a class="gw-btn secondary" href="<?=e(url('/modules/health_safety/index.php'.($filterDate?'?date='.rawurlencode($filterDate):'')))?>#health-records">Show latest 5</a><?php else:?><a class="gw-btn primary" href="<?=e(url('/modules/health_safety/index.php?health_all=1'.($filterDate?'&date='.rawurlencode($filterDate):'')))?>#health-records">See all health records</a><?php endif;?></form>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Employee</th><th>Date</th><th>Department / Position</th><th>Fitness</th><th>Next Checkup</th><th>Provider</th><th></th></tr></thead><tbody><?php $view=$showAllHealth?$health:array_slice($health,0,5);foreach($view as $r):?><tr><td><?=e($r['employee_name'])?><br><small><?=e($r['employee_id']??'')?></small></td><td><?=e($r['checkup_date'])?></td><td><?=e(trim(($r['department']??'').' / '.($r['position_title']??''),' /'))?></td><td><?=e($r['fitness_status'])?><?php if(!empty($r['work_restrictions'])):?><br><small>Restrictions recorded</small><?php endif;?></td><td><?=e($r['next_checkup_date']??'—')?></td><td><?=e($r['medical_provider']??'—')?></td><td><form method="post" onsubmit="return confirm('Archive this health record?')"><?=csrf_field()?><input type="hidden" name="action" value="delete_health"><input type="hidden" name="id" value="<?=$r['id']?>"><button type="submit" class="gw-btn btn-danger">Archive</button></form></td></tr><?php endforeach;if(!$view):?><tr><td colspan="7" class="empty">No health records for the selected filter.</td></tr><?php endif;?></tbody></table></div></section>

<section class="gw-panel" style="margin-top:20px"><div class="gw-panel-head"><div><h2>Health Follow-ups</h2><span>Upcoming and overdue care / clearance tasks</span></div><span class="material-symbols-outlined">event_repeat</span></div>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Employee</th><th>Follow-up</th><th>Due</th><th>Status</th><th>Notes</th><th></th></tr></thead><tbody><?php foreach($followups as $f):?><tr><td><?=e($f['employee_name'])?></td><td><?=e($f['followup_type'])?></td><td><?=e($f['due_date'])?></td><td><?=e($f['status'])?></td><td><?=e($f['notes'])?></td><td><?php if($f['status']!=='Completed'):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="update_followup"><input type="hidden" name="id" value="<?=$f['id']?>"><input type="hidden" name="status" value="Completed"><button type="submit" class="gw-btn secondary">Complete</button></form><?php endif;?></td></tr><?php endforeach;if(!$followups):?><tr><td colspan="6" class="empty">No follow-ups recorded.</td></tr><?php endif;?></tbody></table></div></section>

<section class="gw-panel" id="incident-reports" style="margin-top:20px"><div class="gw-panel-head"><div><h2>Safety Incident Reports</h2><span><?= $showAllIncidents?'Showing all matching records':'Showing the latest 5 records' ?><?= $filterDate?' for '.e($filterDate):'' ?></span></div><span class="material-symbols-outlined">health_and_safety</span></div>
<form method="get" class="date-filter" style="padding:0 18px 14px;justify-content:flex-end"><span class="dashboard-date-filter-label"><span class="material-symbols-outlined">filter_alt</span>Incident date</span><input type="date" name="date" value="<?=e($filterDate)?>"><button type="submit" class="gw-btn secondary">Filter</button><?php if($filterDate):?><a class="gw-btn secondary" href="<?=e(url('/modules/health_safety/index.php'))?>#incident-reports">Clear</a><?php endif;?><?php if($showAllIncidents):?><a class="gw-btn secondary" href="<?=e(url('/modules/health_safety/index.php'.($filterDate?'?date='.rawurlencode($filterDate):'')))?>#incident-reports">Show latest 5</a><?php else:?><a class="gw-btn primary" href="<?=e(url('/modules/health_safety/index.php?incident_all=1'.($filterDate?'&date='.rawurlencode($filterDate):'')))?>#incident-reports">See all safety incident reports</a><?php endif;?></form>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Incident</th><th>Type / Location</th><th>Employee</th><th>Date</th><th>Severity</th><th>Status</th><th>Action</th></tr></thead><tbody><?php $iview=$showAllIncidents?$incidents:array_slice($incidents,0,5);foreach($iview as $r):?><tr><td><strong><?=e($r['title'])?></strong><br><small><?=e($r['external_report_ref']??'')?></small></td><td><?=e($r['incident_type']??'—')?><br><?=e($r['location']??'—')?></td><td><?=e($r['employee_name'])?></td><td><?=e($r['incident_date'])?></td><td><span class="ui-status severity-<?=strtolower(e($r['severity']))?>"><?=e($r['severity'])?></span></td><td><span class="ui-status status-<?=strtolower(str_replace(' ','-',e($r['status'])))?>"><?=e($r['status'])?></span></td><td><details><summary class="table-action"><span class="material-symbols-outlined">edit</span><span>Update</span></summary><form method="post" style="min-width:260px;padding-top:10px"><?=csrf_field()?><input type="hidden" name="action" value="update_incident"><input type="hidden" name="id" value="<?=$r['id']?>"><input type="hidden" name="focus" value="incident-reports"><label>Status</label><select name="status"><option <?=($r['status']==='Open'?'selected':'')?>>Open</option><option <?=($r['status']==='Under Investigation'?'selected':'')?>>Under Investigation</option><option <?=($r['status']==='Closed'?'selected':'')?>>Closed</option></select><label>Severity</label><select name="severity"><?php foreach(['Low','Medium','High','Critical'] as $s):?><option <?=($r['severity']===$s?'selected':'')?>><?=e($s)?></option><?php endforeach;?></select><label>Root Cause</label><textarea name="root_cause"><?=e($r['root_cause']??'')?></textarea><label>Corrective Action</label><textarea name="corrective_action"><?=e($r['corrective_action']??'')?></textarea><label>Days Lost</label><input type="number" min="0" name="days_lost" value="<?=e($r['days_lost']??0)?>"><button type="submit" class="gw-btn primary" style="margin-top:8px">Save Update</button></form>
<div class="incident-actions-panel" style="margin-top:12px;padding-top:12px;border-top:1px solid #e2e8f0">
<strong style="display:block;margin-bottom:8px">Corrective / Preventive Actions</strong>
<?php $incidentActions=$incidentActionMap[(int)$r['id']]??[]; if($incidentActions): ?>
<div style="display:grid;gap:8px;margin-bottom:10px">
<?php foreach($incidentActions as $ia): ?>
<div style="padding:9px 10px;border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc">
<div style="display:flex;justify-content:space-between;gap:8px;align-items:center"><strong><?=e($ia['action_type'])?></strong><span class="ui-status"><?=e($ia['status'])?></span></div>
<div style="margin:5px 0"><?=nl2br(e($ia['action_text']))?></div>
<small><?=e($ia['owner']??'Unassigned')?> · Due <?=e($ia['due_date']??'—')?></small>
<?php if($ia['status']!=='Completed'): ?><form method="post" style="margin-top:7px;display:flex;gap:6px;align-items:center"><?=csrf_field()?><input type="hidden" name="action" value="update_incident_action"><input type="hidden" name="id" value="<?=$ia['id']?>"><input type="hidden" name="focus" value="incident-reports"><select name="status"><option <?=($ia['status']==='Open'?'selected':'')?>>Open</option><option <?=($ia['status']==='In Progress'?'selected':'')?>>In Progress</option><option>Completed</option><option>Cancelled</option></select><button type="submit" class="gw-btn secondary">Update</button></form><?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<form method="post" style="margin-top:8px;padding:10px;background:#fff;border:1px solid #e2e8f0;border-radius:10px"><?=csrf_field()?><input type="hidden" name="action" value="add_incident_action"><input type="hidden" name="incident_id" value="<?=$r['id']?>"><input type="hidden" name="focus" value="incident-reports"><div class="form-grid"><div><label>Action Type</label><select name="action_type"><option>Immediate</option><option selected>Corrective</option><option>Preventive</option><option>Investigation</option></select></div><div><label>Owner</label><input name="owner" placeholder="Responsible person"></div><div><label>Due Date</label><input type="date" name="due_date"></div><div><label>Status</label><select name="status"><option>Open</option><option>In Progress</option><option>Completed</option><option>Cancelled</option></select></div><div class="full"><label>Action</label><textarea name="action_text" required placeholder="Describe the corrective or preventive action"></textarea></div></div><button type="submit" class="gw-btn secondary" style="margin-top:8px">Add Action</button></form>
</div></details></td></tr><?php endforeach;if(!$iview):?><tr><td colspan="7" class="empty">No safety incident reports for the selected filter.</td></tr><?php endif;?></tbody></table></div></section>
<?php page_footer(); ?>
