<?php
require_once __DIR__ . '/proxmoxlxc.php'; // 包含 proxmoxlxc_request 函数
require_once __DIR__ . '/db.php';         // 数据库连接封装

$ip_pool_file = __DIR__ . '/ip_pool.json';
$log_file = __DIR__ . '/traffic_log.json';

if (!file_exists($ip_pool_file)) {
    exit("客户数据文件不存在: $ip_pool_file\n");
}

$clients = json_decode(file_get_contents($ip_pool_file), true);
$logs = file_exists($log_file) ? json_decode(file_get_contents($log_file), true) : [];

foreach ($clients as $ip => $info) {
    if (!isset($info['server_vmid']) || !isset($info['data_limit']))
        continue;

    $vmid = (int) $info['server_vmid'];
    $limitGB = (int) $info['data_limit'];
    $limitBytes = $limitGB * 1024 * 1024 * 1024;

    // 伪造参数数组供 proxmoxlxc_request 使用
    $params = [
        'server_host' => 'pve',
        'domain' => $vmid,
        'server_http_prefix' => 'https',
        'server_ip' => 'pve.miraigrid.com',
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

    $rx = (int) $status['data']['netin'];
    $tx = (int) $status['data']['netout'];
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
    echo "[检查] VMID $vmid 已用 $usedGB GB / 限额 $limitGB GB\n";

    if ($monthUsed > $limitBytes) {
        echo "[超出] VMID $vmid 流量已超限，尝试关机并暂停...\n";

        // Step 1: 关机
        $stop_url = "/api2/json/nodes/{$params['server_host']}/lxc/{$vmid}/status/stop";
        $result = json_decode(proxmoxlxc_request($params, $stop_url, [], "POST"), true);
        if (!isset($result['data'])) {
            echo "[错误] 停止 VMID $vmid 失败，返回：\n" . print_r($result, true);
        } else {
            echo "[已关机] VMID $vmid 已通过 PVE API 停止。\n";
        }

        // Step 2: 数据库中暂停业务
        $pdo = getPdoConnection();
        $stmt = $pdo->prepare("UPDATE shd_host SET domainstatus = 'Suspended', suspendreason = :reason, suspend_time = :suspend_time WHERE domain = :vmid");
        $success = $stmt->execute([
            ':reason' => 'flow-用量超额',
            ':suspend_time' => time(),
            ':vmid' => (string) $vmid
        ]);

        if ($success) {
            echo "[数据库] VMID $vmid 已成功设置为 Suspended\n";
        } else {
            echo "[数据库] 设置 VMID $vmid 失败，错误信息：" . implode(', ', $stmt->errorInfo()) . "\n";
        }

        // Step 3: 发邮件通知


    }
}

file_put_contents($log_file, json_encode($logs, JSON_PRETTY_PRINT));


// === 函数：发邮件通知 ===
function sendOverLimitEmail($serviceId)
{
    $url = "http://miraigrid.com/Y6o3C7js/config_message/sendmessage_post";
    $post = http_build_query([
        'msgtype' => 0,
        'emaid' => 114, // 模板 ID
        'ematype' => 1,
        'id' => 1,   // 管理员 ID
        'hid' => $serviceId
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_TIMEOUT => 10
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return $resp;
}
