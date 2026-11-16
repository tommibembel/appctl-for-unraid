<?php
/*
 * appctl – install Docker apps on Unraid from the command line
 * Copyright (C) 2026 tommibembel
 * SPDX-License-Identifier: GPL-2.0-only
 *
 * Installs Docker apps from the Unraid Community Applications (CA) store from
 * the command line, producing the same result as "Install" -> "Apply" in the WebUI.
 *
 * Pipeline (mirrors the WebUI):
 *   1. CA "Install" click  -> ORIGINAL createXML() (community.applications/include/exec.php)
 *                             run in a sub-process via webui-action.php
 *   2. dockerMan form load -> ORIGINAL xmlToVar() + replicated PHP block of CreateDocker.php
 *                             + replicated browser JavaScript (form fill / submit), see formPost()
 *   3. "Apply"             -> ORIGINAL postToXML(), xmlToCommand(), pullImage(), stopContainer(),
 *                             removeContainer(), addRoute() (dynamix.docker.manager/include/Helpers.php)
 *
 * Everything marked "REPLICATED" is copied logic from Unraid 7.1.2 sources and
 * must be re-checked when Unraid changes. Line numbers refer to Unraid 7.1.2.
 */

const APPCTL_VERSION = '0.1.0-dev';


const APPCTL_DOCROOT    = '/usr/local/emhttp';
const APPCTL_FEED_CACHE = '/tmp/community.applications/tempFiles/templates_new.json';
const APPCTL_CA_EXEC    = APPCTL_DOCROOT.'/plugins/community.applications/include/exec.php';
const APPCTL_CA_PLG     = '/var/log/plugins/community.applications.plg';

// Exit codes (stable, for agents)
const EXIT_OK            = 0;
const EXIT_INTERNAL      = 1;
const EXIT_USAGE         = 2;
const EXIT_NOT_FOUND     = 3;
const EXIT_AMBIGUOUS     = 4;
const EXIT_MISSING_REQ   = 5;
const EXIT_CONFLICT      = 6;
const EXIT_ENVIRONMENT   = 7;
const EXIT_INSTALL_FAIL  = 8;
const EXIT_CONFIRM       = 9;
const EXIT_PORT_CONFLICT = 10;

ini_set('memory_limit', '512M');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 'stderr');

class AppctlError extends Exception {
  public string $codeName;
  public int $exitCode;
  public array $details;
  function __construct(string $codeName, string $message, int $exitCode, array $details = []) {
    parent::__construct($message);
    $this->codeName = $codeName;
    $this->exitCode = $exitCode;
    $this->details  = $details;
  }
}

$APPCTL_WARNINGS = [];
function warn(string $msg): void { global $APPCTL_WARNINGS; $APPCTL_WARNINGS[] = $msg; }

#################################################################
# CLI parsing
#################################################################

function usage(): string {
  return <<<TXT
appctl {$GLOBALS['APPCTL_VERSION_STR']} – install Community Applications from the command line

Usage:
  appctl search <term> [--limit N] [--json]
  appctl info <app> [--json]                   raw template from the CA feed
  appctl options <app> [options] [--json]      all settings adapted to this server, incl. problems
                                                   (missing values, port conflicts) -> ask the user, re-run
  appctl install <app> [options] --dry-run      show resulting template + docker command, change nothing
  appctl install <app> [options] --yes          really install
  appctl update-feed [--json]                    download / refresh the CA app feed (done automatically when missing)
  appctl version [--json]

<app> is an exact app name, an app ID from `search`, or an exact repository (e.g. ghcr.io/example/app:latest).

Options for `options` and `install` (cover everything the WebUI "Add Container" form can do,
except Tailscale):
  --name <name>              container name (default: template name)
  --network <net>            bridge | host | none | container:<name> | <custom network, e.g. br0>
  --ip <address>             fixed IP (custom networks only)
  --container KEY=VALUE      any container field: Repository, Registry, Network, MyIP, Shell (sh|bash),
                             Privileged (true|false), WebUI, Icon, ExtraParams, PostArgs, CPUset,
                             Category, Overview, Requires, Support, Project, TemplateURL,
                             DonateText, DonateLink. Repeatable.
  --set KEY=VALUE            value of a template field. KEY = field Target (e.g. PUID, 80, /config)
                             or field Name. Repeatable.
  --set KEY.PROP=VALUE       change a field property like the WebUI "Edit" button. PROP = name,
                             target, default, mode (rw|ro|rw,slave|... / tcp|udp), description,
                             display (always|always-hide|advanced|advanced-hide), required, mask
  --add TYPE:TARGET=VALUE    add a field like "Add another Path, Port, Variable, Label or Device".
                             TYPE = Path|Port|Variable|Label|Device (Device: --add Device:/dev/dri).
                             Name it afterwards with --set TARGET.name=...
  --remove KEY               remove a field. Repeatable.
  --autostart                enable autostart after install (Docker page toggle)
  --autostart-wait <sec>     autostart wait period
  --replace                  allow replacing an existing container/template with the same name
  --json                     machine-readable output

Order of field operations: --remove, then --add, then --set.

Documentation: https://github.com/tommibembel/appctl-for-unraid
  docs/USAGE.md (all options, JSON, error codes) – AGENTS.md (rules for AI agents)

Exit codes: 0 ok, 2 usage, 3 not found, 4 ambiguous, 5 required field missing,
            6 name conflict, 7 environment/version problem, 8 install failed,
            9 confirmation required (use --dry-run or --yes), 10 host port in use,
            1 internal error

TXT;
}
$GLOBALS['APPCTL_VERSION_STR'] = APPCTL_VERSION;

function parseArgs(array $argv): array {
  $o = ['cmd' => null, 'args' => [], 'json' => false, 'limit' => 20, 'name' => null, 'set' => [],
        'container' => [], 'add' => [], 'remove' => [], 'network' => null, 'ip' => null,
        'replace' => false, 'dry' => false, 'yes' => false,
        'autostart' => false, 'autostartWait' => null];
  $valueOpts = ['--limit' => 'limit', '--name' => 'name', '--network' => 'network', '--ip' => 'ip',
                '--autostart-wait' => 'autostartWait'];
  $kvOpts = ['--set' => 'set', '--container' => 'container'];
  $listOpts = ['--add' => 'add', '--remove' => 'remove'];
  for ($i = 1; $i < count($argv); $i++) {
    $a = $argv[$i];
    if (preg_match('/^(--[a-z-]+)=(.*)$/s', $a, $m) && (isset($valueOpts[$m[1]]) || isset($kvOpts[$m[1]]) || isset($listOpts[$m[1]]))) {
      $a = $m[1]; array_splice($argv, $i + 1, 0, [$m[2]]);
    }
    switch ($a) {
      case '--json': $o['json'] = true; break;
      case '--dry-run': $o['dry'] = true; break;
      case '--yes': case '-y': $o['yes'] = true; break;
      case '--replace': $o['replace'] = true; break;
      case '--autostart': $o['autostart'] = true; break;
      case '--help': case '-h': $o['cmd'] = 'help'; break;
      default:
        if (isset($kvOpts[$a])) {
          if (!isset($argv[$i + 1])) throw new AppctlError('USAGE', "$a needs KEY=VALUE", EXIT_USAGE);
          $kv = $argv[++$i];
          $p = strpos($kv, '=');
          if ($p === false || $p === 0) throw new AppctlError('USAGE', "$a expects KEY=VALUE, got '$kv'", EXIT_USAGE);
          $o[$kvOpts[$a]][] = [substr($kv, 0, $p), substr($kv, $p + 1)];
        } elseif (isset($listOpts[$a])) {
          if (!isset($argv[$i + 1])) throw new AppctlError('USAGE', "$a needs a value", EXIT_USAGE);
          $o[$listOpts[$a]][] = $argv[++$i];
        } elseif (isset($valueOpts[$a])) {
          if (!isset($argv[$i + 1])) throw new AppctlError('USAGE', "$a needs a value", EXIT_USAGE);
          $o[$valueOpts[$a]] = $argv[++$i];
        } elseif (str_starts_with($a, '--')) {
          throw new AppctlError('USAGE', "Unknown option $a", EXIT_USAGE);
        } elseif ($o['cmd'] === null) {
          $o['cmd'] = $a;
        } else {
          $o['args'][] = $a;
        }
    }
  }
  $o['limit'] = max(1, (int)$o['limit']);
  // Aliases: --container Name/Network/MyIP == --name/--network/--ip
  foreach ($o['container'] as $k => [$key, $val]) {
    $alias = ['name' => 'name', 'network' => 'network', 'myip' => 'ip'][strtolower($key)] ?? null;
    if ($alias) { $o[$alias] = $val; unset($o['container'][$k]); }
  }
  if ($o['autostartWait'] !== null) {
    if (!ctype_digit($o['autostartWait'])) throw new AppctlError('USAGE', '--autostart-wait expects seconds', EXIT_USAGE);
    $o['autostart'] = true;
  }
  return $o;
}

