<?php
/**
 * Notification Service — single owner of the admin_notifications table.
 *
 * Visibility rules (fixes duplicate/leaky inboxes in the old implementation):
 *  - Everyone sees rows addressed to them (user_id = me) or broadcast (user_id IS NULL).
 *  - Administrators additionally share the staff-feedback inbox (type = 'feedback').
 *  - Read / dismiss state is per row; opening the inbox no longer marks everything read.
 */
final class NotificationService {
    public const TYPES = ['feedback','feedback_reply','data_transfer','file_release','file_request','system'];

    public function __construct(private PDO $pdo) {}

    /* ---------- creating ---------- */

    public function notify(?int $userId, string $type, string $title, string $message, ?array $sender = null, array $extra = []): int {
        if (!in_array($type, self::TYPES, true)) $type = 'system';
        $stmt = $this->pdo->prepare("INSERT INTO admin_notifications
            (user_id,type,title,message,category,rating,parent_id,sender_name,sender_role,sender_user_id)
            VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $userId ?: null, $type, mb_substr($title, 0, 180), $message,
            $extra['category'] ?? null, $extra['rating'] ?? null, $extra['parent_id'] ?? null,
            $sender['name'] ?? null, $sender['role'] ?? null,
            isset($sender['id']) && (int)$sender['id'] > 0 ? (int)$sender['id'] : null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /** @return int[] active user ids for a role, optionally excluding one user. */
    public function userIdsByRole(string $role, ?int $excludeId = null): array {
        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE role=? AND active=1");
        $stmt->execute([$role]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        return array_values(array_filter($ids, fn($id) => $id !== (int)$excludeId));
    }

    public function notifyRole(string $role, string $type, string $title, string $message, ?array $sender = null, ?int $excludeId = null): int {
        $n = 0;
        foreach ($this->userIdsByRole($role, $excludeId) as $id) { $this->notify($id, $type, $title, $message, $sender); $n++; }
        return $n;
    }

    /* ---------- reading ---------- */

    /** SQL fragment + params selecting what $user may see. */
    private function scope(array $user): array {
        $uid = (int)($user['id'] ?? 0);
        if (($user['role'] ?? '') === 'Administrator') {
            return ["(user_id = ? OR user_id IS NULL OR type = 'feedback')", [$uid]];
        }
        return ["((user_id = ? OR user_id IS NULL) AND type <> 'feedback')", [$uid]];
    }

    public function unreadCount(array $user): int {
        [$where, $params] = $this->scope($user);
        // Feedback is thread-based. Count one unread item per thread even if
        // older deployments created multiple notification rows for the same
        // thread. Other notification types remain row-based.
        $sql = "SELECT COUNT(*) FROM admin_notifications WHERE $where AND is_read=0 AND is_dismissed=0 AND type<>'feedback'
                UNION ALL
                SELECT COUNT(DISTINCT COALESCE(feedback_thread_id, id)) FROM admin_notifications WHERE $where AND is_read=0 AND is_dismissed=0 AND type='feedback'";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge($params,$params));
        $counts=$stmt->fetchAll(PDO::FETCH_COLUMN);
        return (int)array_sum(array_map('intval',$counts));
    }

    /**
     * @param array{filter?:string,type?:string,before_id?:int,limit?:int} $opts
     * @return array{items:array,has_more:bool,unread:int}
     */
    public function list(array $user, array $opts = []): array {
        [$where, $params] = $this->scope($user);
        $limit = max(1, min(50, (int)($opts['limit'] ?? 20)));
        $sql = "SELECT n.id,n.type,n.title,n.message,n.category,n.rating,n.parent_id,n.status,n.sender_name,n.sender_role,
                       n.sender_user_id,n.user_id,n.feedback_thread_id,n.is_read,n.created_at
                FROM admin_notifications n WHERE $where AND n.is_dismissed=0";
        if (($opts['filter'] ?? '') === 'unread') $sql .= " AND n.is_read=0";
        if (!empty($opts['type']) && in_array($opts['type'], self::TYPES, true)) { $sql .= " AND n.type=?"; $params[] = $opts['type']; }
        if (!empty($opts['before_id'])) { $sql .= " AND n.id < ?"; $params[] = (int)$opts['before_id']; }
        $sql .= " ORDER BY n.id DESC LIMIT " . ($limit + 1);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // Collapse duplicate feedback rows without deleting any database data.
        // The newest row represents the thread in the notification bell.
        if (($user['role'] ?? '') === 'Administrator') {
            $unique=[]; $seen=[];
            foreach($rows as $row){
                if(($row['type']??'')==='feedback'){
                    $key='thread:'.((int)($row['feedback_thread_id']??0) ?: ('legacy:'.((int)$row['id'])));
                    if(isset($seen[$key])) continue;
                    $seen[$key]=true;
                }
                $unique[]=$row;
            }
            $rows=$unique;
        }
        $more = count($rows) > $limit;
        if ($more) array_pop($rows);
        return ['items' => $rows, 'has_more' => $more, 'unread' => $this->unreadCount($user)];
    }

    /* ---------- state changes (always scoped to the caller) ---------- */

    public function markRead(array $user, int $id): void {
        [$where, $params] = $this->scope($user);
        $q=$this->pdo->prepare("SELECT feedback_thread_id,type FROM admin_notifications WHERE id=? LIMIT 1");
        $q->execute([$id]); $row=$q->fetch(PDO::FETCH_ASSOC);
        if(($user['role']??'')==='Administrator' && ($row['type']??'')==='feedback' && (int)($row['feedback_thread_id']??0)>0){
            $this->pdo->prepare("UPDATE admin_notifications SET is_read=1 WHERE feedback_thread_id=? AND type='feedback' AND $where")
                ->execute(array_merge([(int)$row['feedback_thread_id']],$params));
            return;
        }
        $this->pdo->prepare("UPDATE admin_notifications SET is_read=1 WHERE id=? AND $where")->execute(array_merge([$id], $params));
    }

    public function markAllRead(array $user): int {
        [$where, $params] = $this->scope($user);
        $stmt = $this->pdo->prepare("UPDATE admin_notifications SET is_read=1 WHERE is_read=0 AND is_dismissed=0 AND $where");
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /** Only notifications addressed to the user can be dismissed; the shared feedback inbox is resolved instead. */
    public function dismiss(array $user, int $id): bool {
        $stmt = $this->pdo->prepare("UPDATE admin_notifications SET is_dismissed=1,is_read=1 WHERE id=? AND user_id=? AND type<>'feedback'");
        $stmt->execute([$id, (int)($user['id'] ?? 0)]);
        return $stmt->rowCount() > 0;
    }
}
