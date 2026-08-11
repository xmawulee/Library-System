<?php
// ── Security ──────────────────────────────────────────────────
function sanitize(string $v): string {
    return htmlspecialchars(strip_tags(trim($v)), ENT_QUOTES, 'UTF-8');
}

// ── Auth ──────────────────────────────────────────────────────
function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}
function requireLogin(): void {
    if (!isLoggedIn()) { header('Location: ' . BASE_URL . '/auth/login.php'); exit; }
}
function currentUser(): array { return $_SESSION['user'] ?? []; }
function isAdmin(): bool { return ($_SESSION['user']['role'] ?? '') === 'admin'; }

// ── Flash Messages ────────────────────────────────────────────
function flash(string $type, string $msg): void {
    $_SESSION['flash'] = compact('type', 'msg');
}
function getFlash(): ?array {
    if (!isset($_SESSION['flash'])) return null;
    $f = $_SESSION['flash']; unset($_SESSION['flash']); return $f;
}
function renderFlash(): string {
    $f = getFlash(); if (!$f) return '';
    $t = $f['type'] === 'error' ? 'danger' : $f['type'];
    $ico = match($t) { 'success'=>'check-circle-fill','warning'=>'exclamation-triangle-fill', default=>'x-circle-fill' };
    return "<div class='alert alert-{$t} alert-dismissible fade show d-flex align-items-center gap-2' role='alert'>
        <i class='bi bi-{$ico}'></i><span>{$f['msg']}</span>
        <button type='button' class='btn-close ms-auto' data-bs-dismiss='alert'></button></div>";
}

// ── Status & condition badges ──────────────────────────────────
function badge(string $s): string {
    $m = [
        // Book availability statuses
        'Available'     => 'success',
        'Borrowed'      => 'warning text-dark',
        'Missing'       => 'danger',
        'All Issued'    => 'danger',              // all copies are out
        'Not Available' => 'secondary',           // manually overridden to unavailable

        // Borrow record statuses
        'Returned'     => 'success',
        'Not Returned' => 'warning text-dark',

        // Borrower statuses
        'Active'       => 'success',
        'Inactive'     => 'secondary',

        // Condition values — colour-coded by severity
        'Perfect'      => 'success',             // all good
        'Good'         => 'info text-dark',      // normal wear
        'Mildly Torn'  => 'warning text-dark',   // minor damage
        'Torn'         => 'orange text-dark',    // significant damage (custom)
        'Damaged'      => 'danger',              // severe damage
    ];
    $c = $m[$s] ?? 'secondary';
    // bg-orange is not a Bootstrap built-in; use inline style for Torn
    if ($s === 'Torn') {
        return "<span class='badge' style='background:#fd7e14;color:#000'>{$s}</span>";
    }
    return "<span class='badge bg-{$c}'>{$s}</span>";
}

// ── Condition severity scale ───────────────────────────────────
// Returns 1 (best) … 5 (worst) — mirrors the trigger CASE logic.
function conditionSeverity(string $condition): int {
    return match($condition) {
        'Perfect'    => 1,
        'Good'       => 2,
        'Mildly Torn' => 3,
        'Torn'       => 4,
        'Damaged'    => 5,
        default      => 2,
    };
}

// ── Condition <option> list ────────────────────────────────────
// Returns ready-to-embed HTML <option> elements for the five
// condition values. Used in both issue.php and return.php forms
// to avoid duplicated markup.
function conditionOptions(string $selected = ''): string {
    $conditions = ['Perfect', 'Good', 'Mildly Torn', 'Torn', 'Damaged'];
    $html = '';
    foreach ($conditions as $c) {
        $sel  = $c === $selected ? ' selected' : '';
        $html .= "<option value=\"{$c}\"{$sel}>{$c}</option>\n";
    }
    return $html;
}

// ── Overdue logic ─────────────────────────────────────────────
function isOverdue(string $borrowDate, ?string $returnDate, string $status): bool {
    if ($status === 'Returned') return false;
    return (new DateTime())->diff(new DateTime($borrowDate))->days > BORROW_DAYS;
}
function daysBorrowed(string $borrowDate, ?string $returnDate): int {
    $end = $returnDate ? new DateTime($returnDate) : new DateTime();
    return (int)(new DateTime($borrowDate))->diff($end)->days;
}

// ── ID generators ─────────────────────────────────────────────
function nextId(string $prefix, string $table, string $col): string {
    $pdo  = getDB();
    // Validate table and column names to prevent SQL injection
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $col)) {
        throw new InvalidArgumentException("Invalid table or column name");
    }
    $stmt = $pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(`{$col}`,'- ',-1) AS UNSIGNED)),0)+1 FROM `{$table}`");
    $n    = (int)$stmt->fetchColumn();
    return $prefix . str_pad($n, 3, '0', STR_PAD_LEFT);
}

// ── Pagination ────────────────────────────────────────────────
function paginate(int $total, int $perPage, int $page): array {
    $pages  = max(1, (int)ceil($total / $perPage));
    $page   = max(1, min($page, $pages));
    return ['total'=>$total,'per_page'=>$perPage,'current'=>$page,
            'total_pages'=>$pages,'offset'=>($page-1)*$perPage];
}
function pagerHtml(array $p, string $baseUrl): string {
    if ($p['total_pages'] <= 1) return '';
    $html = "<nav><ul class='pagination pagination-sm mb-0'>";
    $prev = $p['current'] - 1;
    $next = $p['current'] + 1;
    $disabled = $p['current'] <= 1 ? 'disabled' : '';
    $html .= "<li class='page-item {$disabled}'><a class='page-link' href='{$baseUrl}&page={$prev}'>&laquo;</a></li>";
    for ($i = max(1, $p['current']-2); $i <= min($p['total_pages'], $p['current']+2); $i++) {
        $active = $i === $p['current'] ? 'active' : '';
        $html .= "<li class='page-item {$active}'><a class='page-link' href='{$baseUrl}&page={$i}'>{$i}</a></li>";
    }
    $disabled2 = $p['current'] >= $p['total_pages'] ? 'disabled' : '';
    $html .= "<li class='page-item {$disabled2}'><a class='page-link' href='{$baseUrl}&page={$next}'>&raquo;</a></li>";
    return $html . "</ul></nav>";
}

// ── Audit logger ──────────────────────────────────────────────
function auditLog(string $action, string $target, int $targetId, string $detail = ''): void {
    try {
        $pdo = getDB();
        $s = $pdo->prepare("INSERT INTO audit_log(user_id,action,target,target_id,detail,ip_address) VALUES(?,?,?,?,?,?)");
        $s->execute([$_SESSION['user_id'] ?? null, $action, $target, $targetId, $detail, $_SERVER['REMOTE_ADDR'] ?? '']);
    } catch (Exception $e) { 
        error_log("Audit log failed: " . $e->getMessage());
    }
}

function redirect(string $url): never { header("Location: {$url}"); exit; }