#################################################################
# Environment
#################################################################

function unraidVersion(): string {
  $v = @parse_ini_file('/etc/unraid-version');
  return $v['version'] ?? 'unknown';
}

function caVersion(): string {
  $plg = @file_get_contents(APPCTL_CA_PLG);
  if ($plg && preg_match('/<!ENTITY\s+version\s+"([^"]+)"/', $plg, $m)) return $m[1];
  return 'unknown';
}

function checkEnvironment(): void {
  if (!is_file(APPCTL_CA_EXEC)) {
    throw new AppctlError('CA_NOT_INSTALLED', 'Community Applications plugin is not installed', EXIT_ENVIRONMENT);
  }
}

#################################################################
# Feed (read-only)
#################################################################

/** Same reading logic as CA readJsonFile() (helpers.php ~line 130): serialized PHP, fallback JSON. */
/**
 * CA writes its debug log to /tmp/CA_logs; the Apps page creates that folder (Apps.page line 37).
 * Without it CA's debug() recurses until PHP runs out of memory – after every reboot, before the
 * Apps tab was opened. So create it before calling any CA code.
 */
function prepareCA(): void {
  @mkdir('/tmp/CA_logs', 0755, true);
}

/**
 * Download / refresh the CA app feed with CA's ORIGINAL force_update() – the same action the Apps
 * tab runs when it is opened (exec.php force_update -> DownloadApplicationFeed()). The feed lives in
 * RAM (/tmp), so it is gone after every reboot.
 */
function updateFeed(): array {
  prepareCA();
  $out = webuiAction('plugins/community.applications/include/exec.php', ['action' => 'force_update']);
  $res = null;
  for ($p = strpos($out, '{'); $p !== false; $p = strpos($out, '{', $p + 1)) {
    $res = json_decode(substr($out, $p), true);
    if (is_array($res)) break;
  }
  if (!is_file(APPCTL_FEED_CACHE)) {
    $why = trim(preg_replace('/\s+/', ' ', strip_tags(str_replace('<br>', ' ', $res['data'] ?? ''))));
    throw new AppctlError('FEED_DOWNLOAD_FAILED', 'Downloading the CA app feed failed'.($why ? ": $why" : ' (no internet access / DNS?)'),
      EXIT_ENVIRONMENT);
  }
  return ['feed' => APPCTL_FEED_CACHE, 'updated' => date('c', filemtime(APPCTL_FEED_CACHE))];
}

function loadFeed(): array {
  if (!is_file(APPCTL_FEED_CACHE)) {
    updateFeed();   // e.g. after a reboot – no need to open the Apps tab
    warn('CA app feed was not loaded (e.g. after a reboot) and has been downloaded');
  }
  $raw = file_get_contents(APPCTL_FEED_CACHE);
  $feed = @unserialize($raw, ['allowed_classes' => false]);
  if ($feed === false) $feed = json_decode($raw, true);
  if (!is_array($feed) || !$feed) {
    throw new AppctlError('FEED_INVALID', 'CA app feed cache could not be read', EXIT_ENVIRONMENT);
  }
  // Only Docker apps; skip plugins, language packs and blacklisted entries.
  return array_values(array_filter($feed, fn($t) =>
    is_array($t) && empty($t['Plugin']) && empty($t['Language']) && empty($t['Blacklist']) && !empty($t['Repository'])));
}

function configList(array $t): array {
  $c = $t['Config'] ?? [];
  if (!is_array($c) || !$c) return [];
  if (isset($c['@attributes'])) $c = [$c];  // single entry
  return array_values(array_filter($c, 'is_array'));
}

function summary(array $t): array {
  return [
    'id'         => $t['ID'] ?? null,
    'name'       => $t['Name'] ?? '',
    'author'     => $t['Author'] ?? ($t['RepoName'] ?? ''),
    'repository' => $t['Repository'] ?? '',
    'repo'       => $t['RepoName'] ?? '',
    'deprecated' => !empty($t['Deprecated']),
    'compatible' => !isset($t['Compatible']) || !empty($t['Compatible']),
    'overview'   => shortText($t['Overview'] ?? ($t['Description'] ?? ''), 140),
  ];
}

function shortText(string $s, int $max): string {
  $s = trim(preg_replace('/\s+/', ' ', strip_tags(str_replace(['[', ']'], ['<', '>'], $s))));
  return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1).'…' : $s;
}

