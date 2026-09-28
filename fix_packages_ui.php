<?php
$file = 'C:/xampp/htdocs/SB Link Network/operator/packages.php';
$content = file_get_contents($file);

// Replace Standard Rate Limit block
$pattern1 = '/<div class="col-md-12 mb-3">\s*<label class="form-label fw-bold">Standard Rate Limit \(Speed\).*?<\/div>/s';
$replacement1 = <<<'HTML'
                  <div class="col-md-12 mb-3">
                      <label class="form-label fw-bold text-dark">Standard Speed (Rate Limit)</label>
                      <div class="row g-2">
                          <div class="col-6">
                              <label class="form-label small text-muted mb-1">Upload Speed</label>
                              <div class="input-group">
                                  <span class="input-group-text bg-light"><i class="fa-solid fa-upload text-danger"></i></span>
                                  <input type="text" name="rate_up" id="pkg_rate_up" class="form-control" placeholder="e.g. 10M, 512k" required>
                              </div>
                          </div>
                          <div class="col-6">
                              <label class="form-label small text-muted mb-1">Download Speed</label>
                              <div class="input-group">
                                  <span class="input-group-text bg-light"><i class="fa-solid fa-download text-success"></i></span>
                                  <input type="text" name="rate_down" id="pkg_rate_down" class="form-control" placeholder="e.g. 10M, 512k" required>
                              </div>
                          </div>
                      </div>
                      <small class="text-muted d-block mt-2">Type <strong>Unlimited</strong> in any field to remove speed caps entirely.</small>
                  </div>
HTML;
$content = preg_replace($pattern1, $replacement1, $content);

// Replace Scheduled Speed block
$pattern2 = '/<div class="row">\s*<div class="col-md-4 mb-3">\s*<label class="form-label fw-bold">Start Time.*?<input type="text" name="sched_speed" id="sched_speed" class="form-control" placeholder="e.g. 20M\/20M">\s*<\/div>\s*<\/div>/s';
$replacement2 = <<<'HTML'
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
$content = preg_replace($pattern2, $replacement2, $content);

// Replace JS part
$pattern3 = '/document\.getElementById\(\'pkg_rate\'\)\.value = pkg\.rate_limit;\s*if \(pkg\.speed_scheduler\) \{.*?\}/s';
$replacement3 = <<<'JS'
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
$content = preg_replace($pattern3, $replacement3, $content);

file_put_contents($file, $content);
echo "packages.php fully updated.\n";
?>
