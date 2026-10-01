<?php
require_once __DIR__ . '/../includes/error_handler.php';

final class HealthSafetyService {
    private const MODULE='Health, Safety & Welfare';
    private $pdo;
    private $audit;
    public function __construct(PDO $pdo, AuditService $audit) { $this->pdo = $pdo; $this->audit = $audit; }

    public function pdoForReporting(): PDO { return $this->pdo; }

    public function incidents(?string $date=null): array {
        if($date && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$date)){ $s=$this->pdo->prepare('SELECT * FROM safety_incidents WHERE incident_date=? ORDER BY incident_date DESC,id DESC'); $s->execute([$date]); return $s->fetchAll(); }
        return $this->pdo->query('SELECT * FROM safety_incidents ORDER BY incident_date DESC,id DESC')->fetchAll();
    }
    public function healthRecords(?string $date=null): array {
        if($date && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$date)){ $s=$this->pdo->prepare('SELECT * FROM health_records WHERE checkup_date=? ORDER BY checkup_date DESC,id DESC'); $s->execute([$date]); return $s->fetchAll(); }
        return $this->pdo->query('SELECT * FROM health_records ORDER BY checkup_date DESC,id DESC')->fetchAll();
    }
    public function stats(?string $date=null): array {
        if($date && preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)){
 $q=$this->pdo->prepare('SELECT COUNT(*) FROM safety_incidents WHERE incident_date=?');$q->execute([$date]);$i=(int)$q->fetchColumn();
 $q=$this->pdo->prepare("SELECT COUNT(*) FROM safety_incidents WHERE incident_date=? AND status <> 'Closed'");$q->execute([$date]);$o=(int)$q->fetchColumn();
 $q=$this->pdo->prepare('SELECT COUNT(*) FROM health_records WHERE checkup_date=?');$q->execute([$date]);$h=(int)$q->fetchColumn();
}else{$i=(int)$this->pdo->query('SELECT COUNT(*) FROM safety_incidents')->fetchColumn();$o=(int)$this->pdo->query("SELECT COUNT(*) FROM safety_incidents WHERE status <> 'Closed'")->fetchColumn();$h=(int)$this->pdo->query('SELECT COUNT(*) FROM health_records')->fetchColumn();}
return ['incidents'=>$i,'open_incidents'=>$o,'health_records'=>$h];
    }
    private function archiveRecord(string $table, int $id, string $name, ?array $user): void {
        $stmt=$this->pdo->prepare("SELECT * FROM `$table` WHERE id=? LIMIT 1");
        $stmt->execute([$id]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$row) throw new RuntimeException('Record not found.');
        $this->pdo->prepare('INSERT INTO archive_items(item_type,source_table,source_id,item_name,payload,deleted_by) VALUES(?,?,?,?,?,?)')
            ->execute(['record',$table,$id,$name,json_encode($row,JSON_UNESCAPED_UNICODE),$user['id']??null]);
    }

    public function handle(string $action, array $data, ?array $user): string {
        if($action==='add_incident'){
            $title=trim((string)($data['title']??'')); $employee=trim((string)($data['employee_name']??''));
            if($title===''||$employee===''||empty($data['incident_date'])) throw new RuntimeException('Title, employee and incident date are required.');
            $severity=(string)($data['severity']??'Medium'); $status=(string)($data['status']??'Open');
            if(!in_array($severity,['Low','Medium','High','Critical'],true)) throw new RuntimeException('Invalid severity.');
            if(!in_array($status,['Open','Under Investigation','Closed'],true)) throw new RuntimeException('Invalid status.');
            $s=$this->pdo->prepare('INSERT INTO safety_incidents(title,employee_name,incident_date,severity,status,description) VALUES(?,?,?,?,?,?)');
            $s->execute([$title,$employee,$data['incident_date'],$severity,$status,trim((string)($data['description']??''))]);
            $this->audit->record($user,self::MODULE,'Create Incident',$title); return 'Safety incident saved.';
        }
        if($action==='delete_incident'){
            $id=(int)($data['id']??0); if($id<=0) throw new RuntimeException('Invalid incident.');
            $this->archiveRecord('safety_incidents',$id,'Safety Incident #'.$id,$user);
            $this->pdo->prepare('DELETE FROM safety_incidents WHERE id=?')->execute([$id]);
            $this->audit->record($user,self::MODULE,'Delete Incident','Archived ID '.$id); return 'Incident deleted and moved to Archive.';
        }
        if($action==='add_health'){
            $employee=trim((string)($data['employee_name']??'')); if($employee===''||empty($data['checkup_date'])) throw new RuntimeException('Employee and checkup date are required.');
            $fitness=(string)($data['fitness_status']??'Pending'); if(!in_array($fitness,['Fit','Fit with Restrictions','Unfit','Pending'],true)) throw new RuntimeException('Invalid fitness status.');
            $s=$this->pdo->prepare('INSERT INTO health_records(employee_name,checkup_date,record_type,fitness_status,notes) VALUES(?,?,?,?,?)');
            $s->execute([$employee,$data['checkup_date'],trim((string)($data['record_type']??'')),$fitness,trim((string)($data['notes']??''))]);
            $this->audit->record($user,self::MODULE,'Create Health Record',$employee); return 'Health record saved.';
        }
        throw new RuntimeException('Unsupported Health & Safety action.');
    }
}