function cmdSearch(array $o): array {
  if (!$o['args']) throw new AppctlError('USAGE', 'search needs a search term', EXIT_USAGE);
  $term = mb_strtolower(implode(' ', $o['args']));
  $hits = [];
  foreach (loadFeed() as $t) {
    $name = mb_strtolower($t['Name'] ?? '');
    $hay = mb_strtolower(implode(' ', [$t['Name'] ?? '', $t['Author'] ?? '', $t['RepoName'] ?? '',
      $t['Repository'] ?? '', $t['ExtraSearchTerms'] ?? '', $t['Overview'] ?? '']));
    if (!str_contains($hay, $term)) continue;
    $score = $name === $term ? 0 : (str_starts_with($name, $term) ? 1 : (str_contains($name, $term) ? 2 : 3));
    if (!empty($t['Deprecated'])) $score += 4;
    $hits[] = [$score, $name, summary($t)];
  }
  usort($hits, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
  $total = count($hits);
  return ['total' => $total, 'results' => array_map(fn($h) => $h[2], array_slice($hits, 0, $o['limit']))];
}

/** Find exactly one template. Never guesses: several matches -> AMBIGUOUS. */
function resolveApp(array $feed, string $app): array {
  $byId = array_values(array_filter($feed, fn($t) => isset($t['ID']) && (string)$t['ID'] === $app));
  if (count($byId) === 1) return $byId[0];
  $lc = mb_strtolower($app);
  $byRepo = array_values(array_filter($feed, fn($t) => mb_strtolower($t['Repository'] ?? '') === $lc));
  if (count($byRepo) === 1) return $byRepo[0];
  $byName = array_values(array_filter($feed, fn($t) => mb_strtolower($t['Name'] ?? '') === $lc));
  if (count($byName) === 1) return $byName[0];
  $cands = $byRepo ?: $byName;
  if (count($cands) > 1) {
    throw new AppctlError('AMBIGUOUS', "'$app' matches ".count($cands)." templates; use the ID or the exact repository",
      EXIT_AMBIGUOUS, ['candidates' => array_map('summary', $cands)]);
  }
  throw new AppctlError('NOT_FOUND', "No app named '$app' found (try: appctl search $app)", EXIT_NOT_FOUND);
}

function cmdInfo(array $o): array {
  if (count($o['args']) !== 1) throw new AppctlError('USAGE', 'info needs exactly one app', EXIT_USAGE);
  $t = resolveApp(loadFeed(), $o['args'][0]);
  $configs = [];
  foreach (configList($t) as $c) {
    $a = $c['@attributes'] ?? [];
    $configs[] = [
      'type'        => $a['Type'] ?? '',
      'name'        => $a['Name'] ?? '',
      'target'      => $a['Target'] ?? '',
      'default'     => $a['Default'] ?? '',
      'value'       => is_string($c['value'] ?? null) ? $c['value'] : '',
      'mode'        => $a['Mode'] ?? '',
      'display'     => $a['Display'] ?? '',
      'required'    => ($a['Required'] ?? '') === 'true',
      'mask'        => ($a['Mask'] ?? '') === 'true',
      'description' => shortText($a['Description'] ?? '', 300),
    ];
  }
  return summary($t) + [
    'overview'     => shortText($t['Overview'] ?? '', 2000),
    'network'      => $t['Network'] ?? ($t['Networking']['Mode'] ?? ''),
    'webui'        => $t['WebUI'] ?? '',
    'template_url' => $t['TemplateURL'] ?? '',
    'support'      => $t['Support'] ?? '',
    'project'      => $t['Project'] ?? '',
    'requires'     => shortText($t['Requires'] ?? '', 1000),
    'config'       => $configs,
    'note'         => 'Values are the raw template defaults; install adapts paths/network to this server (see install --dry-run).',
  ];
}

#################################################################
# Install
#################################################################

/** Load dockerMan exactly like CreateDocker.php does (lines 14-25). */
function loadDockerMan(): void {
  global $docroot, $var, $subnet, $custom, $driver, $dockercfg, $dockerManPaths, $DockerClient, $DockerUpdate, $DockerTemplates;
  $docroot = APPCTL_DOCROOT;
  $_SERVER['DOCUMENT_ROOT'] = $docroot;
  chdir($docroot);
  require_once "$docroot/plugins/dynamix.docker.manager/include/DockerClient.php";
  require_once "$docroot/webGui/include/Helpers.php";
  $var = parse_ini_file('state/var.ini');
  $DockerClient    = new DockerClient();
  $DockerUpdate    = new DockerUpdate();
  $DockerTemplates = new DockerTemplates();
  $custom = DockerUtil::custom();
  $subnet = DockerUtil::network($custom);
  foreach (['postToXML', 'xmlToVar', 'xmlToCommand', 'pullImage', 'stopContainer', 'removeContainer', 'addRoute'] as $fn) {
    if (!function_exists($fn)) {
      throw new AppctlError('DOCKERMAN_CHANGED', "dockerMan function $fn() not found – this Unraid version is not compatible", EXIT_ENVIRONMENT);
    }
  }
  if (!is_file('/var/run/dockerd.pid')) {
    throw new AppctlError('DOCKER_NOT_RUNNING', 'Docker service is not running', EXIT_ENVIRONMENT);
  }
}

/** Run an ORIGINAL WebUI action script in a sub-process (see webui-action.php). Returns its output. */
function webuiAction(string $script, array $post): string {
  $cmd = escapeshellarg(PHP_BINARY).' -d memory_limit=512M '.escapeshellarg(__DIR__.'/webui-action.php').' '.escapeshellarg($script);
  foreach ($post as $k => $v) $cmd .= ' '.escapeshellarg("$k=$v");
  return shell_exec("$cmd 2>/dev/null") ?? '';
}

/** Step 1: original CA createXML() in a sub-process. Returns path of the adapted template XML. */
function runCreateXML(array $t): string {
  prepareCA();
  $path = $t['Path'] ?? '';
  if (!$path || str_starts_with($path, '/boot/')) {
    throw new AppctlError('TEMPLATE_PATH', 'Template has no usable feed path', EXIT_INTERNAL);
  }
  // Same POST as Apps.page sends on "Install" (getPost() urldecodes the value).
  $out = webuiAction('plugins/community.applications/include/exec.php',
                     ['action' => 'createXML', 'xml' => urlencode($path), 'type' => 'default']);
  $res = null;
  for ($p = strpos($out, '{'); $p !== false; $p = strpos($out, '{', $p + 1)) {
    $res = json_decode(substr($out, $p), true);
    if (is_array($res)) break;
  }
  if (!is_array($res) || ($res['status'] ?? '') !== 'ok') {
    throw new AppctlError('CREATEXML_FAILED', 'CA createXML failed: '.($res['error'] ?? trim(substr($out, 0, 500))), EXIT_INSTALL_FAIL);
  }
  if (!is_file($path)) {
    throw new AppctlError('CREATEXML_FAILED', "CA createXML did not write $path", EXIT_INSTALL_FAIL);
  }
  return $path;
}

/**
 * REPLICATED: CreateDocker.php lines 259-310 ("LOAD TEMPLATE", xmlType=default).
 * Deviation: uses the final container name for the /config appdata path. The WebUI uses the
 * template name even if the user renames the container in the form; that would make a second
 * instance share appdata with the first one.
 */
function loadTemplateLikeDockerMan(string $xmlPath, string $finalName): array {
  global $dockercfg;
  $xml = xmlToVar($xmlPath);                               // ORIGINAL
  $xml['Name'] = $finalName;
  if (!empty($dockercfg['DOCKER_APP_CONFIG_PATH']) && file_exists($dockercfg['DOCKER_APP_CONFIG_PATH'])) {
    foreach ($xml['Config'] as &$arrConfig) {
      if ($arrConfig['Type'] == 'Path' && strtolower($arrConfig['Target']) == '/config') {
        $arrConfig['Default'] = $arrConfig['Value'] = realpath($dockercfg['DOCKER_APP_CONFIG_PATH']).'/'.$xml['Name'];
        if (empty($arrConfig['Display']) || preg_match("/^Host Path\s\d/", $arrConfig['Name'])) {
          $arrConfig['Display'] = 'advanced-hide';
        }
        if (empty($arrConfig['Name']) || preg_match("/^Host Path\s\d/", $arrConfig['Name'])) {
          $arrConfig['Name'] = 'AppData Config Path';
        }
      }
      $arrConfig['Name'] = strip_tags(_var($arrConfig, 'Name'));
      $arrConfig['Description'] = strip_tags(_var($arrConfig, 'Description'));
      $arrConfig['Requires'] = strip_tags(_var($arrConfig, 'Requires'));
    }
    unset($arrConfig);
  }
  if (!empty($dockercfg['DOCKER_APP_UNRAID_PATH']) && file_exists($dockercfg['DOCKER_APP_UNRAID_PATH'])) {
    $boolFound = false;
    foreach ($xml['Config'] as &$arrConfig) {
      if ($arrConfig['Type'] == 'Path' && strtolower($arrConfig['Target']) == '/unraid') {
        $arrConfig['Default'] = $arrConfig['Value'] = realpath($dockercfg['DOCKER_APP_UNRAID_PATH']);
        $arrConfig['Display'] = 'hidden';
        $arrConfig['Name'] = 'Unraid Share Path';
        $boolFound = true;
      }
    }
    unset($arrConfig);
    if (!$boolFound) {
      $xml['Config'][] = ['Name' => 'Unraid Share Path', 'Target' => '/unraid',
        'Default' => realpath($dockercfg['DOCKER_APP_UNRAID_PATH']), 'Value' => realpath($dockercfg['DOCKER_APP_UNRAID_PATH']),
        'Mode' => 'rw', 'Description' => '', 'Type' => 'Path', 'Display' => 'hidden', 'Required' => 'false', 'Mask' => 'false'];
    }
  }
  $xml['Overview'] = str_replace(['[', ']'], ['<', '>'], $xml['Overview']);
  $xml['Description'] = $xml['Overview'] = strip_tags(str_replace("<br>", "\n", $xml['Overview']));
  if (($xml['TailscaleEnabled'] ?? '') === 'true') {
    throw new AppctlError('UNSUPPORTED', 'Templates with Tailscale enabled are not supported yet', EXIT_INSTALL_FAIL);
  }
  return $xml;
}

/**
 * Which template fields does a --set KEY address? Tried in order, first unique hit wins:
 * exact Target, Target ignoring case, Name ignoring case. Returns the matching indexes.
 */
function findField(array $configs, string $key): array {
  $tests = [
    fn($c) => (string)($c['Target'] ?? '') === $key,
    fn($c) => strcasecmp((string)($c['Target'] ?? ''), $key) === 0,
    fn($c) => strcasecmp((string)($c['Name'] ?? ''), $key) === 0,
  ];
  $first = [];
  foreach ($tests as $t) {
    $idx = array_keys(array_filter($configs, $t));
    if (count($idx) === 1) return $idx;
    if (!$first && $idx) $first = $idx;
  }
  return $first;
}

/** The KEY an agent should use with --set for config $i (Target if unique, else Name), or null. */
function setKeyFor(array $configs, int $i): ?string {
  foreach ([$configs[$i]['Target'] ?? '', $configs[$i]['Name'] ?? ''] as $k) {
    if ((string)$k !== '' && findField($configs, (string)$k) === [$i]) return (string)$k;
  }
  return null;
}

/** Container fields settable with --container (form fields cont<KEY>). Name/Network/MyIP via --name/--network/--ip. */
const APPCTL_CONTAINER_FIELDS = ['Repository', 'Registry', 'Shell', 'Privileged', 'WebUI', 'Icon', 'ExtraParams', 'PostArgs',
  'CPUset', 'Category', 'Overview', 'Requires', 'Support', 'Project', 'TemplateURL', 'DonateText', 'DonateLink'];

/** Field properties editable with --set KEY.PROP=VALUE (WebUI "Edit" dialog), with allowed values. */
function fieldPropRules(string $type): array {
  return [
    'name' => null, 'target' => null, 'default' => null, 'description' => null,
    'mode' => $type === 'Path' ? ['rw', 'rw,slave', 'rw,shared', 'ro', 'ro,slave', 'ro,shared'] : ($type === 'Port' ? ['tcp', 'udp'] : ['']),
    'display' => ['always', 'always-hide', 'advanced', 'advanced-hide'],
    'required' => ['true', 'false'], 'mask' => ['true', 'false'],
  ];
}

function fieldList(array $configs): array {
  return array_map(fn($c) => ['name' => $c['Name'] ?? '', 'target' => $c['Target'] ?? '', 'type' => $c['Type'] ?? ''], array_values($configs));
}

/** Resolve exactly one field for KEY or fail. */
function oneField(array $configs, string $key, string $opt): int {
  $idx = findField($configs, $key);
  if (count($idx) > 1) {
    throw new AppctlError('AMBIGUOUS_FIELD', "$opt $key matches several template fields", EXIT_AMBIGUOUS,
      ['fields' => fieldList(array_intersect_key($configs, array_flip($idx)))]);
  }
  if (!$idx) {
    throw new AppctlError('UNKNOWN_FIELD', "$opt $key: no template field with this Target or Name", EXIT_USAGE, ['fields' => fieldList($configs)]);
  }
  return $idx[0];
}

/**
 * Apply user input to the loaded template = what a user does in the "Add Container" form:
 * container fields, --remove ("Remove"), --add ("Add another ..."), --set value / --set KEY.prop ("Edit").
 */
function applyUserInput(array &$xml, array $o): void {
  global $subnet, $DockerClient;
  // Network
  if ($o['network'] !== null) {
    if (preg_match('/^container:(.+)$/', $o['network'], $m)) {
      // <select id="netCONT"> lists the existing containers except this one (CreateDocker.php lines 1114-1122)
      $exists = false;
      foreach ($DockerClient->getDockerContainers() as $ct) if ($ct['Name'] === $m[1] && $ct['Name'] !== $xml['Name']) $exists = true;
      if (!$exists) throw new AppctlError('INVALID_NETWORK', "container:{$m[1]} – no such (other) container", EXIT_USAGE);
    } elseif (!array_key_exists($o['network'], $subnet)) {
      throw new AppctlError('INVALID_NETWORK', "Unknown network '{$o['network']}'", EXIT_USAGE,
        ['available' => array_merge(array_keys($subnet), ['container:<name>'])]);
    }
    $xml['Network'] = $o['network'];
  }
  if ($o['ip'] !== null) {
    if (in_array($xml['Network'], ['bridge', 'host', 'none'], true) || str_starts_with($xml['Network'], 'container:')) {
      throw new AppctlError('USAGE', "--ip only works with a custom network (current: {$xml['Network']})", EXIT_USAGE);
    }
    $xml['MyIP'] = $o['ip'];
  }
  // Container fields
  foreach ($o['container'] as [$key, $value]) {
    $field = null;
    foreach (APPCTL_CONTAINER_FIELDS as $f) if (strcasecmp($f, $key) === 0) $field = $f;
    if ($field === null) {
      throw new AppctlError('UNKNOWN_FIELD', "--container $key: unknown container field", EXIT_USAGE,
        ['available' => array_merge(APPCTL_CONTAINER_FIELDS, ['Name', 'Network', 'MyIP'])]);
    }
    if ($field === 'Shell' && !in_array($value, ['sh', 'bash'], true)) throw new AppctlError('INVALID_VALUE', '--container Shell: sh or bash', EXIT_USAGE);
    if ($field === 'Privileged') {
      if (!in_array(strtolower($value), ['true', 'false'], true)) throw new AppctlError('INVALID_VALUE', '--container Privileged: true or false', EXIT_USAGE);
      $value = strtolower($value);
    }
    if ($field === 'CPUset' && $value !== '' && !preg_match('/^\d+(,\d+)*$/', $value)) {
      throw new AppctlError('INVALID_VALUE', '--container CPUset: comma separated CPU numbers, e.g. 2,3', EXIT_USAGE);
    }
    $xml[$field] = $value;
  }
  // --remove
  foreach ($o['remove'] as $key) {
    $i = oneField($xml['Config'], $key, '--remove');
    unset($xml['Config'][$i]);
  }
  // --add TYPE:TARGET=VALUE   (addConfigPopup(), CreateDocker.php lines 590-640)
  foreach ($o['add'] as $spec) {
    if (!preg_match('/^(Path|Port|Variable|Label|Device):(.*)$/is', $spec, $m)) {
      throw new AppctlError('USAGE', "--add expects TYPE:TARGET=VALUE with TYPE Path|Port|Variable|Label|Device, got '$spec'", EXIT_USAGE);
    }
    $type = ucfirst(strtolower($m[1]));
    if ($type === 'Device') {
      $target = $value = $m[2];
    } else {
      $p = strpos($m[2], '=');
      if ($p === false || $p === 0) throw new AppctlError('USAGE', "--add $type expects $type:TARGET=VALUE", EXIT_USAGE);
      [$target, $value] = [substr($m[2], 0, $p), substr($m[2], $p + 1)];
    }
    // makeName(): "Host Path 1", "Host Port 2", "Host Key 3" (Variable), "Host Label 1", "Host Device 1"
    $n = count(array_filter($xml['Config'], fn($c) => ($c['Type'] ?? '') === $type)) + 1;
    $xml['Config'][] = ['Name' => 'Host '.str_replace('Variable', 'Key', $type)." $n", 'Target' => $target, 'Default' => '',
      'Mode' => $type === 'Path' ? 'rw' : ($type === 'Port' ? 'tcp' : ''), 'Description' => '', 'Type' => $type,
      'Display' => 'always', 'Required' => 'false', 'Mask' => 'false', 'Value' => $value, '_added' => true];
  }
  // --set KEY=VALUE / --set KEY.PROP=VALUE
  foreach ($o['set'] as [$key, $value]) {
    $prop = null;
    $idx = findField($xml['Config'], $key);
    if (!$idx && preg_match('/^(.*)\.(name|target|default|mode|description|display|required|mask)$/i', $key, $m)) {
      $idx = findField($xml['Config'], $m[1]);
      if ($idx) { $key = $m[1]; $prop = strtolower($m[2]); }
    }
    $i = oneField($xml['Config'], $key, '--set');
    $c = &$xml['Config'][$i];
    if ($prop === null) {
      if (($c['Type'] ?? '') === 'Variable' && count(explode('|', $c['Default'] ?? '')) > 1
          && !in_array($value, explode('|', $c['Default']), true)) {
        throw new AppctlError('INVALID_VALUE', "--set $key: allowed values are ".str_replace('|', ', ', $c['Default']), EXIT_USAGE);
      }
      $c['Value'] = $value;
      if (($c['Type'] ?? '') === 'Device') $c['Target'] = $value;
      if ($value === '' && (string)($c['Default'] ?? '') !== '') {
        // xmlToVar()/xmlToCommand(): an empty value falls back to Default (same in the WebUI)
        warn("--set $key= : empty value falls back to the default '{$c['Default']}' (dockerMan behaviour). Use --remove $key to drop the field.");
      }
    } else {
      $rules = fieldPropRules($c['Type'] ?? '');
      if ($rules[$prop] !== null && !in_array($value, $rules[$prop], true)) {
        throw new AppctlError('INVALID_VALUE', "--set $key.$prop: allowed values are ".implode(', ', $rules[$prop]), EXIT_USAGE);
      }
      // The "Edit" dialog re-reads all inputs: attributes the template did not have become ''.
      foreach (['Name', 'Target', 'Default', 'Mode', 'Description', 'Type', 'Display', 'Required', 'Mask'] as $attr) $c[$attr] ??= '';
      if (!in_array($c['Type'], ['Path', 'Port'], true)) $c['Mode'] = '';
      $c[ucfirst($prop)] = $value;
    }
    unset($c);
  }
  $xml['Config'] = array_values($xml['Config']);
}

/** Browser behaviour for text inputs: line breaks are stripped from the value. */
function textInput(?string $s): string { return str_replace(["\r", "\n"], '', (string)$s); }

/**
 * Values put into the hidden <input value="..."> of #templateDisplayConfig via String.format()
 * and $.parseHTML (CreateDocker.php lines 512-531, 1510-1521). Not escaped by Unraid: the HTML parser
 * decodes entities once more and a '"' ends the attribute value.
 */
function htmlAttr($s, string $placeholder): string {
  if ($s === null) return $placeholder;     // JS: undefined -> "{n}" stays (e.g. the well known Mode="{3}")
  $s = (string)$s;
  $q = strpos($s, '"');
  if ($q !== false) { warn("Template field value truncated at '\"' (same as WebUI): ".substr($s, 0, 60)); $s = substr($s, 0, $q); }
  return html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * REPLICATED: browser side of the dockerMan "Add Container" form (CreateDocker.php, Unraid 7.1.2):
 *   - page load: Settings -> form fields (lines 1830-1883), makeConfig() (lines 512-552), showSubnet() (lines 1535-1560)
 *   - submit: prepareConfig() (lines 735-746) + browser form encoding (line breaks -> CRLF)
 * Returns [$post, $missingRequired].
 */
function formPost(array $xml): array {
  global $custom;
  $post = [];
  // Simple fields: Settings[key] -> input[name=cont<key>]
  foreach (['Name', 'Repository', 'Support', 'Project', 'ReadMe', 'Registry', 'DonateText', 'DonateLink',
            'TemplateURL', 'Icon', 'WebUI', 'ExtraParams', 'PostArgs', 'MyIP'] as $k) {
    $post["cont$k"] = textInput($xml[$k] ?? '');
  }
  $post['contOverview'] = (string)($xml['Overview'] ?? '');   // textarea
  $post['contRequires'] = (string)($xml['Requires'] ?? '');   // textarea
  $post['contCategory'] = (string)($xml['Category'] ?? '');   // hidden input
  // <select name="contNetwork">: bridge, host, container, none + custom networks (lines 1082-1100)
  $netOptions = array_merge(['bridge', 'host', 'container', 'none'], $custom);
  if (preg_match('/^container:(.+)$/', $xml['Network'], $m)) {
    // CreateDocker.php lines 263-265: split into select "container" + #netCONT = <name> (showSubnet())
    $post['contNetwork'] = 'container';
    $post['netCONT'] = $m[1];
  } elseif (in_array($xml['Network'], $netOptions, true)) {
    $post['contNetwork'] = $xml['Network'];
  }
  // showSubnet(): fixed IP is cleared for bridge/host/none; netCONT select ends up without selection
  if (preg_match('/^(bridge|host|none|container)$/i', $post['contNetwork'] ?? '')) $post['contMyIP'] = '';
  // <select name="contShell">: only sh|bash exist; any other value -> nothing selected -> not posted
  if (in_array($xml['Shell'], ['sh', 'bash'], true)) $post['contShell'] = $xml['Shell'];
  // checkbox
  if (strtolower($xml['Privileged'] ?? '') === 'true') $post['contPrivileged'] = 'on';
  // CPU pinning checkboxes -> prepareConfig() joins the checked CPUs
  $post['contCPUset'] = (string)($xml['CPUset'] ?? '');
  if ($post['contCPUset'] !== '') warn("Template sets CPU pinning '{$post['contCPUset']}'; kept as-is");
  // Tailscale state dir input is rendered by PHP and always submitted (line 1388)
  $post['TSstatedir'] = textInput($xml['TailscaleStateDir'] ?? '');

  // Config entries: non-advanced ones go to #configLocation, advanced ones to #configLocationAdvanced,
  // which comes later in the form -> different order in the submitted data (lines 1876-1881, 1417-1420).
  $normal = $advanced = [];
  foreach ($xml['Config'] as $i => $c) {
    $c['_index'] = $i;
    if (($c['Type'] ?? null) === 'Device') $c['Target'] = $c['Value'];   // lines 1871-1873
    // fields added with "Add another ..." are always appended to #configLocation (line 637)
    if (empty($c['_added']) && in_array($c['Display'] ?? null, ['advanced', 'advanced-hide'], true)) $advanced[] = $c; else $normal[] = $c;
  }
  $isHost = ($post['contNetwork'] ?? '') === 'host';
  $missing = $meta = [];
  foreach (array_merge($normal, $advanced) as $c) {
    $value = htmlAttr(str_replace('"', '&quot;', (string)$c['Value']), '{9}');   // escapeQuote() + HTML parse
    $default = $c['Default'] ?? null;
    $opts = null;
    if (($c['Type'] ?? null) === 'Variable' && $default !== null && count(explode('|', $default)) > 1) {
      // <select>: option matching Value is selected, otherwise the browser selects the first option
      $opts = explode('|', $default);
      $value = in_array($c['Value'], $opts, true) ? $c['Value'] : $opts[0];
      $required = false;   // the select replaces the input carrying the "required" attribute
    } else {
      $value = textInput($value);
      $required = ($c['Required'] ?? null) === 'true';
    }
    if ($required && $value === '') $missing[] = ['name' => $c['Name'] ?? '', 'target' => $c['Target'] ?? '', 'type' => $c['Type'] ?? ''];
    $target = htmlAttr($c['Target'] ?? null, '{1}');
    if ($isHost && ($c['Type'] ?? null) === 'Port') $target = $value;     // prepareConfig()
    $post['confName'][]        = htmlAttr(isset($c['Name']) ? strip_tags($c['Name']) : null, '{0}');
    $post['confTarget'][]      = $target;
    $post['confDefault'][]     = htmlAttr($default, '{2}');
    $post['confMode'][]        = htmlAttr($c['Mode'] ?? null, '{3}');
    $post['confDescription'][] = htmlAttr($c['Description'] ?? null, '{4}');
    $post['confType'][]        = htmlAttr($c['Type'] ?? null, '{5}');
    $post['confDisplay'][]     = htmlAttr($c['Display'] ?? null, '{6}');
    $post['confRequired'][]    = htmlAttr($c['Required'] ?? null, '{7}');
    $post['confMask'][]        = htmlAttr($c['Mask'] ?? null, '{8}');
    $post['confValue'][]       = $value;
    $meta[] = ['set_key' => setKeyFor($xml['Config'], $c['_index']), 'required' => $required, 'choices' => $opts,
               'requires' => (string)($c['Requires'] ?? '')];
  }
  if ($post['contName'] === '') $missing[] = ['name' => 'Name', 'target' => '--name', 'type' => 'Container'];
  if ($post['contRepository'] === '') $missing[] = ['name' => 'Repository', 'target' => '', 'type' => 'Container'];

  // Browser form submission normalises every line break to CRLF.
  array_walk_recursive($post, function (&$v) { $v = preg_replace("/\r\n|\r|\n/", "\r\n", (string)$v); });
  return [$post, $missing, $meta];
}

/**
 * Host ports currently in use: listening sockets on the host (Unraid services, host-network
 * containers, docker-proxy of published ports) plus port bindings configured on existing
 * containers (also stopped ones). Ports of $exclude (container being replaced) are ignored.
 * Returns ['tcp/80' => ['by' => 'listening socket|container X', 'running' => bool], ...]
 */
function hostPortsInUse(string $exclude): array {
  $used = $excluded = [];
  // configured bindings of all containers (docker inspect works for stopped ones, too)
  $ids = trim((string)shell_exec('docker ps -aq 2>/dev/null'));
  if ($ids !== '') {
    $fmt = '{{.Name}}|{{.State.Running}}|{{json .HostConfig.PortBindings}}';
    foreach (explode("\n", trim((string)shell_exec("docker inspect --format '$fmt' ".str_replace("\n", ' ', escapeshellcmd($ids)).' 2>/dev/null'))) as $line) {
      [$cname, $running, $bind] = array_pad(explode('|', $line, 3), 3, '');
      $cname = ltrim($cname, '/');
      foreach ((array)json_decode($bind, true) as $cport => $hosts) {
        $proto = explode('/', $cport)[1] ?? 'tcp';
        foreach ((array)$hosts as $h) {
          if (empty($h['HostPort'])) continue;
          $key = "$proto/{$h['HostPort']}";
          if (strcasecmp($cname, $exclude) === 0) { $excluded[$key] = true; continue; }
          $used[$key] = ['by' => "container $cname", 'running' => $running === 'true'];
        }
      }
    }
  }
  // listening sockets
  foreach (['tcp' => '-ltn', 'udp' => '-lun'] as $proto => $flags) {
    foreach (explode("\n", (string)shell_exec("ss -H $flags 2>/dev/null")) as $line) {
      $cols = preg_split('/\s+/', trim($line));
      if (count($cols) < 4 || !preg_match('/:(\d+)$/', $cols[3], $m)) continue;
      $key = "$proto/{$m[1]}";
      if (isset($excluded[$key])) continue;
      if (!isset($used[$key]) || !$used[$key]['running']) {
        $used[$key] = ['by' => isset($used[$key]) ? $used[$key]['by'] : 'listening socket on host', 'running' => true];
      }
    }
  }
  return $used;
}

/**
 * Host port conflicts, only for bridge and host networks: there the container shares the host's
 * ports (macvlan/ipvlan like br0 give the container its own IP -> no host port conflicts).
 * Returns conflicts keyed by index in $post['conf*'], each with a suggested free port.
 */
function portConflicts(array $post, string $name): array {
  global $driver;
  $drv = $driver[$post['contNetwork'] ?? ''] ?? '';
  if (!in_array($drv, ['bridge', 'host'], true)) return [];
  $used = hostPortsInUse($name);
  $conflicts = $seen = [];
  foreach ($post['confType'] ?? [] as $i => $type) {
    if ($type !== 'Port') continue;
    $hostPort = strlen($post['confValue'][$i]) ? $post['confValue'][$i] : $post['confDefault'][$i];  // as xmlToCommand()
    if (!ctype_digit($hostPort)) continue;
    $proto = strtolower($post['confMode'][$i]) === 'udp' ? 'udp' : 'tcp';
    $key = "$proto/$hostPort";
    $by = null;
    if (isset($seen[$key])) {
      $by = "field '{$seen[$key]}' of this template";
    } elseif (isset($used[$key])) {
      if ($used[$key]['running']) $by = $used[$key]['by'];
      else warn("Host port $key is also configured on stopped {$used[$key]['by']} – starting both at once will fail");
    }
    $seen[$key] = $post['confName'][$i];
    if ($by !== null) {
      $conflicts[$i] = ['port' => (int)$hostPort, 'protocol' => $proto, 'field' => $post['confName'][$i],
                        'target' => $post['confTarget'][$i], 'used_by' => $by];
    }
  }
  // Suggest free ports: port+8000 (80 -> 8080, 443 -> 8443), else counting up from there.
  $taken = array_merge(array_keys($used), array_keys($seen));
  foreach ($conflicts as &$c) {
    $p = $c['port'] < 57535 ? $c['port'] + 8000 : 20000;
    while (in_array("{$c['protocol']}/$p", $taken, true) && $p < 65535) $p++;
    $c['suggested_port'] = $p;
    $taken[] = "{$c['protocol']}/$p";
  }
  unset($c);
  return $conflicts;
}

/** Remove the Docker page cache entry (webui-info) for $name if no container with that name exists. */
function dropStaleWebuiInfo(string $name): bool {
  global $dockerManPaths, $DockerClient;
  $DockerClient->flushCaches();
  if ($DockerClient->doesContainerExist($name)) return false;
  $info = DockerUtil::loadJSON($dockerManPaths['webui-info']);
  if (!isset($info[$name])) return false;
  unset($info[$name]);   // same as DockerClient::removeContainer()
  DockerUtil::saveJSON($dockerManPaths['webui-info'], $info);
  return true;
}

function nameConflicts(string $name): array {
  global $DockerClient, $dockerManPaths;
  $found = [];
  foreach ($DockerClient->getDockerContainers() as $ct) {
    if (strcasecmp($ct['Name'], $name) === 0) $found[] = "container '{$ct['Name']}'";
  }
  $tmpl = "{$dockerManPaths['templates-user']}/my-$name.xml";   // /boot is FAT -> case-insensitive
  if (is_file($tmpl)) $found[] = "template $tmpl";
  return $found;
}

/**
 * Shared by `options` and `install`: runs the whole WebUI pipeline up to (not including) "Apply"
 * and collects problems instead of failing on them. Usage errors (bad --set/--network) still throw.
 */
function prepare(array $o): array {
  if (count($o['args']) !== 1) throw new AppctlError('USAGE', "{$o['cmd']} needs exactly one app", EXIT_USAGE);
  checkEnvironment();
  $t = resolveApp(loadFeed(), $o['args'][0]);
  if (!empty($t['Deprecated'])) warn("App '{$t['Name']}' is marked as deprecated in CA");
  if (isset($t['Compatible']) && empty($t['Compatible'])) warn("App '{$t['Name']}' is marked as incompatible with this Unraid version");

  loadDockerMan();
  $name = $o['name'] ?? str_replace(' ', '-', $t['Name']);   // createXML: str_replace(" ","-",Name)
  $name = preg_replace('/\s+/', '', $name);
  if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.\-]+$/', $name)) {   // pattern of input[name=contName]
    throw new AppctlError('INVALID_NAME', "Invalid container name '$name' (allowed: letters, digits, _ . -)", EXIT_USAGE);
  }
  $xmlPath = runCreateXML($t);                                // 1. ORIGINAL CA createXML()
  $xml = loadTemplateLikeDockerMan($xmlPath, $name);          // 2. dockerMan form load
  applyUserInput($xml, $o);
  [$post, $missing, $meta] = formPost($xml);
  return ['template' => $t, 'name' => $name, 'xml' => $xml, 'post' => $post, 'meta' => $meta, 'missing' => $missing,
          'name_conflicts' => nameConflicts($name),
          'port_conflicts' => portConflicts($post, $name)];   // not in WebUI: a human sees conflicts, an agent doesn't
}

