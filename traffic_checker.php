<?php
require_once __DIR__ . '/proxmoxlxc.php'; // 保证路径正确

$ip_pool_file = __DIR__ . '/ip_pool.json';
$log_file = __DIR__ . '/traffic_log.json';

// 魔方暂停 API 设置
define('MAGICPANEL_SUSPEND_API', 'http://miraigrid.com/Y6o3C7js/provision/default');

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
    $serviceId = $info['service_id'] ?? null; // 可选

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

    // 判断是否超额
    if ($monthUsed > $limitBytes) {
        echo "[超出] VMID $vmid 流量已超限，尝试暂停服务...\n";

        if ($serviceId) {
            // 优先使用魔方 API 暂停
            $resp = suspendByMagicPanelApi($serviceId);
            echo "[暂停] 调用魔方暂停接口：$resp\n";
            // ✅ 调用邮件通知接口
            $emailResp = sendOverLimitEmail($serviceId);
            echo "[通知] 已尝试发送流量超额邮件，结果：$emailResp\n";
        } else {
            // 否则调用硬关机作为兜底
            $stop_url = "/api2/json/nodes/{$params['server_host']}/lxc/{$vmid}/status/stop";
            $result = json_decode(proxmoxlxc_request($params, $stop_url, [], "POST"), true);

            if (!isset($result['data'])) {
                echo "[失败] 停止 VMID $vmid 失败，返回值：\n" . print_r($result, true);
            } else {
                echo "[已关机] VMID $vmid 因流量超额被强制停止\n";
            }
        }
    }
}

file_put_contents($log_file, json_encode($logs, JSON_PRETTY_PRINT));

// === 魔方后台暂停函数 ===
function suspendByMagicPanelApi($serviceId)
{
    $post = http_build_query([
        'id' => $serviceId,
        'func' => 'suspend',
        'reason' => '',
        'reason_type' => 'flow'
    ]);

    $ch = curl_init(MAGICPANEL_SUSPEND_API);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $resp = curl_exec($ch);
    curl_close($ch);
    return $resp;
}

function sendOverLimitEmail($serviceId)
{
    $url = "http://miraigrid.com/Y6o3C7js/config_message/sendmessage_post";
    $post = http_build_query([
        'msgtype' => 0,
        'emaid' => 114,      // 你的邮件模板 ID
        'ematype' => 1,
        'id' => 1,           // 管理员 ID（通常是 1）
        'hid' => $serviceId  // 服务 ID
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $resp = curl_exec($ch);
    curl_close($ch);

    return $resp;
}
