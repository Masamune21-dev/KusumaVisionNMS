<?php

namespace App\Services\CData;

use RuntimeException;

/**
 * Walk ditolak agent karena subtree-nya memang tak ada (`noSuchObject`/`noSuchInstance`, errno
 * `SNMP::ERRNO_ERROR_IN_REPLY`) — beda dari timeout. Driver memakainya untuk menganggap tabel
 * absen sebagai kosong tanpa ikut menelan timeout: C-Data GPON punya beberapa varian firmware
 * yang tabel ONU-nya tak sama (FD1601S tak punya tabel `34592` yang ada di FD1608S).
 */
class CDataSnmpMissingOid extends RuntimeException {}
