<?php
$file = 'C:/xampp/htdocs/SB Link Network/superadmin/operator_edit.php';
$content = file_get_contents($file);

$old_html = <<<'HTML'
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary fw-bold shadow-sm px-4">
                            <i class="fa-solid fa-save me-2"></i> <?= $nas ? 'Update Router Settings' : 'Link Router' ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
HTML;

$new_html = <<<'HTML'
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary fw-bold shadow-sm px-4">
                            <i class="fa-solid fa-save me-2"></i> <?= $nas ? 'Update Router Settings' : 'Link Router' ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <?php if($nas): ?>
        <div class="card border-0 shadow-sm rounded-4 border-dark border-opacity-25 mt-4">
            <div class="card-header bg-dark text-white border-bottom-0 pt-3 pb-3 rounded-top-4">
                <h6 class="fw-bold mb-0"><i class="fa-solid fa-terminal me-2"></i> MikroTik Quick Setup Script</h6>
            </div>
            <div class="card-body p-4 bg-light rounded-bottom-4">
                <p class="small text-muted mb-3">Copy and paste the following script into the Operator's MikroTik <strong>New Terminal</strong>. It will automatically configure the router to connect to this RADIUS server and enable AAA for Hotspot and PPPoE.</p>
                <div class="position-relative">
                    <?php 
                        // The RADIUS server IP is typically the database host (Ubuntu VM)
                        global $host; 
                        $radius_ip = $host; 
                        $secret = $nas['secret'];
                        
                        $mt_script = "/radius add address=$radius_ip secret=\"$secret\" service=ppp,hotspot\n";
                        $mt_script .= "/radius incoming set accept=yes port=3799\n";
                        $mt_script .= "/ppp aaa set use-radius=yes interim-update=1m\n";
                        $mt_script .= "/ip hotspot profile set [find] use-radius=yes radius-interim-update=1m\n";
                    ?>
                    <textarea id="mtScript" class="form-control font-monospace bg-dark text-success" rows="5" readonly style="font-size: 13px; resize: none;"><?= htmlspecialchars($mt_script) ?></textarea>
                    <button type="button" class="btn btn-sm btn-light position-absolute top-0 end-0 m-2 shadow-sm fw-bold" onclick="copyScript()">
                        <i class="fa-regular fa-copy me-1"></i> Copy
                    </button>
                </div>
            </div>
        </div>
        <script>
        function copyScript() {
            var copyText = document.getElementById("mtScript");
            copyText.select();
            copyText.setSelectionRange(0, 99999); // For mobile devices
            navigator.clipboard.writeText(copyText.value);
            alert("Script copied to clipboard!");
        }
        </script>
        <?php endif; ?>
    </div>
</div>
HTML;

$content = str_replace($old_html, $new_html, $content);
file_put_contents($file, $content);
echo "Added MikroTik setup script box to operator_edit.php.\n";
?>
