<?php
final class ReportsService {
 public function __construct(private HealthSafetyService $health,private LegalComplianceService $legal,private AdminSecurityService $admin,private AssetEquipmentService $assets){}
 public function staffActivity(int $limit=30):array{$limit=max(1,min(100,$limit));$m=['Health, Safety & Welfare','Legal & Compliance','Asset & Equipment Issuance'];$p=implode(',',array_fill(0,count($m),'?'));$q=$this->health->pdoForReporting()->prepare("SELECT a.module,a.action,a.details,a.created_at,u.name AS staff_name,u.email FROM audit_logs a INNER JOIN users u ON u.id=a.user_id WHERE u.role='Staff' AND a.module IN ($p) ORDER BY a.created_at DESC LIMIT {$limit}");$q->execute($m);return $q->fetchAll();}
 public function staffAdminAudit(int $limit=100):array{$limit=max(1,min(200,$limit));return $this->health->pdoForReporting()->query("SELECT a.module,a.action,a.details,a.created_at,COALESCE(u.name,'System') AS name,COALESCE(u.email,'—') AS email,COALESCE(u.role,'System') AS role FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id WHERE u.role IN ('Administrator','Staff') OR u.id IS NULL ORDER BY a.created_at DESC LIMIT {$limit}")->fetchAll();}
 public function staffActivityCounts():array{return $this->health->pdoForReporting()->query("SELECT a.module,COUNT(*) AS activity_count FROM audit_logs a INNER JOIN users u ON u.id=a.user_id WHERE u.role='Staff' AND a.module IN ('Health, Safety & Welfare','Legal & Compliance','Asset & Equipment Issuance') GROUP BY a.module ORDER BY a.module")->fetchAll();}
 public function loginHistory(?int $limit=5, string $date=''):array{$limit=$limit===null?200:max(1,min(200,$limit));$p=$this->health->pdoForReporting();if($date!==''&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)){ $q=$p->prepare("SELECT COALESCE(u.name,'Unknown') AS name,l.email,COALESCE(u.role,'—') AS role,l.login_at FROM login_history l LEFT JOIN users u ON u.id=l.user_id WHERE l.status='Success' AND DATE(l.login_at)=? ORDER BY l.login_at DESC LIMIT {$limit}");$q->execute([$date]);return $q->fetchAll();}return $p->query("SELECT COALESCE(u.name,'Unknown') AS name,l.email,COALESCE(u.role,'—') AS role,l.login_at FROM login_history l LEFT JOIN users u ON u.id=l.user_id WHERE l.status='Success' ORDER BY l.login_at DESC LIMIT {$limit}")->fetchAll();}
 public function dashboard(?string $date=null):array{$date=($date&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))?$date:null;return ['counts'=>array_merge($this->health->stats($date),$this->legal->stats(),$this->admin->stats(),$this->assets->stats()),'health_chart'=>$this->healthChart($date),'asset_issuance_chart'=>$this->assetIssuanceChart(),'report_date'=>$date];}
 public function healthChart(?string $date=null):array{$p=$this->health->pdoForReporting();if($date){$q=$p->prepare('SELECT COUNT(*) FROM safety_incidents WHERE incident_date=?');$q->execute([$date]);$i=(int)$q->fetchColumn();$q=$p->prepare('SELECT COUNT(*) FROM health_records WHERE checkup_date=?');$q->execute([$date]);$h=(int)$q->fetchColumn();$q=$p->prepare("SELECT COUNT(*) FROM safety_incidents WHERE incident_date=? AND status <> 'Closed'");$q->execute([$date]);$o=(int)$q->fetchColumn();}else{$i=(int)$p->query('SELECT COUNT(*) FROM safety_incidents')->fetchColumn();$h=(int)$p->query('SELECT COUNT(*) FROM health_records')->fetchColumn();$o=(int)$p->query("SELECT COUNT(*) FROM safety_incidents WHERE status <> 'Closed'")->fetchColumn();}return ['labels'=>['Incident Reports','Health Records','Open / Under Investigation'],'values'=>[$i,$h,$o]];}
 public function operationalSummary(int $alertLimit=5, int $activityLimit=6):array{
  $alertLimit=max(1,min(20,$alertLimit)); $activityLimit=max(1,min(20,$activityLimit));
  $pdo=$this->health->pdoForReporting(); $alerts=[];
  $q=$pdo->query("SELECT ai.employee_name,ai.expected_return,a.name AS asset_name,a.asset_tag
                  FROM asset_issuances ai JOIN assets a ON a.id=ai.asset_id
                  WHERE ai.return_date IS NULL AND ai.expected_return IS NOT NULL
                    AND ai.expected_return < CURDATE() AND ai.status <> 'Returned'
                  ORDER BY ai.expected_return ASC LIMIT {$alertLimit}");
  foreach($q->fetchAll() as $row){$alerts[]=['type'=>'Asset return overdue','title'=>$row['asset_name'].' ('.$row['asset_tag'].')','detail'=>'Assigned to '.$row['employee_name'].' · Due '.$row['expected_return'],'href'=>'/modules/asset_equipment/index.php','icon'=>'inventory_2'];}
  $q=$pdo->query("SELECT title,due_date,priority,status FROM compliance_obligations
                  WHERE status <> 'Compliant' AND due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                  ORDER BY due_date ASC LIMIT {$alertLimit}");
  foreach($q->fetchAll() as $row){$alerts[]=['type'=>($row['due_date'] < date('Y-m-d')?'Compliance overdue':'Compliance due soon'),'title'=>$row['title'],'detail'=>'Due '.$row['due_date'].' · '.$row['priority'].' priority · '.$row['status'],'href'=>'/modules/legal_compliance/index.php','icon'=>'gavel'];}
  $q=$pdo->query("SELECT al.action,al.module,al.details,al.created_at,u.name
                  FROM audit_logs al LEFT JOIN users u ON u.id=al.user_id
                  ORDER BY al.created_at DESC LIMIT {$activityLimit}");
  return ['alerts'=>array_slice($alerts,0,20),'activity'=>$q->fetchAll()];
 }
 public function assetIssuanceChart():array{$p=$this->health->pdoForReporting();$st=['Returned','Not Returned','Overdue','Issued'];$v=[];$q=$p->prepare('SELECT COUNT(*) FROM asset_issuances WHERE status=?');foreach($st as $x){$q->execute([$x]);$v[]=(int)$q->fetchColumn();}return ['labels'=>$st,'values'=>$v];}
}