<?php
/**
 * OmniRoute Ultimate Health Check & Auto-Restart
 * Объединенный вариант: глубокая диагностика + надежное авто-восстановление.
 */

// --- КОНФИГУРАЦИЯ ---
$config = [
    'service_name'   => 'omniroute.service',
    'app_log'        => '/home/testai26/.omniroute/logs/application/app.log',
    'db_file'        => '/home/testai26/.omniroute/storage.sqlite',
    'call_logs_dir'  => '/home/testai26/.omniroute/call_logs',
    'failure_flag'   => __DIR__ . '/.failure_start_time',
    'report_file'    => '/home/testai26/hermes-agent/omniroute_health_report.txt',
    'restart_limit'  => 600, // 10 минут
];

$errors = [];
$warnings = [];
$ok = [];

// --- 1. Диагностика ---

// Systemd
$serviceStatus = shell_exec("systemctl --user is-active {$config['service_name']} 2>&1");
$serviceActive = trim($serviceStatus) === 'active';
if ($serviceActive) $ok['service_state'] = "Systemd: active";
else $errors[] = "Systemd service not active: " . trim($serviceStatus);

// PID
$mainPid = trim(shell_exec("systemctl --user show {$config['service_name']} -p MainPID --value 2>/dev/null"));
if ($mainPid && is_numeric($mainPid)) $ok['main_pid'] = "MainPID: $mainPid";
else $errors[] = 'Cannot determine MainPID';

// Процесс /proc
if ($mainPid && file_exists("/proc/$mainPid/status")) {
    $status = file_get_contents("/proc/$mainPid/status");
    if (preg_match('/Threads:\t(\d+)/', $status, $t)) $ok['threads'] = "Threads: {$t[1]}";
    if (preg_match('/State:\t(\w)/', $status, $s)) $ok['process_state'] = "State: {$s[1]}";
    $ok['fds'] = "Open FDs: " . count(glob("/proc/$mainPid/fd/*"));
} else {
    $errors[] = 'Process not found at /proc/PID';
}

// Порты
$portsOutput = shell_exec('ss -tulnp 2>/dev/null');
if ($portsOutput && $mainPid) {
    $found = false;
    foreach (explode("\n", $portsOutput) as $line) {
        if (preg_match('/tcp\s+LISTEN\s+.*?(\d+):(\d+).*?pid='.$mainPid.'/', $line, $m)) {
            $ok['listening_port'] = "Port: " . $m[2];
            $found = true;
        }
    }
    if (!$found) $warnings[] = 'No listening ports found for MainPID';
}

// Логи и БД (Freshness)
foreach (['app_log' => $config['app_log'], 'db_file' => $config['db_file']] as $key => $path) {
    if (file_exists($path)) {
        $diff = time() - filemtime($path);
        $ok[$key] = "$key last mod: " . round($diff/60, 1) . " min ago";
        if ($diff > 600) $warnings[] = "$key not updated in > 10 min";
    } else {
        $errors[] = "$key not found";
    }
}

// --- 2. Логика перезапуска ---

$shouldRestart = false;
$statusMessage = "";

if (!empty($errors)) {
    if (!file_exists($config['failure_flag'])) {
        file_put_contents($config['failure_flag'], time());
        $statusMessage = "Критическая ошибка! Запущен таймер 10 минут до перезапуска.";
    } else {
        $elapsed = time() - (int)file_get_contents($config['failure_flag']);
        $statusMessage = "Ошибка длится $elapsed секунд.";
        if ($elapsed >= $config['restart_limit']) $shouldRestart = true;
    }
} else {
    if (file_exists($config['failure_flag'])) unlink($config['failure_flag']);
}

// --- 3. Генерация отчета ---

$report = "=== OmniRoute Health Check (" . date('Y-m-d H:i:s') . ") ===\n\n";
if ($errors) $report .= "❌ ERRORS:\n  - " . implode("\n  - ", $errors) . "\n\n";
if ($warnings) $report .= "⚠️ WARNINGS:\n  - " . implode("\n  - ", $warnings) . "\n\n";
if ($ok) $report .= "✅ OK:\n  - " . implode("\n  - ", $ok) . "\n\n";
$report .= $statusMessage . "\n";

// Ensure directory exists
if (!is_dir(dirname($config['report_file']))) mkdir(dirname($config['report_file']), 0755, true);
file_put_contents($config['report_file'], $report);
echo $report;

// --- 4. Действие ---
if ($shouldRestart) {
    echo "\n🔄 Ошибки длятся > 10 минут. Перезапуск...\n";
    shell_exec("systemctl --user restart {$config['service_name']}");
    if (file_exists($config['failure_flag'])) unlink($config['failure_flag']);
}
