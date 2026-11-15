<?php
/*
 * ca-install – install Community Applications from the command line
 * Copyright (C) 2026 tommibembel
 * SPDX-License-Identifier: GPL-2.0-only
 *
 * Helper process: runs an ORIGINAL WebUI action script (e.g. CA exec.php or dockerMan
 * UpdateConfig.php) exactly as if the WebUI had sent a POST with these fields. It runs in
 * its own process because these scripts execute their switch($_POST['action']) as soon as
 * they are included and define many globals.
 *
 * Usage:  php webui-action.php <script relative to /usr/local/emhttp> key=value [key=value ...]
 * Output: the raw output of the script (for CA: the JSON from postReturn()).
 */

if (PHP_SAPI !== 'cli' || $argc < 3) {
  fwrite(STDERR, "usage: php webui-action.php <script> key=value ...\n");
  exit(2);
}

$allowed = [
  'plugins/community.applications/include/exec.php',
  'plugins/dynamix.docker.manager/include/UpdateConfig.php',
];
if (!in_array($argv[1], $allowed, true)) {
  fwrite(STDERR, "script not allowed: {$argv[1]}\n");
  exit(2);
}

$docroot = '/usr/local/emhttp';
$_SERVER['DOCUMENT_ROOT'] = $docroot;
chdir($docroot);

$_POST = [];
foreach (array_slice($argv, 2) as $kv) {
  $p = strpos($kv, '=');
  if ($p === false) { fwrite(STDERR, "bad argument: $kv\n"); exit(2); }
  $_POST[substr($kv, 0, $p)] = substr($kv, $p + 1);
}

ob_start();
require "$docroot/{$argv[1]}";
echo ob_get_clean();
