<?php

$path = '/tmp/cloud-init.sock';

@unlink($path);

$socket = stream_socket_server(
    address: 'unix://'.$path,
);

$connections = [];

$check = function () use (&$connections, &$socket) {
    $connection = @stream_socket_accept($socket, 0);

    if ($connection) {
        stream_set_blocking($connection, false);
        $connections[] = $connection;
        echo '>>>>> New connection established. Current connection count: '.count($connections).PHP_EOL;
    }
};

while (true) {
    $check();

    foreach ($connections as $index => $connection) {
        $id = $index + 1;
        while ($message = fgets($connection)) {
            echo "Connection [{$id}]: ".$message;
        }
    }
}
