<?php

namespace App\Services\Hsgq;

use App\Models\SnmpOlt;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Klien API JSON antarmuka web OLT HSGQ (HS Fiber 50224).
 *
 * Mekanisme login identik di lima klien pihak ketiga yang dipakai nyata (bot Telegram HSGQ):
 * `POST /userlogin?form=login` `{"method":"set","param":{"name","key":md5(user:pass),"value":base64(pass),
 * "captcha_v":"","captcha_f":""}}` → token di header respons `X-Token`, dikirim balik di setiap request.
 * Token basi dijawab `message: "Token Check Failed"` (atau `code: -1`) → login ulang sekali.
 *
 * Keamanan: server NMS yang membuka koneksi HTTP ke `ip:web_port` isian pengguna (termasuk partner), jadi
 * setiap request lewat {@see self::assertTargetAllowed()} (menolak loopback, link-local/metadata cloud,
 * multicast, dan alamat cadangan), host = IP OLT, redirect TIDAK diikuti, timeout pendek, ukuran respons
 * dibatasi. Tambahkan blokir jaringan internal Anda sendiri di firewall server bila perlu.
 * Web HSGQ hanya HTTP polos — sandi lewat jaringan apa adanya, jadi akses wajib lewat VPN/jaringan mgmt.
 * Sandi & token tak pernah ditulis ke log/audit; token hanya hidup selama instance ini (satu request).
 */
class HsgqWebClient
{
    private const CONNECT_TIMEOUT_S = 5;

    private const TIMEOUT_S = 15;

    private const MAX_BODY_BYTES = 8_000_000;

    /** @var array<int, string> X-Token per id OLT */
    private array $tokens = [];

    /**
     * @return array<string, mixed> JSON respons (`code`, `message`, `data`)
     */
    public function get(SnmpOlt $olt, string $path): array
    {
        return $this->request($olt, 'GET', $path, null);
    }

    /**
     * @param  array<string, mixed>  $param  isi `param` (dibungkus `{"method":"set","param":…}`)
     * @return array<string, mixed>
     */
    public function post(SnmpOlt $olt, string $path, array $param): array
    {
        return $this->request($olt, 'POST', $path, $param);
    }

    /**
     * Login dan simpan token. Gagal → RuntimeException berpesan dari perangkat (tanpa sandi).
     */
    public function login(SnmpOlt $olt): string
    {
        $user = (string) $olt->cli_username;
        $password = (string) $olt->cli_password;

        if ($user === '' || $password === '') {
            throw new RuntimeException(__('hsgq.web_credentials_missing'));
        }

        $response = $this->send($olt, 'POST', '/userlogin?form=login', [
            'method' => 'set',
            'param' => [
                'name' => $user,
                'key' => md5($user.':'.$password),
                'value' => base64_encode($password),
                'captcha_v' => '',
                'captcha_f' => '',
            ],
        ], null);

        $json = $this->decode($response);
        $token = trim((string) $response->header('X-Token'));

        if (! self::succeeded($json) || $token === '') {
            $message = self::message($json);

            throw new RuntimeException(str_contains(strtolower($message), 'captcha')
                ? __('hsgq.web_captcha_required')
                : __('hsgq.web_login_failed', ['message' => $message !== '' ? $message : 'HTTP '.$response->status()]));
        }

        return $this->tokens[(int) $olt->id] = $token;
    }

    /**
     * Respons tulis sukses: `code: 1`, `message: "success"`, atau `status: "success"` (bervariasi antar
     * firmware menurut klien pihak ketiga).
     *
     * @param  array<string, mixed>  $json
     */
    public static function succeeded(array $json): bool
    {
        return (isset($json['code']) && (int) $json['code'] === 1)
            || strcasecmp(trim((string) ($json['message'] ?? '')), 'success') === 0
            || strcasecmp(trim((string) ($json['status'] ?? '')), 'success') === 0;
    }

    /** @param  array<string, mixed>  $json */
    public static function message(array $json): string
    {
        return mb_substr(trim(strip_tags((string) ($json['message'] ?? ''))), 0, 200);
    }

    /**
     * @param  array<string, mixed>|null  $param
     * @return array<string, mixed>
     */
    private function request(SnmpOlt $olt, string $method, string $path, ?array $param, bool $retried = false): array
    {
        $token = $this->tokens[(int) $olt->id] ?? $this->login($olt);
        $body = $param === null ? null : ['method' => 'set', 'param' => $param === [] ? (object) [] : $param];
        $json = $this->decode($this->send($olt, $method, $path, $body, $token));

        $expired = str_contains(strtolower(self::message($json)), 'token check failed')
            || (isset($json['code']) && (int) $json['code'] === -1);

        if ($expired && ! $retried) {
            unset($this->tokens[(int) $olt->id]);

            return $this->request($olt, $method, $path, $param, true);
        }

        if ($expired) {
            throw new RuntimeException(__('hsgq.web_session_expired'));
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function send(SnmpOlt $olt, string $method, string $path, ?array $body, ?string $token): Response
    {
        if ($olt->is_demo) {
            throw new RuntimeException(__('hsgq.demo_device'));
        }
        $this->assertTargetAllowed($olt);

        $request = Http::withHeaders($token !== null ? ['X-Token' => $token] : [])
            ->acceptJson()
            ->connectTimeout(self::CONNECT_TIMEOUT_S)
            ->timeout(self::TIMEOUT_S)
            ->withoutRedirecting();

        try {
            return $method === 'GET'
                ? $request->get($this->url($olt, $path))
                : $request->asJson()->post($this->url($olt, $path), $body ?? []);
        } catch (ConnectionException $exception) {
            throw new RuntimeException(__('hsgq.web_unreachable', ['message' => $exception->getMessage()]));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $body = $response->body();

        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new RuntimeException(__('hsgq.web_bad_response', ['detail' => 'too large']));
        }

        if (! $response->successful()) {
            throw new RuntimeException(__('hsgq.web_bad_response', ['detail' => 'HTTP '.$response->status()]));
        }

        $json = json_decode($body, true);

        if (! is_array($json)) {
            throw new RuntimeException(__('hsgq.web_bad_response', ['detail' => 'not JSON']));
        }

        return $json;
    }

    /**
     * Penjaga SSRF generik: alamat OLT harus IP unicast biasa — bukan loopback, link-local (termasuk
     * metadata cloud 169.254.169.254), multicast, atau rentang cadangan.
     */
    private function assertTargetAllowed(SnmpOlt $olt): void
    {
        $ip = trim((string) $olt->ip);

        $allowed = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) !== false
            && ! str_starts_with($ip, '0.')
            && ! preg_match('/^(?:22[4-9]|23\d|24\d|25[0-5])\./', $ip)
            && ! preg_match('/^(?:::|::ffff:127\.|fe[89ab]|ff)/i', $ip);

        if (! $allowed) {
            throw new RuntimeException(__('hsgq.web_target_blocked'));
        }
    }

    private function url(SnmpOlt $olt, string $path): string
    {
        $port = (int) $olt->web_port;
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException(__('hsgq.web_port_missing'));
        }

        $host = (string) $olt->ip;
        if (str_contains($host, ':')) {
            $host = '['.$host.']';
        }

        return 'http://'.$host.':'.$port.'/'.ltrim($path, '/');
    }
}
