<?php

$path = '/tmp/cloud-init.sock';

@unlink($path);

$socket = stream_socket_server(
    address: 'unix://'.$path,
);

$connections = [];

$check = function () use (&$connections, &$socket) {
    if (count($connections) < 2) {
        $connection = @stream_socket_accept($socket, 0.1);

        if ($connection) {
            stream_set_blocking($connection, false);
            $connections[] = $connection;
        }
    }
};

while (true) {
    $check();

    foreach ($connections as $connection) {
        $message = fgets($connection);

        if ($message === false) {
            continue;
        }

        echo $message;
    }
}
