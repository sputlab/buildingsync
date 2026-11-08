<?php
require_once __DIR__.'/includes/bootstrap.php';
if (Auth::check()) {
    redirect(APP_URL.'/dashboard.php');
} else {
    redirect(APP_URL.'/login.php');
}
