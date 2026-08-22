<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
// No vendor - puro test de .htaccess
echo 'Rewrite works if you see this!';
echo '<br>URI: '.($_SERVER['REQUEST_URI'] ?? 'none');
echo '<br>Script: '.($_SERVER['SCRIPT_NAME'] ?? 'none');
