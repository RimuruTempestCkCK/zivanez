<?php
session_start();
require 'config.php';

$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';

if ($username == '' || $password == '') {
    header("Location: login.php?error=kosong");
    exit;
}

// Query tanpa hash (sesuai permintaan)
$query = mysqli_query($conn, 
    "SELECT * FROM users 
     WHERE username='$username' 
     AND password='$password'");

$user = mysqli_fetch_assoc($query);

if (!$user) {
    header("Location: login.php?error=gagal");
    exit;
}

// Simpan session
$_SESSION['login']    = true;
$_SESSION['id']       = $user['id'];     // 🔥 TAMBAHKAN INI
$_SESSION['username'] = $user['username'];
$_SESSION['role']     = $user['role'];


// Redirect sesuai role
switch ($user['role']) {
    case 'AdminP':
        header("Location: AdminP/dashboard.php");
        break;
    case 'AdminC':
        header("Location: AdminC/dashboard.php");
        break;
    case 'BG':
        header("Location: BG/dashboard.php");
        break;
    case 'Karyawan':
        header("Location: Karyawan/dashboard.php");
        break;
    case 'Pemilik':
        header("Location: Pemilik/dashboard.php");
        break;
    default:
        header("Location: login.php");
}

exit;
