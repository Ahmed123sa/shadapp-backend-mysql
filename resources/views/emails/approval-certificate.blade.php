<!DOCTYPE html>
<html><body style="font-family: sans-serif; line-height: 1.6;">
<div dir="rtl" style="text-align: right;">
<h2>شهادة الموافقة</h2>
<p>السلام عليكم،</p>
<p>تم إصدار شهادة موافقة للطلب "{{ $approval->title }}".</p>
<p>رقم المرجع: {{ $approval->reference_no }}</p>
<p>القرار: {{ $approval->status === 'approved' ? 'تمت الموافقة' : ($approval->status === 'rejected' ? 'مرفوض' : $approval->status) }}</p>
@if($approval->responded_at)
<p>تاريخ الاعتماد: {{ \App\Support\DisplayTime::format($approval->responded_at) }}</p>
@endif
@if($approval->certificate && $approval->certificate->certificate_url)
<p><a href="{{ $approval->certificate->certificate_url }}">تحميل الشهادة</a></p>
@endif
</div>
<hr style="margin: 24px 0; border: 0; border-top: 1px solid #dddddd;">
<div dir="ltr" style="text-align: left;">
<h2>Approval certificate</h2>
<p>Hello,</p>
<p>An approval certificate was issued for the request "{{ $approval->title }}".</p>
<p>Reference number: {{ $approval->reference_no }}</p>
<p>Decision: {{ $approval->status === 'approved' ? 'Approved' : ($approval->status === 'rejected' ? 'Rejected' : $approval->status) }}</p>
@if($approval->responded_at)
<p>Decision date: {{ \App\Support\DisplayTime::format($approval->responded_at) }}</p>
@endif
@if($approval->certificate && $approval->certificate->certificate_url)
<p><a href="{{ $approval->certificate->certificate_url }}">Download the certificate</a></p>
@endif
</div>
</body></html>
