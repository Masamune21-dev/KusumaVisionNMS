<?php

namespace App\Services\Telnet;

use App\Models\SnmpOlt;
use App\Support\Telnet\TelnetIacFilter;
use App\Support\Telnet\TelnetTicket;
use Illuminate\Support\Facades\Log;
use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\FrameInterface;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\Loop;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use React\Socket\SocketServer;

/**
 * Bridges browser WebSocket connections to OLT telnet (raw TCP).
 *
 * Flow per connection: HTTP upgrade handshake -> verify encrypted ticket -> dial
 * the OLT telnet port -> pipe bytes both ways. Inbound telnet bytes are passed
 * through {@see TelnetIacFilter} so the terminal sees clean output and the proxy
 * answers option negotiation. Stored CLI credentials are auto-typed at the login
 * and password prompts.
 */
class TelnetProxyServer
{
    private const WS_MAGIC = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    public function run(string $host, int $port): void
    {
        $socket = new SocketServer("{$host}:{$port}");
        $socket->on('connection', fn (ConnectionInterface $conn) => $this->handleConnection($conn));
        $socket->on('error', fn (\Throwable $e) => Log::warning('telnet-proxy socket error: '.$e->getMessage()));

        Loop::get()->run();
    }

    private function handleConnection(ConnectionInterface $conn): void
    {
        $ctx = (object) [
            'handshaked' => false,
            'headerBuf' => '',
            'msgBuffer' => null,
            'telnet' => null,
            'pending' => '',
            'iac' => new TelnetIacFilter,
            'loginStage' => 0,
            'loginBuf' => '',
            'username' => '',
            'password' => '',
        ];

        $conn->on('data', function (string $data) use ($conn, $ctx) {
            if (! $ctx->handshaked) {
                $ctx->headerBuf .= $data;

                if (strlen($ctx->headerBuf) > 8192) {
                    $conn->end("HTTP/1.1 400 Bad Request\r\n\r\n");

                    return;
                }

                $pos = strpos($ctx->headerBuf, "\r\n\r\n");
                if ($pos === false) {
                    return;
                }

                $header = substr($ctx->headerBuf, 0, $pos);
                $leftover = substr($ctx->headerBuf, $pos + 4);

                $this->completeHandshake($conn, $ctx, $header, $leftover);

                return;
            }

            if ($ctx->msgBuffer) {
                $ctx->msgBuffer->onData($data);
            }
        });

        $conn->on('close', function () use ($ctx) {
            if ($ctx->telnet) {
                $ctx->telnet->close();
            }
        });

        $conn->on('error', fn () => $conn->close());
    }

    private function completeHandshake(ConnectionInterface $conn, object $ctx, string $header, string $leftover): void
    {
        $lines = preg_split('/\r\n/', $header);
        $requestLine = array_shift($lines) ?? '';
        $headers = [];
        foreach ($lines as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }

        $key = $headers['sec-websocket-key'] ?? null;
        $isUpgrade = strtolower($headers['upgrade'] ?? '') === 'websocket';

        if (! $key || ! $isUpgrade || ! preg_match('#^GET\s+(\S+)\s+HTTP/1\.1$#', $requestLine, $m)) {
            $conn->end("HTTP/1.1 400 Bad Request\r\n\r\n");

            return;
        }

        parse_str((string) parse_url($m[1], PHP_URL_QUERY), $query);
        $token = (string) ($query['token'] ?? '');
        $ticket = $token !== '' ? TelnetTicket::verify($token) : null;

        if ($ticket === null) {
            $conn->end("HTTP/1.1 401 Unauthorized\r\n\r\n");

            return;
        }

        $olt = SnmpOlt::find($ticket['o']);
        if (! $olt || $olt->cli_transport !== 'telnet' || ! $olt->cli_username || ! $olt->cli_password) {
            $conn->end("HTTP/1.1 403 Forbidden\r\n\r\n");

            return;
        }

        $accept = base64_encode(sha1($key.self::WS_MAGIC, true));
        $conn->write(
            "HTTP/1.1 101 Switching Protocols\r\n".
            "Upgrade: websocket\r\n".
            "Connection: Upgrade\r\n".
            "Sec-WebSocket-Accept: {$accept}\r\n\r\n"
        );
        $ctx->handshaked = true;
        $ctx->username = (string) $olt->cli_username;
        $ctx->password = (string) $olt->cli_password;

        $this->setupMessageBuffer($conn, $ctx);
        $this->dialTelnet($conn, $ctx, $olt, $ticket['u']);

        if ($leftover !== '' && $ctx->msgBuffer) {
            $ctx->msgBuffer->onData($leftover);
        }
    }