/** `options`: the "Add Container" form as data, so an agent can ask the user what to change. */
function cmdOptions(array $o): array {
  global $subnet, $driver;
  $ctx = prepare($o);
  $post = $ctx['post'];
  $problems = [];
  if ($ctx['name_conflicts'] && !$o['replace']) {
    $problems[] = ['code' => 'NAME_CONFLICT', 'field' => '--name',
                   'message' => "Name '{$ctx['name']}' already in use: ".implode(', ', $ctx['name_conflicts'])];
  }
  $fields = [];
  foreach ($post['confType'] ?? [] as $i => $type) {
    $m = $ctx['meta'][$i];
    $value = $post['confValue'][$i];
    $f = [
      'set_key'     => $m['set_key'],
      'name'        => $post['confName'][$i],
      'type'        => $type,
      'target'      => $post['confTarget'][$i],
      'value'       => $value,
      'default'     => $post['confDefault'][$i],
      'choices'     => $m['choices'],
      'mode'        => $post['confMode'][$i],
      'required'    => $m['required'],
      'secret'      => $post['confMask'][$i] === 'true',
      'advanced'    => in_array($post['confDisplay'][$i], ['advanced', 'advanced-hide'], true),
      'hidden'      => in_array($post['confDisplay'][$i], ['hidden', 'always-hide', 'advanced-hide'], true),
      'description' => trim(preg_replace('/\s+/', ' ', strip_tags($post['confDescription'][$i]))),
      'problems'    => [],
    ];
    if ($m['required'] && $value === '') {
      $f['problems'][] = ['code' => 'MISSING_REQUIRED', 'message' => 'required, no value'];
    }
    if (isset($ctx['port_conflicts'][$i])) {
      $c = $ctx['port_conflicts'][$i];
      $f['problems'][] = ['code' => 'PORT_CONFLICT', 'message' => "host port {$c['protocol']}/{$c['port']} used by {$c['used_by']}",
                          'suggested_value' => (string)$c['suggested_port']];
    }
    if ($f['set_key'] === null) warn("Field '{$f['name']}' cannot be addressed with --set (no unique Target/Name)");
    foreach ($f['problems'] as $p) $problems[] = $p + ['field' => $f['set_key'] ?? $f['name']];
    $fields[] = $f;
  }
  $networks = [];
  foreach ($subnet as $net => $sn) {
    $networks[] = ['name' => $net, 'driver' => $driver[$net] ?? '', 'subnet' => $sn,
                   'fixed_ip_possible' => !in_array($net, ['bridge', 'host', 'none'], true)];
  }
  $t = $ctx['template'];
  return [
    'app'        => summary($t),
    'ready'      => !$problems,
    'problems'   => $problems,
    'container'  => [
      'name'       => $ctx['name'],
      'network'    => ($post['contNetwork'] ?? '') === 'container' ? 'container:'.($post['netCONT'] ?? '') : ($post['contNetwork'] ?? ''),
      'fixed_ip'   => $post['contMyIP'] ?? '',
      'autostart'  => $o['autostart'],
      'autostart_wait' => $o['autostartWait'],
    ],
    // every other container field, settable with --container KEY=VALUE
    'container_fields' => array_combine(APPCTL_CONTAINER_FIELDS, array_map(fn($f) => match ($f) {
      'Privileged' => ($post['contPrivileged'] ?? '') === 'on' ? 'true' : 'false',
      'Shell'      => $post['contShell'] ?? '',
      default      => $post["cont$f"] ?? '',
    }, APPCTL_CONTAINER_FIELDS)),
    'networks'   => $networks,
    'fields'     => $fields,
    'requires'   => trim(strip_tags(str_replace(['[', ']'], ['<', '>'], $ctx['xml']['Requires'] ?? ''))),
    'overview'   => shortText($ctx['xml']['Overview'] ?? '', 2000),
    'support'    => $post['contSupport'],
    'how_to_change' => 'Re-run with --name, --network, --ip, --container KEY=VALUE, --set <set_key>=<value>, --set <set_key>.<prop>=<value>, '.
                       '--add TYPE:TARGET=VALUE, --remove <set_key>, --autostart until ready=true; then: install <same arguments> --dry-run, and finally --yes.',
  ];
}

