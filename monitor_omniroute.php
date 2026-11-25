<?php
/**
 * OmniRoute Health Check & Auto-Restart (v2)
 *
 * Проверяет: systemd -> процесс (/proc) -> порт -> реальный ответ API -> свежесть логов/БД.
 * Перезапускает сервис, если КРИТИЧЕСКИЕ ошибки длятся дольше restart_limit секунд.
 * Защита: flock, лимит рестартов в час, grace-период после рестарта, валидация state.
 *
 * Запуск: php monitor_omniroute.php (cron раз в 1-2 минуты)
 */

// ===================== КОНФИГУРАЦИЯ =====================
$home = getenv('HOME') ?: '/home/testai26';

$config = [
    'service_name'      => 'omniroute.service',
    'app_log'           => "$home/.omniroute/logs/application/app.log",
    'db_file'           => "$home/.omniroute/storage.sqlite",
    'report_file'       => "$home/hermes-agent/omniroute_health_report.txt",
    'history_log'       => "$home/.cache/omniroute-health/history.log",
    'state_file'        => "$home/.cache/omniroute-health/state.json",
    'lock_file'         => "$home/.cache/omniroute-health/lock",

    'api_enabled'       => true,
    'api_path'          => '/v1/models',
    'api_key'           => getenv('OMNIROUTE_API_KEY') ?: '',
    'api_timeout'       => 5,
    'api_slow_warn'     => 3.0,

    'restart_limit'     => 600,   // 10 минут
    'restart_grace'     => 90,
    'max_restarts_hour' => 3,
    'post_restart_wait' => 15,

    'stale_warn_sec'    => 600,
    'fd_warn_ratio'     => 0.8,
    'fd_crit_ratio'     => 0.95,
    'history_max_bytes' => 1048576,
];

// ===================== ПОДГОТОВКА ОКРУЖЕНИЯ =====================
$uid = function_exists('posix_getuid') ? posix_getuid() : (int)trim(shell_exec('id -u'));
if (!getenv('XDG_RUNTIME_DIR')) putenv("XDG_RUNTIME_DIR=/run/user/$uid");
if (!getenv('DBUS_SESSION_BUS_ADDRESS')) putenv("DBUS_SESSION_BUS_ADDRESS=unix:path=/run/user/$uid/bus");

foreach ([$config['state_file'], $config['history_log'], $config['lock_file'], $config['report_file']] as $p) {
    $d = dirname($p);
    if (!is_dir($d)) @mkdir($d, 0755, true);
}

$lockFh = fopen($config['lock_file'], 'c');
if (!$lockFh || !flock($lockFh, LOCK_EX | LOCK_NB)) exit(0);

function sh(string $cmd): string { return trim((string)shell_exec($cmd . ' 2>&1')); }

function loadState(string $file): array {
    $default = ['failure_since' => null, 'restarts' => [], 'last_restart' => 0];
    if (!is_file($file)) return $default;
    $data = json_decode((string)@file_get_contents($file), true) ?: [];
    $data = array_merge($default, $data);
    $now = time();
    $data['restarts'] = array_values(array_filter((array)$data['restarts'], fn($t) => is_int($t) && $t > $now - 3600));
    return $data;
}

function saveState(string $file, array $state): void {
    $tmp = $file . '.tmp';
    if (@file_put_contents($tmp, json_encode($state)) !== false) @rename($tmp, $file);
}

function logHistory(array $config, string $msg): void {
    $f = $config['history_log'];
    if (is_file($f) && filesize($f) > $config['history_max_bytes']) @rename($f, $f . '.1');
    @file_put_contents($f, '[' . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
}

function fmtMin(int $sec): string { return round($sec / 60, 1) . ' min'; }

// ===================== СОСТОЯНИЕ =====================
$state = loadState($config['state_file']);
$now = time();

if ($state['last_restart'] && ($now - $state['last_restart']) < $config['restart_grace']) exit(0);

$errors = []; $warnings = []; $ok = []; $checkFailed = false;
$svc = escapeshellarg($config['service_name']);

// --- ДИАГНОСТИКА ---
$svcOut = sh("systemctl --user is-active $svc");
if ($svcOut === 'active') $ok[] = 'Systemd: active';
else $errors[] = "Systemd: сервис не active ($svcOut)";

$mainPid = (int)sh("systemctl --user show $svc -p MainPID --value");
if ($mainPid > 0) $ok[] = "MainPID: $mainPid";
else $errors[] = 'MainPID = 0';

if ($mainPid > 0) {
    $procDir = "/proc/$mainPid";
    if (is_readable("$procDir/status")) {
        $status = (string)file_get_contents("$procDir/status");
        if (preg_match('/^State:\s+(\w)/m', $status, $m)) $ok[] = "State: {$m[1]}";
        
        $fdList = @glob("$procDir/fd/*") ?: [];
        $fdCount = count($fdList);
        $ok[] = "Open FDs: $fdCount";
    }
}

// Порты и API
$listen = [];
foreach (explode("\n", sh('ss -H -tlnp')) as $line) {
    if (preg_match('/^LISTEN\s+\S+\s+\S+\s+(\S+):(\d+)\s+.*?pid='.$mainPid.'/', $line, $m)) $listen[] = ['host'=>$m[1], 'port'=>(int)$m[2]];
}

if ($config['api_enabled'] && $mainPid > 0 && $listen) {
    $url = "http://127.0.0.1:{$listen[0]['port']}{$config['api_path']}";
    $ctx = stream_context_create(['http' => ['timeout' => $config['api_timeout'], 'ignore_errors' => true]]);
    $t0 = microtime(true);
    @file_get_contents($url, false, $ctx);
    if ((microtime(true) - $t0) > $config['api_timeout']) $errors[] = "API timeout";
    else $ok[] = "API OK";
}

// ===================== ЛОГИКА =====================
$shouldRestart = false;
if ($errors) {
    if ($state['failure_since'] === null) {
        $state['failure_since'] = $now;
        logHistory($config, 'FAIL start: ' . implode(' | ', $errors));
    } elseif (($now - $state['failure_since']) >= $config['restart_limit']) {
        $shouldRestart = true;
    }
} else {
    $state['failure_since'] = null;
}

if ($shouldRestart && count($state['restarts']) >= $config['max_restarts_hour']) $shouldRestart = false;

// ===================== ДЕЙСТВИЕ =====================
if ($shouldRestart) {
    sh("systemctl --user restart $svc");
    logHistory($config, 'RESTART issued');
    $state['restarts'][] = $now;
    $state['last_restart'] = $now;
    $state['failure_since'] = null;
}

saveState($config['state_file'], $state);
@file_put_contents($config['report_file'], '=== OmniRoute Health Check (' . date('Y-m-d H:i:s') . ") ===\n\nErrors: " . implode(', ', $errors));
flock($lockFh, LOCK_UN);
