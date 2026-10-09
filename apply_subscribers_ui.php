<?php
$f = 'operator/subscribers.php';
$c = file_get_contents($f);

$premiumCss = <<<CSS

    /* Premium Table-Card Layout */
    #usersTable { border-collapse: separate !important; border-spacing: 0 10px !important; margin-top: -10px !important; }
    #usersTable thead th { 
        border: none !important; 
        background: transparent !important; 
        color: #64748b; 
        font-size: 0.75rem; 
        text-transform: uppercase; 
        letter-spacing: 1px; 
        padding-bottom: 0px !important; 
    }
    #usersTable tbody tr { 
        background: #ffffff !important; 
        box-shadow: 0 2px 8px rgba(0,0,0,0.03) !important; 
        border-radius: 12px !important; 
        transition: transform 0.2s, box-shadow 0.2s !important; 
    }
    #usersTable tbody tr:hover { 
        transform: translateY(-2px) !important; 
        box-shadow: 0 5px 15px rgba(0,0,0,0.08) !important; 
        z-index: 10;
        position: relative;
    }
    #usersTable tbody td { 
        border-top: 1px solid #f8fafc !important; 
        border-bottom: 1px solid #f8fafc !important; 
        border-right: none !important;
        border-left: none !important;
        vertical-align: middle !important; 
        padding: 12px 15px !important; 
        background: transparent !important;
    }
    #usersTable tbody td:first-child { 
        border-left: 1px solid #f8fafc !important; 
        border-top-left-radius: 12px !important; 
        border-bottom-left-radius: 12px !important; 
    }
    #usersTable tbody td:last-child { 
        border-right: 1px solid #f8fafc !important; 
        border-top-right-radius: 12px !important; 
        border-bottom-right-radius: 12px !important; 
    }
    .table-custom-ui { background: transparent !important; box-shadow: none !important; padding: 0 !important; }
    
    /* Make Avatar look Premium */
    .avatar-circle { 
        width: 45px !important; 
        height: 45px !important; 
        border-radius: 10px !important; 
        background: #e0f2fe !important; 
        color: #0284c7 !important; 
        display: flex !important; 
        align-items: center !important; 
        justify-content: center !important; 
        font-size: 1.2rem !important; 
    }
    
    /* Badges */
    .badge-soft-success { background: #dcfce7 !important; color: #166534 !important; }
    .badge-soft-warning { background: #fef3c7 !important; color: #92400e !important; }
    .badge-soft-primary { background: #dbeafe !important; color: #1e40af !important; }
    .badge-soft-secondary { background: #f1f5f9 !important; color: #475569 !important; }
    .badge-soft-danger { background: #fee2e2 !important; color: #991b1b !important; }
CSS;

$c = str_replace('<style>', '<style>' . $premiumCss, $c);

file_put_contents($f, $c);
echo "Premium styles applied.\n";
?>
