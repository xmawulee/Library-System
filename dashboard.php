<?php
require_once __DIR__ . '/config/app.php';
requireLogin();

$pdo = getDB();
$pageTitle = 'Overview';

// ── Stats ────────────────────────────────────────────────────
$stats = $pdo->query("SELECT
    COUNT(*) total,
    SUM(status='Available')  available,
    SUM(status='Missing')    missing,
    SUM(available_copies < total_copies AND status <> 'Missing') issued,
    SUM(status='All Issued') all_issued
FROM books")->fetch();

$totalBorrowers = $pdo->query("SELECT COUNT(*) FROM borrowers WHERE status='Active'")->fetchColumn();
$totalRecords   = $pdo->query("SELECT COUNT(*) FROM borrow_records")->fetchColumn();
$overdueCount   = $pdo->query("SELECT COUNT(*) FROM borrow_records
    WHERE status='Not Returned' AND DATEDIFF(CURDATE(), borrow_date) > " . BORROW_DAYS)->fetchColumn();
$damagedCount   = $pdo->query("SELECT COUNT(*) FROM books WHERE condition_status IN ('Torn','Damaged')")->fetchColumn();

// ── Monthly borrow trend (last 6 months) ─────────────────────
$trend = $pdo->query("SELECT DATE_FORMAT(MIN(borrow_date),'%b %Y') lbl, COUNT(*) cnt
    FROM borrow_records
    WHERE borrow_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY YEAR(borrow_date), MONTH(borrow_date)
    ORDER BY MIN(borrow_date)")->fetchAll();

// ── Books by category ────────────────────────────────────────
$catData = $pdo->query("SELECT category, COUNT(*) cnt FROM books GROUP BY category ORDER BY cnt DESC LIMIT 8")->fetchAll();

// ── Recent borrow records (For 'In Progress' column) ─────────
$recent = $pdo->query("SELECT br.*, b.title, b.book_id book_code, bo.full_name borrower_name,
        bo.category borrower_cat,
        DATEDIFF(CURDATE(), br.borrow_date) days_borrowed
    FROM borrow_records br
    JOIN books b ON br.book_id = b.id
    JOIN borrowers bo ON br.borrower_id = bo.id
    WHERE br.status='Not Returned'
    ORDER BY br.created_at DESC LIMIT 4")->fetchAll();

// ── Overdue list (For 'At Risk/Review' column) ───────────────
$overdueList = $pdo->query("SELECT br.*, b.title, b.book_id book_code, bo.full_name borrower_name,
        DATEDIFF(CURDATE(), br.borrow_date) days_borrowed
    FROM borrow_records br
    JOIN books b ON br.book_id = b.id
    JOIN borrowers bo ON br.borrower_id = bo.id
    WHERE br.status='Not Returned' AND DATEDIFF(CURDATE(), br.borrow_date) > " . BORROW_DAYS . "
    ORDER BY days_borrowed DESC LIMIT 4")->fetchAll();

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<div id="main">
  <!-- Topbar -->
  <div class="topbar">
    <div class="d-flex flex-column">
      <span class="topbar-title">Overview</span>
      <span class="text-muted small" style="font-size:0.85rem">Track library resources, active loans, and performance easily.</span>
    </div>
    <div class="ms-auto d-flex align-items-center gap-3">
      <div class="text-muted small bg-white px-3 py-2 rounded-3 border">
        <i class="bi bi-calendar3 me-2"></i><?= date('D. d M Y') ?>
      </div>
      <a href="<?= BASE_URL ?>/modules/reports/index.php" class="btn btn-dark" style="border-radius:12px;padding:8px 20px;">
        <i class="bi bi-box-arrow-up-right me-2"></i>Export
      </a>
    </div>
  </div>

  <div class="page-content">
    <?= renderFlash() ?>

    <div class="dashboard-layout">
      <!-- Main Content (Left) -->
      <div class="dashboard-main">
        
        <!-- Overview Cards -->
        <div class="overview-grid">
          <div class="overview-card">
            <div class="overview-card-top">
              <i class="bi bi-book"></i> Total Books
            </div>
            <div class="overview-card-val"><?= number_format((int)$stats['total']) ?></div>
            <div class="overview-card-sub">Books cataloged in the system</div>
          </div>
          <div class="overview-card">
            <div class="overview-card-top">
              <i class="bi bi-check-circle"></i> Available Copies
            </div>
            <div class="overview-card-val"><?= number_format((int)$stats['available']) ?></div>
            <div class="overview-card-sub">Ready to be issued immediately</div>
          </div>
          <div class="overview-card">
            <div class="overview-card-top">
              <i class="bi bi-arrow-right-circle"></i> Active Loans
            </div>
            <div class="overview-card-val"><?= number_format((int)($stats['issued'] + $stats['all_issued'])) ?></div>
            <div class="overview-card-sub">Books currently out with patrons</div>
          </div>
        </div>

        <!-- Kanban Board Layout -->
        <div class="d-flex align-items-center gap-3 mb-4">
          <div class="bg-white p-1 rounded-3 shadow-sm border d-inline-flex">
            <button class="btn btn-sm btn-light bg-white border-0 fw-bold px-3">Board</button>
            <button class="btn btn-sm btn-light bg-transparent border-0 text-muted px-3">List</button>
          </div>
        </div>

        <div class="dashboard-kanban">
          
          <!-- Column 1: Active Loans -->
          <div class="kanban-col">
            <div class="kanban-col-header">
              In Progress (Loans) <span class="kanban-badge"><?= count($recent) ?></span>
            </div>
            
            <?php foreach($recent as $r): 
              $progress = min(100, round(($r['days_borrowed'] / max(1, BORROW_DAYS)) * 100));
            ?>
            <div class="kanban-card">
              <div class="k-title"><?= sanitize($r['title']) ?></div>
              <div class="k-desc">Issued to <?= sanitize($r['borrower_name']) ?> (<?= sanitize($r['borrower_cat']) ?>)</div>
              
              <div class="k-meta">
                <i class="bi bi-calendar"></i> Borrowed: <?= date('M d, Y', strtotime($r['borrow_date'])) ?>
              </div>
              <div class="k-meta">
                <i class="bi bi-clock text-muted"></i> Due: <?= date('M d, Y', strtotime($r['due_date'])) ?>
              </div>
              
              <div class="d-flex justify-content-between align-items-center mt-3">
                <div class="progress-bar-container flex-grow-1 me-2">
                  <div class="progress-bar-fill" style="width: <?= $progress ?>%"></div>
                </div>
                <div class="progress-val fw-bold"><?= $progress ?>%</div>
              </div>
            </div>
            <?php endforeach; ?>
            <?php if(!$recent): ?><div class="text-muted small">No active loans.</div><?php endif; ?>
          </div>

          <!-- Column 2: Overdue/Review -->
          <div class="kanban-col">
            <div class="kanban-col-header">
              In Review (Overdue) <span class="kanban-badge"><?= count($overdueList) ?></span>
            </div>
            
            <?php foreach($overdueList as $o): ?>
            <div class="kanban-card border-danger border-opacity-50">
              <div class="k-title text-danger"><?= sanitize($o['title']) ?></div>
              <div class="k-desc">Overdue for <?= sanitize($o['borrower_name']) ?></div>
              
              <div class="k-meta text-danger fw-medium">
                <i class="bi bi-exclamation-triangle"></i> <?= $o['days_borrowed'] ?> days late
              </div>
              <div class="k-meta">
                <i class="bi bi-calendar-x"></i> Due: <?= date('M d, Y', strtotime($o['due_date'])) ?>
              </div>
              
              <div class="d-flex justify-content-between align-items-center mt-3">
                <div class="progress-bar-container flex-grow-1 me-2">
                  <div class="progress-bar-fill bg-danger" style="width: 100%"></div>
                </div>
                <div class="progress-val text-danger fw-bold">100%</div>
              </div>
            </div>
            <?php endforeach; ?>
            <?php if(!$overdueList): ?><div class="text-muted small">No overdue books!</div><?php endif; ?>
          </div>

        </div>

      </div>

      <!-- Right Sidebar (Charts) -->
      <div class="dashboard-sidebar">
        
        <div class="chart-card">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="chart-header mb-0">Library Activity</div>
            <button class="btn btn-sm btn-light bg-white border shadow-sm rounded-circle"><i class="bi bi-three-dots"></i></button>
          </div>
          <div class="text-center position-relative mb-2">
             <canvas id="catChart" height="200"></canvas>
          </div>
          <div class="d-flex justify-content-center gap-3 mt-3 text-muted" style="font-size:0.75rem;font-weight:500">
             <span class="d-flex align-items-center gap-1"><span class="d-inline-block rounded-circle bg-success" style="width:8px;height:8px"></span> Science</span>
             <span class="d-flex align-items-center gap-1"><span class="d-inline-block rounded-circle" style="width:8px;height:8px;background:#69f0ae"></span> Fiction</span>
             <span class="d-flex align-items-center gap-1"><span class="d-inline-block rounded-circle" style="width:8px;height:8px;background:#b9f6ca"></span> History</span>
          </div>
        </div>

        <div class="chart-card">
           <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="chart-header mb-0">Borrowing Trends</div>
            <button class="btn btn-sm btn-light bg-white border shadow-sm rounded-circle"><i class="bi bi-three-dots"></i></button>
          </div>
          <div><canvas id="trendChart" height="180"></canvas></div>
        </div>

        <div class="smart-update-card">
          <div class="smart-update-title">Smart Library Update</div>
          <div class="smart-update-desc">
            The system tracks, analyzes, and updates circulation status to improve inventory efficiency and reduce book loss.
          </div>
          <i class="bi bi-stars position-absolute text-white opacity-25" style="font-size:5rem;right:-10px;bottom:-10px;"></i>
        </div>

      </div>
    </div>
    
  </div><!-- /.page-content -->
</div><!-- /#main -->

<?php require_once 'includes/footer.php'; ?>
<script>
// Trend chart (Bar)
const trendLabels = <?= json_encode(array_column($trend,'lbl')) ?>;
const trendData   = <?= json_encode(array_column($trend,'cnt')) ?>;
new Chart(document.getElementById('trendChart'), {
  type: 'bar',
  data: {
    labels: trendLabels,
    datasets: [{
      label: 'Borrows',
      data: trendData,
      backgroundColor: '#52b554', // Matches the green accent
      borderRadius: 6,
      borderSkipped: false
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: { 
      x: { grid: {display:false} },
      y: { border:{display:false}, grid: {color:'#f0f0f0'}, beginAtZero: true, ticks: { stepSize: 1 } } 
    }
  }
});

// Category pie (Doughnut)
const catLabels = <?= json_encode(array_column($catData,'category')) ?>;
const catCounts = <?= json_encode(array_column($catData,'cnt')) ?>;
// Use shades of green for the design match
const catColors = ['#2e7d32','#388e3c','#43a047','#4caf50','#66bb6a','#81c784','#a5d6a7','#c8e6c9'];
new Chart(document.getElementById('catChart'), {
  type: 'doughnut',
  data: {
    labels: catLabels,
    datasets: [{ data: catCounts, backgroundColor: catColors, borderWidth: 2, borderColor: '#fff', cutout: '75%' }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } }
  }
});
</script>
