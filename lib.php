<?php

function watch_dir($host, $port, $root, $ignore)
{
    $socket = null;
    $changed = false;

    $modify_table = load_modify_time();
    $queue = array($root);

    while (!empty($queue)) {
        // echo "queue\n"; var_dump($queue);
        $root_dir = array_shift($queue);
        // echo "enter dir $root_dir\n";
        $d = opendir($root_dir);

        while (($f = readdir($d)) !== false) {
            if ($f == '.' || $f == '..') {
                continue;
            }
            if (in_array($f, $ignore)) {
                // echo "skip $f\n";
                continue;
            }
            $filename = "$root_dir/$f";
            if (is_file($filename)) {
                if (!isset($modify_table[$filename])) {
                    $modify_table[$filename] = filemtime($filename);
                } else {
                    $filemtime = filemtime($filename);
                    if ($modify_table[$filename] != $filemtime) {
                        $modify_table[$filename] = $filemtime;
                        echo "time diff $modify_table[$filename] $filemtime\n";
                        echo "send file $filename\n";
                        if ($socket === null) {
                            echo "Connect to $host:$port ... \n";
                            $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)or die("Could not create socket\n"); // 创建一个Socket
                            $connection = socket_connect($socket, $host, $port) or die("Could not connet server\n");    //  连接
                        }
                        send_relet_file($socket, $root, $filename);
                        $changed = true;
                    } else {
                        // echo ".";
                    }
                }
            } elseif (is_dir($filename)) {
                // echo "add to queue $filename\n";
                $queue[] = "$filename";
            }
        }
    }
    echo "ok\n";
    save_modify_time($modify_table);

    if ($socket !== null) {
        send_end($socket);
        while ($buff = socket_read($socket, 1024)) {  
            echo("Response was:" . $buff . "\n");
        }

        socket_close($socket);
    }
    return $changed;
}

function get_config()
{
    $config_file = __DIR__.'/config.default.json';
    if (!is_file($config_file)) {
        echo "$config_file not exists\n";
        exit(1);
    }
    $config = file_get_contents($config_file);
    $config = json_decode($config, true);
    $f = __DIR__.'/config.user.json';
    if (is_file($f)) {
        $config_user = json_decode(file_get_contents($f), true);
        if (isset($config_user['ignore'])) {
            $ignore = array_merge($config['ignore'], $config_user['ignore']);
        }
        $config = array_merge($config, $config_user);
        if (isset($ignore)) {
            $config['ignore'] = $ignore;
        }
    }
    return $config;
}

function socket_read_enough($socket, $len)
{
    if (!$len) {
        return '';
    }
    $ret = socket_read($socket, $len);
    while (strlen($ret) != $len && $len > 0) {
        echo "read more\n";
        $len -= strlen($ret);
        $ret .= socket_read($socket, $len);
    }
    return $ret;
}

function socket_write_big($socket, $st)
{
    $length = strlen($st);
    while (true) {
        $sent = socket_write($socket, $st, $length);
        if ($sent === false) {
            break;
        }
        // Check if the entire message has been sented
        if ($sent < $length) {
            // If not sent the entire message.
            // Get the part of the message that has not yet been sented as message
            $st = substr($st, $sent);
            // Get the length of the not sented part
            $length -= $sent;
        } else {
            break;
        }
    }
    return true;
}

/**
 * 保存文件
 * 服务端调用
 */
function save_file($socket, $filename, $len)
{
    $f = fopen($filename, 'w');
    //读取客户端数据  
    echo "Read client data \n";
    //socket_read函数会一直读取客户端数据,直到遇见\n,\t或者\0字符.PHP脚本把这写字符看做是输入的结束符.  
    $buf = socket_read($socket, $len);
    fwrite($f, $buf);
    while (strlen($buf) != $len && $len > 0) {
        $len -= strlen($buf);
        $buf = socket_read($socket, $len);
        fwrite($f, $buf);
        // echo "write: $buf   \n";
    }
    echo "save file $filename\n";

    return;
}

