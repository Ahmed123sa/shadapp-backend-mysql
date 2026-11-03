<!DOCTYPE html>
<html><body style="font-family: sans-serif; line-height: 1.6;">
<div dir="rtl" style="text-align: right;">
<h2>تم اعتماد العقد من العميل</h2>
<p>السلام عليكم،</p>
<p>قام العميل باعتماد العقد "{{ $contract->title }}".</p>
<p>تاريخ الاعتماد: {{ \App\Support\DisplayTime::format($contract->client_signed_at ?? now()) }}</p>
<p>يرجى مراجعة العقد وإكمال الاعتماد النهائي.</p>
</div>
<hr style="margin: 24px 0; border: 0; border-top: 1px solid #dddddd;">
<div dir="ltr" style="text-align: left;">
<h2>The client approved the contract</h2>
<p>Hello,</p>
<p>The client approved the contract "{{ $contract->title }}".</p>
<p>Approval date: {{ \App\Support\DisplayTime::format($contract->client_signed_at ?? now()) }}</p>
<p>Please review the contract and complete the final approval.</p>
</div>
</body></html>
