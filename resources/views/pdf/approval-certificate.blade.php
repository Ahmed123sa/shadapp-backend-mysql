<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <title>شهادة موافقة - {{ $approval->reference_no }}</title>
    <style>
        body { font-family: 'dejavusans'; font-size: 12px; color: #1a1a1a; line-height: 2; margin: 0; padding: 20px; }
        h1 { text-align: center; font-size: 18px; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid #2563eb; color: #2563eb; }
        .meta { margin-bottom: 20px; }
        .meta p { margin: 4px 0; font-size: 11px; color: #555; }
        .meta strong { color: #1a1a1a; }
        .section { margin: 20px 0; }
        .section h2 { font-size: 14px; color: #2563eb; margin-bottom: 8px; border-bottom: 1px solid #ddd; padding-bottom: 4px; }
        .file-item { margin: 6px 0; padding: 6px; background: #f8fafc; border-right: 3px solid #f59e0b; font-size: 11px; }
        .signatures { margin-top: 40px; padding-top: 20px; border-top: 1px solid #ccc; text-align: center; }
        .sig-box { width: 45%; text-align: center; display: inline-block; vertical-align: top; }
        .sig-box h3 { font-size: 13px; margin-bottom: 8px; color: #333; }
        .sig-box .sig-text { font-size: 16px; color: #2563eb; margin: 8px 0; min-height: 30px; }
        .sig-box .sig-image { max-height: 60px; max-width: 100%; margin: 8px 0; }
        .sig-box .date { font-size: 10px; color: #888; }
        .status-badge { display: inline-block; padding: 2px 12px; border-radius: 12px; font-size: 11px; background: #d1fae5; color: #065f46; }
        .footer { text-align: center; font-size: 9px; color: #aaa; padding-top: 20px; border-top: 1px solid #eee; margin-top: 30px; }
    </style>
</head>
<body>
    <h1>شهادة موافقة</h1>

    <div class="meta">
        <p><strong>رقم المرجع:</strong> {{ $approval->reference_no }}</p>
        <p><strong>العنوان:</strong> {{ $approval->title }}</p>
        @if($approval->description)
        <p><strong>الوصف:</strong> {{ $approval->description }}</p>
        @endif
        <p><strong>تاريخ الإنشاء:</strong> {{ $approval->created_at->format('Y-m-d H:i') }}</p>
        <p><strong>تاريخ الاعتماد:</strong> {{ $approval->responded_at ? $approval->responded_at->format('Y-m-d H:i') : '' }}</p>
        <p><strong>الحالة:</strong> <span class="status-badge">تمت الموافقة</span></p>
        <p><strong>مقدم الطلب:</strong> {{ $requester->name ?? '' }}</p>
        <p><strong>العميل:</strong> {{ $client->company_name ?? '' }}</p>
    </div>

    @if($files->count() > 0)
    <div class="section">
        <h2>المرفقات</h2>
        @foreach($files as $file)
        <div class="file-item">
            <strong>{{ $file->name }}</strong>
            @if($file->type)
            <span style="color:#888;font-size:10px;">({{ $file->type }})</span>
            @endif
            @if($file->size)
            <span style="color:#888;font-size:10px;"> — {{ round($file->size / 1024) }} KB</span>
            @endif
        </div>
        @endforeach
    </div>
    @endif

    <div class="signatures">
        <div class="sig-box">
            <h3>العميل</h3>
            @if($signatureIsImage && $signatureImagePath)
            <img src="{{ $signatureImagePath }}" class="sig-image" alt="توقيع العميل" />
            @elseif($approval->signature)
            <div class="sig-text">{{ $approval->signature }}</div>
            @else
            <div class="sig-text" style="color:#ccc;">________________</div>
            @endif
            <div class="date">{{ $client->company_name ?? '' }}</div>
        </div>
        <div class="sig-box">
            <h3>مقدم الطلب</h3>
            <div class="sig-text">{{ $requester->name ?? '' }}</div>
            <div class="date">{{ $approval->created_at->format('Y-m-d') }}</div>
        </div>
    </div>

    <div class="footer">ShadApp — شهادة موافقة رقم {{ $approval->reference_no }} — تم إنشاؤه إلكترونياً</div>
</body>
</html>
