<?php
// No vendor - puro test de .htaccess
echo "Rewrite works if you see this!";
echo "<br>URI: " . ($_SERVER['REQUEST_URI'] ?? 'none');
echo "<br>Script: " . ($_SERVER['SCRIPT_NAME'] ?? 'none');
