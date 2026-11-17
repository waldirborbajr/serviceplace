<?php
route('POST', '/auth/register/client',   'handle_register_client');
route('POST', '/auth/register/provider', 'handle_register_provider');
route('POST', '/auth/login',             'handle_login');
route('GET',  '/me',                     'handle_me');