function cmdInstall(array $o): array {
  if ($o['dry'] && $o['yes']) throw new AppctlError('USAGE', 'Use either --dry-run or --yes', EXIT_USAGE);
  if (!$o['dry'] && !$o['yes']) {
    throw new AppctlError('CONFIRMATION_REQUIRED', 'Nothing done. Preview with --dry-run, then install with --yes.', EXIT_CONFIRM);
  }
  $ctx = prepare($o);
  $name = $ctx['name'];
  if ($ctx['name_conflicts'] && !$o['replace']) {
    throw new AppctlError('NAME_CONFLICT', "Name '$name' already in use: ".implode(', ', $ctx['name_conflicts']).'. Use --name or --replace.',
      EXIT_CONFLICT, ['conflicts' => $ctx['name_conflicts']]);
  }
  if ($ctx['missing']) {
    throw new AppctlError('MISSING_REQUIRED', 'Required fields without value: '.
      implode(', ', array_map(fn($m) => "{$m['name']} ({$m['target']})", $ctx['missing'])).'. Set them with --set KEY=VALUE.',
      EXIT_MISSING_REQ, ['missing' => $ctx['missing']]);
  }
  if ($ctx['port_conflicts']) {
    $c = array_values($ctx['port_conflicts']);
    throw new AppctlError('PORT_CONFLICT', 'Host ports already in use: '.implode(', ', array_map(fn($c) =>
      "{$c['protocol']}/{$c['port']} ({$c['field']}, used by {$c['used_by']}, free: {$c['suggested_port']})", $c)).
      '. Choose free ports with --set <target>=<port> or use a custom network like br0.',
      EXIT_PORT_CONFLICT, ['conflicts' => $c]);
  }
  $postXML = postToXML($ctx['post'], true);                   // 3. ORIGINAL "Apply"
  [$cmd, $Name, $Repository] = xmlToCommand($postXML, false);
  $runCmd = str_replace('/docker create ', '/docker run -d ', $cmd);
  $filename = sprintf('%s/my-%s.xml', $GLOBALS['dockerManPaths']['templates-user'], $Name);

  $result = ['app' => summary($ctx['template']), 'name' => $Name, 'repository' => $Repository, 'template_file' => $filename,
             'xml' => $postXML, 'command' => $runCmd, 'replaces' => $ctx['name_conflicts'],
             'autostart' => $o['autostart'], 'autostart_wait' => $o['autostartWait']];
  if ($o['dry']) return $result + ['dry_run' => true, 'installed' => false];

  $res = $result + doInstall($postXML, $Name, $Repository, $filename, $o['replace']);
  if ($o['autostart']) $res['steps'][] = setAutostart($Name, $o['autostartWait']);
  return $res;
}

