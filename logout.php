<?php
require_once __DIR__.'/includes/bootstrap.php';
Auth::logout();
redirect(APP_URL.'/login.php?msg=logout');
