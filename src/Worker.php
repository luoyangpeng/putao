<?php

namespace Putao;

use Swoole\Process;

// Define OS Type
define('OS_TYPE_LINUX', 'linux');

define('OS_TYPE_WINDOWS', 'windows');

class Worker
{
    /**
     * Version.
     *
     * @var string
     */
    const VERSION = '0.1.1';

    /**
     * Log file.
     *
     * @var mixed
     */
    public static $logFile = __DIR__.'/server.log';

    /**
     * OS.
     *
     * @var string
     */
    protected static $_OS = OS_TYPE_LINUX;

    /**
     * Daemonize.
     *
     * @var bool
     */
    public static $daemonize = false;

    /**
     * The file to store master process PID.
     *
     * @var string
     */
    public static $pidFile = __DIR__.'/server.pid';

    /**
     * Standard output stream.
     *
     * @var resource
     */
    protected static $_outputStream = null;

    /**
     * If $outputStream support decorated.
     *
     * @var bool
     */
    protected static $_outputDecorated = null;

    /**
     * Graceful stop or not.
     *
     * @var string
     */
    protected static $_gracefulStop = false;

    /**
     * Emitted when a socket connection is successfully established.
     *
     * @var callback
     */
    public $onOpen = null;

    /**
     * Emitted when data is received.
     *
     * @var callback
     */
    public $onMessage = null;

    /**
     * Emitted when the other end of the socket sends a FIN packet.
     *
     * @var callback
     */
    public $onClose = null;

    /**
     * process name.
     *
     *
     * @var string
     */
    public $processName = 'putao';

    /**
     * process count.
     *
     * @var int
     */
    public $processCount = 1;

    /**
     * Heartbeat interval.
     *
     * @var int
     */
    public $pingInterval = 0;

    /**
     * $pingNotResponseLimit * $pingInterval 时间内，客户端未发送任何数据，断开客户端连接.
     *
     * @var int
     */
    public $pingNotResponseLimit = 0;

    /**
     * 服务端向客户端发送的心跳数据.
     *
     * @var string
     */
    public $pingData = '';

    /**
     * 事件处理类，默认是 Event 类.
     *
     * @var string
     */
    public $eventHandler = 'Putao\Event';

    public static function runAll()
    {
        static::checkSapiEnv();
        static::parseCommand();
        static::displayUI();

        (new static())->startServer();
    }

    public function startServer()
    {
        $server = new \swoole_websocket_server('127.0.0.1', 9502);

        $server->set([
            'worker_num' => $this->processCount, // 进程数量
            'daemonize' => static::$daemonize, // 是否守护进程
            'backlog' => 128,
            'pid_file' => static::$pidFile,
            'dispatch_mode' => 5, // UID 分配模式
        ]);

        $server->on('open', $this->eventHandler.'::onOpen');

        $server->on('message', $this->eventHandler.'::onMessage');

        $server->on('close', $this->eventHandler.'::onClose');

        $server->start();
    }

