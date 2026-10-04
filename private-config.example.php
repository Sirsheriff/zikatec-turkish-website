<?php

return [
    // This file is copied outside public_html only on the first deployment.
    // Change the username and either password or password_hash in
    // /home3/zikatecn/zikatec-private/config.php. Never copy that file into Git.
    'username' => 'CHANGE_ME',
    'password' => 'CHANGE_ME_NOW',
    // Recommended: use password_hash('your-password', PASSWORD_DEFAULT), then
    // clear the plain password value above.
    'password_hash' => '',
    // Sales dashboard Odoo JSON-2 connector. Keep all real values only in the
    // private config at /home3/zikatecn/zikatec-private/config.php.
    'odoo_base_url' => 'https://erp.example.com',
    'odoo_database' => 'CHANGE_ME',
    'odoo_api_key' => 'CHANGE_ME',
    'odoo_device_product_ids' => [],
    'odoo_device_category_ids' => [],
];
