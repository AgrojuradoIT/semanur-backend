<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
echo 'PATH_INFO: '.($_SERVER['PATH_INFO'] ?? 'NO DISPONIBLE');
echo '<br>REQUEST_URI: '.($_SERVER['REQUEST_URI'] ?? 'none');
