<?php
require_once __DIR__ . '/proxmoxlxc.php';
require_once __DIR__ . '/db.php';

$log_file = __DIR__ . '/traffic_log.json';

if (!file_exists($log_file)) {
    exit("流量日志文件不存在: $log_file\n");
}

echo "=== 开始执行月度流量重置 ===" . date('Y-m-d H:i:s') . "\n";

$logs = json_decode(file_get_contents($log_file), true);
$reset_count = 0;
$total_count = count($logs);

foreach ($logs as $vmid => $data) {
    // 保留其他数据，只重置月度使用量
    $logs[$vmid]['month_used'] = 0;
    $reset_count++;
    
    $usedGB = round($data['month_used'] / 1024 / 1024 / 1024, 2);
    echo "[重置] VMID $vmid 上月使用: $usedGB GB -> 0 GB\n";
}

// 保存重置后的数据
if (file_put_contents($log_file, json_encode($logs, JSON_PRETTY_PRINT))) {
    echo "✅ 流量重置成功！共重置 $reset_count/$total_count 个容器\n";
    
    // 记录重置日志
    $reset_log = [
        'reset_time' => date('Y-m-d H:i:s'),
        'reset_count' => $reset_count,
        'total_count' => $total_count
    ];
    file_put_contents(__DIR__ . '/reset_history.log', json_encode($reset_log) . "\n", FILE_APPEND);
    
} else {
    echo "❌ 保存流量日志失败！\n";
    exit(1);
}

// 可选：同时恢复被暂停的超流量容器
echo "\n=== 检查并恢复超流量暂停的容器 ===\n";
restoreSuspendedContainers();

echo "=== 月度流量重置完成 ===\n";

function restoreSuspendedContainers() {
    try {
        $pdo = getPdoConnection();
        
        // 查找因流量超额而被暂停的容器
        $stmt = $pdo->prepare("SELECT id, domain FROM shd_host WHERE domainstatus = 'Suspended' AND suspendreason = 'flow-用量超额'");
        $stmt->execute();
        $suspended_hosts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $restored_count = 0;
        
        foreach ($suspended_hosts as $host) {
            $service_id = $host['id'];
            $vmid = $host['domain'];
            
            // 恢复数据库状态
            $update_stmt = $pdo->prepare("UPDATE shd_host SET domainstatus = 'Active', suspendreason = '', suspend_time = 0 WHERE id = :id");
            $success = $update_stmt->execute([':id' => $service_id]);
            
            if ($success) {
                echo "[恢复] 服务ID $service_id (VMID $vmid) 已从暂停状态恢复\n";
                $restored_count++;
                
                // 可选：启动容器
                // startContainer($vmid);
            } else {
                echo "[错误] 恢复服务ID $service_id 失败\n";
            }
        }
        
        echo "✅ 共恢复 $restored_count 个超流量暂停的容器\n";
        
    } catch (Exception $e) {
        echo "❌ 恢复超流量容器时出错: " . $e->getMessage() . "\n";
    }
}

function startContainer($vmid) {
    // 如果需要自动启动容器，可以使用这个函数
    $params = [
        'server_host' => 'pve',
        'domain' => $vmid,
        'server_http_prefix' => 'https',
        'server_ip' => 'pve.miraigrid.com',
        'port' => 443,
        'accesshash' => 'root@pam!root=89b5a694-73ff-4c85-870c-5728300c3366'
    ];
    
    $start_url = "/api2/json/nodes/{$params['server_host']}/lxc/{$vmid}/status/start";
    $result = json_decode(proxmoxlxc_request($params, $start_url, [], "POST"), true);
    
    if (isset($result['data'])) {
        echo "[启动] VMID $vmid 容器已启动\n";
    } else {
        echo "[错误] 启动 VMID $vmid 失败\n";
    }
}
?>