<?php

/*
 * Client assistant (CLIENT_ASSISTANT_PLAN.md).
 *
 * This file holds what must stay in code: the switch, the limits, the
 * placeholders each answer may use, and the default wording. The wording the
 * super admin edits lives in the assistant_questions table; "reset" copies the
 * default below back into the row, and a row deleted by mistake falls back to
 * it, so the assistant never breaks on missing data.
 *
 * Each data question has "states" (the situations the answer depends on). A
 * state lists the placeholders it may use ("allowed") and the ones that must
 * stay in the text ("required"), so an edit cannot drop the amount from an
 * answer that is only useful with the amount.
 */

return [
    // Off by default. The switch hides the assistant from clients only; the
    // super admin's settings page works either way so questions can be
    // prepared before it goes live.
    'enabled' => (bool) env('CLIENT_ASSISTANT_ENABLED', false),

    'limits' => [
        'question' => 80,
        'answer' => 300,
        'faq_answer' => 600,
        'faq_max' => 20,
        'name' => 40,
        'welcome' => 200,
    ],

    // Where an answer may send the client (a tab of their workspace).
    'targets' => ['contracts', 'payments', 'chat', 'approvals', 'files', 'meetings'],

    'settings' => [
        'name' => ['ar' => 'مساعد شاد', 'en' => 'Shad assistant'],
        'welcome' => [
            'ar' => 'أهلاً {name}. اختار سؤال من تحت وهجاوبك علطول.',
            'en' => 'Hi {name}. Pick a question below and I will answer right away.',
        ],
        'welcome_placeholders' => ['name'],
    ],

    // Contract status as the client reads it.
    'contract_status_labels' => [
        'sent' => ['ar' => 'وصلك ومستني موافقتك', 'en' => 'sent to you and waiting for your approval'],
        'edit_requested' => ['ar' => 'في انتظار تعديل الشركة', 'en' => 'waiting for the company to edit it'],
        'client_approved' => ['ar' => 'في انتظار اعتماد الشركة', 'en' => 'waiting for the company approval'],
        'company_approved' => ['ar' => 'معتمد، في انتظار الدفعة', 'en' => 'approved, waiting for the payment'],
        'completed' => ['ar' => 'ساري', 'en' => 'active'],
    ],

    'questions' => [
        'todo' => [
            'sort_order' => 1,
            'permission' => null,
            'question' => ['ar' => 'مطلوب مني إيه دلوقتي؟', 'en' => 'What do I need to do now?'],
            'states' => [
                'has_items' => [
                    'ar' => 'عندك {count} حاجة مطلوبة منك:', 'en' => 'You have {count} things to do:',
                    'allowed' => ['count'], 'required' => ['count'],
                ],
                'item_contract' => [
                    'ar' => 'عقد وصلك ومستني موافقتك.', 'en' => 'A contract is waiting for your approval.',
                    'allowed' => [], 'required' => [],
                ],
                'item_documents' => [
                    'ar' => '{count} مستند مطلوب لسه ما اترفعش.', 'en' => '{count} required documents are not uploaded yet.',
                    'allowed' => ['count'], 'required' => ['count'],
                ],
                'item_approval' => [
                    'ar' => '{count} طلب موافقة مستني ردك.', 'en' => '{count} approval requests are waiting for your answer.',
                    'allowed' => ['count'], 'required' => ['count'],
                ],
                'item_payment_overdue' => [
                    'ar' => 'قسط متأخر {amount} {currency}، كان مستحق {due_date}.',
                    'en' => 'An installment of {amount} {currency} is overdue. It was due {due_date}.',
                    'allowed' => ['amount', 'currency', 'due_date'], 'required' => ['amount', 'currency'],
                ],
                'item_payment_rejected' => [
                    'ar' => 'دفعة {amount} {currency} اترفضت. ارفع إثبات جديد.',
                    'en' => 'A payment of {amount} {currency} was rejected. Upload a new proof.',
                    'allowed' => ['amount', 'currency'], 'required' => ['amount', 'currency'],
                ],
                'nothing' => [
                    'ar' => 'مفيش حاجة مطلوبة منك دلوقتي. هنبلغك أول ما يجي جديد.',
                    'en' => 'Nothing is needed from you right now. We will tell you as soon as something comes up.',
                    'allowed' => [], 'required' => [],
                ],
            ],
        ],
        'balance' => [
            'sort_order' => 2,
            'permission' => 'can_view_payments',
            'question' => ['ar' => 'فاضل عليّا كام؟', 'en' => 'How much do I still owe?'],
            'states' => [
                'default' => [
                    'ar' => 'فاضل عليك {remaining} {currency} من قيمة العقد.',
                    'en' => 'You still owe {remaining} {currency} of the contract value.',
                    'allowed' => ['remaining', 'paid', 'total', 'currency'], 'required' => ['remaining', 'currency'],
                ],
                'fully_paid' => [
                    'ar' => 'مفيش عليك أي مبلغ متبقي. اتدفع {paid} {currency} بالكامل.',
                    'en' => 'Nothing is left to pay. You paid {paid} {currency} in full.',
                    'allowed' => ['paid', 'total', 'currency'], 'required' => [],
                ],
                'no_contract' => [
                    'ar' => 'لسه مفيش عقد معتمد عليه دفعات. مدير حسابك هيبعته قريب.',
                    'en' => 'There is no approved contract with payments yet. Your account manager will send it soon.',
                    'allowed' => [], 'required' => [],
                ],
            ],
        ],
        'next_installment' => [
            'sort_order' => 3,
            'permission' => 'can_view_payments',
            'question' => ['ar' => 'إمتى القسط الجاي وبكام؟', 'en' => 'When is my next installment?'],
            'states' => [
                'upcoming' => [
                    'ar' => 'القسط الجاي {amount} {currency}، مستحق يوم {due_date}.',
                    'en' => 'Your next installment is {amount} {currency}, due on {due_date}.',
                    'allowed' => ['amount', 'currency', 'due_date'], 'required' => ['amount', 'currency', 'due_date'],
                ],
                'overdue' => [
                    'ar' => 'عندك قسط متأخر {amount} {currency}، كان مستحق {due_date}.',
                    'en' => 'You have an overdue installment of {amount} {currency}. It was due on {due_date}.',
                    'allowed' => ['amount', 'currency', 'due_date'], 'required' => ['amount', 'currency'],
                ],
                'none' => [
                    'ar' => 'مفيش أقساط مجدولة عليك دلوقتي.',
                    'en' => 'There are no scheduled installments right now.',
                    'allowed' => [], 'required' => [],
                ],
            ],
        ],
        'contract_status' => [
            'sort_order' => 4,
            'permission' => 'can_view_contracts',
            'question' => ['ar' => 'العقد بتاعي وصل لفين؟', 'en' => 'Where is my contract?'],
            'states' => [
                'default' => [
                    'ar' => 'عقد "{title}": {status}.',
                    'en' => 'Contract "{title}": {status}.',
                    'allowed' => ['title', 'status'], 'required' => ['status'],
                ],
                'no_contract' => [
                    'ar' => 'لسه مفيش عقد. مدير حسابك هيبعته قريب.',
                    'en' => 'There is no contract yet. Your account manager will send it soon.',
                    'allowed' => [], 'required' => [],
                ],
            ],
        ],
        'next_meeting' => [
            'sort_order' => 5,
            'permission' => 'can_view_meetings',
            'question' => ['ar' => 'الاجتماع الجاي إمتى؟', 'en' => 'When is my next meeting?'],
            'states' => [
                'default' => [
                    'ar' => 'الاجتماع الجاي "{title}" يوم {date} الساعة {time}.',
                    'en' => 'Your next meeting "{title}" is on {date} at {time}.',
                    'allowed' => ['title', 'date', 'time'], 'required' => ['date'],
                ],
                'none' => [
                    'ar' => 'مفيش اجتماعات قادمة دلوقتي.',
                    'en' => 'There are no upcoming meetings right now.',
                    'allowed' => [], 'required' => [],
                ],
            ],
        ],
        'contact_manager' => [
            'sort_order' => 6,
            'permission' => 'can_chat',
            'question' => ['ar' => 'كلّم مدير حسابي', 'en' => 'Talk to my account manager'],
            'states' => [
                'default' => [
                    'ar' => 'مدير حسابك {manager}. افتح الشات وابعتله رسالة.',
                    'en' => 'Your account manager is {manager}. Open the chat and send a message.',
                    'allowed' => ['manager'], 'required' => ['manager'],
                ],
            ],
        ],
    ],
];
