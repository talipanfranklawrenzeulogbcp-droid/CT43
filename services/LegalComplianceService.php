<?php
final class LegalComplianceService {
    private const MODULE='Legal & Compliance';
    public function __construct(private PDO $pdo, private AuditService $audit) {}
    private function validDate($v): bool { return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/',$v) && strtotime($v)!==false; }
    private function archiveRecord(string $table,int $id,string $name,?array $user):void{
        $s=$this->pdo->prepare("SELECT * FROM `$table` WHERE id=? LIMIT 1");$s->execute([$id]);$row=$s->fetch(PDO::FETCH_ASSOC);if(!$row)throw new RuntimeException('Record not found.');
        $this->pdo->prepare('INSERT INTO archive_items(item_type,source_table,source_id,item_name,payload,deleted_by) VALUES(?,?,?,?,?,?)')->execute(['record',$table,$id,$name,json_encode($row,JSON_UNESCAPED_UNICODE),$user['id']??null]);
    }
    public function obligations():array{return $this->pdo->query('SELECT * FROM compliance_obligations ORDER BY COALESCE(review_date,due_date) ASC,id DESC')->fetchAll();}
    public function complianceReports(string $date='',?int $limit=null):array{
        $where='';$params=[];if($date&&$this->validDate($date)){$where=' WHERE DATE(COALESCE(reported_at,created_at))=?';$params[]=$date;}
        $lim=$limit!==null?' LIMIT '.max(1,min(200,$limit)):'';$q=$this->pdo->prepare("SELECT * FROM compliance_obligations{$where} ORDER BY COALESCE(reported_at,created_at) DESC,id DESC{$lim}");$q->execute($params);return $q->fetchAll();
    }
    public function audits():array{return $this->pdo->query('SELECT * FROM compliance_audits ORDER BY audit_date DESC,id DESC')->fetchAll();}
    public function actionItems():array{
        $sql='SELECT a.*,c.title AS compliance_title FROM compliance_action_items a INNER JOIN compliance_obligations c ON c.id=a.compliance_id ORDER BY a.due_date IS NULL,a.due_date ASC,a.id DESC';return $this->pdo->query($sql)->fetchAll();
    }
    public function stats():array{return ['obligations'=>(int)$this->pdo->query('SELECT COUNT(*) FROM compliance_obligations')->fetchColumn(),'overdue'=>(int)$this->pdo->query("SELECT COUNT(*) FROM compliance_obligations WHERE status='Overdue' OR (due_date < CURDATE() AND status <> 'Compliant')")->fetchColumn(),'audits'=>(int)$this->pdo->query('SELECT COUNT(*) FROM compliance_audits')->fetchColumn(),'open_actions'=>(int)$this->pdo->query("SELECT COUNT(*) FROM compliance_action_items WHERE status IN ('Open','In Progress') AND (due_date IS NULL OR due_date<=CURDATE())")->fetchColumn()];}

    public function documentRequirements():array{return $this->pdo->query('SELECT * FROM document_requirements WHERE active=1 ORDER BY sort_order ASC,id ASC')->fetchAll();}
    public function employeeDocumentRows():array{
        $sql="SELECT x.employee_name,
            COALESCE(NULLIF(MAX(x.employee_id),''),'—') AS employee_id,
            COALESCE(NULLIF(MAX(x.department),''),'—') AS department,
            COALESCE(NULLIF(MAX(x.position_title),''),'—') AS position_title,
            COUNT(DISTINCT ed.id) AS uploaded_documents,
            (SELECT COUNT(*) FROM document_requirements WHERE active=1 AND required_for_recruitment=1) AS required_documents,
            COUNT(DISTINCT CASE WHEN ed.status IN ('Verified','Uploaded','Pending Review') THEN ed.id END) AS active_documents,
            COUNT(DISTINCT CASE WHEN ed.status='Verified' THEN ed.id END) AS verified_documents,
            COUNT(DISTINCT CASE WHEN ed.status='Expired' OR (ed.expiry_date IS NOT NULL AND ed.expiry_date<CURDATE()) THEN ed.id END) AS expired_documents,
            COUNT(DISTINCT CASE WHEN ed.status='Pending Review' THEN ed.id END) AS pending_documents
            FROM (
                SELECT employee_name,employee_id,department,position_title FROM health_records
                UNION ALL
                SELECT employee_name,NULL,NULL,NULL FROM safety_incidents
            ) x
            LEFT JOIN employee_documents ed ON ed.employee_name=x.employee_name
            GROUP BY x.employee_name
            ORDER BY x.employee_name ASC";
        return $this->pdo->query($sql)->fetchAll();
    }
    public function employeeNames():array{return $this->pdo->query("SELECT DISTINCT employee_name FROM (SELECT employee_name FROM health_records UNION SELECT employee_name FROM safety_incidents) e WHERE employee_name<>'' ORDER BY employee_name ASC")->fetchAll(PDO::FETCH_COLUMN);}
    public function employeeDocuments(string $employee):array{$q=$this->pdo->prepare('SELECT d.*,COALESCE(u.name,\'Unknown\') AS uploader_name,r.description AS requirement_description FROM employee_documents d LEFT JOIN users u ON u.id=d.uploaded_by LEFT JOIN document_requirements r ON r.id=d.requirement_id WHERE d.employee_name=? ORDER BY d.created_at DESC,d.id DESC');$q->execute([$employee]);return $q->fetchAll();}
    public function documentFile(int $id):?array{$q=$this->pdo->prepare('SELECT d.id,d.file_name,d.file_type,d.file_size,d.file_data,d.employee_name,d.document_name,d.status FROM employee_documents d WHERE d.id=?');$q->execute([$id]);return $q->fetch()?:null;}
    public function documentStats():array{return ['documents'=>(int)$this->pdo->query('SELECT COUNT(*) FROM employee_documents')->fetchColumn(),'verified'=>(int)$this->pdo->query("SELECT COUNT(*) FROM employee_documents WHERE status='Verified'")->fetchColumn(),'pending'=>(int)$this->pdo->query("SELECT COUNT(*) FROM employee_documents WHERE status='Pending Review'")->fetchColumn(),'expired'=>(int)$this->pdo->query("SELECT COUNT(*) FROM employee_documents WHERE status='Expired' OR (expiry_date IS NOT NULL AND expiry_date<CURDATE())")->fetchColumn()];}

    public function handle(string $action,array $data,?array $user):string{
        if($action==='add_report'){
            $name=trim((string)($data['report_name']??''));$role=trim((string)($data['report_role']??''));$contact=trim((string)($data['contact_no']??''));$note=trim((string)($data['compliance_note']??''));
            if($name===''||$role===''||$contact===''||$note==='')throw new RuntimeException('Name, role, contact no and compliance are required.');
            $title=$name.' — Compliance Report';$status=(string)($data['status']??'Open');$priority=(string)($data['priority']??'Medium');$due=(string)($data['due_date']??date('Y-m-d'));
            if(!in_array($status,['Open','In Progress','Compliant','Overdue'],true)||!in_array($priority,['Low','Medium','High','Critical'],true)||!$this->validDate($due))throw new RuntimeException('Invalid compliance values.');
            $s=$this->pdo->prepare('INSERT INTO compliance_obligations(title,report_name,report_role,contact_no,compliance_note,reported_at,category,owner,due_date,priority,status,regulation_ref,responsible_department,finding,evidence_reference,corrective_action,review_date) VALUES(?,?,?,?,?,NOW(),?,?,?,?,?,?,?,?,?,?,?)');
            $s->execute([$title,$name,$role,$contact,$note,trim((string)($data['category']??'Compliance')),trim((string)($data['owner']??$role)),$due,$priority,$status,trim((string)($data['regulation_ref']??'')),trim((string)($data['responsible_department']??'')),trim((string)($data['finding']??'')),trim((string)($data['evidence_reference']??'')),trim((string)($data['corrective_action']??'')),$this->validDate((string)($data['review_date']??''))?$data['review_date']:null]);
            $this->audit->record($user,self::MODULE,'Create Compliance Report',$name);return 'Compliance report saved.';
        }
        if($action==='add_obligation'){
            $title=trim((string)($data['title']??''));$due=(string)($data['due_date']??'');if($title===''||!$this->validDate($due))throw new RuntimeException('Requirement title and valid due date are required.');
            $priority=(string)($data['priority']??'Medium');$status=(string)($data['status']??'Open');if(!in_array($priority,['Low','Medium','High','Critical'],true)||!in_array($status,['Open','In Progress','Compliant','Overdue'],true))throw new RuntimeException('Invalid compliance values.');
            $s=$this->pdo->prepare('INSERT INTO compliance_obligations(title,category,owner,due_date,priority,status,regulation_ref,responsible_department,finding,evidence_reference,corrective_action,review_date) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');$s->execute([$title,trim((string)($data['category']??'')),trim((string)($data['owner']??'')),$due,$priority,$status,trim((string)($data['regulation_ref']??'')),trim((string)($data['responsible_department']??'')),trim((string)($data['finding']??'')),trim((string)($data['evidence_reference']??'')),trim((string)($data['corrective_action']??'')),$this->validDate((string)($data['review_date']??''))?$data['review_date']:null]);
            $this->audit->record($user,self::MODULE,'Create Compliance Obligation',$title);return 'Compliance obligation saved.';
        }
        if($action==='update_compliance'){
            $id=(int)($data['id']??0);$status=(string)($data['status']??'Open');$priority=(string)($data['priority']??'Medium');if($id<=0||!in_array($status,['Open','In Progress','Compliant','Overdue'],true)||!in_array($priority,['Low','Medium','High','Critical'],true))throw new RuntimeException('Invalid compliance update.');
            $s=$this->pdo->prepare('UPDATE compliance_obligations SET status=?,priority=?,finding=?,corrective_action=?,evidence_reference=?,review_date=? WHERE id=?');$s->execute([$status,$priority,trim((string)($data['finding']??'')),trim((string)($data['corrective_action']??'')),trim((string)($data['evidence_reference']??'')),$this->validDate((string)($data['review_date']??''))?$data['review_date']:null,$id]);$this->audit->record($user,self::MODULE,'Update Compliance Record','ID '.$id);return 'Compliance record updated.';
        }
        if($action==='delete_report'){$id=(int)($data['id']??0);if($id<=0)throw new RuntimeException('Invalid compliance report.');$s=$this->pdo->prepare('SELECT title FROM compliance_obligations WHERE id=? LIMIT 1');$s->execute([$id]);$row=$s->fetch(PDO::FETCH_ASSOC);$this->archiveRecord('compliance_obligations',$id,(string)($row['title']??('Compliance Report #'.$id)),$user);$this->pdo->prepare('DELETE FROM compliance_obligations WHERE id=?')->execute([$id]);$this->audit->record($user,self::MODULE,'Delete Compliance Report','Archived ID '.$id);return 'Compliance report deleted and moved to Archive.';}
        if($action==='add_audit'){$title=trim((string)($data['title']??''));$date=(string)($data['audit_date']??'');$status=(string)($data['status']??'Scheduled');if($title===''||!$this->validDate($date)||!in_array($status,['Scheduled','In Progress','Completed','Closed'],true))throw new RuntimeException('Audit title, valid date and status are required.');$s=$this->pdo->prepare('INSERT INTO compliance_audits(title,audit_date,auditor,status,findings) VALUES(?,?,?,?,?)');$s->execute([$title,$date,trim((string)($data['auditor']??'')),$status,trim((string)($data['findings']??''))]);$this->audit->record($user,self::MODULE,'Create Audit',$title);return 'Compliance audit saved.';}
        if($action==='add_action_item'){$cid=(int)($data['compliance_id']??0);$text=trim((string)($data['action_text']??''));if($cid<=0||$text==='')throw new RuntimeException('Compliance record and action are required.');$due=$this->validDate((string)($data['due_date']??''))?$data['due_date']:null;$status=(string)($data['status']??'Open');if(!in_array($status,['Open','In Progress','Completed','Cancelled'],true))throw new RuntimeException('Invalid action status.');$s=$this->pdo->prepare('INSERT INTO compliance_action_items(compliance_id,action_text,owner,due_date,status,completion_notes,completed_at) VALUES(?,?,?,?,?,?,?)');$s->execute([$cid,$text,trim((string)($data['owner']??'')),$due,$status,trim((string)($data['completion_notes']??'')),$status==='Completed'?date('Y-m-d H:i:s'):null]);$this->audit->record($user,self::MODULE,'Create Compliance Action','Compliance ID '.$cid);return 'Compliance action item saved.';}
        if($action==='update_action_item'){$id=(int)($data['id']??0);$status=(string)($data['status']??'Completed');if($id<=0||!in_array($status,['Open','In Progress','Completed','Cancelled'],true))throw new RuntimeException('Invalid action item.');$s=$this->pdo->prepare('UPDATE compliance_action_items SET status=?,completion_notes=?,completed_at=? WHERE id=?');$s->execute([$status,trim((string)($data['completion_notes']??'')),$status==='Completed'?date('Y-m-d H:i:s'):null,$id]);$this->audit->record($user,self::MODULE,'Update Compliance Action','ID '.$id);return 'Compliance action updated.';}

        if($action==='upload_employee_document'){
            $employee=trim((string)($data['employee_name']??''));$requirementId=(int)($data['requirement_id']??0);$docName=trim((string)($data['document_name']??''));$category=trim((string)($data['category']??''));
            if($employee===''||$docName===''||$category==='')throw new RuntimeException('Employee, document name and category are required.');
            if(empty($_FILES['employee_document'])||$_FILES['employee_document']['error']!==UPLOAD_ERR_OK)throw new RuntimeException('Please select a document to upload.');
            $file=$_FILES['employee_document'];$max=25*1024*1024;if((int)$file['size']<=0||(int)$file['size']>$max)throw new RuntimeException('Document must be between 1 byte and 25 MB.');
            $allowed=['pdf','jpg','jpeg','png','webp','doc','docx','xls','xlsx','txt'];$ext=strtolower(pathinfo((string)$file['name'],PATHINFO_EXTENSION));if(!in_array($ext,$allowed,true))throw new RuntimeException('Unsupported document type. Allowed: PDF, images, Word, Excel and TXT.');
            $dataBytes=file_get_contents((string)$file['tmp_name']);if($dataBytes===false)throw new RuntimeException('Unable to read the uploaded document.');
            $mime=function_exists('mime_content_type')?(mime_content_type((string)$file['tmp_name'])?:'application/octet-stream'):'application/octet-stream';
            $allowedMimes=['application/pdf','image/jpeg','image/png','image/webp','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','text/plain'];if(!in_array($mime,$allowedMimes,true)&&$ext!=='txt')throw new RuntimeException('The uploaded file type is not allowed.');
            if($requirementId>0){$q=$this->pdo->prepare('SELECT document_name,category FROM document_requirements WHERE id=? AND active=1');$q->execute([$requirementId]);$r=$q->fetch(PDO::FETCH_ASSOC);if(!$r)throw new RuntimeException('Selected document requirement was not found.');$docName=(string)$r['document_name'];$category=(string)$r['category'];}
            $status=(string)($data['status']??'Pending Review');if(!in_array($status,['Uploaded','Pending Review','Verified','Expired','Rejected'],true))$status='Pending Review';$expiry=$this->validDate((string)($data['expiry_date']??''))?$data['expiry_date']:null;
            if($expiry!==null&&$expiry<date('Y-m-d'))$status='Expired';
            $stmt=$this->pdo->prepare('INSERT INTO employee_documents(employee_name,employee_id,requirement_id,document_name,category,document_number,issued_date,expiry_date,status,notes,file_name,file_type,file_size,file_hash,file_data,uploaded_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->bindValue(1,$employee);$stmt->bindValue(2,trim((string)($data['employee_id']??''))?:null);$stmt->bindValue(3,$requirementId>0?$requirementId:null,$requirementId>0?PDO::PARAM_INT:PDO::PARAM_NULL);$stmt->bindValue(4,$docName);$stmt->bindValue(5,$category);$stmt->bindValue(6,trim((string)($data['document_number']??''))?:null);$stmt->bindValue(7,$this->validDate((string)($data['issued_date']??''))?$data['issued_date']:null);$stmt->bindValue(8,$expiry);$stmt->bindValue(9,$status);$stmt->bindValue(10,trim((string)($data['notes']??'')));$stmt->bindValue(11,basename((string)$file['name']));$stmt->bindValue(12,$mime);$stmt->bindValue(13,(int)$file['size'],PDO::PARAM_INT);$stmt->bindValue(14,hash('sha256',$dataBytes));$stmt->bindValue(15,$dataBytes,PDO::PARAM_LOB);$stmt->bindValue(16,(int)($user['id']??0),PDO::PARAM_INT);$stmt->execute();
            $this->audit->record($user,self::MODULE,'Upload Employee Document',$employee.' — '.$docName);return 'Employee document uploaded and added to the compliance register.';
        }
        if($action==='update_employee_document'){$id=(int)($data['id']??0);$status=(string)($data['status']??'Pending Review');if($id<=0||!in_array($status,['Uploaded','Pending Review','Verified','Expired','Rejected'],true))throw new RuntimeException('Invalid document update.');$expiry=$this->validDate((string)($data['expiry_date']??''))?$data['expiry_date']:null;if($expiry!==null&&$expiry<date('Y-m-d'))$status='Expired';$s=$this->pdo->prepare('UPDATE employee_documents SET status=?,expiry_date=?,document_number=?,notes=? WHERE id=?');$s->execute([$status,$expiry,trim((string)($data['document_number']??''))?:null,trim((string)($data['notes']??'')),$id]);$this->audit->record($user,self::MODULE,'Update Employee Document','ID '.$id);return 'Employee document updated.';}
        if($action==='delete_employee_document'){$id=(int)($data['id']??0);if($id<=0)throw new RuntimeException('Invalid document.');$q=$this->pdo->prepare('SELECT * FROM employee_documents WHERE id=?');$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)throw new RuntimeException('Document not found.');$fileData=$row['file_data']??'';unset($row['file_data'],$row['id']);$payload=json_encode($row,JSON_UNESCAPED_UNICODE);$this->pdo->prepare('INSERT INTO archive_items(item_type,source_table,source_id,item_name,payload,file_data,file_type,deleted_by) VALUES(?,?,?,?,?,?,?,?)')->execute(['file','employee_documents',$id,$row['employee_name'].' — '.$row['document_name'],$payload,$fileData,$row['file_type'],$user['id']??null]);$this->pdo->prepare('DELETE FROM employee_documents WHERE id=?')->execute([$id]);$this->audit->record($user,self::MODULE,'Archive Employee Document','ID '.$id);return 'Employee document archived.';}
        throw new RuntimeException('Unknown Legal & Compliance action.');
    }
}