function send_file($socket, $filename)
{
    echo "send file $filename\n";
    $content = file_get_contents($filename);
    socket_write($socket, $content) or die("Write failed in ".__FUNCTION__."():".__LINE__."\n"); // 数据传送 向服务器发送消息
}

function is_text_file($filename)
{
    return !preg_match('/\.png$|\.jpg|\.gif$|\.eot$|\.woff$|\.ttf$/i', $filename);
}

function send_relet_file($socket, $root, $filename)
{
    if (strpos($filename, $root) !== 0) {
        echo "Error: filename $filename, root $root not match\n";
        return;
    }
    $relat_path = substr($filename, strlen($root)+1);
    echo "relat_path $relat_path\n";
    echo "send file $filename\n";
    $content = file_get_contents($filename);
    if (is_text_file($filename)) {
        $content = str_replace(PHP_EOL, "\n", $content);
    }
    // $content = mb_convert_encoding($content, 'UTF-8');
    // echo "$content\n";
    $size = strlen($content);
    if ($size == 0) {
        var_dump($content);
        echo "emtpy file\n";
    }
    $ctrl = array(
        'cmd' => 'send file',
        'filename' => $relat_path,
        'size' => $size,
    );
    // var_dump($ctrl);
    $json = json_encode($ctrl);
    $len = strlen($json);
    echo "length of control message $len\n";
    $len = pack('i', $len);
    socket_write($socket, $len) or die("Write failed in ".__FUNCTION__."():".__LINE__."\n"); // 数据传送 向服务器发送消息
    if (true !== socket_write_big($socket, $json)) {
        echo ("Write failed in ".__FUNCTION__."():".__LINE__."\n");
    }
    if (true !== socket_write_big($socket, $content)) {
        echo ("Write failed in ".__FUNCTION__."():".__LINE__."\n");
    }
}

function send_end($socket)
{
    echo "send end\n";
    $ctrl = array(
        'cmd' => 'end',
    );
    $json = json_encode($ctrl);
    $len = strlen($json);
    echo "length of control message $len\n";
    $len = pack('i', $len);
    socket_write($socket, $len) or die("Write failed\n"); // 数据传送 向服务器发送消息  
    socket_write($socket, ($json)) or die("Write failed\n"); // 数据传送 向服务器发送消息  
}

function save_relet_file($socket, $root)
{
    echo "Read client data \n";
    $len = socket_read_enough($socket, 4);
    if (empty($len) || strlen($len) == 0) {
        return false;
    }
    $len = unpack('i', $len);
    var_dump($len);
    $len = $len[1];
    echo "length of control message $len\n";
    if ($len > 10000) {
        echo "length too long, stop\n";
        exit;
    }
    $json = socket_read_enough($socket, $len);
    $ctrl = json_decode($json);
    if (empty($ctrl)) {
        var_dump($json);
        echo "ctrl obj emtpy\n";
        exit();
    }
    print_r($ctrl);
    if ($ctrl->cmd == 'end') {
        echo "recieve end\n";
        return -1;
    }
    $filename = "$root/$ctrl->filename";

    $dirname = dirname($filename);
    if (is_file($dirname)) {
        echo 'Error: ', $filename, ' is not dir', "\n";
        exit;
    }
    if (!is_dir($dirname)) {
        echo 'mkdir ', $dirname, "\n";
        mkdir($dirname, 0777, true);
    }

    socket_write($socket, 'recieve a file '.$filename."\n");

    return save_file($socket, $filename, $ctrl->size);
}

function load_modify_time()
{
    $f = __DIR__.'/modify_time';
    if (is_file($f)) {
        return json_decode(file_get_contents($f), true);
    }
    return array();
}

function save_modify_time($modify_table)
{
    $f = __DIR__.'/modify_time';
    return file_put_contents($f, json_encode($modify_table));
}
