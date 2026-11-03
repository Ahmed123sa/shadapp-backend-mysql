<!DOCTYPE html>
<html><body style="font-family: sans-serif; line-height: 1.6;">
<div dir="rtl" style="text-align: right;">
<h2>موعد اجتماع</h2>
<p>السلام عليكم،</p>
<p>تم جدولة اجتماع "{{ $meeting->title }}".</p>
<p>التاريخ: {{ \App\Support\DisplayTime::format($meeting->scheduled_at, 'Y-m-d') }}</p>
<p>الوقت: {{ \App\Support\DisplayTime::format($meeting->scheduled_at, 'H:i') }}</p>
@if($meeting->link)
<p>رابط الاجتماع: <a href="{{ $meeting->link }}">{{ $meeting->link }}</a></p>
@endif
@if($meeting->passcode)
<p>كلمة المرور: {{ $meeting->passcode }}</p>
@endif
</div>
<hr style="margin: 24px 0; border: 0; border-top: 1px solid #dddddd;">
<div dir="ltr" style="text-align: left;">
<h2>Meeting scheduled</h2>
<p>Hello,</p>
<p>The meeting "{{ $meeting->title }}" has been scheduled.</p>
<p>Date: {{ \App\Support\DisplayTime::format($meeting->scheduled_at, 'Y-m-d') }}</p>
<p>Time: {{ \App\Support\DisplayTime::format($meeting->scheduled_at, 'H:i') }}</p>
@if($meeting->link)
<p>Meeting link: <a href="{{ $meeting->link }}">{{ $meeting->link }}</a></p>
@endif
@if($meeting->passcode)
<p>Passcode: {{ $meeting->passcode }}</p>
@endif
</div>
</body></html>
