<!DOCTYPE html>
<html><body style="font-family: sans-serif; line-height: 1.6;">
<div dir="rtl" style="text-align: right;">
<h2>مرحباً بك في ShadApp</h2>
<p>السلام عليكم،</p>
<p>تم إنشاء حسابك في منصة ShadApp بنجاح.</p>
<p>بيانات تسجيل الدخول:</p>
<ul>
<li>البريد الإلكتروني: {{ $client->email }}</li>
<li>كلمة المرور: {{ $password }}</li>
</ul>
<p>نوصي بتغيير كلمة المرور بعد أول تسجيل دخول.</p>
</div>
<hr style="margin: 24px 0; border: 0; border-top: 1px solid #dddddd;">
<div dir="ltr" style="text-align: left;">
<h2>Welcome to ShadApp</h2>
<p>Hello,</p>
<p>Your ShadApp account has been created successfully.</p>
<p>Your sign-in details:</p>
<ul>
<li>Email: {{ $client->email }}</li>
<li>Password: {{ $password }}</li>
</ul>
<p>We recommend changing your password after your first sign-in.</p>
</div>
</body></html>