    public static function displayUI()
    {
        global $argv;

        static::safeEcho('----------------------- <w>PUTAO</w> --------------------------------------------'.PHP_EOL);
        static::safeEcho('PHP version:'.PHP_VERSION.'                        Putao version:'.static::VERSION.PHP_EOL);
        static::safeEcho('
---------   ||        ||    ==========     =====        O=======O
-------||   ||        ||        TT         // \\        O       O
||     ||   ||        ||        TT        //   \\       O       O
||     ||   ||        ||        TT       //     \\      O       O
|-------    ||        ||        TT      //=======\\     O       O
||          ||        ||        TT     //         \\    O       O
||          ============        TT    //           \\   O=======O'.PHP_EOL);
        static::safeEcho('----------------------- <w>PUTAO</w> --------------------------------------------'.PHP_EOL);

        if (static::$daemonize) {
            static::safeEcho("Input \"php $argv[0] stop\" to stop. Start success.\n\n");
        } else {
            static::safeEcho("Press Ctrl+C to stop. Start success.\n");
        }
    }

    /**
     * Safe Echo.
     *
     * @param $msg
     * @param bool $decorated
     *
     * @return bool
     */
    public static function safeEcho($msg, $decorated = false)
    {
        $stream = static::outputStream();

        if (!$stream) {
            return false;
        }

        if (!$decorated) {
            $line = $white = $green = $end = '';

            if (static::$_outputDecorated) {
                $line = "\033[1A\n\033[K";
                $white = "\033[47;30m";
                $green = "\033[32;40m";
                $end = "\033[0m";
            }

            $msg = str_replace(['<n>', '<w>', '<g>'], [$line, $white, $green], $msg);
            $msg = str_replace(['</n>', '</w>', '</g>'], $end, $msg);
        } elseif (!static::$_outputDecorated) {
            return false;
        }

        fwrite($stream, $msg);
        fflush($stream);

        return true;
    }

    private static function outputStream($stream = null)
    {
        if (!$stream) {
            $stream = static::$_outputStream ? static::$_outputStream : STDOUT;
        }

        if (!$stream || !is_resource($stream) || 'stream' !== get_resource_type($stream)) {
            return false;
        }

        $stat = fstat($stream);

        if (($stat['mode'] & 0170000) === 0100000) {
            // file
            static::$_outputDecorated = false;
        } else {
            static::$_outputDecorated =
                static::$_OS === OS_TYPE_LINUX &&
                function_exists('posix_isatty') &&
                posix_isatty($stream);
        }

        return static::$_outputStream = $stream;
    }

    /**
     * Parse command.
     */
    public static function parseCommand()
    {
        global $argv;

        if (static::$_OS !== OS_TYPE_LINUX) {
            return;
        }

        $commands = [
            'start',
            'stop',
            'restart',
            'reload',
            'status',
        ];

        $usage = "Usage: php yourfile <command> [mode]\nCommands: \nstart\t\tStart worker in DEBUG mode.\n\t\tUse mode -d to start in DAEMON mode.\nstop\t\tStop worker.\n\t\tUse mode -g to stop gracefully.\nrestart\t\tRestart workers.\n\t\tUse mode -d to start in DAEMON mode.\n\t\tUse mode -g to stop gracefully.\nreload\t\tReload codes.\n\t\tUse mode -g to reload gracefully.\nstatus\t\tGet worker status.\n\t\tUse mode -d to show live status.\n";

        if (!isset($argv[1]) || !in_array($argv[1], $commands)) {
            if (isset($argv[1])) {
                static::safeEcho('Unknown command: '.$argv[1]."\n");
            }

            exit($usage);
        }

        $startFile = $argv[0];

        // Get command.
        $command = trim($argv[1]);
        $command2 = isset($argv[2]) ? $argv[2] : '';

        // Start command.
        $mode = '';

        if ($command === 'start') {
            if ($command2 === '-d' || static::$daemonize) {
                $mode = 'in DAEMON mode';
            } else {
                $mode = 'in DEBUG mode';
            }
        }

        static::log("Putao[$startFile] $command $mode");

        // Get master process PID.
        $masterPid = is_file(static::$pidFile) ? file_get_contents(static::$pidFile) : 0;
        $masterIsAlive = $masterPid && Process::kill($masterPid, 0);

        // Master is still alive?
        if ($masterIsAlive) {
            if ($command === 'start') {
                static::log("Putao[$startFile] already running");
                exit;
            }
        } elseif ($command !== 'start' && $command !== 'restart') {
            static::log("Putao[$startFile] not run");
            exit;
        }

        // Execute command.
        switch ($command) {
            case 'start':
                if ($command2 === '-d') {
                    static::$daemonize = true;
                }

                break;
            case 'restart':
            case 'stop':

                 if ($command2 === '-g') {
                     static::$_gracefulStop = true;
                     $sig = SIGTERM;
                     static::log("Putao[$startFile] is gracefully stopping ...");
                 } else {
                     static::$_gracefulStop = false;
                     $sig = SIGINT;
                     static::log("Putao[$startFile] is stopping ...");
                 }
                // Send stop signal to master process.
                $masterPid && Process::kill($masterPid);
                // Timeout.
                $timeout = 5;
                $startTime = time();
                // Check master process is still alive?
                while (1) {
                    $masterIsAlive = $masterPid && posix_kill($masterPid, 0);

                    if ($masterIsAlive) {
                        // Timeout?
                        if (!static::$_gracefulStop && time() - $startTime >= $timeout) {
                            static::log("Putao[$startFile] stop fail");
                            exit;
                        }
                        // Waiting amoment.
                        usleep(10000);
                        continue;
                    }

                    // Stop success.
                    static::log("Putao[$startFile] stop success");

                    if ($command === 'stop') {
                        exit(0);
                    }

                    if ($command2 === '-d') {
                        static::$daemonize = true;
                    }

                    break;
                }

                break;
            case 'reload':
                if ($command2 === '-g') {
                    $sig = SIGQUIT;
                } else {
                    $sig = SIGUSR1;
                }

                Process::kill($masterPid, $sig);

                exit;
            default:
                if (isset($command)) {
                    static::safeEcho('Unknown command: '.$command."\n");
                }
                exit($usage);
        }
    }

    /**
     * Check sapi.
     */
    protected static function checkSapiEnv()
    {
        // Only for cli.
        if (php_sapi_name() != 'cli') {
            exit("only run in command line mode \n");
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            self::$_OS = OS_TYPE_WINDOWS;
        }
    }

    /**
     * Log.
     *
     * @param string $msg
     */
    public static function log($msg)
    {
        $msg = $msg."\n";

        if (!static::$daemonize) {
            static::safeEcho($msg);
        }

        file_put_contents((string) static::$logFile, date('Y-m-d H:i:s').' '.'pid:'
            .(static::$_OS === OS_TYPE_LINUX ? posix_getpid() : 1).' '.$msg, FILE_APPEND | LOCK_EX);
    }
}
