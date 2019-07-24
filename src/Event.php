<?php

namespace Putao;

class Event
{
    public static function onOpen($server, $req)
    {
        echo "connection open: {$req->fd}\n";
    }

    public static function onMessage($server, $frame)
    {
        echo "received message: {$frame->data}\n";
        $server->push($frame->fd, json_encode(['hello', 'world']));
    }

    public static function onClose($server, $fd)
    {
        echo "connection close: {$fd}\n";
    }
}