/**
 * Autostart toggle on the Docker page = ORIGINAL dockerMan UpdateConfig.php action "autostart"
 * (and "wait" for the wait period), run like the WebUI's POST.
 */
function setAutostart(string $name, ?string $wait): string {
  global $dockerManPaths;
  $file = $dockerManPaths['autostart-file'];
  $names = array_map(fn($l) => explode(' ', $l)[0], (array)@file($file, FILE_IGNORE_NEW_LINES));
  if (in_array($name, $names, true)) {
    if ($wait === null) return 'autostart already enabled';
    webuiAction('plugins/dynamix.docker.manager/include/UpdateConfig.php', ['action' => 'wait', 'container' => urlencode($name), 'wait' => $wait]);
  } else {
    webuiAction('plugins/dynamix.docker.manager/include/UpdateConfig.php',
      ['action' => 'autostart', 'container' => urlencode($name), 'wait' => (string)$wait, 'auto' => 'true']);
  }
  $names = array_map(fn($l) => explode(' ', $l)[0], (array)@file($file, FILE_IGNORE_NEW_LINES));
  if (!in_array($name, $names, true)) {
    throw new AppctlError('AUTOSTART_FAILED', "Container installed and running, but enabling autostart failed – enable it on the Docker page", EXIT_INSTALL_FAIL);
  }
  return 'autostart enabled'.($wait !== null ? " (wait {$wait}s)" : '');
}

