<?php
route('GET',   '/events/:id/professionals',      'handle_list_event_professionals');
route('POST',  '/events/:id/professionals',      'handle_invite_professional');
route('PATCH', '/events/:id/professionals/:pid', 'handle_update_event_professional');
route('POST',  '/events/:id/apply',              'handle_apply_to_event');
