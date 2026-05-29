<?php

namespace App\Console\Commands;

use App\Services\Telnet\TelnetProxyServer;
use Illuminate\Console\Command;

class TelnetProxyCommand extends Command
{
    protected $signature = 'telnet:proxy {--host= : Bind host (default config telnet.proxy.host)} {--port= : Bind port (default config telnet.proxy.port)}';

    protected $description = 'Run the WebSocket<->telnet proxy daemon for the browser terminal';

    public function handle(): int
    {
        $host = (string) ($this->option('host') ?: config('telnet.proxy.host', '127.0.0.1'));
        $port = (int) ($this->option('port') ?: config('telnet.proxy.port', 6002));

        $this->info("Telnet WebSocket proxy listening on ws://{$host}:{$port}");
        $this->comment('Press Ctrl+C to stop.');

        (new TelnetProxyServer)->run($host, $port);

        return self::SUCCESS;
    }
}
