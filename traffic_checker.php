<?php
require_once __DIR__ . '/proxmoxlxc.php'; // 保证路径正确

$ip_pool_file = __DIR__ . '/ip_pool.json';
$log_file = __DIR__ . '/traffic_log.json';

if (!file_exists($ip_pool_file)) {
    exit("客户数据文件不存在: $ip_pool_file\n");
}

$clients = json_decode(file_get_contents($ip_pool_file), true);
$logs = file_exists($log_file) ? json_decode(file_get_contents($log_file), true) : [];

foreach ($clients as $ip => $info) {
    if (!isset($info['server_vmid']) || !isset($info['data_limit'])) continue;

    $vmid = (int)$info['server_vmid'];
    $limitGB = (int)$info['data_limit'];
    $limitBytes = $limitGB * 1024 * 1024 * 1024;

    // 伪造参数数组，供 proxmoxlxc_request 使用
    $params = [
        'server_host' => 'pve', // 节点名
        'domain' => $vmid,
        'server_http_prefix' => 'https',
        'server_ip' => 'pve.miraigrid.com', // 或你的 API IP
        'port' => 443,
        'accesshash' => 'root@pam!root=89b5a694-73ff-4c85-870c-5728300c3366'
    ];

    $url = "/api2/json/nodes/{$params['server_host']}/lxc/{$vmid}/status/current";
    $json = proxmoxlxc_request($params, $url);

    $status = json_decode($json, true);
    if (!isset($status['data']['netin'], $status['data']['netout'])) {
        echo "[跳过] VMID $vmid 无法获取流量信息，输出为：\n$json\n";
        continue;
    }

    $rx = (int)$status['data']['netin'];
    $tx = (int)$status['data']['netout'];
    $currentTotal = $rx + $tx;

    $last = $logs[$vmid]['last_total'] ?? 0;
    $monthUsed = $logs[$vmid]['month_used'] ?? 0;

    $delta = ($currentTotal >= $last) ? $currentTotal - $last : $currentTotal;
    $monthUsed += $delta;

    $logs[$vmid] = [
        'rx' => $rx,
        'tx' => $tx,
        'last_total' => $currentTotal,
        'month_used' => $monthUsed,
        'data_limit' => $limitBytes
    ];

    $usedGB = round($monthUsed / 1024 / 1024 / 1024, 2);
    if ($monthUsed > $limitBytes) {
        echo "[超出] VMID $vmid 已用 $usedGB GB / 限额 $limitGB GB\n";
        // shell_exec("php /your/plugin/hook.php action=SuspendAccount vmid=$vmid");
    } else {
        echo "[正常] VMID $vmid 已用 $usedGB GB / 限额 $limitGB GB\n";
    }
}

file_put_contents($log_file, json_encode($logs, JSON_PRETTY_PRINT));
