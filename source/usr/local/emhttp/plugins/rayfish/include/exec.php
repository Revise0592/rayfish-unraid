<?php
/* AJAX backend for the Rayfish settings page.
 *
 * Unraid's local_prepend.php rejects POSTs without a valid csrf_token, and the
 * webGUI itself requires an authenticated admin session, so everything here
 * runs as root on behalf of the logged-in admin.
 */

const RAY = '/usr/local/bin/ray';
const RC = '/etc/rc.d/rc.rayfish';
const STDOUT_LOG = '/var/log/rayfish/stdout.log';

/* Commands the plugin manages itself, or that block, open a browser, or
 * would install files the next reboot throws away. */
const BLOCKED = ['daemon', 'gui', 'up', 'start', 'stop', 'restart', 'install',
                 'uninstall', 'update', 'upgrade', 'completions', 'report'];

header('Content-Type: application/json');

function reply($data) {
  echo json_encode($data);
  exit;
}

function strip_ansi($text) {
  return preg_replace('/\x1b\[[0-9;?]*[ -\/]*[@-~]/', '', $text);
}

/* Run argv (already split) with a timeout and no stdin. */
function run(array $argv, $timeout = 60) {
  $cmd = 'NO_COLOR=1 timeout ' . (int)$timeout . ' ' . implode(' ', array_map('escapeshellarg', $argv)) . ' </dev/null 2>&1';
  exec($cmd, $out, $status);
  return ['status' => $status, 'output' => strip_ansi(implode("\n", $out)), 'timed_out' => $status === 124];
}

/* Shell-like split: whitespace separates words, single and double quotes
 * group them, backslash escapes the next character outside single quotes. */
function split_args($line) {
  $args = [];
  $cur = '';
  $have = false;
  $quote = null;
  $len = strlen($line);
  for ($i = 0; $i < $len; $i++) {
    $c = $line[$i];
    if ($quote === "'") {
      if ($c === "'") $quote = null; else $cur .= $c;
    } elseif ($c === '\\' && $i + 1 < $len) {
      $cur .= $line[++$i];
      $have = true;
    } elseif ($quote === '"') {
      if ($c === '"') $quote = null; else $cur .= $c;
    } elseif ($c === "'" || $c === '"') {
      $quote = $c;
      $have = true;
    } elseif (ctype_space($c)) {
      if ($have) { $args[] = $cur; $cur = ''; $have = false; }
    } else {
      $cur .= $c;
      $have = true;
    }
  }
  if ($quote !== null) return null;
  if ($have) $args[] = $cur;
  return $args;
}

function daemon_running() {
  exec(RC . ' status >/dev/null 2>&1', $_, $status);
  return $status === 0;
}

function ipv6_warning() {
  if (!is_dir('/proc/sys/net/ipv6')) return 'IPv6 is not available in this kernel. Rayfish needs IPv6 for its overlay network.';
  if (trim(@file_get_contents('/proc/sys/net/ipv6/conf/default/disable_ipv6')) === '1')
    return 'IPv6 is disabled for new interfaces. Rayfish addresses its rayfish0 interface over IPv6, so set Settings → Network Settings → Network protocol to "IPv4 + IPv6" if the daemon fails to start.';
  return '';
}

$action = $_POST['action'] ?? '';

switch ($action) {
case 'status':
  $running = daemon_running();
  $version = is_executable(RAY) ? trim(run([RAY, '--version'], 10)['output']) : 'not installed';
  $status = $running ? run([RAY, 'status', '--with-ips'], 20)['output'] : '';
  reply(['running' => $running, 'version' => $version, 'status' => $status, 'warning' => ipv6_warning()]);

case 'service':
  $op = $_POST['op'] ?? '';
  if (!in_array($op, ['start', 'stop', 'restart'], true)) reply(['error' => 'unknown service action']);
  reply(run([RC, $op], 90));

case 'create':
  $argv = [RAY, 'create'];
  if (($_POST['name'] ?? '') !== '') array_push($argv, '--name', $_POST['name']);
  if (($_POST['hostname'] ?? '') !== '') array_push($argv, '--hostname', $_POST['hostname']);
  if (($_POST['open'] ?? '') === 'true') $argv[] = '--open';
  reply(run($argv));

case 'invite':
  $network = $_POST['network'] ?? '';
  if ($network === '') reply(['error' => 'enter a network name']);
  reply(run([RAY, 'invite', $network]));

case 'join':
  $code = trim($_POST['code'] ?? '');
  if ($code === '') reply(['error' => 'paste an invite code or network key']);
  $argv = [RAY, 'join', $code];
  if (($_POST['hostname'] ?? '') !== '') array_push($argv, '--hostname', $_POST['hostname']);
  reply(run($argv, 120));

case 'run':
  $args = split_args($_POST['args'] ?? '');
  if ($args === null) reply(['error' => 'unbalanced quotes']);
  if ($args && $args[0] === 'ray') array_shift($args);
  if (!$args) reply(['error' => 'enter a ray command']);
  $sub = null;
  foreach ($args as $a) if ($a === '' || $a[0] !== '-') { $sub = $a; break; }
  if (in_array($sub, BLOCKED, true))
    reply(['error' => "`ray $sub` is managed by the plugin (or needs a terminal) and can't be run from here"]);
  if (in_array('-f', $args, true) || in_array('--follow', $args, true))
    reply(['error' => 'following output is not supported here; run it from a terminal']);
  reply(run(array_merge([RAY], $args), 120));

case 'logs':
  $out = daemon_running() ? run([RAY, 'logs', '--since', '1h'], 20)['output'] : '';
  $lines = explode("\n", $out);
  $tail = @file(STDOUT_LOG, FILE_IGNORE_NEW_LINES) ?: [];
  reply(['logs' => implode("\n", array_slice($lines, -300)),
         'stdout' => strip_ansi(implode("\n", array_slice($tail, -100)))]);

default:
  reply(['error' => 'unknown action']);
}
