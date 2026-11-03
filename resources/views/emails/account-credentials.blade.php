<!DOCTYPE html>
<html><body style="font-family: sans-serif; line-height: 1.6;">
<div dir="rtl" style="text-align: right;">
<h2>مرحباً {{ $name }}</h2>
<p>تم إنشاء حسابك في منصة ShadApp بصفة: {{ $roleLabel }}.</p>
<p>بيانات تسجيل الدخول:</p>
<ul>
<li>البريد الإلكتروني: {{ $email }}</li>
<li>كلمة المرور: {{ $password }}</li>
</ul>
<p>نوصي بتغيير كلمة المرور بعد أول تسجيل دخول.</p>
</div>
<hr style="margin: 24px 0; border: 0; border-top: 1px solid #dddddd;">
<div dir="ltr" style="text-align: left;">
<h2>Welcome {{ $name }}</h2>
<p>Your ShadApp account has been created with the role: {{ $roleLabel }}.</p>
<p>Your sign-in details:</p>
<ul>
<li>Email: {{ $email }}</li>
<li>Password: {{ $password }}</li>
</ul>
<p>We recommend changing your password after your first sign-in.</p>
</div>
</body></html>
