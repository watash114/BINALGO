<?php
require_once __DIR__ . '/../includes/layout.php';
session_start();
flash_message('error', 'Facebook login is not yet configured. Please contact the administrator.');
redirect('/auth/login.php');
