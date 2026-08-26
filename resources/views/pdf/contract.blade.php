<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <title>{{ $contract->title }}</title>
    <style>
        body { font-family: 'dejavusans'; font-size: 12px; color: #1a1a1a; line-height: 2; margin: 0; padding: 0; }
        h1 { text-align: center; font-size: 18px; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid #333; }
        .meta { margin-bottom: 20px; }
        .meta p { margin: 4px 0; font-size: 11px; color: #555; }
        .clauses { margin: 20px 0; }
        .clause { margin-bottom: 12px; padding: 8px; border-right: 3px solid #2563eb; background: #f8fafc; }
        .clause .label { font-weight: bold; font-size: 11px; color: #2563eb; margin-bottom: 2px; }
        .signatures { margin-top: 40px; padding-top: 20px; border-top: 1px solid #ccc; }
        .sig-box { width: 45%; text-align: center; display: inline-block; vertical-align: top; }
        .sig-box h3 { font-size: 13px; margin-bottom: 8px; color: #333; }
        .sig-box .sig-image { max-width: 180px; max-height: 60px; margin: 8px auto; display: block; }
        .sig-box .sig-text { font-size: 16px; color: #2563eb; margin: 8px 0; min-height: 30px; }
        .sig-box .date { font-size: 10px; color: #888; }
        .footer { text-align: center; font-size: 9px; color: #aaa; padding-top: 20px; }
    </style>
</head>
<body>
    <h1>{{ $contract->title }}</h1>

    <div class="meta">
        <p><strong>رقم العقد:</strong> #{{ $contract->id }}</p>
        <p><strong>العميل:</strong> {{ $client->company_name }}</p>
        <p><strong>مدير الحساب:</strong> {{ $manager->name ?? '' }}</p>
        @if($contract->value > 0)
        <p><strong>قيمة العقد:</strong> {{ number_format($contract->value, 2) }} ر.س</p>
        @if($taxPercentage > 0)
        <p><strong>الضريبة المضافة ({{ $taxPercentage }}%):</strong> {{ number_format($taxAmount, 2) }} ر.س</p>
        <p><strong>الإجمالي شامل الضريبة:</strong> {{ number_format($contract->value + $taxAmount, 2) }} ر.س</p>
        @endif
        @endif
        @if($contract->start_date)
        <p><strong>مدة العقد:</strong> من {{ $contract->start_date }} @if($contract->end_date) إلى {{ $contract->end_date }} @endif</p>
        @endif
        <p><strong>تاريخ الإنشاء:</strong> {{ $contract->created_at->format('Y-m-d') }}</p>
    </div>

    @if($contract->clauses->count() > 0)
    <div class="clauses">
        <h2 style="font-size:14px;margin-bottom:10px;">بنود العقد</h2>
        @foreach($contract->clauses as $i => $clause)
        <div class="clause">
            <div class="label">بند {{ $i + 1 }} @if($clause->type === 'fixed') (ثابت) @elseif($clause->type === 'optional') (اختياري) @endif</div>
            <div>{{ $clause->content }}</div>
        </div>
        @endforeach
    </div>
    @endif

    @if($requiredDocuments->count() > 0)
    <div class="clauses" style="margin-top:20px;">
        <h2 style="font-size:14px;margin-bottom:10px;">المستندات المطلوبة من العميل</h2>
        @foreach($requiredDocuments as $i => $doc)
        <div class="clause" style="border-right-color: #f59e0b;">
            <div class="label" style="color:#f59e0b;">مستند {{ $i + 1 }}</div>
            <div>{{ $doc->name }} @if($doc->is_required) <span style="color:#dc2626;">*</span> @endif</div>
            @if($doc->description)
            <div style="font-size:10px;color:#888;margin-top:2px;">{{ $doc->description }}</div>
            @endif
        </div>
        @endforeach
    </div>
    @endif

    <div class="signatures" style="text-align: center;">
        <div class="sig-box">
            <h3>الطرف الأول (العميل)</h3>
            @if($clientSignatureIsImage && $clientImagePath)
                <img src="{{ $clientImagePath }}" class="sig-image" alt="توقيع العميل" />
            @elseif($clientSignature)
                <div class="sig-text">{{ $clientSignature }}</div>
            @else
                <div class="sig-text" style="color:#ccc;">________________</div>
            @endif
            <div class="date">{{ $client->company_name }} — {{ $contract->client_signed_at ? $contract->client_signed_at->format('Y-m-d') : '' }}</div>
        </div>
        <div class="sig-box">
            <h3>الطرف الثاني (الشركة)</h3>
            @if($companySignature)
                <div class="sig-text">{{ $companySignature }}</div>
            @else
                <div class="sig-text" style="color:#ccc;">________________</div>
            @endif
            <div class="date">{{ $manager->name ?? '' }} — {{ $contract->company_signed_at ? $contract->company_signed_at->format('Y-m-d') : '' }}</div>
        </div>
    </div>

    <div class="footer">ShadApp — عقد رقم {{ $contract->id }} — تم إنشاؤه إلكترونياً</div>
</body>
</html>
