<?php
/**
 * Feedback Service — employee feedback with categories, optional rating,
 * threaded admin replies and a simple open → replied → resolved status.
 * Stored in admin_notifications (type 'feedback' / 'feedback_reply') so existing data keeps working.
 */
final class FeedbackService {
    public const CATEGORIES = ['Suggestion','Problem / Bug','Compliment','Question','Other'];
    private const MAX_LEN = 3000;
    private const HOURLY_LIMIT = 5;

    public function __construct(private PDO $pdo, private NotificationService $notes, private AuditService $audit) {}

    public function submit(array $user, string $message, string $category, ?int $rating): int {
        $message = trim($message);
        if (mb_strlen($message) < 5) throw new RuntimeException('Please write a little more detail (at least 5 characters).');
        if (mb_strlen($message) > self::MAX_LEN) throw new RuntimeException('Feedback is limited to '.self::MAX_LEN.' characters.');
        if (!in_array($category, self::CATEGORIES, true)) $category = 'Other';
        if ($rating !== null && ($rating < 1 || $rating > 5)) $rating = null;
        $uid = (int)($user['id'] ?? 0);

        $q = $this->pdo->prepare("SELECT COUNT(*) FROM admin_notifications WHERE type='feedback' AND sender_user_id=? AND created_at >= (NOW() - INTERVAL 1 HOUR)");
        $q->execute([$uid]);
        if ((int)$q->fetchColumn() >= self::HOURLY_LIMIT) throw new RuntimeException('You have sent several feedback messages recently. Please try again later.');
        $d = $this->pdo->prepare("SELECT COUNT(*) FROM admin_notifications WHERE type='feedback' AND sender_user_id=? AND message=? AND created_at >= (NOW() - INTERVAL 1 DAY)");
        $d->execute([$uid, $message]);
        if ((int)$d->fetchColumn() > 0) throw new RuntimeException('This feedback was already sent.');

        // One shared inbox row for all administrators (not just the first one).
        $id = $this->notes->notify(null, 'feedback', 'New User Feedback', $message, $user, ['category'=>$category,'rating'=>$rating]);
        $this->audit->record($user, 'System Administration & Security', 'Submit Feedback', ($user['name'] ?? '').' ('.($user['role'] ?? '').') — '.$category);
        return $id;
    }

    private function original(int $id): array {
        $q = $this->pdo->prepare("SELECT * FROM admin_notifications WHERE id=? AND type='feedback' LIMIT 1");
        $q->execute([$id]);
        $row = $q->fetch();
        if (!$row) throw new RuntimeException('The selected feedback could not be found.');
        return $row;
    }

    /** Resolve who wrote a feedback row. Never guesses: legacy rows need an unambiguous match. */
    private function authorId(array $row): int {
        if ((int)($row['sender_user_id'] ?? 0) > 0) return (int)$row['sender_user_id'];
        if (!empty($row['sender_name'])) {
            $f = $this->pdo->prepare("SELECT id FROM users WHERE name=?");
            $f->execute([$row['sender_name']]);
            $ids = $f->fetchAll(PDO::FETCH_COLUMN);
            if (count($ids) === 1) return (int)$ids[0];
        }
        throw new RuntimeException('The staff account for this feedback could not be identified.');
    }

    public function reply(array $admin, int $feedbackId, string $message): void {
        if (($admin['role'] ?? '') !== 'Administrator') throw new RuntimeException('Only an administrator can reply to feedback.');
        $message = trim($message);
        if ($message === '') throw new RuntimeException('Please provide a reply.');
        if (mb_strlen($message) > self::MAX_LEN) throw new RuntimeException('Reply is limited to '.self::MAX_LEN.' characters.');
        $orig = $this->original($feedbackId);
        $to = $this->authorId($orig);
        $this->notes->notify($to, 'feedback_reply', 'Reply to Your Feedback', $message, $admin, ['parent_id'=>$feedbackId]);
        if (($orig['status'] ?? 'open') === 'open') {
            $this->pdo->prepare("UPDATE admin_notifications SET status='replied' WHERE id=?")->execute([$feedbackId]);
        }
        $this->audit->record($admin, 'System Administration & Security', 'Reply to Feedback', ($orig['sender_name'] ?? 'Staff').' — '.mb_substr($message, 0, 200));
    }

    public function setStatus(array $admin, int $feedbackId, string $status): void {
        if (($admin['role'] ?? '') !== 'Administrator') throw new RuntimeException('Only an administrator can change feedback status.');
        if (!in_array($status, ['open','replied','resolved'], true)) throw new RuntimeException('Invalid status.');
        $orig = $this->original($feedbackId);
        $this->pdo->prepare("UPDATE admin_notifications SET status=?,is_read=1 WHERE id=?")->execute([$status, $feedbackId]);
        if ($status === 'resolved' && ($orig['status'] ?? '') !== 'resolved') {
            try { $this->notes->notify($this->authorId($orig), 'feedback_reply', 'Feedback Resolved', 'Your feedback was reviewed and marked as resolved: "'.mb_substr((string)$orig['message'], 0, 120).'"', $admin, ['parent_id'=>$feedbackId]); } catch (Throwable $e) {}
        }
        $this->audit->record($admin, 'System Administration & Security', 'Feedback '.ucfirst($status), '#'.$feedbackId);
    }

    /** Feedback history for the signed-in user, each with its admin replies (oldest first). */
    public function mine(array $user, int $limit = 30): array {
        $limit = max(1, min(100, $limit));
        $q = $this->pdo->prepare("SELECT id,message,category,rating,status,created_at FROM admin_notifications
            WHERE type='feedback' AND sender_user_id=? ORDER BY id DESC LIMIT $limit");
        $q->execute([(int)($user['id'] ?? 0)]);
        return $this->attachReplies($q->fetchAll());
    }

    /** Admin view of feedback threads. */
    public function inbox(array $admin, string $status = '', int $beforeId = 0, int $limit = 20): array {
        if (($admin['role'] ?? '') !== 'Administrator') throw new RuntimeException('Administrator access required.');
        $limit = max(1, min(50, $limit));
        $sql = "SELECT id,message,category,rating,status,sender_name,sender_role,sender_user_id,is_read,created_at FROM admin_notifications WHERE type='feedback'";
        $p = [];
        if (in_array($status, ['open','replied','resolved'], true)) { $sql .= " AND status=?"; $p[] = $status; }
        if ($beforeId > 0) { $sql .= " AND id<?"; $p[] = $beforeId; }
        $sql .= " ORDER BY id DESC LIMIT ".($limit + 1);
        $q = $this->pdo->prepare($sql); $q->execute($p);
        $rows = $q->fetchAll();
        $more = count($rows) > $limit; if ($more) array_pop($rows);
        return ['items' => $this->attachReplies($rows), 'has_more' => $more];
    }

    private function attachReplies(array $rows): array {
        if (!$rows) return [];
        $ids = array_map(fn($r) => (int)$r['id'], $rows);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $q = $this->pdo->prepare("SELECT id,parent_id,message,sender_name,sender_role,created_at FROM admin_notifications
            WHERE type='feedback_reply' AND parent_id IN ($in) AND title='Reply to Your Feedback' ORDER BY id ASC");
        $q->execute($ids);
        $by = [];
        foreach ($q->fetchAll() as $r) $by[(int)$r['parent_id']][] = $r;
        foreach ($rows as &$r) $r['replies'] = $by[(int)$r['id']] ?? [];
        return $rows;
    }
}
