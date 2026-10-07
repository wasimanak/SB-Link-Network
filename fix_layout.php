<?php
$f = 'superadmin/live_routers.php';
$c = file_get_contents($f);

// 1. Remove the bad buttons from the accordion-button
$badHeaderStart = '<div class="d-flex justify-content-between align-items-center w-100 pe-3">
                    <div>
                        <button type="button" class="btn btn-sm btn-outline-primary me-3 sync-btn" data-nas="<?= $router[\'id\'] ?>" title="Sync Router (Clear Ghost Sessions)" onclick="syncRouter(event, this)">
                            <i class="fa-solid fa-rotate"></i> Sync
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger me-3 flush-btn" data-nas="<?= $router[\'id\'] ?>" title="Force Clear All Database Sessions for this Router" onclick="flushRouter(event, this)">
                            <i class="fa-solid fa-broom"></i> Flush DB
                        </button>
                    </div>';
$goodHeaderStart = '<div class="d-flex justify-content-between align-items-center w-100 pe-3">';
$c = str_replace($badHeaderStart, $goodHeaderStart, $c);

// 2. Inject the buttons safely INSIDE the accordion-body, above the table
$bodyTarget = '<div class="accordion-body bg-light p-4">';
$newBodyHeader = '<div class="accordion-body bg-light p-4">
                <div class="d-flex justify-content-end mb-3 gap-2">
                    <button type="button" class="btn btn-sm btn-outline-primary sync-btn shadow-sm" data-nas="<?= $router[\'id\'] ?>" title="Sync Router (Clear Ghost Sessions)" onclick="syncRouter(this)">
                        <i class="fa-solid fa-rotate"></i> Sync
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger flush-btn shadow-sm" data-nas="<?= $router[\'id\'] ?>" title="Force Clear All Database Sessions for this Router" onclick="flushRouter(this)">
                        <i class="fa-solid fa-broom"></i> Flush DB
                    </button>
                </div>';
$c = str_replace($bodyTarget, $newBodyHeader, $c);

// 3. Fix the onclick signature in JS (removed event since we don't need stopPropagation anymore)
$c = str_replace('function syncRouter(e, btn) {', 'function syncRouter(btn) {', $c);
$c = str_replace('e.stopPropagation(); // Prevent accordion from toggling', '', $c);
$c = str_replace('function flushRouter(e, btn) {', 'function flushRouter(btn) {', $c);
$c = str_replace('e.stopPropagation();', '', $c);

file_put_contents($f, $c);
echo "Fixed HTML layout by moving buttons to accordion body.\n";
?>
