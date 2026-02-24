<?php

return [
    // Start with one store; later you can extend this list without code changes.
    'shop_ids' => [5, 4, 7, 3],

    'queue' => 'bulk_ops',
    'timeout_seconds' => 900,
    'poll_seconds' => 5,
    'sample_limit' => 20,

    'minicrm' => [
        'endpoint' => env('MINICRM_ENDPOINT', 'https://r3.minicrm.ro/Api/Signup'),
        'signup_page' => env('MINICRM_SIGNUP_PAGE', 'https://lustreled.ro/email-from-gmail'),
        'max_comment_length' => 2000,

        // Map by shop id. Extend this when you provide hashes for other shops.
        'forms' => [
            3 => [
                'form_hash' => '76759-0blmutyl160p6cdqco4a1q6qwy6g7f',
                'todo_comment_field' => 'ToDo[3547][Comment]',
                'contact_email_field' => 'Contact[3544][Email]',
                'contact_name_field' => 'Contact[3544][Name]',
            ],
            4 => [
                'form_hash' => '76759-03qa4qb3z52lbkqkh3w40dmoo2uvka',
                'todo_comment_field' => 'ToDo[3535][Comment]',
                'contact_email_field' => 'Contact[3532][Email]',
                'contact_name_field' => 'Contact[3532][Name]',
            ],
            5 => [
                'form_hash' => '76759-2c7t18qsif07swig5ii610pdrgrcvw',
                'todo_comment_field' => 'ToDo[3541][Comment]',
                'contact_email_field' => 'Contact[3538][Email]',
                'contact_name_field' => 'Contact[3538][Name]',
            ],
            7 => [
                'form_hash' => '76759-0wdt57u45w0kgedadx3d03ndiwqyvj',
                'todo_comment_field' => 'ToDo[3529][Comment]',
                'contact_email_field' => 'Contact[3526][Email]',
                'contact_name_field' => 'Contact[3526][Name]',
            ],
        ],
    ],
];
