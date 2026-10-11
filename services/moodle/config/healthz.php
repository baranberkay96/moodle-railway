<?php
// Railway healthcheck endpoint (Apache + PHP). No database or Moodle bootstrap involved.
header('Content-Type: text/plain');
header('Cache-Control: no-store');
echo "ok\n";
