<?php
// Local demonstration only. No intranet credentials or external API calls.
return [
    'staff_support_enabled'=>true,
    'staff_support_mail_mode'=>'capture',
    'staff_support_base_url'=>'http://127.0.0.1:8080/index.html',
    'staff_support_files_dir'=>__DIR__.'/var/files',
    'staff_support_public_dir'=>__DIR__.'/public',
    'db_path'=>__DIR__.'/var/support.sqlite',
];
