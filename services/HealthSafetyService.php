<?php
final class HealthSafetyService {
    private const MODULE='Health, Safety & Welfare';
    public function __construct(private PDO $pdo, private AuditService $audit) {}
    public function pdoForReporting(): PDO { return $this->pdo; }

    private function validDate($v): bool {
        return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/',$v) && strtotime($v)!==false;
    }
    private function archiveRecord(string $table,int $id,string $name,?array $user): void {
        $s=$this->pdo->prepare("SELECT * FROM `$table` WHERE id=? LIMIT 1"); $s->execute([$id]);
        $row=$s->fetch(PDO::FETCH_ASSOC); if(!$row) throw new RuntimeException('Record not found.');
        $this->pdo->prepare('INSERT INTO archive_items(item_type,source_table,source_id,item_name,payload,deleted_by) VALUES(?,?,?,?,?,?)')
            ->execute(['record',$table,$id,$name,json_encode($row,JSON_UNESCAPED_UNICODE),$user['id']??null]);
    }

    public function incidents(?string $date=null): array {
        if($date && $this->validDate($date)){ $s=$this->pdo->prepare('SELECT * FROM safety_incidents WHERE incident_date=? ORDER BY incident_date DESC,id DESC'); $s->execute([$date]); }
        else $s=$this->pdo->query('SELECT * FROM safety_incidents ORDER BY incident_date DESC,id DESC');
        return $s->fetchAll();
    }
    public function healthRecords(?string $date=null): array {
        if($date && $this->validDate($date)){ $s=$this->pdo->prepare('SELECT * FROM health_records WHERE checkup_date=? ORDER BY checkup_date DESC,id DESC'); $s->execute([$date]); }
        else $s=$this->pdo->query('SELECT * FROM health_records ORDER BY checkup_date DESC,id DESC');
        return $s->fetchAll();
    }
    public function followups(?string $date=null): array {
        $sql='SELECT f.*, COALESCE(h.record_type,\'Health follow-up\') AS record_type FROM health_followups f LEFT JOIN health_records h ON h.id=f.health_record_id';
        $params=[];
        if($date && $this->validDate($date)){ $sql.=' WHERE f.due_date=?'; $params[]=$date; }
        $sql.=' ORDER BY f.due_date ASC,f.id DESC LIMIT 200';
        $s=$this->pdo->prepare($sql); $s->execute($params); return $s->fetchAll();
    }
    public function incidentActions(int $incidentId): array {
        $s=$this->pdo->prepare('SELECT * FROM incident_actions WHERE incident_id=? ORDER BY due_date IS NULL,due_date ASC,id DESC'); $s->execute([$incidentId]); return $s->fetchAll();
    }
    public function stats(?string $date=null): array {
        $params=[];$where=''; if($date&&$this->validDate($date)){$where=' WHERE incident_date=?';$params=[$date];}
        $q=$this->pdo->prepare("SELECT COUNT(*) FROM safety_incidents{$where}");$q->execute($params);$i=(int)$q->fetchColumn();
        $q=$this->pdo->prepare("SELECT COUNT(*) FROM safety_incidents".($where?' WHERE incident_date=? AND status <> \'Closed\'':" WHERE status <> 'Closed'"));$q->execute($params);$o=(int)$q->fetchColumn();
        $whereH=$date&&$this->validDate($date)?' WHERE checkup_date=?':'';$q=$this->pdo->prepare("SELECT COUNT(*) FROM health_records{$whereH}");$q->execute($date&&$this->validDate($date)?[$date]:[]);$h=(int)$q->fetchColumn();
        $follow=(int)$this->pdo->query("SELECT COUNT(*) FROM health_followups WHERE status IN ('Pending','Overdue') AND due_date < CURDATE()")->fetchColumn();
        $critical=(int)$this->pdo->query("SELECT COUNT(*) FROM safety_incidents WHERE severity='Critical' AND status <> 'Closed'")->fetchColumn();
        return ['incidents'=>$i,'open_incidents'=>$o,'health_records'=>$h,'overdue_followups'=>$follow,'critical_incidents'=>$critical];
    }

