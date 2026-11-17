<?php
route('POST',   '/events',      'handle_create_event');
route('GET',    '/events',      'handle_list_my_events');
route('GET',    '/events/open', 'handle_list_open_events');
route('GET',    '/events/:id',  'handle_get_event');
route('PATCH',  '/events/:id',  'handle_update_event');
route('DELETE', '/events/:id',  'handle_cancel_event');
