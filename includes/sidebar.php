<?php
$cp  = basename($_SERVER['PHP_SELF']);
$cd  = basename(dirname($_SERVER['PHP_SELF']));
function navCls(array $pages, string $dir=''): string {
    global $cp,$cd;
    foreach($pages as $p) if($cp===$p||$cd===$p) return 'active';
    return '';
}
?>
<nav id="sidebar">
  <div class="sidebar-brand">
    <a href="<?=BASE_URL?>/dashboard.php">
      <i class="bi bi-book-half"></i>
    </a>
  </div>

  <div class="sidebar-links">
    <a href="<?=BASE_URL?>/dashboard.php" class="s-link <?=navCls(['dashboard.php'])?>" title="Dashboard">
      <div class="s-icon-bg"><i class="bi bi-house"></i></div>
    </a>
    
    <a href="<?=BASE_URL?>/modules/books/index.php" class="s-link <?=navCls(['index.php','add.php','edit.php'],'books')?>" title="Books">
      <div class="s-icon-bg"><i class="bi bi-book"></i></div>
    </a>
    
    <a href="<?=BASE_URL?>/modules/borrowers/index.php" class="s-link <?=navCls(['index.php','add.php','edit.php'],'borrowers')?>" title="Borrowers">
      <div class="s-icon-bg"><i class="bi bi-people"></i></div>
    </a>
    
    <a href="<?=BASE_URL?>/modules/borrow/index.php" class="s-link <?=navCls(['index.php','issue.php','return.php'],'borrow')?>" title="Borrow & Return">
      <div class="s-icon-bg"><i class="bi bi-arrow-left-right"></i></div>
    </a>
    
    <a href="<?=BASE_URL?>/modules/reports/index.php" class="s-link <?=navCls(['index.php'],'reports')?>" title="Reports">
      <div class="s-icon-bg"><i class="bi bi-pie-chart"></i></div>
    </a>

    <a href="<?=BASE_URL?>/auth/logout.php" class="s-link mt-auto" title="Logout">
      <div class="s-icon-bg"><i class="bi bi-box-arrow-right"></i></div>
    </a>
  </div>
</nav>
<div id="sidebarOverlay" class="sidebar-overlay d-lg-none"></div>
