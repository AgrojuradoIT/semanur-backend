<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
echo 'PHP OK - '.date('Y-m-d H:i:s');
