<?php
final class AssetEquipmentService {
    private const MODULE='Asset & Equipment Issuance';
    public function __construct(private PDO $pdo, private AuditService $audit) {}
    public function assets(): array {
        return $this->pdo->query("SELECT a.*, CASE WHEN a.status IN ('Maintenance','Retired') THEN 0 ELSE GREATEST(a.quantity - COALESCE((SELECT COUNT(*) FROM asset_issuances i WHERE i.asset_id=a.id AND i.status IN ('Issued','Overdue','Not Returned')),0),0) END AS available_quantity FROM assets a ORDER BY a.id DESC")->fetchAll();
    }
    public function issuances(): array { return $this->pdo->query('SELECT i.*,a.asset_tag,a.name FROM asset_issuances i JOIN assets a ON a.id=i.asset_id ORDER BY i.id DESC')->fetchAll(); }
    public function stats(): array { return [
        'assets'=>(int)$this->pdo->query('SELECT COUNT(*) FROM assets')->fetchColumn(),
        'issued'=>(int)$this->pdo->query("SELECT COUNT(*) FROM asset_issuances WHERE status='Issued'")->fetchColumn(),
        'maintenance'=>(int)$this->pdo->query("SELECT COUNT(*) FROM assets WHERE status='Maintenance'")->fetchColumn(),
    ]; }
    public function handle(string $action,array $data,?array $user): string {
        if($action==='add_asset'){
            $quantity=max(1,(int)($data['quantity']??1));
            $tag=trim((string)($data['asset_tag']??'')); $name=trim((string)($data['name']??'')); if($tag===''||$name==='') throw new RuntimeException('Asset tag and name are required.');
            $status=(string)($data['status']??'Available'); if(!in_array($status,['Available','Issued','Maintenance','Retired'],true)) throw new RuntimeException('Invalid asset status.');
            [$imageData,$imageName,$imageType,$imageSize,$imageHash]=$this->validatedImage($data['asset_image']??null);
            $s=$this->pdo->prepare('INSERT INTO assets(asset_tag,name,category,serial_number,status,quantity,location,image_name,image_type,image_size,image_hash,image_data) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
            $s->execute([$tag,$name,trim((string)($data['category']??'')),trim((string)($data['serial_number']??'')),$status,$quantity,trim((string)($data['location']??'')),$imageName,$imageType,$imageSize,$imageHash,$imageData]);
            $this->audit->record($user,self::MODULE,'Register Asset',$tag); return $imageData!==null ? 'Asset registered with picture.' : 'Asset registered.';
        }
        if($action==='update_asset_picture'){
            $assetId=(int)($data['asset_id']??0); if($assetId<=0) throw new RuntimeException('Invalid asset record.');
            [$imageData,$imageName,$imageType,$imageSize,$imageHash]=$this->validatedImage($data['asset_image']??null);
            if($imageData===null) throw new RuntimeException('Please select a valid asset picture.');
            $q=$this->pdo->prepare('SELECT asset_tag FROM assets WHERE id=?'); $q->execute([$assetId]); $tag=$q->fetchColumn();
            if($tag===false) throw new RuntimeException('Asset record not found.');
            $s=$this->pdo->prepare('UPDATE assets SET image_name=?,image_type=?,image_size=?,image_hash=?,image_data=? WHERE id=?');
            $s->execute([$imageName,$imageType,$imageSize,$imageHash,$imageData,$assetId]);
            $this->audit->record($user,self::MODULE,'Update Asset Picture',$tag); return 'Asset picture updated.';
        }
        if($action==='issue_asset'){
            $assetId=(int)($data['asset_id']??0); $employee=trim((string)($data['employee_name']??'')); if($assetId<=0||$employee===''||empty($data['issued_date'])) throw new RuntimeException('Available asset, employee and issued date are required.');
            $issuedDate=(string)$data['issued_date'];
            $expectedReturn=trim((string)($data['expected_return']??''));
            if(!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$issuedDate) || !strtotime($issuedDate)) throw new RuntimeException('Invalid issued date.');
            if($expectedReturn!=='' && (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$expectedReturn) || !strtotime($expectedReturn) || $expectedReturn < $issuedDate)) throw new RuntimeException('Expected return date must be on or after the issued date.');
            $this->pdo->beginTransaction(); try {
                $lock=$this->pdo->prepare('SELECT status,quantity,(SELECT COUNT(*) FROM asset_issuances i WHERE i.asset_id=assets.id AND i.status IN (\'Issued\',\'Overdue\',\'Not Returned\')) AS active_issuances FROM assets WHERE id=? FOR UPDATE'); $lock->execute([$assetId]); $asset=$lock->fetch();
                if(!$asset||$asset['status']!=='Available' || ((int)$asset['quantity']-(int)$asset['active_issuances'])<=0) throw new RuntimeException('Selected asset is no longer available.');
                $s=$this->pdo->prepare('INSERT INTO asset_issuances(asset_id,employee_name,issued_date,expected_return,status,notes) VALUES(?,?,?,?,?,?)');
                $s->execute([$assetId,$employee,$issuedDate,$expectedReturn!==''?$expectedReturn:null,'Issued',trim((string)($data['notes']??''))]);
                $remaining=(int)$asset['quantity']-(int)$asset['active_issuances']-1; $this->pdo->prepare("UPDATE assets SET status=? WHERE id=?")->execute([$remaining>0?'Available':'Issued',$assetId]); $this->pdo->commit();
            } catch(Throwable $e){ if($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
            $this->audit->record($user,self::MODULE,'Issue Equipment','Asset ID '.$assetId); return 'Equipment issued.';
        }
        if($action==='update_issuance_status'){
            $role=(string)($user['role']??'');
            if(!in_array($role,['Administrator','Staff'],true)) throw new RuntimeException('Only Administrator or Staff users can change borrowed item status.');
            $id=(int)($data['issuance_id']??0); $status=(string)($data['status']??'');
            if($id<=0) throw new RuntimeException('Invalid issuance record.');
            if(!in_array($status,['Issued','Returned','Overdue','Not Returned'],true)) throw new RuntimeException('Invalid issuance status.');
            $this->pdo->beginTransaction();
            try {
                $r=$this->pdo->prepare('SELECT asset_id,status FROM asset_issuances WHERE id=? FOR UPDATE'); $r->execute([$id]); $row=$r->fetch();
                if(!$row) throw new RuntimeException('Issuance record not found.');
                if($row['status']==='Returned' && $status==='Returned') throw new RuntimeException('This issuance is already returned.');
                $this->pdo->prepare("UPDATE asset_issuances SET status=?,return_date=".($status==='Returned'?'CURDATE()':'NULL')." WHERE id=?")->execute([$status,$id]);
                $assetId=(int)$row['asset_id'];
                $active=$this->pdo->prepare("SELECT COUNT(*) FROM asset_issuances WHERE asset_id=? AND status IN ('Issued','Overdue','Not Returned')");
                $active->execute([$assetId]); $activeCount=(int)$active->fetchColumn();
                $assetState=$this->pdo->prepare('SELECT status FROM assets WHERE id=? FOR UPDATE'); $assetState->execute([$assetId]); $currentAssetStatus=$assetState->fetchColumn();
                if($currentAssetStatus===false) throw new RuntimeException('Asset record not found.');
                $nextStatus=in_array($currentAssetStatus,['Maintenance','Retired'],true) ? $currentAssetStatus : ($activeCount>0 ? 'Issued' : 'Available');
                $this->pdo->prepare('UPDATE assets SET status=? WHERE id=?')->execute([$nextStatus,$assetId]);
                $this->pdo->commit();
            } catch(Throwable $e){ if($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
            $this->audit->record($user,self::MODULE,'Update Issuance Status','Issuance ID '.$id.' -> '.$status);
            return 'Borrowed item status updated to '.$status.'.';
        }
        throw new RuntimeException('Unsupported Asset & Equipment action.');
    }

    /** @return array{0:?string,1:?string,2:?string,3:int,4:?string} */
    private function validatedImage(mixed $file): array {
        if(!is_array($file) || !isset($file['error']) || (int)$file['error']===UPLOAD_ERR_NO_FILE) return [null,null,null,0,null];
        $uploadError=(int)($file['error']??UPLOAD_ERR_NO_FILE);
        if($uploadError!==UPLOAD_ERR_OK){
            $errors=[
                UPLOAD_ERR_INI_SIZE=>'The selected picture is larger than the server upload limit.',
                UPLOAD_ERR_FORM_SIZE=>'The selected picture is larger than the allowed form size.',
                UPLOAD_ERR_PARTIAL=>'The picture upload was interrupted. Please try again.',
                UPLOAD_ERR_NO_TMP_DIR=>'The server upload folder is unavailable.',
                UPLOAD_ERR_CANT_WRITE=>'The server could not save the uploaded picture.',
                UPLOAD_ERR_EXTENSION=>'The server stopped the picture upload.'
            ];
            throw new RuntimeException($errors[$uploadError]??'Asset picture upload failed.');
        }
        $size=(int)($file['size']??0); if($size<=0 || $size>5*1024*1024) throw new RuntimeException('Asset picture must be between 1 byte and 5 MB.');
        $tmp=(string)($file['tmp_name']??'');
        if($tmp==='' || !is_file($tmp) || !is_readable($tmp)) throw new RuntimeException('The uploaded picture could not be read by the server.');
        if(function_exists('is_uploaded_file') && !is_uploaded_file($tmp)) throw new RuntimeException('The picture upload could not be verified by the server.');
        $info=@getimagesize($tmp); if($info===false) throw new RuntimeException('The asset picture must be a valid image.');
        $mime=(string)($info['mime']??'');
        $allowed=['image/jpeg','image/png','image/webp','image/gif'];
        if(!in_array($mime,$allowed,true)) throw new RuntimeException('Allowed asset picture types are JPG, PNG, WEBP, and GIF.');
        $data=@file_get_contents($tmp); if($data===false || $data==='') throw new RuntimeException('Unable to read the asset picture.');
        $name=trim((string)($file['name']??'asset-picture')); $name=function_exists('mb_substr')?mb_substr($name,0,255):substr($name,0,255);
        return [$data,$name,$mime,$size,hash('sha256',$data)];
    }
}
