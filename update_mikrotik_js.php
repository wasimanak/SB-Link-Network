<?php
$file = 'C:/xampp/htdocs/SB Link Network/operator/mikrotik_sync.php';
$content = file_get_contents($file);

$old_js = <<<'HTML'
<script>
function showSimulatedAction(title, msg) {
    $('#simModalTitle').text(title);
    $('#simModalBody').text(msg);
    var simModal = new bootstrap.Modal(document.getElementById('simModal'));
    simModal.show();
}

function testConnection(id) {
    showSimulatedAction('Testing Connection...', 'Connection successful! RouterOS v7.11. Uptime: 45 days, 2 hours.');
}

function syncUsers(id) {
    showSimulatedAction('Syncing Users...', 'Successfully pulled 150 PPPoE users and 50 Hotspot users from MikroTik. 0 errors.');
}

function syncProfiles(id) {
    showSimulatedAction('Syncing Profiles...', 'All active packages have been successfully pushed to MikroTik PPPoE Profiles.');
}

function disconnectAll(id) {
    showSimulatedAction('Disconnecting Users...', 'Successfully sent disconnect packets to 0 expired active sessions.');
}
</script>
HTML;

$new_js = <<<'HTML'
<script>
function showResult(title, msg, isError = false) {
    $('#simModalTitle').text(title);
    let html = `<div class="alert ${isError ? 'alert-danger' : 'alert-success'}">${msg}</div>`;
    $('#simModalBody').html(html);
    var simModal = new bootstrap.Modal(document.getElementById('simModal'));
    simModal.show();
}

function callApi(action, id, title) {
    $('#simModalTitle').text(title + ' (Please Wait...)');
    $('#simModalBody').html('<div class="text-center py-3"><div class="spinner-border text-primary" role="status"></div><p class="mt-2 text-muted">Communicating with MikroTik...</p></div>');
    var simModal = new bootstrap.Modal(document.getElementById('simModal'));
    simModal.show();
    
    $.post('api_mikrotik_sync.php', { action: action, nas_id: id }, function(res) {
        if(res.status === 'success') {
            $('#simModalBody').html(`<div class="alert alert-success"><i class="fa-solid fa-check-circle me-2"></i> ${res.message}</div>`);
        } else {
            $('#simModalBody').html(`<div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation me-2"></i> ${res.message}</div>`);
        }
    }).fail(function() {
        $('#simModalBody').html(`<div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation me-2"></i> Server Error: Could not connect to API.</div>`);
    });
}

function testConnection(id) {
    callApi('test', id, 'Testing Connection');
}

function syncProfiles(id) {
    callApi('sync_profiles', id, 'Importing Profiles from MikroTik');
}

function syncUsers(id) {
    callApi('sync_users', id, 'Importing Users from MikroTik');
}

function disconnectAll(id) {
    $('#simModalTitle').text('Disconnecting Users...');
    $('#simModalBody').text('This feature is currently under development (Requires Disconnect-Request CoA).');
    var simModal = new bootstrap.Modal(document.getElementById('simModal'));
    simModal.show();
}
</script>
HTML;

$content = str_replace($old_js, $new_js, $content);
file_put_contents($file, $content);
echo "Frontend updated to use live API calls.\n";
?>
