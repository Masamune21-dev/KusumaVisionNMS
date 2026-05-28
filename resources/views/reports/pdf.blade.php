<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $report['title'] }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 10px; color: #1e293b; margin: 0; }
        .header { border-bottom: 2px solid #0891b2; padding-bottom: 8px; margin-bottom: 12px; }
        .brand { font-size: 16px; font-weight: bold; color: #0891b2; }
        .subtitle { font-size: 9px; color: #64748b; }
        h1 { font-size: 13px; margin: 10px 0 2px; }
        .meta { font-size: 9px; color: #64748b; margin-bottom: 10px; }
        .summary { margin-bottom: 12px; }
        .summary span {
            display: inline-block; border: 1px solid #cbd5e1; border-radius: 4px;
            padding: 4px 10px; margin-right: 6px; font-size: 9px;
        }
        .summary strong { color: #0f172a; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #cbd5e1; padding: 4px 6px; text-align: left; }
        th { background: #f1f5f9; font-size: 9px; text-transform: uppercase; color: #475569; }
        td { font-size: 9px; }
        tr:nth-child(even) td { background: #f8fafc; }
        .footer { margin-top: 14px; font-size: 8px; color: #94a3b8; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <div class="brand">KusumaVision NMS</div>
        <div class="subtitle">PT BERKAH MEDIA KUSUMA VISION &middot; FTTH/GPON Network Management</div>
    </div>

    <h1>{{ $report['title'] }}</h1>
    <div class="meta">Dibuat: {{ $generatedAt }}</div>

    <div class="summary">
        @foreach ($report['summary'] as $item)
            <span>{{ $item['label'] }}: <strong>{{ $item['value'] }}</strong></span>
        @endforeach
    </div>

    <table>
        <thead>
            <tr>
                @foreach ($report['columns'] as $column)
                    <th>{{ $column['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($report['rows'] as $row)
                <tr>
                    @foreach ($report['columns'] as $column)
                        <td>{{ $row[$column['key']] ?? '' }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($report['columns']) }}" style="text-align:center; color:#94a3b8;">
                        Tidak ada data untuk filter ini.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        &copy; {{ date('Y') }} KusumaVision NMS &middot; Laporan dibuat otomatis
    </div>
</body>
</html>
