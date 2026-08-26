<?php

namespace Database\Seeders;

use App\Models\ContractClauseTemplate;
use Illuminate\Database\Seeder;

class ContractClauseTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $clauses = [
            // Fixed clauses (always included)
            ['type' => 'fixed', 'category' => 'general', 'content' => 'يقر الطرفان بأهليتهما القانونية للتعاقد.', 'sort_order' => 1],
            ['type' => 'fixed', 'category' => 'scope', 'content' => 'يلتزم الطرف الأول بتقديم الخدمات المتفق عليها وفقاً للمواصفات المرفقة.', 'sort_order' => 2],
            ['type' => 'fixed', 'category' => 'payment', 'content' => 'يلتزم الطرف الثاني بسداد القيمة المتفق عليها وفقاً لجدول الدفع المرفق.', 'sort_order' => 3],
            ['type' => 'fixed', 'category' => 'confidentiality', 'content' => 'يلتزم الطرفان بالحفاظ على سرية المعلومات المتبادلة وعدم إفشائها لأي طرف ثالث.', 'sort_order' => 4],

            // Optional clauses (can be toggled)
            ['type' => 'optional', 'category' => 'termination', 'content' => 'يحق لأي من الطرفين إنهاء العقد بإشعار خطي قبل 30 يوماً.', 'sort_order' => 5],
            ['type' => 'optional', 'category' => 'liability', 'content' => 'لا تتحمل الشركة مسؤولية أي أضرار غير مباشرة ناتجة عن استخدام الخدمة.', 'sort_order' => 6],
            ['type' => 'optional', 'category' => 'ip', 'content' => 'جميع حقوق الملكية الفكرية للمنتجات المسلمة تبقى مملوكة للطرف الأول.', 'sort_order' => 7],
            ['type' => 'optional', 'category' => 'renewal', 'content' => 'يجدد العقد تلقائياً لمدة مماثلة ما لم يخطر أحد الطرفين الآخر برغبته في عدم التجديد.', 'sort_order' => 8],
            ['type' => 'optional', 'category' => 'dispute', 'content' => 'في حالة وجود نزاع، يتم حله ودياً خلال 30 يوماً، وإلا يُحال إلى التحكيم.', 'sort_order' => 9],
            ['type' => 'optional', 'category' => 'governing_law', 'content' => 'يسري على هذا العقد أحكام القانون السعودي.', 'sort_order' => 10],
            ['type' => 'optional', 'category' => 'tax', 'content' => 'قيمة العقد المذكورة أعلاه لا تشمل قيمة الضريبة المضافة.', 'sort_order' => 11],
        ];

        foreach ($clauses as $clause) {
            ContractClauseTemplate::create($clause);
        }
    }
}
