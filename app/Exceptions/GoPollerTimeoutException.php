<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Poll OLT terjadwal sengaja dibatalkan karena poller Go melewati batas waktunya — TANPA fallback PHP
 * (lihat `PollOltJob::pollViaGo()`). Kelas tersendiri supaya `PollOltJob` bisa meloloskannya melewati
 * `catch (Throwable)` yang menjalankan fallback `OltSnmpClient` untuk kegagalan poller Go lainnya.
 */
class GoPollerTimeoutException extends RuntimeException {}
