<?php

return [
    'custom_fields'		        => 'حقول مخصصة',
    'manage'                    => 'Manage',
    'field'		                => 'حقل',
    'about_fieldsets_title'		=> 'حول مجموعة الحقول',
    'about_fieldsets_text'		=> 'Fieldsets allow you to create groups of custom fields that are frequently re-used for specific asset model types.',
    'custom_format'             => 'Custom Regex format...',
    'encrypt_field'      	        => 'تشفير قيمة هذا الحقل في قاعدة البيانات',
    'encrypt_field_help'      => 'تحذير: تشفير الحقل يجعله غير قابل للبحث.',
    'encrypted'      	        => 'مشفّر',
    'fieldset'      	        => 'مجموعة حقول',
    'qty_fields'      	      => 'الحقول الكمية',
    'fieldsets'      	        => 'مجموعات الحقول',
    'fieldset_name'           => 'إسم مجموعة الحقول',
    'field_name'              => 'إسم الحقل',
    'field_values'            => 'قيم الحقول',
    'field_values_help'       => 'اضف خيارات قابلة للاختيار، واحد في كل سطر. سيتم تجاهل الأسطر الفارغة ما عدا السطر الأول.',
    'field_element'           => 'عنصر النموذج',
    'field_element_short'     => 'عنصر',
    'field_format'            => 'صيغة',
    'field_custom_format'     => 'صيغة Regex مخصصة',
    'field_custom_format_help'     => 'هذا الحقل يسمح لك باستخدام تعبير regex للتحقق من صحة النص. فإنه ينبغي أن تبدأ مع ":regex" - على سبيل المثال، للتحقق من أن قيمة الحقل المخصص يحتوي IMEI صالحة (15 خانة رقمية)، يمكنك استخدام <code>regex:/^[0-9]{15}$/</code>.',
    'required'   		          => 'إجباري',
    'req'   		              => 'اجباري',
    'used_by_models'   		    => 'مستخدم في الموديلات',
    'order'   		            => 'طلب',
    'create_fieldset'         => 'مجموعة حقول جديدة',
    'create_fieldset_title' => 'Create a new fieldset',
    'create_field'            => 'حقل جديد مخصص',
    'create_field_title' => 'Create a new custom field',
    'value_encrypted'      	        => 'هذا الحقل مشفر في قاعدة البيانات. يمكن قرائته من قبل مدراء النظام فقط',
    'show_in_email'     => 'هل تريد تضمين قيمة هذا الحقل في رسائل البريد الإلكتروني المرسلة إلى المستخدم؟ لا يمكن تضمين الحقول المشفرة في رسائل البريد الإلكتروني.',
    'help_text' => 'Help Text',
    'help_text_description' => 'This is optional text that will appear below the form elements while editing an asset to provide context on the field.',
    'about_custom_fields_title' => 'About Custom Fields',
    'about_custom_fields_text' => 'Custom fields allow you to add arbitrary attributes to assets.',
    'add_field_to_fieldset' => 'Add Field to Fieldset',
    'make_optional' => 'Required - click to make optional',
    'make_required' => 'Optional - click to make required',
    'reorder' => 'Reorder',
    'db_field' => 'DB Field',
    'db_convert_warning' => 'WARNING. This field is in the custom fields table as <code>:db_column</code> but should be <code>:expected</code>.',
    'is_unique' => 'يجب أن تكون هذه القيمة فريدة من نوعها عبر جميع الأصول',
    'unique' => 'غير مكرر',
    'display_in_user_view' => 'Allow the checked out user to view these values in their View Assigned Assets page',
    'display_in_user_view_table' => 'Visible to User',
];
