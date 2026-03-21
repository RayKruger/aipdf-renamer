<?php
// handles logout, just destroy the session and exit
require 'db.php';

session_unset();
session_destroy();

header("Location: login.php");
exit;
