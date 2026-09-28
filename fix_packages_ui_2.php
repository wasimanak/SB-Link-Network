<?php
$file = 'C:/xampp/htdocs/SB Link Network/operator/packages.php';
$content = file_get_contents($file);

// 1. Fix the Night Scheduler HTML
$pattern = '/<h6 class="text-primary mb-3"><i class="fa-regular fa-clock"><\/i> Night \/ Speed Scheduler \(Optional\)<\/h6>.*?<div class="col-12"><small class="text-muted">If set, FreeRADIUS\/MikroTik will change user\'s speed during these hours automatically\.<\/small><\/div>\s*<\/div>/s';

$replacement = <<<'HTML'
            <h6 class="text-primary mb-3"><i class="fa-regular fa-clock"></i> Night / Speed Scheduler (Optional)</h6>
            <div class="row bg-light p-3 rounded border">
                <div class="col-md-6 mb-2">
                    <label class="form-label small fw-bold">Start Time</label>
                    <input type="time" name="sched_start" id="sched_start" class="form-control">
                </div>
                <div class="col-md-6 mb-2">
                    <label class="form-label small fw-bold">End Time</label>
                    <input type="time" name="sched_end" id="sched_end" class="form-control">
                </div>
                <div class="col-12 mb-2">
                    <label class="form-label small fw-bold">Night Speed</label>
                    <div class="row g-2">
                        <div class="col-6">
                            <div class="input-group">
                                <span class="input-group-text bg-white"><i class="fa-solid fa-upload text-danger"></i></span>
                                <input type="text" name="sched_up" id="sched_up" class="form-control" placeholder="Upload (e.g. 20M)">
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="input-group">
                                <span class="input-group-text bg-white"><i class="fa-solid fa-download text-success"></i></span>
                                <input type="text" name="sched_down" id="sched_down" class="form-control" placeholder="Download (e.g. 20M)">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12"><small class="text-muted">If set, FreeRADIUS/MikroTik will change user's speed during these hours automatically.</small></div>
            </div>
HTML;
$content = preg_replace($pattern, $replacement, $content);

// 2. Fix the JS syntax error
$js_pattern = '/\} else \{\s*document\.getElementById\(\'sched_start\'\)\.value = \'\';\s*document\.getElementById\(\'sched_end\'\)\.value = \'\';\s*document\.getElementById\(\'sched_speed\'\)\.value = \'\';\s*\}/s';
$content = preg_replace($js_pattern, '', $content); // Remove the duplicate else block entirely

file_put_contents($file, $content);
echo "packages.php JS and Night Scheduler fixed.\n";
?>