/**
 * REPLICATED: CreateDocker.php lines 71-160 (Apply, not dry run), using the ORIGINAL helpers.
 * Deviation: the image is pulled BEFORE the template is written, so a failed pull leaves nothing behind.
 */
function doInstall(string $postXML, string $Name, string $Repository, string $filename, bool $replace): array {
  global $DockerClient, $DockerTemplates, $dockerManPaths, $docroot;
  $steps = [];
  if (!$DockerClient->doesImageExist($Repository)) {
    if (!pullImage($Name, $Repository, false)) {
      throw new AppctlError('PULL_FAILED', "Pulling image $Repository failed", EXIT_INSTALL_FAIL);
    }
    $steps[] = "pulled $Repository";
  }
  [$cmd] = xmlToCommand($postXML, true);   // second call creates the host paths (as WebUI)
  $userTmplDir = $dockerManPaths['templates-user'];
  if (!is_dir($userTmplDir)) mkdir($userTmplDir, 0777, true);
  if (is_file($filename)) {
    $oldXML = simplexml_load_file($filename);
    $newIcon = (string)simplexml_load_string($postXML)->Icon;
    if ($oldXML && (string)$oldXML->Icon != $newIcon) {
      $repo = strpos($Repository, ':') ? $Repository : "$Repository:latest";
      $iconPath = $DockerTemplates->getIcon($repo, $Name);
      @unlink("$docroot/$iconPath");
      @unlink("{$dockerManPaths['images']}/".basename($iconPath));
    }
  }
  if (file_put_contents($filename, $postXML) === false) {
    throw new AppctlError('WRITE_FAILED', "Could not write $filename", EXIT_INSTALL_FAIL);
  }
  $steps[] = "wrote $filename";
  if ($DockerClient->doesContainerExist($Name)) {
    if (!$replace) throw new AppctlError('NAME_CONFLICT', "Container '$Name' appeared during install", EXIT_CONFLICT);
    $old = $DockerClient->getContainerDetails($Name);
    if (!empty($old['State']['Running'])) { stopContainer($Name, false, false); $steps[] = "stopped old $Name"; }
    removeContainer($Name, false, false);
    $steps[] = "removed old $Name";
  }
  // A stale Docker page cache entry for this name (e.g. left by a container that was renamed
  // outside dockerMan) would keep its old update status/links forever. DockerClient::removeContainer()
  // drops this entry when the WebUI replaces a container; do the same when there is no container.
  dropStaleWebuiInfo($Name);
  $cmd = str_replace('/docker create ', '/docker run -d ', $cmd);
  // execCommand() (Helpers.php line 619) with echo=false would discard the output; same popen, but output kept.
  $proc = popen("$cmd 2>&1", 'r');
  $output = '';
  while (($line = fgets($proc)) !== false) $output .= $line;
  $rc = pclose($proc);
  if ($rc !== 0) {
    throw new AppctlError('RUN_FAILED', "docker run failed (exit $rc): ".trim($output), EXIT_INSTALL_FAIL,
      ['output' => trim($output), 'template_file' => $filename,
       'hint' => 'The template was saved (same as WebUI). Fix the cause and re-run with --replace, or edit it in the WebUI.']);
  }
  $steps[] = 'started container';
  addRoute($Name);
  return ['dry_run' => false, 'installed' => true, 'container_id' => trim($output), 'steps' => $steps];
}