    public function handle(string $action,array $data,?array $user): string {
        if($action==='add_incident'){
            $title=trim((string)($data['title']??'')); $employee=trim((string)($data['employee_name']??''));
            $date=(string)($data['incident_date']??'');
            if($title===''||$employee===''||!$this->validDate($date)) throw new RuntimeException('Title, employee and a valid incident date are required.');
            $severity=(string)($data['severity']??'Medium'); $status=(string)($data['status']??'Open');
            if(!in_array($severity,['Low','Medium','High','Critical'],true)||!in_array($status,['Open','Under Investigation','Closed'],true)) throw new RuntimeException('Invalid incident values.');
            $s=$this->pdo->prepare('INSERT INTO safety_incidents(title,employee_name,incident_date,severity,status,description,incident_type,location,reported_by,witnesses,injury_type,treatment,immediate_action,root_cause,corrective_action,investigation_date,closed_date,days_lost,external_report_ref) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $closed=$status==='Closed' ? ($this->validDate((string)($data['closed_date']??''))?$data['closed_date']:date('Y-m-d')) : null;
            $s->execute([$title,$employee,$date,$severity,$status,trim((string)($data['description']??'')),trim((string)($data['incident_type']??'')),trim((string)($data['location']??'')),trim((string)($data['reported_by']??'')),trim((string)($data['witnesses']??'')),trim((string)($data['injury_type']??'')),trim((string)($data['treatment']??'')),trim((string)($data['immediate_action']??'')),trim((string)($data['root_cause']??'')),trim((string)($data['corrective_action']??'')),$this->validDate((string)($data['investigation_date']??''))?$data['investigation_date']:null,$closed,max(0,(int)($data['days_lost']??0)),trim((string)($data['external_report_ref']??''))]);
            $this->audit->record($user,self::MODULE,'Create Incident',$title); return 'Safety incident saved.';
        }
        if($action==='update_incident'){
            $id=(int)($data['id']??0); if($id<=0) throw new RuntimeException('Invalid incident.');
            $status=(string)($data['status']??'Open'); if(!in_array($status,['Open','Under Investigation','Closed'],true)) throw new RuntimeException('Invalid status.');
            $closed=$status==='Closed' ? ($this->validDate((string)($data['closed_date']??''))?$data['closed_date']:date('Y-m-d')) : null;
            $s=$this->pdo->prepare('UPDATE safety_incidents SET status=?,severity=?,root_cause=?,corrective_action=?,investigation_date=?,closed_date=?,days_lost=?,treatment=?,immediate_action=? WHERE id=?');
            $s->execute([$status,(string)($data['severity']??'Medium'),trim((string)($data['root_cause']??'')),trim((string)($data['corrective_action']??'')),$this->validDate((string)($data['investigation_date']??''))?$data['investigation_date']:null,$closed,max(0,(int)($data['days_lost']??0)),trim((string)($data['treatment']??'')),trim((string)($data['immediate_action']??'')),$id]);
            $this->audit->record($user,self::MODULE,'Update Incident','ID '.$id); return 'Incident updated.';
        }
        if($action==='delete_incident'){
            $id=(int)($data['id']??0); if($id<=0) throw new RuntimeException('Invalid incident.');
            $this->archiveRecord('safety_incidents',$id,'Safety Incident #'.$id,$user); $this->pdo->prepare('DELETE FROM safety_incidents WHERE id=?')->execute([$id]);
            $this->audit->record($user,self::MODULE,'Delete Incident','Archived ID '.$id); return 'Incident deleted and moved to Archive.';
        }
        if($action==='add_health'){
            $employee=trim((string)($data['employee_name']??''));$date=(string)($data['checkup_date']??'');
            if($employee===''||!$this->validDate($date)) throw new RuntimeException('Employee and a valid checkup date are required.');
            $fitness=(string)($data['fitness_status']??'Pending'); if(!in_array($fitness,['Fit','Fit with Restrictions','Unfit','Pending'],true)) throw new RuntimeException('Invalid fitness status.');
            $s=$this->pdo->prepare('INSERT INTO health_records(employee_name,checkup_date,record_type,fitness_status,notes,employee_id,department,position_title,contact_no,blood_type,emergency_contact,emergency_contact_no,medical_provider,next_checkup_date,work_restrictions,clearance_expiry,document_ref) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $s->execute([$employee,$date,trim((string)($data['record_type']??'')),$fitness,trim((string)($data['notes']??'')),trim((string)($data['employee_id']??'')),trim((string)($data['department']??'')),trim((string)($data['position_title']??'')),trim((string)($data['contact_no']??'')),trim((string)($data['blood_type']??'')),trim((string)($data['emergency_contact']??'')),trim((string)($data['emergency_contact_no']??'')),trim((string)($data['medical_provider']??'')),$this->validDate((string)($data['next_checkup_date']??''))?$data['next_checkup_date']:null,trim((string)($data['work_restrictions']??'')),$this->validDate((string)($data['clearance_expiry']??''))?$data['clearance_expiry']:null,trim((string)($data['document_ref']??''))]);
            $this->audit->record($user,self::MODULE,'Create Health Record',$employee); return 'Health record saved.';
        }
        if($action==='delete_health'){
            $id=(int)($data['id']??0);if($id<=0)throw new RuntimeException('Invalid health record.');
            $this->archiveRecord('health_records',$id,'Health Record #'.$id,$user);$this->pdo->prepare('DELETE FROM health_records WHERE id=?')->execute([$id]);
            $this->audit->record($user,self::MODULE,'Delete Health Record','Archived ID '.$id);return 'Health record deleted and moved to Archive.';
        }
        if($action==='add_followup'){
            $employee=trim((string)($data['employee_name']??''));$type=trim((string)($data['followup_type']??''));$date=(string)($data['due_date']??'');
            if($employee===''||$type===''||!$this->validDate($date))throw new RuntimeException('Employee, follow-up type and due date are required.');
            $status=(string)($data['status']??'Pending');if(!in_array($status,['Pending','Completed','Cancelled','Overdue'],true))throw new RuntimeException('Invalid follow-up status.');
            $s=$this->pdo->prepare('INSERT INTO health_followups(health_record_id,employee_name,followup_type,due_date,status,notes,completed_at) VALUES(?,?,?,?,?,?,?)');
            $s->execute([(int)($data['health_record_id']??0)>0?(int)$data['health_record_id']:null,$employee,$type,$date,$status,trim((string)($data['notes']??'')),$status==='Completed'?date('Y-m-d H:i:s'):null]);
            $this->audit->record($user,self::MODULE,'Create Health Follow-up',$employee);return 'Health follow-up saved.';
        }
        if($action==='update_followup'){
            $id=(int)($data['id']??0);$status=(string)($data['status']??'Pending');if($id<=0||!in_array($status,['Pending','Completed','Cancelled','Overdue'],true))throw new RuntimeException('Invalid follow-up update.');
            $s=$this->pdo->prepare('UPDATE health_followups SET status=?,completed_at=? WHERE id=?');$s->execute([$status,$status==='Completed'?date('Y-m-d H:i:s'):null,$id]);
            $this->audit->record($user,self::MODULE,'Update Health Follow-up','ID '.$id);return 'Health follow-up updated.';
        }
        if($action==='add_incident_action'){
            $incident=(int)($data['incident_id']??0);$text=trim((string)($data['action_text']??''));if($incident<=0||$text==='')throw new RuntimeException('Incident and action are required.');
            $type=(string)($data['action_type']??'Corrective');$status=(string)($data['status']??'Open');
            if(!in_array($type,['Immediate','Corrective','Preventive','Investigation'],true)||!in_array($status,['Open','In Progress','Completed','Cancelled'],true))throw new RuntimeException('Invalid incident action values.');
            $s=$this->pdo->prepare('INSERT INTO incident_actions(incident_id,action_type,action_text,owner,due_date,status,completed_at) VALUES(?,?,?,?,?,?,?)');
            $s->execute([$incident,$type,$text,trim((string)($data['owner']??'')),$this->validDate((string)($data['due_date']??''))?$data['due_date']:null,$status,$status==='Completed'?date('Y-m-d H:i:s'):null]);
            $this->audit->record($user,self::MODULE,'Create Incident Action','Incident ID '.$incident);return 'Incident action saved.';
        }
        if($action==='update_incident_action'){
            $id=(int)($data['id']??0);$status=(string)($data['status']??'Open');if($id<=0||!in_array($status,['Open','In Progress','Completed','Cancelled'],true))throw new RuntimeException('Invalid action status.');
            $s=$this->pdo->prepare('UPDATE incident_actions SET status=?,completed_at=? WHERE id=?');$s->execute([$status,$status==='Completed'?date('Y-m-d H:i:s'):null,$id]);
            $this->audit->record($user,self::MODULE,'Update Incident Action','ID '.$id);return 'Incident action updated.';
        }
        throw new RuntimeException('Unsupported Health & Safety action.');
    }
}
