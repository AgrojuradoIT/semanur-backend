<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
// Test mínimo - no requiere vendor
echo 'Direct PHP works. ';
echo 'Current dir: '.__DIR__;
echo '<br>File: '.__FILE__;
