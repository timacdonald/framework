<?php

$path = '/tmp/cloud-init.sock';

@unlink($path);

$socket = stream_socket_server(
    address: 'unix://'.$path,
);

$connections = [];
$connectionIndex = 0;

$check = function () use (&$connections, &$socket, &$connectionIndex) {
    $connection = @stream_socket_accept($socket, 0);

    if ($connection) {
        stream_set_blocking($connection, false);
        $connections[$connectionIndex] = $connection;
        echo ">>> Connection [$connectionIndex] established.".PHP_EOL;
        $connectionIndex++;
    }
};

while (true) {
    $check();

    foreach ($connections as $index => $connection) {
        if (feof($connection)) {
            fclose($connection);
            echo "<<< Connection [{$id}] closed.".PHP_EOL;

            unset($connections[$index]);

            continue;
        }
        $id = $index + 1;
        while ($message = fgets($connection)) {
            echo "Connection [{$id}]: ".$message;
        }
    }
}
