<?php
$file = 'C:/xampp/htdocs/SB Link Network/operator/packages.php';
$content = file_get_contents($file);

// 1. Update the POST handler
$old_post = <<<'PHP'
        $rate_limit = trim($_POST['rate_limit']);
PHP;

$new_post = <<<'PHP'
        $rate_up = trim($_POST['rate_up'] ?? '');
        $rate_down = trim($_POST['rate_down'] ?? '');
        
        if (strcasecmp($rate_up, 'Unlimited') === 0 || empty($rate_up) || empty($rate_down)) {
            $rate_limit = 'Unlimited';
        } else {
            $rate_limit = $rate_up . '/' . $rate_down;
        }
PHP;
$content = str_replace($old_post, $new_post, $content);

$old_sched_post = <<<'PHP'
                'end_time' => $_POST['sched_end'],     // e.g., '06:00'
                'speed' => $_POST['sched_speed']       // e.g., '20M/20M'
            ];
PHP;

$new_sched_post = <<<'PHP'
                'end_time' => $_POST['sched_end'],
                'speed' => trim($_POST['sched_up'] ?? '') . '/' . trim($_POST['sched_down'] ?? '')
            ];
PHP;
$content = str_replace($old_sched_post, $new_sched_post, $content);

// 2. Update the HTML Form fields
$old_html = <<<'HTML'
                  <div class="col-md-12 mb-3">
                      <label class="form-label fw-bold">Standard Rate Limit (Speed) <span class="text-danger">*</span></label>
                      <input type="text" name="rate_limit" id="pkg_rate" class="form-control" required placeholder="e.g. 10M/10M">
                      <small class="text-muted">MikroTik Format: RX/TX (Upload/Download)</small>
                  </div>
              </div>
  
              <hr>
              <h6 class="fw-bold mb-3"><i class="fa-solid fa-clock text-warning me-1"></i> Night/Off-Peak Speed Scheduler (Optional)</h6>
              <div class="row">
                  <div class="col-md-4 mb-3">
                      <label class="form-label fw-bold">Start Time</label>
                      <input type="time" name="sched_start" id="sched_start" class="form-control">
                  </div>
                  <div class="col-md-4 mb-3">
                      <label class="form-label fw-bold">End Time</label>
                      <input type="time" name="sched_end" id="sched_end" class="form-control">
                  </div>
                  <div class="col-md-4 mb-3">
                      <label class="form-label fw-bold">Scheduled Speed</label>
                      <input type="text" name="sched_speed" id="sched_speed" class="form-control" placeholder="e.g. 20M/20M">
                  </div>
              </div>
HTML;

$new_html = <<<'HTML'
                  <div class="col-md-12 mb-3">
                      <label class="form-label fw-bold text-dark">Standard Speed (Rate Limit)</label>
                      <div class="row g-2">
                          <div class="col-6">
                              <label class="form-label small text-muted mb-1">Upload Speed</label>
                              <div class="input-group">
                                  <span class="input-group-text bg-light"><i class="fa-solid fa-upload text-danger"></i></span>
                                  <input type="text" name="rate_up" id="pkg_rate_up" class="form-control" placeholder="e.g. 10M, 512k, or Unlimited" required>
                              </div>
                          </div>
                          <div class="col-6">
                              <label class="form-label small text-muted mb-1">Download Speed</label>
                              <div class="input-group">
                                  <span class="input-group-text bg-light"><i class="fa-solid fa-download text-success"></i></span>
                                  <input type="text" name="rate_down" id="pkg_rate_down" class="form-control" placeholder="e.g. 10M, 512k, or Unlimited" required>
                              </div>
                          </div>
                      </div>
                      <small class="text-muted d-block mt-2">Type <strong>Unlimited</strong> in any field to remove speed caps entirely.</small>
                  </div>
              </div>
  
              <hr>
              <h6 class="fw-bold mb-3"><i class="fa-solid fa-clock text-warning me-1"></i> Night/Off-Peak Speed Scheduler (Optional)</h6>
              <div class="row">
                  <div class="col-md-6 mb-3">
                      <label class="form-label fw-bold">Start Time</label>
                      <input type="time" name="sched_start" id="sched_start" class="form-control">
                  </div>
                  <div class="col-md-6 mb-3">
                      <label class="form-label fw-bold">End Time</label>
                      <input type="time" name="sched_end" id="sched_end" class="form-control">
                  </div>
                  <div class="col-12">
                      <label class="form-label fw-bold text-dark">Night Speed</label>
                      <div class="row g-2">
                          <div class="col-6">
                              <div class="input-group">
                                  <span class="input-group-text bg-light"><i class="fa-solid fa-upload text-danger"></i></span>
                                  <input type="text" name="sched_up" id="sched_up" class="form-control" placeholder="Upload (e.g. 20M)">
                              </div>
                          </div>
                          <div class="col-6">
                              <div class="input-group">
                                  <span class="input-group-text bg-light"><i class="fa-solid fa-download text-success"></i></span>
                                  <input type="text" name="sched_down" id="sched_down" class="form-control" placeholder="Download (e.g. 20M)">
                              </div>
                          </div>
                      </div>
                  </div>
              </div>
HTML;

$content = str_replace($old_html, $new_html, $content);

// 3. Update the Javascript Edit Population
$old_js = <<<'JS'
      document.getElementById('pkg_data').value = pkg.data_limit_gb;
      document.getElementById('pkg_rate').value = pkg.rate_limit;
      
      if (pkg.speed_scheduler) {
          let sched = JSON.parse(pkg.speed_scheduler);
          document.getElementById('sched_start').value = sched.start_time;
          document.getElementById('sched_end').value = sched.end_time;
          document.getElementById('sched_speed').value = sched.speed;
      }
JS;

$new_js = <<<'JS'
      document.getElementById('pkg_data').value = pkg.data_limit_gb;
      
      let rate = pkg.rate_limit || 'Unlimited';
      if (rate.toLowerCase() === 'unlimited' || !rate.includes('/')) {
          document.getElementById('pkg_rate_up').value = 'Unlimited';
          document.getElementById('pkg_rate_down').value = 'Unlimited';
      } else {
          let parts = rate.split('/');
          document.getElementById('pkg_rate_up').value = parts[0];
          document.getElementById('pkg_rate_down').value = parts[1];
      }
      
      if (pkg.speed_scheduler) {
          let sched = JSON.parse(pkg.speed_scheduler);
          document.getElementById('sched_start').value = sched.start_time;
          document.getElementById('sched_end').value = sched.end_time;
          if (sched.speed && sched.speed.includes('/')) {
              let sparts = sched.speed.split('/');
              document.getElementById('sched_up').value = sparts[0];
              document.getElementById('sched_down').value = sparts[1];
          }
      } else {
          document.getElementById('sched_start').value = '';
          document.getElementById('sched_end').value = '';
          document.getElementById('sched_up').value = '';
          document.getElementById('sched_down').value = '';
      }
JS;

$content = str_replace($old_js, $new_js, $content);

file_put_contents($file, $content);
echo "Updated packages.php to split upload/download inputs.\n";
?>