    private function setupMessageBuffer(ConnectionInterface $conn, object $ctx): void
    {
        $ctx->msgBuffer = new MessageBuffer(
            new CloseFrameChecker,
            function (MessageInterface $message) use ($ctx) {
                $payload = $message->getPayload();
                if ($ctx->telnet) {
                    $ctx->telnet->write($payload);
                } else {
                    $ctx->pending .= $payload;
                }
            },
            function (FrameInterface $frame) use ($conn) {
                $opcode = $frame->getOpcode();
                if ($opcode === Frame::OP_CLOSE) {
                    $conn->close();
                } elseif ($opcode === Frame::OP_PING) {
                    $this->wsSend($conn, $frame->getPayload(), Frame::OP_PONG);
                }
            },
            true, // server: expect masked client frames
        );
    }

    private function dialTelnet(ConnectionInterface $conn, object $ctx, SnmpOlt $olt, int $userId): void
    {
        $port = $olt->cli_port ?: $olt->defaultCliPort();
        $target = "{$olt->ip}:{$port}";
        $this->wsSend($conn, "\r\n[proxy] menghubungkan ke {$olt->name} ({$target})...\r\n");

        $connector = new Connector(['timeout' => (int) config('telnet.connect_timeout', 10)]);

        $connector->connect($target)->then(
            function (ConnectionInterface $telnet) use ($conn, $ctx, $olt, $userId) {
                $ctx->telnet = $telnet;
                Log::info("telnet-proxy: user {$userId} connected to OLT {$olt->id} ({$olt->ip})");
                $this->wsSend($conn, "[proxy] tersambung. Auto-login sebagai {$ctx->username}...\r\n");

                if ($ctx->pending !== '') {
                    $telnet->write($ctx->pending);
                    $ctx->pending = '';
                }

                $telnet->on('data', function (string $data) use ($conn, $ctx) {
                    [$clean, $reply] = $ctx->iac->feed($data);
                    if ($reply !== '') {
                        $ctx->telnet->write($reply);
                    }
                    if ($clean !== '') {
                        $this->autoLogin($ctx, $clean);
                        $this->wsSend($conn, $clean);
                    }
                });

                $telnet->on('close', fn () => $conn->close());
                $telnet->on('error', function (\Throwable $e) use ($conn) {
                    $this->wsSend($conn, "\r\n[proxy] telnet error: {$e->getMessage()}\r\n");
                    $conn->close();
                });
            },
            function (\Throwable $e) use ($conn, $target) {
                $this->wsSend($conn, "\r\n[proxy] gagal connect ke {$target}: {$e->getMessage()}\r\n");
                $conn->close();
            },
        );
    }

    private function autoLogin(object $ctx, string $clean): void
    {
        if ($ctx->loginStage >= 2) {
            return;
        }

        $ctx->loginBuf = substr($ctx->loginBuf.strtolower($clean), -256);

        if ($ctx->loginStage === 0 && (str_contains($ctx->loginBuf, 'login') || str_contains($ctx->loginBuf, 'username'))) {
            $ctx->telnet->write($ctx->username."\n");
            $ctx->loginStage = 1;
            $ctx->loginBuf = '';
        } elseif ($ctx->loginStage === 1 && str_contains($ctx->loginBuf, 'password')) {
            $ctx->telnet->write($ctx->password."\n");
            $ctx->loginStage = 2;
            $ctx->loginBuf = '';
        }
    }

    private function wsSend(ConnectionInterface $conn, string $payload, int $opcode = Frame::OP_BINARY): void
    {
        $conn->write((new Frame($payload, true, $opcode))->getContents());
    }
}