#################################################################
# Output
#################################################################

function printHuman(string $cmd, array $d): void {
  switch ($cmd) {
    case 'search':
      foreach ($d['results'] as $r) {
        printf("%-28s %-10s %s%s\n    %s\n    %s\n", $r['name'], 'ID '.$r['id'], $r['repository'],
          $r['deprecated'] ? '  [DEPRECATED]' : '', 'by '.$r['author'], $r['overview']);
      }
      printf("%d of %d results\n", count($d['results']), $d['total']);
      break;
    case 'info':
      printf("%s (ID %s) by %s\nRepository: %s\nNetwork:    %s\nWebUI:      %s\nTemplate:   %s\n\n%s\n\nFields:\n",
        $d['name'], $d['id'], $d['author'], $d['repository'], $d['network'], $d['webui'], $d['template_url'], $d['overview']);
      foreach ($d['config'] as $c) {
        printf("  %-8s %-30s target=%-25s default=%s%s%s\n", $c['type'], $c['name'], $c['target'], $c['default'],
          $c['required'] ? '  [required]' : '', $c['display'] === 'advanced' ? '  [advanced]' : '');
      }
      echo "\n{$d['note']}\n";
      break;
    case 'options':
      printf("%s  (%s)\nContainer name: %s\nNetwork:        %s%s   [available: %s]\nWebUI:          %s\n",
        $d['app']['name'], $d['app']['repository'], $d['container']['name'], $d['container']['network'],
        $d['container']['fixed_ip'] !== '' ? " ip {$d['container']['fixed_ip']}" : '',
        implode(', ', array_column($d['networks'], 'name')), $d['container_fields']['WebUI']);
      printf("Autostart:      %s\n", $d['container']['autostart'] ? 'on'.($d['container']['autostart_wait'] !== null ? " (wait {$d['container']['autostart_wait']}s)" : '') : 'off');
      if ($d['requires'] !== '') echo "Requires:       {$d['requires']}\n";
      echo "\nContainer fields (--container KEY=VALUE):\n";
      foreach ($d['container_fields'] as $k => $v) {
        if (in_array($k, ['Overview', 'DonateText', 'DonateLink', 'TemplateURL', 'Support', 'Project', 'Requires', 'Category'], true)) continue;
        printf("  %-12s %s\n", $k, mb_strimwidth(str_replace(["\r", "\n"], ' ', $v), 0, 120, '…'));
      }
      foreach ([false => 'Settings', true => 'Advanced settings'] as $adv => $title) {
        echo "\n$title:\n";
        foreach ($d['fields'] as $f) {
          if ($f['advanced'] != $adv) continue;
          $val = $f['secret'] && $f['value'] !== '' ? '****' : $f['value'];
          printf("  %-8s %-28s --set %s=%s%s%s%s%s\n", $f['type'], $f['name'], $f['set_key'] ?? '(n/a)', $val,
            in_array($f['type'], ['Path', 'Port'], true) ? "  [{$f['mode']}]" : '',
            $f['choices'] ? '   [choices: '.implode('|', $f['choices']).']' : '',
            $f['required'] ? '  [required]' : '', $f['secret'] ? '  [secret]' : '');
          if ($f['description'] !== '') echo '           '.shortText($f['description'], 160)."\n";
          foreach ($f['problems'] as $p) {
            echo "           !! {$p['message']}".(isset($p['suggested_value']) ? " -> suggestion: --set {$f['set_key']}={$p['suggested_value']}" : '')."\n";
          }
        }
      }
      foreach ($d['problems'] as $p) if ($p['code'] === 'NAME_CONFLICT') echo "\n!! {$p['message']} -> use --name\n";
      echo $d['ready'] ? "\nREADY – next: install ... --dry-run, then --yes\n" : "\nNOT READY – ".count($d['problems'])." problem(s), see !! above\n";
      break;
    case 'install':
      echo $d['dry_run'] ? "DRY RUN – nothing was changed.\n\n" : "Installed.\n\n";
      echo "Template file: {$d['template_file']}\n";
      if ($d['replaces']) echo "Replaces: ".implode(', ', $d['replaces'])."\n";
      echo 'Autostart: '.($d['autostart'] ? 'on' : 'off')."\n";
      echo "\n--- XML ---\n{$d['xml']}\n--- COMMAND ---\n{$d['command']}\n";
      if (!$d['dry_run']) echo "\n".implode("\n", $d['steps'])."\nContainer ID: {$d['container_id']}\n";
      break;
    case 'update-feed':
      echo "CA app feed updated ({$d['updated']})\n";
      break;
    case 'version':
      printf("appctl %s\nUnraid %s\nCommunity Applications %s\n", $d['appctl'], $d['unraid'], $d['community_applications']);
      break;
  }
}

function main(array $argv): int {
  global $APPCTL_WARNINGS;
  $json = in_array('--json', $argv, true);
  try {
    $o = parseArgs($argv);
    $cmd = $o['cmd'] ?? 'help';
    switch ($cmd) {
      case 'help':    fwrite(STDOUT, usage()); return EXIT_OK;
      case 'search':  checkEnvironment(); $data = cmdSearch($o); break;
      case 'info':    checkEnvironment(); $data = cmdInfo($o); break;
      case 'options': $data = cmdOptions($o); break;
      case 'install': $data = cmdInstall($o); break;
      case 'update-feed': checkEnvironment(); $data = updateFeed(); break;
      case 'version': $data = ['appctl' => APPCTL_VERSION, 'unraid' => unraidVersion(), 'community_applications' => caVersion()]; break;
      default: throw new AppctlError('USAGE', "Unknown command '$cmd' (see appctl --help)", EXIT_USAGE);
    }
    if ($json) {
      echo json_encode(['ok' => true, 'command' => $cmd, 'data' => $data, 'warnings' => $APPCTL_WARNINGS],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    } else {
      printHuman($cmd, $data);
      foreach ($APPCTL_WARNINGS as $w) fwrite(STDERR, "warning: $w\n");
    }
    return EXIT_OK;
  } catch (AppctlError $e) {
    $err = ['code' => $e->codeName, 'message' => $e->getMessage()] + $e->details;
    $exit = $e->exitCode;
  } catch (Throwable $e) {
    $err = ['code' => 'INTERNAL', 'message' => get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine()];
    $exit = EXIT_INTERNAL;
  }
  if ($json) {
    echo json_encode(['ok' => false, 'error' => $err, 'warnings' => $APPCTL_WARNINGS],
      JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
  } else {
    fwrite(STDERR, "error [{$err['code']}]: {$err['message']}\n");
    foreach ($err['candidates'] ?? [] as $c) fwrite(STDERR, "  ID {$c['id']}: {$c['name']}  {$c['repository']}  ({$c['author']})\n");
    foreach ($err['conflicts'] ?? [] as $c) if (is_array($c)) fwrite(STDERR, "  port {$c['protocol']}/{$c['port']} ({$c['field']} -> container {$c['target']}): used by {$c['used_by']}\n");
    foreach ($err['missing'] ?? [] as $m) fwrite(STDERR, "  missing: {$m['name']} ({$m['type']} {$m['target']})\n");
    foreach ($err['available'] ?? [] as $n) fwrite(STDERR, "  available: $n\n");
    foreach ($APPCTL_WARNINGS as $w) fwrite(STDERR, "warning: $w\n");
  }
  return $exit;
}

if (!defined('APPCTL_NO_MAIN')) exit(main($argv));   // APPCTL_NO_MAIN: include for tests
