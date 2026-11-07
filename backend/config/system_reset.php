<?php

return [
    'backup_root' => env('CSMS_RESET_BACKUP_DIRECTORY',
        storage_path('app/private/backups/system-resets')),
];
