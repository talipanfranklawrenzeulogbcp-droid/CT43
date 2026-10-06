<?php
final class AdminSecurityService {
    private const MODULE='System Administration & Security';
    public function __construct(private PDO $pdo, private AuditService $audit) {}
    public function users(): array { return $this->pdo->query('SELECT id,name,email,role,active,created_at FROM users ORDER BY id')->fetchAll(); }
    public function events(int $limit=20): array { return $this->audit->latestOverall($limit); }
    public function logins(int $limit=5, string $date=''): array {
        $limit=max(1,min(100,$limit));
        if ($date !== '' && !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$date)) {
            throw new InvalidArgumentException('Invalid login history date.');
        }
        if ($date !== '') {
            $q=$this->pdo->prepare("SELECT l.*,u.name AS user_name,u.role FROM login_history l LEFT JOIN users u ON u.id=l.user_id WHERE DATE(l.login_at)=? ORDER BY l.login_at DESC LIMIT {$limit}");
            $q->execute([$date]);
            return $q->fetchAll();
        }
        return $this->pdo->query("SELECT l.*,u.name AS user_name,u.role FROM login_history l LEFT JOIN users u ON u.id=l.user_id ORDER BY l.login_at DESC LIMIT {$limit}")->fetchAll();
    }
    public function stats(): array { return [
        'users'=>(int)$this->pdo->query('SELECT COUNT(*) FROM users WHERE active=1')->fetchColumn(),
        'logins'=>(int)$this->pdo->query("SELECT COUNT(*) FROM login_history WHERE status='Success'")->fetchColumn(),
    ]; }
    public function handle(string $action,array $data,?array $user): string {
        if(($user['role'] ?? '') !== 'Administrator') throw new RuntimeException('Administrator access is required.');
        if($action==='add_user'){
            $name=trim((string)($data['name']??'')); $email=strtolower(trim((string)($data['email']??''))); $password=(string)($data['password']??''); $role=(string)($data['role']??'Staff');
            if(!in_array($role,['Administrator','Staff'],true)) throw new RuntimeException('Invalid role selected.');
            if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid name and email address.');
            if(strlen($password)<8) throw new RuntimeException('Password must be at least 8 characters.');
            $dup=$this->pdo->prepare('SELECT id,name,email FROM users WHERE name=? OR email=? LIMIT 1'); $dup->execute([$name,$email]);
            if($existing=$dup->fetch()){
                if(strcasecmp((string)$existing['email'],$email)===0) throw new RuntimeException('This email is already registered in the system. Use a different email.');
                throw new RuntimeException('This name is already registered in the system. Use a different name.');
            }
            // New accounts remain inactive until the administrator completes Face ID enrollment.
            $this->pdo->beginTransaction();
            try {
                $s=$this->pdo->prepare('INSERT INTO users(name,email,password_hash,role,active) VALUES(?,?,?,?,0)');
                $s->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT),$role]);
                $id=(int)$this->pdo->lastInsertId();
                $this->pdo->commit();
            } catch(Throwable $e) { if($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
            $_SESSION['pending_admin_face_user']=['id'=>$id,'name'=>$name,'email'=>$email,'role'=>$role];
            $_SESSION['pending_admin_face_created']=time();
            $this->audit->record($user,self::MODULE,'Create User',$email.' ('.$role.') — pending Face ID enrollment');
            return 'User account created. Complete Face ID enrollment to activate the account.';
        }
        if($action==='start_face_registration'){
            $targetId=(int)($data['id']??0);
            if($targetId<=0) throw new RuntimeException('Invalid user account.');
            $q=$this->pdo->prepare('SELECT id,name,email,role,active FROM users WHERE id=? LIMIT 1'); $q->execute([$targetId]);
            $target=$q->fetch(PDO::FETCH_ASSOC);
            if(!$target) throw new RuntimeException('User account not found.');
            if(strtolower((string)$target['email'])==='adminct4@gmail.com') throw new RuntimeException('The primary administrator must complete Face ID setup from the sign-in screen before Face ID can be managed here.');
            $_SESSION['pending_admin_face_user']=['id'=>(int)$target['id'],'name'=>(string)$target['name'],'email'=>(string)$target['email'],'role'=>(string)$target['role']];
            $_SESSION['pending_admin_face_created']=time();
            $this->audit->record($user,self::MODULE,'Start Face ID Enrollment',(string)$target['email']);
            return 'Face ID enrollment started.';
        }
        if($action==='delete_user'){
            $targetId=(int)($data['id']??0);
            if($targetId<=0) throw new RuntimeException('Invalid user account.');
            if($targetId===(int)($user['id']??0)) throw new RuntimeException('You cannot delete the account currently signed in.');
            $q=$this->pdo->prepare('SELECT id,name,email,role,active,created_at FROM users WHERE id=? LIMIT 1'); $q->execute([$targetId]);
            $target=$q->fetch(PDO::FETCH_ASSOC);
            if(!$target) throw new RuntimeException('User account not found.');
            if(strtolower((string)$target['email'])==='adminct4@gmail.com') throw new RuntimeException('The primary administrator account cannot be deleted.');
            $this->pdo->beginTransaction();
            try {
                // Preserve the account record in the existing Archive table before deletion.
                $payload=json_encode($target,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                $archive=$this->pdo->prepare('INSERT INTO archive_items(item_type,source_table,source_id,item_name,payload,deleted_by) VALUES(?,?,?,?,?,?)');
                $archive->execute(['user_account','users',$targetId,(string)$target['name'].' — '.(string)$target['email'],$payload,(int)$user['id']]);
                $this->pdo->prepare('DELETE FROM users WHERE id=?')->execute([$targetId]);
                $this->pdo->commit();
            } catch(Throwable $e) { if($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
            $this->audit->record($user,self::MODULE,'Delete User',(string)$target['email'].' — archived before deletion');
            return 'User account deleted and archived.';
        }
        if($action==='edit_user'){
            $targetId=(int)($data['id']??0); $name=trim((string)($data['name']??'')); $email=strtolower(trim((string)($data['email']??''))); $password=(string)($data['password']??''); $role=(string)($data['role']??'Staff');
            $target=$this->pdo->prepare('SELECT email FROM users WHERE id=?'); $target->execute([$targetId]);
            if (strtolower((string)$target->fetchColumn())==='adminct4@gmail.com' && ($role!=='Administrator' || $email!=='adminct4@gmail.com')) throw new RuntimeException('The primary administrator account must remain an Administrator with its designated email.');
            if($targetId<=0 || $name==='' || !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid name and email address.');
            if(!in_array($role,['Administrator','Staff'],true)) throw new RuntimeException('Invalid role selected.');
            if($password!=='' && strlen($password)<6) throw new RuntimeException('New password must be at least 6 characters.');
            $check=$this->pdo->prepare('SELECT id,name,email FROM users WHERE id<>? AND (email=? OR name=?) LIMIT 2'); $check->execute([$targetId,$email,$name]);
            foreach($check->fetchAll(PDO::FETCH_ASSOC) as $existing){
                if(strcasecmp((string)$existing['email'],$email)===0) throw new RuntimeException('That email is already in use.');
                if(strcasecmp(trim((string)$existing['name']),$name)===0) throw new RuntimeException('That name is already in use.');
            }
            if($password!==''){
                $s=$this->pdo->prepare('UPDATE users SET name=?,email=?,role=?,password_hash=? WHERE id=?'); $s->execute([$name,$email,$role,password_hash($password,PASSWORD_DEFAULT),$targetId]);
            } else {
                $s=$this->pdo->prepare('UPDATE users SET name=?,email=?,role=? WHERE id=?'); $s->execute([$name,$email,$role,$targetId]);
            }
            $this->audit->record($user,self::MODULE,'Edit User',$email);
            return 'User account updated.';
        }
        if($action==='set_user_status'){
            $targetId=(int)($data['id']??0); $active=((int)($data['active']??0)===1)?1:0; if($targetId<=0) throw new RuntimeException('Invalid user account.');
            $target=$this->pdo->prepare('SELECT email FROM users WHERE id=?'); $target->execute([$targetId]);
            if (strtolower((string)$target->fetchColumn())==='adminct4@gmail.com' && $active===0) throw new RuntimeException('The primary administrator account cannot be deactivated.');
            if($targetId===(int)($user['id']??0)&&$active===0) throw new RuntimeException('You cannot deactivate the account currently signed in.');
            $this->pdo->prepare('UPDATE users SET active=? WHERE id=?')->execute([$active,$targetId]);
            $this->audit->record($user,self::MODULE,$active?'Activate User':'Deactivate User','ID '.$targetId); return $active?'User activated.':'User deactivated.';
        }
        throw new RuntimeException('Unsupported Administration & Security action.');
    }
}
