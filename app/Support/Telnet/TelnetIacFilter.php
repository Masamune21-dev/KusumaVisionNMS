<?php

namespace App\Support\Telnet;

/**
 * Minimal Telnet IAC negotiator/stripper for a passthrough terminal.
 *
 * Strips IAC command sequences out of the server stream (so xterm only sees real
 * output) and produces the option replies a well-behaved client would send:
 * accept remote ECHO and SUPPRESS-GO-AHEAD, refuse everything else.
 *
 * Stateful across chunks — an IAC sequence may straddle two TCP reads.
 */
class TelnetIacFilter
{
    private const IAC = 255;

    private const DONT = 254;

    private const DO = 253;

    private const WONT = 252;

    private const WILL = 251;

    private const SB = 250;

    private const SE = 240;

    private const OPT_ECHO = 1;

    private const OPT_SGA = 3;

    private const S_NORMAL = 0;

    private const S_IAC = 1;

    private const S_OPT = 2;   // after WILL/WONT/DO/DONT, expecting option byte

    private const S_SB = 3;    // inside subnegotiation

    private const S_SB_IAC = 4; // inside subnegotiation, saw IAC

    private int $state = self::S_NORMAL;

    private int $cmd = 0;

    /**
     * Feed raw server bytes.
     *
     * @return array{0:string, 1:string} [cleanBytesForTerminal, replyBytesForServer]
     */
    public function feed(string $bytes): array
    {
        $clean = '';
        $reply = '';
        $len = strlen($bytes);

        for ($i = 0; $i < $len; $i++) {
            $b = ord($bytes[$i]);

            switch ($this->state) {
                case self::S_NORMAL:
                    if ($b === self::IAC) {
                        $this->state = self::S_IAC;
                    } else {
                        $clean .= chr($b);
                    }
                    break;

                case self::S_IAC:
                    if ($b === self::IAC) {
                        $clean .= chr(self::IAC); // escaped 0xFF data byte
                        $this->state = self::S_NORMAL;
                    } elseif (in_array($b, [self::WILL, self::WONT, self::DO, self::DONT], true)) {
                        $this->cmd = $b;
                        $this->state = self::S_OPT;
                    } elseif ($b === self::SB) {
                        $this->state = self::S_SB;
                    } else {
                        // GA / NOP / other single-byte commands — drop
                        $this->state = self::S_NORMAL;
                    }
                    break;

                case self::S_OPT:
                    $reply .= $this->negotiate($this->cmd, $b);
                    $this->state = self::S_NORMAL;
                    break;

                case self::S_SB:
                    if ($b === self::IAC) {
                        $this->state = self::S_SB_IAC;
                    }
                    // otherwise: subnegotiation payload, ignore
                    break;

                case self::S_SB_IAC:
                    // IAC SE ends the subnegotiation; IAC IAC is escaped data (stay in SB)
                    $this->state = $b === self::SE ? self::S_NORMAL : self::S_SB;
                    break;
            }
        }

        return [$clean, $reply];
    }

    private function negotiate(int $cmd, int $opt): string
    {
        if ($cmd === self::WILL) {
            // Accept remote echo + suppress-go-ahead so the terminal behaves normally.
            if ($opt === self::OPT_ECHO || $opt === self::OPT_SGA) {
                return chr(self::IAC).chr(self::DO).chr($opt);
            }

            return chr(self::IAC).chr(self::DONT).chr($opt);
        }

        if ($cmd === self::DO) {
            if ($opt === self::OPT_SGA) {
                return chr(self::IAC).chr(self::WILL).chr($opt);
            }

            return chr(self::IAC).chr(self::WONT).chr($opt);
        }

        // WONT / DONT need no reply.
        return '';
    }
}
