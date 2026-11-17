<?php
route('POST', '/events/:id/pay',       'handle_pay_event_professional');
route('POST', '/payments/:id/release', 'handle_release_payment');
route('GET',  '/events/:id/payments',  'handle_list_event_payments');
route('GET',  '/me/payments',          'handle_my_provider_payments');
