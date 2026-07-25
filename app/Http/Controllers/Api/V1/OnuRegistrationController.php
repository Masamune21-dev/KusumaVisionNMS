<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SmartOltProfileController;
use App\Models\SnmpOlt;
use App\Services\Zte\OnuRegistrationFormDefaults;
use App\Services\Zte\OnuRegistrationService;
use App\Support\SmartOltSupport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/olts/{olt}/register/options — bahan form registrasi ONU (mode dasar):
 * profil per-OLT (onu_type/tcont/vlan/ip, dengan fallback global), nilai default,
 * kapabilitas driver, dan saran ONU-id berikutnya saat slot/port diberikan.
 *
 * Registrasi hanya untuk OLT ZTE; untuk driver lain `capabilities.supports_provisioning`
 * bernilai false dan klien menyembunyikan form.
 */
class OnuRegistrationController extends Controller
{
    public function __construct(
        private readonly OnuRegistrationFormDefaults $defaults,
        private readonly OnuRegistrationService $registration,
    ) {}

    public function options(Request $request, SnmpOlt $olt): JsonResponse
    {
        $driver = SmartOltSupport::driverKey(
            $olt,
            data_get($olt->last_test_result, 'system.sys_descr'),
            data_get($olt->last_test_result, 'system.sys_object_id'),
        );

        $slot = $request->integer('slot') ?: null;
        $port = $request->integer('port') ?: null;

        return response()->json([
            'data' => [
                'capabilities' => SmartOltSupport::capabilities($driver, $olt),
                'profiles' => SmartOltProfileController::profileOptions($olt),
                'defaults' => $this->defaults->build(
                    $olt,
                    $slot,
                    $port,
                    (string) $request->query('sn', ''),
                    $request->integer('suggested_onu_id'),
                ),
            ],
        ]);
    }

    /**
     * POST /api/v1/olts/{olt}/register/preview — script CLI hasil form (tanpa menyentuh OLT).
     */
    public function preview(Request $request, SnmpOlt $olt): JsonResponse
    {
        $this->assertZte($olt);
        $data = $request->validate($this->registration->rules($olt));

        return response()->json(['data' => ['script' => $this->registration->buildScript($olt, $data)]]);
    }

    /**
     * POST /api/v1/olts/{olt}/register — simpan audit & (bila execute) eksekusi ke OLT.
     */
    public function store(Request $request, SnmpOlt $olt): JsonResponse
    {
        $this->assertZte($olt);
        $data = $request->validate($this->registration->rules($olt));
        $execute = $request->boolean('execute');

        if ($execute) {
            $this->assertCapability($olt, 'supports_cli_onu_configure');
        }

        $result = $this->registration->register($olt, $data, $execute, $request->user()?->id);

        $ok = in_array($result['status'], ['generated', 'executed'], true);

        return response()->json(['data' => $result], $ok ? 200 : 422);
    }

    private function assertZte(SnmpOlt $olt): void
    {
        $this->assertCapability($olt, 'supports_provisioning');
    }

    private function assertCapability(SnmpOlt $olt, string $capability): void
    {
        $driver = SmartOltSupport::driverKey(
            $olt,
            data_get($olt->last_test_result, 'system.sys_descr'),
            data_get($olt->last_test_result, 'system.sys_object_id'),
        );

        abort_unless(
            (bool) (SmartOltSupport::capabilities($driver, $olt)[$capability] ?? false),
            422,
            'Aksi ini tidak didukung untuk driver OLT ini.',
        );
    }
}
