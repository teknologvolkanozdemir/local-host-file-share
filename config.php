<?php
declare(strict_types=1);

return [
    'db_host' => getenv('LHS_DB_HOST') ?: '127.0.0.1',
    'db_name' => getenv('LHS_DB_NAME') ?: 'local_host_file_share',
    'db_user' => getenv('LHS_DB_USER') ?: 'root',
    'db_password' => getenv('LHS_DB_PASSWORD') ?: '',
    'storage_path' => getenv('LHS_STORAGE_PATH') ?: dirname(__DIR__) . '/local-host-file-share-data',
    'max_upload_bytes' => 100 * 1024 * 1024,
    'max_edit_bytes' => 2 * 1024 * 1024,
];
