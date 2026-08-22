<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
phpinfo(INFO_GENERAL | INFO_CONFIGURATION | INFO_MODULES);
