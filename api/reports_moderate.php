<?php
/**
 * Reports: Moderate (admin)
 *
 * Endpoint: POST /api/reports_moderate.php
 * Purpose: Approve or deny a report before it appears publicly.
 * Auth: Requires admin session
 *
 * Form params:
 * - report_id (int, required)
 * - decision (enum: approve|deny)
 * - notes (string, optional)
 *
 * Response:
 * - 200: { success: true, message, moderation_status }
 * - 4xx/5xx: { success: false, message }
 */
require_once __DIR__ . '/../includes/api_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (!is_admin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$reportId = isset($_POST['report_id']) ? (int)$_POST['report_id'] : 0;
$decision = isset($_POST['decision']) ? strtolower(trim((string)$_POST['decision'])) : '';
$notes = isset($_POST['notes']) ? trim((string)$_POST['notes']) : '';

if (!$reportId || !in_array($decision, ['approve','deny'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid report id or decision']);
    exit;
}

// Ensure the column exists to avoid runtime errors on older schemas
$hasModeration = false;
try {
    $chk = $conn->query("SHOW COLUMNS FROM reports LIKE 'moderation_status'");
    $hasModeration = ($chk && $chk->num_rows > 0);
    if ($chk) { $chk->close(); }
} catch (Throwable $e) { $hasModeration = false; }

if (!$hasModeration) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'Moderation fields missing. Run migration 013_add_reports_moderation.sql']);
    exit;
}

try {
    $admin = current_user();
    $adminId = (int)($admin['id'] ?? 0);
    $moderationStatus = $decision === 'approve' ? 'approved' : 'denied';

    // Get owner and title for notifications
    $stmt0 = $conn->prepare('SELECT user_id, title FROM reports WHERE id = ?');
    $stmt0->bind_param('i', $reportId);
    $stmt0->execute();
    $res0 = $stmt0->get_result();
    $row0 = $res0->fetch_assoc();
    $stmt0->close();

    $stmt = $conn->prepare('UPDATE reports SET moderation_status = ?, moderated_by = ?, moderated_at = NOW(), moderation_notes = ? WHERE id = ?');
    $stmt->bind_param('sisi', $moderationStatus, $adminId, $notes, $reportId);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        throw new Exception('Update failed');
    }

    // Optional: when approving, ensure status remains at least 'unresolved'
    // (we keep current status as-is)

    // Notify owner if present
    try {
        $userId = (int)($row0['user_id'] ?? 0);
        if ($userId > 0) {
            $title = $moderationStatus === 'approved' ? 'Report approved' : 'Report denied';
            $meta = ($row0['title'] ?? 'Your report');
            if ($moderationStatus === 'approved') { $meta .= ' is now live'; }
            if ($moderationStatus === 'denied') { $meta .= ' was not approved'; }
            if ($notes !== '') { $meta .= ' · ' . $notes; }

            $check = $conn->query("SHOW TABLES LIKE 'notifications'");
            if ($check && $check->num_rows > 0) {
                $type = $moderationStatus === 'approved' ? 'success' : 'warning';
                $stmtN = $conn->prepare('INSERT INTO notifications (user_id, title, meta, type, is_read, created_at, updated_at) VALUES (?, ?, ?, ?, 0, NOW(), NOW())');
                $stmtN->bind_param('isss', $userId, $title, $meta, $type);
                $stmtN->execute();
                $stmtN->close();
            }
        }
    } catch (Throwable $e) { /* ignore */ }

    echo json_encode(['success' => true, 'message' => 'Moderation updated', 'moderation_status' => $moderationStatus]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error moderating report']);
    exit;
}
